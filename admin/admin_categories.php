<?php
/**
 * Admin category manager.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_admin_login();

$pdo = db();
$base = rtrim((string) env('APP_URL', ''), '/');

if (empty($_SESSION['admin_categories_csrf'])) {
    $_SESSION['admin_categories_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['admin_categories_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $nameInput = $_POST['new_category_name'] ?? '';
    $name = is_string($nameInput) ? trim($nameInput) : '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $_SESSION['flash_message'] = 'Your form expired. Reload and try again.';
        $_SESSION['flash_type'] = 'error';
    } elseif ($name === '' || mb_strlen($name) > 100) {
        $_SESSION['flash_message'] = 'Enter a category name of 1 to 100 characters.';
        $_SESSION['flash_type'] = 'error';
    } else {
        $existing = $pdo->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
        $existing->execute([$name]);

        if ($existing->fetchColumn() !== false) {
            $_SESSION['flash_message'] = 'That category already exists.';
            $_SESSION['flash_type'] = 'error';
        } else {
            $slugBase = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
            if ($slugBase === '') {
                $slugBase = 'category';
            }

            $slug = $slugBase;
            $suffix = 2;
            $slugCheck = $pdo->prepare('SELECT id FROM categories WHERE slug = ? LIMIT 1');
            while (true) {
                $slugCheck->execute([$slug]);
                if ($slugCheck->fetchColumn() === false) {
                    break;
                }
                $slug = $slugBase . '-' . $suffix++;
            }

            try {
                $insert = $pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?)');
                $insert->execute([$name, $slug]);
                $_SESSION['flash_message'] = 'Category added successfully.';
                $_SESSION['flash_type'] = 'success';
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000') {
                    throw $exception;
                }
                $_SESSION['flash_message'] = 'That category or category URL already exists. Please try again.';
                $_SESSION['flash_type'] = 'error';
            }
        }
    }

    header('Location: ' . $base . '/admin/admin_categories.php');
    exit;
}

$categories = $pdo->query(
    'SELECT id, name, slug, created_at FROM categories ORDER BY name ASC'
)->fetchAll(PDO::FETCH_ASSOC);
$flashMessage = $_SESSION['flash_message'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$pageTitle = 'Product Categories';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex gap-0 -mx-4 sm:-mx-6 lg:-mx-8 min-h-screen">
    <aside class="admin-sidebar hidden lg:flex flex-col w-56 xl:w-60"
           aria-label="Admin navigation">
        <div class="admin-sidebar__brand">Admin Panel</div>
        <a href="<?= htmlspecialchars($base) ?>/admin/index.php" class="admin-sidebar__link">Dashboard</a>
        <a href="<?= htmlspecialchars($base) ?>/admin/products.php" class="admin-sidebar__link">Products</a>
        <a href="<?= htmlspecialchars($base) ?>/admin/admin_categories.php" class="admin-sidebar__link active" aria-current="page">Categories</a>
        <a href="<?= htmlspecialchars($base) ?>/admin/admin_requests.php" class="admin-sidebar__link">Requests</a>
        <a href="<?= htmlspecialchars($base) ?>/admin/admin_gallery.php" class="admin-sidebar__link">Gallery Manager</a>
        <a href="<?= htmlspecialchars($base) ?>/admin/orders.php" class="admin-sidebar__link">Orders</a>
        <a href="<?= htmlspecialchars($base) ?>/admin/users.php" class="admin-sidebar__link">Users</a>
        <a href="<?= htmlspecialchars($base) ?>/admin/employees.php" class="admin-sidebar__link">Employees</a>
        <a href="<?= htmlspecialchars($base) ?>/admin/admin_payroll.php" class="admin-sidebar__link">Payroll</a>
        <a href="<?= htmlspecialchars($base) ?>/admin/deliveries.php" class="admin-sidebar__link">Deliveries</a>
        <a href="<?= htmlspecialchars($base) ?>/admin/payments_record.php" class="admin-sidebar__link">Payments</a>
        <div class="border-t border-gray-700/50 my-3 mx-3"></div>
        <a href="<?= htmlspecialchars($base) ?>/admin/logout.php" class="admin-sidebar__link">Sign Out</a>
    </aside>

    <main class="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8"
          aria-labelledby="categories-heading">
        <div class="mb-6">
            <h1 id="categories-heading" class="text-2xl font-extrabold text-gray-900">
                Product Categories
            </h1>
            <p class="mt-1 text-sm text-gray-600">
                Add and review categories used to organize products.
            </p>
        </div>

        <?php if (is_string($flashMessage) && $flashMessage !== ''): ?>
            <div class="alert <?= $flashType === 'success' ? 'alert-success' : ($flashType === 'error' ? 'alert-error' : 'alert-info') ?> mb-5"
                 role="<?= $flashType === 'error' ? 'alert' : 'status' ?>">
                <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <section class="card p-5 mb-6" aria-labelledby="add-category-heading">
            <h2 id="add-category-heading" class="text-lg font-bold text-gray-900 mb-4">
                Add a Category
            </h2>
            <form method="POST" action="<?= htmlspecialchars($base) ?>/admin/admin_categories.php"
                  class="flex flex-col sm:flex-row gap-3 sm:items-end">
                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group flex-1 mb-0">
                    <label for="new_category_name" class="form-label">Category name</label>
                    <input type="text" id="new_category_name" name="new_category_name"
                           class="form-input" maxlength="100"
                           placeholder="e.g., Electricals" required>
                </div>
                <button type="submit" class="btn btn-primary"
                        style="background-color: #D9531E; border-color: #D9531E;">
                    Add Category
                </button>
            </form>
        </section>

        <section class="card overflow-hidden" aria-labelledby="category-list-heading">
            <div class="p-5 border-b border-gray-200">
                <h2 id="category-list-heading" class="text-lg font-bold text-gray-900">
                    Existing Categories
                </h2>
            </div>
            <?php if ($categories === []): ?>
                <p class="p-5 text-sm text-gray-600">No categories have been created yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table" aria-label="Product categories">
                        <thead>
                            <tr>
                                <th scope="col">Category</th>
                                <th scope="col">URL Slug</th>
                                <th scope="col">Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $category): ?>
                                <tr>
                                    <td class="font-semibold">
                                        <?= htmlspecialchars((string) $category['name'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td><?= htmlspecialchars((string) $category['slug'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?= htmlspecialchars((string) $category['created_at'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
