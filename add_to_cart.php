<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

function respond_with_json(int $status, array $response): never
{
    http_response_code($status);
    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond_with_json(405, [
        'success' => false,
        'message' => 'Only POST requests are allowed.',
    ]);
}

if (has_any_role(['admin', 'attendant', 'employee'])) {
    respond_with_json(403, [
        'success' => false,
        'message' => 'Staff accounts cannot add products to the customer cart.',
    ]);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    respond_with_json(400, [
        'success' => false,
        'message' => 'A valid JSON request body is required.',
    ]);
}

$productId = filter_var($payload['product_id'] ?? null, FILTER_VALIDATE_INT);
$quantity = filter_var($payload['quantity'] ?? null, FILTER_VALIDATE_INT);

if ($productId === false || $productId < 1 || $quantity === false || $quantity < 1 || $quantity > 99) {
    respond_with_json(400, [
        'success' => false,
        'message' => 'A valid product ID and quantity between 1 and 99 are required.',
    ]);
}

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT id, name, stock_qty FROM products
     WHERE id = ? AND is_active = 1 LIMIT 1'
);
$stmt->execute([$productId]);
$product = $stmt->fetch();

if (!$product) {
    respond_with_json(404, [
        'success' => false,
        'message' => 'Product not found.',
    ]);
}

$stockQuantity = (int) $product['stock_qty'];
if ($stockQuantity < 1) {
    respond_with_json(409, [
        'success' => false,
        'message' => 'This product is out of stock.',
    ]);
}

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$currentQuantity = (int) ($_SESSION['cart'][$productId] ?? 0);
$_SESSION['cart'][$productId] = min($currentQuantity + $quantity, $stockQuantity);

respond_with_json(200, [
    'success' => true,
    'message' => $product['name'] . ' added to cart.',
    'cart_count' => array_sum($_SESSION['cart']),
]);
