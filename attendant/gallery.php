<?php
/**
 * Attendant access to the shared storefront gallery manager.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_attendant();

$pdo = db();
$base = rtrim((string) env('APP_URL', ''), '/');
$galleryManagerAction = $base . '/attendant/gallery.php';
$galleryMarkupBufferLevel = ob_get_level();
ob_start();
require __DIR__ . '/../includes/gallery_manager_component.php';
$galleryManagerMarkup = ob_get_clean();
if ($galleryManagerMarkup === false || ob_get_level() !== $galleryMarkupBufferLevel) {
    throw new RuntimeException('Unable to render the gallery manager.');
}
$pageTitle = 'Gallery Manager';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
    <div>
        <p class="mb-1 text-sm text-gray-500">Attendant Portal / Storefront</p>
        <h1 class="text-2xl font-extrabold text-gray-900">Gallery Manager</h1>
        <p class="mt-1 text-sm text-gray-600">
            Create albums and add or remove photos displayed in the public Jengo Hardware gallery.
        </p>
    </div>
    <a class="btn btn-secondary" href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/gallery.php">
        View public gallery
    </a>
</div>

<?= $galleryManagerMarkup ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
