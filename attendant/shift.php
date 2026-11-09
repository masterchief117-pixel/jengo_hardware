<?php
/**
 * Sales rung up on the current attendant's shift, with a void request.
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
$attendantId = (int) get_user_id();

$sales = $pdo->prepare(
    "SELECT id, sale_number, customer_name, subtotal, discount_percent, discount_amount,
            total_amount, payment_method, status, created_at
     FROM sales
     WHERE attendant_id = ?
       AND created_at >= CURDATE()
     ORDER BY created_at DESC"
);
$sales->execute([$attendantId]);
$shiftSales = $sales->fetchAll();

$pendingVoids = [];
$pending = $pdo->prepare(
    "SELECT action_payload FROM approval_requests
     WHERE attendant_id = ? AND action_type = 'void_sale' AND status = 'pending'"
);
$pending->execute([$attendantId]);
foreach ($pending->fetchAll() as $row) {
    $payload = json_decode((string) $row['action_payload'], true);
    if (is_array($payload) && isset($payload['sale_id'])) {
        $pendingVoids[(int) $payload['sale_id']] = true;
    }
}

$pageTitle = 'My Shift';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-extrabold text-gray-900">My Shift</h1>
    <p class="mt-1 text-sm text-gray-500">
        Recent sales from today. Request Void sends the sale to an admin. Stock is returned only after approval.
    </p>
</div>

<div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
    <table class="w-full text-left text-sm">
        <thead class="bg-gray-50 text-gray-500">
            <tr>
                <th class="px-4 py-3">Sale</th>
                <th class="px-4 py-3">Customer</th>
                <th class="px-4 py-3">Discount</th>
                <th class="px-4 py-3">Total</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Time</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($shiftSales === []): ?>
                <tr><td class="px-4 py-6 text-gray-500" colspan="7">No sales on this shift yet. Ring one up from POS.</td></tr>
            <?php endif; ?>
            <?php foreach ($shiftSales as $sale): ?>
                <?php $completed = strcasecmp((string) $sale['status'], 'completed') === 0; ?>
                <tr class="border-t">
                    <td class="px-4 py-3 font-medium"><?= htmlspecialchars((string) $sale['sale_number'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="px-4 py-3"><?= htmlspecialchars((string) ($sale['customer_name'] ?: 'Walk-in'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="px-4 py-3">
                        <?= htmlspecialchars(number_format((float) $sale['discount_percent'], 2), ENT_QUOTES, 'UTF-8') ?>%
                        · KES <?= number_format((float) $sale['discount_amount'], 2) ?>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">KES <?= number_format((float) $sale['total_amount'], 2) ?></td>
                    <td class="px-4 py-3">
                        <?php if ($completed): ?>
                            <span class="badge badge-success">Completed</span>
                        <?php else: ?>
                            <span class="badge badge-charcoal"><?= htmlspecialchars((string) $sale['status'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap"><?= htmlspecialchars((string) $sale['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="px-4 py-3 text-right">
                        <?php if ($completed && isset($pendingVoids[(int) $sale['id']])): ?>
                            <span class="badge badge-warning">Pending Admin Approval...</span>
                        <?php elseif ($completed): ?>
                            <button type="button" class="btn btn-secondary btn-sm"
                                    data-sale-id="<?= (int) $sale['id'] ?>"
                                    data-sale-number="<?= htmlspecialchars((string) $sale['sale_number'], ENT_QUOTES, 'UTF-8') ?>"
                                    onclick="openVoidRequest(this)">
                                Request Void
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<dialog id="void-request" class="w-[min(100%,32rem)] rounded-2xl p-0 shadow-xl backdrop:bg-gray-900/50">
    <form method="post" action="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/attendant/api_request_approval.php" class="p-5">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($approvalCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action_type" value="void_sale">
        <input type="hidden" name="redirect_to" value="/attendant/shift.php">
        <input type="hidden" name="sale_id" id="void-sale-id" value="">
        <input type="hidden" name="sale_number" id="void-sale-number" value="">
        <h2 class="text-lg font-bold text-gray-900">Request Void</h2>
        <p id="void-sale-label" class="mt-1 text-sm text-gray-500"></p>
        <label class="mt-4 block">
            <span class="form-label">Reason</span>
            <textarea name="reason" required minlength="3" maxlength="500" rows="3" class="form-input"
                      placeholder="Customer returned the item before leaving the counter"></textarea>
        </label>
        <div class="mt-4 flex justify-end gap-2">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('void-request').close()">Cancel</button>
            <button type="submit" class="btn btn-primary">Send to Admin</button>
        </div>
    </form>
</dialog>

<script>
function openVoidRequest(button) {
    document.getElementById('void-sale-id').value = button.dataset.saleId || '';
    document.getElementById('void-sale-number').value = button.dataset.saleNumber || '';
    document.getElementById('void-sale-label').textContent = button.dataset.saleNumber || '';
    document.getElementById('void-request').showModal();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
