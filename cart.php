<?php
/**
 * cart.php
 *
 * Shopping cart — session-based.
 * $_SESSION['cart'] = [ product_id => qty, ... ]
 *
 * Handles three POST actions:
 *   update  — change quantity of a single item
 *   remove  — delete a single item
 *   clear   — empty the entire cart
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/product_images.php';

if (is_system_owner()) {
    redirect('/admin/owner_dashboard.php', 'Owner accounts cannot use the shopping cart.', 'error');
}

if (has_any_role(['admin', 'attendant', 'employee'])) {
    redirect('/shop.php', 'Staff accounts can browse products but cannot use the customer cart.', 'error');
}

$pdo = db();

// ══════════════════════════════════════════════════════════════════════════
// CART MUTATION HANDLER (POST)
// ══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action']    ?? '';
    $productId = (int) ($_POST['product_id'] ?? 0);

    // Initialise cart if missing
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    switch ($action) {

        case 'update':
            $qty = max(1, min(99, (int) ($_POST['qty'] ?? 1)));
            if ($productId > 0 && array_key_exists($productId, $_SESSION['cart'])) {
                // Verify stock before updating
                $stmt = $pdo->prepare(
                    'SELECT stock_qty FROM products
                     WHERE id = ? AND is_active = 1 LIMIT 1'
                );
                $stmt->execute([$productId]);
                $row = $stmt->fetch();

                if ($row) {
                    $qty = min($qty, (int) $row['stock_qty']);
                    $_SESSION['cart'][$productId] = $qty;
                }
            }
            redirect('/cart.php', 'Cart updated.', 'success');

        case 'remove':
            if ($productId > 0) {
                unset($_SESSION['cart'][$productId]);
            }
            redirect('/cart.php', 'Item removed from cart.', 'info');

        case 'clear':
            $_SESSION['cart'] = [];
            redirect('/cart.php', 'Cart cleared.', 'info');
    }

    // Unknown action — just reload
    redirect('/cart.php');
}

// ══════════════════════════════════════════════════════════════════════════
// LOAD CART ITEMS FROM DATABASE
// ══════════════════════════════════════════════════════════════════════════
$cartItems   = [];   // enriched rows for display
$cartTotal   = 0.0;
$cartCount   = 0;

if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    // Build a safe IN clause using only the IDs from the session
    $ids = array_keys($_SESSION['cart']);
    $ids = array_filter($ids, fn($id) => is_int($id) && $id > 0);

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare(
            "SELECT p.id, p.name, p.price, p.stock_qty, p.image_url,
                    c.name AS category_name
             FROM   products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE  p.id IN ($placeholders) AND p.is_active = 1"
        );
        $stmt->execute($ids);
        $dbProducts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Index by id for quick lookup
        $dbIndex = [];
        foreach ($dbProducts as $row) {
            $dbIndex[(int)$row['id']] = $row;
        }

        foreach ($_SESSION['cart'] as $id => $qty) {
            $id  = (int) $id;
            $qty = (int) $qty;

            if (!isset($dbIndex[$id])) {
                // Product removed/deactivated — silently drop from cart
                unset($_SESSION['cart'][$id]);
                continue;
            }

            $product  = $dbIndex[$id];
            $inStock  = (int) $product['stock_qty'];
            $displayImage = product_image_src($product['image_url'] ?? '');

            // Cap qty at current stock
            if ($qty > $inStock) {
                $qty = $inStock;
                $_SESSION['cart'][$id] = $qty;
            }

            if ($qty < 1) {
                unset($_SESSION['cart'][$id]);
                continue;
            }

            $subtotal     = (float) $product['price'] * $qty;
            $cartTotal   += $subtotal;
            $cartCount   += $qty;

            $cartItems[] = [
                'id'            => $id,
                'name'          => $product['name'],
                'price'         => (float) $product['price'],
                'stock_qty'     => $inStock,
                'image_url'     => $product['image_url'],
                'display_image' => $displayImage,
                'category_name' => $product['category_name'] ?? '',
                'qty'           => $qty,
                'subtotal'      => $subtotal,
            ];
        }
    }
}

// Simple shipping estimate
$shippingFee     = $cartTotal > 0 ? 200.0 : 0.0;   // flat KES 200
$freeShipThresh  = 5000.0;
if ($cartTotal >= $freeShipThresh) {
    $shippingFee = 0.0;
}
$orderTotal = $cartTotal + $shippingFee;

// ── Page meta ─────────────────────────────────────────────────────────────
$pageTitle = 'Shopping Cart';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════
     CART PAGE
══════════════════════════════════════════════════════════════════════ -->

<!-- Breadcrumb -->
<nav class="flex items-center gap-2 text-sm text-gray-500 mb-6"
     aria-label="Breadcrumb">
    <a href="<?= $base ?>/index.php"
       class="hover:text-brown transition-colors">Home</a>
    <span aria-hidden="true">/</span>
    <a href="<?= $base ?>/shop.php"
       class="hover:text-orange transition-colors">Shop</a>
    <span aria-hidden="true">/</span>
    <span class="text-gray-800 font-medium" aria-current="page">Cart</span>
</nav>

<h1 class="text-2xl font-extrabold text-gray-900 mb-6">
    Shopping Cart
    <?php if ($cartCount > 0): ?>
    <span class="ml-2 text-base font-normal text-gray-400">
        (<?= $cartCount ?> item<?= $cartCount !== 1 ? 's' : '' ?>)
    </span>
    <?php endif; ?>
</h1>


<?php if (empty($cartItems)): ?>
<!-- ══════════════════════════════════════════════════════════════════════
     EMPTY CART STATE
══════════════════════════════════════════════════════════════════════ -->
<div class="bg-white rounded-2xl border border-dashed border-gray-200
            py-24 text-center" role="status">
    <div class="text-8xl mb-6" aria-hidden="true">🛒</div>
    <h2 class="text-xl font-bold text-gray-700 mb-2">Your cart is empty</h2>
    <p class="text-gray-400 text-sm mb-8 max-w-xs mx-auto">
        Browse our products and add items to get started.
    </p>
    <a href="<?= $base ?>/shop.php" class="btn btn-primary btn-lg">
        Continue Shopping
    </a>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════════════════════
     CART TABLE + ORDER SUMMARY
══════════════════════════════════════════════════════════════════════ -->

<div class="flex flex-col lg:flex-row gap-8 items-start">

    <!-- ── LEFT: Cart item list ───────────────────────────────────────── -->
    <div class="flex-1 min-w-0">

        <!-- Clear cart -->
        <div class="flex justify-end mb-3">
            <form method="POST" action="cart.php">
                <input type="hidden" name="action" value="clear">
                <button type="submit"
                        class="text-xs text-red-400 hover:text-red-600
                               transition-colors font-medium flex items-center gap-1"
                        data-confirm="Remove all items from your cart?">
                    <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor"
                         stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M19 7l-.867 12.142A2 2 0 0116.138
                                 21H7.862a2 2 0 01-1.995-1.858L5 7m5
                                 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1
                                 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    Clear cart
                </button>
            </form>
        </div>

        <!-- Items — desktop table -->
        <div class="hidden sm:block bg-white rounded-2xl shadow-sm
                    border border-gray-100 overflow-hidden">
            <table class="w-full text-sm" aria-label="Cart items">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr>
                        <th class="text-left px-5 py-3.5 text-xs font-semibold
                                   text-gray-500 uppercase tracking-wider"
                            scope="col">Product</th>
                        <th class="text-center px-4 py-3.5 text-xs font-semibold
                                   text-gray-500 uppercase tracking-wider"
                            scope="col">Price</th>
                        <th class="text-center px-4 py-3.5 text-xs font-semibold
                                   text-gray-500 uppercase tracking-wider"
                            scope="col">Qty</th>
                        <th class="text-right px-5 py-3.5 text-xs font-semibold
                                   text-gray-500 uppercase tracking-wider"
                            scope="col">Subtotal</th>
                        <th class="px-3 py-3.5" scope="col">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($cartItems as $item): ?>
                    <tr class="hover:bg-gray-50/50 transition-colors">

                        <!-- Product info -->
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <!-- Thumbnail -->
                                <div class="w-14 h-14 rounded-lg bg-gray-100
                                            overflow-hidden flex-shrink-0">
                                    <?php if ($item['display_image'] !== ''): ?>
                                        <img src="<?= htmlspecialchars($item['display_image'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                             alt="<?= htmlspecialchars($item['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                             class="w-full h-full object-cover"
                                             loading="lazy" />
                                    <?php else: ?>
                                        <div class="w-full h-full flex items-center
                                                    justify-center text-gray-300"
                                             aria-hidden="true">
                                            <svg class="w-7 h-7" xmlns="http://www.w3.org/2000/svg"
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

                                <div>
                                    <p class="font-semibold text-gray-800 line-clamp-2">
                                        <?= htmlspecialchars($item['name']) ?>
                                    </p>
                                    <?php if ($item['category_name']): ?>
                                    <p class="text-xs text-gray-400 mt-0.5">
                                        <?= htmlspecialchars($item['category_name']) ?>
                                    </p>
                                    <?php endif; ?>
                                    <?php if ($item['stock_qty'] <= 5): ?>
                                    <p class="text-xs text-amber-600 font-medium mt-0.5">
                                        Only <?= $item['stock_qty'] ?> in stock
                                    </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>

                        <!-- Unit price -->
                        <td class="px-4 py-4 text-center text-gray-700 font-medium
                                   whitespace-nowrap">
                            KES <?= number_format($item['price'], 2) ?>
                        </td>

                        <!-- Qty stepper -->
                        <td class="px-4 py-4 text-center">
                            <form method="POST" action="cart.php"
                                  class="inline-block update-qty-form">
                                <input type="hidden" name="action"     value="update">
                                <input type="hidden" name="product_id" value="<?= $item['id'] ?>">
                                <div class="qty-stepper mx-auto">
                                    <button type="button"
                                            data-action="decrease"
                                            aria-label="Decrease quantity">−</button>
                                    <input type="number"
                                           name="qty"
                                           value="<?= $item['qty'] ?>"
                                           min="1"
                                           max="<?= $item['stock_qty'] ?>"
                                           aria-label="Quantity for <?= htmlspecialchars($item['name'], ENT_QUOTES) ?>" />
                                    <button type="button"
                                            data-action="increase"
                                            aria-label="Increase quantity">+</button>
                                </div>
                            </form>
                        </td>

                        <!-- Subtotal -->
                        <td class="px-5 py-4 text-right font-bold text-orange
                                   whitespace-nowrap">
                            KES <?= number_format($item['subtotal'], 2) ?>
                        </td>

                        <!-- Remove -->
                        <td class="px-3 py-4 text-center">
                            <form method="POST" action="cart.php">
                                <input type="hidden" name="action"     value="remove">
                                <input type="hidden" name="product_id" value="<?= $item['id'] ?>">
                                <button type="submit"
                                        class="inline-flex items-center gap-1 text-sm font-medium
                                               text-red-600 hover:text-red-800
                                               transition-colors p-1 rounded"
                                        aria-label="Drop <?= htmlspecialchars($item['name'], ENT_QUOTES) ?> from cart"
                                        data-confirm="Remove this item from your cart?">
                                    <span>Drop</span>
                                    <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg"
                                         fill="none" viewBox="0 0 24 24"
                                         stroke="currentColor" stroke-width="1.8"
                                         aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                              d="M19 7l-.867 12.142A2 2 0 0116.138
                                                 21H7.862a2 2 0 01-1.995-1.858L5
                                                 7m5 4v6m4-6v6m1-10V4a1 1 0
                                                 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </form>
                        </td>

                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Items — mobile card stack -->
        <div class="sm:hidden space-y-4">
            <?php foreach ($cartItems as $item): ?>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                <div class="flex items-start gap-3 mb-3">
                    <!-- Thumbnail -->
                    <div class="w-16 h-16 rounded-lg bg-gray-100 overflow-hidden flex-shrink-0">
                        <?php if ($item['display_image'] !== ''): ?>
                            <img src="<?= htmlspecialchars($item['display_image'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                 alt="<?= htmlspecialchars($item['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                 class="w-full h-full object-cover" loading="lazy" />
                        <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center
                                        text-gray-300" aria-hidden="true">
                                <svg class="w-7 h-7" xmlns="http://www.w3.org/2000/svg"
                                     fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                     stroke-width="1">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2
                                             l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6
                                             20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0
                                             00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="flex-1">
                        <p class="font-semibold text-gray-800 text-sm leading-snug">
                            <?= htmlspecialchars($item['name']) ?>
                        </p>
                        <p class="text-xs text-gray-400 mt-0.5">
                            <?= htmlspecialchars($item['category_name']) ?>
                        </p>
                        <p class="text-sm font-bold text-orange mt-1">
                            KES <?= number_format($item['price'], 2) ?>
                        </p>
                    </div>
                    <!-- Remove (mobile) -->
                    <form method="POST" action="cart.php">
                        <input type="hidden" name="action"     value="remove">
                        <input type="hidden" name="product_id" value="<?= $item['id'] ?>">
                        <button type="submit"
                                class="inline-flex items-center gap-1 text-sm font-medium
                                       text-red-600 hover:text-red-800 transition-colors"
                                aria-label="Drop <?= htmlspecialchars($item['name'], ENT_QUOTES) ?> from cart"
                                data-confirm="Remove this item?">
                            <span>Drop</span>
                            <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                 stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </form>
                </div>

                <!-- Qty + subtotal row -->
                <div class="flex items-center justify-between">
                    <form method="POST" action="cart.php" class="update-qty-form">
                        <input type="hidden" name="action"     value="update">
                        <input type="hidden" name="product_id" value="<?= $item['id'] ?>">
                        <div class="qty-stepper">
                            <button type="button" data-action="decrease"
                                    aria-label="Decrease quantity">−</button>
                            <input type="number" name="qty"
                                   value="<?= $item['qty'] ?>"
                                   min="1" max="<?= $item['stock_qty'] ?>"
                                   aria-label="Quantity" />
                            <button type="button" data-action="increase"
                                    aria-label="Increase quantity">+</button>
                        </div>
                    </form>
                    <p class="text-sm font-bold text-gray-800">
                        Subtotal:
                        <span class="text-orange">
                            KES <?= number_format($item['subtotal'], 2) ?>
                        </span>
                    </p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Continue shopping -->
        <div class="mt-5">
            <a href="<?= $base ?>/shop.php"
               class="inline-flex items-center gap-2 text-sm text-gray-500
                      hover:text-orange transition-colors font-medium">
                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor"
                     stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M15 19l-7-7 7-7"/>
                </svg>
                Continue Shopping
            </a>
        </div>
    </div><!-- /left column -->


    <!-- ── RIGHT: Order Summary ───────────────────────────────────────── -->
    <div class="w-full lg:w-80 xl:w-96 flex-shrink-0">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100
                    overflow-hidden sticky top-20">

            <div class="px-6 py-5 border-b border-gray-100">
                <h2 class="text-base font-bold text-gray-900">Order Summary</h2>
            </div>

            <div class="px-6 py-5 space-y-3 text-sm">

                <!-- Line: Cart subtotal -->
                <div class="flex items-center justify-between text-gray-600">
                    <span>
                        Subtotal
                        <span class="text-gray-400 font-normal">
                            (<?= $cartCount ?> item<?= $cartCount !== 1 ? 's' : '' ?>)
                        </span>
                    </span>
                    <span class="font-semibold text-gray-800">
                        KES <?= number_format($cartTotal, 2) ?>
                    </span>
                </div>

                <!-- Line: Shipping -->
                <div class="flex items-center justify-between text-gray-600">
                    <span class="flex items-center gap-1">
                        Delivery
                        <?php if ($cartTotal < $freeShipThresh && $cartTotal > 0): ?>
                        <span class="text-xs text-gray-400 font-normal">
                            (free over KES <?= number_format($freeShipThresh) ?>)
                        </span>
                        <?php endif; ?>
                    </span>
                    <?php if ($shippingFee === 0.0): ?>
                        <span class="font-semibold text-green-600">FREE</span>
                    <?php else: ?>
                        <span class="font-semibold text-gray-800">
                            KES <?= number_format($shippingFee, 2) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Free shipping progress -->
                <?php if ($cartTotal < $freeShipThresh && $cartTotal > 0):
                    $remaining = $freeShipThresh - $cartTotal;
                    $progress  = min(100, ($cartTotal / $freeShipThresh) * 100);
                ?>
                <div class="bg-green-50 rounded-lg p-3 text-xs text-green-700">
                    <p class="mb-1.5">
                        Add
                        <strong>KES <?= number_format($remaining, 2) ?></strong>
                        more for free delivery!
                    </p>
                    <div class="w-full bg-green-200 rounded-full h-1.5">
                        <div class="bg-green-500 h-1.5 rounded-full transition-all"
                             style="width: <?= $progress ?>%"
                             role="progressbar"
                             aria-valuenow="<?= round($progress) ?>"
                             aria-valuemin="0"
                             aria-valuemax="100"
                             aria-label="Free delivery progress"></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Divider -->
                <div class="border-t border-gray-100 pt-3">
                    <div class="flex items-center justify-between">
                        <span class="text-base font-bold text-gray-900">Total</span>
                        <span class="text-xl font-extrabold text-orange">
                            KES <?= number_format($orderTotal, 2) ?>
                        </span>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">
                        Including all applicable taxes
                    </p>
                </div>
            </div>

            <!-- CTA -->
            <div class="px-6 pb-6 space-y-3">
                <?php if (is_logged_in()): ?>
                    <a href="<?= $base ?>/checkout.php"
                       class="btn btn-primary btn-block btn-lg">
                        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor"
                             stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M12 15v2m-6 4h12a2 2 0 002-2v-6
                                     a2 2 0 00-2-2H6a2 2 0 00-2 2v6
                                     a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                        Proceed to Checkout
                    </a>
                <?php else: ?>
                    <!-- Guest: prompt to log in -->
                    <a href="<?= $base ?>/login.php?redirect=<?= urlencode('/checkout.php') ?>"
                       class="btn btn-primary btn-block btn-lg">
                        Log In to Checkout
                    </a>
                    <a href="<?= $base ?>/register.php"
                       class="btn btn-ghost btn-block text-sm">
                        Create a Free Account
                    </a>
                    <p class="text-center text-xs text-gray-400">
                        You need an account to complete your purchase.
                    </p>
                <?php endif; ?>
            </div>

            <!-- Payment method icons -->
            <div class="px-6 pb-5 flex items-center justify-center gap-3">
                <span class="inline-flex items-center gap-1 bg-green-900/10
                             border border-green-200 text-green-700 text-xs
                             font-semibold px-2.5 py-1 rounded-md">
                    M-Pesa
                </span>
                <span class="inline-flex items-center gap-1 bg-blue-50
                             border border-blue-200 text-blue-700 text-xs
                             font-semibold px-2.5 py-1 rounded-md">
                    Paystack
                </span>
                <span class="inline-flex items-center gap-1 bg-gray-100
                             border border-gray-200 text-gray-600 text-xs
                             font-semibold px-2.5 py-1 rounded-md">
                    Cash
                </span>
            </div>

        </div><!-- /sticky card -->
    </div><!-- /right column -->

</div><!-- /flex layout -->
<section class="mt-6 rounded-2xl border border-gray-200 bg-white p-5 sm:p-6 shadow-sm"
         aria-label="Cart total summary">
    <h2 class="text-lg font-bold text-gray-900 mb-4">Order total</h2>
    <dl class="space-y-2 text-sm">
        <div class="flex items-center justify-between text-gray-600">
            <dt>Items (<?= $cartCount ?>)</dt>
            <dd class="font-medium text-gray-900">KES <?= number_format($cartTotal, 2) ?></dd>
        </div>
        <div class="flex items-center justify-between text-gray-600">
            <dt>Delivery</dt>
            <dd class="font-medium text-gray-900">
                <?= $shippingFee === 0.0 ? 'FREE' : 'KES ' . number_format($shippingFee, 2) ?>
            </dd>
        </div>
        <div class="flex items-center justify-between border-t border-gray-200 pt-3 text-base font-bold">
            <dt>Total</dt>
            <dd class="text-orange">KES <?= number_format($orderTotal, 2) ?></dd>
        </div>
    </dl>
</section>
<?php endif; // cart not empty ?>


<!-- Page-specific JS: auto-submit qty forms on stepper change ───────── -->
<script>
(function () {
    // Submit the update form whenever the qty input changes via the stepper
    document.querySelectorAll('.update-qty-form').forEach(function (form) {
        var input = form.querySelector('input[type="number"]');
        if (!input) return;

        input.addEventListener('change', function () {
            form.submit();
        });
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
