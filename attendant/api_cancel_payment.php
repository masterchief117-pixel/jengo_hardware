<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pos_sales.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cancel_payment_response(int $status, array $response): never
{
    http_response_code($status);
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    header('Allow: POST');
    cancel_payment_response(405, ['success' => false, 'message' => 'Only POST requests are allowed.']);
}

require_attendant();
$pdo = db();
ensure_pos_schema($pdo);

$sessionToken = (string) ($_SESSION['attendant_approval_csrf'] ?? '');
$submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($sessionToken === '' || !is_string($submittedToken) || !hash_equals($sessionToken, $submittedToken)) {
    cancel_payment_response(403, ['success' => false, 'message' => 'Your session expired. Reload the POS and try again.']);
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    cancel_payment_response(400, ['success' => false, 'message' => 'A valid JSON request body is required.']);
}

$reasonValue = $payload['reason'] ?? 'Cancelled by Cashier';
$reason = is_string($reasonValue) ? trim($reasonValue) : '';
$allowedReasons = [
    'Cancelled by Cashier',
    'Customer Aborted',
    'Payment timed out',
    'STK Timeout',
    'STK initiation failed',
];
if (!in_array($reason, $allowedReasons, true)) {
    cancel_payment_response(400, ['success' => false, 'message' => 'Choose a valid cancellation reason.']);
}

$saleIdValue = $payload['sale_id'] ?? null;
$saleId = filter_var(is_scalar($saleIdValue) ? $saleIdValue : null, FILTER_VALIDATE_INT);
$referenceValue = $payload['transaction_reference'] ?? null;
$reference = is_string($referenceValue) ? trim($referenceValue) : '';

if (($saleId === false || $saleId === null || $saleId < 1) && $reference === '') {
    cancel_payment_response(400, ['success' => false, 'message' => 'Provide a sale ID or transaction reference.']);
}

if ($saleId === false || $saleId === null || $saleId < 1) {
    $lookup = $pdo->prepare(
        'SELECT s.id
         FROM sales s
         LEFT JOIN pos_payments pp ON pp.sale_id = s.id
         WHERE (s.sale_number = ? OR pp.checkout_request_id = ?)
           AND s.attendant_id = ?
         ORDER BY pp.id DESC
         LIMIT 1'
    );
    $lookup->execute([$reference, $reference, (int) get_user_id()]);
    $resolvedSaleId = $lookup->fetchColumn();
    if ($resolvedSaleId === false) {
        cancel_payment_response(404, ['success' => false, 'message' => 'Pending payment was not found.']);
    }
    $saleId = (int) $resolvedSaleId;
}

try {
    $result = cancel_pos_sale_payment(
        $pdo,
        (int) $saleId,
        (int) get_user_id(),
        $reason
    );
    if (!$result['cancelled']) {
        cancel_payment_response(409, [
            'success' => false,
            'message' => $result['message'],
        ]);
    }

    unset($_SESSION['pos_pending_payment']);
    cancel_payment_response(200, ['success' => true, 'message' => 'Payment terminated safely.']);
} catch (PDOException $exception) {
    error_log('[POS payment cancellation] ' . $exception->getMessage());
    cancel_payment_response(500, [
        'success' => false,
        'message' => 'Payment could not be cancelled safely. Please contact an administrator.',
    ]);
} catch (RuntimeException $exception) {
    cancel_payment_response(409, ['success' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('[POS payment cancellation] ' . $exception->getMessage());
    cancel_payment_response(500, [
        'success' => false,
        'message' => 'Payment could not be cancelled safely. Please contact an administrator.',
    ]);
}
