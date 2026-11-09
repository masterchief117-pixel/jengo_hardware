<?php
/**
 * Approval requests raised by the signed-in attendant.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_attendant();

$attendantId = (int) get_user_id();
$filter = (string) ($_GET['status'] ?? 'all');
if (!in_array($filter, ['all', 'pending', 'approved', 'rejected'], true)) {
    $filter = 'all';
}

$sql = 'SELECT id, action_type, action_payload, status, created_at, reviewed_at
        FROM approval_requests
        WHERE attendant_id = ?';
$params = [$attendantId];
if ($filter !== 'all') {
    $sql .= ' AND status = ?';
    $params[] = $filter;
}
$sql .= ' ORDER BY created_at DESC LIMIT 100';
$statement = db()->prepare($sql);
$statement->execute($params);
$requests = $statement->fetchAll();

$pageTitle = 'Pending Approvals Status';
require_once __DIR__ . '/../includes/header.php';

$filters = [
    'all' => 'All',
    'pending' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
];
?>

<div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
    <div>
        <h1 class="text-2xl font-extrabold text-gray-900">Pending Approvals Status</h1>
        <p class="mt-1 text-sm text-gray-500">
            Discounts, stock counts, price overrides, and voids wait here until an admin decides.
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <?php foreach ($filters as $value => $label): ?>
            <a href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/attendant/approvals.php?status=<?= $value ?>"
               class="btn btn-sm <?= $filter === $value ? 'btn-primary' : 'btn-secondary' ?>">
                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
    <table class="w-full text-left text-sm">
        <thead class="bg-gray-50 text-gray-500">
            <tr>
                <th class="px-4 py-3">When</th>
                <th class="px-4 py-3">Action</th>
                <th class="px-4 py-3">Details</th>
                <th class="px-4 py-3">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($requests === []): ?>
                <tr><td class="px-4 py-6 text-gray-500" colspan="4">No approval requests in this view.</td></tr>
            <?php endif; ?>
            <?php foreach ($requests as $request): ?>
                <?php
                $status = (string) $request['status'];
                $payload = json_decode((string) $request['action_payload'], true);
                $summary = is_array($payload)
                    ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : (string) $request['action_payload'];
                ?>
                <tr class="border-t align-top">
                    <td class="px-4 py-3 whitespace-nowrap">
                        <?= htmlspecialchars((string) $request['created_at'], ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td class="px-4 py-3 font-medium">
                        <?= htmlspecialchars((string) $request['action_type'], ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td class="max-w-md px-4 py-3">
                        <code class="break-all text-xs"><?= htmlspecialchars((string) $summary, ENT_QUOTES, 'UTF-8') ?></code>
                    </td>
                    <td class="px-4 py-3">
                        <?php if ($status === 'pending'): ?>
                            <span class="badge badge-warning">Pending Admin Approval...</span>
                        <?php elseif ($status === 'approved'): ?>
                            <span class="badge badge-success">Approved</span>
                        <?php else: ?>
                            <span class="badge badge-charcoal">Rejected</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
