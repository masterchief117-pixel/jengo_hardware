<?php
/**
 * Admin delivery board and completed-delivery records.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_admin_login();

$pdo = db();
$base = rtrim((string) env('APP_URL', ''), '/');
$escape = static fn (string $value): string =>
    htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if (empty($_SESSION['admin_operations_csrf'])) {
    $_SESSION['admin_operations_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['admin_operations_csrf'];
$error = '';
$view = (string) ($_GET['view'] ?? 'list');
if (!in_array($view, ['list', 'records'], true)) {
    $view = 'list';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $submittedToken)) {
        http_response_code(400);
        $error = 'This form expired. Reload the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create') {
            $orderReference = trim((string) ($_POST['order_reference'] ?? ''));
            $recipient = trim((string) ($_POST['recipient_name'] ?? ''));
            $phone = trim((string) ($_POST['recipient_phone'] ?? ''));
            $destination = trim((string) ($_POST['destination'] ?? ''));
            $items = trim((string) ($_POST['items_description'] ?? ''));
            $scheduledFor = trim((string) ($_POST['scheduled_for'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $validDate = static function (string $date): bool {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                return $parsed !== false && $parsed->format('Y-m-d') === $date;
            };
            $errors = [];

            if ($recipient === '' || mb_strlen($recipient) > 120) {
                $errors[] = 'Enter a recipient name (up to 120 characters).';
            }
            if ($phone !== '' && mb_strlen($phone) > 40) {
                $errors[] = 'Recipient phone must be 40 characters or fewer.';
            }
            if ($destination === '' || mb_strlen($destination) > 500) {
                $errors[] = 'Enter a delivery destination (up to 500 characters).';
            }
            if ($items === '' || mb_strlen($items) > 5000) {
                $errors[] = 'Enter the delivery items (up to 5,000 characters).';
            }
            if ($orderReference !== '' && mb_strlen($orderReference) > 80) {
                $errors[] = 'Order reference must be 80 characters or fewer.';
            }
            if ($scheduledFor !== '' && !$validDate($scheduledFor)) {
                $errors[] = 'Enter a valid scheduled delivery date.';
            }
            if (mb_strlen($notes) > 5000) {
                $errors[] = 'Notes must be 5,000 characters or fewer.';
            }

            if ($errors === []) {
                try {
                    $statement = $pdo->prepare(
                        'INSERT INTO deliveries
                            (order_reference, recipient_name, recipient_phone, destination,
                             items_description, scheduled_for, notes, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $statement->execute([
                        $orderReference !== '' ? $orderReference : null,
                        $recipient,
                        $phone !== '' ? $phone : null,
                        $destination,
                        $items,
                        $scheduledFor !== '' ? $scheduledFor : null,
                        $notes !== '' ? $notes : null,
                        admin_user_id(),
                    ]);
                    header('Location: ' . $base . '/admin/deliveries.php?created=1');
                    exit;
                } catch (PDOException $exception) {
                    error_log('[Admin Deliveries] Create failed: ' . $exception->getMessage());
                    $error = 'The delivery could not be saved. Please try again.';
                }
            } else {
                $error = implode(' ', $errors);
            }
        } elseif ($action === 'mark_delivered') {
            $deliveryId = filter_var($_POST['delivery_id'] ?? 0, FILTER_VALIDATE_INT);
            $deliveryId = $deliveryId === false ? 0 : (int) $deliveryId;
            if ($deliveryId < 1) {
                http_response_code(400);
                $error = 'Select a valid delivery to complete.';
            } else {
                try {
                    $statement = $pdo->prepare(
                        "UPDATE deliveries
                         SET delivery_status = 'delivered',
                             delivered_at = CURRENT_TIMESTAMP,
                             delivered_by = ?
                         WHERE id = ? AND delivery_status = 'pending'"
                    );
                    $statement->execute([admin_user_id(), $deliveryId]);
                    if ($statement->rowCount() === 1) {
                        header('Location: ' . $base . '/admin/deliveries.php?view=records&delivered=1');
                        exit;
                    }
                    $error = 'Delivery was not found or has already been completed. Refresh the list.';
                } catch (PDOException $exception) {
                    error_log('[Admin Deliveries] Completion failed: ' . $exception->getMessage());
                    $error = 'The delivery could not be marked complete. Please try again.';
                }
            }
        } else {
            http_response_code(400);
            $error = 'Invalid delivery action.';
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$counts = $pdo->query(
    "SELECT
        SUM(delivery_status = 'pending') AS pending_count,
        SUM(delivery_status = 'delivered') AS delivered_count
     FROM deliveries"
)->fetch(PDO::FETCH_ASSOC);
$sql = 'SELECT * FROM deliveries WHERE delivery_status = ?';
$params = [$view === 'records' ? 'delivered' : 'pending'];
if ($search !== '') {
    $sql .= ' AND (recipient_name LIKE ? OR destination LIKE ? OR order_reference LIKE ? OR items_description LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}
$sql .= $view === 'records'
    ? ' ORDER BY delivered_at DESC, id DESC'
    : ' ORDER BY scheduled_for IS NULL ASC, scheduled_for ASC, id ASC';
$statement = $pdo->prepare($sql);
$statement->execute($params);
$deliveries = $statement->fetchAll(PDO::FETCH_ASSOC);

$formData = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create'
    ? $_POST
    : [];
$pageTitle = $view === 'records' ? 'Delivery Records' : 'Deliveries';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-6">
        <div>
            <p class="text-sm text-gray-500 mb-1">Admin Portal / Logistics</p>
            <h1 class="text-3xl font-extrabold text-gray-900"><?= $view === 'records' ? 'Delivery Records' : 'Deliveries' ?></h1>
            <p class="text-gray-600 mt-1">Create delivery tasks and record completion.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="btn btn-secondary" href="<?= $escape($base) ?>/admin/index.php">Admin dashboard</a>
            <a class="btn btn-secondary" href="<?= $escape($base) ?>/admin/dashboard.php">Owner analytics</a>
            <a class="btn btn-secondary" href="<?= $escape($base) ?>/admin/employees.php">Employees</a>
            <a class="btn btn-secondary" href="<?= $escape($base) ?>/admin/admin_payroll.php">Payroll</a>
            <a class="btn <?= $view === 'list' ? 'btn-primary' : 'btn-secondary' ?>" href="<?= $escape($base) ?>/admin/deliveries.php">Pending (<?= (int) ($counts['pending_count'] ?? 0) ?>)</a>
            <a class="btn <?= $view === 'records' ? 'btn-primary' : 'btn-secondary' ?>" href="<?= $escape($base) ?>/admin/deliveries.php?view=records">Records (<?= (int) ($counts['delivered_count'] ?? 0) ?>)</a>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error mb-5" role="alert"><?= $escape($error) ?></div>
    <?php elseif (isset($_GET['created'])): ?>
        <div class="alert alert-success mb-5" role="status">Delivery saved to the pending list.</div>
    <?php elseif (isset($_GET['delivered'])): ?>
        <div class="alert alert-success mb-5" role="status">Delivery marked complete and added to records.</div>
    <?php endif; ?>

    <?php if ($view === 'list'): ?>
        <section class="bg-white rounded-xl shadow p-5 sm:p-6 mb-6">
            <h2 class="text-xl font-bold mb-4">Add a delivery</h2>
            <form method="post" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="action" value="create">
                <label class="block">
                    <span class="form-label">Recipient / customer</span>
                    <input class="form-input" name="recipient_name" maxlength="120" required value="<?= $escape((string) ($formData['recipient_name'] ?? '')) ?>">
                </label>
                <label class="block">
                    <span class="form-label">Phone (optional)</span>
                    <input class="form-input" name="recipient_phone" type="tel" maxlength="40" value="<?= $escape((string) ($formData['recipient_phone'] ?? '')) ?>">
                </label>
                <label class="block">
                    <span class="form-label">Order reference (optional)</span>
                    <input class="form-input" name="order_reference" maxlength="80" value="<?= $escape((string) ($formData['order_reference'] ?? '')) ?>">
                </label>
                <label class="block">
                    <span class="form-label">Scheduled date (optional)</span>
                    <input class="form-input" name="scheduled_for" type="date" value="<?= $escape((string) ($formData['scheduled_for'] ?? '')) ?>">
                </label>
                <label class="block sm:col-span-2">
                    <span class="form-label">Destination</span>
                    <input class="form-input" name="destination" maxlength="500" required value="<?= $escape((string) ($formData['destination'] ?? '')) ?>">
                </label>
                <label class="block sm:col-span-2 lg:col-span-3">
                    <span class="form-label">Items to deliver</span>
                    <textarea class="form-input" name="items_description" rows="2" maxlength="5000" required><?= $escape((string) ($formData['items_description'] ?? '')) ?></textarea>
                </label>
                <label class="block sm:col-span-2 lg:col-span-3">
                    <span class="form-label">Delivery notes (optional)</span>
                    <textarea class="form-input" name="notes" rows="2" maxlength="5000"><?= $escape((string) ($formData['notes'] ?? '')) ?></textarea>
                </label>
                <div class="sm:col-span-2 lg:col-span-3">
                    <button class="btn btn-primary" type="submit">Save delivery</button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="bg-white rounded-xl shadow p-5">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
            <div>
                <h2 class="text-xl font-bold"><?= $view === 'records' ? 'Completed deliveries' : 'Pending deliveries' ?></h2>
                <?php if ($view === 'records'): ?><p class="text-sm text-gray-600 mt-1">Completed items stay here as a permanent delivery record.</p><?php endif; ?>
            </div>
            <form method="get" class="flex gap-2">
                <?php if ($view === 'records'): ?><input type="hidden" name="view" value="records"><?php endif; ?>
                <input class="form-input" type="search" name="q" placeholder="Search deliveries" value="<?= $escape($search) ?>">
                <button class="btn btn-secondary" type="submit">Search</button>
            </form>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php foreach ($deliveries as $delivery): ?>
                <article class="border rounded-xl p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="font-bold text-lg"><?= $escape((string) $delivery['recipient_name']) ?></h3>
                            <p class="text-sm text-gray-600"><?= $escape((string) $delivery['destination']) ?></p>
                        </div>
                        <span class="badge <?= $view === 'records' ? 'badge-success' : 'badge-warning' ?>"><?= $view === 'records' ? 'Delivered' : 'Pending' ?></span>
                    </div>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-2 mt-4 text-sm">
                        <?php if (!empty($delivery['order_reference'])): ?><div><dt class="text-gray-500">Order reference</dt><dd class="font-medium"><?= $escape((string) $delivery['order_reference']) ?></dd></div><?php endif; ?>
                        <?php if (!empty($delivery['recipient_phone'])): ?><div><dt class="text-gray-500">Phone</dt><dd class="font-medium"><?= $escape((string) $delivery['recipient_phone']) ?></dd></div><?php endif; ?>
                        <?php if (!empty($delivery['scheduled_for'])): ?><div><dt class="text-gray-500">Scheduled</dt><dd class="font-medium"><?= $escape((string) $delivery['scheduled_for']) ?></dd></div><?php endif; ?>
                        <?php if ($view === 'records' && !empty($delivery['delivered_at'])): ?><div><dt class="text-gray-500">Completed</dt><dd class="font-medium"><?= $escape((string) $delivery['delivered_at']) ?></dd></div><?php endif; ?>
                    </dl>
                    <div class="mt-4"><p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Items</p><p class="text-sm whitespace-pre-line"><?= $escape((string) $delivery['items_description']) ?></p></div>
                    <?php if (!empty($delivery['notes'])): ?><div class="mt-3"><p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Notes</p><p class="text-sm whitespace-pre-line"><?= $escape((string) $delivery['notes']) ?></p></div><?php endif; ?>
                    <?php if ($view === 'list'): ?>
                        <form method="post" class="mt-5" onsubmit="return confirm('Mark this delivery as completed?');">
                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="action" value="mark_delivered">
                            <input type="hidden" name="delivery_id" value="<?= (int) $delivery['id'] ?>">
                            <button class="btn btn-primary" type="submit">✓ Mark delivered</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if ($deliveries === []): ?>
                <p class="text-gray-500 py-8 text-center md:col-span-2"><?= $view === 'records' ? 'No completed deliveries match this search yet.' : 'No pending deliveries match this search. Add a delivery above to get started.' ?></p>
            <?php endif; ?>
        </div>
    </section>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
