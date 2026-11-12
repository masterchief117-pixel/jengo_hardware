<?php
/**
 * Attendant point of sale.
 *
 * Discounts within 5% and KES 1,000, with no reduced unit price, are saved
 * as completed sales. Anything above that limit is stored as a pending
 * high_discount approval and the cart is cleared.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/approval_submit.php';

require_attendant();

if (empty($_SESSION['attendant_approval_csrf'])) {
    $_SESSION['attendant_approval_csrf'] = bin2hex(random_bytes(32));
}
$approvalCsrfToken = (string) $_SESSION['attendant_approval_csrf'];

$pdo = db();
ensure_pos_schema($pdo);
$attendantId = (int) get_user_id();
$customersStatement = $pdo->query('SELECT id, name, company_name, phone FROM customers ORDER BY name ASC');
$customers = $customersStatement->fetchAll(PDO::FETCH_ASSOC);

if (!isset($_SESSION['pos_cart']) || !is_array($_SESSION['pos_cart'])) {
    $_SESSION['pos_cart'] = [];
}

/**
 * @param array<string, mixed> $postedLines
 */
function pos_apply_posted_lines(PDO $pdo, array $postedLines): void
{
    foreach ($_SESSION['pos_cart'] as $productId => $line) {
        $posted = $postedLines[(string) $productId] ?? $postedLines[$productId] ?? null;
        if (!is_array($posted)) {
            continue;
        }
        $product = $pdo->prepare('SELECT price, stock_qty, is_active FROM products WHERE id = ? LIMIT 1');
        $product->execute([(int) $productId]);
        $row = $product->fetch();
        if ($row === false || (int) $row['is_active'] !== 1) {
            unset($_SESSION['pos_cart'][$productId]);
            continue;
        }
        $available = (int) $row['stock_qty'];
        if ($available < 1) {
            unset($_SESSION['pos_cart'][$productId]);
            continue;
        }
        $quantity = (int) ($posted['quantity'] ?? $line['quantity'] ?? 1);
        $quantity = max(1, min($quantity, $available));
        $unitPrice = is_numeric($posted['unit_price'] ?? null)
            ? round((float) $posted['unit_price'], 2)
            : (float) $row['price'];
        if ($unitPrice < 0) {
            $unitPrice = 0;
        }
        $_SESSION['pos_cart'][$productId]['catalog_price'] = round((float) $row['price'], 2);
        $_SESSION['pos_cart'][$productId]['unit_price'] = $unitPrice;
        $_SESSION['pos_cart'][$productId]['quantity'] = $quantity;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    if ($approvalCsrfToken === '' || !hash_equals($approvalCsrfToken, $csrfToken)) {
        redirect('/attendant/pos.php', 'Your form expired. Reload and try again.', 'error');
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
            $statement = $pdo->prepare(
                'SELECT id, name, sku, price, stock_qty
                 FROM products WHERE id = ? AND is_active = 1 LIMIT 1'
            );
            $statement->execute([$productId]);
            $product = $statement->fetch();
            if ($product === false) {
                throw new InvalidArgumentException('That product is not available.');
            }
            $existing = (int) ($_SESSION['pos_cart'][$productId]['quantity'] ?? 0);
            $nextQuantity = min((int) $product['stock_qty'], $existing + $quantity);
            if ($nextQuantity < 1) {
                throw new InvalidArgumentException($product['name'] . ' is out of stock.');
            }
            $_SESSION['pos_cart'][$productId] = [
                'product_id' => (int) $product['id'],
                'name' => (string) $product['name'],
                'sku' => (string) ($product['sku'] ?? ''),
                'catalog_price' => round((float) $product['price'], 2),
                'unit_price' => round((float) ($_SESSION['pos_cart'][$productId]['unit_price'] ?? $product['price']), 2),
                'quantity' => $nextQuantity,
            ];
            redirect('/attendant/pos.php', $product['name'] . ' added to the cart.', 'success');
        }

        if ($action === 'remove') {
            unset($_SESSION['pos_cart'][(int) ($_POST['product_id'] ?? 0)]);
            redirect('/attendant/pos.php', 'Item removed.', 'info');
        }

        if ($action === 'clear') {
            $_SESSION['pos_cart'] = [];
            redirect('/attendant/pos.php', 'Cart cleared.', 'info');
        }

        if ($action === 'update' || $action === 'checkout') {
            pos_apply_posted_lines($pdo, is_array($_POST['lines'] ?? null) ? $_POST['lines'] : []);
        }

        if ($action === 'update') {
            redirect('/attendant/pos.php', 'Cart updated.', 'success');
        }

        if ($action === 'checkout') {
            $rawCustomerId = $_POST['customer_id'] ?? '';
            $customerId = null;
            if ($rawCustomerId !== '') {
                if (!is_string($rawCustomerId) && !is_int($rawCustomerId)) {
                    throw new InvalidArgumentException('Select a valid customer or choose Walk-in Customer.');
                }
                $validatedCustomerId = filter_var($rawCustomerId, FILTER_VALIDATE_INT);
                if ($validatedCustomerId === false || $validatedCustomerId < 1) {
                    throw new InvalidArgumentException('Select a valid customer or choose Walk-in Customer.');
                }
                $customerId = $validatedCustomerId;
            }

            $sale = normalize_sale_payload(
                $pdo,
                array_values($_SESSION['pos_cart']),
                (float) ($_POST['discount_percent'] ?? 0),
                (string) ($_POST['customer_name'] ?? ''),
                (string) ($_POST['payment_method'] ?? 'cash'),
                $customerId
            );

            $mpesaPhone = '';
            if ($sale['payment_method'] === 'mpesa') {
                $phoneInput = $_POST['mpesa_phone'] ?? '';
                $mpesaPhone = is_string($phoneInput)
                    ? (format_pos_mpesa_phone($phoneInput) ?? '')
                    : '';
                if ($mpesaPhone === '') {
                    throw new InvalidArgumentException('Enter a valid Kenyan M-Pesa phone number.');
                }
                if (pos_sale_needs_approval($sale)) {
                    throw new InvalidArgumentException(
                        'M-Pesa checkout is unavailable for discounts requiring admin approval. Remove the discount or use another payment method.'
                    );
                }
            }

            if (pos_sale_needs_approval($sale)) {
                submit_approval_request($attendantId, 'high_discount', $sale);
                $_SESSION['pos_cart'] = [];
                redirect('/attendant/pos.php', APPROVAL_SUCCESS_MESSAGE, 'info');
            }

            if ($sale['payment_method'] === 'mpesa') {
                $sale['status'] = 'pending';
                $recordedSale = record_pos_sale($pdo, $attendantId, $sale);
                $_SESSION['pos_cart'] = [];
                $_SESSION['pos_pending_payment'] = [
                    'sale_id' => $recordedSale['id'],
                    'phone' => $mpesaPhone,
                ];
                redirect('/attendant/pos.php?payment=' . $recordedSale['id']);
            }

            $recordedSale = record_pos_sale($pdo, $attendantId, $sale);
            $_SESSION['pos_cart'] = [];
            redirect('/receipt.php?sale_id=' . $recordedSale['id']);
        }

        redirect('/attendant/pos.php', 'Unknown action.', 'error');
    } catch (InvalidArgumentException $exception) {
        redirect('/attendant/pos.php', $exception->getMessage(), 'error');
    } catch (Throwable $exception) {
        redirect(
            '/attendant/pos.php',
            env('APP_DEBUG', false) === true ? $exception->getMessage() : 'Checkout could not be completed.',
            'error'
        );
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$productSql = 'SELECT id, name, sku, price, stock_qty FROM products WHERE is_active = 1';
$productParams = [];
if ($search !== '') {
    $productSql .= ' AND (name LIKE ? OR sku LIKE ?)';
    $like = '%' . $search . '%';
    $productParams = [$like, $like];
}
$productSql .= ' ORDER BY name ASC LIMIT 30';
$productStatement = $pdo->prepare($productSql);
$productStatement->execute($productParams);
$products = $productStatement->fetchAll();
$pendingPayment = $_SESSION['pos_pending_payment'] ?? null;
$pendingSaleId = is_array($pendingPayment) ? (int) ($pendingPayment['sale_id'] ?? 0) : 0;
$showPaymentModal = isset($_GET['payment'])
    && is_array($pendingPayment)
    && (int) ($_GET['payment']) === $pendingSaleId
    && $pendingSaleId > 0;

$subtotal = 0.0;
$priceOverridden = false;
foreach ($_SESSION['pos_cart'] as $line) {
    $subtotal += (float) $line['unit_price'] * (int) $line['quantity'];
    if (money_cents($line['unit_price']) < money_cents($line['catalog_price'])) {
        $priceOverridden = true;
    }
}

$pageTitle = 'Point of Sale';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h1 class="text-2xl font-extrabold text-gray-900">Point of Sale</h1>
        <p class="mt-1 text-sm text-gray-500">
            Ring up a walk-in sale. Discounts above 5% or KES 1,000, and any lowered unit price, are sent to an admin instead of completing.
        </p>
        <p class="mt-2 inline-flex rounded border border-gray-200 bg-white px-3 py-1 text-xs font-semibold text-gray-700">
            Admin / Tech Support Hotline:
            <a class="ml-1 text-orange underline" href="tel:+254789453215">0789453215</a>
        </p>
    </div>
    <a href="<?= htmlspecialchars(rtrim((string) env('APP_URL', ''), '/') . '/customers.php', ENT_QUOTES, 'UTF-8') ?>"
       class="btn btn-secondary btn-sm">Manage Customers</a>
</div>

<div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
    <section class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm" aria-labelledby="pos-products-heading">
        <h2 id="pos-products-heading" class="text-lg font-bold text-gray-900">Products</h2>
        <form method="get" class="mt-3 flex gap-2">
            <label class="flex-1">
                <span class="sr-only">Search products</span>
                <input type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                       class="form-input" placeholder="Search name or SKU">
            </label>
            <button type="submit" class="btn btn-secondary">Search</button>
        </form>
        <div class="table-responsive mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-gray-500">
                        <th class="py-2 pr-3">Product</th>
                        <th class="py-2 pr-3">Price</th>
                        <th class="py-2 pr-3">Stock</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($products === []): ?>
                        <tr><td class="py-4 text-gray-500" colspan="4">No active products match that search.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($products as $product): ?>
                        <tr class="border-b last:border-0">
                            <td class="py-3 pr-3">
                                <p class="font-medium text-gray-900"><?= htmlspecialchars((string) $product['name'], ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="text-xs text-gray-500"><?= htmlspecialchars((string) ($product['sku'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                            </td>
                            <td class="py-3 pr-3 whitespace-nowrap">KES <?= number_format((float) $product['price'], 2) ?></td>
                            <td class="py-3 pr-3"><?= (int) $product['stock_qty'] ?></td>
                            <td class="py-3 text-right">
                                <form method="post" class="inline-flex items-center gap-2">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($approvalCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                    <input type="number" name="quantity" min="1" max="<?= max(1, (int) $product['stock_qty']) ?>" value="1"
                                           class="form-input w-16" aria-label="Quantity for <?= htmlspecialchars((string) $product['name'], ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="btn btn-primary btn-sm" <?= (int) $product['stock_qty'] < 1 ? 'disabled' : '' ?>>
                                        Add
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm" aria-labelledby="pos-cart-heading">
        <div class="flex items-center justify-between gap-3">
            <h2 id="pos-cart-heading" class="text-lg font-bold text-gray-900">Cart</h2>
            <?php if ($_SESSION['pos_cart'] !== []): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($approvalCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="clear">
                    <button type="submit" class="btn btn-ghost btn-sm">Clear cart</button>
                </form>
            <?php endif; ?>
        </div>

        <label for="barcode-scanner" class="sr-only">Scan barcode or search for a product</label>
        <input type="text" id="barcode-scanner"
               class="form-input mt-4 w-full border-2 border-orange-400 px-4 py-4 text-lg font-semibold"
               placeholder="Scan Barcode or Type SKU/Name..."
               autocomplete="off" autofocus>
        <p id="barcode-scan-message" class="mt-2 hidden text-sm" role="status" aria-live="polite"></p>

        <?php if ($_SESSION['pos_cart'] === []): ?>
            <p class="mt-4 rounded-lg bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">
                The cart is empty. Add a product to start a sale.
            </p>
        <?php else: ?>
            <form method="post" class="mt-4 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($approvalCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="table-responsive overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b text-gray-500">
                                <th class="py-2 pr-3">Item</th>
                                <th class="py-2 pr-3">Unit price</th>
                                <th class="py-2 pr-3">Qty</th>
                                <th class="py-2 pr-3">Line</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($_SESSION['pos_cart'] as $productId => $line): ?>
                                <?php $lineTotal = (float) $line['unit_price'] * (int) $line['quantity']; ?>
                                <tr class="border-b last:border-0 align-top">
                                    <td class="py-3 pr-3">
                                        <p class="font-medium"><?= htmlspecialchars((string) $line['name'], ENT_QUOTES, 'UTF-8') ?></p>
                                        <p class="text-xs text-gray-500">
                                            Shelf KES <?= number_format((float) $line['catalog_price'], 2) ?>
                                            <?php if (money_cents($line['unit_price']) < money_cents($line['catalog_price'])): ?>
                                                · lowered
                                            <?php endif; ?>
                                        </p>
                                    </td>
                                    <td class="py-3 pr-3">
                                        <input type="number" step="0.01" min="0"
                                               name="lines[<?= (int) $productId ?>][unit_price]"
                                               value="<?= htmlspecialchars(number_format((float) $line['unit_price'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
                                               class="form-input w-28"
                                               aria-label="Unit price for <?= htmlspecialchars((string) $line['name'], ENT_QUOTES, 'UTF-8') ?>">
                                    </td>
                                    <td class="py-3 pr-3">
                                        <input type="number" min="1" step="1"
                                               name="lines[<?= (int) $productId ?>][quantity]"
                                               value="<?= (int) $line['quantity'] ?>"
                                               class="form-input w-20"
                                               aria-label="Quantity for <?= htmlspecialchars((string) $line['name'], ENT_QUOTES, 'UTF-8') ?>">
                                    </td>
                                    <td class="py-3 pr-3 whitespace-nowrap">KES <?= number_format($lineTotal, 2) ?></td>
                                    <td class="py-3">
                                        <button type="submit" class="btn btn-ghost btn-sm" form="remove-line-<?= (int) $productId ?>">
                                            Remove
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <label>
                        <span class="form-label">Select Customer</span>
                        <select name="customer_id" class="form-input">
                            <option value="">Walk-in Customer</option>
                            <?php foreach ($customers as $customer): ?>
                                <?php
                                $customerLabel = (string) $customer['name'];
                                if (!empty($customer['company_name'])) {
                                    $customerLabel .= ' - ' . $customer['company_name'];
                                }
                                ?>
                                <option value="<?= (int) $customer['id'] ?>"
                                        data-phone="<?= htmlspecialchars((string) $customer['phone'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($customerLabel, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span class="form-label">Payment</span>
                        <select name="payment_method" class="form-input">
                            <option value="cash">Cash</option>
                            <option value="mpesa">M-Pesa</option>
                            <option value="card">Card</option>
                        </select>
                    </label>
                    <label id="mpesa-phone-field" class="hidden">
                        <span class="form-label">M-Pesa Phone</span>
                        <input type="tel" name="mpesa_phone" class="form-input"
                               placeholder="0712 345 678" autocomplete="tel"
                               inputmode="tel">
                    </label>
                    <label>
                        <span class="form-label">Discount %</span>
                        <input type="number" name="discount_percent" min="0" max="100" step="0.01" value="0" class="form-input">
                    </label>
                </div>

                <p class="text-sm text-gray-600">
                    Cart subtotal before discount:
                    <span class="font-semibold text-gray-900">KES <?= number_format($subtotal, 2) ?></span>
                    <?php if ($priceOverridden): ?>
                        <span class="badge badge-warning ml-2">Unit price lowered</span>
                    <?php endif; ?>
                </p>

                <div class="flex flex-wrap gap-2">
                    <button type="submit" name="action" value="update" class="btn btn-secondary">Update cart</button>
                    <button type="submit" name="action" value="checkout" class="btn btn-primary">Checkout</button>
                </div>
            </form>
            <?php foreach ($_SESSION['pos_cart'] as $productId => $line): ?>
                <form id="remove-line-<?= (int) $productId ?>" method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($approvalCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="product_id" value="<?= (int) $productId ?>">
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</div>

<?php if ($showPaymentModal): ?>
    <div id="pos-payment-modal"
         class="fixed inset-0 z-[100] flex items-center justify-center bg-gray-950/70 p-4"
         role="dialog" aria-modal="true" aria-labelledby="pos-payment-title">
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
            <div class="text-center">
                <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-green-100 text-2xl"
                     aria-hidden="true">📱</div>
                <h2 id="pos-payment-title" class="text-xl font-bold text-gray-900">M-Pesa Payment</h2>
                <p id="pos-payment-message" class="mt-2 text-sm text-gray-600" role="status" aria-live="polite">
                    Sending payment prompt...
                </p>
            </div>
            <div class="mt-6 flex justify-center">
                <span id="pos-payment-spinner"
                      class="h-8 w-8 animate-spin rounded-full border-4 border-gray-200 border-t-green-600"
                      aria-label="Payment in progress"></span>
            </div>
            <p id="pos-payment-countdown" class="mt-4 text-center text-xs text-gray-500"></p>
            <div class="mt-6 flex flex-wrap justify-center gap-3">
                <button id="pos-payment-cancel" type="button" class="btn btn-danger">
                    Cancel / Abort Payment
                </button>
                <a id="pos-payment-receipt" href="<?= $base ?>/receipt.php?sale_id=<?= $pendingSaleId ?>"
                   class="btn btn-primary hidden">View Receipt</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
(() => {
    const paymentModal = document.getElementById('pos-payment-modal');
    if (paymentModal) {
        const saleId = <?= json_encode($pendingSaleId) ?>;
        const csrfToken = <?= json_encode($approvalCsrfToken) ?>;
        const phone = <?= json_encode((string) ($pendingPayment['phone'] ?? '')) ?>;
        const paymentMessage = document.getElementById('pos-payment-message');
        const countdown = document.getElementById('pos-payment-countdown');
        const spinner = document.getElementById('pos-payment-spinner');
        const cancelButton = document.getElementById('pos-payment-cancel');
        const receiptLink = document.getElementById('pos-payment-receipt');
        let finished = false;
        let pollTimer = 0;
        let timeoutTimer = 0;

        function armPaymentTimeout(startedAt) {
            let secondsLeft = Math.max(0, 60 - Math.floor(Date.now() / 1000 - startedAt));
            countdown.textContent = `Payment will time out in ${secondsLeft} seconds.`;
            if (secondsLeft === 0) {
                cancelPayment('Payment timed out');
                return;
            }
            timeoutTimer = window.setTimeout(() => cancelPayment('Payment timed out'), secondsLeft * 1000);
            const countdownTimer = window.setInterval(() => {
                secondsLeft -= 1;
                if (finished || secondsLeft <= 0) {
                    window.clearInterval(countdownTimer);
                    return;
                }
                countdown.textContent = `Payment will time out in ${secondsLeft} seconds.`;
            }, 1000);
        }

        function setMessage(message, isError) {
            paymentMessage.textContent = message;
            paymentMessage.className = `mt-2 text-sm ${isError ? 'text-red-600' : 'text-gray-600'}`;
        }

        async function cancelPayment(reason) {
            if (finished) return;
            cancelButton.disabled = true;
            try {
                const response = await fetch('api_cancel_payment.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({ sale_id: saleId, reason })
                });
                const result = await response.json();
                if (!response.ok || result.success !== true) {
                    throw new Error(result.message || 'Could not cancel this payment.');
                }
                finished = true;
                window.clearInterval(pollTimer);
                window.clearTimeout(timeoutTimer);
                paymentModal.remove();
                const message = document.getElementById('barcode-scan-message');
                message.textContent = reason === 'Payment timed out'
                    ? 'Payment timed out. Reserved stock was released; register is ready.'
                    : 'Payment cancelled. Reserved stock was released; register is ready.';
                message.className = 'mt-2 text-sm text-amber-700';
                document.getElementById('barcode-scanner')?.focus();
            } catch (error) {
                cancelButton.disabled = false;
                setMessage(error instanceof Error ? error.message : 'Could not cancel this payment.', true);
            }
        }

        async function pollPayment() {
            if (finished) return;
            try {
                const response = await fetch(
                    `api_pos_payment_status.php?sale_id=${encodeURIComponent(saleId)}`,
                    { headers: { 'X-CSRF-Token': csrfToken }, cache: 'no-store' }
                );
                const result = await response.json();
                if (!response.ok || result.success !== true) {
                    throw new Error(result.message || 'Unable to check payment status.');
                }
                if (result.state === 'completed') {
                    finished = true;
                    window.clearInterval(pollTimer);
                    window.clearTimeout(timeoutTimer);
                    setMessage('Payment confirmed. Opening receipt...', false);
                    spinner.classList.add('hidden');
                    receiptLink.classList.remove('hidden');
                    cancelButton.classList.add('hidden');
                    window.location.assign(result.sale_id
                        ? `../receipt.php?sale_id=${encodeURIComponent(result.sale_id)}`
                        : receiptLink.href);
                } else if (result.state === 'cancelled') {
                    finished = true;
                    window.clearInterval(pollTimer);
                    window.clearTimeout(timeoutTimer);
                    paymentModal.remove();
                    window.location.reload();
                } else {
                    setMessage(result.message, false);
                }
            } catch (error) {
                if (!finished) {
                    setMessage(error instanceof Error ? error.message : 'Unable to check payment status.', true);
                }
            }
        }

        cancelButton.addEventListener('click', () => {
            cancelPayment(
                cancelButton.textContent === 'Release Reserved Stock'
                    ? 'STK Timeout'
                    : 'Cancelled by Cashier'
            );
        });
        pollTimer = window.setInterval(pollPayment, 2000);

        fetch('api_start_pos_payment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({ sale_id: saleId, phone })
        })
            .then(async (response) => {
                const result = await response.json();
                if (!response.ok || result.success !== true) {
                    throw new Error(result.message || 'Unable to start M-Pesa payment.');
                }
                setMessage(result.message, false);
                if (result.state === 'cancelled') {
                    finished = true;
                    window.clearInterval(pollTimer);
                    paymentModal.remove();
                    const message = document.getElementById('barcode-scan-message');
                    message.textContent = result.message;
                    message.className = 'mt-2 text-sm text-amber-700';
                    document.getElementById('barcode-scanner')?.focus();
                } else {
                    armPaymentTimeout(Number(result.started_at) || Math.floor(Date.now() / 1000));
                }
            })
            .catch((error) => {
                if (!finished) {
                    setMessage(error instanceof Error ? error.message : 'Unable to start payment.', true);
                    spinner.classList.add('hidden');
                    cancelButton.textContent = 'Release Reserved Stock';
                }
            });
    }

    const scanner = document.getElementById('barcode-scanner');
    const message = document.getElementById('barcode-scan-message');
    const paymentMethod = document.querySelector('select[name="payment_method"]');
    const customerSelect = document.querySelector('select[name="customer_id"]');
    const mpesaPhoneField = document.getElementById('mpesa-phone-field');
    const mpesaPhoneInput = mpesaPhoneField?.querySelector('input[name="mpesa_phone"]');
    function updateMpesaPhoneField() {
        const isMpesa = paymentMethod?.value === 'mpesa';
        mpesaPhoneField?.classList.toggle('hidden', !isMpesa);
        if (mpesaPhoneInput) {
            mpesaPhoneInput.required = Boolean(isMpesa);
            if (isMpesa && customerSelect?.selectedOptions[0]?.dataset.phone && !mpesaPhoneInput.value) {
                mpesaPhoneInput.value = customerSelect.selectedOptions[0].dataset.phone;
            }
        }
    }
    paymentMethod?.addEventListener('change', updateMpesaPhoneField);
    customerSelect?.addEventListener('change', () => {
        if (paymentMethod?.value === 'mpesa' && mpesaPhoneInput) {
            mpesaPhoneInput.value = customerSelect.selectedOptions[0]?.dataset.phone || '';
        }
    });
    updateMpesaPhoneField();
    let requestPending = false;
    let messageTimer;

    function showScanMessage(text, isError) {
        message.textContent = text;
        message.className = `mt-2 text-sm ${isError ? 'text-red-600' : 'text-green-700'}`;
        window.clearTimeout(messageTimer);
        messageTimer = window.setTimeout(() => {
            message.textContent = '';
            message.className = 'mt-2 hidden text-sm';
        }, 2500);
    }

    scanner.addEventListener('keydown', async (event) => {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();
        const lookup = scanner.value.trim();
        if (!lookup || requestPending) {
            return;
        }

        scanner.value = '';
        requestPending = true;
        scanner.disabled = true;

        try {
            const response = await fetch('api_scan_product.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': <?= json_encode($approvalCsrfToken) ?>
                },
                body: new URLSearchParams({ sku: lookup })
            });
            const result = await response.json();
            if (!response.ok) {
                throw new Error(result.error || 'Product not found.');
            }

            showScanMessage(`${result.product.name} added to cart.`, false);
            window.location.reload();
        } catch (error) {
            showScanMessage(error instanceof Error ? error.message : 'Product not found.', true);
            scanner.focus();
        } finally {
            requestPending = false;
            scanner.disabled = false;
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
