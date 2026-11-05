<?php
/**
 * customer/dashboard.php
 *
 * Customer account dashboard.
 * Displays:
 *   - Profile card (name, email, phone, member since)
 *   - Order stats summary (total, pending, paid, completed)
 *   - Full order history table with status badges and item counts
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// Must be logged in — any role can view their own dashboard.
// Admins/attendants land on their own pages via role_dashboard_path(),
// but we still allow them here if they navigate directly.
require_login();

$pdo    = db();
$userId = get_user_id();

// ── Fetch full user profile ───────────────────────────────────────────────
$stmtUser = $pdo->prepare(
    'SELECT id, full_name, email, phone, role, is_active, created_at
     FROM users WHERE id = ? LIMIT 1'
);
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch();

if (!$user || (int)$user['is_active'] === 0) {
    logout_user(); // Account deactivated mid-session
}

// ── Order stats ───────────────────────────────────────────────────────────
$stmtStats = $pdo->prepare(
    "SELECT
         COUNT(*)                                                  AS total_orders,
         SUM(total_amount)                                         AS lifetime_spend,
         SUM(status = 'pending'    OR status = 'processing')      AS active_orders,
         SUM(status = 'paid'       OR status = 'completed')       AS completed_orders,
         SUM(status = 'cancelled'  OR status = 'refunded')        AS cancelled_orders
     FROM orders
     WHERE customer_id = ?"
);
$stmtStats->execute([$userId]);
$stats = $stmtStats->fetch();

// ── Order history (paginated) ─────────────────────────────────────────────
$perPage = 10;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$stmtCount = $pdo->prepare(
    'SELECT COUNT(*) FROM orders WHERE customer_id = ?'
);
$stmtCount->execute([$userId]);
$totalOrders = (int) $stmtCount->fetchColumn();
$totalPages  = (int) ceil($totalOrders / $perPage);
$page        = min($page, max(1, $totalPages));

$stmtOrders = $pdo->prepare(
    "SELECT o.id, o.order_number, o.status, o.total_amount,
            o.notes, o.created_at,
            COUNT(oi.id)   AS item_count,
            SUM(oi.quantity) AS total_qty
     FROM   orders o
     LEFT JOIN order_items oi ON oi.order_id = o.id
     WHERE  o.customer_id = ?
     GROUP  BY o.id
     ORDER  BY o.created_at DESC
     LIMIT  ? OFFSET ?"
);
$stmtOrders->execute([$userId, $perPage, $offset]);
$orders = $stmtOrders->fetchAll();

// ── Status badge helper ───────────────────────────────────────────────────
function order_badge(string $status): string
{
    $map = [
        'pending'    => ['status-pending',    'Pending'],
        'processing' => ['status-processing', 'Processing'],
        'paid'       => ['status-paid',       'Paid'],
        'completed'  => ['status-completed',  'Completed'],
        'cancelled'  => ['status-cancelled',  'Cancelled'],
        'refunded'   => ['status-refunded',   'Refunded'],
    ];
    [$class, $label] = $map[$status] ?? ['badge-charcoal', ucfirst($status)];
    return "<span class=\"badge {$class}\">{$label}</span>";
}

$pageTitle = 'My Account';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════
     CUSTOMER DASHBOARD
══════════════════════════════════════════════════════════════════════ -->

<!-- Breadcrumb -->
<nav class="flex items-center gap-2 text-sm text-gray-500 mb-6"
     aria-label="Breadcrumb">
    <a href="<?= $base ?>/index.php"
       class="hover:text-orange transition-colors">Home</a>
    <span aria-hidden="true">/</span>
    <span class="text-gray-800 font-medium" aria-current="page">My Account</span>
</nav>


<!-- ══════════════════════════════════════════════════════════════════════
     TOP ROW: Profile + Stats
══════════════════════════════════════════════════════════════════════ -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

    <!-- ── Profile card ──────────────────────────────────────────────── -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6
                flex flex-col items-center text-center lg:col-span-1">

        <!-- Avatar -->
        <div class="w-20 h-20 rounded-full bg-orange flex items-center
                    justify-center text-white text-3xl font-extrabold
                    select-none mb-4" aria-hidden="true">
            <?= mb_strtoupper(mb_substr($user['full_name'], 0, 1)) ?>
        </div>

        <h1 class="text-lg font-bold text-gray-900">
            <?= htmlspecialchars($user['full_name']) ?>
        </h1>

        <span class="badge badge-orange mt-1 mb-4 capitalize">
            <?= htmlspecialchars($user['role']) ?>
        </span>

        <!-- Detail rows -->
        <ul class="w-full space-y-3 text-sm text-left" role="list">
            <li class="flex items-center gap-3 text-gray-600">
                <svg class="w-4 h-4 text-gray-400 flex-shrink-0"
                     xmlns="http://www.w3.org/2000/svg" fill="none"
                     viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"
                     aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M3 8l7.89 5.26a2 2 0 002.22 0L21
                             8M5 19h14a2 2 0 002-2V7a2 2 0
                             00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                <span class="truncate"><?= htmlspecialchars($user['email']) ?></span>
            </li>
            <li class="flex items-center gap-3 text-gray-600">
                <svg class="w-4 h-4 text-gray-400 flex-shrink-0"
                     xmlns="http://www.w3.org/2000/svg" fill="none"
                     viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"
                     aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M3 5a2 2 0 012-2h3.28a1 1 0
                             01.948.684l1.498 4.493a1 1 0
                             01-.502 1.21l-2.257 1.13a11.042
                             11.042 0 005.516 5.516l1.13-2.257a1
                             1 0 011.21-.502l4.493 1.498a1 1 0
                             01.684.949V19a2 2 0 01-2 2h-1C9.716
                             21 3 14.284 3 6V5z"/>
                </svg>
                <?php
                // Format phone for display: 254712345678 → +254 712 345 678
                $ph = $user['phone'];
                $displayPhone = (strlen($ph) === 12 && str_starts_with($ph, '254'))
                    ? '+' . substr($ph, 0, 3) . ' ' . substr($ph, 3, 3)
                      . ' ' . substr($ph, 6, 3) . ' ' . substr($ph, 9)
                    : $ph;
                ?>
                <span><?= htmlspecialchars($displayPhone) ?></span>
            </li>
            <li class="flex items-center gap-3 text-gray-600">
                <svg class="w-4 h-4 text-gray-400 flex-shrink-0"
                     xmlns="http://www.w3.org/2000/svg" fill="none"
                     viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"
                     aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2
                             0 002-2V7a2 2 0 00-2-2H5a2 2 0
                             00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <span>Member since
                    <?= date('F Y', strtotime($user['created_at'])) ?>
                </span>
            </li>
        </ul>

        <!-- Actions -->
        <div class="w-full mt-6 space-y-2">
            <a href="<?= $base ?>/shop.php"
               class="btn btn-primary btn-block btn-sm">
                Continue Shopping
            </a>
            <a href="<?= $base ?>/logout.php"
               class="btn btn-ghost btn-block btn-sm">
                Sign Out
            </a>
        </div>
    </div>


    <!-- ── Stats grid ─────────────────────────────────────────────────── -->
    <div class="lg:col-span-2 grid grid-cols-2 sm:grid-cols-2 gap-4 content-start">

        <?php
        $statCards = [
            [
                'value' => (int)($stats['total_orders'] ?? 0),
                'label' => 'Total Orders',
                'icon'  => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0
                            002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0
                            002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2
                            2 0 012 2',
                'color' => 'border-l-orange',
                'fmt'   => false,
            ],
            [
                'value' => 'KES ' . number_format((float)($stats['lifetime_spend'] ?? 0), 2),
                'label' => 'Total Spent',
                'icon'  => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3
                            .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402
                            2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11
                            0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0
                            9 9 0 0118 0z',
                'color' => 'border-l-green-500',
                'fmt'   => true,
            ],
            [
                'value' => (int)($stats['active_orders'] ?? 0),
                'label' => 'Active Orders',
                'icon'  => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
                'color' => 'border-l-blue-500',
                'fmt'   => false,
            ],
            [
                'value' => (int)($stats['completed_orders'] ?? 0),
                'label' => 'Completed',
                'icon'  => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
                'color' => 'border-l-emerald-500',
                'fmt'   => false,
            ],
        ];
        foreach ($statCards as $card): ?>
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100
                    border-l-4 <?= $card['color'] ?> p-5 flex items-center gap-4">
            <div class="w-11 h-11 rounded-xl bg-gray-50 flex items-center
                        justify-center flex-shrink-0" aria-hidden="true">
                <svg class="w-5 h-5 text-gray-500" xmlns="http://www.w3.org/2000/svg"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor"
                     stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="<?= $card['icon'] ?>"/>
                </svg>
            </div>
            <div>
                <p class="text-xl font-extrabold text-gray-900 leading-none">
                    <?= is_string($card['value']) ? $card['value'] : number_format($card['value']) ?>
                </p>
                <p class="text-xs text-gray-500 mt-0.5"><?= $card['label'] ?></p>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Shop CTA card -->
        <div class="col-span-2 bg-gradient-to-r from-gray-900 to-charcoal
                    rounded-2xl p-5 flex flex-col sm:flex-row
                    sm:items-center justify-between gap-4">
            <div>
                <p class="text-white font-bold text-sm">
                    Need something new?
                </p>
                <p class="text-gray-400 text-xs mt-0.5">
                    Browse 500+ quality hardware products.
                </p>
            </div>
            <a href="<?= $base ?>/shop.php"
               class="btn btn-primary btn-sm flex-shrink-0">
                Shop Now →
            </a>
        </div>

    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════════════
     ORDER HISTORY TABLE
══════════════════════════════════════════════════════════════════════ -->
<section aria-labelledby="orders-heading">
    <div class="flex items-center justify-between mb-4">
        <h2 id="orders-heading" class="section-heading text-lg">
            Order History
        </h2>
        <?php if ($totalOrders > 0): ?>
        <span class="text-xs text-gray-400">
            <?= number_format($totalOrders) ?> order<?= $totalOrders !== 1 ? 's' : '' ?>
        </span>
        <?php endif; ?>
    </div>

    <?php if (empty($orders)): ?>
    <!-- Empty state -->
    <div class="bg-white rounded-2xl border border-dashed border-gray-200
                py-20 text-center" role="status">
        <div class="text-6xl mb-4" aria-hidden="true">📦</div>
        <h3 class="text-base font-semibold text-gray-700 mb-2">
            No orders yet
        </h3>
        <p class="text-gray-400 text-sm mb-6">
            Your completed orders will appear here.
        </p>
        <a href="<?= $base ?>/shop.php" class="btn btn-primary">
            Start Shopping
        </a>
    </div>

    <?php else: ?>

    <!-- Desktop table -->
    <div class="hidden sm:block bg-white rounded-2xl shadow-sm
                border border-gray-100 overflow-hidden">
        <table class="data-table" aria-label="Order history">
            <thead>
                <tr>
                    <th scope="col">Order #</th>
                    <th scope="col">Date</th>
                    <th scope="col">Items</th>
                    <th scope="col">Total</th>
                    <th scope="col">Fulfilment</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order):
                    // Extract fulfilment note from the notes field
                    $noteText   = $order['notes'] ?? '';
                    $fulfil     = 'delivery'; // default
                    if (str_contains(strtolower($noteText), '[pickup]')) {
                        $fulfil = 'pickup';
                    }
                    $fulfilLabel = $fulfil === 'pickup' ? '🏪 Pickup' : '🚚 Delivery';
                ?>
                <tr>
                    <!-- Order number -->
                    <td>
                        <span class="font-mono text-sm font-semibold text-gray-800">
                            <?= htmlspecialchars($order['order_number']) ?>
                        </span>
                    </td>

                    <!-- Date -->
                    <td class="text-gray-500 whitespace-nowrap">
                        <?= date('d M Y', strtotime($order['created_at'])) ?>
                        <span class="block text-xs text-gray-400">
                            <?= date('H:i', strtotime($order['created_at'])) ?>
                        </span>
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
                    <td class="text-gray-600 text-sm whitespace-nowrap">
                        <?= $fulfilLabel ?>
                    </td>

                    <!-- Status badge -->
                    <td><?= order_badge($order['status']) ?></td>

                    <!-- Actions -->
                    <td>
                        <button type="button"
                                class="text-xs text-orange hover:text-orange-dark
                                       font-medium transition-colors"
                                onclick="toggleOrderDetail(<?= $order['id'] ?>)"
                                aria-expanded="false"
                                aria-controls="order-detail-<?= $order['id'] ?>"
                                id="toggle-<?= $order['id'] ?>">
                            View
                        </button>
                    </td>
                </tr>

                <!-- Expandable detail row -->
                <tr id="order-detail-<?= $order['id'] ?>"
                    class="hidden bg-gray-50/70"
                    aria-label="Order <?= htmlspecialchars($order['order_number']) ?> details">
                    <td colspan="7" class="px-5 py-4">
                        <?php
                        // Fetch line items for this order
                        $stmtItems = $pdo->prepare(
                            'SELECT product_name, unit_price, quantity, subtotal
                             FROM order_items WHERE order_id = ? ORDER BY id ASC'
                        );
                        $stmtItems->execute([$order['id']]);
                        $lineItems = $stmtItems->fetchAll();
                        ?>
                        <div class="text-sm">
                            <p class="font-semibold text-gray-700 mb-2">
                                Items in this order:
                            </p>
                            <ul class="space-y-1" role="list">
                                <?php foreach ($lineItems as $li): ?>
                                <li class="flex items-center justify-between
                                           text-gray-600 py-1 border-b
                                           border-gray-100 last:border-0">
                                    <span>
                                        <?= htmlspecialchars($li['product_name']) ?>
                                        <span class="text-gray-400">
                                            × <?= (int)$li['quantity'] ?>
                                        </span>
                                    </span>
                                    <span class="font-medium text-gray-800">
                                        KES <?= number_format((float)$li['subtotal'], 2) ?>
                                    </span>
                                </li>
                                <?php endforeach; ?>
                            </ul>

                            <?php
                            $cleanNote = trim(preg_replace('/^\[(pickup|delivery)\]\s*/i', '', $noteText));
                            if ($cleanNote !== ''):
                            ?>
                            <p class="mt-3 text-xs text-gray-500">
                                <span class="font-semibold">Note:</span>
                                <?= htmlspecialchars($cleanNote) ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile card stack -->
    <div class="sm:hidden space-y-4">
        <?php foreach ($orders as $order):
            $noteText  = $order['notes'] ?? '';
            $fulfil    = str_contains(strtolower($noteText), '[pickup]') ? 'pickup' : 'delivery';
            $fulfilLabel = $fulfil === 'pickup' ? '🏪 Pickup' : '🚚 Delivery';
        ?>
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
            <div class="flex items-start justify-between mb-3">
                <div>
                    <p class="font-mono text-sm font-bold text-gray-800">
                        <?= htmlspecialchars($order['order_number']) ?>
                    </p>
                    <p class="text-xs text-gray-400 mt-0.5">
                        <?= date('d M Y, H:i', strtotime($order['created_at'])) ?>
                    </p>
                </div>
                <?= order_badge($order['status']) ?>
            </div>

            <div class="flex items-center justify-between text-sm mb-3">
                <span class="text-gray-500"><?= $fulfilLabel ?></span>
                <span class="font-bold text-orange">
                    KES <?= number_format((float)$order['total_amount'], 2) ?>
                </span>
            </div>

            <p class="text-xs text-gray-400">
                <?= (int)$order['item_count'] ?> item<?= (int)$order['item_count'] !== 1 ? 's' : '' ?>
                · <?= (int)$order['total_qty'] ?> unit<?= (int)$order['total_qty'] !== 1 ? 's' : '' ?>
            </p>
        </div>
        <?php endforeach; ?>
    </div>


    <!-- ── Pagination ────────────────────────────────────────────────── -->
    <?php if ($totalPages > 1): ?>
    <nav class="flex items-center justify-center gap-1 mt-8"
         aria-label="Order history pages">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <a href="?page=<?= $p ?>"
           class="px-3 py-2 text-sm rounded-lg border transition-colors
                  <?= $p === $page
                      ? 'bg-orange border-orange text-white font-semibold'
                      : 'border-gray-200 text-gray-600 hover:border-orange hover:text-orange' ?>"
           <?= $p === $page ? 'aria-current="page"' : '' ?>>
            <?= $p ?>
        </a>
        <?php endfor; ?>
    </nav>
    <?php endif; ?>

    <?php endif; // orders not empty ?>
</section>


<!-- Page-specific JS: expandable order detail rows ──────────────────── -->
<script>
function toggleOrderDetail(orderId) {
    var row    = document.getElementById('order-detail-' + orderId);
    var toggle = document.getElementById('toggle-' + orderId);
    if (!row || !toggle) return;

    var isHidden = row.classList.contains('hidden');
    row.classList.toggle('hidden', !isHidden);
    toggle.textContent    = isHidden ? 'Hide' : 'View';
    toggle.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
