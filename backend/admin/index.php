<?php
/**
 * admin/index.php
 *
 * Admin dashboard — summary KPIs, recent orders, low stock alerts,
 * user stats, and a 7-day revenue sparkline.
 *
 * Protected by require_admin() — admin role only.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_admin();

$pdo = db();

// ══════════════════════════════════════════════════════════════════════════
// 1. REVENUE & ORDER KPIs
// ══════════════════════════════════════════════════════════════════════════
$kpiStmt = $pdo->query(
    "SELECT
        /* All-time */
        SUM(CASE WHEN status IN ('paid','completed') THEN total_amount ELSE 0 END)
            AS total_revenue,
        COUNT(*)
            AS total_orders,
        SUM(status IN ('paid','completed'))
            AS completed_orders,
        SUM(status = 'pending' OR status = 'processing')
            AS active_orders,
        SUM(status = 'cancelled' OR status = 'refunded')
            AS cancelled_orders,

        /* Today */
        SUM(CASE WHEN DATE(created_at) = CURDATE()
                 THEN 1 ELSE 0 END)
            AS orders_today,
        SUM(CASE WHEN DATE(created_at) = CURDATE()
                  AND status IN ('paid','completed')
                 THEN total_amount ELSE 0 END)
            AS revenue_today,

        /* This month */
        SUM(CASE WHEN YEAR(created_at)  = YEAR(NOW())
                  AND MONTH(created_at) = MONTH(NOW())
                  AND status IN ('paid','completed')
                 THEN total_amount ELSE 0 END)
            AS revenue_month
     FROM orders"
);
$kpi = $kpiStmt->fetch();

// ══════════════════════════════════════════════════════════════════════════
// 2. PRODUCT STATS
// ══════════════════════════════════════════════════════════════════════════
$productStmt = $pdo->query(
    "SELECT
        COUNT(*)                                     AS total_products,
        SUM(is_active = 1)                           AS active_products,
        SUM(stock_qty = 0)                           AS out_of_stock,
        SUM(stock_qty > 0 AND stock_qty <= low_stock_threshold)
                                                     AS low_stock_count
     FROM products"
);
$productStats = $productStmt->fetch();

// ══════════════════════════════════════════════════════════════════════════
// 3. USER STATS
// ══════════════════════════════════════════════════════════════════════════
$userStmt = $pdo->query(
    "SELECT
        COUNT(*)                         AS total_users,
        SUM(role = 'customer')           AS customers,
        SUM(role = 'attendant')          AS attendants,
        SUM(role = 'admin')              AS admins,
        SUM(DATE(created_at) = CURDATE()) AS new_today
     FROM users
     WHERE is_active = 1"
);
$userStats = $userStmt->fetch();

// ══════════════════════════════════════════════════════════════════════════
// 4. 7-DAY DAILY REVENUE (sparkline data)
// ══════════════════════════════════════════════════════════════════════════
$revenueStmt = $pdo->query(
    "SELECT
        DATE(created_at)                                        AS day,
        SUM(CASE WHEN status IN ('paid','completed')
                 THEN total_amount ELSE 0 END)                  AS revenue,
        COUNT(*)                                                AS orders
     FROM orders
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(created_at)
     ORDER BY day ASC"
);
$revenueRows = $revenueStmt->fetchAll();

// Fill missing days with 0
$revenueDays = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $revenueDays[$day] = ['revenue' => 0, 'orders' => 0];
}
foreach ($revenueRows as $row) {
    $revenueDays[$row['day']] = [
        'revenue' => (float) $row['revenue'],
        'orders'  => (int)   $row['orders'],
    ];
}

// ══════════════════════════════════════════════════════════════════════════
// 5. LOW STOCK PRODUCTS
// ══════════════════════════════════════════════════════════════════════════
$lowStockStmt = $pdo->query(
    "SELECT p.id, p.name, p.sku, p.stock_qty, p.low_stock_threshold,
            c.name AS category_name
     FROM   products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE  p.is_active = 1
       AND  p.stock_qty <= p.low_stock_threshold
     ORDER  BY p.stock_qty ASC
     LIMIT  10"
);
$lowStockProducts = $lowStockStmt->fetchAll();

// ══════════════════════════════════════════════════════════════════════════
// 6. RECENT ORDERS (last 10)
// ══════════════════════════════════════════════════════════════════════════
$recentStmt = $pdo->query(
    "SELECT o.id, o.order_number, o.status, o.total_amount, o.created_at,
            u.full_name AS customer_name
     FROM   orders o
     LEFT JOIN users u ON u.id = o.customer_id
     ORDER  BY o.created_at DESC
     LIMIT  10"
);
$recentOrders = $recentStmt->fetchAll();

// ── Order status badge helper ─────────────────────────────────────────────
function admin_badge(string $status): string
{
    $map = [
        'pending'    => ['status-pending',    'Pending'],
        'processing' => ['status-processing', 'Processing'],
        'paid'       => ['status-paid',       'Paid'],
        'completed'  => ['status-completed',  'Completed'],
        'cancelled'  => ['status-cancelled',  'Cancelled'],
        'refunded'   => ['status-refunded',   'Refunded'],
    ];
    [$cls, $lbl] = $map[$status] ?? ['badge-charcoal', ucfirst($status)];
    return "<span class=\"badge {$cls}\">{$lbl}</span>";
}

$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════
     ADMIN LAYOUT WRAPPER
══════════════════════════════════════════════════════════════════════ -->
<div class="flex gap-0 -mx-4 sm:-mx-6 lg:-mx-8 min-h-screen">

    <!-- ── SIDEBAR ────────────────────────────────────────────────────── -->
    <aside class="admin-sidebar hidden lg:flex flex-col w-56 xl:w-60
                  sticky top-16 self-start h-[calc(100vh-4rem)] overflow-y-auto"
           aria-label="Admin navigation">

        <div class="admin-sidebar__brand">
            <svg class="w-5 h-5 text-yellow-400 flex-shrink-0"
                 xmlns="http://www.w3.org/2000/svg" fill="none"
                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"
                 aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M14.121 14.121L19 19m-7-7 7-7-3-3-7
                         7M9.172 9.172 4.343 4.343 1.515 7.172
                         5.172 10.828l4-4z"/>
            </svg>
            Admin Panel
        </div>

        <nav class="admin-sidebar__nav" aria-label="Admin sections">
            <p class="admin-sidebar__section-label">Overview</p>

            <?php
            $navLinks = [
                ['Dashboard',       'index.php',           'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                ['Products',        'products.php',        'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10'],
                ['Orders',          'orders.php',          'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
                ['Users',           'users.php',           'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
                ['Payments',        'payments_record.php', 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z'],
            ];
            $currentFile = basename($_SERVER['PHP_SELF']);
            foreach ($navLinks as [$label, $file, $icon]):
                $active = $file === $currentFile ? 'active' : '';
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
                <?= $label ?>
            </a>
            <?php endforeach; ?>

            <div class="border-t border-gray-700/50 my-3 mx-3"></div>
            <p class="admin-sidebar__section-label">Store</p>

            <a href="<?= $base ?>/shop.php"  class="admin-sidebar__link">
                <svg class="w-4 h-4 flex-shrink-0" xmlns="http://www.w3.org/2000/svg"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor"
                     stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                </svg>
                View Shop
            </a>
            <a href="<?= $base ?>/logout.php" class="admin-sidebar__link">
                <svg class="w-4 h-4 flex-shrink-0" xmlns="http://www.w3.org/2000/svg"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor"
                     stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
                Sign Out
            </a>
        </nav>
    </aside>

    <!-- ── MAIN CONTENT ───────────────────────────────────────────────── -->
    <div class="flex-1 min-w-0 px-4 sm:px-6 lg:px-8 py-6">

        <!-- Page header -->
        <div class="flex flex-col sm:flex-row sm:items-center
                    sm:justify-between gap-3 mb-8">
            <div>
                <h1 class="text-2xl font-extrabold text-gray-900">
                    Dashboard
                </h1>
                <p class="text-sm text-gray-500 mt-0.5">
                    <?= date('l, d F Y') ?> ·
                    Welcome back,
                    <span class="font-semibold text-orange">
                        <?= htmlspecialchars(explode(' ', get_user_name() ?? 'Admin')[0]) ?>
                    </span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="<?= $base ?>/admin/products.php?action=add"
                   class="btn btn-primary btn-sm">
                    + Add Product
                </a>
                <a href="<?= $base ?>/admin/orders.php"
                   class="btn btn-secondary btn-sm">
                    View Orders
                </a>
            </div>
        </div>


        <!-- ══════════════════════════════════════════════════════════════
             SUMMARY KPI CARDS
        ══════════════════════════════════════════════════════════════ -->
        <div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">

            <!-- Total Revenue -->
            <div class="stat-card col-span-2 sm:col-span-1">
                <div class="stat-card__icon">
                    <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor"
                         stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 8c-1.657 0-3 .895-3 2s1.343
                                 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11
                                 0 2.08.402 2.599 1M12 8V7m0 1v8m0
                                 0v1m0-1c-1.11 0-2.08-.402-2.599-1
                                 M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <p class="stat-card__value text-2xl">
                        <?= 'KES ' . number_format((float)($kpi['total_revenue'] ?? 0), 0) ?>
                    </p>
                    <p class="stat-card__label">Total Revenue</p>
                    <p class="text-xs text-green-600 font-medium mt-1">
                        +<?= 'KES ' . number_format((float)($kpi['revenue_today'] ?? 0), 0) ?>
                        today
                    </p>
                </div>
            </div>

            <!-- Total Orders -->
            <div class="stat-card">
                <div class="stat-card__icon">
                    <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor"
                         stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002
                                 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9
                                 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2
                                 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                </div>
                <div>
                    <p class="stat-card__value">
                        <?= number_format((int)($kpi['total_orders'] ?? 0)) ?>
                    </p>
                    <p class="stat-card__label">Total Orders</p>
                    <p class="text-xs text-blue-600 font-medium mt-1">
                        <?= (int)($kpi['orders_today'] ?? 0) ?> today
                    </p>
                </div>
            </div>

            <!-- Active Orders -->
            <div class="stat-card" style="border-left-color: #3B82F6;">
                <div class="stat-card__icon" style="background:#EFF6FF; color:#3B82F6;">
                    <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor"
                         stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 8v4l3 3m6-3a9 9 0 11-18 0
                                 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <p class="stat-card__value">
                        <?= number_format((int)($kpi['active_orders'] ?? 0)) ?>
                    </p>
                    <p class="stat-card__label">Active Orders</p>
                    <p class="text-xs text-gray-400 font-medium mt-1">
                        Pending / Processing
                    </p>
                </div>
            </div>

            <!-- Low Stock Alerts -->
            <div class="stat-card"
                 style="border-left-color: #EF4444;">
                <div class="stat-card__icon"
                     style="background:#FEF2F2; color:#EF4444;">
                    <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor"
                         stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 9v2m0 4h.01m-6.938 4h13.856
                                 c1.54 0 2.502-1.667 1.732-3L13.732
                                 4c-.77-1.333-2.694-1.333-3.464 0L3.34
                                 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <div>
                    <p class="stat-card__value text-red-600">
                        <?= number_format((int)($productStats['low_stock_count'] ?? 0) +
                                          (int)($productStats['out_of_stock'] ?? 0)) ?>
                    </p>
                    <p class="stat-card__label">Stock Alerts</p>
                    <p class="text-xs text-red-500 font-medium mt-1">
                        <?= (int)($productStats['out_of_stock'] ?? 0) ?> out of stock
                    </p>
                </div>
            </div>

        </div>


        <!-- ══════════════════════════════════════════════════════════════
             SECONDARY STAT ROW
        ══════════════════════════════════════════════════════════════ -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
            <?php
            $secondary = [
                [
                    'label' => 'This Month',
                    'value' => 'KES ' . number_format((float)($kpi['revenue_month'] ?? 0), 0),
                    'icon'  => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
                    'color' => 'text-purple-600 bg-purple-50',
                ],
                [
                    'label' => 'Total Customers',
                    'value' => number_format((int)($userStats['customers'] ?? 0)),
                    'icon'  => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
                    'color' => 'text-blue-600 bg-blue-50',
                ],
                [
                    'label' => 'Active Products',
                    'value' => number_format((int)($productStats['active_products'] ?? 0)),
                    'icon'  => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10',
                    'color' => 'text-green-600 bg-green-50',
                ],
                [
                    'label' => 'Completed Orders',
                    'value' => number_format((int)($kpi['completed_orders'] ?? 0)),
                    'icon'  => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
                    'color' => 'text-emerald-600 bg-emerald-50',
                ],
            ];
            foreach ($secondary as $s): ?>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm
                        p-4 flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg <?= $s['color'] ?>
                            flex items-center justify-center flex-shrink-0"
                     aria-hidden="true">
                    <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor"
                         stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="<?= $s['icon'] ?>"/>
                    </svg>
                </div>
                <div>
                    <p class="text-lg font-extrabold text-gray-900 leading-none">
                        <?= $s['value'] ?>
                    </p>
                    <p class="text-xs text-gray-500 mt-0.5"><?= $s['label'] ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>


        <!-- ══════════════════════════════════════════════════════════════
             7-DAY REVENUE CHART + RECENT ORDERS (side by side)
        ══════════════════════════════════════════════════════════════ -->
        <div class="grid grid-cols-1 xl:grid-cols-5 gap-6 mb-8">

            <!-- Revenue chart (3/5 width on xl) -->
            <div class="xl:col-span-3 bg-white rounded-2xl shadow-sm
                        border border-gray-100 p-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-base font-bold text-gray-900">
                        7-Day Revenue
                    </h2>
                    <span class="text-xs text-gray-400">
                        <?= date('d M', strtotime('-6 days')) ?>
                        – <?= date('d M Y') ?>
                    </span>
                </div>

                <!-- Simple CSS bar chart -->
                <?php
                $maxRevenue = max(array_column(array_values($revenueDays), 'revenue')) ?: 1;
                ?>
                <div class="flex items-end gap-2 h-36" role="img"
                     aria-label="7-day revenue bar chart">
                    <?php foreach ($revenueDays as $day => $data):
                        $height = $maxRevenue > 0
                            ? max(4, round(($data['revenue'] / $maxRevenue) * 100))
                            : 4;
                        $isToday = $day === date('Y-m-d');
                        $label   = date('D', strtotime($day));
                    ?>
                    <div class="flex-1 flex flex-col items-center gap-1 group">
                        <span class="text-xs text-gray-500 font-semibold opacity-0
                                     group-hover:opacity-100 transition-opacity
                                     whitespace-nowrap">
                            <?= number_format($data['revenue'], 0) ?>
                        </span>
                        <div class="w-full rounded-t-md transition-all duration-300
                                    <?= $isToday ? 'bg-orange' : 'bg-gray-200 group-hover:bg-orange/60' ?>"
                             style="height: <?= $height ?>%"
                             title="<?= $label ?>: KES <?= number_format($data['revenue'], 2) ?>">
                        </div>
                        <span class="text-xs <?= $isToday ? 'text-orange font-bold' : 'text-gray-400' ?>">
                            <?= $label ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Totals row -->
                <div class="flex items-center justify-between mt-4 pt-4
                            border-t border-gray-100 text-sm">
                    <div>
                        <p class="text-gray-500 text-xs">7-day revenue</p>
                        <p class="font-bold text-gray-800">
                            KES <?= number_format(array_sum(array_column($revenueDays, 'revenue')), 0) ?>
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-gray-500 text-xs">7-day orders</p>
                        <p class="font-bold text-gray-800">
                            <?= number_format(array_sum(array_column($revenueDays, 'orders'))) ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Recent orders (2/5 width on xl) -->
            <div class="xl:col-span-2 bg-white rounded-2xl shadow-sm
                        border border-gray-100 overflow-hidden flex flex-col">
                <div class="flex items-center justify-between px-5 py-4
                            border-b border-gray-100">
                    <h2 class="text-base font-bold text-gray-900">
                        Recent Orders
                    </h2>
                    <a href="<?= $base ?>/admin/orders.php"
                       class="text-xs text-orange font-semibold hover:underline">
                        View all →
                    </a>
                </div>

                <ul class="divide-y divide-gray-50 flex-1 overflow-y-auto"
                    role="list">
                    <?php if (empty($recentOrders)): ?>
                    <li class="px-5 py-8 text-center text-gray-400 text-sm">
                        No orders yet.
                    </li>
                    <?php else: foreach ($recentOrders as $o): ?>
                    <li class="flex items-center gap-3 px-5 py-3
                                hover:bg-gray-50/60 transition-colors">
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-mono font-semibold
                                       text-gray-800 truncate">
                                <?= htmlspecialchars($o['order_number']) ?>
                            </p>
                            <p class="text-xs text-gray-400 truncate">
                                <?= $o['customer_name']
                                    ? htmlspecialchars($o['customer_name'])
                                    : 'Walk-in' ?>
                            </p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <?= admin_badge($o['status']) ?>
                            <p class="text-xs text-gray-400 mt-0.5">
                                KES <?= number_format((float)$o['total_amount'], 0) ?>
                            </p>
                        </div>
                    </li>
                    <?php endforeach; endif; ?>
                </ul>
            </div>
        </div>


        <!-- ══════════════════════════════════════════════════════════════
             LOW STOCK ALERTS
        ══════════════════════════════════════════════════════════════ -->
        <?php if (!empty($lowStockProducts)): ?>
        <section class="mb-8" aria-labelledby="low-stock-heading">
            <div class="flex items-center justify-between mb-4">
                <h2 id="low-stock-heading"
                    class="section-heading text-base flex items-center gap-2">
                    Low Stock Alerts
                    <span class="inline-flex items-center justify-center
                                 w-5 h-5 rounded-full bg-red-500 text-white
                                 text-xs font-bold" aria-hidden="true">
                        <?= min(count($lowStockProducts), 9) ?><?= count($lowStockProducts) >= 10 ? '+' : '' ?>
                    </span>
                </h2>
                <a href="<?= $base ?>/admin/products.php"
                   class="text-xs text-orange font-semibold hover:underline">
                    Manage Products →
                </a>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <table class="data-table" aria-label="Low stock products">
                    <thead>
                        <tr>
                            <th scope="col">Product</th>
                            <th scope="col">Category</th>
                            <th scope="col">SKU</th>
                            <th scope="col" class="text-center">In Stock</th>
                            <th scope="col" class="text-center">Threshold</th>
                            <th scope="col" class="text-center">Status</th>
                            <th scope="col"><span class="sr-only">Action</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lowStockProducts as $p):
                            $isOut = (int)$p['stock_qty'] === 0;
                        ?>
                        <tr>
                            <td class="font-medium text-gray-800">
                                <?= htmlspecialchars($p['name']) ?>
                            </td>
                            <td class="text-gray-500 text-sm">
                                <?= htmlspecialchars($p['category_name'] ?? '—') ?>
                            </td>
                            <td class="font-mono text-xs text-gray-400">
                                <?= htmlspecialchars($p['sku'] ?? '—') ?>
                            </td>
                            <td class="text-center">
                                <span class="font-bold text-lg
                                             <?= $isOut ? 'text-red-600' : 'text-amber-600' ?>">
                                    <?= (int)$p['stock_qty'] ?>
                                </span>
                            </td>
                            <td class="text-center text-gray-400 text-sm">
                                <?= (int)$p['low_stock_threshold'] ?>
                            </td>
                            <td class="text-center">
                                <?php if ($isOut): ?>
                                    <span class="badge badge-error">Out of Stock</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Low Stock</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?= $base ?>/admin/products.php?edit=<?= $p['id'] ?>"
                                   class="text-xs text-orange font-semibold
                                          hover:underline whitespace-nowrap">
                                    Restock →
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>


        <!-- ══════════════════════════════════════════════════════════════
             QUICK LINKS ROW
        ══════════════════════════════════════════════════════════════ -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <?php
            $quickLinks = [
                ['Add Product',   '/admin/products.php?action=add',  'bg-orange      text-white', 'M12 4v16m8-8H4'],
                ['All Orders',    '/admin/orders.php',               'bg-blue-600    text-white', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
                ['Manage Users',  '/admin/users.php',                'bg-purple-600  text-white', 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
                ['Payment Logs',  '/admin/payments_record.php',     'bg-green-600   text-white', 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z'],
            ];
            foreach ($quickLinks as [$label, $href, $bg, $icon]): ?>
            <a href="<?= $base . $href ?>"
               class="flex items-center gap-3 p-4 rounded-xl <?= $bg ?>
                      shadow-sm hover:opacity-90 transition-opacity font-semibold
                      text-sm focus:outline-none focus:ring-2 focus:ring-offset-2
                      focus:ring-orange">
                <svg class="w-5 h-5 flex-shrink-0 opacity-90"
                     xmlns="http://www.w3.org/2000/svg" fill="none"
                     viewBox="0 0 24 24" stroke="currentColor"
                     stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="<?= $icon ?>"/>
                </svg>
                <?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>

    </div><!-- /main content -->
</div><!-- /admin layout flex -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
