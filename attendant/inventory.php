<?php
/**
 * View-only inventory. Stock changes are submitted for admin approval.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pos_sales.php';

require_attendant();

if (empty($_SESSION['attendant_approval_csrf'])) {
    $_SESSION['attendant_approval_csrf'] = bin2hex(random_bytes(32));
}
$approvalCsrfToken = (string) $_SESSION['attendant_approval_csrf'];

$pdo = db();
ensure_pos_schema($pdo);

$search = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT id, name, sku, location, price, stock_qty
        FROM products
        WHERE is_active = 1';
$params = [];
if ($search !== '') {
    $sql .= ' AND (name LIKE ? OR sku LIKE ? OR location LIKE ?)';
    $like = '%' . $search . '%';
    $params = [$like, $like, $like];
}
$sql .= ' ORDER BY name ASC';
$statement = $pdo->prepare($sql);
$statement->execute($params);
$products = $statement->fetchAll();

$pageTitle = 'Inventory Lookup';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
    <div>
        <h1 class="text-2xl font-extrabold text-gray-900">Inventory Lookup</h1>
        <p class="mt-1 text-sm text-gray-500">
            Shelf counts are read-only. A discrepancy is sent to an admin and does not change stock until it is approved.
        </p>
    </div>
    <form method="get" class="flex gap-2">
        <label>
            <span class="sr-only">Search inventory</span>
            <input type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                   class="form-input" placeholder="Name, SKU, or location">
        </label>
        <button type="submit" class="btn btn-secondary">Search</button>
    </form>
</div>

<div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
    <table class="w-full text-left text-sm">
        <thead class="bg-gray-50 text-gray-500">
            <tr>
                <th class="px-4 py-3">Name</th>
                <th class="px-4 py-3">SKU</th>
                <th class="px-4 py-3">Location</th>
                <th class="px-4 py-3">Retail Price</th>
                <th class="px-4 py-3">Current Stock</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($products === []): ?>
                <tr><td class="px-4 py-6 text-gray-500" colspan="6">No products found.</td></tr>
            <?php endif; ?>
            <?php foreach ($products as $product): ?>
                <tr class="border-t">
                    <td class="px-4 py-3 font-medium"><?= htmlspecialchars((string) $product['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="px-4 py-3"><?= htmlspecialchars((string) ($product['sku'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="px-4 py-3"><?= htmlspecialchars((string) ($product['location'] ?: 'Shop floor'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="px-4 py-3 whitespace-nowrap">KES <?= number_format((float) $product['price'], 2) ?></td>
                    <td class="px-4 py-3"><?= (int) $product['stock_qty'] ?></td>
                    <td class="px-4 py-3 text-right">
                        <button type="button" class="btn btn-secondary btn-sm"
                                data-product-id="<?= (int) $product['id'] ?>"
                                data-product-name="<?= htmlspecialchars((string) $product['name'], ENT_QUOTES, 'UTF-8') ?>"
                                data-old-stock="<?= (int) $product['stock_qty'] ?>"
                                onclick="openStockAdjustment(this)">
                            Report Discrepancy / Adjust Stock
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<dialog id="stock-adjustment" class="w-[min(100%,32rem)] rounded-2xl p-0 shadow-xl backdrop:bg-gray-900/50">
    <form method="post" action="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/attendant/api_request_approval.php" class="p-5">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($approvalCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action_type" value="stock_adjustment">
        <input type="hidden" name="redirect_to" value="/attendant/inventory.php">
        <input type="hidden" name="product_id" id="adjust-product-id" value="">
        <input type="hidden" name="old_stock" id="adjust-old-stock" value="">
        <h2 class="text-lg font-bold text-gray-900">Report Discrepancy / Adjust Stock</h2>
        <p id="adjust-product-name" class="mt-1 text-sm text-gray-500"></p>
        <p class="mt-3 text-sm text-gray-600">Current stock: <span id="adjust-current" class="font-semibold"></span></p>
        <label class="mt-4 block">
            <span class="form-label">New physical count</span>
            <input type="number" name="new_stock" id="adjust-new-stock" min="0" step="1" required class="form-input">
        </label>
        <label class="mt-3 block">
            <span class="form-label">Reason</span>
            <textarea name="reason" required minlength="3" maxlength="500" rows="3" class="form-input"
                      placeholder="Shelf count was short by two units"></textarea>
        </label>
        <div class="mt-4 flex justify-end gap-2">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('stock-adjustment').close()">Cancel</button>
            <button type="submit" class="btn btn-primary">Send to Admin</button>
        </div>
    </form>
</dialog>

<script>
function openStockAdjustment(button) {
    document.getElementById('adjust-product-id').value = button.dataset.productId || '';
    document.getElementById('adjust-old-stock').value = button.dataset.oldStock || '';
    document.getElementById('adjust-product-name').textContent = button.dataset.productName || '';
    document.getElementById('adjust-current').textContent = button.dataset.oldStock || '0';
    document.getElementById('adjust-new-stock').value = button.dataset.oldStock || '0';
    document.getElementById('stock-adjustment').showModal();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
