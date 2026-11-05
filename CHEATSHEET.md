# Jengo Hardware — Student Cheatsheet

Quick reference notes for the four core concepts used in this project.  
Keep this open while you code. 🛠️

---

## Table of Contents

1. [PDO Database Connections](#1-pdo-database-connections)
2. [Password Hashing in PHP](#2-password-hashing-in-php)
3. [Daraja STK Push Flow (M-Pesa)](#3-daraja-stk-push-flow-m-pesa)
4. [Paystack Payment Flow](#4-paystack-payment-flow)

---

## 1. PDO Database Connections

**PDO** (PHP Data Objects) is the safe way to talk to a MySQL database.  
It uses **prepared statements** which protect against SQL injection.

### Connect to the database

```php
$dsn = "mysql:host=localhost;port=3306;dbname=jengo_hardware;charset=utf8mb4";

$pdo = new PDO($dsn, 'root', '', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // throw errors
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // arrays not objects
    PDO::ATTR_EMULATE_PREPARES   => false,                   // real prepared stmts
]);
```

> In this project `db()` in `config/database.php` does this for you.  
> Just call `$pdo = db();` anywhere after requiring the config file.

---

### SELECT — fetch one row

```php
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();          // returns array or false

if ($user) {
    echo $user['full_name'];
}
```

### SELECT — fetch many rows

```php
$stmt = $pdo->prepare('SELECT * FROM products WHERE is_active = 1');
$stmt->execute();
$products = $stmt->fetchAll();   // returns array of arrays

foreach ($products as $product) {
    echo $product['name'];
}
```

### INSERT a new row

```php
$stmt = $pdo->prepare(
    'INSERT INTO users (full_name, email, password_hash) VALUES (?, ?, ?)'
);
$stmt->execute([$fullName, $email, $passwordHash]);

$newId = (int) $pdo->lastInsertId();  // get the auto-increment ID
```

### UPDATE a row

```php
$stmt = $pdo->prepare(
    'UPDATE orders SET status = ? WHERE id = ?'
);
$stmt->execute(['paid', $orderId]);

$rowsChanged = $stmt->rowCount();  // how many rows were updated
```

### DELETE a row

```php
$stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
$stmt->execute([$productId]);
```

### Transactions (all-or-nothing)

Use a transaction when you need **multiple queries to succeed together**.  
If one fails, everything rolls back.

```php
$pdo->beginTransaction();

try {
    $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')
        ->execute(['paid', $orderId]);

    $pdo->prepare('UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?')
        ->execute([$qty, $productId]);

    $pdo->commit();   // ✅ both worked — save changes

} catch (Exception $e) {
    $pdo->rollBack(); // ❌ something failed — undo everything
    throw $e;
}
```

### ⚠️ Golden rules

| Rule | Why |
|------|-----|
| Always use `?` placeholders | Prevents SQL injection |
| Never put variables directly in SQL strings | `WHERE id = $id` is dangerous |
| Use `FETCH_ASSOC` | Cleaner array keys like `$row['name']` |
| Wrap related queries in transactions | Keeps data consistent |

---

## 2. Password Hashing in PHP

**Never store plain-text passwords.** PHP has built-in functions for this.

### Hash a password (on registration)

```php
$plainPassword = $_POST['password'];   // e.g. "Secret@123"

// password_hash() automatically generates a random salt
$hash = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 12]);

// $hash looks like: $2y$12$R9h/cIPz0gi.URNNX3kh2OPST9/PgBkqquzi.Ss7KIUgO2t0jWMUW
// Store THIS in your database — not the plain password
```

> **Cost 12** means PHP performs 2¹² = 4,096 hashing rounds.  
> Higher cost = slower to crack. 12 is a good balance for most servers.

---

### Verify a password (on login)

```php
$plainPassword  = $_POST['password'];
$storedHash     = $userFromDatabase['password_hash'];

if (password_verify($plainPassword, $storedHash)) {
    echo "Password is correct — log the user in!";
} else {
    echo "Wrong password.";
}
```

> `password_verify()` handles the salt automatically — you never touch it.

---

### Upgrade old hashes silently (on login)

If you change the cost factor later, PHP can rehash transparently on login:

```php
if (password_needs_rehash($storedHash, PASSWORD_BCRYPT, ['cost' => 12])) {
    $newHash = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    // UPDATE users SET password_hash = ? WHERE id = ?
}
```

---

### Full login example

```php
// 1. Fetch user by email
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

// 2. Dummy hash prevents timing attacks when user not found
$dummyHash   = '$2y$12$invalidhashthatisnevermatched0000000';
$hashToCheck = $user ? $user['password_hash'] : $dummyHash;

// 3. Verify (always runs — same time whether user exists or not)
if ($user && password_verify($password, $hashToCheck)) {
    // ✅ Login success — start session
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_role'] = $user['role'];
} else {
    // ❌ Wrong credentials
    $error = 'Invalid email or password.';
}
```

### ⚠️ Golden rules

| Rule | Why |
|------|-----|
| Use `password_hash()` | Automatic salt, bcrypt algorithm |
| Use `password_verify()` | Constant-time comparison, no timing leak |
| Never use `md5()` or `sha1()` | They are fast — easy to crack |
| Never store plain passwords | Non-negotiable |

---

## 3. Daraja STK Push Flow (M-Pesa)

The **Lipa Na M-Pesa Online API** sends a payment prompt directly to the customer's phone.

### The full flow in 5 steps

```
Customer clicks "Pay with M-Pesa"
        │
        ▼
[1] Your server fetches an OAuth token from Daraja
        │
        ▼
[2] Your server sends STK Push request to Daraja
        │
        ▼
[3] Daraja sends a PIN prompt to the customer's phone
        │
        ▼
[4] Customer enters M-Pesa PIN ✅  (or cancels ❌)
        │
        ▼
[5] Daraja POSTs result to your CALLBACK URL
```

---

### Step 1 — Get OAuth Token

```php
$credentials = base64_encode("$consumerKey:$consumerSecret");

$ch = curl_init('https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Basic $credentials"]);

$result      = json_decode(curl_exec($ch), true);
$accessToken = $result['access_token'];  // Valid for 3600 seconds
```

---

### Step 2 — Build the STK Push password

```php
$shortcode = '174379';           // Your till/paybill number
$passkey   = 'your_passkey';     // From Daraja portal
$timestamp = date('YmdHis');     // e.g. 20261104143022

// Formula: base64( shortcode + passkey + timestamp )
$password  = base64_encode($shortcode . $passkey . $timestamp);
```

---

### Step 3 — Send the STK Push

```php
$payload = [
    'BusinessShortCode' => $shortcode,
    'Password'          => $password,
    'Timestamp'         => $timestamp,
    'TransactionType'   => 'CustomerPayBillOnline',
    'Amount'            => 1500,                   // Integer KES (no decimals)
    'PartyA'            => '254712345678',          // Customer phone
    'PartyB'            => $shortcode,
    'PhoneNumber'       => '254712345678',
    'CallBackURL'       => 'https://yoursite.com/payments/mpesa_callback.php',
    'AccountReference'  => 'ORD-20261104-00001',   // Shows on customer's phone
    'TransactionDesc'   => 'Payment for Order #001',
];

$ch = curl_init('https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $accessToken",
    "Content-Type: application/json",
]);

$response = json_decode(curl_exec($ch), true);

// Success response contains:
// $response['ResponseCode']       === '0'
// $response['CheckoutRequestID']  === 'ws_CO_...'  ← save this!
```

---

### Step 4 — Save CheckoutRequestID to database

```php
// Store it so you can match the callback later
$pdo->prepare(
    'UPDATE payments SET gateway_transaction_id = ? WHERE order_id = ?'
)->execute([$response['CheckoutRequestID'], $orderId]);
```

---

### Step 5 — Handle the callback

Daraja sends a POST to your `CallBackURL`:

```php
// mpesa_callback.php
$rawBody = file_get_contents('php://input');
$data    = json_decode($rawBody, true);

$callback   = $data['Body']['stkCallback'];
$resultCode = $callback['ResultCode'];         // 0 = success
$checkoutId = $callback['CheckoutRequestID'];  // Match to your DB

if ($resultCode === 0) {
    $items   = $callback['CallbackMetadata']['Item'];
    $receipt = ''; // MpesaReceiptNumber
    foreach ($items as $item) {
        if ($item['Name'] === 'MpesaReceiptNumber') {
            $receipt = $item['Value'];
        }
    }
    // UPDATE payments SET status = 'success', gateway_reference = ?
    // UPDATE orders   SET status = 'paid'
}

// Always acknowledge Daraja or it will retry
echo json_encode(['ResultCode' => '0', 'ResultDesc' => 'Success']);
```

### ⚠️ Golden rules

| Rule | Why |
|------|-----|
| Use ngrok for local dev | Daraja needs a public HTTPS callback URL |
| Store CheckoutRequestID | Needed to match callback to your order |
| Round amount to integer | Daraja rejects decimal KES amounts |
| Always return `ResultCode: 0` | Stops Daraja from retrying the callback |
| Sandbox shortcode is `174379` | Pre-configured for testing |

---

## 4. Paystack Payment Flow

Paystack redirects the customer to a **hosted payment page** then sends them back to your site.

### The full flow in 5 steps

```
Customer clicks "Pay with Paystack"
        │
        ▼
[1] Your server calls Paystack /transaction/initialize
        │
        ▼
[2] Paystack returns an authorization_url
        │
        ▼
[3] You redirect customer to that URL (Paystack's page)
        │
        ▼
[4] Customer pays on Paystack ✅  (card, M-Pesa, bank)
        │
        ├──▶ [5a] Paystack redirects customer to your CALLBACK_URL
        │         → your server calls /transaction/verify
        │
        └──▶ [5b] Paystack POSTs charge.success to your WEBHOOK_URL
                  → secure server-to-server backup
```

---

### Step 1 — Initialize a transaction

```php
$secretKey = env('PAYSTACK_SECRET_KEY');  // sk_test_xxxx from .env

$payload = [
    'email'        => 'customer@example.com',
    'amount'       => 150000,          // Amount in kobo: 1500 KES × 100
    'currency'     => 'KES',
    'reference'    => 'PS-ORD-001-' . time(),   // Must be unique
    'callback_url' => 'https://yoursite.com/payments/paystack_verify.php',
];

$ch = curl_init('https://api.paystack.co/transaction/initialize');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $secretKey",
    "Content-Type: application/json",
]);

$result = json_decode(curl_exec($ch), true);

// $result['data']['authorization_url']  ← redirect customer here
// $result['data']['reference']          ← save this to DB
```

---

### Step 2 — Save reference & redirect

```php
$reference       = $result['data']['reference'];
$authorizationUrl = $result['data']['authorization_url'];

// Save to DB as 'pending'
$pdo->prepare(
    "INSERT INTO payments (order_id, payment_method, amount, status, gateway_reference)
     VALUES (?, 'paystack', ?, 'pending', ?)"
)->execute([$orderId, $totalAmount, $reference]);

// Send customer to Paystack
header('Location: ' . $authorizationUrl);
exit;
```

---

### Step 3 — Verify on return (paystack_verify.php)

Paystack redirects the customer back to your callback URL with `?reference=xxx`:

```php
// paystack_verify.php
$reference = $_GET['reference'];   // e.g. PS-ORD-001-1730720400

$ch = curl_init("https://api.paystack.co/transaction/verify/" . urlencode($reference));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $secretKey",
]);

$result   = json_decode(curl_exec($ch), true);
$txStatus = $result['data']['status'];   // 'success' | 'failed' | 'abandoned'

if ($txStatus === 'success') {
    // UPDATE payments SET status = 'success' WHERE gateway_reference = ?
    // UPDATE orders   SET status = 'paid'    WHERE id = ?
    header('Location: /customer/dashboard.php');
} else {
    // UPDATE payments SET status = 'failed'
    header('Location: /checkout.php');
}
exit;
```

---

### Step 4 — Webhook signature verification (paystack_webhook.php)

The webhook is the **secure server-to-server backup**. Verify the signature first:

```php
// paystack_webhook.php
$rawBody   = file_get_contents('php://input');
$secretKey = env('PAYSTACK_SECRET_KEY');

// Compute expected signature
$computed = hash_hmac('sha512', $rawBody, $secretKey);
$received = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

// hash_equals() prevents timing attacks
if (!hash_equals($computed, $received)) {
    http_response_code(401);
    exit; // Reject unsigned requests immediately
}

$payload = json_decode($rawBody, true);
$event   = $payload['event'];           // e.g. 'charge.success'
$data    = $payload['data'];
$ref     = $data['reference'];

if ($event === 'charge.success') {
    // UPDATE payments SET status = 'success' WHERE gateway_reference = ?
    // UPDATE orders   SET status = 'paid'    WHERE id = ?
}

// Always respond 200 — Paystack retries on anything else
http_response_code(200);
echo json_encode(['status' => 'ok']);
```

---

### Amount conversion — KES ↔ Kobo

```php
// Sending to Paystack: KES → kobo (multiply by 100)
$amountKobo = (int) round(1500.00 * 100);   // = 150000

// Reading from Paystack callback: kobo → KES (divide by 100)
$amountKes  = $data['amount'] / 100;        // = 1500.00
```

### ⚠️ Golden rules

| Rule | Why |
|------|-----|
| Always verify after redirect | The URL can be tampered with |
| Always validate webhook signature | Anyone can POST to your webhook URL |
| Use `hash_equals()` not `===` | Prevents timing attacks |
| The webhook is the backup | Customer may close browser before redirect |
| Use test keys for development | `sk_test_xxx` from Paystack dashboard |
| Amount is in kobo (×100) | Paystack uses smallest currency unit |

---

## Quick Reference Card

| Task | Function / Endpoint |
|------|---------------------|
| Connect to DB | `$pdo = db()` (uses `config/database.php`) |
| Query one row | `$stmt->fetch()` |
| Query many rows | `$stmt->fetchAll()` |
| Hash a password | `password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12])` |
| Check a password | `password_verify($plain, $hash)` |
| M-Pesa token URL | `GET /oauth/v1/generate?grant_type=client_credentials` |
| M-Pesa STK Push URL | `POST /mpesa/stkpush/v1/processrequest` |
| Paystack initialize | `POST https://api.paystack.co/transaction/initialize` |
| Paystack verify | `GET  https://api.paystack.co/transaction/verify/:ref` |
| Paystack signature | `hash_hmac('sha512', $rawBody, $secretKey)` |

---

*Built with PHP 8, MySQL, Tailwind CSS, Safaricom Daraja API, and Paystack API.*  
*Jengo Hardware — Kenya's Trusted Hardware Store 🔨*
