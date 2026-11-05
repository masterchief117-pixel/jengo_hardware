<?php
/**
 * admin/payments_record.php
 *
 * Payment transaction log — admin only.
 *
 * Displays all rows from the `payments` table with:
 *   - Method (M-Pesa / Paystack / Cash)
 *   - Amount & currency
 *   - Gateway status (pending / success / failed / cancelled / refunded)
 *   - Transaction reference
 *   - Linked order number + customer
 *   - Timestamp
 *
 * Filters: method, status, date range, search (ref / order# / customer)
 * Exports: CSV download of current filter set
 */

declare(strict_types=1);

require_once __DIR__ . '/../../admin/auth.php';

require_admin_login();

$pdo = db();

// ══════════════════════════════════════════════════════════════════════════
// FILTER PARAMS
// ══════════════════════════════════════════════════════════════════════════
$search       = trim($_GET['q']       ?? '');
$filterMethod = $_GET['method']       ?? '';
$filterStatus = $_GET['status']       ?? '';
$filterFrom   = $_GET['from']         ?? '';
$filterTo     = $_GET['to']           ?? '';
$exportCsv    = isset($_GET['export']) && $_GET['export'] === 'csv';
$perPage      = 25;
$page         = max(1, (int) ($_GET['page'] ?? 1));
$offset       = ($page - 1) * $perPage;

$validMethods = ['mpesa', 'paystack', 'cash'];
$validStatuses= ['pending', 'success', 'failed', 'cancelled', 'refunded'];

if (!in_array($filterMethod, $validMethods, true)) $filterMethod = '';
if (!in_array($filterStatus, $validStatuses, true)) $filterStatus = '';

// ══════════════════════════════════════════════════════════════════════════
// BUILD QUERY
// ══════════════════════════════════════════════════════════════════════════
$conditions = [];
$bindings   = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $conditions[] = '(p.gateway_reference LIKE ?
                      OR p.gateway_transaction_id LIKE ?
                      OR o.order_number LIKE ?
                      OR u.full_name LIKE ?
                      OR u.email LIKE ?)';
    $bindings = array_merge($bindings, [$like, $like, $like, $like, $like]);
}
if ($filterMethod !== '') {
    $conditions[] = 'p.payment_method = ?';
    $bindings[]   = $filterMethod;
}
if ($filterStatus !== '') {
    $conditions[] = 'p.status = ?';
    $bindings[]   = $filterStatus;
}
if ($filterFrom !== '') {
    $conditions[] = 'DATE(p.created_at) >= ?';
    $bindings[]   = $filterFrom;
}
if ($filterTo !== '') {
    $conditions[] = 'DATE(p.created_at) <= ?';
    $bindings[]   = $filterTo;
}

$joins = 'LEFT JOIN orders o ON o.id = p.order_id
          LEFT JOIN users  u ON u.id = p.user_id';
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// ══════════════════════════════════════════════════════════════════════════
// CSV EXPORT (before any HTML output)
// ══════════════════════════════════════════════════════════════════════════
if ($exportCsv) {
    $stmt = $pdo->prepare(
        "SELECT p.id, p.payment_method, p.amount, p.currency, p.status,
                p.gateway_reference, p.gateway_transaction_id,
                p.phone_number, p.paid_at, p.created_at,
                o.order_number,
                u.full_name AS customer_name, u.email AS customer_email
         FROM   payments p
         $joins
         $where
         ORDER  BY p.created_at DESC"
    );
    $stmt->execute($bindings);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');

    fputcsv($out, [
        'ID', 'Order #', 'Customer', 'Email',
        'Method', 'Amount', 'Currency', 'Status',
        'Gateway Reference', 'Transaction ID',
        'Phone', 'Paid At', 'Created At',
    ]);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['order_number']      ?? '',
            $r['customer_name']     ?? 'Walk-in',
            $r['customer_email']    ?? '',
            strtoupper($r['payment_method']),
            number_format((float)$r['amount'], 2),
            $r['currency'],
            $r['status'],
            $r['gateway_reference']     ?? '',
            $r['gateway_transaction_id'] ?? '',
            $r['phone_number']          ?? '',
            $r['paid_at']               ?? '',
            $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// SUMMARY AGGREGATES (KPI strip)
// ══════════════════════════════════════════════════════════════════════════
$kpiStmt = $pdo->query(
    "SELECT
        COUNT(*)                                                AS total_records,
        SUM(status = 'success')                                 AS successful,
        SUM(status = 'failed')                                  AS failed,
        SUM(status = 'pending')                                 AS pending,
        SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END) AS total_collected,
        SUM(CASE WHEN status = 'success'
                  AND payment_method = 'mpesa'
                 THEN amount ELSE 0 END)                        AS mpesa_total,
        SUM(CASE WHEN status = 'success'
                  AND payment_method = 'paystack'
                 THEN amount ELSE 0 END)                        AS paystack_total,
        SUM(CASE WHEN status = 'success'
                  AND DATE(created_at) = CURDATE()
                 THEN amount ELSE 0 END)                        AS today_total
     FROM payments"
);
$kpi = $kpiStmt->fetch();

// Status & method counts for tab bars
$methodCountsStmt = $pdo->query(
    "SELECT payment_method, COUNT(*) AS cnt FROM payments GROUP BY payment_method"
);
$methodCounts = [];
foreach ($methodCountsStmt->fetchAll() as $r) {
    $methodCounts[$r['payment_method']] = (int)$r['cnt'];
}

$statusCountsStmt = $pdo->query(
    "SELECT status, COUNT(*) AS cnt FROM payments GROUP BY status"
);
$statusCounts = [];
foreach ($statusCountsStmt->fetchAll() as $r) {
    $statusCounts[$r['status']] = (int)$r['cnt'];
}

// ══════════════════════════════════════════════════════════════════════════
// PAGINATED RECORDS
// ══════════════════════════════════════════════════════════════════════════
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM payments p $joins $where"
);
$countStmt->execute($bindings);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages   = (int) ceil($totalRecords / $perPage);
$page         = min($page, max(1, $totalPages ?: 1));

$paymentsStmt = $pdo->prepare(
    "SELECT p.id, p.payment_method, p.amount, p.currency,
            p.status, p.gateway_reference, p.gateway_transaction_id,
            p.phone_number, p.paid_at, p.created_at,
            o.id           AS order_id,
            o.order_number,
            u.full_name    AS customer_name,
            u.email        AS customer_email
     FROM   payments p
     $joins
     $where
     ORDER  BY p.created_at DESC
     LIMIT  ? OFFSET ?"
);
$paymentsStmt->execute(array_merge($bindings, [$perPage, $offset]));
$payments = $paymentsStmt->fetchAll();

// ── Helpers ───────────────────────────────────────────────────────────────
function payment_status_badge(string $status): string
{
    $map = [
        'pending'   => 'status-pending',
        'success'   => 'status-completed',
        'failed'    => 'status-cancelled',
        'cancelled' => 'badge-warning',
        'refunded'  => 'status-refunded',
    ];
    $labels = [
        'pending'   => 'Pending',
        'success'   => 'Success',
        'failed'    => 'Failed',
        'cancelled' => 'Cancelled',
        'refunded'  => 'Refunded',
    ];
    $cls = $map[$status] ?? 'badge-charcoal';
    $lbl = $labels[$status] ?? ucfirst($status);
    return "<span class=\"badge {$cls}\">{$lbl}</span>";
}

function method_badge(string $method): string
{
    $map = [
        'mpesa'    => 'bg-green-100 text-green-800 border border-green-200',
        'paystack' => 'bg-blue-100  text-blue-800  border border-blue-200',
        'cash'     => 'bg-gray-100  text-gray-700  border border-gray-200',
    ];
    $cls = $map[$method] ?? 'bg-gray-100 text-gray-600';
    $lbl = strtoupper($method);
    return "<span class=\"badge {$cls}\">{$lbl}</span>";
}

$pageTitle = 'Payment Records';
require_once __DIR__ . '/../includes/header.php';

// Build CSV export URL preserving current filters
$csvParams = array_filter([
    'q'      => $search,
    'method' => $filterMethod,
    'status' => $filterStatus,
    'from'   => $filterFrom,
    'to'     => $filterTo,
    'export' => 'csv',
]);
$csvUrl = $base . '/admin/payments_record.php?' . http_build_query($csvParams);
?>

<!-- ══════════════════════════════════════════════════════════════════════
     ADMIN LAYOUT
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
        <a href="<?= $base ?>/admin/logout.php" class="admin-sidebar__link">🚪 Sign Out</a>
    </aside>

    <!-- Main content -->
    <div class="flex-1 min-w-0 px-4 sm:px-6 lg:px-8 py-6">

        <!-- Page header -->
        <div class="flex flex-col sm:flex-row sm:items-center
                    sm:justify-between gap-3 mb-8">
            <div>
                <h1 class="text-2xl font-extrabold text-gray-900">
                    Payment Records
                </h1>
                <p class="text-sm text-gray-500 mt-0.5">
                    All gateway transaction logs
                </p>
            </div>
            <a href="<?= htmlspecialchars($csvUrl) ?>"
               class="btn btn-secondary btn-sm flex items-center gap-2">
                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor"
                     stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0
                             00-2-2V5a2 2 0 012-2h5.586a1 1 0
                             01.707.293l5.414 5.414a1 1 0
                             01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Export CSV
            </a>
        </div>


        <!-- ══════════════════════════════════════════════════════════════
             KPI STRIP
        ══════════════════════════════════════════════════════════════ -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
            <?php
            $kpis = [
                [
                    'label' => 'Total Collected',
                    'value' => 'KES ' . number_format((float)($kpi['total_collected'] ?? 0), 0),
                    'sub'   => 'Successful payments',
                    'color' => 'border-l-green-500',
                    'icon'  => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                ],
                [
                    'label' => "Today's Revenue",
                    'value' => 'KES ' . number_format((float)($kpi['today_total'] ?? 0), 0),
                    'sub'   => date('d M Y'),
                    'color' => 'border-l-orange',
                    'icon'  => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
                ],
                [
                    'label' => 'Successful',
                    'value' => number_format((int)($kpi['successful'] ?? 0)),
                    'sub'   => 'of ' . number_format((int)($kpi['total_records'] ?? 0)) . ' total',
                    'color' => 'border-l-blue-500',
                    'icon'  => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
                ],
                [
                    'label' => 'Failed',
                    'value' => number_format((int)($kpi['failed'] ?? 0)),
                    'sub'   => (int)($kpi['pending'] ?? 0) . ' pending',
                    'color' => 'border-l-red-400',
                    'icon'  => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
                ],
            ];
            foreach ($kpis as $k): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100
                        border-l-4 <?= $k['color'] ?> p-4">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-gray-50 flex items-center
                                justify-center flex-shrink-0" aria-hidden="true">
                        <svg class="w-4 h-4 text-gray-400"
                             xmlns="http://www.w3.org/2000/svg" fill="none"
                             viewBox="0 0 24 24" stroke="currentColor"
                             stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="<?= $k['icon'] ?>"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-lg font-extrabold text-gray-900 leading-none">
                            <?= $k['value'] ?>
                        </p>
                        <p class="text-xs text-gray-500 mt-0.5"><?= $k['label'] ?></p>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2"><?= $k['sub'] ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Gateway breakdown row -->
        <div class="grid grid-cols-3 gap-4 mb-8">
            <?php
            $gateways = [
                ['M-Pesa',    'mpesa',    'KES ' . number_format((float)($kpi['mpesa_total'] ?? 0), 0),    'bg-green-50  border-green-200  text-green-700'],
                ['Paystack',  'paystack', 'KES ' . number_format((float)($kpi['paystack_total'] ?? 0), 0), 'bg-blue-50   border-blue-200   text-blue-700'],
                ['Cash',      'cash',     '—', 'bg-gray-50   border-gray-200   text-gray-600'],
            ];
            foreach ($gateways as [$gName, $gKey, $gTotal, $gStyle]): ?>
            <a href="?method=<?= $gKey ?>"
               class="rounded-xl border <?= $gStyle ?> p-4 text-center
                      hover:shadow-sm transition-shadow">
                <p class="text-xs font-bold uppercase tracking-wider mb-1">
                    <?= $gName ?>
                </p>
                <p class="text-xl font-extrabold"><?= $gTotal ?></p>
                <p class="text-xs opacity-60 mt-0.5">
                    <?= number_format($methodCounts[$gKey] ?? 0) ?> transactions
                </p>
            </a>
            <?php endforeach; ?>
        </div>


        <!-- ══════════════════════════════════════════════════════════════
             FILTER BAR
        ══════════════════════════════════════════════════════════════ -->

        <!-- Status filter tabs -->
        <div class="flex flex-wrap gap-2 mb-4" role="tablist"
             aria-label="Filter by payment status">
            <?php
            $tabStatuses = ['' => 'All'] + array_combine(
                $validStatuses,
                ['Pending', 'Success', 'Failed', 'Cancelled', 'Refunded']
            );
            foreach ($tabStatuses as $tVal => $tLabel):
                $isActive = $filterStatus === $tVal;
                $cnt      = $tVal === '' ? array_sum($statusCounts) : ($statusCounts[$tVal] ?? 0);
                $qp       = array_filter(['q' => $search, 'method' => $filterMethod,
                                          'from' => $filterFrom, 'to' => $filterTo]);
                if ($tVal !== '') $qp['status'] = $tVal;
            ?>
            <a href="?<?= http_build_query($qp) ?>"
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

        <form method="GET" action="payments_record.php"
              class="bg-white rounded-xl border border-gray-100 shadow-sm
                     p-4 mb-5 flex flex-wrap gap-3 items-end">
            <?php if ($filterStatus): ?>
            <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
            <?php endif; ?>

            <div class="flex-1 min-w-44">
                <label for="q" class="form-label text-xs mb-1">Search</label>
                <input type="search" id="q" name="q"
                       value="<?= htmlspecialchars($search) ?>"
                       placeholder="Ref, order #, customer name/email…"
                       class="form-input py-2 text-sm" />
            </div>

            <div class="w-32">
                <label for="method" class="form-label text-xs mb-1">Method</label>
                <select id="method" name="method" class="form-select py-2 text-sm">
                    <option value="">All</option>
                    <option value="mpesa"    <?= $filterMethod === 'mpesa'    ? 'selected' : '' ?>>M-Pesa</option>
                    <option value="paystack" <?= $filterMethod === 'paystack' ? 'selected' : '' ?>>Paystack</option>
                    <option value="cash"     <?= $filterMethod === 'cash'     ? 'selected' : '' ?>>Cash</option>
                </select>
            </div>

            <div class="w-36">
                <label for="from" class="form-label text-xs mb-1">From</label>
                <input type="date" id="from" name="from"
                       value="<?= htmlspecialchars($filterFrom) ?>"
                       class="form-input py-2 text-sm" />
            </div>

            <div class="w-36">
                <label for="to" class="form-label text-xs mb-1">To</label>
                <input type="date" id="to" name="to"
                       value="<?= htmlspecialchars($filterTo) ?>"
                       class="form-input py-2 text-sm" />
            </div>

            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <?php if ($search || $filterMethod || $filterFrom || $filterTo): ?>
                <a href="<?= $base ?>/admin/payments_record.php<?= $filterStatus ? '?status=' . urlencode($filterStatus) : '' ?>"
                   class="btn btn-secondary btn-sm">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Results count -->
        <div class="flex items-center justify-between mb-3">
            <p class="text-sm text-gray-500">
                <?= number_format($totalRecords) ?> record<?= $totalRecords !== 1 ? 's' : '' ?> found
                <?php if ($totalRecords > 0): ?>
                · Showing
                <strong class="text-gray-700">
                    <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalRecords)) ?>
                </strong>
                <?php endif; ?>
            </p>
            <a href="<?= htmlspecialchars($csvUrl) ?>"
               class="text-xs text-orange font-semibold hover:underline
                      flex items-center gap-1">
                <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor"
                     stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Download CSV
            </a>
        </div>


        <!-- ══════════════════════════════════════════════════════════════
             PAYMENTS TABLE
        ══════════════════════════════════════════════════════════════ -->
        <?php if (empty($payments)): ?>
        <div class="bg-white rounded-2xl border border-dashed border-gray-200
                    py-20 text-center" role="status">
            <div class="text-5xl mb-4" aria-hidden="true">💳</div>
            <h3 class="text-base font-semibold text-gray-700 mb-2">
                No payment records found
            </h3>
            <p class="text-gray-400 text-sm">
                <?= ($search || $filterMethod || $filterStatus || $filterFrom || $filterTo)
                    ? 'Try adjusting your filters.'
                    : 'Payment records will appear here once customers start paying.' ?>
            </p>
        </div>

        <?php else: ?>
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100
                    overflow-hidden">
            <div class="overflow-x-auto">
            <table class="data-table" aria-label="Payment records">
                <thead>
                    <tr>
                        <th scope="col">Date / Time</th>
                        <th scope="col">Order</th>
                        <th scope="col">Customer</th>
                        <th scope="col" class="text-center">Method</th>
                        <th scope="col" class="text-right">Amount</th>
                        <th scope="col" class="text-center">Status</th>
                        <th scope="col">Reference / Tx ID</th>
                        <th scope="col">Phone</th>
                        <th scope="col" class="text-center">Paid At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $pay): ?>
                    <tr class="hover:bg-gray-50/50 transition-colors group">

                        <!-- Date / time -->
                        <td class="whitespace-nowrap text-sm text-gray-500">
                            <?= date('d M Y', strtotime($pay['created_at'])) ?>
                            <span class="block text-xs text-gray-400">
                                <?= date('H:i:s', strtotime($pay['created_at'])) ?>
                            </span>
                        </td>

                        <!-- Order number -->
                        <td>
                            <?php if ($pay['order_number']): ?>
                            <a href="<?= $base ?>/admin/orders.php?view=detail&id=<?= $pay['order_id'] ?>"
                               class="font-mono text-sm font-semibold text-orange
                                      hover:underline">
                                <?= htmlspecialchars($pay['order_number']) ?>
                            </a>
                            <?php else: ?>
                            <span class="text-gray-400 text-sm">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Customer -->
                        <td>
                            <?php if ($pay['customer_name']): ?>
                            <p class="text-sm font-medium text-gray-800 truncate
                                       max-w-[140px]">
                                <?= htmlspecialchars($pay['customer_name']) ?>
                            </p>
                            <p class="text-xs text-gray-400 truncate max-w-[140px]">
                                <?= htmlspecialchars($pay['customer_email'] ?? '') ?>
                            </p>
                            <?php else: ?>
                            <span class="text-gray-400 text-sm">Walk-in</span>
                            <?php endif; ?>
                        </td>

                        <!-- Method -->
                        <td class="text-center">
                            <?= method_badge($pay['payment_method']) ?>
                        </td>

                        <!-- Amount -->
                        <td class="text-right font-bold whitespace-nowrap
                                   <?= $pay['status'] === 'success'
                                       ? 'text-green-600'
                                       : ($pay['status'] === 'failed'
                                           ? 'text-red-400'
                                           : 'text-gray-700') ?>">
                            <?= htmlspecialchars($pay['currency']) ?>
                            <?= number_format((float)$pay['amount'], 2) ?>
                        </td>

                        <!-- Status -->
                        <td class="text-center">
                            <?= payment_status_badge($pay['status']) ?>
                        </td>

                        <!-- Reference / Tx ID -->
                        <td>
                            <?php if ($pay['gateway_reference']): ?>
                            <p class="font-mono text-xs text-gray-800 font-semibold">
                                <?= htmlspecialchars($pay['gateway_reference']) ?>
                            </p>
                            <?php endif; ?>
                            <?php if ($pay['gateway_transaction_id'] &&
                                      $pay['gateway_transaction_id'] !== $pay['gateway_reference']): ?>
                            <p class="font-mono text-xs text-gray-400 mt-0.5">
                                <?= htmlspecialchars($pay['gateway_transaction_id']) ?>
                            </p>
                            <?php endif; ?>
                            <?php if (!$pay['gateway_reference'] && !$pay['gateway_transaction_id']): ?>
                            <span class="text-gray-300 text-xs">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Phone number used for M-Pesa -->
                        <td class="text-sm text-gray-500 whitespace-nowrap">
                            <?= $pay['phone_number']
                                ? htmlspecialchars($pay['phone_number'])
                                : '<span class="text-gray-300">—</span>' ?>
                        </td>

                        <!-- Paid at (confirmed timestamp) -->
                        <td class="text-center text-xs text-gray-500 whitespace-nowrap">
                            <?php if ($pay['paid_at']): ?>
                                <?= date('d M', strtotime($pay['paid_at'])) ?>
                                <span class="block text-gray-400">
                                    <?= date('H:i', strtotime($pay['paid_at'])) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-gray-300">—</span>
                            <?php endif; ?>
                        </td>

                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div><!-- /overflow-x-auto -->
        </div>


        <!-- ── PAGINATION ─────────────────────────────────────────────── -->
        <?php if ($totalPages > 1):
            $qp = array_filter([
                'q'      => $search,
                'method' => $filterMethod,
                'status' => $filterStatus,
                'from'   => $filterFrom,
                'to'     => $filterTo,
            ]);
        ?>
        <nav class="flex items-center justify-center gap-1 mt-6"
             aria-label="Payment records pages">
            <?php
            $range = range(max(1, $page - 2), min($totalPages, $page + 2));
            if ($range[0] > 1): ?>
                <a href="?<?= http_build_query(array_merge($qp, ['page' => 1])) ?>"
                   class="px-3 py-1.5 text-sm rounded-lg border border-gray-200
                          text-gray-600 hover:border-orange hover:text-orange">1</a>
                <?php if ($range[0] > 2): ?>
                <span class="text-gray-400 px-1 text-sm">…</span>
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

            <?php $lastR = end($range);
            if ($lastR < $totalPages): ?>
                <?php if ($lastR < $totalPages - 1): ?>
                <span class="text-gray-400 px-1 text-sm">…</span>
                <?php endif; ?>
                <a href="?<?= http_build_query(array_merge($qp, ['page' => $totalPages])) ?>"
                   class="px-3 py-1.5 text-sm rounded-lg border border-gray-200
                          text-gray-600 hover:border-orange hover:text-orange">
                    <?= $totalPages ?>
                </a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>

        <?php endif; // payments not empty ?>
    </div><!-- /main content -->
</div><!-- /admin flex -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
