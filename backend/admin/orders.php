<?php
/**
 * admin/orders.php
 *
 * Full order management panel for admins.
 *
 * GET  ?view=list  — paginated, filterable order table (default)
 * GET  ?view=detail&id=X — single order detail with line items
 * POST action=update_status — change an order's status
 * POST action=add_note      — append an admin note to an order
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_admin();

$pdo = db();

// ══════════════════════════════════════════════════════════════════════════
// VALID STATUS TRANSITIONS
// Each status maps to the labels/values an admin may set it to.
// Admin can set any status from any state (override power).
// ══════════════════════════════════════════════════════════════════════════
const ORDER_STATUSES = [
    'pending'    => 'Pending',
    'processing' => 'Processing',
    'paid'       => 'Paid',
    'completed'  => 'Packed / Collected',
    'cancelled'  => 'Cancelled',
    'refunded'   => 'Refunded',
];

// Status → badge CSS class
function order_status_badge(string $status): string
{
    $map = [
        'pending'    => 'status-pending',
        'processing' => 'status-processing',
        'paid'       => 'status-paid',
        'completed'  => 'status-completed',
        'cancelled'  => 'status-cancelled',
        'refunded'   => 'status-refunded',
    ];
    $cls   = $map[$status] ?? 'badge-charcoal';
    $label = ORDER_STATUSES[$status] ?? ucfirst($status);
    return "<span class=\"badge {$cls}\">{$label}</span>";
}

// Fulfilment label from notes
function fulfil_badge(string $notes): string
{
    $l = strtolower($notes);
    if (str_contains($l, '[pickup]'))   return '<span class="badge badge-charcoal">🏪 Pickup</span>';
    if (str_contains($l, '[delivery]')) return '<span class="badge badge-info">🚚 Delivery</span>';
    return '';
}

// ══════════════════════════════════════════════════════════════════════════
// POST HANDLERS
// ══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']   ?? '';
    $orderId = (int) ($_POST['order_id'] ?? 0);

    if ($orderId < 1) {
        redirect('/admin/orders.php', 'Invalid order.', 'error');
    }

    // ── Update status ─────────────────────────────────────────────────────
    if ($action === 'update_status') {
        $newStatus = $_POST['new_status'] ?? '';

        if (!array_key_exists($newStatus, ORDER_STATUSES)) {
            redirect('/admin/orders.php', 'Invalid status.', 'error');
        }

        $pdo->prepare(
            "UPDATE orders SET status = ? WHERE id = ?"
        )->execute([$newStatus, $orderId]);

        // If completing → stamp the attendant_id
        if ($newStatus === 'completed') {
            $pdo->prepare(
                "UPDATE orders SET attendant_id = ? WHERE id = ? AND attendant_id IS NULL"
            )->execute([get_user_id(), $orderId]);
        }

        $label = ORDER_STATUSES[$newStatus];
        redirect(
            "/admin/orders.php?view=detail&id={$orderId}",
            "Order status updated to \"{$label}\".",
            'success'
        );
    }

    // ── Add admin note ────────────────────────────────────────────────────
    if ($action === 'add_note') {
        $note = trim($_POST['admin_note'] ?? '');

        if ($note === '') {
            redirect("/admin/orders.php?view=detail&id={$orderId}", 'Note cannot be empty.', 'warning');
        }

        // Append to existing notes
        $stmt = $pdo->prepare('SELECT notes FROM orders WHERE id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $existingNotes = $stmt->fetchColumn() ?: '';

        $timestamp   = date('d M Y H:i');
        $adminName   = get_user_name() ?? 'Admin';
        $appendedNote = $existingNotes
            . "\n[Admin – {$adminName}, {$timestamp}]: " . $note;

        $pdo->prepare(
            'UPDATE orders SET notes = ? WHERE id = ?'
        )->execute([trim($appendedNote), $orderId]);

        redirect(
            "/admin/orders.php?view=detail&id={$orderId}",
            'Note added.',
            'success'
        );
    }
}

// ══════════════════════════════════════════════════════════════════════════
// GET ROUTING
// ══════════════════════════════════════════════════════════════════════════
$view = $_GET['view'] ?? 'list';

// ── DETAIL VIEW ───────────────────────────────────────────────────────────
if ($view === 'detail') {
    $orderId = (int) ($_GET['id'] ?? 0);

    $stmt = $pdo->prepare(
        "SELECT o.*,
                u.full_name  AS customer_name,
                u.email      AS customer_email,
                u.phone      AS customer_phone,
                a.full_name  AS attendant_name
         FROM   orders o
         LEFT JOIN users u ON u.id = o.customer_id
         LEFT JOIN users a ON a.id = o.attendant_id
         WHERE  o.id = ? LIMIT 1"
    );
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        redirect('/admin/orders.php', 'Order not found.', 'error');
    }

    // Line items
    $itemsStmt = $pdo->prepare(
        'SELECT oi.*, p.image_url
         FROM   order_items oi
         LEFT JOIN products p ON p.id = oi.product_id
         WHERE  oi.order_id = ? ORDER BY oi.id ASC'
    );
    $itemsStmt->execute([$orderId]);
    $lineItems = $itemsStmt->fetchAll();

    // Payment record(s)
    $payStmt = $pdo->prepare(
        'SELECT * FROM payments WHERE order_id = ? ORDER BY created_at DESC'
    );
    $payStmt->execute([$orderId]);
    $payments = $payStmt->fetchAll();

    $pageTitle = 'Order ' . $order['order_number'];
    require_once __DIR__ . '/../includes/header.php';
    ?>

    <!-- ── ADMIN LAYOUT ──────────────────────────────────────────────── -->
    <div class="flex gap-0 -mx-4 sm:-mx-6 lg:-mx-8 min-h-screen">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>

    <div class="flex-1 min-w-0 px-4 sm:px-6 lg:px-8 py-6">

        <!-- Breadcrumb -->
        <nav class="flex items-center gap-2 text-sm text-gray-500 mb-6"
             aria-label="Breadcrumb">
            <a href="<?= $base ?>/admin/index.php"
               class="hover:text-orange">Dashboard</a>
            <span>/</span>
            <a href="<?= $base ?>/admin/orders.php"
               class="hover:text-orange">Orders</a>
            <span>/</span>
            <span class="text-gray-800 font-medium" aria-current="page">
                <?= htmlspecialchars($order['order_number']) ?>
            </span>
        </nav>

        <!-- Header row -->
        <div class="flex flex-col sm:flex-row sm:items-center
                    sm:justify-between gap-3 mb-6">
            <div>
                <h1 class="text-2xl font-extrabold text-gray-900">
                    <?= htmlspecialchars($order['order_number']) ?>
                </h1>
                <p class="text-sm text-gray-500 mt-0.5">
                    Placed <?= date('d M Y \a\t H:i', strtotime($order['created_at'])) ?>
                </p>
            </div>
            <div class="flex items-center gap-3">
                <?= order_status_badge($order['status']) ?>
                <a href="<?= $base ?>/admin/orders.php"
                   class="btn btn-secondary btn-sm">← All Orders</a>
            </div>
        </div>


        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- ── LEFT: items + notes + payment ─────────────────────── -->
            <div class="lg:col-span-2 space-y-6">

                <!-- Line items -->
                <section class="bg-white rounded-2xl border border-gray-100
                                shadow-sm overflow-hidden"
                         aria-labelledby="items-heading">
                    <div class="px-5 py-4 border-b border-gray-100">
                        <h2 id="items-heading"
                            class="text-base font-bold text-gray-900">
                            Order Items
                            <span class="text-sm font-normal text-gray-400 ml-1">
                                (<?= count($lineItems) ?> lines)
                            </span>
                        </h2>
                    </div>
                    <table class="w-full text-sm" aria-label="Order line items">
                        <thead class="bg-gray-50 text-xs text-gray-500
                                      uppercase tracking-wider">
                            <tr>
                                <th class="text-left px-5 py-3" scope="col">Product</th>
                                <th class="text-center px-4 py-3" scope="col">Qty</th>
                                <th class="text-right px-4 py-3" scope="col">Unit</th>
                                <th class="text-right px-5 py-3" scope="col">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php foreach ($lineItems as $li): ?>
                            <tr class="hover:bg-gray-50/50 transition-colors">
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-lg bg-gray-100
                                                    overflow-hidden flex-shrink-0">
                                            <?php if ($li['image_url']): ?>
                                            <img src="<?= htmlspecialchars($base . $li['image_url']) ?>"
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
                                                          d="M4 16l4.586-4.586a2 2 0 012.828 0L16
                                                             16m-2-2l1.586-1.586a2 2 0 012.828
                                                             0L20 14m-6-6h.01M6 20h12a2 2 0
                                                             002-2V6a2 2 0 00-2-2H6a2 2 0 00-2
                                                             2v12a2 2 0 002 2z"/>
                                                </svg>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <span class="font-medium text-gray-800">
                                            <?= htmlspecialchars($li['product_name']) ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center font-semibold
                                           text-gray-700">
                                    <?= (int)$li['quantity'] ?>
                                </td>
                                <td class="px-4 py-3 text-right text-gray-500">
                                    KES <?= number_format((float)$li['unit_price'], 2) ?>
                                </td>
                                <td class="px-5 py-3 text-right font-bold text-orange">
                                    KES <?= number_format((float)$li['subtotal'], 2) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200">
                                <td colspan="3"
                                    class="px-5 py-3 text-right font-bold
                                           text-gray-800">
                                    Order Total
                                </td>
                                <td class="px-5 py-3 text-right text-xl
                                           font-extrabold text-orange">
                                    KES <?= number_format((float)$order['total_amount'], 2) ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </section>

                <!-- Notes -->
                <section class="bg-white rounded-2xl border border-gray-100
                                shadow-sm p-6"
                         aria-labelledby="notes-heading">
                    <h2 id="notes-heading"
                        class="text-base font-bold text-gray-900 mb-3">
                        Notes &amp; History
                    </h2>

                    <?php
                    $rawNotes = $order['notes'] ?? '';
                    $noteLines = array_filter(
                        array_map('trim', explode("\n", $rawNotes))
                    );
                    ?>
                    <?php if (!empty($noteLines)): ?>
                    <ul class="space-y-2 mb-5 text-sm" role="list">
                        <?php foreach ($noteLines as $nl):
                            $isAdmin = str_contains($nl, '[Admin –');
                            $cleanedLine = trim(preg_replace('/^\[(pickup|delivery)\]\s*/i', '', $nl));
                            if ($cleanedLine === '') continue;
                        ?>
                        <li class="flex items-start gap-2">
                            <span class="mt-0.5 flex-shrink-0
                                         <?= $isAdmin ? 'text-orange' : 'text-gray-400' ?>"
                                  aria-hidden="true">
                                <?= $isAdmin ? '👤' : '💬' ?>
                            </span>
                            <span class="<?= $isAdmin ? 'text-gray-700 font-medium' : 'text-gray-600' ?>">
                                <?= htmlspecialchars($cleanedLine) ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <p class="text-sm text-gray-400 mb-4">No notes on this order.</p>
                    <?php endif; ?>

                    <!-- Add note form -->
                    <form method="POST" action="orders.php">
                        <input type="hidden" name="action"   value="add_note">
                        <input type="hidden" name="order_id" value="<?= $orderId ?>">
                        <div class="flex gap-2">
                            <input type="text" name="admin_note"
                                   class="form-input flex-1 py-2 text-sm"
                                   placeholder="Add an internal note…"
                                   maxlength="300" required />
                            <button type="submit"
                                    class="btn btn-primary btn-sm whitespace-nowrap">
                                Add Note
                            </button>
                        </div>
                    </form>
                </section>

                <!-- Payment records -->
                <?php if (!empty($payments)): ?>
                <section class="bg-white rounded-2xl border border-gray-100
                                shadow-sm p-6"
                         aria-labelledby="pay-heading">
                    <h2 id="pay-heading"
                        class="text-base font-bold text-gray-900 mb-4">
                        Payment Records
                    </h2>
                    <div class="space-y-3">
                    <?php foreach ($payments as $pay):
                        $payBadge = match($pay['status']) {
                            'success'   => 'badge-success',
                            'failed'    => 'badge-error',
                            'cancelled' => 'badge-warning',
                            default     => 'badge-charcoal',
                        };
                    ?>
                    <div class="border border-gray-100 rounded-xl p-4 text-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold text-gray-800 capitalize">
                                    <?= htmlspecialchars($pay['payment_method']) ?>
                                    <span class="badge <?= $payBadge ?> ml-1">
                                        <?= ucfirst($pay['status']) ?>
                                    </span>
                                </p>
                                <p class="text-gray-500 mt-0.5">
                                    KES <?= number_format((float)$pay['amount'], 2) ?>
                                    · <?= htmlspecialchars($pay['currency']) ?>
                                </p>
                            </div>
                            <p class="text-xs text-gray-400 whitespace-nowrap flex-shrink-0">
                                <?= date('d M Y H:i', strtotime($pay['created_at'])) ?>
                            </p>
                        </div>
                        <?php if ($pay['gateway_reference']): ?>
                        <p class="text-xs text-gray-400 mt-2 font-mono">
                            Ref: <?= htmlspecialchars($pay['gateway_reference']) ?>
                        </p>
                        <?php endif; ?>
                        <?php if ($pay['phone_number']): ?>
                        <p class="text-xs text-gray-400 mt-0.5">
                            Phone: <?= htmlspecialchars($pay['phone_number']) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>

            </div><!-- /left -->


            <!-- ── RIGHT: customer info + status control ─────────────── -->
            <div class="space-y-5">

                <!-- Customer card -->
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h2 class="text-sm font-bold text-gray-700 uppercase
                               tracking-wider mb-3">
                        Customer
                    </h2>
                    <?php if ($order['customer_name']): ?>
                    <p class="font-semibold text-gray-900">
                        <?= htmlspecialchars($order['customer_name']) ?>
                    </p>
                    <p class="text-sm text-gray-500 mt-0.5">
                        <?= htmlspecialchars($order['customer_email'] ?? '—') ?>
                    </p>
                    <p class="text-sm text-gray-500">
                        <?= htmlspecialchars($order['customer_phone'] ?? '—') ?>
                    </p>
                    <?php else: ?>
                    <p class="text-sm text-gray-400 italic">Walk-in / no account</p>
                    <?php endif; ?>

                    <?php if ($order['attendant_name']): ?>
                    <div class="mt-3 pt-3 border-t border-gray-100">
                        <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">
                            Processed by
                        </p>
                        <p class="text-sm font-medium text-gray-700">
                            <?= htmlspecialchars($order['attendant_name']) ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Order info card -->
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h2 class="text-sm font-bold text-gray-700 uppercase
                               tracking-wider mb-3">
                        Order Info
                    </h2>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Order #</dt>
                            <dd class="font-mono font-semibold text-gray-800">
                                <?= htmlspecialchars($order['order_number']) ?>
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Date</dt>
                            <dd class="text-gray-700">
                                <?= date('d M Y', strtotime($order['created_at'])) ?>
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Fulfilment</dt>
                            <dd><?= fulfil_badge($order['notes'] ?? '') ?></dd>
                        </div>
                        <div class="flex justify-between items-center">
                            <dt class="text-gray-500">Status</dt>
                            <dd><?= order_status_badge($order['status']) ?></dd>
                        </div>
                        <div class="flex justify-between border-t border-gray-100 pt-2">
                            <dt class="font-bold text-gray-800">Total</dt>
                            <dd class="font-extrabold text-orange text-base">
                                KES <?= number_format((float)$order['total_amount'], 2) ?>
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- ── STATUS UPDATE CARD ─────────────────────────────── -->
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h2 class="text-sm font-bold text-gray-700 uppercase
                               tracking-wider mb-4">
                        Update Status
                    </h2>

                    <!-- Status pipeline visual -->
                    <ol class="flex flex-col gap-1 mb-5" aria-label="Order status pipeline">
                        <?php
                        $pipeline = [
                            'pending'    => ['Pending',             'text-yellow-600 bg-yellow-50 border-yellow-200'],
                            'processing' => ['Processing',          'text-blue-600   bg-blue-50   border-blue-200'],
                            'paid'       => ['Paid',                'text-teal-600   bg-teal-50   border-teal-200'],
                            'completed'  => ['Packed / Collected',  'text-green-600  bg-green-50  border-green-200'],
                        ];
                        $statusOrder = array_keys($pipeline);
                        $curIdx      = array_search($order['status'], $statusOrder);
                        foreach ($pipeline as $sVal => [$sLabel, $sStyle]):
                            $idx      = array_search($sVal, $statusOrder);
                            $isDone   = is_int($curIdx) && $idx < $curIdx;
                            $isCur    = $order['status'] === $sVal;
                        ?>
                        <li class="flex items-center gap-2 px-3 py-2 rounded-lg
                                   border text-sm font-medium
                                   <?= $isCur  ? $sStyle
                                       : ($isDone ? 'bg-gray-50 border-gray-200 text-gray-400 line-through'
                                                  : 'bg-white border-gray-200 text-gray-400') ?>">
                            <span class="w-4 h-4 rounded-full border flex items-center
                                         justify-center flex-shrink-0 text-xs
                                         <?= $isDone ? 'border-green-500 bg-green-500 text-white'
                                             : ($isCur ? 'border-current bg-current/20' : 'border-gray-300') ?>"
                                  aria-hidden="true">
                                <?= $isDone ? '✓' : '' ?>
                            </span>
                            <?= $sLabel ?>
                            <?php if ($isCur): ?>
                            <span class="ml-auto text-xs font-bold">Current</span>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ol>

                    <!-- Status select + confirm -->
                    <form method="POST" action="orders.php"
                          id="status-form"
                          data-submit-guard>
                        <input type="hidden" name="action"   value="update_status">
                        <input type="hidden" name="order_id" value="<?= $orderId ?>">

                        <div class="form-group mb-3">
                            <label for="new_status" class="form-label text-xs">
                                Set status to:
                            </label>
                            <select id="new_status" name="new_status"
                                    class="form-select py-2 text-sm">
                                <?php foreach (ORDER_STATUSES as $val => $label): ?>
                                <option value="<?= $val ?>"
                                        <?= $order['status'] === $val ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit"
                                class="btn btn-primary btn-block btn-sm"
                                data-loading-text="Updating…"
                                data-confirm="Update this order's status?">
                            ✓ Update Status
                        </button>
                    </form>
                </div>

            </div><!-- /right -->
        </div><!-- /grid -->
    </div><!-- /main content -->
    </div><!-- /admin flex -->

    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// LIST VIEW
// ══════════════════════════════════════════════════════════════════════════

// Filters
$search        = trim($_GET['q']       ?? '');
$filterStatus  = $_GET['status']       ?? '';
$filterFrom    = $_GET['from']         ?? '';
$filterTo      = $_GET['to']           ?? '';
$perPage       = 20;
$page          = max(1, (int) ($_GET['page'] ?? 1));
$offset        = ($page - 1) * $perPage;

$conditions = [];
$bindings   = [];

if ($search !== '') {
    $conditions[] = '(o.order_number LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)';
    $like = '%' . $search . '%';
    $bindings = array_merge($bindings, [$like, $like, $like]);
}
if ($filterStatus !== '' && array_key_exists($filterStatus, ORDER_STATUSES)) {
    $conditions[] = 'o.status = ?';
    $bindings[]   = $filterStatus;
}
if ($filterFrom !== '') {
    $conditions[] = 'DATE(o.created_at) >= ?';
    $bindings[]   = $filterFrom;
}
if ($filterTo !== '') {
    $conditions[] = 'DATE(o.created_at) <= ?';
    $bindings[]   = $filterTo;
}

$join  = 'LEFT JOIN users u ON u.id = o.customer_id';
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM orders o $join $where"
);
$countStmt->execute($bindings);
$totalOrders = (int) $countStmt->fetchColumn();
$totalPages  = (int) ceil($totalOrders / $perPage);
$page        = min($page, max(1, $totalPages ?: 1));

$ordersStmt = $pdo->prepare(
    "SELECT o.id, o.order_number, o.status, o.total_amount,
            o.notes, o.created_at, o.updated_at,
            u.full_name AS customer_name,
            u.email     AS customer_email,
            COUNT(oi.id)     AS item_count,
            SUM(oi.quantity) AS total_qty
     FROM   orders o
     $join
     LEFT JOIN order_items oi ON oi.order_id = o.id
     $where
     GROUP  BY o.id
     ORDER  BY o.created_at DESC
     LIMIT  ? OFFSET ?"
);
$ordersStmt->execute(array_merge($bindings, [$perPage, $offset]));
$orders = $ordersStmt->fetchAll();

// Quick stat counts for the filter badges
$statsStmt = $pdo->query(
    "SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status"
);
$statusCounts = [];
foreach ($statsStmt->fetchAll() as $r) {
    $statusCounts[$r['status']] = (int)$r['cnt'];
}

$pageTitle = 'Orders';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════
     ADMIN LAYOUT — LIST VIEW
══════════════════════════════════════════════════════════════════════ -->
<div class="flex gap-0 -mx-4 sm:-mx-6 lg:-mx-8 min-h-screen">

    <!-- Sidebar -->
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
            $act = $file === $cur ? 'active' : '';
        ?>
        <a href="<?= $base ?>/admin/<?= $file ?>"
           class="admin-sidebar__link <?= $act ?>"
           <?= $act ? 'aria-current="page"' : '' ?>>
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
        <a href="<?= $base ?>/logout.php" class="admin-sidebar__link">🚪 Sign Out</a>
    </aside>

    <!-- Main -->
    <div class="flex-1 min-w-0 px-4 sm:px-6 lg:px-8 py-6">

        <!-- Page header -->
        <div class="flex flex-col sm:flex-row sm:items-center
                    sm:justify-between gap-3 mb-6">
            <h1 class="text-2xl font-extrabold text-gray-900">
                All Orders
            </h1>
            <p class="text-sm text-gray-500">
                <?= number_format($totalOrders) ?> total order<?= $totalOrders !== 1 ? 's' : '' ?>
            </p>
        </div>

        <!-- Status filter tabs -->
        <div class="flex flex-wrap gap-2 mb-5" role="tablist"
             aria-label="Filter by status">
            <?php
            $tabBase = ['q' => $search, 'from' => $filterFrom, 'to' => $filterTo];
            $tabs    = ['' => 'All'] + ORDER_STATUSES;
            foreach ($tabs as $tVal => $tLabel):
                $isActive = $filterStatus === $tVal;
                $cnt      = $tVal === ''
                    ? array_sum($statusCounts)
                    : ($statusCounts[$tVal] ?? 0);
                $qp = $tabBase;
                if ($tVal !== '') $qp['status'] = $tVal;
            ?>
            <a href="?<?= http_build_query(array_filter($qp)) ?>"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg
                      text-xs font-semibold border transition-colors
                      <?= $isActive
                          ? 'bg-orange border-orange text-white'
                          : 'bg-white border-gray-200 text-gray-600 hover:border-orange hover:text-orange' ?>"
               role="tab"
               aria-selected="<?= $isActive ? 'true' : 'false' ?>">
                <?= $tLabel ?>
                <span class="<?= $isActive ? 'bg-white/25' : 'bg-gray-100' ?>
                              rounded-full px-1.5 py-0.5 text-xs leading-none">
                    <?= $cnt ?>
                </span>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Search + date filter bar -->
        <form method="GET" action="orders.php"
              class="bg-white rounded-xl border border-gray-100 shadow-sm
                     p-4 mb-5 flex flex-wrap gap-3 items-end">
            <?php if ($filterStatus): ?>
            <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
            <?php endif; ?>

            <!-- Search -->
            <div class="flex-1 min-w-48">
                <label for="q" class="form-label text-xs mb-1">Search</label>
                <input type="search" id="q" name="q"
                       value="<?= htmlspecialchars($search) ?>"
                       placeholder="Order #, customer name or email…"
                       class="form-input py-2 text-sm" />
            </div>

            <!-- Date from -->
            <div class="w-36">
                <label for="from" class="form-label text-xs mb-1">From</label>
                <input type="date" id="from" name="from"
                       value="<?= htmlspecialchars($filterFrom) ?>"
                       class="form-input py-2 text-sm" />
            </div>

            <!-- Date to -->
            <div class="w-36">
                <label for="to" class="form-label text-xs mb-1">To</label>
                <input type="date" id="to" name="to"
                       value="<?= htmlspecialchars($filterTo) ?>"
                       class="form-input py-2 text-sm" />
            </div>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <?php if ($search || $filterFrom || $filterTo): ?>
                <a href="<?= $base ?>/admin/orders.php<?= $filterStatus ? '?status=' . urlencode($filterStatus) : '' ?>"
                   class="btn btn-secondary btn-sm">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Orders table -->
        <?php if (empty($orders)): ?>
        <div class="bg-white rounded-2xl border border-dashed border-gray-200
                    py-20 text-center" role="status">
            <div class="text-5xl mb-4" aria-hidden="true">📋</div>
            <p class="text-gray-400 text-sm">No orders match your filters.</p>
        </div>

        <?php else: ?>
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100
                    overflow-hidden">
            <div class="overflow-x-auto">
            <table class="data-table" aria-label="Orders list">
                <thead>
                    <tr>
                        <th scope="col">Order #</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Date</th>
                        <th scope="col" class="text-center">Items</th>
                        <th scope="col" class="text-right">Total</th>
                        <th scope="col" class="text-center">Fulfilment</th>
                        <th scope="col" class="text-center">Status</th>
                        <th scope="col" class="text-center">Quick Update</th>
                        <th scope="col"><span class="sr-only">Detail</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                    <tr class="hover:bg-gray-50/50 transition-colors">

                        <!-- Order number -->
                        <td class="font-mono font-bold text-orange text-sm">
                            <?= htmlspecialchars($order['order_number']) ?>
                        </td>

                        <!-- Customer -->
                        <td>
                            <p class="font-medium text-gray-800 text-sm">
                                <?= $order['customer_name']
                                    ? htmlspecialchars($order['customer_name'])
                                    : '<span class="text-gray-400">Walk-in</span>' ?>
                            </p>
                            <?php if ($order['customer_email']): ?>
                            <p class="text-xs text-gray-400 truncate max-w-[160px]">
                                <?= htmlspecialchars($order['customer_email']) ?>
                            </p>
                            <?php endif; ?>
                        </td>

                        <!-- Date -->
                        <td class="text-sm text-gray-500 whitespace-nowrap">
                            <?= date('d M Y', strtotime($order['created_at'])) ?>
                            <span class="block text-xs text-gray-400">
                                <?= date('H:i', strtotime($order['created_at'])) ?>
                            </span>
                        </td>

                        <!-- Items -->
                        <td class="text-center">
                            <span class="inline-block bg-gray-100 text-gray-700
                                         text-xs font-semibold px-2 py-0.5 rounded-full">
                                <?= (int)$order['item_count'] ?>
                                item<?= (int)$order['item_count'] !== 1 ? 's' : '' ?>
                            </span>
                        </td>

                        <!-- Total -->
                        <td class="text-right font-bold text-orange whitespace-nowrap">
                            KES <?= number_format((float)$order['total_amount'], 2) ?>
                        </td>

                        <!-- Fulfilment -->
                        <td class="text-center">
                            <?= fulfil_badge($order['notes'] ?? '') ?>
                        </td>

                        <!-- Status badge -->
                        <td class="text-center">
                            <?= order_status_badge($order['status']) ?>
                        </td>

                        <!-- Quick status update (inline dropdown) -->
                        <td class="text-center">
                            <form method="POST" action="orders.php"
                                  class="inline-flex items-center gap-1">
                                <input type="hidden" name="action"   value="update_status">
                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                <select name="new_status"
                                        onchange="this.form.submit()"
                                        class="form-select py-1 px-2 text-xs w-36
                                               rounded-lg border-gray-200"
                                        aria-label="Update status for order <?= htmlspecialchars($order['order_number'], ENT_QUOTES) ?>">
                                    <?php foreach (ORDER_STATUSES as $val => $lbl): ?>
                                    <option value="<?= $val ?>"
                                            <?= $order['status'] === $val ? 'selected' : '' ?>>
                                        <?= $lbl ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>

                        <!-- Detail link -->
                        <td class="text-center">
                            <a href="<?= $base ?>/admin/orders.php?view=detail&id=<?= $order['id'] ?>"
                               class="text-xs text-orange font-semibold hover:underline">
                                View →
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div><!-- /overflow-x-auto -->
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1):
            $qp = array_filter([
                'status' => $filterStatus,
                'q'      => $search,
                'from'   => $filterFrom,
                'to'     => $filterTo,
            ]);
        ?>
        <nav class="flex items-center justify-center gap-1 mt-6"
             aria-label="Orders pages">
            <?php
            $range = range(max(1, $page - 2), min($totalPages, $page + 2));
            if ($range[0] > 1): ?>
                <a href="?<?= http_build_query(array_merge($qp, ['page' => 1])) ?>"
                   class="px-3 py-1.5 text-sm rounded-lg border border-gray-200
                          text-gray-600 hover:border-orange hover:text-orange">1</a>
                <?php if ($range[0] > 2): ?>
                <span class="text-gray-400 text-sm px-1">…</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php foreach ($range as $p): ?>
            <a href="?<?= http_build_query(array_merge($qp, ['page' => $p])) ?>"
               class="px-3 py-1.5 text-sm rounded-lg border transition-colors
                      <?= $p === $page
                          ? 'bg-orange border-orange text-white font-bold'
                          : 'border-gray-200 text-gray-600 hover:border-orange hover:text-orange' ?>"
               <?= $p === $page ? 'aria-current="page"' : '' ?>>
                <?= $p ?>
            </a>
            <?php endforeach; ?>

            <?php $lastRange = end($range);
            if ($lastRange < $totalPages): ?>
                <?php if ($lastRange < $totalPages - 1): ?>
                <span class="text-gray-400 text-sm px-1">…</span>
                <?php endif; ?>
                <a href="?<?= http_build_query(array_merge($qp, ['page' => $totalPages])) ?>"
                   class="px-3 py-1.5 text-sm rounded-lg border border-gray-200
                          text-gray-600 hover:border-orange hover:text-orange">
                    <?= $totalPages ?>
                </a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>

        <?php endif; // orders not empty ?>
    </div><!-- /main -->
</div><!-- /admin flex -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
