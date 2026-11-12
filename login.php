<?php
/**
 * login.php
 *
 * User authentication page.
 * - Accepts email + password
 * - Verifies password hash with password_verify()
 * - Enforces brute-force throttle (5 attempts → 15-minute lockout)
 * - Calls login_user() to write session variables
 * - Keeps admin sign-in separate from customer and attendant authentication
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Already authenticated — go straight to the dashboard
redirect_if_logged_in();

// ── Brute-force throttle constants ────────────────────────────────────────
const MAX_ATTEMPTS    = 5;
const LOCKOUT_SECONDS = 900; // 15 minutes

// ── State ─────────────────────────────────────────────────────────────────
$errors  = [];
$old     = [];
$locked  = false;
$lockRemainingSeconds = 0;

// ── Check for active lockout (stored in session to survive page reloads) ──
if (
    isset($_SESSION['login_attempts'], $_SESSION['login_lockout_until']) &&
    time() < $_SESSION['login_lockout_until']
) {
    $locked               = true;
    $lockRemainingSeconds = $_SESSION['login_lockout_until'] - time();
}

// ══════════════════════════════════════════════════════════════════════════
// POST HANDLER
// ══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {

    // ── 1. Read & sanitise ────────────────────────────────────────────────
    $old   = ['email' => trim($_POST['email'] ?? '')];
    $email    = strtolower($old['email']);
    $password = $_POST['password'] ?? '';

    // ── 2. Basic presence validation ─────────────────────────────────────
    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }

    if ($password === '') {
        $errors['password'] = 'Password is required.';
    }

    // ── 3. Database lookup + password verify ──────────────────────────────
    if (empty($errors)) {
        $pdo  = db();
        $stmt = $pdo->prepare(
            'SELECT id, full_name, email, phone, password_hash, role, is_active
             FROM users
             WHERE email = ?
             LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Use a dummy hash when user not found to prevent timing-based
        // user enumeration attacks
        $dummyHash = '$2y$12$invalidhashthatisnevermatched000000000000000000000000000';
        $hashToCheck = $user ? $user['password_hash'] : $dummyHash;

        $passwordOk = password_verify($password, $hashToCheck);

        if (!$user || !$passwordOk) {
            // Increment attempt counter
            $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;

            if ($_SESSION['login_attempts'] >= MAX_ATTEMPTS) {
                $_SESSION['login_lockout_until'] = time() + LOCKOUT_SECONDS;
                $locked               = true;
                $lockRemainingSeconds = LOCKOUT_SECONDS;
            } else {
                $remaining = MAX_ATTEMPTS - $_SESSION['login_attempts'];
                // Deliberately vague message — don't reveal which field is wrong
                $errors['general'] = "Invalid email or password. {$remaining} attempt(s) remaining before lockout.";
            }

        } elseif ((int) $user['is_active'] === 0) {
            // Account disabled by admin
            $errors['general'] = 'Your account has been deactivated. Please contact support.';

        } else {
            $employeeStatus = null;
            if (in_array($user['role'], ['admin', 'attendant'], true)) {
                $employeeStatement = $pdo->prepare(
                    'SELECT employment_status FROM employees WHERE user_id = ? LIMIT 1'
                );
                $employeeStatement->execute([(int) $user['id']]);
                $employeeStatus = $employeeStatement->fetchColumn();
            }

            // ── SUCCESS ──────────────────────────────────────────────────
            // Clear any throttle data on successful login
            unset(
                $_SESSION['login_attempts'],
                $_SESSION['login_lockout_until']
            );

            // Rehash if the stored hash uses outdated parameters
            if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
                $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([$newHash, $user['id']]);
            }

            if (
                in_array($user['role'], ['admin', 'attendant'], true) &&
                $employeeStatus !== 'employed'
            ) {
                $user['role'] = 'customer';
                login_user($user);
                redirect(
                    '/index.php',
                    'Your staff access is no longer active. You are signed in as a customer.',
                    'info'
                );
            }

            if ($user['role'] === 'admin') {
                header('Location: ' . rtrim((string) env('APP_URL', ''), '/') . '/admin/login.php');
                exit;
            }

            // Write session via auth.php helper
            login_user($user);

            if ($user['role'] === 'owner') {
                redirect('/admin/owner_dashboard.php');
            }

            if ($user['role'] === 'attendant') {
                redirect(
                    '/attendant/index.php',
                    'Welcome back, ' . explode(' ', $user['full_name'])[0] . '!',
                    'success'
                );
            }

            // Honour a ?redirect= param set by require_login() guards
            $redirectTo = $_GET['redirect'] ?? '';
            if (
                $redirectTo !== '' &&
                str_starts_with($redirectTo, '/') &&
                !str_starts_with($redirectTo, '//')
            ) {
                // Safe relative redirect only
                redirect($redirectTo);
            }

            redirect('/index.php', 'Welcome back, ' . explode(' ', $user['full_name'])[0] . '!', 'success');
        }
    }
}

// ── Page meta ─────────────────────────────────────────────────────────────
$pageTitle = 'Sign In';
$bodyClass = 'login-body';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════
     LOGIN PAGE
══════════════════════════════════════════════════════════════════════ -->

<div class="min-h-[70vh] flex items-center justify-center py-10 px-4">
    <div class="w-full max-w-md">

        <!-- Card -->
        <div class="login-card bg-white rounded-2xl shadow-lg overflow-hidden">

            <!-- Card header -->
            <div class="bg-gray-900 px-8 py-6 text-center">
                <div class="inline-flex items-center justify-center w-14 h-14
                            rounded-full bg-yellow-400/10 mb-3">
                    <svg class="w-7 h-7 text-yellow-400" xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor"
                         stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743
                                 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586
                                 a1 1 0 01.293-.707l5.964-5.964A6 6 0
                                 0121 9z"/>
                    </svg>
                </div>
                <h1 class="text-white text-2xl font-bold">Welcome back</h1>
                <p class="text-gray-400 text-sm mt-1">Sign in to your Jengo Hardware account</p>
            </div>

            <!-- Form body -->
            <div class="px-8 py-8">

                <!-- Lockout banner -->
                <?php if ($locked): ?>
                <div class="alert alert-error mb-6" role="alert" aria-live="assertive">
                    <svg class="w-5 h-5 flex-shrink-0" xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor"
                         stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 15v2m-6 4h12a2 2 0 002-2v-6
                                 a2 2 0 00-2-2H6a2 2 0 00-2 2v6
                                 a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                    <div>
                        <p class="font-semibold">Too many failed attempts.</p>
                        <p class="text-sm mt-0.5">
                            Please try again in
                            <span id="lockout-timer" class="font-mono font-bold">
                                <?= gmdate('i:s', $lockRemainingSeconds) ?>
                            </span>.
                        </p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- General error (wrong credentials / deactivated) -->
                <?php if (isset($errors['general'])): ?>
                <div class="alert alert-error mb-6" role="alert" aria-live="polite">
                    <svg class="w-5 h-5 flex-shrink-0" xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor"
                         stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span><?= htmlspecialchars($errors['general']) ?></span>
                </div>
                <?php endif; ?>

                <form method="POST"
                      action="login.php<?= isset($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : '' ?>"
                      novalidate
                      data-submit-guard
                      aria-label="Login form"
                      <?= $locked ? 'inert' : '' ?>>

                    <!-- Email -->
                    <div class="form-group">
                        <label for="email" class="form-label">
                            Email Address <span class="required" aria-hidden="true">*</span>
                        </label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-input <?= isset($errors['email']) ? 'is-error' : '' ?>"
                            value="<?= htmlspecialchars($old['email'] ?? '') ?>"
                            placeholder="you@example.com"
                            maxlength="180"
                            autocomplete="email"
                            required
                            aria-required="true"
                            aria-describedby="<?= isset($errors['email']) ? 'err-email' : '' ?>"
                            <?= $locked ? 'disabled' : '' ?>
                        />
                        <?php if (isset($errors['email'])): ?>
                        <p id="err-email" class="form-error-msg" role="alert">
                            <?= htmlspecialchars($errors['email']) ?>
                        </p>
                        <?php endif; ?>
                    </div>

                    <!-- Password -->
                    <div class="form-group">
                        <div class="flex items-center justify-between mb-1.5">
                            <label for="password" class="form-label mb-0">
                                Password <span class="required" aria-hidden="true">*</span>
                            </label>
                            <a href="#"
                               class="text-xs text-orange hover:text-orange-dark
                                      transition-colors font-medium">
                                Forgot password?
                            </a>
                        </div>
                        <div class="relative">
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="form-input pr-11 <?= isset($errors['password']) ? 'is-error' : '' ?>"
                                placeholder="Enter your password"
                                maxlength="72"
                                autocomplete="current-password"
                                required
                                aria-required="true"
                                aria-describedby="<?= isset($errors['password']) ? 'err-password' : '' ?>"
                                <?= $locked ? 'disabled' : '' ?>
                            />
                            <!-- Show / hide toggle -->
                            <button type="button"
                                    class="absolute inset-y-0 right-0 flex items-center
                                           px-3 text-gray-400 hover:text-gray-600"
                                    data-toggle-password="password"
                                    aria-label="Show password"
                                    tabindex="-1">
                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg"
                                     fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                     stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M2.458 12C3.732 7.943 7.523 5 12 5
                                             c4.478 0 8.268 2.943 9.542 7
                                             -1.274 4.057-5.064 7-9.542 7
                                             -4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                            </button>
                        </div>
                        <?php if (isset($errors['password'])): ?>
                        <p id="err-password" class="form-error-msg" role="alert">
                            <?= htmlspecialchars($errors['password']) ?>
                        </p>
                        <?php endif; ?>
                    </div>

                    <!-- Remember me -->
                    <div class="flex items-center gap-2 mb-6">
                        <input type="checkbox"
                               id="remember"
                               name="remember"
                               value="1"
                               class="w-4 h-4 rounded border-gray-300
                                      text-orange-600 focus:ring-orange-500
                                      cursor-pointer"
                               <?= $locked ? 'disabled' : '' ?> />
                        <label for="remember"
                               class="text-sm text-gray-600 cursor-pointer select-none">
                            Keep me signed in
                        </label>
                    </div>

                    <!-- Submit -->
                    <button
                        type="submit"
                        class="btn btn-primary btn-block btn-lg"
                        data-loading-text="Signing in…"
                        <?= $locked ? 'disabled' : '' ?>>
                        Sign In
                    </button>

                </form>

                <!-- Divider -->
                <div class="divider mt-6">or</div>

                <!-- Register link -->
                <p class="text-center text-sm text-gray-600">
                    Don't have an account?
                    <a href="<?= $base ?>/register.php"
                       class="font-semibold text-orange hover:text-orange-dark transition-colors">
                        Create one free
                    </a>
                </p>

            </div><!-- /form body -->
        </div><!-- /card -->

        <!-- Role hint (helpful during development) -->
        <?php if (env('APP_ENV', 'production') === 'development'): ?>
        <div class="mt-6 bg-gray-800 rounded-xl p-4 text-xs text-gray-400 space-y-1">
            <p class="text-gray-300 font-semibold mb-2">🛠 Dev credentials (seed data)</p>
            <p><span class="text-yellow-400">Admin</span>
               &mdash; admin@jengohardware.co.ke / Admin@1234</p>
        </div>
        <?php endif; ?>

    </div><!-- /max-w-md -->
</div>

<!-- Page-specific JS -->
<script>
(function () {
    // ── Show / hide password toggle ──────────────────────────────────────
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-toggle-password'));
            if (!input) return;
            var isText = input.type === 'text';
            input.type = isText ? 'password' : 'text';
            btn.setAttribute('aria-label', isText ? 'Show password' : 'Hide password');
        });
    });

    // ── Lockout countdown timer ──────────────────────────────────────────
    var timerEl = document.getElementById('lockout-timer');
    if (timerEl) {
        // Parse mm:ss already rendered by PHP
        var parts     = timerEl.textContent.trim().split(':');
        var remaining = parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);

        var interval = setInterval(function () {
            remaining--;
            if (remaining <= 0) {
                clearInterval(interval);
                // Reload so the form becomes usable again
                window.location.reload();
                return;
            }
            var m = String(Math.floor(remaining / 60)).padStart(2, '0');
            var s = String(remaining % 60).padStart(2, '0');
            timerEl.textContent = m + ':' + s;
        }, 1000);
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
