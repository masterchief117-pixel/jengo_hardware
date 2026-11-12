<?php
/**
 * payments/paystack_init.php
 *
 * Paystack payment initiator.
 *
 * Flow:
 *   1. Validate the pending_order session set by checkout.php
 *   2. Call Paystack /transaction/initialize API to get an authorization URL
 *   3. Insert a 'pending' payment row with the Paystack reference
 *   4. Update the order to 'processing'
 *   5. Redirect the customer to Paystack's hosted payment page
 *
 * After payment Paystack redirects to paystack_verify.php?reference=xxx
 * and also fires a webhook to paystack_webhook.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login();

if (is_system_owner()) {
    redirect('/admin/owner_dashboard.php', 'Owner accounts cannot initiate customer payments.', 'error');
}

$pdo = db();

// ══════════════════════════════════════════════════════════════════════════
// 1. VALIDATE SESSION ORDER
// ══════════════════════════════════════════════════════════════════════════
$pending = $_SESSION['pending_order'] ?? null;

if (
    !$pending ||
    !isset($pending['order_id'], $pending['total_amount']) ||
    ($pending['payment_method'] ?? '') !== 'paystack'
) {
    redirect('/cart.php', 'No active Paystack order found. Please try again.', 'error');
}

$orderId      = (int)   $pending['order_id'];
$totalAmount  = (float) $pending['total_amount'];
$orderNumber  = (string)$pending['order_number'];
$fulfillment  = (string)($pending['fulfillment'] ?? 'delivery');

// Verify the order still exists and belongs to this user
$orderStmt = $pdo->prepare(
    'SELECT id, status FROM orders WHERE id = ? AND customer_id = ? LIMIT 1'
);
$orderStmt->execute([$orderId, get_user_id()]);
$order = $orderStmt->fetch();

if (!$order) {
    redirect('/cart.php', 'Order not found.', 'error');
}
if (!in_array($order['status'], ['pending', 'processing'], true)) {
    redirect(
        '/customer/dashboard.php',
        'This order has already been processed.',
        'info'
    );
}

// ══════════════════════════════════════════════════════════════════════════
// 2. PAYSTACK CONFIG
// ══════════════════════════════════════════════════════════════════════════
$secretKey   = (string) env('PAYSTACK_SECRET_KEY',   '');
$callbackUrl = (string) env('PAYSTACK_CALLBACK_URL', '');
$baseUrl     = (string) env('PAYSTACK_BASE_URL',     'https://api.paystack.co');

if ($secretKey === '' || $callbackUrl === '') {
    error_log('[Paystack] Missing credentials in .env');
    redirect('/checkout.php', 'Payment gateway not configured. Contact support.', 'error');
}

// Fetch customer email for Paystack (required field)
$userStmt = $pdo->prepare('SELECT email, full_name, phone FROM users WHERE id = ? LIMIT 1');
$userStmt->execute([get_user_id()]);
$user = $userStmt->fetch();

if (!$user) {
    redirect('/login.php', 'Session expired. Please log in again.', 'error');
}

// ══════════════════════════════════════════════════════════════════════════
// 3. INITIALIZE PAYSTACK TRANSACTION
// ══════════════════════════════════════════════════════════════════════════

/**
 * Execute a Paystack API request.
 *
 * @param  string $secretKey
 * @param  string $endpoint    Full URL
 * @param  array  $data        POST body (pass null for GET)
 * @return array               Decoded JSON response
 * @throws RuntimeException
 */
function paystack_request(string $secretKey, string $endpoint, ?array $data = null): array
{
    $ch = curl_init($endpoint);

    $headers = [
        'Authorization: Bearer ' . $secretKey,
        'Content-Type: application/json',
        'Cache-Control: no-cache',
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POST,       true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        throw new \RuntimeException("Paystack cURL error: {$curlError}");
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new \RuntimeException("Invalid JSON from Paystack: " . substr($response, 0, 300));
    }

    return $decoded;
}

$initError       = '';
$authorizationUrl = '';

try {
    // Paystack expects amount in kobo (1 KES = 100 kobo equivalent for KES currency)
    // For KES the amount is in cents — multiply by 100
    $amountKobo = (int) round($totalAmount * 100);

    $payload = [
        'email'      => $user['email'],
        'amount'     => $amountKobo,
        'currency'   => 'KES',
        'reference'  => 'PS-' . $orderNumber . '-' . time(),   // Unique reference
        'callback_url' => $callbackUrl,
        'metadata'   => [
            'order_id'      => $orderId,
            'order_number'  => $orderNumber,
            'customer_name' => $user['full_name'],
            'fulfillment'   => $fulfillment,
            'cancel_action' => rtrim(env('APP_URL', ''), '/') . '/checkout.php',
        ],
    ];

    $result = paystack_request(
        $secretKey,
        "{$baseUrl}/transaction/initialize",
        $payload
    );

    if (!($result['status'] ?? false)) {
        throw new \RuntimeException(
            $result['message'] ?? 'Paystack initialization failed.'
        );
    }

    $authorizationUrl    = $result['data']['authorization_url'] ?? '';
    $paystackReference   = $result['data']['reference']          ?? $payload['reference'];
    $paystackAccessCode  = $result['data']['access_code']        ?? '';

    if ($authorizationUrl === '') {
        throw new \RuntimeException('No authorization URL returned by Paystack.');
    }

    // ── Insert pending payment record ─────────────────────────────────────
    $pdo->prepare(
        'INSERT INTO payments
            (order_id, user_id, payment_method, amount, currency,
             status, gateway_reference, gateway_transaction_id, gateway_response)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $orderId,
        get_user_id(),
        'paystack',
        $totalAmount,
        'KES',
        'pending',
        $paystackReference,    // gateway_reference = Paystack reference
        $paystackAccessCode,   // gateway_transaction_id = access_code
        json_encode($result),
    ]);

    // ── Update order to 'processing' ──────────────────────────────────────
    $pdo->prepare(
        "UPDATE orders SET status = 'processing' WHERE id = ? AND status IN ('pending','processing')"
    )->execute([$orderId]);

    // ── Stash reference in session for verify page ────────────────────────
    $_SESSION['paystack_reference'] = $paystackReference;
    $_SESSION['paystack_order_id']  = $orderId;

    // ── Redirect customer to Paystack's hosted checkout ───────────────────
    header('Location: ' . $authorizationUrl);
    exit;

} catch (\Exception $e) {
    error_log('[Paystack Init] Order ' . $orderNumber . ': ' . $e->getMessage());

    $initError = env('APP_DEBUG', false) === true
        ? $e->getMessage()
        : 'Could not connect to Paystack. Please try again.';
}

// ══════════════════════════════════════════════════════════════════════════
// FALLBACK: Show error page if initialization failed
// ══════════════════════════════════════════════════════════════════════════
$pageTitle = 'Payment Error';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-lg mx-auto py-10 px-4">
    <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
        <div class="bg-red-600 px-8 py-8 text-center">
            <div class="inline-flex items-center justify-center w-16 h-16
                        rounded-full bg-white/20 mb-4">
                <svg class="w-8 h-8 text-white" xmlns="http://www.w3.org/2000/svg"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor"
                     stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0
                             2.502-1.667 1.732-3L13.732 4c-.77-1.333
                             -2.694-1.333-3.464 0L3.34 16c-.77 1.333
                             .192 3 1.732 3z"/>
                </svg>
            </div>
            <h1 class="text-white text-2xl font-extrabold">Payment Failed</h1>
        </div>

        <div class="px-8 py-6 text-center">
            <p class="text-gray-600 mb-3">
                We could not initialise the Paystack payment for order
                <strong><?= htmlspecialchars($orderNumber) ?></strong>.
            </p>
            <?php if ($initError): ?>
            <p class="text-sm text-red-600 bg-red-50 rounded-lg px-4 py-2 mb-6">
                <?= htmlspecialchars($initError) ?>
            </p>
            <?php endif; ?>
            <div class="space-y-3">
                <a href="<?= $base ?>/checkout.php"
                   class="btn btn-primary btn-block">Try Again</a>
                <a href="<?= $base ?>/customer/dashboard.php"
                   class="btn btn-ghost btn-block">My Orders</a>
            </div>
            <div class="alert mt-3 text-left"
                 style="background-color: var(--bg-surface); border: 1px solid var(--border-subtle); border-left: 4px solid #D9531E;">
                <strong>Need help with your order or payment?</strong>
                Call or WhatsApp our dispatch desk at
                <a href="tel:+254789453215" style="color: #D9531E; font-weight: 700;">0789453215</a>
                or <a href="https://wa.me/254789453215" target="_blank" rel="noopener noreferrer"
                      style="color: #D9531E; font-weight: 700;">message us on WhatsApp</a>.
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
