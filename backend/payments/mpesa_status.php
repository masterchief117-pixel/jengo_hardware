<?php
/**
 * payments/mpesa_status.php
 *
 * Lightweight JSON endpoint polled by mpesa_push.php's waiting page.
 * Returns the current status of a payment record.
 *
 * GET ?payment_id=X
 * Response: { "status": "pending|success|failed|cancelled", "message": "..." }
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

// Must be called via AJAX by a logged-in user
if (
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest' ||
    !is_logged_in()
) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$paymentId = (int) ($_GET['payment_id'] ?? 0);

if ($paymentId < 1) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid payment ID']);
    exit;
}

$pdo  = db();
$stmt = $pdo->prepare(
    'SELECT p.status, p.gateway_reference, o.customer_id
     FROM   payments p
     JOIN   orders   o ON o.id = p.order_id
     WHERE  p.id = ? LIMIT 1'
);
$stmt->execute([$paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Payment not found']);
    exit;
}

// Ensure this payment belongs to the logged-in user
if ((int)$payment['customer_id'] !== get_user_id()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$messages = [
    'success'   => 'Payment confirmed! Redirecting…',
    'failed'    => 'Payment failed. Please try again.',
    'cancelled' => 'Payment was cancelled.',
    'pending'   => 'Waiting for confirmation…',
    'refunded'  => 'Payment refunded.',
];

echo json_encode([
    'status'    => $payment['status'],
    'reference' => $payment['gateway_reference'] ?? '',
    'message'   => $messages[$payment['status']] ?? 'Unknown status',
]);
