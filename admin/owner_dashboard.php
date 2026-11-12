<?php
/**
 * Read-only executive metrics and maintenance-mode controls for the owner.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_owner();

$pdo = db();

if (empty($_SESSION['owner_dashboard_csrf'])) {
    $_SESSION['owner_dashboard_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['owner_dashboard_csrf'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $requestedStatus = $_POST['new_status'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(400);
        $error = 'Your session token is invalid. Reload the page and try again.';
    } elseif (!is_string($requestedStatus) || !in_array($requestedStatus, ['0', '1'], true)) {
        http_response_code(400);
        $error = 'The requested system status is invalid.';
    } else {
        $statement = $pdo->prepare(
            'UPDATE system_settings
             SET system_active = ?, updated_by = ?
             WHERE id = 1'
        );
        $statement->execute([(int) $requestedStatus, get_user_id()]);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('System settings row is missing; apply database/system_owner.sql.');
        }

        header('Location: ' . rtrim((string) env('APP_URL', ''), '/') . '/admin/owner_dashboard.php?updated=1');
        exit;
    }
}

$sales = (float) $pdo->query(
    "SELECT COALESCE(SUM(total_amount), 0)
     FROM orders
     WHERE status IN ('paid', 'completed')"
)->fetchColumn();
$pendingOrders = (int) $pdo->query(
    "SELECT COUNT(*) FROM orders WHERE status = 'pending'"
)->fetchColumn();
$inventoryValue = (float) $pdo->query(
    'SELECT COALESCE(SUM(price * stock_qty), 0) FROM products'
)->fetchColumn();
$systemActive = (int) $pdo->query(
    'SELECT system_active FROM system_settings WHERE id = 1'
)->fetchColumn() === 1;

$pageTitle = 'Owner Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<main class="container py-5">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <p class="text-uppercase small fw-bold mb-1">System owner</p>
            <h1 class="h2 mb-0">Executive Dashboard</h1>
        </div>
        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(rtrim((string) env('APP_URL', ''), '/') . '/logout.php', ENT_QUOTES, 'UTF-8') ?>">
            Sign Out
        </a>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="alert alert-success" role="status">System status updated.</div>
    <?php endif; ?>

    <section class="card p-4 p-md-5 mb-4 border-top border-4 <?= $systemActive ? '' : 'bg-dark text-white' ?>"
             style="border-top-color: var(--brand-accent) !important;" aria-labelledby="maintenance-heading">
        <div class="text-center">
            <h2 class="h4 fw-bold mb-3" id="maintenance-heading">Maintenance Mode</h2>
            <p class="mb-4">
                Status:
                <?php if ($systemActive): ?>
                    <span class="badge bg-success">ONLINE</span>
                <?php else: ?>
                    <span class="badge bg-danger">OFFLINE</span>
                <?php endif; ?>
            </p>
            <p class="small mb-4">
                Normal storefront and staff pages are blocked while offline. The owner dashboard,
                sign-in/out, APIs, and payment callbacks remain available.
            </p>
            <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="new_status" value="<?= $systemActive ? '0' : '1' ?>">
                <button type="submit" class="btn <?= $systemActive ? 'btn-danger' : 'btn-success' ?> btn-lg fw-bold"
                        <?= $systemActive ? 'data-confirm="Take normal site pages offline?"' : '' ?>>
                    <?= $systemActive ? 'Enable Maintenance Mode' : 'Restore Site Access' ?>
                </button>
            </form>
        </div>
    </section>

    <section class="row g-3" aria-label="Read-only business metrics">
        <div class="col-md-4">
            <article class="card h-100 p-4">
                <h2 class="h6 text-muted text-uppercase fw-bold">Paid and Completed Sales</h2>
                <p class="h3 fw-bold mb-0">KES <?= number_format($sales, 2) ?></p>
            </article>
        </div>
        <div class="col-md-4">
            <article class="card h-100 p-4">
                <h2 class="h6 text-muted text-uppercase fw-bold">Pending Orders</h2>
                <p class="h3 fw-bold mb-0"><?= number_format($pendingOrders) ?></p>
            </article>
        </div>
        <div class="col-md-4">
            <article class="card h-100 p-4">
                <h2 class="h6 text-muted text-uppercase fw-bold">Inventory at Selling Price</h2>
                <p class="h3 fw-bold mb-0">KES <?= number_format($inventoryValue, 2) ?></p>
            </article>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
