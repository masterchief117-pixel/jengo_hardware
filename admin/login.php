<?php
/**
 * Sign in to the independent admin portal.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (admin_user_id() !== null) {
    require_admin_login();
    header('Location: ' . rtrim((string) env('APP_URL', ''), '/') . '/admin/dashboard.php');
    exit;
}

$error = '';
if (empty($_SESSION['admin_login_csrf'])) {
    $_SESSION['admin_login_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $storedToken = (string) $_SESSION['admin_login_csrf'];

    if (!hash_equals($storedToken, $submittedToken)) {
        $error = 'Your sign-in session expired. Refresh the page and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Enter a valid email address and password.';
    } else {
        try {
            $statement = db()->prepare(
                'SELECT id, full_name, email, password_hash, is_active
                 FROM admin_users
                 WHERE email = ?
                 LIMIT 1'
            );
            $statement->execute([$email]);
            $admin = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            error_log('[Admin Login] Admin account table unavailable: ' . $exception->getMessage());
            $admin = false;
            $error = 'Admin login is not configured yet. Apply database/admin_auth.sql first.';
        }

        if ($error === '') {
            $dummyHash = '$2y$12$invalidhashthatisnevermatched000000000000000000000000000';
            $passwordValid = password_verify(
                $password,
                $admin ? (string) $admin['password_hash'] : $dummyHash
            );

            if (!$admin || !$passwordValid || (int) $admin['is_active'] !== 1) {
                $error = 'Invalid admin email or password.';
            } else {
                session_regenerate_id(true);
                $_SESSION['admin_user_id'] = (int) $admin['id'];
                $_SESSION['admin_user_name'] = (string) $admin['full_name'];
                $_SESSION['admin_user_email'] = (string) $admin['email'];
                unset($_SESSION['admin_login_csrf']);

                header('Location: ' . rtrim((string) env('APP_URL', ''), '/') . '/admin/dashboard.php');
                exit;
            }
        }
    }
}

$base = rtrim((string) env('APP_URL', ''), '/');
$escape = static fn (string $value): string =>
    htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Sign In — Jengo Hardware</title>
    <link rel="stylesheet" href="<?= $escape($base) ?>/assets/css/style.css">
</head>
<body class="bg-gray-100">
    <main class="min-h-screen flex items-center justify-center px-4 py-10">
        <section class="w-full max-w-md bg-white rounded-2xl shadow-lg p-8">
            <h1 class="text-2xl font-extrabold text-gray-900 text-center">
                Admin Portal
            </h1>
            <p class="text-sm text-gray-500 text-center mt-2 mb-6">
                Sign in with your separate administrator account.
            </p>

            <?php if ($error !== ''): ?>
                <p class="alert alert-error mb-5" role="alert">
                    <?= $escape($error) ?>
                </p>
            <?php endif; ?>

            <form method="post" action="<?= $escape($base) ?>/admin/login.php"
                  class="space-y-4">
                <input type="hidden" name="csrf_token"
                       value="<?= $escape((string) $_SESSION['admin_login_csrf']) ?>">
                <div>
                    <label for="admin-email" class="form-label">Admin Email</label>
                    <input id="admin-email" name="email" type="email"
                           class="form-input" autocomplete="username"
                           maxlength="180" required>
                </div>
                <div>
                    <label for="admin-password" class="form-label">Password</label>
                    <input id="admin-password" name="password" type="password"
                           class="form-input" autocomplete="current-password"
                           required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">
                    Sign in to Admin Portal
                </button>
            </form>
        </section>
    </main>
</body>
</html>
