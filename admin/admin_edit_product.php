<?php
/**
 * Compatibility route to the product editor in products.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_admin_login();

$productIdValue = $_GET['id'] ?? null;
$productId = filter_var(
    is_string($productIdValue) || is_int($productIdValue) ? $productIdValue : null,
    FILTER_VALIDATE_INT
);

$base = rtrim((string) env('APP_URL', ''), '/');
if ($productId === false || $productId === null || $productId < 1) {
    $_SESSION['flash_message'] = 'Product not found.';
    $_SESSION['flash_type'] = 'error';
    header('Location: ' . $base . '/admin/products.php');
    exit;
}

header(
    'Location: ' . $base . '/admin/products.php?action=edit&edit=' . $productId
);
exit;
