<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pos_sales.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pos_payment_status_response(int $status, array $response): never
{
    http_response_code($status);
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
    header('Allow: GET');
    pos_payment_status_response(405, ['success' => false, 'message' => 'Only GET requests are allowed.']);
}

require_attendant();
$sessionToken = (string) ($_SESSION['attendant_approval_csrf'] ?? '');
$submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($sessionToken === '' || !is_string($submittedToken) || !hash_equals($sessionToken, $submittedToken)) {
    pos_payment_status_response(403, ['success' => false, 'message' => 'Your session expired. Reload the POS and try again.']);
}

$saleId = filter_input(INPUT_GET, 'sale_id', FILTER_VALIDATE_INT);
if ($saleId === false || $saleId === null || $saleId < 1) {
    pos_payment_status_response(400, ['success' => false, 'message' => 'A valid sale ID is required.']);
}

$pdo = db();
ensure_pos_schema($pdo);
$statement = $pdo->prepare(
    'SELECT s.id, s.status AS sale_status, s.attendant_id,
            pp.status AS payment_status, pp.gateway_receipt
     FROM sales s
     LEFT JOIN pos_payments pp ON pp.sale_id = s.id
     WHERE s.id = ?
     ORDER BY pp.id DESC
     LIMIT 1'
);
$statement->execute([$saleId]);
$payment = $statement->fetch();
if ($payment === false || (int) $payment['attendant_id'] !== (int) get_user_id()) {
    pos_payment_status_response(404, ['success' => false, 'message' => 'Payment was not found.']);
}

if ($payment['sale_status'] === 'completed') {
    unset($_SESSION['pos_pending_payment']);
    pos_payment_status_response(200, [
        'success' => true,
        'state' => 'completed',
        'message' => 'Payment confirmed.',
        'sale_id' => (int) $saleId,
    ]);
}
if ($payment['sale_status'] === 'cancelled') {
    unset($_SESSION['pos_pending_payment']);
    pos_payment_status_response(200, [
        'success' => true,
        'state' => 'cancelled',
        'message' => 'Payment was cancelled.',
    ]);
}

pos_payment_status_response(200, [
    'success' => true,
    'state' => 'pending',
    'message' => $payment['payment_status'] === 'pending'
        ? 'Waiting for customer M-Pesa PIN...'
        : 'Starting M-Pesa payment...',
]);
