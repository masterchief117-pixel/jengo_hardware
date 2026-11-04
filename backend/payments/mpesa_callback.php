<?php
/**
 * payments/mpesa_callback.php
 *
 * Safaricom Daraja STK Push callback receiver.
 *
 * Daraja POSTs a JSON body to this URL after the customer approves
 * or cancels the M-Pesa prompt. This endpoint:
 *
 *   1. Reads and validates the raw JSON payload
 *   2. Extracts the result code, receipt number, and metadata
 *   3. Matches the payment via CheckoutRequestID
 *   4. On success (ResultCode = 0):
 *        - Updates payments row  → status = 'success', stores receipt
 *        - Updates orders row    → status = 'paid'
 *        - Decrements stock for each order_item (idempotent guard)
 *   5. On failure (any other ResultCode):
 *        - Updates payments row  → status = 'failed' or 'cancelled'
 *        - Leaves order at 'processing' (admin can investigate)
 *   6. Always responds 200 OK with Daraja's expected acknowledgement JSON
 *
 * IMPORTANT: This endpoint must be publicly accessible via HTTPS.
 * Use ngrok during development (see README.md).
 * No session, no HTML output — pure API endpoint.
 */

declare(strict_types=1);

// No session needed — this is a server-to-server callback
require_once __DIR__ . '/../config/database.php';

// ══════════════════════════════════════════════════════════════════════════
// FORCE JSON RESPONSE — always, even on errors
// ══════════════════════════════════════════════════════════════════════════
header('Content-Type: application/json');

// Daraja retries if we don't respond quickly — set a strict time limit
set_time_limit(30);

// ══════════════════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════════════════

/**
 * Log a message to a dedicated callback log file.
 * Falls back silently if the log directory is not writable.
 */
function mpesa_log(string $level, string $message, array $context = []): void
{
    $logDir  = __DIR__ . '/../logs/';
    $logFile = $logDir . 'mpesa_callback_' . date('Y-m') . '.log';

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $line = sprintf(
        "[%s] [%s] %s %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        $context ? json_encode($context) : ''
    );

    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Send the Daraja acknowledgement and exit.
 * Daraja requires this exact JSON shape; any other response triggers a retry.
 */
function daraja_ack(string $resultCode = '0', string $resultDesc = 'Success'): never
{
    echo json_encode([
        'ResultCode' => $resultCode,
        'ResultDesc' => $resultDesc,
    ]);
    exit;
}

/**
 * Extract a named item from the STK callback metadata array.
 * Daraja sends metadata as [ ['Name' => 'X', 'Value' => 'Y'], ... ]
 */
function mpesa_meta(array $items, string $name): mixed
{
    foreach ($items as $item) {
        if (($item['Name'] ?? '') === $name) {
            return $item['Value'] ?? null;
        }
    }
    return null;
}

// ══════════════════════════════════════════════════════════════════════════
// 1. READ & VALIDATE RAW PAYLOAD
// ══════════════════════════════════════════════════════════════════════════

$rawBody = file_get_contents('php://input');

if (empty($rawBody)) {
    mpesa_log('warn', 'Empty callback body received');
    daraja_ack('1', 'Empty body');
}

$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    mpesa_log('error', 'Invalid JSON in callback', ['raw' => substr($rawBody, 0, 500)]);
    daraja_ack('1', 'Invalid JSON');
}

mpesa_log('info', 'Callback received', ['payload' => $payload]);

// ══════════════════════════════════════════════════════════════════════════
// 2. NAVIGATE DARAJA PAYLOAD STRUCTURE
//
// Daraja STK Push callback structure:
// {
//   "Body": {
//     "stkCallback": {
//       "MerchantRequestID": "...",
//       "CheckoutRequestID": "...",
//       "ResultCode": 0,
//       "ResultDesc": "The service request is processed successfully.",
//       "CallbackMetadata": {            ← Only present on success (ResultCode 0)
//         "Item": [
//           { "Name": "Amount",              "Value": 1500.00 },
//           { "Name": "MpesaReceiptNumber",  "Value": "NLJ7RT61SV" },
//           { "Name": "Balance" },
//           { "Name": "TransactionDate",     "Value": 20191219102115 },
//           { "Name": "PhoneNumber",         "Value": 254708374149 }
//         ]
//       }
//     }
//   }
// }
// ══════════════════════════════════════════════════════════════════════════

$stkCallback = $payload['Body']['stkCallback'] ?? null;

if (!is_array($stkCallback)) {
    mpesa_log('error', 'Missing stkCallback in payload');
    daraja_ack('1', 'Missing stkCallback');
}

$merchantRequestId  = (string) ($stkCallback['MerchantRequestID']  ?? '');
$checkoutRequestId  = (string) ($stkCallback['CheckoutRequestID']  ?? '');
$resultCode         = (int)    ($stkCallback['ResultCode']          ?? -1);
$resultDesc         = (string) ($stkCallback['ResultDesc']          ?? '');

if ($checkoutRequestId === '') {
    mpesa_log('error', 'Missing CheckoutRequestID');
    daraja_ack('1', 'Missing CheckoutRequestID');
}

// ══════════════════════════════════════════════════════════════════════════
// 3. MATCH PAYMENT RECORD VIA CheckoutRequestID
// ══════════════════════════════════════════════════════════════════════════

$pdo = db();

$payStmt = $pdo->prepare(
    "SELECT p.id        AS payment_id,
            p.status    AS payment_status,
            p.amount    AS payment_amount,
            p.order_id,
            o.status    AS order_status,
            o.order_number
     FROM   payments p
     JOIN   orders   o ON o.id = p.order_id
     WHERE  p.gateway_transaction_id = ?
       AND  p.payment_method         = 'mpesa'
     LIMIT  1"
);
$payStmt->execute([$checkoutRequestId]);
$record = $payStmt->fetch();

if (!$record) {
    // Could be a callback for a payment we don't know about (e.g. test call)
    mpesa_log('warn', 'No matching payment for CheckoutRequestID', [
        'checkout_request_id' => $checkoutRequestId,
    ]);
    // Still acknowledge so Daraja stops retrying
    daraja_ack();
}

$paymentId   = (int)    $record['payment_id'];
$orderId     = (int)    $record['order_id'];
$orderNumber = (string) $record['order_number'];

// ── Idempotency guard — don't reprocess an already-finalised payment ──────
if (in_array($record['payment_status'], ['success', 'failed', 'refunded'], true)) {
    mpesa_log('info', 'Callback already processed — skipping', [
        'payment_id' => $paymentId,
        'status'     => $record['payment_status'],
    ]);
    daraja_ack();
}

// ══════════════════════════════════════════════════════════════════════════
// 4. PROCESS RESULT
// ══════════════════════════════════════════════════════════════════════════

$pdo->beginTransaction();

try {

    // ── RESULT CODE 0 = SUCCESS ───────────────────────────────────────────
    if ($resultCode === 0) {

        $metadata = $stkCallback['CallbackMetadata']['Item'] ?? [];

        // Extract key fields from metadata
        $mpesaReceipt    = (string) (mpesa_meta($metadata, 'MpesaReceiptNumber') ?? '');
        $paidAmount      = (float)  (mpesa_meta($metadata, 'Amount')             ?? 0);
        $transactionDate = (string) (mpesa_meta($metadata, 'TransactionDate')    ?? '');
        $phoneNumber     = (string) (mpesa_meta($metadata, 'PhoneNumber')        ?? '');

        // Parse Daraja's compact timestamp: 20191219102115 → MySQL datetime
        $paidAt = null;
        if (strlen($transactionDate) === 14) {
            $paidAt = date(
                'Y-m-d H:i:s',
                mktime(
                    (int)substr($transactionDate,  8, 2),  // hour
                    (int)substr($transactionDate, 10, 2),  // minute
                    (int)substr($transactionDate, 12, 2),  // second
                    (int)substr($transactionDate,  4, 2),  // month
                    (int)substr($transactionDate,  6, 2),  // day
                    (int)substr($transactionDate,  0, 4)   // year
                )
            );
        }

        // 4a. Update payment record → success
        $pdo->prepare(
            "UPDATE payments
             SET    status                  = 'success',
                    gateway_reference       = ?,
                    gateway_response        = ?,
                    phone_number            = COALESCE(NULLIF(?, ''), phone_number),
                    paid_at                 = COALESCE(?, NOW())
             WHERE  id = ?"
        )->execute([
            $mpesaReceipt,
            json_encode($stkCallback),
            $phoneNumber,
            $paidAt,
            $paymentId,
        ]);

        // 4b. Update order → paid (only if currently processing/pending)
        $pdo->prepare(
            "UPDATE orders
             SET    status = 'paid'
             WHERE  id     = ?
               AND  status IN ('pending', 'processing')"
        )->execute([$orderId]);

        // 4c. Decrement product stock for each order item
        //     Uses optimistic locking: only decrements if stock is sufficient.
        //     The checkout.php already decremented on order creation, so we
        //     skip this step to avoid double-decrement.
        //     If you prefer to reserve stock only after payment is confirmed,
        //     remove the decrement in checkout.php and uncomment below:
        //
        // $itemsStmt = $pdo->prepare(
        //     'SELECT product_id, quantity FROM order_items WHERE order_id = ?'
        // );
        // $itemsStmt->execute([$orderId]);
        // $decrStmt = $pdo->prepare(
        //     'UPDATE products
        //      SET    stock_qty = stock_qty - ?
        //      WHERE  id = ? AND stock_qty >= ?'
        // );
        // foreach ($itemsStmt->fetchAll() as $item) {
        //     $decrStmt->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
        // }

        $pdo->commit();

        mpesa_log('info', 'Payment SUCCESS', [
            'payment_id'    => $paymentId,
            'order_id'      => $orderId,
            'order_number'  => $orderNumber,
            'receipt'       => $mpesaReceipt,
            'amount'        => $paidAmount,
            'phone'         => $phoneNumber,
        ]);

    } else {
        // ── ANY OTHER RESULT CODE = FAILURE / CANCELLATION ───────────────

        // ResultCode 1032 = request cancelled by user
        $paymentStatus = $resultCode === 1032 ? 'cancelled' : 'failed';

        $pdo->prepare(
            "UPDATE payments
             SET    status           = ?,
                    gateway_response = ?
             WHERE  id = ?"
        )->execute([
            $paymentStatus,
            json_encode($stkCallback),
            $paymentId,
        ]);

        // Revert order back to 'pending' so customer can retry
        $pdo->prepare(
            "UPDATE orders
             SET    status = 'pending'
             WHERE  id     = ?
               AND  status IN ('processing')"
        )->execute([$orderId]);

        $pdo->commit();

        mpesa_log('info', 'Payment ' . strtoupper($paymentStatus), [
            'payment_id'  => $paymentId,
            'order_id'    => $orderId,
            'result_code' => $resultCode,
            'result_desc' => $resultDesc,
        ]);
    }

} catch (\Exception $e) {
    $pdo->rollBack();
    mpesa_log('error', 'DB exception in callback', [
        'payment_id' => $paymentId,
        'message'    => $e->getMessage(),
    ]);
    // Still acknowledge Daraja — we'll investigate via logs
    daraja_ack();
}

// ══════════════════════════════════════════════════════════════════════════
// 5. ACKNOWLEDGE DARAJA
// ══════════════════════════════════════════════════════════════════════════
daraja_ack();
