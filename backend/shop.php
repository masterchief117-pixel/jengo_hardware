<?php
/**
 * shop.php
 *
 * Product listing page with category filter, search, and sort.
 * Handles Add-to-Cart POST requests and returns JSON for AJAX calls
 * or redirects for plain-HTML fallback.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$pdo = db();

// ══════════════════════════════════════════════════════════════════════════
// ADD-TO-CART HANDLER (POST)
// Accepts both AJAX (expects JSON response) and plain form POST.
// ══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_to_cart') {

    $productId = (int) ($_POST['product_id'] ?? 0);
    $qty       = max(1, min(99, (int) ($_POST['qty'] ?? 1)));
    $isAjax    = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                 strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    $response  = ['success' => false, 'message' => '', 'cart_count' => 0];

    if ($productId < 1) {
        $response['message'] = 'Invalid product.';
    } else {
        // Verify product exists and has stock
        $stmt = $pdo->prepare(
            'SELECT id, name, price, stock_qty FROM products
             WHERE id = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if (!$product) {
            $response['message'] = 'Product not found.';
        } elseif ((int)$product['stock_qty'] < 1) {
            $response['message'] = 'This product is out of stock.';
        } else {
            // Cart stored in session as [product_id => qty]
            if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }

            $currentQty = (int) ($_SESSION['cart'][$productId] ?? 0);
            $newQty     = min($currentQty + $qty, (int)$product['stock_qty']);

            $_SESSION['cart'][$productId] = $newQty;

            $cartCount = array_sum($_SESSION['cart']);
            $response  = [
                'success'    => true,
                'message'    => htmlspecialchars($product['name']) . ' added to cart.',
                'cart_count' => $cartCount,
            ];
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    // Plain-HTML fallback
    if ($response['success']) {
        redirect('/shop.php', $response['message'], 'success');
    } else {
        redirect('/shop.php', $response['message'], 'error');
    }
}

// ══════════════════════════════════════════════════════════════════════════
// READ QUERY PARAMS
// ══════════════════════════════════════════════════════════════════════════

// Search
$search   = trim($_GET['q'] ?? '');

// Category slug
$catSlug  = trim($_GET['category'] ?? '');

// Sort
$sortMap  = [
    'latest'     => 'p.created_at DESC',
    'price_asc'  => 'p.price ASC',
    'price_desc' => 'p.price DESC',
    'name_asc'   => 'p.name ASC',
];
$sortKey  = array_key_exists($_GET['sort'] ?? '', $sortMap)
            ? ($_GET['sort'] ?? 'latest')
            : 'latest';
$orderBy  = $sortMap[$sortKey];

// Pagination
$perPage  = 12;
$page     = max(1, (int) ($_GET['page'] ?? 1));
$offset   = ($page - 1) * $perPage;

// ══════════════════════════════════════════════════════════════════════════
// FETCH CATEGORIES (for sidebar filter)
// ══════════════════════════════════════════════════════════════════════════
$categories = $pdo->query(
    'SELECT id, name, slug,
            (SELECT COUNT(*) FROM products p
             WHERE p.category_id = c.id AND p.is_active = 1) AS product_count
     FROM categories c
     ORDER BY name ASC'
)->fetchAll();

// Resolve active category id from slug
$activeCategoryId = null;
$activeCategoryName = '';
foreach ($categories as $cat) {
    if ($cat['slug'] === $catSlug) {
        $activeCategoryId   = (int) $cat['id'];
        $activeCategoryName = $cat['name'];
        break;
    }
}

// ══════════════════════════════════════════════════════════════════════════
// BUILD PRODUCT QUERY
// ══════════════════════════════════════════════════════════════════════════
$conditions = ['p.is_active = 1'];
$bindings   = [];

if ($search !== '') {
    $conditions[] = '(p.name LIKE ? OR p.description LIKE ? OR p.sku LIKE ?)';
    $like = '%' . $search . '%';
    $bindings = array_merge($bindings, [$like, $like, $like]);
}

if ($activeCategoryId !== null) {
    $conditions[] = 'p.category_id = ?';
    $bindings[]   = $activeCategoryId;
}

$where = 'WHERE ' . implode(' AND ', $conditions);

// Total count for pagination
$countSql = "SELECT COUNT(*) FROM products p $where";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($bindings);
$totalProducts = (int) $countStmt->fetchColumn();
$totalPages    = (int) ceil($totalProducts / $perPage);
$page          = min($page, max(1, $totalPages)); // clamp

// Products for current page
$productSql = "
    SELECT p.id, p.name, p.slug, p.price, p.stock_qty,
           p.image_url, p.low_stock_threshold, p.sku,
           c.name AS category_name, c.slug AS category_slug
    FROM   products p
    LEFT JOIN categories c ON c.id = p.category_id
    $where
    ORDER BY $orderBy
    LIMIT ? OFFSET ?
";
$productStmt = $pdo->prepare($productSql);
$productStmt->execute(array_merge($bindings, [$perPage, $offset]));
$products = $productStmt->fetchAll();

// ── Page meta ─────────────────────────────────────────────────────────────
$pageTitle = $activeCategoryName !== '' ? $activeCategoryName
           : ($search !== '' ? 'Search: ' . $search : 'Shop');

require_once __DIR__ . '/includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════
     PAGE HERO / BREADCRUMB
══════════════════════════════════════════════════════════════════════ -->
<div class="page-hero rounded-xl mb-8 -mx-0">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="<?= $base ?>/index.php">Home</a>
        <span class="breadcrumb-sep" aria-hidden="true">/</span>
        <?php if ($activeCategoryName !== ''): ?>
            <a href="<?= $base ?>/shop.php">Shop</a>
            <span class="breadcrumb-sep" aria-hidden="true">/</span>
            <span aria-current="page"><?= htmlspecialchars($activeCategoryName) ?></span>
        <?php elseif ($search !== ''): ?>
            <a href="<?= $base ?>/shop.php">Shop</a>
            <span class="breadcrumb-sep" aria-hidden="true">/</span>
            <span aria-current="page">Search results</span>
        <?php else: ?>
            <span aria-current="page">Shop</span>
        <?php endif; ?>
    </nav>

    <h1 class="text-2xl sm:text-3xl font-extrabold text-white">
        <?php if ($search !== ''): ?>
            Search results for &ldquo;<?= htmlspecialchars($search) ?>&rdquo;
        <?php elseif ($activeCategoryName !== ''): ?>
            <?= htmlspecialchars($activeCategoryName) ?>
        <?php else: ?>
            All Products
        <?php endif; ?>
    </h1>

    <?php if ($totalProducts > 0): ?>
    <p class="text-gray-400 text-sm mt-1">
        <?= number_format($totalProducts) ?> product<?= $totalProducts !== 1 ? 's' : '' ?> found
    </p>
    <?php endif; ?>
</div>


<!-- ══════════════════════════════════════════════════════════════════════
     SEARCH BAR
══════════════════════════════════════════════════════════════════════ -->
<form method="GET" action="shop.php" class="mb-6" role="search">
    <?php if ($catSlug !== ''): ?>
        <input type="hidden" name="category" value="<?= htmlspecialchars($catSlug) ?>">
    <?php endif; ?>
    <div class="relative max-w-xl">
        <label for="search-input" class="sr-only">Search products</label>
        <div class="absolute inset-y-0 left-0 flex items-center pl-3.5
                    pointer-events-none" aria-hidden="true">
            <svg class="w-5 h-5 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </div>
        <input type="search"
               id="search-input"
               name="q"
               value="<?= htmlspecialchars($search) ?>"
               placeholder="Search products, tools, materials…"
               class="form-input pl-11 pr-28"
               autocomplete="off" />
        <button type="submit"
                class="absolute inset-y-0 right-0 px-4 text-sm font-semibold
                       bg-orange text-white rounded-r-md hover:bg-orange-dark
                       transition-colors focus:outline-none focus:ring-2
                       focus:ring-orange focus:ring-offset-1">
            Search
        </button>
    </div>
</form>


<!-- ══════════════════════════════════════════════════════════════════════
     MAIN LAYOUT: SIDEBAR + PRODUCT GRID
══════════════════════════════════════════════════════════════════════ -->
<div class="flex flex-col lg:flex-row gap-8">

    <!-- ── SIDEBAR ────────────────────────────────────────────────────── -->
    <aside class="lg:w-56 xl:w-64 flex-shrink-0" aria-label="Product filters">

        <!-- Category filter -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-4">
            <h2 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-4">
                Categories
            </h2>
            <ul class="space-y-1" role="list">
                <li>
                    <a href="<?= $base ?>/shop.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>"
                       class="flex items-center justify-between px-3 py-2 rounded-lg
                              text-sm transition-colors
                              <?= $catSlug === '' ? 'bg-orange-subtle text-orange font-semibold' : 'text-gray-600 hover:bg-gray-50' ?>">
                        <span>All Products</span>
                        <span class="text-xs text-gray-400 font-normal">
                            <?= number_format($totalProducts) ?>
                        </span>
                    </a>
                </li>
                <?php foreach ($categories as $cat): ?>
                <li>
                    <a href="<?= $base ?>/shop.php?category=<?= urlencode($cat['slug']) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"
                       class="flex items-center justify-between px-3 py-2 rounded-lg
                              text-sm transition-colors
                              <?= $cat['slug'] === $catSlug ? 'bg-orange-subtle text-orange font-semibold' : 'text-gray-600 hover:bg-gray-50' ?>">
                        <span><?= htmlspecialchars($cat['name']) ?></span>
                        <span class="text-xs text-gray-400 font-normal">
                            <?= (int)$cat['product_count'] ?>
                        </span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Clear filters -->
        <?php if ($search !== '' || $catSlug !== ''): ?>
        <a href="<?= $base ?>/shop.php"
           class="flex items-center gap-2 text-sm text-red-500
                  hover:text-red-700 font-medium px-1 transition-colors">
            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor"
                 stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M6 18L18 6M6 6l12 12"/>
            </svg>
            Clear filters
        </a>
        <?php endif; ?>
    </aside>


    <!-- ── PRODUCT GRID ───────────────────────────────────────────────── -->
    <div class="flex-1 min-w-0">

        <!-- Toolbar: result count + sort -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between
                    gap-3 mb-5">
            <p class="text-sm text-gray-500">
                <?php if ($totalProducts === 0): ?>
                    No products found.
                <?php else: ?>
                    Showing
                    <strong class="text-gray-700">
                        <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalProducts)) ?>
                    </strong>
                    of <strong class="text-gray-700"><?= number_format($totalProducts) ?></strong>
                    product<?= $totalProducts !== 1 ? 's' : '' ?>
                <?php endif; ?>
            </p>

            <!-- Sort -->
            <form method="GET" action="shop.php" id="sort-form">
                <?php if ($catSlug !== ''): ?>
                    <input type="hidden" name="category" value="<?= htmlspecialchars($catSlug) ?>">
                <?php endif; ?>
                <?php if ($search !== ''): ?>
                    <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
                <?php endif; ?>
                <label for="sort-select" class="sr-only">Sort products</label>
                <select id="sort-select"
                        name="sort"
                        class="form-select text-sm py-2 w-44"
                        onchange="document.getElementById('sort-form').submit()">
                    <option value="latest"     <?= $sortKey === 'latest'     ? 'selected' : '' ?>>Latest</option>
                    <option value="price_asc"  <?= $sortKey === 'price_asc'  ? 'selected' : '' ?>>Price: Low to High</option>
                    <option value="price_desc" <?= $sortKey === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
                    <option value="name_asc"   <?= $sortKey === 'name_asc'   ? 'selected' : '' ?>>Name A–Z</option>
                </select>
            </form>
        </div>

        <!-- Empty state -->
        <?php if (empty($products)): ?>
        <div class="bg-white rounded-2xl border border-dashed border-gray-200
                    py-20 text-center" role="status">
            <div class="text-6xl mb-4" aria-hidden="true">🔍</div>
            <h3 class="text-lg font-semibold text-gray-700 mb-2">
                No products found
            </h3>
            <p class="text-gray-400 text-sm mb-6">
                <?= $search !== '' ? 'Try a different search term or' : 'Try' ?>
                browsing all categories.
            </p>
            <a href="<?= $base ?>/shop.php" class="btn btn-primary">
                View All Products
            </a>
        </div>

        <?php else: ?>

        <!-- Product grid -->
        <div class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-5"
             id="product-grid">
            <?php foreach ($products as $product):
                $stockQty  = (int) $product['stock_qty'];
                $threshold = (int) $product['low_stock_threshold'];
                $inStock   = $stockQty > 0;
                $lowStock  = $inStock && $stockQty <= $threshold;
            ?>
            <article class="product-card group"
                     aria-label="<?= htmlspecialchars($product['name']) ?>">

                <!-- Image -->
                <div class="product-card__image-wrap">
                    <?php if ($product['image_url']): ?>
                        <img src="<?= htmlspecialchars($product['image_url']) ?>"
                             alt="<?= htmlspecialchars($product['name']) ?>"
                             loading="lazy" />
                    <?php else: ?>
                        <div class="w-full h-full flex items-center justify-center
                                    bg-gray-100 text-gray-300" aria-hidden="true">
                            <svg class="w-14 h-14" xmlns="http://www.w3.org/2000/svg"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                 stroke-width="1">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M4 16l4.586-4.586a2 2 0 012.828
                                         0L16 16m-2-2l1.586-1.586a2 2 0
                                         012.828 0L20 14m-6-6h.01M6 20h12
                                         a2 2 0 002-2V6a2 2 0 00-2-2H6a2
                                         2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </div>
                    <?php endif; ?>

                    <!-- Stock badge -->
                    <?php if (!$inStock): ?>
                        <span class="product-card__badge product-card__badge--out-of-stock">
                            Out of Stock
                        </span>
                    <?php elseif ($lowStock): ?>
                        <span class="product-card__badge product-card__badge--low-stock">
                            Only <?= $stockQty ?> left
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Body -->
                <div class="product-card__body">
                    <?php if ($product['category_name']): ?>
                    <p class="product-card__category">
                        <a href="<?= $base ?>/shop.php?category=<?= urlencode($product['category_slug'] ?? '') ?>"
                           class="hover:text-orange transition-colors">
                            <?= htmlspecialchars($product['category_name']) ?>
                        </a>
                    </p>
                    <?php endif; ?>

                    <h3 class="product-card__name">
                        <a href="<?= $base ?>/shop.php?product=<?= $product['id'] ?>"
                           class="hover:text-orange transition-colors">
                            <?= htmlspecialchars($product['name']) ?>
                        </a>
                    </h3>

                    <!-- SKU -->
                    <?php if ($product['sku']): ?>
                    <p class="text-xs text-gray-400 mt-1">
                        SKU: <?= htmlspecialchars($product['sku']) ?>
                    </p>
                    <?php endif; ?>

                    <p class="product-card__price">
                        KES <?= number_format((float)$product['price'], 2) ?>
                    </p>
                </div>

                <!-- Footer: Add to Cart -->
                <div class="px-4 pb-4">
                    <?php if ($inStock): ?>
                    <form method="POST" action="shop.php"
                          class="add-to-cart-form"
                          data-product-id="<?= $product['id'] ?>"
                          data-product-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>">
                        <input type="hidden" name="action"     value="add_to_cart">
                        <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                        <input type="hidden" name="qty"        value="1">
                        <button type="submit"
                                class="btn btn-primary btn-sm btn-block add-to-cart-btn">
                            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                 stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M3 3h2l.4 2M7 13h10l4-8H5.4M7
                                         13L5.4 5M7 13l-2 9m12-9l2 9m-9-4h4"/>
                            </svg>
                            Add to Cart
                        </button>
                    </form>
                    <?php else: ?>
                    <button type="button" disabled
                            class="btn btn-sm btn-block bg-gray-100 text-gray-400
                                   border-0 cursor-not-allowed"
                            aria-disabled="true">
                        Out of Stock
                    </button>
                    <?php endif; ?>
                </div>

            </article>
            <?php endforeach; ?>
        </div><!-- /#product-grid -->


        <!-- ── PAGINATION ─────────────────────────────────────────────── -->
        <?php if ($totalPages > 1): ?>
        <nav class="flex items-center justify-center gap-1 mt-10"
             aria-label="Product pages">
            <?php
            // Build base URL for pagination links
            $paginationParams = [];
            if ($search  !== '') $paginationParams['q']        = $search;
            if ($catSlug !== '') $paginationParams['category']  = $catSlug;
            if ($sortKey !== 'latest') $paginationParams['sort'] = $sortKey;

            function paginationUrl(int $p, array $params, string $base): string
            {
                $params['page'] = $p;
                return $base . '/shop.php?' . http_build_query($params);
            }

            $prevPage = $page - 1;
            $nextPage = $page + 1;
            ?>

            <!-- Previous -->
            <?php if ($page > 1): ?>
            <a href="<?= paginationUrl($prevPage, $paginationParams, $base) ?>"
               class="px-3 py-2 text-sm rounded-lg border border-gray-200
                      text-gray-600 hover:border-orange hover:text-orange
                      transition-colors"
               aria-label="Previous page">
                &lsaquo;
            </a>
            <?php endif; ?>

            <!-- Page numbers -->
            <?php
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);
            if ($start > 1): ?>
                <a href="<?= paginationUrl(1, $paginationParams, $base) ?>"
                   class="px-3 py-2 text-sm rounded-lg border border-gray-200
                          text-gray-600 hover:border-orange hover:text-orange
                          transition-colors">1</a>
                <?php if ($start > 2): ?>
                    <span class="px-2 py-2 text-gray-400 text-sm">&hellip;</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($p = $start; $p <= $end; $p++): ?>
            <a href="<?= paginationUrl($p, $paginationParams, $base) ?>"
               class="px-3 py-2 text-sm rounded-lg border transition-colors
                      <?= $p === $page
                          ? 'bg-orange border-orange text-white font-semibold'
                          : 'border-gray-200 text-gray-600 hover:border-orange hover:text-orange' ?>"
               <?= $p === $page ? 'aria-current="page"' : '' ?>>
                <?= $p ?>
            </a>
            <?php endfor; ?>

            <?php if ($end < $totalPages): ?>
                <?php if ($end < $totalPages - 1): ?>
                    <span class="px-2 py-2 text-gray-400 text-sm">&hellip;</span>
                <?php endif; ?>
                <a href="<?= paginationUrl($totalPages, $paginationParams, $base) ?>"
                   class="px-3 py-2 text-sm rounded-lg border border-gray-200
                          text-gray-600 hover:border-orange hover:text-orange
                          transition-colors"><?= $totalPages ?></a>
            <?php endif; ?>

            <!-- Next -->
            <?php if ($page < $totalPages): ?>
            <a href="<?= paginationUrl($nextPage, $paginationParams, $base) ?>"
               class="px-3 py-2 text-sm rounded-lg border border-gray-200
                      text-gray-600 hover:border-orange hover:text-orange
                      transition-colors"
               aria-label="Next page">
                &rsaquo;
            </a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>

        <?php endif; // end products not empty ?>

    </div><!-- /product grid column -->
</div><!-- /sidebar + grid flex row -->


<!-- ══════════════════════════════════════════════════════════════════════
     TOAST NOTIFICATION (AJAX cart feedback)
══════════════════════════════════════════════════════════════════════ -->
<div id="cart-toast"
     class="fixed bottom-6 right-6 z-50 hidden max-w-sm w-full"
     role="status" aria-live="polite" aria-atomic="true">
    <div class="flex items-start gap-3 bg-gray-900 text-white
                px-4 py-3 rounded-xl shadow-2xl border border-gray-700">
        <span id="cart-toast-icon" class="text-lg flex-shrink-0 mt-0.5"
              aria-hidden="true">✓</span>
        <p id="cart-toast-msg" class="text-sm font-medium flex-1"></p>
        <button onclick="hideToast()"
                class="text-gray-400 hover:text-white ml-1 flex-shrink-0"
                aria-label="Dismiss notification">&times;</button>
    </div>
</div>


<!-- Page-specific JS: AJAX add-to-cart + toast ───────────────────────── -->
<script>
(function () {
    var toast        = document.getElementById('cart-toast');
    var toastMsg     = document.getElementById('cart-toast-msg');
    var toastIcon    = document.getElementById('cart-toast-icon');
    var toastTimer   = null;

    window.hideToast = function () {
        if (toast) {
            toast.classList.add('hidden');
            clearTimeout(toastTimer);
        }
    };

    function showToast(message, success) {
        if (!toast || !toastMsg) return;
        toastMsg.textContent  = message;
        toastIcon.textContent = success ? '✓' : '✕';
        toastIcon.className   = 'text-lg flex-shrink-0 mt-0.5 ' +
                                (success ? 'text-green-400' : 'text-red-400');
        toast.classList.remove('hidden');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(window.hideToast, 4000);
    }

    // Intercept all Add-to-Cart forms with AJAX
    document.querySelectorAll('.add-to-cart-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var btn          = form.querySelector('.add-to-cart-btn');
            var originalHtml = btn ? btn.innerHTML : '';

            if (btn) {
                btn.disabled  = true;
                btn.innerHTML = '<span class="spinner" aria-hidden="true"></span>';
            }

            var data = new FormData(form);

            fetch(form.action, {
                method:  'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body:    data,
            })
            .then(function (res) {
                if (!res.ok) throw new Error('Network error');
                return res.json();
            })
            .then(function (json) {
                showToast(json.message, json.success);

                if (json.success) {
                    // Update cart badge via main.js helper
                    if (window.Jengo && window.Jengo.CartBadge) {
                        window.Jengo.CartBadge.update(json.cart_count);
                    }
                    // Also update data-cart-badge elements
                    document.querySelectorAll('[data-cart-badge]').forEach(function (el) {
                        el.textContent = json.cart_count > 9 ? '9+' : String(json.cart_count);
                        el.classList.toggle('hidden', json.cart_count < 1);
                    });
                }
            })
            .catch(function () {
                showToast('Something went wrong. Please try again.', false);
            })
            .finally(function () {
                if (btn) {
                    btn.disabled  = false;
                    btn.innerHTML = originalHtml;
                }
            });
        });
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
