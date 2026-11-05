<?php
/**
 * payments/paystack_webhook.php
 *
 * Paystack server-to-server webhook receiver.
 *
 * Paystack fires this endpoint for all account events regardless of
 * whether the customer successfully redirected back to paystack_verify.php.
 * It is the authoritative, secure backup confirmation mechanism.
 *
 * Security model:
 *   Every webhook POST is signed with your Paystack secret key using
 *   HMAC-SHA512. We validate the X-Paystack-Signature header before
 *   touching the database. Unsigned requests are rejected with 401.
 *
 * Supported events:
 *   charge.success    — Payment confirmed  → mark payment success, order paid
 *   charge.failed     — Payment failed     → mark payment failed, revert order
 *   refund.processed  — Refund confirmed   → mark payment & order refunded
 *
 * IMPORTANT:
 *   - This endpoint must be publicly accessible via HTTPS.
 *   - Use ngrok during development (see README.md).
 *   - No session, no HTML output — pure API endpoint.
 *   - Always respond HTTP 200 so Paystack does not keep retrying.
 */

declare(strict_types=1);

// No session needed — server-to-server only
require_once __DIR__ . '/../config/database.php';

// ══════════════════════════════════════════════════════════════════════════
// BOOTSTRAP — respond 200 immediately, process synchronously
// Paystack marks the webhook failed if it doesn't get 200 within ~30 s.
// ══════════════════════════════════════════════════════════════════════════
http_response_code(200);
header('Content-Type: application/json');
set_time_limit(30);

// ══════════════════════════════════════════════════════════════════════════
// LOGGER
// ══════════════════════════════════════════════════════════════════════════
function ps_log(string $level, string $message, array $ctx = []): void
{
    $dir  = __DIR__ . '/../logs/';
    $file = $dir . 'paystack_webhook_' . date('Y-m') . '.log';

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $line = sprintf(
        "[%s] [%s] %s%s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        $ctx ? ' ' . json_encode($ctx) : ''
    );

    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

// ══════════════════════════════════════════════════════════════════════════
// 1. READ RAW BODY
// ══════════════════════════════════════════════════════════════════════════
$rawBody = (string) file_get_contents('php://input');

if ($rawBody === '') {
    ps_log('warn', 'Empty webhook body received');
    echo json_encode(['status' => 'ignored', 'reason' => 'empty body']);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// 2. VERIFY PAYSTACK SIGNATURE
//
// Paystack signs every request with:
//   HMAC-SHA512( raw_request_body, your_secret_key )
// and places the lowercase hex digest in the header:
//   X-Paystack-Signature
//
// We use hash_equals() (constant-time comparison) to prevent
// timing-based side-channel attacks.
// ══════════════════════════════════════════════════════════════════════════
$secretKey = (string) env('PAYSTACK_SECRET_KEY', '');

if ($secretKey === '') {
    ps_log('error', 'PAYSTACK_SECRET_KEY not set in .env — webhook cannot be verified');
    echo json_encode(['status' => 'error', 'reason' => 'misconfigured']);
    exit;
}

$receivedSignature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
$computedSignature = hash_hmac('sha512', $rawBody, $secretKey);

if (!hash_equals($computedSignature, $receivedSignature)) {
    ps_log('warn', 'Signature validation FAILED — possible spoofed request', [
        'received_sig' => substr($receivedSignature, 0, 16) . '…',
        'ip'           => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);
    http_response_code(401);
    echo json_encode(['status' => 'error', 'reason' => 'invalid signature']);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// 3. PARSE PAYLOAD
// ══════════════════════════════════════════════════════════════════════════
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    ps_log('error', 'Invalid JSON in webhook body', ['raw_preview' => substr($rawBody, 0, 200)]);
    echo json_encode(['status' => 'error', 'reason' => 'invalid JSON']);
    exit;
}

$event = (string) ($payload['event'] ?? '');
$data  = (array)  ($payload['data']  ?? []);

ps_log('info', "Webhook received: {$event}", [
    'reference' => $data['reference'] ?? '—',
]);

// ══════════════════════════════════════════════════════════════════════════
// 4. DB HELPER — find payment + order by Paystack reference
// ══════════════════════════════════════════════════════════════════════════
$pdo = db();

function ps_find_payment(\PDO $pdo, string $reference): ?array
{
    if ($reference === '') return null;

    $stmt = $pdo->prepare(
        "SELECT p.id           AS payment_id,
                p.status       AS payment_status,
                p.amount       AS payment_amount,
                p.order_id,
                o.status       AS order_status,
                o.order_number,
                o.customer_id
         FROM   payments p
         JOIN   orders   o ON o.id = p.order_id
         WHERE  p.gateway_reference = ?
           AND  p.payment_method    = 'paystack'
         LIMIT  1"
    );
    $stmt->execute([$reference]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ══════════════════════════════════════════════════════════════════════════
// 5. ROUTE BY EVENT
// ══════════════════════════════════════════════════════════════════════════
switch ($event) {

    // ┌─────────────────────────────────────────────────────────────────────
    // │  charge.success
    // │  Fired when a Paystack transaction completes successfully.
    // │  This is the secure backup to paystack_verify.php — if the
    // │  customer closes their browser before being redirected back,
    // │  this webhook ensures the order is still marked paid.
    // └─────────────────────────────────────────────────────────────────────
    case 'charge.success': {

        $reference = (string) ($data['reference'] ?? '');
        $record    = ps_find_payment($pdo, $reference);

        if (!$record) {
            ps_log('warn', 'No matching payment record for charge.success', [
                'reference' => $reference,
            ]);
            break;
        }

        $paymentId   = (int)    $record['payment_id'];
        $orderId     = (int)    $record['order_id'];
        $orderNumber = (string) $record['order_number'];

        // ── Idempotency guard ─────────────────────────────────────────────
        // paystack_verify.php may have already processed this payment.
        // Don't update again — just log and move on.
        if ($record['payment_status'] === 'success') {
            ps_log('info', 'charge.success — already processed, skipping', [
                'payment_id'   => $paymentId,
                'order_number' => $orderNumber,
            ]);
            break;
        }

        // ── Extract transaction metadata ──────────────────────────────────
        $gatewayTxId = (string) ($data['id']               ?? '');
        $channel     = (string) ($data['channel']           ?? '');  // card | mobile_money | bank
        $currency    = (string) ($data['currency']          ?? 'KES');
        $paidAmount  = (float)  (($data['amount'] ?? 0) / 100);      // kobo → KES
        $paidAt      = $data['paid_at'] ?? $data['transaction_date'] ?? null;
        $customerEmail = (string) ($data['customer']['email'] ?? '');

        $pdo->beginTransaction();

        try {
            // ── Update payment → success ──────────────────────────────────
            $pdo->prepare(
                "UPDATE payments
                 SET    status                 = 'success',
                        gateway_transaction_id = ?,
                        gateway_response       = ?,
                        paid_at                = COALESCE(?, NOW())
                 WHERE  id = ?"
            )->execute([
                $gatewayTxId,
                json_encode($data),
                $paidAt ? date('Y-m-d H:i:s', strtotime((string)$paidAt)) : null,
                $paymentId,
            ]);

            // ── Update order → paid ───────────────────────────────────────
            // Guard: only update if still in a pre-paid state.
            // If the verify page already set it to 'completed' or beyond,
            // we leave it alone.
            $pdo->prepare(
                "UPDATE orders
                 SET    status = 'paid'
                 WHERE  id     = ?
                   AND  status IN ('pending', 'processing')"
            )->execute([$orderId]);

            $pdo->commit();

            ps_log('info', 'charge.success — order marked PAID', [
                'payment_id'    => $paymentId,
                'order_number'  => $orderNumber,
                'gateway_tx_id' => $gatewayTxId,
                'channel'       => $channel,
                'amount_kes'    => $paidAmount,
                'customer'      => $customerEmail,
            ]);

        } catch (\Exception $e) {
            $pdo->rollBack();
            ps_log('error', 'DB error processing charge.success', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);
        }

        break;
    }

    // ┌─────────────────────────────────────────────────────────────────────
    // │  charge.failed
    // │  Fired when a transaction attempt fails on Paystack's end.
    // │  Marks the payment failed and reverts the order to 'pending'
    // │  so the customer can retry.
    // └─────────────────────────────────────────────────────────────────────
    case 'charge.failed': {

        $reference = (string) ($data['reference'] ?? '');
        $record    = ps_find_payment($pdo, $reference);

        if (!$record) {
            ps_log('warn', 'No matching payment for charge.failed', [
                'reference' => $reference,
            ]);
            break;
        }

        $paymentId = (int) $record['payment_id'];
        $orderId   = (int) $record['order_id'];

        // Don't downgrade a payment that already succeeded or was refunded
        if (in_array($record['payment_status'], ['success', 'refunded'], true)) {
            ps_log('info', 'charge.failed ignored — terminal status already set', [
                'payment_id' => $paymentId,
                'status'     => $record['payment_status'],
            ]);
            break;
        }

        try {
            $pdo->prepare(
                "UPDATE payments
                 SET    status           = 'failed',
                        gateway_response = ?
                 WHERE  id = ?"
            )->execute([json_encode($data), $paymentId]);

            // Revert order so customer can attempt payment again
            $pdo->prepare(
                "UPDATE orders
                 SET    status = 'pending'
                 WHERE  id     = ?
                   AND  status IN ('processing')"
            )->execute([$orderId]);

            ps_log('info', 'charge.failed — payment marked failed, order reverted to pending', [
                'payment_id' => $paymentId,
                'reference'  => $reference,
            ]);

        } catch (\Exception $e) {
            ps_log('error', 'DB error on charge.failed', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);
        }

        break;
    }

    // ┌─────────────────────────────────────────────────────────────────────
    // │  refund.processed
    // │  Fired when Paystack completes a refund.
    // │  Marks the payment and order as refunded.
    // └─────────────────────────────────────────────────────────────────────
    case 'refund.processed': {

        // Paystack's refund payload may use transaction_reference
        $reference = (string) (
            $data['transaction_reference'] ??
            $data['reference']             ??
            ''
        );

        $record = ps_find_payment($pdo, $reference);

        if (!$record) {
            ps_log('warn', 'No matching payment for refund.processed', [
                'reference' => $reference,
            ]);
            break;
        }

        $paymentId = (int) $record['payment_id'];
        $orderId   = (int) $record['order_id'];

        try {
            $pdo->prepare(
                "UPDATE payments
                 SET    status           = 'refunded',
                        gateway_response = ?
                 WHERE  id = ?"
            )->execute([json_encode($data), $paymentId]);

            $pdo->prepare(
                "UPDATE orders
                 SET    status = 'refunded'
                 WHERE  id     = ?
                   AND  status NOT IN ('cancelled')"
            )->execute([$orderId]);

            ps_log('info', 'refund.processed — payment and order marked refunded', [
                'payment_id' => $paymentId,
                'reference'  => $reference,
            ]);

        } catch (\Exception $e) {
            ps_log('error', 'DB error on refund.processed', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);
        }

        break;
    }

    // ┌─────────────────────────────────────────────────────────────────────
    // │  All other events — log and ignore gracefully
    // └─────────────────────────────────────────────────────────────────────
    default: {
        ps_log('info', "Unhandled event type ignored: {$event}");
        break;
    }
}

// ══════════════════════════════════════════════════════════════════════════
// 6. ACKNOWLEDGE — Paystack requires any 200 + valid JSON body
// ══════════════════════════════════════════════════════════════════════════
echo json_encode(['status' => 'ok']);
exit;
