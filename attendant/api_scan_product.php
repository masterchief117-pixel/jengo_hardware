<?php
/**
 * Look up a product for the attendant POS scanner and optionally add it to
 * the current POS cart.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_attendant();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$source = $method === 'POST' ? $_POST : $_GET;
$lookupValue = $source['sku'] ?? $source['barcode'] ?? $source['name'] ?? null;
if (!is_string($lookupValue) && !is_numeric($lookupValue)) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter a SKU, barcode, or product name']);
    exit;
}
$lookup = trim((string) $lookupValue);
if ($lookup === '' || strlen($lookup) > 200) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter a SKU, barcode, or product name']);
    exit;
}

if ($method === 'POST') {
    $csrfToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionToken = (string) ($_SESSION['attendant_approval_csrf'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Your session expired. Reload the POS and try again.']);
        exit;
    }

    if (!isset($_SESSION['pos_cart']) || !is_array($_SESSION['pos_cart'])) {
        $_SESSION['pos_cart'] = [];
    }
}

$pdo = db();
$statement = $pdo->prepare(
    'SELECT id, name, sku, price, stock_qty
     FROM products
     WHERE is_active = 1 AND (sku = ? OR name LIKE ?)
     ORDER BY CASE WHEN sku = ? THEN 0 ELSE 1 END
     LIMIT 1'
);
$statement->execute([$lookup, '%' . $lookup . '%', $lookup]);
$product = $statement->fetch();

if ($product === false) {
    http_response_code(404);
    echo json_encode(['error' => 'Product not found']);
    exit;
}

$productDetails = [
    'id' => (int) $product['id'],
    'name' => (string) $product['name'],
    'sku' => (string) ($product['sku'] ?? ''),
    'price' => round((float) $product['price'], 2),
    'stock_qty' => (int) $product['stock_qty'],
];

if ($method === 'POST') {
    $productId = $productDetails['id'];
    $existingQuantity = (int) ($_SESSION['pos_cart'][$productId]['quantity'] ?? 0);
    if ($productDetails['stock_qty'] < 1 || $existingQuantity >= $productDetails['stock_qty']) {
        http_response_code(409);
        echo json_encode(['error' => $productDetails['name'] . ' is out of stock.']);
        exit;
    }

    $_SESSION['pos_cart'][$productId] = [
        'product_id' => $productId,
        'name' => $productDetails['name'],
        'sku' => $productDetails['sku'],
        'catalog_price' => $productDetails['price'],
        'unit_price' => round(
            (float) ($_SESSION['pos_cart'][$productId]['unit_price'] ?? $productDetails['price']),
            2
        ),
        'quantity' => $existingQuantity + 1,
    ];

    $productDetails['cart_quantity'] = $existingQuantity + 1;
}

echo json_encode(['product' => $productDetails]);
