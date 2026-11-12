<?php
$product = isset($product) && is_array($product) ? $product : [];
$productId = trim((string) ($product['id'] ?? ''));
$productName = trim((string) ($product['name'] ?? 'Product'));
$productImage = trim((string) ($product['image_url'] ?? ''));
$productPrice = is_numeric($product['price'] ?? null) ? (float) $product['price'] : null;
$stockQuantity = is_numeric($product['stock_qty'] ?? null) ? (int) $product['stock_qty'] : 0;

if ($stockQuantity > 5) {
    $stockLabel = 'In stock';
    $stockClasses = 'bg-green-100 text-green-800';
} elseif ($stockQuantity > 0) {
    $stockLabel = 'Low stock';
    $stockClasses = 'bg-amber-100 text-amber-800';
} else {
    $stockLabel = 'Out of stock';
    $stockClasses = 'bg-red-100 text-red-800';
}

$userRole = $_SESSION['user_role'] ?? null;
$canAddToCart = $productId !== '' && $productPrice !== null && $stockQuantity > 0;
$isStaffOrOwner = in_array($userRole, ['admin', 'attendant', 'employee', 'owner'], true);
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<article class="jengo-hover-lift flex h-full flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
    <div class="relative aspect-[4/3] overflow-hidden bg-gray-100">
        <?php if ($productImage !== ''): ?>
            <img src="<?= $escape($productImage) ?>"
                 alt="<?= $escape($productName) ?>"
                 class="h-full w-full object-cover"
                 loading="lazy">
        <?php else: ?>
            <div class="flex h-full w-full items-center justify-center bg-gradient-to-br from-gray-100 to-gray-200"
                 role="img" aria-label="<?= $escape($productName) ?>">
                <svg class="h-16 w-16 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M4 7h16v13H4zM8 7V5a4 4 0 018 0v2m-8 6h8"/>
                </svg>
            </div>
        <?php endif; ?>
        <span class="absolute left-3 top-3 rounded-full px-3 py-1 text-xs font-bold <?= $stockClasses ?>">
            <?= $escape($stockLabel) ?>
        </span>
    </div>

    <div class="flex flex-1 flex-col p-5">
        <h2 class="line-clamp-2 text-lg font-bold text-gray-900"><?= $escape($productName) ?></h2>
        <?php if (isset($product['description']) && trim((string) $product['description']) !== ''): ?>
            <p class="mt-2 line-clamp-2 text-sm leading-6 text-gray-600"><?= $escape(trim((string) $product['description'])) ?></p>
        <?php endif; ?>

        <div class="mt-auto pt-5">
            <p class="text-xl font-extrabold text-gray-900">
                <?php if ($productPrice !== null): ?>
                    <span class="sr-only">Price: </span>KES <?= number_format($productPrice, 2) ?>
                <?php else: ?>
                    <span class="text-sm font-medium text-gray-500">Price unavailable</span>
                <?php endif; ?>
            </p>
            <?php if ($isStaffOrOwner): ?>
            <button type="button" disabled
                    class="jengo-hover-transition mt-4 inline-flex w-full items-center justify-center rounded-lg border border-gray-300 px-4 py-3 text-sm font-bold text-gray-600"
                    aria-disabled="true">
                <?= $userRole === 'owner' ? 'Owner View' : 'Staff View' ?>
            </button>
            <?php else: ?>
            <button type="button"
                    class="jengo-hover-transition mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg px-4 py-3 text-sm font-bold text-white hover:brightness-90 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-gray-300"
                    style="<?= $canAddToCart ? 'background-color: var(--jengo-safety-orange);' : '' ?>"
                    data-add-to-cart
                    data-product-id="<?= $escape($productId) ?>"
                    data-product-name="<?= $escape($productName) ?>"
                    data-product-price="<?= $productPrice !== null ? $escape((string) $productPrice) : '' ?>"
                    <?= $canAddToCart ? '' : 'disabled' ?>>
                <?php if ($stockQuantity > 0): ?>
                    Add to Cart
                <?php else: ?>
                    Out of Stock
                <?php endif; ?>
            </button>
            <?php endif; ?>
        </div>
    </div>
</article>