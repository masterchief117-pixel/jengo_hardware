<?php
/**
 * payments/paystack_verify.php
 *
 * Paystack payment verification — redirect callback handler.
 *
 * After the customer pays (or cancels) on Paystack's hosted page,
 * Paystack redirects them here with ?reference=xxx appended.
 *
 * Flow:
 *   1. Read the reference from GET params (validate against session)
 *   2. Call Paystack /transaction/verify/:reference
 *   3. If status = 'success':
 *        - Update payment  → status = 'success', store gateway data
 *        - Update order    → status = 'paid'
 *        - Clear pending_order session key
 *   4. If status != 'success' → mark payment failed, revert order to pending
 *   5. Redirect customer to appropriate page with flash message
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login();

$pdo = db();

// ══════════════════════════════════════════════════════════════════════════
// 1. READ & VALIDATE REFERENCE
// ══════════════════════════════════════════════════════════════════════════
$reference = trim($_GET['reference'] ?? '');

if ($reference === '') {
    redirect('/customer/dashboard.php', 'Invalid payment reference.', 'error');
}

// Cross-check with session to prevent reference tampering
$sessionReference = $_SESSION['paystack_reference'] ?? '';
$sessionOrderId   = (int) ($_SESSION['paystack_order_id'] ?? 0);

if ($sessionReference !== '' && $sessionReference !== $reference) {
    error_log('[Paystack Verify] Reference mismatch. GET: ' . $reference . ' | Session: ' . $sessionReference);
    redirect('/customer/dashboard.php', 'Payment reference mismatch.', 'error');
}

// ══════════════════════════════════════════════════════════════════════════
// 2. PAYSTACK CONFIG
// ══════════════════════════════════════════════════════════════════════════
$secretKey = (string) env('PAYSTACK_SECRET_KEY', '');
$baseUrl   = (string) env('PAYSTACK_BASE_URL',   'https://api.paystack.co');

if ($secretKey === '') {
    redirect('/checkout.php', 'Payment gateway not configured.', 'error');
}

// ══════════════════════════════════════════════════════════════════════════
// HELPER — reuse from paystack_init.php (or define locally)
// ══════════════════════════════════════════════════════════════════════════
if (!function_exists('paystack_request')) {
    function paystack_request(string $secretKey, string $endpoint, ?array $data = null): array
    {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $secretKey,
                'Content-Type: application/json',
                'Cache-Control: no-cache',
            ],
        ]);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POST,       true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($response === false || $curlError !== '') {
            throw new \RuntimeException("Paystack cURL error: {$curlError}");
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid JSON from Paystack: " . substr($response, 0, 300));
        }
        return $decoded;
    }
}

// ══════════════════════════════════════════════════════════════════════════
// 3. FETCH PAYMENT RECORD FROM DB (matched by reference)
// ══════════════════════════════════════════════════════════════════════════
$payStmt = $pdo->prepare(
    "SELECT p.id        AS payment_id,
            p.status    AS payment_status,
            p.order_id,
            p.amount,
            o.status    AS order_status,
            o.order_number,
            o.customer_id
     FROM   payments p
     JOIN   orders   o ON o.id = p.order_id
     WHERE  p.gateway_reference = ?
       AND  p.payment_method    = 'paystack'
     LIMIT  1"
);
$payStmt->execute([$reference]);
$record = $payStmt->fetch();

if (!$record) {
    error_log('[Paystack Verify] No payment record for reference: ' . $reference);
    redirect('/customer/dashboard.php', 'Payment record not found.', 'error');
}

// Security: ensure order belongs to the logged-in user
if ((int)$record['customer_id'] !== get_user_id()) {
    error_log('[Paystack Verify] User ' . get_user_id() . ' attempted to verify order owned by ' . $record['customer_id']);
    redirect('/customer/dashboard.php', 'Unauthorised.', 'error');
}

$paymentId   = (int)    $record['payment_id'];
$orderId     = (int)    $record['order_id'];
$orderNumber = (string) $record['order_number'];

// Idempotency — already processed
if ($record['payment_status'] === 'success') {
    unset($_SESSION['pending_order'], $_SESSION['paystack_reference'], $_SESSION['paystack_order_id']);
    redirect(
        '/customer/dashboard.php',
        'Payment already confirmed. Thank you!',
        'success'
    );
}

// ══════════════════════════════════════════════════════════════════════════
// 4. CALL PAYSTACK VERIFY API
// ══════════════════════════════════════════════════════════════════════════
try {
    $result = paystack_request(
        $secretKey,
        "{$baseUrl}/transaction/verify/" . urlencode($reference)
    );

    if (!($result['status'] ?? false)) {
        throw new \RuntimeException($result['message'] ?? 'Verification request failed.');
    }

    $txData   = $result['data']   ?? [];
    $txStatus = $txData['status'] ?? 'failed';   // 'success' | 'failed' | 'abandoned'

    // ── SUCCESS ───────────────────────────────────────────────────────────
    if ($txStatus === 'success') {

        $paidAt    = $txData['paid_at']    ?? $txData['transaction_date'] ?? null;
        $gatewayId = (string) ($txData['id'] ?? '');
        $channel   = $txData['channel']    ?? '';   // card | mobile_money | bank

        $pdo->beginTransaction();

        // Update payment → success
        $pdo->prepare(
            "UPDATE payments
             SET    status                  = 'success',
                    gateway_transaction_id  = ?,
                    gateway_response        = ?,
                    paid_at                 = ?
             WHERE  id = ?"
        )->execute([
            $gatewayId,
            json_encode($txData),
            $paidAt ? date('Y-m-d H:i:s', strtotime($paidAt)) : null,
            $paymentId,
        ]);

        // Update order → paid
        $pdo->prepare(
            "UPDATE orders
             SET    status = 'paid'
             WHERE  id     = ?
               AND  status IN ('pending','processing')"
        )->execute([$orderId]);

        $pdo->commit();

        // Clear checkout session data
        unset(
            $_SESSION['pending_order'],
            $_SESSION['paystack_reference'],
            $_SESSION['paystack_order_id']
        );

        error_log('[Paystack Verify] SUCCESS — Order: ' . $orderNumber . ' | Ref: ' . $reference . ' | Channel: ' . $channel);

        redirect(
            '/customer/dashboard.php',
            '✓ Payment confirmed! Order ' . $orderNumber . ' is now being processed.',
            'success'
        );

    } else {
        // ── FAILED / ABANDONED ────────────────────────────────────────────
        $paymentFinalStatus = $txStatus === 'abandoned' ? 'cancelled' : 'failed';

        $pdo->prepare(
            "UPDATE payments
             SET    status           = ?,
                    gateway_response = ?
             WHERE  id = ?"
        )->execute([
            $paymentFinalStatus,
            json_encode($txData),
            $paymentId,
        ]);

        // Revert order to pending so customer can retry
        $pdo->prepare(
            "UPDATE orders SET status = 'pending'
             WHERE id = ? AND status IN ('processing')"
        )->execute([$orderId]);

        error_log('[Paystack Verify] ' . strtoupper($paymentFinalStatus) . ' — Order: ' . $orderNumber . ' | Status from Paystack: ' . $txStatus);

        redirect(
            '/checkout.php',
            'Payment was ' . $paymentFinalStatus . '. Please try again.',
            'error'
        );
    }

} catch (\Exception $e) {
    error_log('[Paystack Verify] Exception for ref ' . $reference . ': ' . $e->getMessage());

    $userMessage = env('APP_DEBUG', false) === true
        ? $e->getMessage()
        : 'Could not verify your payment. Please check your order status or contact support.';

    redirect('/customer/dashboard.php', $userMessage, 'error');
}
