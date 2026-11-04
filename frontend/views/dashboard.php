<?php
declare(strict_types=1);

$pageTitle = 'My Account';
$orders = isset($orders) && is_array($orders) ? $orders : [];
$customerName = isset($_SESSION['user_name']) && is_string($_SESSION['user_name'])
    ? trim($_SESSION['user_name'])
    : 'Customer';

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$statusStyles = [
    'pending' => ['Pending', 'bg-amber-100 text-amber-800'],
    'paid' => ['Paid', 'bg-blue-100 text-blue-800'],
    'delivered' => ['Delivered', 'bg-green-100 text-green-800'],
    'completed' => ['Delivered', 'bg-green-100 text-green-800'],
    'failed' => ['Failed', 'bg-red-100 text-red-800'],
    'cancelled' => ['Cancelled', 'bg-gray-100 text-gray-700'],
];

require __DIR__ . '/../components/header.php';
?>

<main class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    <header class="mb-8">
        <p class="text-sm font-bold uppercase tracking-widest" style="color: var(--jengo-safety-orange);">Customer account</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl">My dashboard</h1>
        <p class="mt-2 text-gray-600">Welcome back, <?= $escape($customerName) ?>. Manage your account and keep track of your orders.</p>
    </header>

    <div class="grid items-start gap-8 lg:grid-cols-[16rem_minmax(0,1fr)]">
        <aside class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm" aria-label="Customer profile navigation">
            <div class="flex items-center gap-3 border-b border-gray-100 pb-5">
                <span class="flex h-12 w-12 items-center justify-center rounded-full text-lg font-bold text-white"
                      style="background-color: var(--jengo-dark-charcoal);"
                      aria-hidden="true"><?= $escape(strtoupper(substr($customerName, 0, 1))) ?></span>
                <div class="min-w-0">
                    <p class="truncate font-bold text-gray-900"><?= $escape($customerName) ?></p>
                    <p class="text-sm text-gray-500">Customer account</p>
                </div>
            </div>

            <nav class="mt-4 flex gap-2 overflow-x-auto lg:flex-col" aria-label="Account sections">
                <a href="#profile" aria-current="page"
                   class="whitespace-nowrap rounded-lg bg-orange-50 px-3 py-2.5 text-sm font-semibold text-orange-800">
                    Profile overview
                </a>
                <a href="#order-history"
                   class="whitespace-nowrap rounded-lg px-3 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900">
                    Order history
                </a>
                <a href="shop.php"
                   class="whitespace-nowrap rounded-lg px-3 py-2.5 text-sm font-medium text-gray-600 hover:bg-gray-50 hover:text-gray-900">
                    Continue shopping
                </a>
            </nav>
        </aside>

        <div class="min-w-0 space-y-8">
            <section id="profile" class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6"
                     aria-labelledby="profile-heading">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 id="profile-heading" class="text-xl font-bold text-gray-900">Profile overview</h2>
                        <p class="mt-1 text-sm text-gray-600">Your account information and recent activity.</p>
                    </div>
                    <span class="rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-800">Active account</span>
                </div>
                <div class="mt-6 grid gap-5 sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Name</p>
                        <p class="mt-1 font-medium text-gray-900"><?= $escape($customerName) ?></p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Orders</p>
                        <p class="mt-1 font-medium text-gray-900"><?= count($orders) ?> total</p>
                    </div>
                </div>
            </section>

            <section id="order-history" class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm"
                     aria-labelledby="order-history-heading">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-5 sm:px-6">
                    <div>
                        <h2 id="order-history-heading" class="text-xl font-bold text-gray-900">Order History</h2>
                        <p class="mt-1 text-sm text-gray-600">Review your purchases and their current status.</p>
                    </div>
                    <span class="rounded-full bg-gray-100 px-3 py-1 text-sm font-semibold text-gray-700"><?= count($orders) ?> orders</span>
                </div>

                <?php if ($orders !== []): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-left">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="whitespace-nowrap px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 sm:px-6">Order ID</th>
                                    <th scope="col" class="whitespace-nowrap px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 sm:px-6">Date</th>
                                    <th scope="col" class="whitespace-nowrap px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 sm:px-6">Total</th>
                                    <th scope="col" class="whitespace-nowrap px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 sm:px-6">Status</th>
                                    <th scope="col" class="whitespace-nowrap px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500 sm:px-6"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <?php foreach ($orders as $order): ?>
                                    <?php
                                    if (!is_array($order)) {
                                        continue;
                                    }
                                    $orderId = (string) ($order['order_number'] ?? $order['id'] ?? '');
                                    $orderDate = (string) ($order['created_at'] ?? $order['date'] ?? '');
                                    $rawStatus = strtolower(trim((string) ($order['status'] ?? 'pending')));
                                    [$statusLabel, $statusClass] = $statusStyles[$rawStatus] ?? [ucfirst($rawStatus), 'bg-gray-100 text-gray-700'];
                                    $total = is_numeric($order['total_amount'] ?? $order['total'] ?? null)
                                        ? (float) ($order['total_amount'] ?? $order['total'])
                                        : 0.0;
                                    $isFailed = $rawStatus === 'failed';
                                    ?>
                                    <tr class="transition-colors hover:bg-gray-50">
                                        <td class="whitespace-nowrap px-5 py-4 text-sm font-semibold text-gray-900 sm:px-6">
                                            <?= $escape($orderId !== '' ? $orderId : '—') ?>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600 sm:px-6">
                                            <?php if ($orderDate !== '' && strtotime($orderDate) !== false): ?>
                                                <time datetime="<?= $escape($orderDate) ?>"><?= $escape(date('M j, Y', strtotime($orderDate))) ?></time>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4 text-sm font-medium text-gray-900 sm:px-6">
                                            KES <?= number_format($total, 2) ?>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4 sm:px-6">
                                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold <?= $statusClass ?>">
                                                <?= $escape($statusLabel) ?>
                                            </span>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4 text-right sm:px-6">
                                            <?php if ($isFailed && $orderId !== ''): ?>
                                                <a href="checkout.php?retry_order=<?= rawurlencode($orderId) ?>"
                                                   class="inline-flex items-center rounded-lg border border-orange-200 px-3 py-2 text-xs font-bold text-orange-800 hover:bg-orange-50 focus:outline-none focus:ring-2 focus:ring-[#ff6600]/30">
                                                    Retry Payment
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="px-6 py-14 text-center">
                        <svg class="mx-auto h-12 w-12 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                  d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>
                        </svg>
                        <h3 class="mt-4 text-lg font-bold text-gray-900">No orders yet</h3>
                        <p class="mt-2 text-sm text-gray-600">Your orders will appear here after you make a purchase.</p>
                        <a href="shop.php"
                           class="mt-5 inline-flex rounded-lg px-4 py-2.5 text-sm font-bold text-white hover:brightness-90"
                           style="background-color: var(--jengo-safety-orange);">
                            Browse the shop
                        </a>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../components/footer.php'; ?>