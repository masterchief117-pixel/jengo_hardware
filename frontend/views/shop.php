<?php
declare(strict_types=1);

$pageTitle = 'Shop Tools & Building Supplies';

$allProducts = isset($products) && is_array($products) ? $products : [];
$categoryList = isset($categories) && is_array($categories) ? $categories : [];
$selectedCategory = isset($_GET['category']) && is_scalar($_GET['category'])
    ? trim((string) $_GET['category'])
    : '';
$searchQuery = isset($_GET['q']) && is_scalar($_GET['q'])
    ? trim((string) $_GET['q'])
    : '';

$categoryOptions = [];
foreach ($categoryList as $category) {
    if (!is_array($category)) {
        continue;
    }

    $categoryId = trim((string) ($category['id'] ?? ''));
    $categorySlug = trim((string) ($category['slug'] ?? $categoryId));
    $categoryName = trim((string) ($category['name'] ?? ''));

    if ($categorySlug !== '' && $categoryName !== '') {
        $categoryOptions[$categorySlug] = $categoryName;
    }
}

if ($categoryOptions === []) {
    foreach ($allProducts as $product) {
        if (!is_array($product)) {
            continue;
        }

        $categorySlug = trim((string) ($product['category_slug'] ?? ''));
        $categoryName = trim((string) ($product['category_name'] ?? ''));
        if ($categorySlug !== '' && $categoryName !== '') {
            $categoryOptions[$categorySlug] = $categoryName;
        }
    }
}

$visibleProducts = array_values(array_filter(
    $allProducts,
    static function ($product) use ($selectedCategory, $searchQuery): bool {
        if (!is_array($product)) {
            return false;
        }

        if ($selectedCategory !== '') {
            $productCategory = (string) ($product['category_slug'] ?? $product['category_id'] ?? '');
            if ($productCategory !== $selectedCategory) {
                return false;
            }
        }

        if ($searchQuery !== '') {
            $searchable = implode(' ', array_filter([
                (string) ($product['name'] ?? ''),
                (string) ($product['description'] ?? ''),
                (string) ($product['sku'] ?? ''),
                (string) ($product['category_name'] ?? ''),
            ]));

            if (stripos($searchable, $searchQuery) === false) {
                return false;
            }
        }

        return true;
    }
));

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
require __DIR__ . '/../components/header.php';
?>

<main class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    <header class="mb-8">
        <p class="text-sm font-bold uppercase tracking-widest" style="color: var(--jengo-safety-orange);">Jengo Hardware</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl">Shop hardware</h1>
        <p class="mt-3 max-w-2xl text-gray-600">Find the tools and building supplies you need for your next project.</p>
    </header>

    <div class="lg:grid lg:grid-cols-[16rem_minmax(0,1fr)] lg:items-start lg:gap-8">
        <aside class="mb-8 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm lg:sticky lg:top-6 lg:mb-0"
               aria-label="Product filters">
            <h2 class="text-lg font-bold text-gray-900">Find products</h2>
            <form method="get" action="shop.php" class="mt-4 space-y-5">
                <div>
                    <label for="shop-search" class="mb-2 block text-sm font-semibold text-gray-700">Search</label>
                    <div class="flex">
                        <input id="shop-search"
                               name="q"
                               type="search"
                               value="<?= $escape($searchQuery) ?>"
                               placeholder="Search products..."
                               class="min-w-0 flex-1 rounded-l-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-900 placeholder:text-gray-400 focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20">
                        <button type="submit"
                                class="rounded-r-lg px-4 py-2.5 text-sm font-bold text-white hover:brightness-90 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2"
                                style="background-color: var(--jengo-safety-orange);">
                            Search
                        </button>
                    </div>
                </div>

                <fieldset>
                    <legend class="mb-2 text-sm font-semibold text-gray-700">Categories</legend>
                    <div class="space-y-1">
                        <a href="shop.php<?= $searchQuery !== '' ? '?q=' . rawurlencode($searchQuery) : '' ?>"
                           class="flex items-center justify-between rounded-lg px-3 py-2 text-sm <?= $selectedCategory === '' ? 'bg-orange-50 font-semibold text-orange-800' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' ?>">
                            <span>All categories</span>
                            <?php if ($selectedCategory === ''): ?>
                                <span class="sr-only">Selected</span>
                            <?php endif; ?>
                        </a>
                        <?php foreach ($categoryOptions as $slug => $name): ?>
                            <?php
                            $categoryUrl = 'shop.php?' . http_build_query(array_filter([
                                'category' => $slug,
                                'q' => $searchQuery !== '' ? $searchQuery : null,
                            ], static fn ($value): bool => $value !== null));
                            ?>
                            <a href="<?= $escape($categoryUrl) ?>"
                               class="flex items-center justify-between rounded-lg px-3 py-2 text-sm <?= $selectedCategory === (string) $slug ? 'bg-orange-50 font-semibold text-orange-800' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' ?>">
                                <span><?= $escape((string) $name) ?></span>
                                <?php if ($selectedCategory === (string) $slug): ?>
                                    <span class="sr-only">Selected</span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($categoryOptions === []): ?>
                            <p class="px-3 py-2 text-sm text-gray-500">No categories available.</p>
                        <?php endif; ?>
                    </div>
                </fieldset>

                <?php if ($selectedCategory !== '' || $searchQuery !== ''): ?>
                    <a href="shop.php" class="inline-flex text-sm font-semibold text-gray-600 underline decoration-gray-300 underline-offset-4 hover:text-gray-900">
                        Clear filters
                    </a>
                <?php endif; ?>
            </form>
        </aside>

        <section aria-labelledby="product-results-heading">
            <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="product-results-heading" class="text-xl font-bold text-gray-900">
                        <?php if ($selectedCategory !== '' && isset($categoryOptions[$selectedCategory])): ?>
                            <?= $escape($categoryOptions[$selectedCategory]) ?>
                        <?php elseif ($searchQuery !== ''): ?>
                            Search results
                        <?php else: ?>
                            All products
                        <?php endif; ?>
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">
                        <?= count($visibleProducts) ?> product<?= count($visibleProducts) === 1 ? '' : 's' ?> found
                        <?php if ($searchQuery !== ''): ?>
                            for “<?= $escape($searchQuery) ?>”
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <?php if ($visibleProducts !== []): ?>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    <?php foreach ($visibleProducts as $product): ?>
                        <?php
                        if (!isset($product['stock_qty']) && isset($product['stock'])) {
                            $product['stock_qty'] = $product['stock'];
                        }
                        require __DIR__ . '/../components/product_card.php';
                        ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M3 3h2l2.2 11.2a2 2 0 002 1.6h8.9a2 2 0 002-1.6L22 7H6M10 21h.01M18 21h.01"/>
                    </svg>
                    <h3 class="mt-4 text-lg font-bold text-gray-900">No products found</h3>
                    <p class="mt-2 text-sm text-gray-600">
                        <?php if ($searchQuery !== '' || $selectedCategory !== ''): ?>
                            Try another search or clear the selected filters.
                        <?php else: ?>
                            Products will appear here when they are available.
                        <?php endif; ?>
                    </p>
                    <?php if ($searchQuery !== '' || $selectedCategory !== ''): ?>
                        <a href="shop.php"
                           class="mt-5 inline-flex rounded-lg px-4 py-2.5 text-sm font-bold text-white hover:brightness-90"
                           style="background-color: var(--jengo-safety-orange);">
                            Clear filters
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<?php require __DIR__ . '/../components/footer.php'; ?>