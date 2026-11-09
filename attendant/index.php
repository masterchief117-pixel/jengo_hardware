<?php
/**
 * attendant/index.php
 *
 * Attendant POS dashboard.
 * Accessible to roles: attendant, admin (via require_attendant()).
 *
 * GET  : Display orders with status 'paid' (ready to pack / fulfil).
 * POST : Update a single order's status from 'paid' → 'completed'
 *        (Mark as Packed) and stamp the attendant_id.
 *
 * Also shows a read-only view of 'completed' orders (today's fulfilments)
 * for reference, and a live count of all active statuses in the top bar.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// Attendants AND admins can access this page
require_attendant();

$pdo         = db();
$attendantId = get_user_id();
if (empty($_SESSION['attendant_approval_csrf'])) {
    $_SESSION['attendant_approval_csrf'] = bin2hex(random_bytes(32));
}
$approvalCsrfToken = (string) $_SESSION['attendant_approval_csrf'];

// ══════════════════════════════════════════════════════════════════════════
// POST — update order status
// ══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']   ?? '';
    $orderId = (int) ($_POST['order_id'] ?? 0);

    if ($orderId < 1) {
        redirect('/attendant/index.php', 'Invalid order.', 'error');
    }

    switch ($action) {

        // Mark a 'paid' order as 'completed' (packed & ready / dispatched)
        case 'mark_packed':
            $stmt = $pdo->prepare(
                "UPDATE orders
                 SET    status       = 'completed',
                        attendant_id = ?
                 WHERE  id     = ?
                   AND  status = 'paid'"   // only transition from paid
            );
            $stmt->execute([$attendantId, $orderId]);

            if ($stmt->rowCount() > 0) {
                redirect(
                    '/attendant/index.php',
                    "Order #{$orderId} marked as Packed & Completed.",
                    'success'
                );
            } else {
                redirect(
                    '/attendant/index.php',
                    'Could not update order — it may have already been processed.',
                    'warning'
                );
            }

        // Mark a 'paid' order as 'cancelled' (rare, admin-level action)
        case 'cancel_order':
            // Only admins can cancel paid orders
            if (!has_role('admin')) {
                redirect(
                    '/attendant/index.php',
                    'Only admins can cancel a paid order.',
                    'error'
                );
            }
            $stmt = $pdo->prepare(
                "UPDATE orders
                 SET    status = 'cancelled', attendant_id = ?
                 WHERE  id = ? AND status IN ('paid','processing','pending')"
            );
            $stmt->execute([$attendantId, $orderId]);
            redirect(
                '/attendant/index.php',
                "Order #{$orderId} cancelled.",
                'info'
            );

        default:
            redirect('/attendant/index.php', 'Unknown action.', 'error');
    }
}

// ══════════════════════════════════════════════════════════════════════════
// STATUS COUNTS (dashboard KPIs)
// ══════════════════════════════════════════════════════════════════════════
$stmtCounts = $pdo->query(
    "SELECT
         SUM(status = 'pending'    OR status = 'processing') AS awaiting_payment,
         SUM(status = 'paid')                                AS ready_to_pack,
         SUM(status = 'completed'
             AND DATE(updated_at) = CURDATE())               AS packed_today,
         SUM(status = 'cancelled')                           AS cancelled_total
     FROM orders"
);
$counts = $stmtCounts->fetch();

// ══════════════════════════════════════════════════════════════════════════
// APPROVAL REQUESTS
// ══════════════════════════════════════════════════════════════════════════
$approvalStmt = $pdo->prepare(
    'SELECT id, action_type, action_payload, status, created_at
     FROM approval_requests
     WHERE attendant_id = ?
     ORDER BY created_at DESC
     LIMIT 10'
);
$approvalStmt->execute([$attendantId]);
$approvalRequests = $approvalStmt->fetchAll();

// ══════════════════════════════════════════════════════════════════════════
// PAID ORDERS — main list
// ══════════════════════════════════════════════════════════════════════════
$stmtPaid = $pdo->query(
    "SELECT o.id, o.order_number, o.total_amount, o.notes,
            o.created_at, o.updated_at,
            u.full_name  AS customer_name,
            u.phone      AS customer_phone,
            u.email      AS customer_email,
            COUNT(oi.id) AS item_count,
            SUM(oi.quantity) AS total_qty
     FROM   orders o
     LEFT JOIN users       u  ON u.id  = o.customer_id
     LEFT JOIN order_items oi ON oi.order_id = o.id
     WHERE  o.status = 'paid'
     GROUP  BY o.id
     ORDER  BY o.updated_at ASC"   // oldest paid first → FIFO queue
);
$paidOrders = $stmtPaid->fetchAll();

// ══════════════════════════════════════════════════════════════════════════
// COMPLETED ORDERS — today only (reference / audit)
// ══════════════════════════════════════════════════════════════════════════
$stmtDone = $pdo->query(
    "SELECT o.id, o.order_number, o.total_amount, o.notes,
            o.updated_at,
            u.full_name AS customer_name,
            a.full_name AS attendant_name,
            COUNT(oi.id) AS item_count
     FROM   orders o
     LEFT JOIN users       u  ON u.id  = o.customer_id
     LEFT JOIN users       a  ON a.id  = o.attendant_id
     LEFT JOIN order_items oi ON oi.order_id = o.id
     WHERE  o.status = 'completed'
       AND  DATE(o.updated_at) = CURDATE()
     GROUP  BY o.id
     ORDER  BY o.updated_at DESC
     LIMIT  50"
);
$doneToday = $stmtDone->fetchAll();

// ══════════════════════════════════════════════════════════════════════════
// HELPER — fulfilment label from notes field
// ══════════════════════════════════════════════════════════════════════════
function fulfil_label(string $notes): string
{
    $lower = strtolower($notes);
    if (str_contains($lower, '[pickup]'))  return '<span class="badge badge-charcoal">🏪 Pickup</span>';
    if (str_contains($lower, '[delivery]')) return '<span class="badge badge-info">🚚 Delivery</span>';
    return '<span class="badge badge-charcoal">—</span>';
}

$pageTitle = 'Attendant Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════
     ATTENDANT DASHBOARD
══════════════════════════════════════════════════════════════════════ -->

<!-- Header row -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between
            gap-3 mb-8">
    <div>
        <h1 class="text-2xl font-extrabold text-gray-900">Attendant Dashboard</h1>
        <p class="text-sm text-gray-500 mt-0.5">
            Hello, <span class="font-semibold text-orange">
                <?= htmlspecialchars(get_user_name() ?? '') ?>
            </span> ·
            <?= date('l, d F Y') ?> · <?= date('H:i') ?>
        </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <a href="<?= $base ?>/attendant/pos.php" class="btn btn-primary btn-sm">POS</a>
        <a href="<?= $base ?>/attendant/inventory.php" class="btn btn-secondary btn-sm">Inventory Lookup</a>
        <a href="<?= $base ?>/attendant/quotes.php" class="btn btn-secondary btn-sm">Quotes &amp; Customers</a>
        <a href="<?= $base ?>/attendant/shift.php" class="btn btn-secondary btn-sm">My Shift</a>
        <a href="<?= $base ?>/attendant/approvals.php" class="btn btn-secondary btn-sm">Pending Approvals Status</a>
        <span class="badge badge-orange capitalize"><?= get_user_role() ?></span>
        <a href="<?= $base ?>/logout.php"
           class="btn btn-secondary btn-sm">Sign Out</a>
    </div>
</div>

<section class="mb-8 rounded-2xl border border-amber-200 bg-amber-50 p-5"
         aria-labelledby="approval-request-heading">
    <div class="mb-4">
        <h2 id="approval-request-heading" class="text-lg font-bold text-gray-900">
            Request Admin Approval
        </h2>
        <p class="mt-1 text-sm text-gray-600">
            Submit a critical action for review. Include its details as a JSON object.
        </p>
    </div>
    <form method="post" action="<?= htmlspecialchars($base) ?>/attendant/request_approval.php"
          class="grid gap-3 md:grid-cols-[12rem_minmax(0,1fr)_auto]">
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($approvalCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <label>
            <span class="form-label">Action type</span>
            <select name="action_type" class="form-input" required>
                <option value="large_discount">Large discount</option>
                <option value="price_override">Price override</option>
                <option value="other_action">Other</option>
            </select>
        </label>
        <label>
            <span class="form-label">Action details (JSON)</span>
            <textarea name="action_payload" class="form-input min-h-10" rows="2"
                      maxlength="10000" required
                      placeholder='{"cart_id": 45, "requested_discount": 5000}'></textarea>
        </label>
        <div class="flex items-end">
            <button type="submit" class="btn btn-primary whitespace-nowrap">
                Request Approval
            </button>
        </div>
    </form>

    <?php if ($approvalRequests !== []): ?>
        <div class="mt-5 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-amber-200 text-gray-600">
                        <th class="py-2 pr-4">Action</th>
                        <th class="py-2 pr-4">Details</th>
                        <th class="py-2 pr-4">Requested</th>
                        <th class="py-2">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approvalRequests as $request): ?>
                        <?php $requestStatus = (string) $request['status']; ?>
                        <tr class="border-b border-amber-100 last:border-0">
                            <td class="py-3 pr-4"><?= htmlspecialchars((string) $request['action_type']) ?></td>
                            <td class="py-3 pr-4">
                                <code class="break-all text-xs"><?= htmlspecialchars((string) $request['action_payload']) ?></code>
                            </td>
                            <td class="py-3 pr-4 whitespace-nowrap">
                                <?= htmlspecialchars((string) $request['created_at']) ?>
                            </td>
                            <td class="py-3">
                                <?php if ($requestStatus === 'pending'): ?>
                                    <span class="badge badge-warning">Pending Admin Approval...</span>
                                <?php elseif ($requestStatus === 'approved'): ?>
                                    <span class="badge badge-success">Approved — authorized to proceed</span>
                                <?php else: ?>
                                    <span class="badge badge-charcoal">Rejected</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>


<!-- ══════════════════════════════════════════════════════════════════════
     KPI STRIP
══════════════════════════════════════════════════════════════════════ -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
    <?php
    $kpis = [
        [
            'value' => (int)($counts['awaiting_payment'] ?? 0),
            'label' => 'Awaiting Payment',
            'color' => 'border-l-yellow-400',
            'icon'  => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
        [
            'value' => (int)($counts['ready_to_pack'] ?? 0),
            'label' => 'Ready to Pack',
            'color' => 'border-l-orange',
            'icon'  => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10',
        ],
        [
            'value' => (int)($counts['packed_today'] ?? 0),
            'label' => 'Packed Today',
            'color' => 'border-l-green-500',
            'icon'  => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
        [
            'value' => (int)($counts['cancelled_total'] ?? 0),
            'label' => 'Cancelled (All)',
            'color' => 'border-l-red-400',
            'icon'  => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
    ];
    foreach ($kpis as $kpi): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100
                border-l-4 <?= $kpi['color'] ?> p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-gray-50 flex items-center
                    justify-center flex-shrink-0" aria-hidden="true">
            <svg class="w-5 h-5 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor"
                 stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="<?= $kpi['icon'] ?>"/>
            </svg>
        </div>
        <div>
            <p class="text-2xl font-extrabold text-gray-900 leading-none">
                <?= number_format($kpi['value']) ?>
            </p>
            <p class="text-xs text-gray-500 mt-0.5"><?= $kpi['label'] ?></p>
        </div>
    </div>
    <?php endforeach; ?>
</div>


<!-- ══════════════════════════════════════════════════════════════════════
     PAID ORDERS — READY TO PACK
══════════════════════════════════════════════════════════════════════ -->
<section class="mb-10" aria-labelledby="paid-heading">

    <div class="flex items-center justify-between mb-4">
        <h2 id="paid-heading" class="section-heading text-lg flex items-center gap-2">
            Ready to Pack
            <?php if (!empty($paidOrders)): ?>
            <span class="inline-flex items-center justify-center w-6 h-6
                         rounded-full bg-orange text-white text-xs font-bold"
                  aria-label="<?= count($paidOrders) ?> orders">
                <?= count($paidOrders) ?>
            </span>
            <?php endif; ?>
        </h2>
        <!-- Refresh page button -->
        <button onclick="window.location.reload()"
                class="btn btn-ghost btn-sm flex items-center gap-1.5"
                aria-label="Refresh order list">
            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor"
                 stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M4 4v5h.582m15.356 2A8.001 8.001 0
                         004.582 9m0 0H9m11 11v-5h-.581m0
                         0a8.003 8.003 0 01-15.357-2m15.357
                         2H15"/>
            </svg>
            Refresh
        </button>
    </div>

    <?php if (empty($paidOrders)): ?>
    <!-- Empty state -->
    <div class="bg-white rounded-2xl border border-dashed border-gray-200
                py-16 text-center" role="status" aria-live="polite">
        <div class="text-6xl mb-4" aria-hidden="true">✅</div>
        <h3 class="text-base font-semibold text-gray-700">
            All clear — no paid orders waiting
        </h3>
        <p class="text-gray-400 text-sm mt-1">
            New paid orders will appear here automatically.
        </p>
    </div>

    <?php else: ?>

    <!-- Desktop table -->
    <div class="hidden sm:block bg-white rounded-2xl shadow-sm
                border border-gray-100 overflow-hidden">
        <table class="data-table" aria-label="Paid orders ready to pack">
            <thead>
                <tr>
                    <th scope="col">Order #</th>
                    <th scope="col">Customer</th>
                    <th scope="col">Paid At</th>
                    <th scope="col">Items</th>
                    <th scope="col">Total</th>
                    <th scope="col">Fulfilment</th>
                    <th scope="col" class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($paidOrders as $order): ?>
                <tr id="order-row-<?= $order['id'] ?>">

                    <!-- Order number -->
                    <td>
                        <button type="button"
                                class="font-mono text-sm font-bold text-orange
                                       hover:underline text-left"
                                onclick="toggleDetail('paid', <?= $order['id'] ?>)"
                                aria-expanded="false"
                                aria-controls="paid-detail-<?= $order['id'] ?>">
                            <?= htmlspecialchars($order['order_number']) ?>
                        </button>
                    </td>

                    <!-- Customer -->
                    <td>
                        <p class="font-medium text-gray-800 text-sm">
                            <?= $order['customer_name']
                                ? htmlspecialchars($order['customer_name'])
                                : '<span class="text-gray-400">Walk-in</span>' ?>
                        </p>
                        <?php if ($order['customer_phone']): ?>
                        <p class="text-xs text-gray-400 mt-0.5">
                            <?= htmlspecialchars($order['customer_phone']) ?>
                        </p>
                        <?php endif; ?>
                    </td>

                    <!-- Paid at -->
                    <td class="whitespace-nowrap text-sm text-gray-500">
                        <?= date('d M, H:i', strtotime($order['updated_at'])) ?>
                        <p class="text-xs text-gray-400">
                            <?= human_time_diff($order['updated_at']) ?> ago
                        </p>
                    </td>

                    <!-- Items -->
                    <td class="text-center">
                        <span class="inline-block bg-gray-100 text-gray-700 text-xs
                                     font-semibold px-2 py-0.5 rounded-full">
                            <?= (int)$order['item_count'] ?>
                            item<?= (int)$order['item_count'] !== 1 ? 's' : '' ?>
                        </span>
                    </td>

                    <!-- Total -->
                    <td class="font-bold text-orange whitespace-nowrap">
                        KES <?= number_format((float)$order['total_amount'], 2) ?>
                    </td>

                    <!-- Fulfilment -->
                    <td><?= fulfil_label($order['notes'] ?? '') ?></td>

                    <!-- Action -->
                    <td class="text-center">
                        <div class="flex items-center justify-center gap-2">
                            <!-- Mark as Packed -->
                            <form method="POST" action="index.php">
                                <input type="hidden" name="action"   value="mark_packed">
                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                <button type="submit"
                                        class="btn btn-primary btn-sm whitespace-nowrap"
                                        data-confirm="Mark order <?= htmlspecialchars($order['order_number'], ENT_QUOTES) ?> as Packed & Completed?">
                                    ✓ Mark Packed
                                </button>
                            </form>

                            <?php if (has_role('admin')): ?>
                            <!-- Cancel (admin only) -->
                            <form method="POST" action="index.php">
                                <input type="hidden" name="action"   value="cancel_order">
                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                <button type="submit"
                                        class="btn btn-danger btn-sm"
                                        data-confirm="Cancel order <?= htmlspecialchars($order['order_number'], ENT_QUOTES) ?>? This cannot be undone.">
                                    Cancel
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <!-- Expandable line items -->
                <tr id="paid-detail-<?= $order['id'] ?>"
                    class="hidden bg-amber-50/40"
                    aria-label="Line items for <?= htmlspecialchars($order['order_number']) ?>">
                    <td colspan="7" class="px-6 py-4">
                        <?php
                        $stmtLi = $pdo->prepare(
                            'SELECT product_name, unit_price, quantity, subtotal
                             FROM order_items WHERE order_id = ? ORDER BY id'
                        );
                        $stmtLi->execute([$order['id']]);
                        $lineItems = $stmtLi->fetchAll();
                        ?>
                        <p class="text-xs font-bold text-gray-500 uppercase
                                  tracking-wider mb-2">Items to pack:</p>
                        <ul class="space-y-1.5" role="list">
                            <?php foreach ($lineItems as $li): ?>
                            <li class="flex items-center justify-between
                                       text-sm text-gray-700 border-b
                                       border-amber-100 pb-1.5 last:border-0">
                                <span class="flex items-center gap-2">
                                    <span class="w-6 h-6 rounded bg-orange text-white
                                                 text-xs font-bold flex items-center
                                                 justify-center flex-shrink-0"
                                          aria-hidden="true">
                                        <?= (int)$li['quantity'] ?>
                                    </span>
                                    <?= htmlspecialchars($li['product_name']) ?>
                                </span>
                                <span class="font-semibold text-gray-800 whitespace-nowrap ml-4">
                                    KES <?= number_format((float)$li['subtotal'], 2) ?>
                                </span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php
                        $noteClean = trim(preg_replace('/^\[(pickup|delivery)\]\s*/i', '', $order['notes'] ?? ''));
                        if ($noteClean !== ''): ?>
                        <p class="text-xs text-gray-500 mt-3">
                            <span class="font-semibold">Customer note:</span>
                            <?= htmlspecialchars($noteClean) ?>
                        </p>
                        <?php endif; ?>
                    </td>
                </tr>

                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile cards -->
    <div class="sm:hidden space-y-4">
        <?php foreach ($paidOrders as $order): ?>
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm
                    overflow-hidden">
            <!-- Card header -->
            <div class="flex items-start justify-between px-4 pt-4 pb-2">
                <div>
                    <p class="font-mono font-bold text-sm text-orange">
                        <?= htmlspecialchars($order['order_number']) ?>
                    </p>
                    <p class="text-xs text-gray-400 mt-0.5">
                        Paid <?= human_time_diff($order['updated_at']) ?> ago
                    </p>
                </div>
                <?= fulfil_label($order['notes'] ?? '') ?>
            </div>

            <!-- Customer + total -->
            <div class="flex items-center justify-between px-4 pb-3">
                <div>
                    <p class="text-sm font-medium text-gray-700">
                        <?= $order['customer_name']
                            ? htmlspecialchars($order['customer_name'])
                            : 'Walk-in' ?>
                    </p>
                    <p class="text-xs text-gray-400">
                        <?= (int)$order['item_count'] ?> items ·
                        <?= (int)$order['total_qty'] ?> units
                    </p>
                </div>
                <p class="font-extrabold text-orange">
                    KES <?= number_format((float)$order['total_amount'], 2) ?>
                </p>
            </div>

            <!-- Action -->
            <div class="px-4 pb-4">
                <form method="POST" action="index.php">
                    <input type="hidden" name="action"   value="mark_packed">
                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                    <button type="submit"
                            class="btn btn-primary btn-sm btn-block"
                            data-confirm="Mark order <?= htmlspecialchars($order['order_number'], ENT_QUOTES) ?> as Packed?">
                        ✓ Mark as Packed & Complete
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; // paid orders ?>
</section>


<!-- ══════════════════════════════════════════════════════════════════════
     COMPLETED TODAY (reference)
══════════════════════════════════════════════════════════════════════ -->
<?php if (!empty($doneToday)): ?>
<section aria-labelledby="done-heading">
    <h2 id="done-heading" class="section-heading text-base mb-4">
        Packed Today
        <span class="text-xs font-normal text-gray-400 ml-2">
            (<?= count($doneToday) ?> order<?= count($doneToday) !== 1 ? 's' : '' ?>)
        </span>
    </h2>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <table class="data-table text-sm" aria-label="Completed orders today">
            <thead>
                <tr>
                    <th scope="col">Order #</th>
                    <th scope="col">Customer</th>
                    <th scope="col">Packed By</th>
                    <th scope="col">Completed At</th>
                    <th scope="col">Items</th>
                    <th scope="col">Total</th>
                    <th scope="col">Fulfilment</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($doneToday as $o): ?>
                <tr>
                    <td class="font-mono font-semibold text-gray-700">
                        <?= htmlspecialchars($o['order_number']) ?>
                    </td>
                    <td class="text-gray-600">
                        <?= $o['customer_name']
                            ? htmlspecialchars($o['customer_name'])
                            : '<span class="text-gray-400">Walk-in</span>' ?>
                    </td>
                    <td class="text-gray-600">
                        <?= $o['attendant_name']
                            ? htmlspecialchars($o['attendant_name'])
                            : '<span class="text-gray-400">—</span>' ?>
                    </td>
                    <td class="text-gray-500 whitespace-nowrap">
                        <?= date('H:i', strtotime($o['updated_at'])) ?>
                    </td>
                    <td class="text-center">
                        <span class="inline-block bg-gray-100 text-gray-700 text-xs
                                     font-semibold px-2 py-0.5 rounded-full">
                            <?= (int)$o['item_count'] ?>
                        </span>
                    </td>
                    <td class="font-bold text-green-600 whitespace-nowrap">
                        KES <?= number_format((float)$o['total_amount'], 2) ?>
                    </td>
                    <td><?= fulfil_label($o['notes'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>


<!-- Auto-refresh & toggle JS ───────────────────────────────────────── -->
<script>
// Toggle expandable detail rows
function toggleDetail(prefix, orderId) {
    var row    = document.getElementById(prefix + '-detail-' + orderId);
    var btn    = document.querySelector('[aria-controls="' + prefix + '-detail-' + orderId + '"]');
    if (!row) return;
    var hidden = row.classList.contains('hidden');
    row.classList.toggle('hidden', !hidden);
    if (btn) btn.setAttribute('aria-expanded', hidden ? 'true' : 'false');
}

// Auto-refresh every 60 seconds to catch new paid orders
(function () {
    var countdown = 60;
    var badge     = document.createElement('span');
    badge.className = 'text-xs text-gray-400 ml-2 font-normal';

    var heading = document.getElementById('paid-heading');
    if (heading) heading.appendChild(badge);

    setInterval(function () {
        countdown--;
        badge.textContent = '(refreshing in ' + countdown + 's)';
        if (countdown <= 0) {
            window.location.reload();
        }
    }, 1000);
})();
</script>

<?php
// ── Helper: human-readable time difference ───────────────────────────────
function human_time_diff(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return $diff . 's';
    if ($diff < 3600)  return floor($diff / 60)   . 'm';
    if ($diff < 86400) return floor($diff / 3600)  . 'h';
    return floor($diff / 86400) . 'd';
}

require_once __DIR__ . '/../includes/footer.php';
?>
