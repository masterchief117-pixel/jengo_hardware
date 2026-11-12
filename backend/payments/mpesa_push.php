<?php
/**
 * payments/mpesa_push.php
 *
 * Safaricom Daraja — Lipa Na M-Pesa Online (STK Push) initiator.
 *
 * Flow:
 *   1. Validate the pending_order session set by checkout.php
 *   2. Fetch an OAuth 2.0 Bearer token from Daraja
 *   3. Build and send the STK Push payload via cURL
 *   4. Insert a 'pending' row into the payments table
 *   5. Update the order status to 'processing'
 *   6. Show a "waiting for M-Pesa" page that polls for confirmation
 *
 * On success the customer confirms on their phone → Daraja calls
 * payments/mpesa_callback.php which updates the payment + order status.
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
    !isset($pending['order_id'], $pending['total_amount'], $pending['order_number']) ||
    ($pending['payment_method'] ?? '') !== 'mpesa'
) {
    redirect('/cart.php', 'No active M-Pesa order found. Please try again.', 'error');
}

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !is_string($_SESSION['mpesa_push_token'] ?? null) ||
    !is_string($_POST['csrf_token'] ?? null) ||
    !hash_equals($_SESSION['mpesa_push_token'], $_POST['csrf_token'])
) {
    unset($_SESSION['mpesa_push_token']);
    redirect('/checkout.php', 'Your M-Pesa checkout expired. Please try again.', 'error');
}

$submittedPhone = $_POST['phone'] ?? null;
$phone = is_string($submittedPhone) ? format_phone_daraja($submittedPhone) : null;
if ($phone === null) {
    unset($_SESSION['mpesa_push_token']);
    redirect('/checkout.php', 'Enter a valid Kenyan M-Pesa number and try again.', 'error');
}
unset($_SESSION['mpesa_push_token']);

$orderId     = (int)   $pending['order_id'];
$totalAmount = (float) $pending['total_amount'];
$orderNumber = (string)$pending['order_number'];
$pending['phone'] = $phone;
$_SESSION['pending_order'] = $pending;

// Verify the order still exists and is in 'pending' state
$orderStmt = $pdo->prepare(
    'SELECT id, status FROM orders WHERE id = ? AND customer_id = ? LIMIT 1'
);
$orderStmt->execute([$orderId, get_user_id()]);
$order = $orderStmt->fetch();

if (!$order) {
    redirect('/cart.php', 'Order not found.', 'error');
}
if (!in_array($order['status'], ['pending', 'processing'], true)) {
    // Already processed — redirect to thank-you or dashboard
    redirect(
        '/customer/dashboard.php',
        'This order has already been processed.',
        'info'
    );
}

// ══════════════════════════════════════════════════════════════════════════
// 2. DARAJA CONFIG (read from .env via config/database.php → env())
// ══════════════════════════════════════════════════════════════════════════
$consumerKey    = (string) env('MPESA_CONSUMER_KEY',    '');
$consumerSecret = (string) env('MPESA_CONSUMER_SECRET', '');
$shortcode      = (string) env('MPESA_SHORTCODE',       '174379');
$passkey        = (string) env('MPESA_PASSKEY',         '');
$callbackUrl    = (string) env('MPESA_CALLBACK_URL',    '');
$mpesaEnv       = (string) env('MPESA_ENV',             'sandbox');

$baseUrl = $mpesaEnv === 'production'
    ? (string) env('MPESA_PRODUCTION_URL', 'https://api.safaricom.co.ke')
    : (string) env('MPESA_SANDBOX_URL',    'https://sandbox.safaricom.co.ke');

// Basic config guard
if ($consumerKey === '' || $consumerSecret === '' || $passkey === '' || $callbackUrl === '') {
    error_log('[M-Pesa] Missing Daraja credentials in .env');
    redirect(
        '/checkout.php',
        'Payment gateway not configured. Please contact support.',
        'error'
    );
}

// ══════════════════════════════════════════════════════════════════════════
// 3. HELPER FUNCTIONS
// ══════════════════════════════════════════════════════════════════════════

/**
 * Execute a cURL request and return decoded JSON or throw on error.
 *
 * @param  string  $url
 * @param  array   $headers
 * @param  mixed   $postData  Pass null for GET
 * @return array
 * @throws RuntimeException
 */
function daraja_curl(string $url, array $headers, mixed $postData = null): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,       // always verify in production
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    if ($postData !== null) {
        curl_setopt($ch, CURLOPT_POST,       true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    }

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        throw new \RuntimeException("cURL error: {$curlError}");
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        throw new \RuntimeException("Invalid JSON from Daraja: {$response}");
    }

    return $decoded;
}

/**
 * Fetch a short-lived OAuth Bearer token from Daraja.
 *
 * @param string $baseUrl
 * @param string $consumerKey
 * @param string $consumerSecret
 * @return string  The access token
 * @throws RuntimeException
 */
function daraja_get_token(string $baseUrl, string $consumerKey, string $consumerSecret): string
{
    $credentials = base64_encode("{$consumerKey}:{$consumerSecret}");
    $url         = "{$baseUrl}/oauth/v1/generate?grant_type=client_credentials";

    $result = daraja_curl($url, [
        'Authorization: Basic ' . $credentials,
        'Content-Type: application/json',
    ]);

    if (empty($result['access_token'])) {
        throw new \RuntimeException(
            'Failed to get Daraja token: ' . json_encode($result)
        );
    }

    return (string) $result['access_token'];
}

/**
 * Build the Base64-encoded password required by the STK Push API.
 * Formula: base64( Shortcode + Passkey + Timestamp )
 */
function daraja_stk_password(string $shortcode, string $passkey, string $timestamp): string
{
    return base64_encode($shortcode . $passkey . $timestamp);
}

/**
 * Format the phone number for Daraja.
 * Daraja expects 254XXXXXXXXX (no +, no leading 0).
 * Accept Kenyan local or international formats and return normalized digits.
 */
function format_phone_daraja(string $phone): ?string
{
    $phone = preg_replace('/[\s().-]/', '', trim($phone));

    if (str_starts_with($phone, '+254')) {
        $phone = substr($phone, 1);
    } elseif (str_starts_with($phone, '0') && strlen($phone) === 10) {
        $phone = '254' . substr($phone, 1);
    }

    return preg_match('/^254(7[0-9]{8}|1[0-1][0-9]{7})$/', $phone)
        ? $phone
        : null;
}

// ══════════════════════════════════════════════════════════════════════════
// 4. EXECUTE STK PUSH
// ══════════════════════════════════════════════════════════════════════════

$stkError   = '';
$paymentId  = null;
$stkSuccess = false;

try {
    // ── 4a. Get OAuth token ───────────────────────────────────────────────
    $accessToken = daraja_get_token($baseUrl, $consumerKey, $consumerSecret);

    // ── 4b. Build STK Push parameters ────────────────────────────────────
    $timestamp = date('YmdHis');
    $password  = daraja_stk_password($shortcode, $passkey, $timestamp);
    $amount    = (int) ceil($totalAmount);  // Daraja requires integer KES
    $formattedPhone = $phone;

    $payload = [
        'BusinessShortCode' => $shortcode,
        'Password'          => $password,
        'Timestamp'         => $timestamp,
        'TransactionType'   => 'CustomerPayBillOnline',
        'Amount'            => $amount,
        'PartyA'            => $formattedPhone,   // Customer phone
        'PartyB'            => $shortcode,         // Business shortcode
        'PhoneNumber'       => $formattedPhone,   // Phone to receive STK prompt
        'CallBackURL'       => $callbackUrl,
        'AccountReference'  => $orderNumber,      // Shows on customer's phone
        'TransactionDesc'   => 'Jengo Hardware – Order ' . $orderNumber,
    ];

    // ── 4c. Create pending payment record BEFORE calling Daraja
    //        so we have a payment_id to reference in the callback ─────────
    $insertPay = $pdo->prepare(
        'INSERT INTO payments
            (order_id, user_id, payment_method, amount, currency, status, phone_number)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insertPay->execute([
        $orderId,
        get_user_id(),
        'mpesa',
        $totalAmount,
        'KES',
        'pending',
        $formattedPhone,
    ]);
    $paymentId = (int) $pdo->lastInsertId();

    // ── 4d. Send STK Push ─────────────────────────────────────────────────
    $stkUrl    = "{$baseUrl}/mpesa/stkpush/v1/processrequest";
    $stkResult = daraja_curl($stkUrl, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ], $payload);

    // ── 4e. Handle Daraja response ────────────────────────────────────────
    if (
        isset($stkResult['ResponseCode']) &&
        $stkResult['ResponseCode'] === '0'
    ) {
        // STK Push accepted — update order to 'processing'
        $pdo->prepare(
            "UPDATE orders SET status = 'processing' WHERE id = ?"
        )->execute([$orderId]);

        // Store the CheckoutRequestID for matching in callback
        $checkoutRequestId = $stkResult['CheckoutRequestID'] ?? '';
        $pdo->prepare(
            'UPDATE payments
             SET    gateway_transaction_id = ?,
                    gateway_response       = ?
             WHERE  id = ?'
        )->execute([
            $checkoutRequestId,
            json_encode($stkResult),
            $paymentId,
        ]);

        // Store in session so the polling page can reference it
        $_SESSION['mpesa_checkout_request_id'] = $checkoutRequestId;
        $_SESSION['mpesa_payment_id']          = $paymentId;

        $stkSuccess = true;

    } else {
        // Daraja returned an error code
        $errorMsg = $stkResult['errorMessage']
            ?? $stkResult['ResponseDescription']
            ?? 'Unknown Daraja error';

        // Mark payment as failed
        $pdo->prepare(
            'UPDATE payments
             SET    status           = ?,
                    gateway_response = ?
             WHERE  id = ?'
        )->execute(['failed', json_encode($stkResult), $paymentId]);

        $stkError = $errorMsg;
        error_log('[M-Pesa STK] Error for order ' . $orderNumber . ': ' . $errorMsg);
    }

} catch (\Exception $e) {
    error_log('[M-Pesa STK] Exception for order ' . $orderNumber . ': ' . $e->getMessage());

    // Mark payment failed if we already created the record
    if ($paymentId) {
        $pdo->prepare(
            'UPDATE payments SET status = ? WHERE id = ?'
        )->execute(['failed', $paymentId]);
    }

    $stkError = env('APP_DEBUG', false) === true
        ? $e->getMessage()
        : 'Could not reach the payment gateway. Please try again.';
}

// ── Page meta ─────────────────────────────────────────────────────────────
$pageTitle = 'M-Pesa Payment';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════
     M-PESA STK PUSH — WAITING PAGE
══════════════════════════════════════════════════════════════════════ -->

<div class="max-w-lg mx-auto py-10 px-4">

    <?php if ($stkSuccess): ?>
    <!-- ── SUCCESS: Waiting for customer confirmation ─────────────────── -->
    <div class="bg-white rounded-2xl shadow-lg overflow-hidden">

        <!-- Green header -->
        <div class="bg-green-600 px-8 py-8 text-center">
            <div class="inline-flex items-center justify-center w-16 h-16
                        rounded-full bg-white/20 mb-4">
                <!-- Phone / notification icon -->
                <svg class="w-8 h-8 text-white" xmlns="http://www.w3.org/2000/svg"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor"
                     stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0
                             00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                </svg>
            </div>
            <h1 class="text-white text-2xl font-extrabold">
                Check Your Phone!
            </h1>
            <p class="text-green-100 text-sm mt-1">
                An M-Pesa prompt has been sent to your phone.
            </p>
        </div>

        <div class="px-8 py-6 text-center">
            <!-- Phone number display -->
            <p class="text-gray-500 text-sm mb-1">M-Pesa prompt sent to</p>
            <p class="text-xl font-bold text-gray-900 mb-4">
                +<?= htmlspecialchars(
                    substr($formattedPhone, 0, 3) . ' ' .
                    substr($formattedPhone, 3, 3) . ' ' .
                    substr($formattedPhone, 6, 3) . ' ' .
                    substr($formattedPhone, 9)
                ) ?>
            </p>

            <!-- Order summary -->
            <div class="bg-gray-50 rounded-xl p-4 text-sm mb-6">
                <div class="flex justify-between text-gray-600 mb-1">
                    <span>Order</span>
                    <span class="font-mono font-semibold text-gray-800">
                        <?= htmlspecialchars($orderNumber) ?>
                    </span>
                </div>
                <div class="flex justify-between font-bold text-gray-900 text-base">
                    <span>Amount Due</span>
                    <span class="text-green-700">
                        KES <?= number_format($totalAmount, 2) ?>
                    </span>
                </div>
            </div>

            <!-- Spinner + status message -->
            <div id="waiting-state">
                <div class="flex items-center justify-center gap-3 mb-4"
                     aria-live="polite" aria-atomic="true">
                    <div class="spinner" aria-hidden="true"></div>
                    <p class="text-sm text-gray-600" id="status-msg">
                        Waiting for your M-Pesa confirmation…
                    </p>
                </div>

                <p class="text-xs text-gray-400 mb-6">
                    Enter your M-Pesa PIN on your phone to complete payment.
                    This page will update automatically.
                </p>
            </div>

            <!-- Confirmed state (shown by JS after polling) -->
            <div id="confirmed-state" class="hidden">
                <div class="flex items-center justify-center gap-2
                            text-green-600 font-bold text-lg mb-4">
                    <svg class="w-7 h-7" xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor"
                         stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0
                                 9 9 0 0118 0z"/>
                    </svg>
                    Payment Confirmed!
                </div>
                <p class="text-sm text-gray-500 mb-4">
                    Redirecting you to your order summary…
                </p>
            </div>

            <!-- Failed state -->
            <div id="failed-state" class="hidden">
                <div class="alert alert-error mb-4 text-left">
                    <svg class="w-5 h-5 flex-shrink-0" xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor"
                         stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    <span id="failed-msg">Payment was cancelled or failed.</span>
                </div>
                <a href="<?= $base ?>/checkout.php"
                   class="btn btn-primary btn-block">Try Again</a>
            </div>

            <!-- Manual check link (fallback) -->
            <p class="text-xs text-gray-400 mt-4" id="manual-check">
                Didn't receive the prompt?
                <a href="<?= $base ?>/customer/dashboard.php"
                   class="text-orange font-semibold hover:underline">
                    Check My Orders
                </a>
            </p>
        </div>
    </div>

    <!-- Steps reminder -->
    <div class="bg-white rounded-xl border border-gray-100 p-5 mt-4 text-sm">
        <p class="font-semibold text-gray-700 mb-3">How to complete:</p>
        <ol class="space-y-2 text-gray-500" role="list">
            <li class="flex items-start gap-2">
                <span class="w-5 h-5 rounded-full bg-green-600 text-white text-xs
                             font-bold flex items-center justify-center flex-shrink-0
                             mt-0.5" aria-hidden="true">1</span>
                Check your phone for an M-Pesa push notification.
            </li>
            <li class="flex items-start gap-2">
                <span class="w-5 h-5 rounded-full bg-green-600 text-white text-xs
                             font-bold flex items-center justify-center flex-shrink-0
                             mt-0.5" aria-hidden="true">2</span>
                Enter your <strong>M-Pesa PIN</strong> to authorise the payment.
            </li>
            <li class="flex items-start gap-2">
                <span class="w-5 h-5 rounded-full bg-green-600 text-white text-xs
                             font-bold flex items-center justify-center flex-shrink-0
                             mt-0.5" aria-hidden="true">3</span>
                This page will update automatically once confirmed.
            </li>
        </ol>
    </div>

    <?php else: ?>
    <!-- ── STK PUSH FAILED ─────────────────────────────────────────────── -->
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
            <p class="text-gray-600 mb-2">
                We could not initiate the M-Pesa payment.
            </p>
            <?php if ($stkError !== ''): ?>
            <p class="text-sm text-red-600 bg-red-50 rounded-lg px-4 py-2 mb-6">
                <?= htmlspecialchars($stkError) ?>
            </p>
            <?php endif; ?>

            <p class="text-sm text-gray-500 mb-6">
                Your order <strong><?= htmlspecialchars($orderNumber) ?></strong>
                has been saved. You can retry the payment from your dashboard.
            </p>

            <div class="space-y-3">
                <a href="<?= $base ?>/checkout.php"
                   class="btn btn-primary btn-block">
                    Try Again
                </a>
                <a href="<?= $base ?>/customer/dashboard.php"
                   class="btn btn-ghost btn-block">
                    My Orders
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /max-w-lg -->


<!-- ══════════════════════════════════════════════════════════════════════
     POLLING JS (only when STK Push succeeded)
══════════════════════════════════════════════════════════════════════ -->
<?php if ($stkSuccess): ?>
<script>
(function () {
    var paymentId  = <?= json_encode($paymentId) ?>;
    var orderId    = <?= json_encode($orderId) ?>;
    var dashUrl    = <?= json_encode($base . '/customer/dashboard.php') ?>;
    var maxRetries = 24;   // 24 × 5 s = 2 minutes max
    var retries    = 0;
    var interval   = null;

    function setStatus(msg) {
        var el = document.getElementById('status-msg');
        if (el) el.textContent = msg;
    }

    function showConfirmed() {
        document.getElementById('waiting-state')?.classList.add('hidden');
        document.getElementById('confirmed-state')?.classList.remove('hidden');
        document.getElementById('manual-check')?.classList.add('hidden');
        // Redirect after 2 seconds
        setTimeout(function () {
            window.location.href = dashUrl + '?flash=paid';
        }, 2000);
    }

    function showFailed(msg) {
        document.getElementById('waiting-state')?.classList.add('hidden');
        document.getElementById('failed-state')?.classList.remove('hidden');
        document.getElementById('manual-check')?.classList.add('hidden');
        var el = document.getElementById('failed-msg');
        if (el && msg) el.textContent = msg;
    }

    function poll() {
        if (retries >= maxRetries) {
            clearInterval(interval);
            setStatus('Payment timed out. Check your orders for status.');
            return;
        }

        retries++;
        setStatus('Checking payment status… (' + retries + '/' + maxRetries + ')');

        fetch('<?= $base ?>/payments/mpesa_status.php?payment_id=' + paymentId, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.status === 'success') {
                clearInterval(interval);
                showConfirmed();
            } else if (data.status === 'failed' || data.status === 'cancelled') {
                clearInterval(interval);
                showFailed(data.message || 'Payment was not completed.');
            }
            // 'pending' → keep polling
        })
        .catch(function () {
            // Network error — keep polling silently
        });
    }

    // Start polling every 5 seconds
    interval = setInterval(poll, 5000);

    // Also poll immediately after 3 seconds (first attempt)
    setTimeout(poll, 3000);
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
