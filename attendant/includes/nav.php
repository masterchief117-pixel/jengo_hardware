<?php
/**
 * Attendant floor navigation. Included from the shared header on attendant pages,
 * and available to any attendant page that needs the same links.
 */

declare(strict_types=1);

if (!isset($base)) {
    $base = rtrim((string) env('APP_URL', ''), '/');
}

$attendantModules = [
    ['Floor', '/attendant/index.php'],
    ['POS', '/attendant/pos.php'],
    ['Inventory Lookup', '/attendant/inventory.php'],
    ['Quotes & Customers', '/attendant/quotes.php'],
    ['My Shift', '/attendant/shift.php'],
    ['Pending Approvals Status', '/attendant/approvals.php'],
];
?>
<div class="bg-gray-800 border-t border-gray-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <nav class="flex gap-1 overflow-x-auto py-2" aria-label="Attendant modules">
            <?php foreach ($attendantModules as [$label, $path]): ?>
                <?php $href = $base . $path; ?>
                <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"
                   class="whitespace-nowrap rounded-md px-3 py-1.5 text-sm transition-colors <?= nav_active($href) ?>">
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
</div>
