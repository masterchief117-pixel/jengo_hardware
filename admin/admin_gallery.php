<?php
/**
 * Admin storefront gallery manager.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_admin_login();

$pdo = db();
$base = rtrim((string) env('APP_URL', ''), '/');
$escape = static fn (string $value): string =>
    htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$galleryManagerAction = $base . '/admin/admin_gallery.php';
$galleryMarkupBufferLevel = ob_get_level();
ob_start();
require __DIR__ . '/../includes/gallery_manager_component.php';
$galleryManagerMarkup = ob_get_clean();
if ($galleryManagerMarkup === false || ob_get_level() !== $galleryMarkupBufferLevel) {
    throw new RuntimeException('Unable to render the gallery manager.');
}
$pageTitle = 'Manage Gallery';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex gap-0 -mx-4 sm:-mx-6 lg:-mx-8 min-h-screen">
    <aside class="admin-sidebar hidden lg:flex flex-col w-56 xl:w-60"
           aria-label="Admin navigation">
        <div class="admin-sidebar__brand">Admin Panel</div>
        <a href="<?= $escape($base) ?>/admin/index.php" class="admin-sidebar__link">Dashboard</a>
        <a href="<?= $escape($base) ?>/admin/products.php" class="admin-sidebar__link">Products</a>
        <a href="<?= $escape($base) ?>/admin/admin_categories.php" class="admin-sidebar__link">Categories</a>
        <a href="<?= $escape($base) ?>/admin/admin_requests.php" class="admin-sidebar__link">Requests</a>
        <a href="<?= $escape($base) ?>/admin/admin_gallery.php" class="admin-sidebar__link active" aria-current="page">Gallery Manager</a>
        <a href="<?= $escape($base) ?>/admin/orders.php" class="admin-sidebar__link">Orders</a>
        <a href="<?= $escape($base) ?>/admin/users.php" class="admin-sidebar__link">Users</a>
        <a href="<?= $escape($base) ?>/admin/employees.php" class="admin-sidebar__link">Employees</a>
        <a href="<?= $escape($base) ?>/admin/admin_payroll.php" class="admin-sidebar__link">Payroll</a>
        <a href="<?= $escape($base) ?>/admin/deliveries.php" class="admin-sidebar__link">Deliveries</a>
        <a href="<?= $escape($base) ?>/admin/payments_record.php" class="admin-sidebar__link">Payments</a>
        <div class="border-t border-gray-700/50 my-3 mx-3"></div>
        <a href="<?= $escape($base) ?>/admin/logout.php" class="admin-sidebar__link">Sign Out</a>
    </aside>

    <main class="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8"
          aria-labelledby="gallery-manager-heading">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 id="gallery-manager-heading" class="text-2xl font-extrabold text-gray-900">
                    Album &amp; Gallery Management
                </h1>
                <p class="mt-1 text-sm text-gray-600">
                    Create albums, add photos by upload or HTTP/HTTPS URL, and manage storefront images.
                </p>
            </div>
            <a class="btn btn-secondary" href="<?= $escape($base) ?>/gallery.php">View public gallery</a>
        </div>

        <?= $galleryManagerMarkup ?>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
