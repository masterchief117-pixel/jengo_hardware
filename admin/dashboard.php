<?php
/**
 * admin/dashboard.php
 *
 * Owner-facing sales, profit, and inventory analytics.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin_login();

$pdo = db();

$summaryStmt = $pdo->query(
    "SELECT
        (SELECT COALESCE(SUM(total_amount), 0)
         FROM orders
         WHERE status = 'completed') AS total_revenue,
        (SELECT COALESCE(SUM(oi.quantity * COALESCE(p.cost_price, 0)), 0)
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         LEFT JOIN products p ON p.id = oi.product_id
         WHERE o.status = 'completed') AS total_cogs,
        (SELECT COALESCE(SUM(oi.quantity), 0)
         FROM order_items oi
         INNER JOIN orders o ON o.id = oi.order_id
         WHERE o.status = 'completed') AS total_units_sold"
);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$totalRevenue = (float) ($summary['total_revenue'] ?? 0);
$totalCogs = (float) ($summary['total_cogs'] ?? 0);
$netProfit = $totalRevenue - $totalCogs;
$totalUnitsSold = (int) ($summary['total_units_sold'] ?? 0);

$salesTrendStmt = $pdo->query(
    "SELECT DATE(created_at) AS sale_date,
            SUM(total_amount) AS revenue
     FROM orders
     WHERE status = 'completed'
       AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
     GROUP BY DATE(created_at)
     ORDER BY sale_date ASC"
);
$salesTrendRows = $salesTrendStmt->fetchAll(PDO::FETCH_ASSOC);

$salesByDay = [];
for ($daysAgo = 29; $daysAgo >= 0; $daysAgo--) {
    $day = date('Y-m-d', strtotime("-{$daysAgo} days"));
    $salesByDay[$day] = 0.0;
}
foreach ($salesTrendRows as $row) {
    $salesByDay[(string) $row['sale_date']] = (float) $row['revenue'];
}

$monthlyRevenueStmt = $pdo->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_key,
            SUM(total_amount) AS revenue
     FROM orders
     WHERE status = 'completed'
       AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
     GROUP BY DATE_FORMAT(created_at, '%Y-%m')"
);
$monthlyRevenueRows = $monthlyRevenueStmt->fetchAll(PDO::FETCH_ASSOC);
$monthlyRevenue = [];
foreach ($monthlyRevenueRows as $row) {
    $monthlyRevenue[(string) $row['month_key']] = (float) $row['revenue'];
}

$monthlyCogsStmt = $pdo->query(
    "SELECT DATE_FORMAT(o.created_at, '%Y-%m') AS month_key,
            SUM(oi.quantity * COALESCE(p.cost_price, 0)) AS cogs
     FROM order_items oi
     INNER JOIN orders o ON o.id = oi.order_id
     LEFT JOIN products p ON p.id = oi.product_id
     WHERE o.status = 'completed'
       AND o.created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
     GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')"
);
$monthlyCogsRows = $monthlyCogsStmt->fetchAll(PDO::FETCH_ASSOC);
$monthlyCogs = [];
foreach ($monthlyCogsRows as $row) {
    $monthlyCogs[(string) $row['month_key']] = (float) $row['cogs'];
}

$monthlyLabels = [];
$monthlyRevenueValues = [];
$monthlyCogsValues = [];
$monthlyProfitValues = [];
for ($monthsAgo = 11; $monthsAgo >= 0; $monthsAgo--) {
    $monthKey = date('Y-m', strtotime("first day of -{$monthsAgo} months"));
    $revenue = $monthlyRevenue[$monthKey] ?? 0.0;
    $cogs = $monthlyCogs[$monthKey] ?? 0.0;

    $monthlyLabels[] = date('M Y', strtotime($monthKey . '-01'));
    $monthlyRevenueValues[] = $revenue;
    $monthlyCogsValues[] = $cogs;
    $monthlyProfitValues[] = $revenue - $cogs;
}

$stockStmt = $pdo->query(
    'SELECT name, cost_price, price, stock_qty
     FROM products
     ORDER BY (stock_qty <= 5) DESC, stock_qty ASC, name ASC'
);
$stockProducts = $stockStmt->fetchAll(PDO::FETCH_ASSOC);

$recentSalesStmt = $pdo->query(
    "SELECT o.id, o.total_amount, o.created_at,
            GROUP_CONCAT(oi.product_name ORDER BY oi.product_name SEPARATOR ', ') AS items_sold,
            COALESCE(SUM(oi.quantity), 0) AS quantity_sold
     FROM orders o
     LEFT JOIN order_items oi ON oi.order_id = o.id
     WHERE o.status = 'completed'
     GROUP BY o.id, o.total_amount, o.created_at
     ORDER BY o.created_at DESC
     LIMIT 10"
);
$recentSales = $recentSalesStmt->fetchAll(PDO::FETCH_ASSOC);

$escape = static fn (string $value): string =>
    htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$money = static fn (float $value): string =>
    'KES ' . number_format($value, 2);
$currencies = [
    'KES' => 'Kenyan Shilling',
    'USD' => 'US Dollar',
    'EUR' => 'Euro',
    'GBP' => 'British Pound',
    'UGX' => 'Ugandan Shilling',
    'TZS' => 'Tanzanian Shilling',
    'RWF' => 'Rwandan Franc',
    'ZAR' => 'South African Rand',
    'INR' => 'Indian Rupee',
    'CNY' => 'Chinese Yuan',
    'AED' => 'UAE Dirham',
    'CAD' => 'Canadian Dollar',
    'AUD' => 'Australian Dollar',
    'JPY' => 'Japanese Yen',
];

$chartJsonFlags = JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
$salesTrendLabelsJson = json_encode(
    array_map(static fn (string $day): string => date('d M', strtotime($day)), array_keys($salesByDay)),
    $chartJsonFlags
);
$salesTrendValuesJson = json_encode(array_values($salesByDay), $chartJsonFlags);
$monthlyLabelsJson = json_encode($monthlyLabels, $chartJsonFlags);
$monthlyRevenueJson = json_encode($monthlyRevenueValues, $chartJsonFlags);
$monthlyCogsJson = json_encode($monthlyCogsValues, $chartJsonFlags);
$monthlyProfitJson = json_encode($monthlyProfitValues, $chartJsonFlags);

$pageTitle = 'Owner Analytics';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex gap-0 -mx-4 sm:-mx-6 lg:-mx-8 min-h-screen">
    <aside class="admin-sidebar hidden lg:flex flex-col w-56 xl:w-60
                  sticky top-16 self-start h-[calc(100vh-4rem)] overflow-y-auto"
           aria-label="Admin navigation">
        <div class="admin-sidebar__brand">Owner Dashboard</div>
        <nav class="admin-sidebar__nav" aria-label="Admin sections">
            <?php
            $navLinks = [
                ['Admin Overview', 'index.php'],
                ['Owner Analytics', 'dashboard.php'],
                ['Products', 'products.php'],
                ['Categories', 'admin_categories.php'],
                ['Requests', 'admin_requests.php'],
                ['Gallery Manager', 'admin_gallery.php'],
                ['Orders', 'orders.php'],
                ['Users', 'users.php'],
                ['Employees', 'employees.php'],
                ['Payroll', 'admin_payroll.php'],
                ['Deliveries', 'deliveries.php'],
                ['Payments', 'payments_record.php'],
            ];
            foreach ($navLinks as [$label, $file]):
                $active = $file === basename($_SERVER['PHP_SELF']);
            ?>
            <a href="<?= $base ?>/admin/<?= $file ?>"
               class="admin-sidebar__link <?= $active ? 'active' : '' ?>"
               <?= $active ? 'aria-current="page"' : '' ?>>
                <?= $escape($label) ?>
            </a>
            <?php endforeach; ?>
            <div class="border-t border-gray-700/50 my-3 mx-3"></div>
            <a href="<?= $base ?>/shop.php" class="admin-sidebar__link">View Shop</a>
            <a href="<?= $base ?>/admin/logout.php" class="admin-sidebar__link">Logout</a>
        </nav>
    </aside>

    <div class="flex-1 min-w-0 px-4 sm:px-6 lg:px-8 py-6">
        <header class="mb-8">
            <h1 class="text-2xl font-extrabold text-gray-900">Owner Analytics</h1>
            <p class="text-sm text-gray-500 mt-1">
                Completed-order performance and current inventory · <?= date('l, d F Y') ?>
            </p>
        </header>

        <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-8"
                 aria-label="Business summary">
            <article class="stat-card">
                <div>
                    <p class="stat-card__value"><?= $escape($money($totalRevenue)) ?></p>
                    <p class="stat-card__label">Total Revenue</p>
                </div>
            </article>
            <article class="stat-card">
                <div>
                    <p class="stat-card__value"><?= $escape($money($totalCogs)) ?></p>
                    <p class="stat-card__label">Total COGS</p>
                </div>
            </article>
            <article class="stat-card" style="border-left-color: <?= $netProfit < 0 ? '#EF4444' : '#16A34A' ?>;">
                <div>
                    <p class="stat-card__value"><?= $escape($money($netProfit)) ?></p>
                    <p class="stat-card__label">Net Profit</p>
                </div>
            </article>
            <article class="stat-card">
                <div>
                    <p class="stat-card__value"><?= number_format($totalUnitsSold) ?></p>
                    <p class="stat-card__label">Units Sold</p>
                </div>
            </article>
        </section>

        <section class="grid grid-cols-1 xl:grid-cols-2 gap-5 mb-8"
                 aria-label="Sales and profit charts">
            <article class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <h2 class="text-lg font-bold text-gray-900 mb-1">Sales Trend</h2>
                <p class="text-sm text-gray-500 mb-4">Completed sales by day · last 30 days</p>
                <div class="relative h-72">
                    <canvas id="sales-trend-chart" aria-label="Daily sales trend chart"></canvas>
                </div>
            </article>
            <article class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <h2 class="text-lg font-bold text-gray-900 mb-1">Profit vs. Loss</h2>
                <p class="text-sm text-gray-500 mb-4">Monthly revenue, COGS, and net profit · last 12 months</p>
                <div class="relative h-72">
                    <canvas id="monthly-profit-chart" aria-label="Monthly revenue and profit chart"></canvas>
                </div>
            </article>
        </section>

        <section class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 mb-8"
                 aria-labelledby="quick-calculator-title">
            <div class="mb-5">
                <h2 id="quick-calculator-title" class="text-lg font-bold text-gray-900">
                    Quick Hardware Calculator &amp; Margin Check
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    Estimate a selling price and batch margin, or use the calculator keypad.
                </p>
            </div>
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                <div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <label class="block text-sm font-medium text-gray-700">
                            Cost Price (KES)
                            <input id="margin-cost" type="number" min="0" step="0.01"
                                   inputmode="decimal" value="0"
                                   class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-base focus:border-orange focus:outline-none focus:ring-2 focus:ring-orange/20">
                        </label>
                        <label class="block text-sm font-medium text-gray-700">
                            Target Margin (%)
                            <input id="margin-target" type="number" min="0" max="99.99" step="0.01"
                                   inputmode="decimal" value="30"
                                   class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-base focus:border-orange focus:outline-none focus:ring-2 focus:ring-orange/20">
                        </label>
                        <label class="block text-sm font-medium text-gray-700">
                            Selling Price (KES)
                            <input id="margin-selling" type="number" min="0" step="0.01"
                                   inputmode="decimal" placeholder="Use recommended price"
                                   class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-base focus:border-orange focus:outline-none focus:ring-2 focus:ring-orange/20">
                        </label>
                        <label class="block text-sm font-medium text-gray-700 sm:col-span-1">
                            Quantity
                            <input id="margin-quantity" type="number" min="1" step="1"
                                   inputmode="numeric" value="1"
                                   class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-base focus:border-orange focus:outline-none focus:ring-2 focus:ring-orange/20">
                        </label>
                    </div>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-5">
                        <div class="rounded-lg bg-gray-50 p-3">
                            <dt class="text-xs font-medium text-gray-500">Recommended Selling Price</dt>
                            <dd id="margin-recommended" class="mt-1 text-lg font-bold text-gray-900">KES 0.00</dd>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3">
                            <dt class="text-xs font-medium text-gray-500">Total Cost vs. Revenue</dt>
                            <dd id="margin-batch-totals" class="mt-1 text-sm font-semibold text-gray-900">KES 0.00 vs. KES 0.00</dd>
                        </div>
                        <div class="rounded-lg bg-orange-50 p-3 sm:col-span-2">
                            <dt class="text-xs font-medium text-gray-600">Expected Gross Profit</dt>
                            <dd id="margin-profit" class="mt-1 text-lg font-bold text-gray-900">KES 0.00 (0.00%)</dd>
                        </div>
                    </dl>
                    <p class="text-xs text-gray-500 mt-3">
                        Enter a selling price to check it against cost, or leave it blank to use the target-margin recommendation.
                    </p>
                </div>

                <div class="rounded-xl bg-gray-50 p-4">
                    <label class="sr-only" for="basic-calculator-display">Calculator display</label>
                    <input id="basic-calculator-display" type="text" value="0" readonly
                           aria-live="polite"
                           class="block w-full rounded-lg border border-gray-300 bg-white px-4 py-3 text-right text-2xl font-bold text-gray-900">
                    <div class="grid grid-cols-4 gap-2 mt-3" aria-label="Calculator keypad">
                        <?php
                        $calculatorKeys = [
                            ['7', '8', '9', '/'],
                            ['4', '5', '6', '*'],
                            ['1', '2', '3', '-'],
                            ['0', '.', 'clear', '+'],
                        ];
                        foreach ($calculatorKeys as $row):
                            foreach ($row as $key):
                                $label = match ($key) {
                                    'clear' => 'Clear',
                                    '/' => '÷',
                                    '*' => '×',
                                    '-' => '−',
                                    default => $key,
                                };
                        ?>
                        <button type="button" data-calculator-key="<?= $escape($key) ?>"
                                class="rounded-lg <?= $key === 'clear' ? 'bg-red-100 text-red-700 hover:bg-red-200' : (in_array($key, ['+', '-', '*', '/'], true) ? 'bg-orange text-white hover:bg-orange-dark' : 'bg-white text-gray-800 hover:bg-gray-100') ?> py-3 font-semibold shadow-sm"
                                <?= in_array($key, ['+', '-', '*', '/'], true) ? 'aria-label="' . $escape(match ($key) { '+' => 'Add', '-' => 'Subtract', '*' => 'Multiply', '/' => 'Divide' }) . '"' : '' ?>>
                            <?= $escape($label) ?>
                        </button>
                        <?php endforeach; endforeach; ?>
                        <button type="button" data-calculator-key="="
                                class="col-span-4 rounded-lg bg-gray-800 py-3 font-semibold text-white hover:bg-gray-700">
                            =
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <section class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 mb-8"
                 aria-labelledby="currency-converter-title">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-5">
                <div>
                    <h2 id="currency-converter-title" class="text-lg font-bold text-gray-900">
                        Currency Converter
                    </h2>
                    <p class="text-sm text-gray-500 mt-1">
                        Convert amounts using the latest available exchange rates. Rates are estimates and may differ from your provider.
                    </p>
                </div>
                <button id="currency-refresh" type="button"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                        aria-describedby="currency-rate-status">
                    Refresh rates
                </button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <label class="block text-sm font-medium text-gray-700" for="currency-amount">
                    Amount
                    <input id="currency-amount" type="number" min="0" step="any"
                           inputmode="decimal" value="1"
                           class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-base focus:border-orange focus:outline-none focus:ring-2 focus:ring-orange/20">
                </label>
                <label class="block text-sm font-medium text-gray-700">
                    From
                    <select id="currency-from"
                            class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-base focus:border-orange focus:outline-none focus:ring-2 focus:ring-orange/20">
                        <?php foreach ($currencies as $code => $name): ?>
                        <option value="<?= $escape($code) ?>"><?= $escape($code . ' — ' . $name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="flex items-center justify-center">
                    <button id="currency-swap" type="button"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                            aria-label="Swap currencies">
                        Swap
                    </button>
                </div>
                <label class="block text-sm font-medium text-gray-700">
                    To
                    <select id="currency-to"
                            class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-base focus:border-orange focus:outline-none focus:ring-2 focus:ring-orange/20">
                        <?php foreach ($currencies as $code => $name): ?>
                        <option value="<?= $escape($code) ?>" <?= $code === 'USD' ? 'selected' : '' ?>><?= $escape($code . ' — ' . $name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="mt-5 rounded-lg bg-orange-50 p-4">
                <p class="text-xs font-medium text-gray-600">Converted amount</p>
                <p id="currency-result" class="mt-1 text-2xl font-bold text-gray-900" aria-live="polite">
                    Loading exchange rates…
                </p>
                <p id="currency-rate-status" class="mt-2 text-xs text-gray-600" role="status" aria-live="polite">
                    Fetching the latest rates…
                </p>
            </div>
        </section>

        <section class="bg-white rounded-xl border border-gray-100 shadow-sm mb-8">
            <div class="p-5 border-b border-gray-100">
                <h2 class="text-lg font-bold text-gray-900">Stock Status</h2>
                <p class="text-sm text-gray-500 mt-1">Products with five or fewer units are flagged as low stock.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Product Name</th>
                            <th class="px-5 py-3 font-semibold">Cost Price</th>
                            <th class="px-5 py-3 font-semibold">Selling Price</th>
                            <th class="px-5 py-3 font-semibold">Remaining Stock</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if ($stockProducts === []): ?>
                        <tr><td class="px-5 py-6 text-gray-500" colspan="5">No products found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($stockProducts as $product):
                                $stockQuantity = (int) $product['stock_qty'];
                                $isLowStock = $stockQuantity <= 5;
                            ?>
                            <tr>
                                <td class="px-5 py-3 font-medium text-gray-900"><?= $escape((string) $product['name']) ?></td>
                                <td class="px-5 py-3"><?= $escape($money((float) $product['cost_price'])) ?></td>
                                <td class="px-5 py-3"><?= $escape($money((float) $product['price'])) ?></td>
                                <td class="px-5 py-3"><?= number_format($stockQuantity) ?></td>
                                <td class="px-5 py-3">
                                    <span class="badge <?= $stockQuantity === 0 ? 'badge-error' : ($isLowStock ? 'badge-warning' : 'badge-success') ?>">
                                        <?= $stockQuantity === 0 ? 'Out of Stock' : ($isLowStock ? 'Low Stock' : 'In Stock') ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="bg-white rounded-xl border border-gray-100 shadow-sm mb-8">
            <div class="p-5 border-b border-gray-100">
                <h2 class="text-lg font-bold text-gray-900">Recent Sales</h2>
                <p class="text-sm text-gray-500 mt-1">Latest completed orders</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Order ID</th>
                            <th class="px-5 py-3 font-semibold">Items Sold</th>
                            <th class="px-5 py-3 font-semibold">Quantity</th>
                            <th class="px-5 py-3 font-semibold">Total Amount</th>
                            <th class="px-5 py-3 font-semibold">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if ($recentSales === []): ?>
                        <tr><td class="px-5 py-6 text-gray-500" colspan="5">No completed sales yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentSales as $sale): ?>
                            <tr>
                                <td class="px-5 py-3 font-semibold">
                                    <a class="text-orange hover:underline"
                                       href="<?= $base ?>/admin/orders.php?view=detail&amp;id=<?= (int) $sale['id'] ?>">
                                        #<?= (int) $sale['id'] ?>
                                    </a>
                                </td>
                                <td class="px-5 py-3"><?= $escape((string) ($sale['items_sold'] ?? 'No item details')) ?></td>
                                <td class="px-5 py-3"><?= number_format((int) $sale['quantity_sold']) ?></td>
                                <td class="px-5 py-3 font-medium"><?= $escape($money((float) $sale['total_amount'])) ?></td>
                                <td class="px-5 py-3 whitespace-nowrap"><?= $escape(date('d M Y, H:i', strtotime((string) $sale['created_at']))) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <p class="text-xs text-gray-500">
            COGS is estimated from current product cost prices; historical cost prices are not stored with order items.
        </p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const salesTrendCanvas = document.getElementById('sales-trend-chart');
    const monthlyProfitCanvas = document.getElementById('monthly-profit-chart');

    if (typeof Chart !== 'undefined' && salesTrendCanvas && monthlyProfitCanvas) {
        new Chart(salesTrendCanvas, {
        type: 'line',
        data: {
            labels: <?= $salesTrendLabelsJson ?>,
            datasets: [{
                label: 'Sales (KES)',
                data: <?= $salesTrendValuesJson ?>,
                borderColor: '#E8500A',
                backgroundColor: 'rgba(232, 80, 10, 0.12)',
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true } }
        }
        });

        new Chart(monthlyProfitCanvas, {
        type: 'bar',
        data: {
            labels: <?= $monthlyLabelsJson ?>,
            datasets: [
                {
                    label: 'Revenue (KES)',
                    data: <?= $monthlyRevenueJson ?>,
                    backgroundColor: '#E8500A'
                },
                {
                    label: 'COGS (KES)',
                    data: <?= $monthlyCogsJson ?>,
                    backgroundColor: '#64748B'
                },
                {
                    label: 'Net Profit (KES)',
                    data: <?= $monthlyProfitJson ?>,
                    backgroundColor: '#16A34A'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true } }
        }
        });
    }

    const costInput = document.getElementById('margin-cost');
    const targetInput = document.getElementById('margin-target');
    const sellingInput = document.getElementById('margin-selling');
    const quantityInput = document.getElementById('margin-quantity');
    const recommendedOutput = document.getElementById('margin-recommended');
    const totalsOutput = document.getElementById('margin-batch-totals');
    const profitOutput = document.getElementById('margin-profit');

    const formatKes = function (amount) {
        return 'KES ' + amount.toLocaleString('en-KE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    };

    const updateMargin = function () {
        const cost = Number(costInput.value);
        const margin = Number(targetInput.value);
        const enteredPrice = sellingInput.value.trim();
        const quantity = Number(quantityInput.value);
        const valid = costInput.value.trim() !== ''
            && targetInput.value.trim() !== ''
            && quantityInput.value.trim() !== ''
            && Number.isFinite(cost) && cost >= 0
            && Number.isFinite(margin) && margin >= 0 && margin < 100
            && Number.isFinite(quantity) && Number.isInteger(quantity) && quantity > 0;

        if (!valid) {
            recommendedOutput.textContent = 'Enter valid cost, margin (<100%), and quantity';
            totalsOutput.textContent = '—';
            profitOutput.textContent = '—';
            return;
        }

        const recommendedPrice = cost / (1 - margin / 100);
        if (!Number.isFinite(recommendedPrice)) {
            recommendedOutput.textContent = 'Value too large to calculate';
            totalsOutput.textContent = '—';
            profitOutput.textContent = '—';
            return;
        }

        const sellingPrice = enteredPrice === '' ? recommendedPrice : Number(enteredPrice);
        if (!Number.isFinite(sellingPrice) || sellingPrice < 0) {
            recommendedOutput.textContent = formatKes(recommendedPrice);
            totalsOutput.textContent = 'Enter a valid selling price';
            profitOutput.textContent = '—';
            return;
        }

        const totalCost = cost * quantity;
        const totalRevenue = sellingPrice * quantity;
        const grossProfit = totalRevenue - totalCost;
        const actualMargin = totalRevenue === 0 ? null : (grossProfit / totalRevenue) * 100;

        if (![totalCost, totalRevenue, grossProfit].every(Number.isFinite) ||
            (actualMargin !== null && !Number.isFinite(actualMargin))) {
            totalsOutput.textContent = 'Values are too large to calculate';
            profitOutput.textContent = '—';
            return;
        }

        recommendedOutput.textContent = formatKes(recommendedPrice);
        totalsOutput.textContent = formatKes(totalCost) + ' vs. ' + formatKes(totalRevenue);
        profitOutput.textContent = formatKes(grossProfit) + ' (' +
            (actualMargin === null ? '—' : actualMargin.toFixed(2) + '%') + ')';
        profitOutput.classList.toggle('text-red-700', grossProfit < 0);
        profitOutput.classList.toggle('text-green-700', grossProfit >= 0);
    };

    [costInput, targetInput, sellingInput, quantityInput].forEach(function (input) {
        input.addEventListener('input', updateMargin);
    });
    updateMargin();

    const currencyAmount = document.getElementById('currency-amount');
    const currencyFrom = document.getElementById('currency-from');
    const currencyTo = document.getElementById('currency-to');
    const currencyResult = document.getElementById('currency-result');
    const currencyRateStatus = document.getElementById('currency-rate-status');
    const currencyRefresh = document.getElementById('currency-refresh');
    const currencySwap = document.getElementById('currency-swap');
    let currencyRates = null;
    let currencyRateUpdatedAt = '';

    const updateCurrencyConversion = function () {
        if (!currencyRates) {
            return;
        }

        const amountText = currencyAmount.value.trim();
        const amount = Number(amountText);
        const from = currencyFrom.value;
        const to = currencyTo.value;
        const fromRate = currencyRates[from];
        const toRate = currencyRates[to];

        if (amountText === '' || !Number.isFinite(amount) || amount < 0) {
            currencyResult.textContent = 'Enter a valid non-negative amount.';
            return;
        }
        if (!Number.isFinite(fromRate) || fromRate <= 0 ||
            !Number.isFinite(toRate) || toRate <= 0) {
            currencyResult.textContent = 'Rate unavailable for the selected currency.';
            return;
        }

        const convertedAmount = amount * (toRate / fromRate);
        if (!Number.isFinite(convertedAmount)) {
            currencyResult.textContent = 'Amount is too large to convert.';
            return;
        }

        const formattedAmount = new Intl.NumberFormat('en', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(convertedAmount);
        currencyResult.textContent = formattedAmount + ' ' + to;
    };

    const loadCurrencyRates = async function () {
        currencyRefresh.disabled = true;
        currencyRateStatus.classList.remove('text-red-700');
        currencyRateStatus.textContent = 'Fetching the latest rates…';

        try {
            const response = await fetch('https://open.er-api.com/v6/latest/KES', {
                headers: { Accept: 'application/json' }
            });
            if (!response.ok) {
                throw new Error('Exchange-rate service returned HTTP ' + response.status + '.');
            }

            const data = await response.json();
            if (data.result !== 'success' || !data.rates || typeof data.rates !== 'object') {
                throw new Error('Exchange-rate service returned an invalid response.');
            }

            currencyRates = data.rates;
            const updatedAt = new Date(data.time_last_update_utc);
            currencyRateUpdatedAt = Number.isNaN(updatedAt.getTime())
                ? ''
                : updatedAt.toLocaleString();
            currencyRateStatus.textContent = currencyRateUpdatedAt
                ? 'Rates last updated ' + currencyRateUpdatedAt + '. Source: open.er-api.com.'
                : 'Rates loaded. Source: open.er-api.com.';
            updateCurrencyConversion();
        } catch {
            currencyRateStatus.classList.add('text-red-700');
            currencyRateStatus.textContent = currencyRates
                ? 'Could not refresh rates. Showing the previously loaded rates' +
                    (currencyRateUpdatedAt ? ' from ' + currencyRateUpdatedAt : '') + '.'
                : 'Exchange rates could not be loaded. Check your connection and try again.';
            if (!currencyRates) {
                currencyResult.textContent = 'Conversion unavailable until rates load.';
            }
        } finally {
            currencyRefresh.disabled = false;
        }
    };

    [currencyAmount, currencyFrom, currencyTo].forEach(function (control) {
        control.addEventListener('input', updateCurrencyConversion);
        control.addEventListener('change', updateCurrencyConversion);
    });
    currencySwap.addEventListener('click', function () {
        const previousFrom = currencyFrom.value;
        currencyFrom.value = currencyTo.value;
        currencyTo.value = previousFrom;
        updateCurrencyConversion();
    });
    currencyRefresh.addEventListener('click', loadCurrencyRates);
    loadCurrencyRates();

    const calculatorDisplay = document.getElementById('basic-calculator-display');
    let storedValue = null;
    let pendingOperator = null;
    let waitingForOperand = false;

    const resetCalculator = function () {
        calculatorDisplay.value = '0';
        storedValue = null;
        pendingOperator = null;
        waitingForOperand = false;
    };

    const performCalculation = function (left, right, operator) {
        switch (operator) {
            case '+': return left + right;
            case '-': return left - right;
            case '*': return left * right;
            case '/': return right === 0 ? null : left / right;
            default: return right;
        }
    };

    document.querySelectorAll('[data-calculator-key]').forEach(function (button) {
        button.addEventListener('click', function () {
            const key = button.dataset.calculatorKey;

            if (key === 'clear') {
                resetCalculator();
                return;
            }

            if (/^\d$/.test(key) || key === '.') {
                if (waitingForOperand || calculatorDisplay.value === 'Error') {
                    calculatorDisplay.value = key === '.' ? '0.' : key;
                    waitingForOperand = false;
                } else if (key === '.') {
                    if (!calculatorDisplay.value.includes('.')) {
                        calculatorDisplay.value += '.';
                    }
                } else {
                    calculatorDisplay.value = calculatorDisplay.value === '0'
                        ? key
                        : calculatorDisplay.value + key;
                }
                return;
            }

            const currentValue = Number(calculatorDisplay.value);
            if (!Number.isFinite(currentValue)) {
                resetCalculator();
                return;
            }

            if (key === '=') {
                if (pendingOperator !== null && storedValue !== null) {
                    const result = performCalculation(storedValue, currentValue, pendingOperator);
                    calculatorDisplay.value = result === null || !Number.isFinite(result)
                        ? 'Error'
                        : String(result);
                    storedValue = null;
                    pendingOperator = null;
                }
                waitingForOperand = true;
                return;
            }

            if (pendingOperator !== null && !waitingForOperand && storedValue !== null) {
                const result = performCalculation(storedValue, currentValue, pendingOperator);
                if (result === null || !Number.isFinite(result)) {
                    calculatorDisplay.value = 'Error';
                    storedValue = null;
                    pendingOperator = null;
                    waitingForOperand = true;
                    return;
                }
                calculatorDisplay.value = String(result);
                storedValue = result;
            } else {
                storedValue = currentValue;
            }

            pendingOperator = key;
            waitingForOperand = true;
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
