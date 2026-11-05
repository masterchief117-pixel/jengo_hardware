<?php
/**
 * admin/products.php
 *
 * Full product CRUD for admins.
 * Actions (via GET ?action= or POST action field):
 *   list   (default) — paginated product table
 *   add    — show blank form
 *   edit   — show pre-filled form (?edit=ID)
 *   save   — POST: insert or update product
 *   delete — POST: soft-delete (is_active = 0)
 *   stock  — POST: quick stock adjustment
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin_login();

$pdo = db();

// ══════════════════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════════════════

/**
 * Generate a URL-safe slug from a product name.
 */
function make_slug(string $name, int $excludeId = 0, \PDO $pdo): string
{
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
    $slug = $base;
    $i    = 1;

    while (true) {
        $stmt = $pdo->prepare(
            'SELECT id FROM products WHERE slug = ? AND id != ? LIMIT 1'
        );
        $stmt->execute([$slug, $excludeId]);
        if (!$stmt->fetch()) break;
        $slug = $base . '-' . (++$i);
    }

    return $slug;
}

/**
 * Handle image upload.
 * Returns the web-accessible path string on success, null on skip, throws on error.
 */
function handle_image_upload(array $file, int $productId): ?string
{
    if ($file['error'] === UPLOAD_ERR_NO_FILE) return null;

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new \RuntimeException('Upload error code: ' . $file['error']);
    }

    $maxBytes  = 2 * 1024 * 1024; // 2 MB
    $allowed   = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    if ($file['size'] > $maxBytes) {
        throw new \RuntimeException('Image must be 2 MB or smaller.');
    }

    // Validate MIME type from file content, not the browser header
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) {
        throw new \RuntimeException('Only JPEG, PNG, WebP, or GIF images are accepted.');
    }

    $ext     = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    };

    $dir = __DIR__ . '/../assets/img/products/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = 'product-' . $productId . '-' . time() . '.' . $ext;
    $dest     = $dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new \RuntimeException('Failed to save uploaded image.');
    }

    return '/assets/img/products/' . $filename;
}

// ══════════════════════════════════════════════════════════════════════════
// POST HANDLER
// ══════════════════════════════════════════════════════════════════════════
$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── SAVE (add or edit) ────────────────────────────────────────────────
    if ($action === 'save') {
        $id          = (int) ($_POST['product_id'] ?? 0);   // 0 = new
        $categoryId  = (int) ($_POST['category_id'] ?? 0);
        $name        = trim($_POST['name']        ?? '');
        $description = trim($_POST['description'] ?? '');
        $sku         = trim($_POST['sku']          ?? '');
        $price       = (float) ($_POST['price']       ?? 0);
        $costPrice   = (float) ($_POST['cost_price']  ?? 0);
        $stockQty    = (int)   ($_POST['stock_qty']   ?? 0);
        $threshold   = (int)   ($_POST['low_stock_threshold'] ?? 5);
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        // Validate
        if ($name === '')         $errors['name']  = 'Product name is required.';
        if (mb_strlen($name) > 200) $errors['name'] = 'Name must be 200 characters or fewer.';
        if ($price <= 0)          $errors['price'] = 'Price must be greater than zero.';
        if ($stockQty < 0)        $errors['stock_qty'] = 'Stock cannot be negative.';
        if ($threshold < 0)       $threshold = 0;
        if ($categoryId < 1)      $categoryId = null;

        // SKU uniqueness
        if ($sku !== '') {
            $s = $pdo->prepare(
                'SELECT id FROM products WHERE sku = ? AND id != ? LIMIT 1'
            );
            $s->execute([$sku, $id]);
            if ($s->fetch()) $errors['sku'] = 'This SKU is already used by another product.';
        }

        if (empty($errors)) {
            $slug = make_slug($name, $id, $pdo);

            if ($id === 0) {
                // INSERT
                $pdo->prepare(
                    'INSERT INTO products
                        (category_id, name, slug, description, sku,
                         price, cost_price, stock_qty, low_stock_threshold, is_active)
                     VALUES (?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $categoryId, $name, $slug, $description ?: null,
                    $sku ?: null, $price, $costPrice,
                    $stockQty, $threshold, $isActive,
                ]);
                $id = (int) $pdo->lastInsertId();
            } else {
                // UPDATE
                $pdo->prepare(
                    'UPDATE products
                     SET category_id = ?, name = ?, slug = ?, description = ?,
                         sku = ?, price = ?, cost_price = ?,
                         stock_qty = ?, low_stock_threshold = ?, is_active = ?
                     WHERE id = ?'
                )->execute([
                    $categoryId, $name, $slug, $description ?: null,
                    $sku ?: null, $price, $costPrice,
                    $stockQty, $threshold, $isActive, $id,
                ]);
            }

            // Image upload (runs after insert so we have the product ID)
            if (!empty($_FILES['image']['name'])) {
                try {
                    $imagePath = handle_image_upload($_FILES['image'], $id);
                    if ($imagePath !== null) {
                        $pdo->prepare(
                            'UPDATE products SET image_url = ? WHERE id = ?'
                        )->execute([$imagePath, $id]);
                    }
                } catch (\RuntimeException $e) {
                    $errors['image'] = $e->getMessage();
                }
            }

            if (empty($errors)) {
                redirect(
                    '/admin/products.php',
                    ($id ? 'Product updated' : 'Product added') . ' successfully.',
                    'success'
                );
            }
        }

        // Fall through to re-show form with errors
        $_GET['action'] = $id > 0 ? 'edit' : 'add';
        if ($id > 0) $_GET['edit'] = $id;
    }

    // ── DELETE (soft) ─────────────────────────────────────────────────────
    elseif ($action === 'delete') {
        $id = (int) ($_POST['product_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare(
                'UPDATE products SET is_active = 0 WHERE id = ?'
            )->execute([$id]);
            redirect('/admin/products.php', 'Product removed from the shop.', 'info');
        }
    }

    // ── RESTORE (un-delete) ───────────────────────────────────────────────
    elseif ($action === 'restore') {
        $id = (int) ($_POST['product_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare(
                'UPDATE products SET is_active = 1 WHERE id = ?'
            )->execute([$id]);
            redirect('/admin/products.php', 'Product restored.', 'success');
        }
    }

    // ── STOCK ADJUSTMENT ──────────────────────────────────────────────────
    elseif ($action === 'stock') {
        $id       = (int)   ($_POST['product_id'] ?? 0);
        $newStock = max(0, (int) ($_POST['stock_qty'] ?? 0));
        if ($id > 0) {
            $pdo->prepare(
                'UPDATE products SET stock_qty = ? WHERE id = ?'
            )->execute([$newStock, $id]);
            redirect('/admin/products.php', 'Stock updated.', 'success');
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════
// GET ROUTING
// ══════════════════════════════════════════════════════════════════════════
$view     = $_GET['action'] ?? 'list';
$editId   = (int) ($_GET['edit'] ?? 0);
$editProduct = null;

// Fetch categories for form dropdowns
$categories = $pdo->query(
    'SELECT id, name FROM categories ORDER BY name ASC'
)->fetchAll();

if ($view === 'edit' && $editId > 0) {
    $stmt = $pdo->prepare(
        'SELECT * FROM products WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$editId]);
    $editProduct = $stmt->fetch();
    if (!$editProduct) {
        redirect('/admin/products.php', 'Product not found.', 'error');
    }
}

// ── LIST VIEW: paginated product table ────────────────────────────────────
$search      = trim($_GET['q']        ?? '');
$filterCat   = (int) ($_GET['cat']    ?? 0);
$filterStock = $_GET['stock'] ?? '';   // 'low' | 'out' | ''
$showInactive= isset($_GET['inactive']);
$perPage     = 15;
$page        = max(1, (int) ($_GET['page'] ?? 1));
$offset      = ($page - 1) * $perPage;

$conditions  = [];
$bindings    = [];

if (!$showInactive) {
    $conditions[] = 'p.is_active = 1';
}
if ($search !== '') {
    $conditions[] = '(p.name LIKE ? OR p.sku LIKE ?)';
    $like = '%' . $search . '%';
    $bindings = array_merge($bindings, [$like, $like]);
}
if ($filterCat > 0) {
    $conditions[] = 'p.category_id = ?';
    $bindings[]   = $filterCat;
}
if ($filterStock === 'out') {
    $conditions[] = 'p.stock_qty = 0';
} elseif ($filterStock === 'low') {
    $conditions[] = 'p.stock_qty > 0 AND p.stock_qty <= p.low_stock_threshold';
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$totalStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM products p $where"
);
$totalStmt->execute($bindings);
$totalProducts = (int) $totalStmt->fetchColumn();
$totalPages    = (int) ceil($totalProducts / $perPage);
$page          = min($page, max(1, $totalPages ?: 1));

$productStmt = $pdo->prepare(
    "SELECT p.*, c.name AS category_name
     FROM   products p
     LEFT JOIN categories c ON c.id = p.category_id
     $where
     ORDER BY p.updated_at DESC
     LIMIT ? OFFSET ?"
);
$productStmt->execute(array_merge($bindings, [$perPage, $offset]));
$products = $productStmt->fetchAll();

// ── Page meta ─────────────────────────────────────────────────────────────
$pageTitle = $view === 'add'  ? 'Add Product'
           : ($view === 'edit' ? 'Edit Product'
           : 'Products');

require_once __DIR__ . '/../includes/header.php';

// ── Reusable product form (add + edit share the same template) ────────────
function render_product_form(
    array $cats,
    array $errors,
    string $base,
    ?array $p = null
): void {
    $isEdit = $p !== null;
    $v = function(string $key, mixed $default = '') use ($p): mixed {
        return $p[$key] ?? $default;
    };
    ?>
    <form method="POST"
          action="<?= $base ?>/admin/products.php"
          enctype="multipart/form-data"
          data-submit-guard
          aria-label="<?= $isEdit ? 'Edit' : 'Add' ?> product form">

        <input type="hidden" name="action"     value="save">
        <input type="hidden" name="product_id" value="<?= $isEdit ? (int)$p['id'] : 0 ?>">

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- ── LEFT: core fields ─────────────────────────────────── -->
            <div class="lg:col-span-2 space-y-5">

                <!-- Name -->
                <div class="form-group">
                    <label for="name" class="form-label">
                        Product Name <span class="required">*</span>
                    </label>
                    <input type="text" id="name" name="name"
                           class="form-input <?= isset($errors['name']) ? 'is-error' : '' ?>"
                           value="<?= htmlspecialchars((string)$v('name')) ?>"
                           maxlength="200" required />
                    <?php if (isset($errors['name'])): ?>
                    <p class="form-error-msg" role="alert">
                        <?= htmlspecialchars($errors['name']) ?>
                    </p>
                    <?php endif; ?>
                </div>

                <!-- Description -->
                <div class="form-group">
                    <label for="description" class="form-label">Description</label>
                    <textarea id="description" name="description"
                              class="form-textarea" rows="4"
                              placeholder="Product details, specifications, usage…"><?= htmlspecialchars((string)$v('description')) ?></textarea>
                </div>

                <!-- Price + Cost -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="form-group mb-0">
                        <label for="price" class="form-label">
                            Selling Price (KES) <span class="required">*</span>
                        </label>
                        <input type="number" id="price" name="price" step="0.01" min="0.01"
                               class="form-input <?= isset($errors['price']) ? 'is-error' : '' ?>"
                               value="<?= htmlspecialchars((string)$v('price', '')) ?>"
                               required />
                        <?php if (isset($errors['price'])): ?>
                        <p class="form-error-msg" role="alert">
                            <?= htmlspecialchars($errors['price']) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <div class="form-group mb-0">
                        <label for="cost_price" class="form-label">
                            Cost Price (KES)
                        </label>
                        <input type="number" id="cost_price" name="cost_price"
                               step="0.01" min="0"
                               class="form-input"
                               value="<?= htmlspecialchars((string)$v('cost_price', '')) ?>" />
                    </div>
                </div>

                <!-- SKU + Category -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="form-group mb-0">
                        <label for="sku" class="form-label">SKU / Barcode</label>
                        <input type="text" id="sku" name="sku"
                               class="form-input <?= isset($errors['sku']) ? 'is-error' : '' ?>"
                               value="<?= htmlspecialchars((string)$v('sku')) ?>"
                               maxlength="80"
                               placeholder="e.g. HT-001" />
                        <?php if (isset($errors['sku'])): ?>
                        <p class="form-error-msg" role="alert">
                            <?= htmlspecialchars($errors['sku']) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <div class="form-group mb-0">
                        <label for="category_id" class="form-label">Category</label>
                        <select id="category_id" name="category_id" class="form-select">
                            <option value="0">— None —</option>
                            <?php foreach ($cats as $cat): ?>
                            <option value="<?= $cat['id'] ?>"
                                <?= (int)$v('category_id', 0) === (int)$cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Stock + Threshold -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="form-group mb-0">
                        <label for="stock_qty" class="form-label">
                            Stock Quantity <span class="required">*</span>
                        </label>
                        <input type="number" id="stock_qty" name="stock_qty"
                               min="0" step="1"
                               class="form-input <?= isset($errors['stock_qty']) ? 'is-error' : '' ?>"
                               value="<?= htmlspecialchars((string)$v('stock_qty', 0)) ?>"
                               required />
                        <?php if (isset($errors['stock_qty'])): ?>
                        <p class="form-error-msg" role="alert">
                            <?= htmlspecialchars($errors['stock_qty']) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <div class="form-group mb-0">
                        <label for="low_stock_threshold" class="form-label">
                            Low Stock Alert at
                        </label>
                        <input type="number" id="low_stock_threshold"
                               name="low_stock_threshold" min="0" step="1"
                               class="form-input"
                               value="<?= htmlspecialchars((string)$v('low_stock_threshold', 5)) ?>" />
                        <p class="form-hint">Alert when stock falls to this number.</p>
                    </div>
                </div>

            </div>
            <!-- /left -->

            <!-- ── RIGHT: image + status ─────────────────────────────── -->
            <div class="space-y-5">

                <!-- Image upload -->
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <p class="text-sm font-semibold text-gray-700 mb-3">
                        Product Image
                    </p>

                    <!-- Current image preview -->
                    <?php $img = $v('image_url', ''); ?>
                    <div id="image-preview"
                         class="w-full aspect-square rounded-lg bg-gray-100
                                overflow-hidden mb-3 flex items-center
                                justify-center border border-gray-200">
                        <?php if ($img): ?>
                        <img src="<?= htmlspecialchars($base . $img) ?>"
                             alt="Current product image"
                             class="w-full h-full object-cover"
                             id="img-thumb" />
                        <?php else: ?>
                        <div id="img-placeholder"
                             class="flex flex-col items-center text-gray-300">
                            <svg class="w-12 h-12 mb-1" xmlns="http://www.w3.org/2000/svg"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                 stroke-width="1" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M4 16l4.586-4.586a2 2 0 012.828
                                         0L16 16m-2-2l1.586-1.586a2 2 0
                                         012.828 0L20 14m-6-6h.01M6 20h12
                                         a2 2 0 002-2V6a2 2 0 00-2-2H6a2
                                         2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            <span class="text-xs">No image</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <label for="image" class="btn btn-ghost btn-sm btn-block
                                              cursor-pointer text-center">
                        <?= $img ? '↑ Replace Image' : '↑ Upload Image' ?>
                    </label>
                    <input type="file" id="image" name="image"
                           accept="image/jpeg,image/png,image/webp,image/gif"
                           class="sr-only"
                           aria-describedby="image-hint" />
                    <p id="image-hint" class="form-hint text-center mt-1">
                        JPEG, PNG, WebP or GIF · max 2 MB
                    </p>
                    <?php if (isset($errors['image'])): ?>
                    <p class="form-error-msg text-center" role="alert">
                        <?= htmlspecialchars($errors['image']) ?>
                    </p>
                    <?php endif; ?>
                </div>

                <!-- Status toggle -->
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <p class="text-sm font-semibold text-gray-700 mb-3">
                        Visibility
                    </p>
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" id="is_active" name="is_active"
                               value="1"
                               class="w-4 h-4 accent-orange"
                               <?= (int)$v('is_active', 1) ? 'checked' : '' ?> />
                        <div>
                            <p class="text-sm font-medium text-gray-800">
                                Visible in shop
                            </p>
                            <p class="text-xs text-gray-400">
                                Uncheck to hide from customers.
                            </p>
                        </div>
                    </label>
                </div>

                <!-- Margin hint (computed client-side) -->
                <div class="bg-orange-subtle rounded-xl border border-orange/20 p-4
                            text-sm" id="margin-card">
                    <p class="font-semibold text-orange mb-1">Gross Margin</p>
                    <p class="text-2xl font-extrabold text-gray-900" id="margin-val">—</p>
                    <p class="text-xs text-gray-500 mt-0.5" id="margin-pct">
                        Set price and cost to calculate
                    </p>
                </div>

                <!-- Submit row -->
                <button type="submit"
                        class="btn btn-primary btn-block"
                        data-loading-text="Saving…">
                    <?= $isEdit ? 'Save Changes' : 'Add Product' ?>
                </button>

                <a href="<?= $base ?>/admin/products.php"
                   class="btn btn-ghost btn-block">
                    Cancel
                </a>
            </div>
            <!-- /right -->

        </div>
    </form>
    <?php
}
?>

<!-- ══════════════════════════════════════════════════════════════════════
     ADMIN LAYOUT
══════════════════════════════════════════════════════════════════════ -->
<div class="flex gap-0 -mx-4 sm:-mx-6 lg:-mx-8 min-h-screen">

    <!-- Sidebar (reused from admin/index.php) -->
    <aside class="admin-sidebar hidden lg:flex flex-col w-56 xl:w-60
                  sticky top-16 self-start h-[calc(100vh-4rem)] overflow-y-auto"
           aria-label="Admin navigation">
        <div class="admin-sidebar__brand">
            <svg class="w-5 h-5 text-yellow-400 flex-shrink-0"
                 xmlns="http://www.w3.org/2000/svg" fill="none"
                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"
                 aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M14.121 14.121L19 19m-7-7 7-7-3-3-7 7
                         M9.172 9.172 4.343 4.343 1.515 7.172
                         5.172 10.828l4-4z"/>
            </svg>
            Admin Panel
        </div>
        <?php
        $navLinks = [
            ['Dashboard', 'index.php',           'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
            ['Products',  'products.php',        'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10'],
            ['Orders',    'orders.php',          'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
            ['Users',     'users.php',           'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
            ['Payments',  'payments_record.php', 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z'],
        ];
        $cur = basename($_SERVER['PHP_SELF']);
        foreach ($navLinks as [$lbl, $file, $icon]):
            $active = $file === $cur ? 'active' : '';
        ?>
        <a href="<?= $base ?>/admin/<?= $file ?>"
           class="admin-sidebar__link <?= $active ?>"
           <?= $active ? 'aria-current="page"' : '' ?>>
            <svg class="w-4 h-4 flex-shrink-0" xmlns="http://www.w3.org/2000/svg"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor"
                 stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="<?= $icon ?>"/>
            </svg>
            <?= $lbl ?>
        </a>
        <?php endforeach; ?>
        <div class="border-t border-gray-700/50 my-3 mx-3"></div>
        <a href="<?= $base ?>/shop.php"   class="admin-sidebar__link">🛒 View Shop</a>
        <a href="<?= $base ?>/admin/logout.php" class="admin-sidebar__link">🚪 Sign Out</a>
    </aside>

    <!-- Main content -->
    <div class="flex-1 min-w-0 px-4 sm:px-6 lg:px-8 py-6">

        <!-- ── ADD FORM ────────────────────────────────────────────── -->
        <?php if ($view === 'add'): ?>

        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-extrabold text-gray-900">Add Product</h1>
            <a href="<?= $base ?>/admin/products.php"
               class="btn btn-secondary btn-sm">← Back to Products</a>
        </div>

        <?php render_product_form($categories, $errors, $base); ?>


        <!-- ── EDIT FORM ───────────────────────────────────────────── -->
        <?php elseif ($view === 'edit' && $editProduct): ?>

        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-extrabold text-gray-900">
                Edit: <?= htmlspecialchars($editProduct['name']) ?>
            </h1>
            <a href="<?= $base ?>/admin/products.php"
               class="btn btn-secondary btn-sm">← Back to Products</a>
        </div>

        <?php render_product_form($categories, $errors, $base, $editProduct); ?>


        <!-- ── LIST VIEW ───────────────────────────────────────────── -->
        <?php else: ?>

        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center
                    sm:justify-between gap-3 mb-6">
            <h1 class="text-2xl font-extrabold text-gray-900">Products</h1>
            <a href="<?= $base ?>/admin/products.php?action=add"
               class="btn btn-primary btn-sm">+ Add Product</a>
        </div>

        <!-- Filters -->
        <form method="GET" action="products.php"
              class="bg-white rounded-xl border border-gray-100 shadow-sm
                     p-4 mb-6 flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-40">
                <label for="q" class="form-label text-xs mb-1">Search</label>
                <input type="search" id="q" name="q"
                       value="<?= htmlspecialchars($search) ?>"
                       placeholder="Name or SKU…"
                       class="form-input py-2 text-sm" />
            </div>
            <div class="w-40">
                <label for="cat" class="form-label text-xs mb-1">Category</label>
                <select id="cat" name="cat" class="form-select py-2 text-sm">
                    <option value="0">All</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>"
                        <?= $filterCat === (int)$cat['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="w-36">
                <label for="stock" class="form-label text-xs mb-1">Stock</label>
                <select id="stock" name="stock" class="form-select py-2 text-sm">
                    <option value="">All</option>
                    <option value="low"  <?= $filterStock === 'low'  ? 'selected' : '' ?>>Low Stock</option>
                    <option value="out"  <?= $filterStock === 'out'  ? 'selected' : '' ?>>Out of Stock</option>
                </select>
            </div>
            <label class="flex items-center gap-1.5 text-sm text-gray-600
                          cursor-pointer pb-2">
                <input type="checkbox" name="inactive" value="1"
                       <?= $showInactive ? 'checked' : '' ?>
                       class="accent-orange" />
                Show hidden
            </label>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <?php if ($search || $filterCat || $filterStock || $showInactive): ?>
                <a href="<?= $base ?>/admin/products.php"
                   class="btn btn-secondary btn-sm">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Results info -->
        <p class="text-sm text-gray-500 mb-3">
            <?= number_format($totalProducts) ?> product<?= $totalProducts !== 1 ? 's' : '' ?> found
        </p>

        <!-- Product table -->
        <?php if (empty($products)): ?>
        <div class="bg-white rounded-2xl border border-dashed border-gray-200
                    py-20 text-center">
            <p class="text-gray-400">No products match your filters.</p>
            <a href="<?= $base ?>/admin/products.php?action=add"
               class="btn btn-primary btn-sm mt-4">Add First Product</a>
        </div>

        <?php else: ?>
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100
                    overflow-hidden">
            <div class="overflow-x-auto">
            <table class="data-table" aria-label="Products list">
                <thead>
                    <tr>
                        <th scope="col" class="w-12">IMG</th>
                        <th scope="col">Name</th>
                        <th scope="col">Category</th>
                        <th scope="col">SKU</th>
                        <th scope="col" class="text-right">Price</th>
                        <th scope="col" class="text-right">Cost</th>
                        <th scope="col" class="text-center">Stock</th>
                        <th scope="col" class="text-center">Status</th>
                        <th scope="col" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p):
                        $stockQty = (int)$p['stock_qty'];
                        $isOut    = $stockQty === 0;
                        $isLow    = !$isOut && $stockQty <= (int)$p['low_stock_threshold'];
                    ?>
                    <tr class="<?= !$p['is_active'] ? 'opacity-50' : '' ?>">

                        <!-- Thumbnail -->
                        <td class="p-2">
                            <div class="w-10 h-10 rounded-lg bg-gray-100
                                        overflow-hidden flex-shrink-0">
                                <?php if ($p['image_url']): ?>
                                    <img src="<?= htmlspecialchars($base . $p['image_url']) ?>"
                                         alt="" class="w-full h-full object-cover"
                                         loading="lazy" aria-hidden="true" />
                                <?php else: ?>
                                    <div class="w-full h-full flex items-center
                                                justify-center text-gray-300"
                                         aria-hidden="true">
                                        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg"
                                             fill="none" viewBox="0 0 24 24"
                                             stroke="currentColor" stroke-width="1">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                  d="M4 16l4.586-4.586a2 2 0 012.828
                                                     0L16 16m-2-2l1.586-1.586a2 2 0
                                                     012.828 0L20 14m-6-6h.01M6 20h12
                                                     a2 2 0 002-2V6a2 2 0 00-2-2H6a2
                                                     2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </td>

                        <!-- Name -->
                        <td>
                            <p class="font-semibold text-gray-800 text-sm
                                      truncate max-w-[180px]">
                                <?= htmlspecialchars($p['name']) ?>
                            </p>
                            <?php if (!$p['is_active']): ?>
                            <span class="text-xs text-gray-400">(hidden)</span>
                            <?php endif; ?>
                        </td>

                        <!-- Category -->
                        <td class="text-sm text-gray-500">
                            <?= htmlspecialchars($p['category_name'] ?? '—') ?>
                        </td>

                        <!-- SKU -->
                        <td class="font-mono text-xs text-gray-400">
                            <?= htmlspecialchars($p['sku'] ?? '—') ?>
                        </td>

                        <!-- Selling price -->
                        <td class="text-right font-semibold text-gray-800 whitespace-nowrap">
                            KES <?= number_format((float)$p['price'], 2) ?>
                        </td>

                        <!-- Cost price -->
                        <td class="text-right text-gray-400 text-sm whitespace-nowrap">
                            KES <?= number_format((float)$p['cost_price'], 2) ?>
                        </td>

                        <!-- Stock + inline adjust -->
                        <td class="text-center">
                            <div class="flex items-center justify-center gap-1.5">
                                <span class="font-bold text-sm
                                             <?= $isOut ? 'text-red-600'
                                                : ($isLow ? 'text-amber-600'
                                                : 'text-green-600') ?>">
                                    <?= $stockQty ?>
                                </span>
                                <!-- Quick stock edit trigger -->
                                <button type="button"
                                        class="text-gray-300 hover:text-orange
                                               transition-colors"
                                        onclick="openStockModal(<?= $p['id'] ?>,
                                                '<?= htmlspecialchars(addslashes($p['name'])) ?>',
                                                <?= $stockQty ?>)"
                                        aria-label="Edit stock for <?= htmlspecialchars($p['name'], ENT_QUOTES) ?>">
                                    <svg class="w-3.5 h-3.5"
                                         xmlns="http://www.w3.org/2000/svg" fill="none"
                                         viewBox="0 0 24 24" stroke="currentColor"
                                         stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M15.232 5.232l3.536 3.536m-2.036-5.036
                                                 a2.5 2.5 0 113.536 3.536L6.5 21.036H3
                                                 v-3.572L16.732 3.732z"/>
                                    </svg>
                                </button>
                            </div>
                            <?php if ($isOut): ?>
                            <span class="text-xs text-red-500 block">Out of stock</span>
                            <?php elseif ($isLow): ?>
                            <span class="text-xs text-amber-500 block">Low</span>
                            <?php endif; ?>
                        </td>

                        <!-- Active status -->
                        <td class="text-center">
                            <?php if ($p['is_active']): ?>
                            <span class="badge badge-success">Live</span>
                            <?php else: ?>
                            <span class="badge badge-charcoal">Hidden</span>
                            <?php endif; ?>
                        </td>

                        <!-- Actions -->
                        <td class="text-center">
                            <div class="flex items-center justify-center gap-2">
                                <!-- Edit -->
                                <a href="<?= $base ?>/admin/products.php?action=edit&edit=<?= $p['id'] ?>"
                                   class="btn btn-ghost btn-sm py-1 px-2.5"
                                   aria-label="Edit <?= htmlspecialchars($p['name'], ENT_QUOTES) ?>">
                                    Edit
                                </a>

                                <!-- Delete / Restore -->
                                <form method="POST" action="products.php" class="inline">
                                    <?php if ($p['is_active']): ?>
                                    <input type="hidden" name="action"     value="delete">
                                    <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                    <button type="submit"
                                            class="btn btn-danger btn-sm py-1 px-2.5"
                                            data-confirm="Hide '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>' from the shop?">
                                        Hide
                                    </button>
                                    <?php else: ?>
                                    <input type="hidden" name="action"     value="restore">
                                    <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                    <button type="submit"
                                            class="btn btn-sm py-1 px-2.5
                                                   bg-green-600 text-white
                                                   hover:bg-green-700">
                                        Restore
                                    </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div><!-- /overflow-x-auto -->
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1):
            $qp = [];
            if ($search)      $qp['q']       = $search;
            if ($filterCat)   $qp['cat']      = $filterCat;
            if ($filterStock) $qp['stock']    = $filterStock;
            if ($showInactive)$qp['inactive'] = '1';
        ?>
        <nav class="flex items-center justify-center gap-1 mt-6"
             aria-label="Products pages">
            <?php for ($p = 1; $p <= $totalPages; $p++):
                $qp['page'] = $p; ?>
            <a href="?<?= http_build_query($qp) ?>"
               class="px-3 py-1.5 text-sm rounded-lg border transition-colors
                      <?= $p === $page
                          ? 'bg-orange border-orange text-white font-bold'
                          : 'border-gray-200 text-gray-600 hover:border-orange hover:text-orange' ?>"
               <?= $p === $page ? 'aria-current="page"' : '' ?>>
                <?= $p ?>
            </a>
            <?php endfor; ?>
        </nav>
        <?php endif; ?>

        <?php endif; // products not empty ?>
        <?php endif; // list view ?>

    </div><!-- /main -->
</div><!-- /admin flex -->


<!-- ══════════════════════════════════════════════════════════════════════
     STOCK ADJUST MODAL
══════════════════════════════════════════════════════════════════════ -->
<div id="stock-modal"
     class="fixed inset-0 z-50 hidden flex items-center justify-center
            bg-black/50 backdrop-blur-sm p-4"
     role="dialog" aria-modal="true" aria-labelledby="stock-modal-title">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6">
        <h2 id="stock-modal-title"
            class="text-lg font-bold text-gray-900 mb-1">
            Update Stock
        </h2>
        <p class="text-sm text-gray-500 mb-5" id="stock-modal-name"></p>

        <form method="POST" action="products.php">
            <input type="hidden" name="action"     value="stock">
            <input type="hidden" name="product_id" id="stock-modal-id">

            <div class="form-group">
                <label for="stock-modal-qty" class="form-label">
                    New Stock Quantity
                </label>
                <input type="number" id="stock-modal-qty"
                       name="stock_qty" min="0" step="1"
                       class="form-input text-lg font-bold"
                       required />
            </div>

            <div class="flex gap-3 mt-5">
                <button type="submit" class="btn btn-primary flex-1">
                    Save
                </button>
                <button type="button" onclick="closeStockModal()"
                        class="btn btn-secondary flex-1">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>


<!-- Page JS: image preview, margin calculator, stock modal ─────────── -->
<script>
// ── Image preview ──────────────────────────────────────────────────────
(function () {
    var fileInput = document.getElementById('image');
    if (!fileInput) return;

    fileInput.addEventListener('change', function () {
        var file = fileInput.files[0];
        if (!file) return;

        var reader = new FileReader();
        reader.onload = function (e) {
            var preview = document.getElementById('image-preview');
            if (!preview) return;
            preview.innerHTML =
                '<img src="' + e.target.result + '" ' +
                'class="w-full h-full object-cover" alt="Preview" />';
        };
        reader.readAsDataURL(file);
    });
})();

// ── Gross margin calculator ────────────────────────────────────────────
(function () {
    var priceInput = document.getElementById('price');
    var costInput  = document.getElementById('cost_price');
    var valEl      = document.getElementById('margin-val');
    var pctEl      = document.getElementById('margin-pct');

    function calc() {
        if (!priceInput || !costInput || !valEl || !pctEl) return;
        var price = parseFloat(priceInput.value) || 0;
        var cost  = parseFloat(costInput.value)  || 0;
        if (price <= 0) { valEl.textContent = '—'; pctEl.textContent = 'Set price and cost to calculate'; return; }
        var margin    = price - cost;
        var marginPct = price > 0 ? (margin / price) * 100 : 0;
        valEl.textContent = 'KES ' + margin.toFixed(2);
        pctEl.textContent = marginPct.toFixed(1) + '% margin';
        valEl.className   = 'text-2xl font-extrabold ' + (margin >= 0 ? 'text-gray-900' : 'text-red-600');
    }

    if (priceInput) priceInput.addEventListener('input', calc);
    if (costInput)  costInput.addEventListener('input',  calc);
    calc(); // run on page load for edit form
})();

// ── Stock modal ────────────────────────────────────────────────────────
function openStockModal(id, name, currentQty) {
    document.getElementById('stock-modal-id').value  = id;
    document.getElementById('stock-modal-name').textContent = name;
    document.getElementById('stock-modal-qty').value = currentQty;
    document.getElementById('stock-modal').classList.remove('hidden');
    document.getElementById('stock-modal-qty').focus();
}
function closeStockModal() {
    document.getElementById('stock-modal').classList.add('hidden');
}
// Close on backdrop click
document.getElementById('stock-modal').addEventListener('click', function (e) {
    if (e.target === this) closeStockModal();
});
// Close on Escape
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeStockModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
