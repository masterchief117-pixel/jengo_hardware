<?php
/**
 * Customer lookup and counter quotes.
 * A unit price below the shelf price is sent for approval instead of being saved.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    if ($approvalCsrfToken === '' || !hash_equals($approvalCsrfToken, $csrfToken)) {
        redirect('/attendant/quotes.php', 'Your form expired. Reload and try again.', 'error');
    }

    try {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity = (int) ($_POST['quantity'] ?? 1);
        $unitPrice = is_numeric($_POST['unit_price'] ?? null) ? (float) $_POST['unit_price'] : -1;
        $product = $pdo->prepare('SELECT id, name, price FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
        $product->execute([$productId]);
        $row = $product->fetch();
        if ($row === false || $quantity < 1 || $unitPrice < 0) {
            throw new InvalidArgumentException('Choose a product, quantity, and price.');
        }

        $payload = [
            'customer_id' => (int) ($_POST['customer_id'] ?? 0),
            'customer_name' => trim((string) ($_POST['customer_name'] ?? '')),
            'customer_phone' => trim((string) ($_POST['customer_phone'] ?? '')),
            'notes' => trim((string) ($_POST['notes'] ?? '')),
            'items' => [[
                'product_id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'catalog_price' => round((float) $row['price'], 2),
                'unit_price' => round($unitPrice, 2),
                'quantity' => $quantity,
            ]],
        ];
        if ($payload['customer_name'] === '') {
            throw new InvalidArgumentException('Enter the customer name.');
        }

        if (money_cents($unitPrice) < money_cents($row['price'])) {
            submit_approval_request($attendantId, 'price_override', $payload);
            redirect('/attendant/quotes.php', APPROVAL_SUCCESS_MESSAGE, 'info');
        }

        $quoteNumber = save_quote_from_payload($pdo, $attendantId, $payload, 'draft');
        redirect('/attendant/quotes.php', 'Quote ' . $quoteNumber . ' saved.', 'success');
    } catch (InvalidArgumentException $exception) {
        redirect('/attendant/quotes.php', $exception->getMessage(), 'error');
    } catch (Throwable $exception) {
        redirect(
            '/attendant/quotes.php',
            env('APP_DEBUG', false) === true ? $exception->getMessage() : 'The quote could not be saved.',
            'error'
        );
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$customerSql = "SELECT id, full_name, email, phone FROM users WHERE role = 'customer' AND is_active = 1";
$customerParams = [];
if ($search !== '') {
    $customerSql .= ' AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)';
    $like = '%' . $search . '%';
    $customerParams = [$like, $like, $like];
}
$customerSql .= ' ORDER BY full_name ASC LIMIT 25';
$customers = $pdo->prepare($customerSql);
$customers->execute($customerParams);
$customerRows = $customers->fetchAll();

$products = $pdo->query(
    'SELECT id, name, sku, price FROM products WHERE is_active = 1 ORDER BY name ASC'
)->fetchAll();

$quotes = $pdo->prepare(
    'SELECT id, quote_number, customer_name, customer_phone, subtotal, status, created_at
     FROM quotes
     WHERE attendant_id = ?
     ORDER BY created_at DESC
     LIMIT 20'
);
$quotes->execute([$attendantId]);
$quoteRows = $quotes->fetchAll();

$pageTitle = 'Quotes & Customers';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-extrabold text-gray-900">Quotes &amp; Customers</h1>
    <p class="mt-1 text-sm text-gray-500">
        Look up a customer and price a quote. A unit price under the shelf price is sent to an admin before it is saved.
    </p>
</div>

<div class="grid gap-6 lg:grid-cols-2">
    <section class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm" aria-labelledby="customers-heading">
        <h2 id="customers-heading" class="text-lg font-bold">Customers</h2>
        <form method="get" class="mt-3 flex gap-2">
            <label class="flex-1">
                <span class="sr-only">Search customers</span>
                <input type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                       class="form-input" placeholder="Name, email, or phone">
            </label>
            <button type="submit" class="btn btn-secondary">Search</button>
        </form>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b text-gray-500">
                        <th class="py-2 pr-3">Name</th>
                        <th class="py-2 pr-3">Phone</th>
                        <th class="py-2">Email</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($customerRows === []): ?>
                        <tr><td class="py-4 text-gray-500" colspan="3">No customers match that search.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($customerRows as $customer): ?>
                        <tr class="border-b last:border-0">
                            <td class="py-3 pr-3">
                                <button type="button" class="font-medium text-left text-amber-800"
                                        data-id="<?= (int) $customer['id'] ?>"
                                        data-name="<?= htmlspecialchars((string) $customer['full_name'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-phone="<?= htmlspecialchars((string) $customer['phone'], ENT_QUOTES, 'UTF-8') ?>"
                                        onclick="useCustomer(this)">
                                    <?= htmlspecialchars((string) $customer['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            </td>
                            <td class="py-3 pr-3"><?= htmlspecialchars((string) $customer['phone'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="py-3"><?= htmlspecialchars((string) $customer['email'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm" aria-labelledby="quote-heading">
        <h2 id="quote-heading" class="text-lg font-bold">New quote</h2>
        <form method="post" class="mt-4 grid gap-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($approvalCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="customer_id" id="quote-customer-id" value="0">
            <label>
                <span class="form-label">Customer name</span>
                <input type="text" name="customer_name" id="quote-customer-name" required maxlength="120" class="form-input">
            </label>
            <label>
                <span class="form-label">Phone</span>
                <input type="text" name="customer_phone" id="quote-customer-phone" maxlength="40" class="form-input">
            </label>
            <label>
                <span class="form-label">Product</span>
                <select name="product_id" id="quote-product" required class="form-input" onchange="fillQuotePrice()">
                    <option value="">Select a product</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>" data-price="<?= htmlspecialchars((string) $product['price'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars((string) $product['name'], ENT_QUOTES, 'UTF-8') ?>
                            · KES <?= number_format((float) $product['price'], 2) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="grid grid-cols-2 gap-3">
                <label>
                    <span class="form-label">Quantity</span>
                    <input type="number" name="quantity" min="1" value="1" required class="form-input">
                </label>
                <label>
                    <span class="form-label">Offered unit price (KES)</span>
                    <input type="number" name="unit_price" id="quote-unit-price" min="0" step="0.01" required class="form-input">
                </label>
            </div>
            <label>
                <span class="form-label">Notes</span>
                <textarea name="notes" rows="2" maxlength="500" class="form-input"></textarea>
            </label>
            <button type="submit" class="btn btn-primary">Save quote</button>
        </form>
    </section>
</div>

<section class="mt-8" aria-labelledby="recent-quotes-heading">
    <h2 id="recent-quotes-heading" class="mb-3 text-lg font-bold">Recent quotes</h2>
    <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
        <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 text-gray-500">
                <tr>
                    <th class="px-4 py-3">Quote</th>
                    <th class="px-4 py-3">Customer</th>
                    <th class="px-4 py-3">Total</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Created</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($quoteRows === []): ?>
                    <tr><td class="px-4 py-6 text-gray-500" colspan="5">No quotes yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($quoteRows as $quote): ?>
                    <tr class="border-t">
                        <td class="px-4 py-3 font-medium"><?= htmlspecialchars((string) $quote['quote_number'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="px-4 py-3">
                            <?= htmlspecialchars((string) $quote['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                            <?php if ($quote['customer_phone']): ?>
                                <span class="block text-xs text-gray-500"><?= htmlspecialchars((string) $quote['customer_phone'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">KES <?= number_format((float) $quote['subtotal'], 2) ?></td>
                        <td class="px-4 py-3"><?= htmlspecialchars((string) $quote['status'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="px-4 py-3 whitespace-nowrap"><?= htmlspecialchars((string) $quote['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
function useCustomer(button) {
    document.getElementById('quote-customer-id').value = button.dataset.id || '0';
    document.getElementById('quote-customer-name').value = button.dataset.name || '';
    document.getElementById('quote-customer-phone').value = button.dataset.phone || '';
    document.getElementById('quote-customer-name').focus();
}
function fillQuotePrice() {
    var select = document.getElementById('quote-product');
    var option = select.options[select.selectedIndex];
    var price = option ? option.getAttribute('data-price') : '';
    if (price) {
        document.getElementById('quote-unit-price').value = Number(price).toFixed(2);
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
