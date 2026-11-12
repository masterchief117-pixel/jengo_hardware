<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pos_sales.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pos_payment_response(int $status, array $response): never
{
    http_response_code($status);
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    header('Allow: POST');
    pos_payment_response(405, ['success' => false, 'message' => 'Only POST requests are allowed.']);
}

require_attendant();
$pdo = db();
ensure_pos_schema($pdo);

$sessionToken = (string) ($_SESSION['attendant_approval_csrf'] ?? '');
$submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($sessionToken === '' || !is_string($submittedToken) || !hash_equals($sessionToken, $submittedToken)) {
    pos_payment_response(403, ['success' => false, 'message' => 'Your session expired. Reload the POS and try again.']);
}

$payload = json_decode((string) file_get_contents('php://input'), true);
$saleId = filter_var(is_array($payload) ? ($payload['sale_id'] ?? null) : null, FILTER_VALIDATE_INT);
$phone = is_array($payload) && is_string($payload['phone'] ?? null)
    ? format_pos_mpesa_phone($payload['phone'])
    : null;
if (!is_array($payload) || $saleId === false || $saleId < 1 || $phone === null) {
    pos_payment_response(400, ['success' => false, 'message' => 'Provide a valid sale and Kenyan M-Pesa phone number.']);
}

$ownsTransaction = !$pdo->inTransaction();
if ($ownsTransaction) {
    $pdo->beginTransaction();
}

try {
    $saleStatement = $pdo->prepare(
        'SELECT id, sale_number, attendant_id, total_amount, payment_method, status
         FROM sales WHERE id = ? FOR UPDATE'
    );
    $saleStatement->execute([$saleId]);
    $sale = $saleStatement->fetch();
    if ($sale === false || (int) $sale['attendant_id'] !== (int) get_user_id()) {
        throw new RuntimeException('Pending payment was not found.');
    }
    if ($sale['status'] !== 'pending' || $sale['payment_method'] !== 'mpesa') {
        throw new RuntimeException('This sale is no longer awaiting M-Pesa payment.');
    }

    $existingStatement = $pdo->prepare(
        "SELECT id, checkout_request_id, UNIX_TIMESTAMP(created_at) AS started_at
         FROM pos_payments
         WHERE sale_id = ? AND status = 'pending'
         ORDER BY id DESC LIMIT 1 FOR UPDATE"
    );
    $existingStatement->execute([$saleId]);
    $existing = $existingStatement->fetch();
    if ($existing !== false) {
        if ($ownsTransaction) {
            $pdo->commit();
        }
        pos_payment_response(200, [
            'success' => true,
            'message' => 'M-Pesa prompt is being processed.',
            'state' => 'pending',
            'started_at' => (int) $existing['started_at'],
        ]);
    }

    $insert = $pdo->prepare(
        "INSERT INTO pos_payments (sale_id, amount, phone_number, status)
         VALUES (?, ?, ?, 'pending')"
    );
    $insert->execute([$saleId, $sale['total_amount'], $phone]);
    $paymentId = (int) $pdo->lastInsertId();
    $createdAtStatement = $pdo->prepare('SELECT UNIX_TIMESTAMP(created_at) FROM pos_payments WHERE id = ?');
    $createdAtStatement->execute([$paymentId]);
    $startedAt = (int) $createdAtStatement->fetchColumn();
    if ($ownsTransaction) {
        $pdo->commit();
    }
} catch (Throwable $exception) {
    if ($ownsTransaction && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($exception instanceof PDOException) {
        error_log('[POS M-Pesa] ' . $exception->getMessage());
        pos_payment_response(500, [
            'success' => false,
            'message' => 'Unable to prepare the payment. Please contact an administrator.',
        ]);
    }
    pos_payment_response(409, ['success' => false, 'message' => $exception->getMessage()]);
}

$consumerKey = trim((string) env('MPESA_CONSUMER_KEY', ''));
$consumerSecret = trim((string) env('MPESA_CONSUMER_SECRET', ''));
$shortcode = trim((string) env('MPESA_SHORTCODE', ''));
$passkey = trim((string) env('MPESA_PASSKEY', ''));
$callbackUrl = trim((string) env('MPESA_CALLBACK_URL', ''));
$environment = (string) env('MPESA_ENV', 'sandbox');
$baseUrl = $environment === 'production'
    ? (string) env('MPESA_PRODUCTION_URL', 'https://api.safaricom.co.ke')
    : (string) env('MPESA_SANDBOX_URL', 'https://sandbox.safaricom.co.ke');

if ($consumerKey === '' || $consumerSecret === '' || $shortcode === '' || $passkey === '' || $callbackUrl === '') {
    $error = 'M-Pesa is not configured. Please contact an administrator.';
} else {
    $error = '';
}

try {
    if ($error !== '') {
        throw new RuntimeException($error);
    }

    $curl = curl_init($baseUrl . '/oauth/v1/generate?grant_type=client_credentials');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode($consumerKey . ':' . $consumerSecret),
            'Content-Type: application/json',
        ],
    ]);
    $tokenResponse = curl_exec($curl);
    $tokenStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $tokenError = curl_error($curl);
    curl_close($curl);
    $tokenData = is_string($tokenResponse) ? json_decode($tokenResponse, true) : null;
    if ($tokenResponse === false || $tokenStatus < 200 || $tokenStatus >= 300 || !is_array($tokenData) || empty($tokenData['access_token'])) {
        throw new RuntimeException('Could not connect to M-Pesa to start the payment.');
    }

    $timestamp = date('YmdHis');
    $stkPayload = [
        'BusinessShortCode' => $shortcode,
        'Password' => base64_encode($shortcode . $passkey . $timestamp),
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => (int) ceil((float) $sale['total_amount']),
        'PartyA' => $phone,
        'PartyB' => $shortcode,
        'PhoneNumber' => $phone,
        'CallBackURL' => $callbackUrl,
        'AccountReference' => (string) $sale['sale_number'],
        'TransactionDesc' => 'Jengo Hardware POS ' . (string) $sale['sale_number'],
    ];

    $curl = curl_init($baseUrl . '/mpesa/stkpush/v1/processrequest');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($stkPayload),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . (string) $tokenData['access_token'],
            'Content-Type: application/json',
        ],
    ]);
    $stkResponse = curl_exec($curl);
    $stkStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $stkData = is_string($stkResponse) ? json_decode($stkResponse, true) : null;
    if ($stkResponse === false || $stkStatus < 200 || $stkStatus >= 300 || !is_array($stkData)) {
        throw new RuntimeException('M-Pesa did not accept the payment request.');
    }
    if (($stkData['ResponseCode'] ?? null) !== '0'
        || !is_string($stkData['CheckoutRequestID'] ?? null)
        || $stkData['CheckoutRequestID'] === '') {
        $gatewayMessage = $stkData['errorMessage'] ?? $stkData['ResponseDescription'] ?? 'M-Pesa did not accept the payment request.';
        throw new RuntimeException(is_string($gatewayMessage) ? $gatewayMessage : 'M-Pesa did not accept the payment request.');
    }

    $update = $pdo->prepare(
        'UPDATE pos_payments
         SET merchant_request_id = ?, checkout_request_id = ?, gateway_response = ?
         WHERE id = ? AND status = \'pending\''
    );
    $update->execute([
        (string) ($stkData['MerchantRequestID'] ?? ''),
        $stkData['CheckoutRequestID'],
        json_encode($stkData, JSON_INVALID_UTF8_SUBSTITUTE),
        $paymentId,
    ]);
    pos_payment_response(200, [
        'success' => true,
        'message' => 'M-Pesa prompt sent. Waiting for customer confirmation.',
        'state' => 'pending',
        'started_at' => $startedAt,
    ]);
} catch (Throwable $exception) {
    error_log('[POS M-Pesa] ' . $exception->getMessage());
    $pdo->prepare(
        "UPDATE pos_payments
         SET status = 'failed', gateway_response = ?
         WHERE id = ? AND status = 'pending'"
    )->execute([mb_substr($exception->getMessage(), 0, 2000), $paymentId]);

    try {
        $cancellation = cancel_pos_sale_payment(
            $pdo,
            (int) $saleId,
            (int) get_user_id(),
            'STK initiation failed'
        );
        if ($cancellation['cancelled']) {
            unset($_SESSION['pos_pending_payment']);
            pos_payment_response(200, [
                'success' => true,
                'state' => 'cancelled',
                'message' => 'M-Pesa could not start. Reserved stock was released and the register is ready.',
            ]);
        }
    } catch (Throwable $cancelException) {
        error_log('[POS M-Pesa] Failed to release stock after STK initiation failure: ' . $cancelException->getMessage());
    }
    pos_payment_response(502, [
        'success' => false,
        'message' => 'M-Pesa payment could not be started. The reserved stock has been released.',
    ]);
}
