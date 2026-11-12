<?php
/**
 * Compatibility route to the product creation form in products.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_admin_login();

$base = rtrim((string) env('APP_URL', ''), '/');
header('Location: ' . $base . '/admin/products.php?action=add');
exit;
