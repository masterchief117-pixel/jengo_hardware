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
            $sale = normalize_sale_payload(
                $pdo,
                array_values($_SESSION['pos_cart']),
                (float) ($_POST['discount_percent'] ?? 0),
                (string) ($_POST['customer_name'] ?? ''),
                (string) ($_POST['payment_method'] ?? 'cash')
            );

            if (pos_sale_needs_approval($sale)) {
                submit_approval_request($attendantId, 'high_discount', $sale);
                $_SESSION['pos_cart'] = [];
                redirect('/attendant/pos.php', APPROVAL_SUCCESS_MESSAGE, 'info');
            }

            $saleNumber = record_pos_sale($pdo, $attendantId, $sale);
            $_SESSION['pos_cart'] = [];
            redirect(
                '/attendant/pos.php',
                'Sale ' . $saleNumber . ' completed for KES ' . number_format((float) $sale['total'], 2) . '.',
                'success'
            );
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

<div class="mb-6">
    <h1 class="text-2xl font-extrabold text-gray-900">Point of Sale</h1>
    <p class="mt-1 text-sm text-gray-500">
        Ring up a walk-in sale. Discounts above 5% or KES 1,000, and any lowered unit price, are sent to an admin instead of completing.
    </p>
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
        <div class="mt-4 overflow-x-auto">
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

        <?php if ($_SESSION['pos_cart'] === []): ?>
            <p class="mt-4 rounded-lg bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">
                The cart is empty. Add a product to start a sale.
            </p>
        <?php else: ?>
            <form method="post" class="mt-4 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($approvalCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="overflow-x-auto">
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
                        <span class="form-label">Customer</span>
                        <input type="text" name="customer_name" maxlength="120" class="form-input" placeholder="Walk-in">
                    </label>
                    <label>
                        <span class="form-label">Payment</span>
                        <select name="payment_method" class="form-input">
                            <option value="cash">Cash</option>
                            <option value="mpesa">M-Pesa</option>
                            <option value="card">Card</option>
                        </select>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
