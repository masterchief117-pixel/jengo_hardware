<?php
/**
 * Admin review interface for general attendant requests.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_admin_login();

$pdo = db();
$base = rtrim((string) env('APP_URL', ''), '/');
if (empty($_SESSION['admin_requests_csrf'])) {
    $_SESSION['admin_requests_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['admin_requests_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken)
        || !hash_equals($csrfToken, $submittedToken)) {
        $_SESSION['flash_message'] = 'Your form expired. Reload and try again.';
        $_SESSION['flash_type'] = 'error';
        header('Location: ' . $base . '/admin/admin_requests.php');
        exit;
    }

    $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
    $adminStatus = $_POST['admin_status'] ?? '';
    $adminReplyInput = $_POST['admin_reply'] ?? '';
    $adminReply = is_string($adminReplyInput) ? trim($adminReplyInput) : '';

    if ($requestId === false || $requestId === null || $requestId < 1
        || !is_string($adminStatus)
        || !in_array($adminStatus, ['Pending', 'Approved', 'Rejected'], true)
        || mb_strlen($adminReply) > 5000) {
        $_SESSION['flash_message'] = 'Enter a valid request status and reply (up to 5000 characters).';
        $_SESSION['flash_type'] = 'error';
        header('Location: ' . $base . '/admin/admin_requests.php');
        exit;
    }

    $requestCheck = $pdo->prepare('SELECT id FROM requests WHERE id = ? LIMIT 1');
    $requestCheck->execute([$requestId]);
    if ($requestCheck->fetchColumn() === false) {
        $_SESSION['flash_message'] = 'Request not found.';
        $_SESSION['flash_type'] = 'error';
        header('Location: ' . $base . '/admin/admin_requests.php');
        exit;
    }

    $update = $pdo->prepare(
        'UPDATE requests SET admin_status = ?, admin_reply = ? WHERE id = ?'
    );
    $update->execute([
        $adminStatus,
        $adminReply !== '' ? $adminReply : null,
        $requestId,
    ]);

    $_SESSION['flash_message'] = 'Request response saved.';
    $_SESSION['flash_type'] = 'success';
    header('Location: ' . $base . '/admin/admin_requests.php');
    exit;
}

$requests = $pdo->query(
    'SELECT requests.*, users.full_name AS attendant_name
     FROM requests
     INNER JOIN users ON users.id = requests.attendant_id
     ORDER BY requests.created_at DESC'
)->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Attendant Requests';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex gap-0 -mx-4 sm:-mx-6 lg:-mx-8 min-h-screen">
    <aside class="admin-sidebar hidden lg:flex flex-col w-56 xl:w-60"
           aria-label="Admin navigation">
        <div class="admin-sidebar__brand">Admin Panel</div>
        <a href="<?= htmlspecialchars($base) ?>/admin/index.php" class="admin-sidebar__link">Dashboard</a>
        <a href="<?= htmlspecialchars($base) ?>/admin/products.php" class="admin-sidebar__link">Products</a>
        <a href="<?= htmlspecialchars($base) ?>/admin/admin_categories.php" class="admin-sidebar__link">Categories</a>
        <a href="<?= htmlspecialchars($base) ?>/admin/admin_requests.php" class="admin-sidebar__link active" aria-current="page">Requests</a>
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

    <section class="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8"
             aria-labelledby="requests-heading">
        <div class="mb-6">
            <h1 id="requests-heading" class="text-2xl font-extrabold text-gray-900">
                Attendant Requests
            </h1>
            <p class="mt-1 text-sm text-gray-600">Review requests and send a status or reply.</p>
        </div>

        <?php if ($requests === []): ?>
            <div class="card p-6 text-sm text-gray-600">No attendant requests have been submitted.</div>
        <?php else: ?>
            <div class="space-y-5">
                <?php foreach ($requests as $request): ?>
                    <?php
                    $status = (string) $request['admin_status'];
                    $statusClass = match (strtolower($status)) {
                        'approved' => 'badge-success',
                        'rejected' => 'badge-error',
                        default => 'badge-warning',
                    };
                    ?>
                    <article class="card p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold text-gray-900">
                                    <?= htmlspecialchars((string) $request['attendant_name'], ENT_QUOTES, 'UTF-8') ?>
                                </p>
                                <time class="text-xs text-gray-500">
                                    <?= htmlspecialchars((string) $request['created_at'], ENT_QUOTES, 'UTF-8') ?>
                                </time>
                            </div>
                            <span class="badge <?= $statusClass ?>">
                                <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                        <p class="mt-4 whitespace-pre-wrap text-sm text-gray-800"><?= htmlspecialchars((string) $request['request_text'], ENT_QUOTES, 'UTF-8') ?></p>

                        <form method="post" class="mt-5 grid gap-4 md:grid-cols-[12rem_minmax(0,1fr)_auto] md:items-end">
                            <input type="hidden" name="csrf_token"
                                   value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                            <label>
                                <span class="form-label">Admin status</span>
                                <select name="admin_status" class="form-select" required>
                                    <?php foreach (['Pending', 'Approved', 'Rejected'] as $option): ?>
                                        <option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>>
                                            <?= $option ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span class="form-label">Reply to attendant</span>
                                <textarea name="admin_reply" class="form-input" rows="2"
                                          maxlength="5000"><?= htmlspecialchars((string) ($request['admin_reply'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                            </label>
                            <button type="submit" class="btn btn-primary">Save Response</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
