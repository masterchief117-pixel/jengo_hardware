<?php
/**
 * Customer accounts shared by the attendant POS and admin portal.
 */

declare(strict_types=1);

$adminPortal = true;
if (isset($_COOKIE['JENGO_SESS'])) {
    require_once __DIR__ . '/includes/auth.php';
    if (is_logged_in() && in_array(get_user_role(), ['admin', 'attendant'], true)) {
        if (get_user_role() === 'admin') {
            require_admin();
        } else {
            require_attendant();
        }
        $adminPortal = false;
    }
}

if ($adminPortal) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_name('JENGO_ADMIN_SESS');
    session_id('');
    require_once __DIR__ . '/admin/auth.php';
    require_admin_login();
}

require_once __DIR__ . '/includes/pos_sales.php';

$pdo = db();
ensure_pos_schema($pdo);

if (empty($_SESSION['customers_csrf'])) {
    $_SESSION['customers_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['customers_csrf'];
$base = rtrim((string) env('APP_URL', ''), '/');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $_SESSION['customers_flash'] = ['type' => 'error', 'message' => 'Your form expired. Reload and try again.'];
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $companyName = trim((string) ($_POST['company_name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 120 || $phone === '' || mb_strlen($phone) > 40 || mb_strlen($companyName) > 160) {
            $_SESSION['customers_flash'] = [
                'type' => 'error',
                'message' => 'Enter a name and phone number. Name, phone, and company must fit the provided limits.',
            ];
        } else {
            try {
                $insert = $pdo->prepare(
                    'INSERT INTO customers (name, phone, company_name) VALUES (?, ?, ?)'
                );
                $insert->execute([
                    $name,
                    $phone,
                    $companyName !== '' ? $companyName : null,
                ]);
                $_SESSION['customers_flash'] = ['type' => 'success', 'message' => 'Customer created successfully.'];
            } catch (PDOException $exception) {
                error_log('Customer account creation failed: ' . $exception->getMessage());
                $_SESSION['customers_flash'] = ['type' => 'error', 'message' => 'The customer could not be saved. Please try again.'];
            }
        }
    }

    header('Location: ' . $base . '/customers.php');
    exit;
}

$customersStatement = $pdo->query(
    'SELECT id, name, phone, company_name, created_at
     FROM customers
     ORDER BY name ASC, id DESC'
);
$customers = $customersStatement->fetchAll(PDO::FETCH_ASSOC);
$flash = $_SESSION['customers_flash'] ?? null;
unset($_SESSION['customers_flash']);
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pageTitle = 'Customer Accounts';
require_once __DIR__ . '/includes/header.php';
?>

<main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-extrabold text-gray-900">Customer Accounts</h1>
            <p class="mt-1 text-sm text-gray-500">Registered customers available for POS checkout.</p>
        </div>
        <button type="button" id="open-customer-modal" class="btn btn-primary">New Customer</button>
    </div>

    <?php if (is_array($flash)): ?>
        <p class="mb-5 rounded-lg px-4 py-3 text-sm <?= ($flash['type'] ?? '') === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800' ?>"
           role="status">
            <?= $escape((string) ($flash['message'] ?? '')) ?>
        </p>
    <?php endif; ?>

    <section class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm"
             aria-label="Registered customers">
        <?php if ($customers === []): ?>
            <p class="px-5 py-8 text-center text-sm text-gray-500">No customer accounts yet.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-gray-500">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Name</th>
                            <th class="px-5 py-3 font-semibold">Phone</th>
                            <th class="px-5 py-3 font-semibold">Company</th>
                            <th class="px-5 py-3 font-semibold">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $customer): ?>
                            <tr class="border-t border-gray-100">
                                <td class="px-5 py-3 font-medium text-gray-900">
                                    <?= $escape((string) $customer['name']) ?>
                                </td>
                                <td class="px-5 py-3"><?= $escape((string) $customer['phone']) ?></td>
                                <td class="px-5 py-3"><?= $escape((string) ($customer['company_name'] ?: '—')) ?></td>
                                <td class="px-5 py-3 whitespace-nowrap">
                                    <?= $escape(date('d M Y', strtotime((string) $customer['created_at']))) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>

<dialog id="new-customer-dialog" class="w-full max-w-lg rounded-2xl p-0 shadow-xl backdrop:bg-gray-900/50">
    <form method="post" class="space-y-4 p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-gray-900">New Customer</h2>
                <p class="mt-1 text-sm text-gray-500">Add a customer account for future POS sales.</p>
            </div>
            <button type="button" id="close-customer-modal"
                    class="rounded-md px-2 py-1 text-gray-500 hover:bg-gray-100"
                    aria-label="Close dialog">✕</button>
        </div>
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
        <label class="block">
            <span class="form-label">Name</span>
            <input type="text" name="name" maxlength="120" required class="form-input">
        </label>
        <label class="block">
            <span class="form-label">Phone</span>
            <input type="tel" name="phone" maxlength="40" required class="form-input">
        </label>
        <label class="block">
            <span class="form-label">Company Name <span class="font-normal text-gray-500">(optional)</span></span>
            <input type="text" name="company_name" maxlength="160" class="form-input">
        </label>
        <div class="flex justify-end gap-2 pt-2">
            <button type="button" id="cancel-customer-modal" class="btn btn-secondary">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Customer</button>
        </div>
    </form>
</dialog>

<script>
(() => {
    const dialog = document.getElementById('new-customer-dialog');
    document.getElementById('open-customer-modal').addEventListener('click', () => dialog.showModal());
    document.getElementById('close-customer-modal').addEventListener('click', () => dialog.close());
    document.getElementById('cancel-customer-modal').addEventListener('click', () => dialog.close());
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
