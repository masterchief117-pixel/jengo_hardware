<?php
/**
 * checkout.php
 *
 * Checkout page — authenticated customers only.
 *
 * GET  : Show cart summary + delivery/payment form.
 * POST : Validate form, save order (status = 'pending') + order_items,
 *        store order_id in session, redirect to the chosen payment handler.
 *
 * Payment redirects:
 *   M-Pesa   → payments/mpesa_push.php
 *   Paystack → payments/paystack_init.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Must be logged in to checkout
require_login();

if (is_system_owner()) {
    redirect('/admin/owner_dashboard.php', 'Owner accounts cannot place customer orders.', 'error');
}

if (has_any_role(['admin', 'attendant', 'employee'])) {
    redirect('/shop.php', 'Staff accounts can browse products but cannot place customer orders.', 'error');
}

$pdo = db();

// ══════════════════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════════════════

/**
 * Generate a unique, human-readable order number.
 * Format: ORD-YYYYMMDD-XXXXX  (e.g. ORD-20261104-00042)
 */
function generate_order_number(\PDO $pdo): string
{
    $date   = date('Ymd');
    $prefix = "ORD-{$date}-";

    // Find the highest sequence used today
    $stmt = $pdo->prepare(
        "SELECT order_number FROM orders
         WHERE order_number LIKE ? ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    $seq = 1;
    if ($last) {
        $parts = explode('-', $last);
        $seq   = (int) end($parts) + 1;
    }

    return $prefix . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
}

/**
 * Load and validate the session cart against live DB stock.
 * Returns an array of enriched cart rows, or empty array if cart is invalid.
 */
function load_checkout_cart(\PDO $pdo): array
{
    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        return [];
    }

    $ids = array_filter(array_keys($_SESSION['cart']), fn($id) => is_int($id) && $id > 0);
    if (empty($ids)) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, name, price, stock_qty
         FROM   products
         WHERE  id IN ($placeholders) AND is_active = 1"
    );
    $stmt->execute($ids);
    $rows = $stmt->fetchAll();

    $items = [];
    foreach ($rows as $row) {
        $pid = (int) $row['id'];
        $qty = (int) ($_SESSION['cart'][$pid] ?? 0);
        $qty = min($qty, (int)$row['stock_qty']);
        if ($qty < 1) continue;

        $items[] = [
            'id'       => $pid,
            'name'     => $row['name'],
            'price'    => (float) $row['price'],
            'stock'    => (int)   $row['stock_qty'],
            'qty'      => $qty,
            'subtotal' => (float) $row['price'] * $qty,
        ];
    }
    return $items;
}

// ══════════════════════════════════════════════════════════════════════════
// LOAD CART (needed for both GET display and POST validation)
// ══════════════════════════════════════════════════════════════════════════
$cartItems = load_checkout_cart($pdo);

if (empty($cartItems)) {
    redirect('/cart.php', 'Your cart is empty. Add some items first.', 'warning');
}

$cartSubtotal   = array_sum(array_column($cartItems, 'subtotal'));
$shippingFee    = 0.0;
$freeShipThresh = 5000.0;
$errors         = [];
$old            = [];
$mpesaPostPhone = null;

// ══════════════════════════════════════════════════════════════════════════
// POST HANDLER — save order and prepare the payment handoff
// ══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $old = [
        'fulfillment'    => $_POST['fulfillment']    ?? '',
        'payment_method' => $_POST['payment_method'] ?? '',
        'notes'          => trim($_POST['notes']     ?? ''),
        'phone'          => trim($_POST['phone']     ?? ''),
    ];

    // ── Validate fulfillment ──────────────────────────────────────────────
    $validFulfillment = ['delivery', 'pickup'];
    if (!in_array($old['fulfillment'], $validFulfillment, true)) {
        $errors['fulfillment'] = 'Please select Delivery or Pickup.';
    }

    // ── Validate payment method ───────────────────────────────────────────
    $validPayment = ['mpesa', 'paystack'];
    if (!in_array($old['payment_method'], $validPayment, true)) {
        $errors['payment_method'] = 'Please select a payment method.';
    }

    // ── Validate phone for M-Pesa ─────────────────────────────────────────
    $normPhone = null;
    if ($old['payment_method'] === 'mpesa') {
        $stripped = preg_replace('/[\s\-().]/', '', $old['phone']);
        if (str_starts_with($stripped, '+254') && strlen($stripped) === 13) {
            $normPhone = '254' . substr($stripped, 4);
        } elseif (str_starts_with($stripped, '254') && strlen($stripped) === 12) {
            $normPhone = $stripped;
        } elseif (str_starts_with($stripped, '0') && strlen($stripped) === 10) {
            $normPhone = '254' . substr($stripped, 1);
        }

        if (!$normPhone || !preg_match('/^254(7[0-9]{8}|1[0-1][0-9]{7})$/', $normPhone)) {
            $errors['phone'] = 'Enter a valid Kenyan M-Pesa number (e.g. 0712 345 678).';
        }
    }

    // ── Notes length ──────────────────────────────────────────────────────
    if (mb_strlen($old['notes']) > 500) {
        $errors['notes'] = 'Notes must be 500 characters or fewer.';
    }

    // ── Compute shipping ──────────────────────────────────────────────────
    if ($old['fulfillment'] === 'pickup') {
        $shippingFee = 0.0;
    } else {
        $shippingFee = $cartSubtotal >= $freeShipThresh ? 0.0 : 200.0;
    }
    $orderTotal = $cartSubtotal + $shippingFee;

    // ── Save order ────────────────────────────────────────────────────────
    if (empty($errors)) {
        $pdo->beginTransaction();

        try {
            $orderNumber = generate_order_number($pdo);
            $notesText   = $old['notes'] !== ''
                ? "[{$old['fulfillment']}] " . $old['notes']
                : "[{$old['fulfillment']}]";

            // Insert order header
            $pdo->prepare(
                'INSERT INTO orders
                    (customer_id, order_number, status, total_amount, notes)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                get_user_id(),
                $orderNumber,
                'pending',
                $orderTotal,
                $notesText,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            // Insert order_items + decrement stock
            $insertItem = $pdo->prepare(
                'INSERT INTO order_items
                    (order_id, product_id, product_name, unit_price, quantity, subtotal)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $decrementStock = $pdo->prepare(
                'UPDATE products SET stock_qty = stock_qty - ?
                 WHERE id = ? AND stock_qty >= ?'
            );

            foreach ($cartItems as $item) {
                $insertItem->execute([
                    $orderId,
                    $item['id'],
                    $item['name'],
                    $item['price'],
                    $item['qty'],
                    $item['subtotal'],
                ]);

                $decrementStock->execute([
                    $item['qty'],
                    $item['id'],
                    $item['qty'],
                ]);
            }

            $pdo->commit();

            // Stash order context in session for payment pages
            $_SESSION['pending_order'] = [
                'order_id'       => $orderId,
                'order_number'   => $orderNumber,
                'total_amount'   => $orderTotal,
                'payment_method' => $old['payment_method'],
                'phone'          => $normPhone ?? '',
                'fulfillment'    => $old['fulfillment'],
            ];

            // Clear cart
            $_SESSION['cart'] = [];

            if ($old['payment_method'] === 'mpesa') {
                $_SESSION['mpesa_push_token'] = bin2hex(random_bytes(32));
                $mpesaPostPhone = $normPhone;
            } else {
                redirect('/payments/paystack_init.php');
            }

        } catch (\Exception $e) {
            $pdo->rollBack();

            if (env('APP_DEBUG', false) === true) {
                throw $e;
            }
            $errors['general'] = 'Could not place your order. Please try again.';
        }
    }

    // Recompute shipping if validation failed so summary stays accurate
    if ($old['fulfillment'] === 'pickup') {
        $shippingFee = 0.0;
    } else {
        $shippingFee = $cartSubtotal >= $freeShipThresh ? 0.0 : 200.0;
    }
    $orderTotal = $cartSubtotal + $shippingFee;
}

// Default shipping for GET
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $shippingFee = $cartSubtotal >= $freeShipThresh ? 0.0 : 200.0;
    $orderTotal  = $cartSubtotal + $shippingFee;
}

// Pre-fill phone from user profile
$userPhone = $_SESSION['user_phone'] ?? '';
if ($userPhone === '') {
    $row = $pdo->prepare('SELECT phone FROM users WHERE id = ? LIMIT 1');
    $row->execute([get_user_id()]);
    $userPhone = $row->fetchColumn() ?: '';
}

if ($mpesaPostPhone !== null) {
    $mpesaPushUrl = rtrim((string) env('APP_URL', ''), '/') . '/payments/mpesa_push.php';
    $safePushUrl = htmlspecialchars($mpesaPushUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safePhone = htmlspecialchars($mpesaPostPhone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeToken = htmlspecialchars(
        (string) $_SESSION['mpesa_push_token'],
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Connecting to M-Pesa</title>
    </head>
    <body>
        <p>Connecting securely to M-Pesa…</p>
        <form id="mpesa-push-handoff" method="post" action="<?= $safePushUrl ?>">
            <input type="hidden" name="phone" value="<?= $safePhone ?>">
            <input type="hidden" name="csrf_token" value="<?= $safeToken ?>">
            <button type="submit">Continue to M-Pesa</button>
        </form>
        <script>
            document.getElementById('mpesa-push-handoff').submit();
        </script>
    </body>
    </html>
    <?php
    exit;
}

$pageTitle = 'Checkout';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════
     CHECKOUT PAGE
══════════════════════════════════════════════════════════════════════ -->

<!-- Breadcrumb -->
<nav class="flex items-center gap-2 text-sm text-gray-500 mb-6"
     aria-label="Breadcrumb">
    <a href="<?= $base ?>/index.php"  class="hover:text-orange transition-colors">Home</a>
    <span aria-hidden="true">/</span>
    <a href="<?= $base ?>/shop.php"   class="hover:text-orange transition-colors">Shop</a>
    <span aria-hidden="true">/</span>
    <a href="<?= $base ?>/cart.php"   class="hover:text-orange transition-colors">Cart</a>
    <span aria-hidden="true">/</span>
    <span class="text-gray-800 font-medium" aria-current="page">Checkout</span>
</nav>

<h1 class="text-2xl font-extrabold text-gray-900 mb-8">Checkout</h1>

<!-- General error -->
<?php if (isset($errors['general'])): ?>
<div class="alert alert-error mb-6" role="alert">
    <svg class="w-5 h-5 flex-shrink-0" xmlns="http://www.w3.org/2000/svg"
         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
         aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <span><?= htmlspecialchars($errors['general']) ?></span>
</div>
<?php endif; ?>

<!-- Progress steps -->
<ol class="flex items-center gap-0 mb-10 text-xs font-semibold"
    aria-label="Checkout progress">
    <?php
    $checkoutSteps = [
        ['Cart',     '/cart.php',     true],
        ['Checkout', '/checkout.php', true],
        ['Payment',  '#',             false],
        ['Done',     '#',             false],
    ];
    $lastIdx = count($checkoutSteps) - 1;
    foreach ($checkoutSteps as $si => [$label, $href, $active]):
        $isCurrent = $label === 'Checkout';
    ?>
    <li class="flex items-center <?= $si < $lastIdx ? 'flex-1' : '' ?>">
        <a href="<?= $href ?>"
           class="flex items-center gap-1.5
                  <?= $active && !$isCurrent ? 'text-orange' : ($isCurrent ? 'text-orange font-bold' : 'text-gray-300') ?>"
           <?= $isCurrent ? 'aria-current="step"' : '' ?>>
            <span class="w-6 h-6 rounded-full border-2 flex items-center justify-center
                         <?= $active   ? 'border-orange bg-orange text-white'
                                       : 'border-gray-200 text-gray-300' ?>
                         text-xs font-bold leading-none">
                <?= $si + 1 ?>
            </span>
            <span class="hidden sm:inline"><?= $label ?></span>
        </a>
        <?php if ($si < $lastIdx): ?>
        <div class="flex-1 h-px mx-2
                    <?= $active ? 'bg-orange' : 'bg-gray-200' ?>"></div>
        <?php endif; ?>
    </li>
    <?php endforeach; ?>
</ol>


<form method="POST" action="checkout.php"
      data-submit-guard
      aria-label="Checkout form">

<div class="flex flex-col lg:flex-row gap-8 items-start">

    <!-- ══════════════════════════════════════════════════════════════════
         LEFT COLUMN — Form fields
    ══════════════════════════════════════════════════════════════════════ -->
    <div class="flex-1 min-w-0 space-y-6">

        <!-- ── Fulfilment method ──────────────────────────────────────── -->
        <fieldset class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <legend class="text-base font-bold text-gray-900 mb-4 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-orange text-white text-xs
                             font-bold flex items-center justify-center"
                      aria-hidden="true">1</span>
                Fulfilment
            </legend>

            <?php if (isset($errors['fulfillment'])): ?>
            <p class="form-error-msg mb-3" role="alert">
                <?= htmlspecialchars($errors['fulfillment']) ?>
            </p>
            <?php endif; ?>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                <!-- Delivery -->
                <label class="fulfillment-option relative flex items-start gap-3
                              p-4 border-2 rounded-xl cursor-pointer transition-all
                              <?= ($old['fulfillment'] ?? '') === 'delivery' || empty($old)
                                  ? 'border-orange bg-orange-subtle'
                                  : 'border-gray-200 hover:border-orange/50' ?>">
                    <input type="radio" name="fulfillment" value="delivery"
                           class="mt-0.5 accent-orange"
                           <?= ($old['fulfillment'] ?? 'delivery') === 'delivery' ? 'checked' : '' ?>
                           required />
                    <div>
                        <p class="font-semibold text-gray-800 text-sm">
                            🚚 Home Delivery
                        </p>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Same-day within Nairobi CBD ·
                            <?= $cartSubtotal >= $freeShipThresh
                                ? '<span class="text-green-600 font-semibold">FREE</span>'
                                : 'KES 200' ?>
                        </p>
                    </div>
                </label>

                <!-- Pickup -->
                <label class="fulfillment-option relative flex items-start gap-3
                              p-4 border-2 rounded-xl cursor-pointer transition-all
                              <?= ($old['fulfillment'] ?? '') === 'pickup'
                                  ? 'border-orange bg-orange-subtle'
                                  : 'border-gray-200 hover:border-orange/50' ?>">
                    <input type="radio" name="fulfillment" value="pickup"
                           class="mt-0.5 accent-orange"
                           <?= ($old['fulfillment'] ?? '') === 'pickup' ? 'checked' : '' ?> />
                    <div>
                        <p class="font-semibold text-gray-800 text-sm">
                            🏪 In-Store Pickup
                        </p>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Kirinyaga Road, Nairobi CBD ·
                            <span class="text-green-600 font-semibold">FREE</span>
                        </p>
                    </div>
                </label>

            </div>
        </fieldset>


        <!-- ── Payment method ─────────────────────────────────────────── -->
        <fieldset class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <legend class="text-base font-bold text-gray-900 mb-4 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-orange text-white text-xs
                             font-bold flex items-center justify-center"
                      aria-hidden="true">2</span>
                Payment Method
            </legend>

            <?php if (isset($errors['payment_method'])): ?>
            <p class="form-error-msg mb-3" role="alert">
                <?= htmlspecialchars($errors['payment_method']) ?>
            </p>
            <?php endif; ?>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                <!-- M-Pesa -->
                <label class="payment-option relative flex items-start gap-3
                              p-4 border-2 rounded-xl cursor-pointer transition-all
                              <?= ($old['payment_method'] ?? '') === 'mpesa'
                                  ? 'border-green-500 bg-green-50'
                                  : 'border-gray-200 hover:border-green-400' ?>">
                    <input type="radio" name="payment_method" value="mpesa"
                           id="pay-mpesa"
                           class="mt-0.5 accent-green-600"
                           <?= ($old['payment_method'] ?? '') === 'mpesa' ? 'checked' : '' ?>
                           required />
                    <div>
                        <p class="font-bold text-gray-800 text-sm">M-Pesa</p>
                        <p class="text-xs text-gray-500 mt-0.5">
                            STK Push to your phone — instant confirmation
                        </p>
                    </div>
                    <span class="absolute top-3 right-3 text-xs font-bold
                                 bg-green-100 text-green-700 px-1.5 py-0.5 rounded">
                        Popular
                    </span>
                </label>

                <!-- Paystack -->
                <label class="payment-option relative flex items-start gap-3
                              p-4 border-2 rounded-xl cursor-pointer transition-all
                              <?= ($old['payment_method'] ?? '') === 'paystack'
                                  ? 'border-blue-500 bg-blue-50'
                                  : 'border-gray-200 hover:border-blue-400' ?>">
                    <input type="radio" name="payment_method" value="paystack"
                           id="pay-paystack"
                           class="mt-0.5 accent-blue-600"
                           <?= ($old['payment_method'] ?? '') === 'paystack' ? 'checked' : '' ?> />
                    <div>
                        <p class="font-bold text-gray-800 text-sm">Paystack</p>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Card, M-Pesa, or bank transfer via Paystack
                        </p>
                    </div>
                </label>

            </div>

            <!-- M-Pesa phone field (shown only when M-Pesa selected) -->
            <div id="mpesa-phone-field"
                 class="mt-4 <?= ($old['payment_method'] ?? 'mpesa') === 'mpesa' ? '' : 'hidden' ?>">
                <div class="form-group mb-0">
                    <label for="mpesa-phone" class="form-label">
                        M-Pesa Phone Number
                        <span class="required" aria-hidden="true">*</span>
                    </label>
                    <input type="tel"
                           id="mpesa-phone"
                           name="phone"
                           class="form-input <?= isset($errors['phone']) ? 'is-error' : '' ?>"
                           value="<?= htmlspecialchars($old['phone'] ?? $userPhone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                           placeholder="0712 345 678 or 254712345678"
                           maxlength="16"
                           inputmode="tel"
                           autocomplete="tel"
                           data-phone-input
                           data-error-id="err-mpesa-phone"
                           aria-describedby="err-mpesa-phone mpesa-phone-hint" />
                    <p id="err-mpesa-phone"
                       class="form-error-msg <?= isset($errors['phone']) ? '' : 'hidden' ?>"
                       role="alert">
                        <?= htmlspecialchars($errors['phone'] ?? '') ?>
                    </p>
                    <p id="mpesa-phone-hint" class="form-hint">
                        Editable number for the STK Push. Use 07XXXXXXXX, 01XXXXXXXX,
                        2547XXXXXXXX, 2541XXXXXXXX, or +254 format.
                    </p>
                </div>
            </div>

        </fieldset>


        <!-- ── Order notes ────────────────────────────────────────────── -->
        <fieldset class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6">
            <legend class="text-base font-bold text-gray-900 mb-4 flex items-center gap-2">
                <span class="w-6 h-6 rounded-full bg-orange text-white text-xs
                             font-bold flex items-center justify-center"
                      aria-hidden="true">3</span>
                Additional Notes
                <span class="text-sm font-normal text-gray-400">(optional)</span>
            </legend>

            <textarea id="notes"
                      name="notes"
                      class="form-textarea <?= isset($errors['notes']) ? 'is-error' : '' ?>"
                      rows="3"
                      maxlength="500"
                      placeholder="Delivery instructions, landmarks, or any special requests…"
                      aria-describedby="<?= isset($errors['notes']) ? 'err-notes' : '' ?>"><?= htmlspecialchars($old['notes'] ?? '') ?></textarea>

            <?php if (isset($errors['notes'])): ?>
            <p id="err-notes" class="form-error-msg" role="alert">
                <?= htmlspecialchars($errors['notes']) ?>
            </p>
            <?php endif; ?>
            <p class="text-xs text-gray-400 mt-1 text-right">
                <span id="notes-count">0</span> / 500
            </p>
        </fieldset>

    </div>
    <!-- /left column -->


    <!-- ══════════════════════════════════════════════════════════════════
         RIGHT COLUMN — Cart summary + place order
    ══════════════════════════════════════════════════════════════════════ -->
    <div class="w-full lg:w-80 xl:w-96 flex-shrink-0">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100
                    overflow-hidden sticky top-20">

            <!-- Summary header -->
            <div class="px-6 py-4 border-b border-gray-100 flex items-center
                        justify-between">
                <h2 class="text-base font-bold text-gray-900">Order Summary</h2>
                <span class="text-xs text-gray-400">
                    <?= count($cartItems) ?> item<?= count($cartItems) !== 1 ? 's' : '' ?>
                </span>
            </div>

            <!-- Item list -->
            <ul class="divide-y divide-gray-50 max-h-60 overflow-y-auto"
                role="list" aria-label="Items in order">
                <?php foreach ($cartItems as $item): ?>
                <li class="flex items-center gap-3 px-5 py-3">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-800 truncate">
                            <?= htmlspecialchars($item['name']) ?>
                        </p>
                        <p class="text-xs text-gray-400">
                            <?= $item['qty'] ?> × KES <?= number_format($item['price'], 2) ?>
                        </p>
                    </div>
                    <p class="text-sm font-semibold text-gray-700 whitespace-nowrap flex-shrink-0">
                        KES <?= number_format($item['subtotal'], 2) ?>
                    </p>
                </li>
                <?php endforeach; ?>
            </ul>

            <!-- Totals -->
            <div class="px-5 py-4 space-y-2.5 text-sm border-t border-gray-100">

                <div class="flex justify-between text-gray-600">
                    <span>Subtotal</span>
                    <span class="font-medium text-gray-800">
                        KES <?= number_format($cartSubtotal, 2) ?>
                    </span>
                </div>

                <div class="flex justify-between text-gray-600" id="shipping-row">
                    <span>Delivery</span>
                    <span class="font-medium" id="shipping-display">
                        <?php if ($shippingFee === 0.0): ?>
                            <span class="text-green-600 font-semibold">FREE</span>
                        <?php else: ?>
                            KES <?= number_format($shippingFee, 2) ?>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="border-t border-gray-100 pt-3 flex justify-between
                            items-center">
                    <span class="font-bold text-gray-900 text-base">Total</span>
                    <span class="text-xl font-extrabold text-orange"
                          id="order-total">
                        KES <?= number_format($orderTotal, 2) ?>
                    </span>
                </div>
            </div>

            <!-- Submit -->
            <div class="px-5 pb-5">
                <button type="submit"
                        class="btn btn-primary btn-block btn-lg"
                        data-loading-text="Placing order…">
                    <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor"
                         stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9 12l2 2 4-4m5.618-4.016A11.955
                                 11.955 0 0112 2.944a11.955 11.955
                                 0 01-8.618 3.04A12.02 12.02 0
                                 003 9c0 5.591 3.824 10.29 9 11.622
                                 5.176-1.332 9-6.03 9-11.622
                                 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                    <span id="submit-label">Place Order</span>
                </button>

                <p class="text-center text-xs text-gray-400 mt-3">
                    🔒 Your payment is processed securely.
                    By placing this order you agree to our
                    <a href="#" class="underline hover:text-gray-600">Terms</a>.
                </p>
            </div>

        </div><!-- /sticky card -->
    </div><!-- /right column -->

</div><!-- /flex layout -->
</form>


<!-- Page-specific JS ────────────────────────────────────────────────── -->
<script>
(function () {
    var CART_SUBTOTAL  = <?= json_encode($cartSubtotal) ?>;
    var SHIPPING_RATE  = 200;
    var FREE_THRESHOLD = <?= json_encode($freeShipThresh) ?>;

    // ── Fulfilment radio — update border highlight & recalculate shipping
    var fulfillmentInputs = document.querySelectorAll('[name="fulfillment"]');
    fulfillmentInputs.forEach(function (radio) {
        radio.addEventListener('change', function () {
            // Update card borders
            document.querySelectorAll('.fulfillment-option').forEach(function (el) {
                el.classList.remove('border-orange', 'bg-orange-subtle');
                el.classList.add('border-gray-200');
            });
            var card = radio.closest('.fulfillment-option');
            if (card) {
                card.classList.remove('border-gray-200');
                card.classList.add('border-orange', 'bg-orange-subtle');
            }
            recalculateTotals();
        });
    });

    // ── Payment radio — update border + show/hide M-Pesa phone field
    var paymentInputs = document.querySelectorAll('[name="payment_method"]');
    var mpesaField    = document.getElementById('mpesa-phone-field');
    var mpesaInput    = document.getElementById('mpesa-phone');
    var checkoutForm  = document.querySelector('form[aria-label="Checkout form"]');

    function normalizeMpesaPhone(value) {
        var phone = value.replace(/[\s\-().]/g, '');
        if (phone.startsWith('+254')) {
            phone = phone.slice(1);
        } else if (phone.startsWith('0') && phone.length === 10) {
            phone = '254' + phone.slice(1);
        }
        return /^254(7[0-9]{8}|1[0-1][0-9]{7})$/.test(phone) ? phone : null;
    }

    if (mpesaInput && checkoutForm) {
        checkoutForm.addEventListener('submit', function (event) {
            var selectedPayment = document.querySelector('[name="payment_method"]:checked');
            if (!selectedPayment || selectedPayment.value !== 'mpesa') return;

            var normalizedPhone = normalizeMpesaPhone(mpesaInput.value.trim());
            if (!normalizedPhone) {
                event.preventDefault();
                mpesaInput.setCustomValidity(
                    'Enter a valid Kenyan mobile number, such as 0712 345 678 or 254712345678.'
                );
                mpesaInput.reportValidity();
                return;
            }
            mpesaInput.setCustomValidity('');
            mpesaInput.value = normalizedPhone;
        });
        mpesaInput.addEventListener('input', function () {
            mpesaInput.setCustomValidity('');
        });
    }

    paymentInputs.forEach(function (radio) {
        radio.addEventListener('change', function () {
            // Update card borders
            document.querySelectorAll('.payment-option').forEach(function (el) {
                el.classList.remove('border-green-500', 'bg-green-50',
                                    'border-blue-500', 'bg-blue-50');
                el.classList.add('border-gray-200');
            });
            var card = radio.closest('.payment-option');
            if (card) {
                card.classList.remove('border-gray-200');
                if (radio.value === 'mpesa') {
                    card.classList.add('border-green-500', 'bg-green-50');
                } else {
                    card.classList.add('border-blue-500', 'bg-blue-50');
                }
            }

            // Toggle M-Pesa phone
            if (mpesaField) {
                mpesaField.classList.toggle('hidden', radio.value !== 'mpesa');
                if (mpesaInput) mpesaInput.required = (radio.value === 'mpesa');
            }

            // Update submit label
            var label = document.getElementById('submit-label');
            if (label) {
                label.textContent = radio.value === 'mpesa'
                    ? 'Place Order & Pay with M-Pesa'
                    : 'Place Order & Pay with Paystack';
            }
        });
    });

    // ── Recalculate shipping & total in sidebar
    function recalculateTotals() {
        var fulfillment = document.querySelector('[name="fulfillment"]:checked');
        var isPickup    = fulfillment && fulfillment.value === 'pickup';
        var shipping    = 0;

        if (!isPickup) {
            shipping = CART_SUBTOTAL >= FREE_THRESHOLD ? 0 : SHIPPING_RATE;
        }

        var total         = CART_SUBTOTAL + shipping;
        var shippingEl    = document.getElementById('shipping-display');
        var totalEl       = document.getElementById('order-total');

        if (shippingEl) {
            shippingEl.innerHTML = (shipping === 0)
                ? '<span class="text-green-600 font-semibold">FREE</span>'
                : 'KES ' + total.toLocaleString('en-KE', { minimumFractionDigits: 2 });
        }
        if (totalEl) {
            totalEl.textContent = 'KES ' + total.toLocaleString('en-KE',
                { minimumFractionDigits: 2 });
        }
    }

    // ── Notes character counter
    var notesArea  = document.getElementById('notes');
    var notesCount = document.getElementById('notes-count');
    if (notesArea && notesCount) {
        notesCount.textContent = notesArea.value.length;
        notesArea.addEventListener('input', function () {
            notesCount.textContent = notesArea.value.length;
        });
    }

    // ── Trigger initial state in case of POST repopulation
    var checkedPayment = document.querySelector('[name="payment_method"]:checked');
    if (checkedPayment) checkedPayment.dispatchEvent(new Event('change'));
    var checkedFulfil  = document.querySelector('[name="fulfillment"]:checked');
    if (checkedFulfil) checkedFulfil.dispatchEvent(new Event('change'));

})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
