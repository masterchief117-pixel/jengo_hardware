<?php
/**
 * register.php
 *
 * New customer registration page.
 * - Validates and sanitises all input server-side
 * - Normalises phone to 254XXXXXXXXX format
 * - Hashes password with bcrypt (PASSWORD_BCRYPT, cost 12)
 * - Saves the user as role='customer' in the users table
 * - Redirects to login.php with a success flash on completion
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Redirect already-logged-in users to their dashboard
redirect_if_logged_in();

// ── Constants ─────────────────────────────────────────────────────────────
const MIN_PASSWORD_LENGTH = 8;

// ── State ─────────────────────────────────────────────────────────────────
$errors  = [];   // field-keyed error messages
$old     = [];   // repopulate form on validation failure

// ══════════════════════════════════════════════════════════════════════════
// POST HANDLER
// ══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── 1. Read & sanitise raw input ─────────────────────────────────────
    $old = [
        'full_name' => trim($_POST['full_name'] ?? ''),
        'email'     => trim($_POST['email']     ?? ''),
        'phone'     => trim($_POST['phone']      ?? ''),
    ];

    $fullName        = $old['full_name'];
    $email           = strtolower($old['email']);
    $phone           = $old['phone'];
    $password        = $_POST['password']         ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    // ── 2. Validate full name ─────────────────────────────────────────────
    if ($fullName === '') {
        $errors['full_name'] = 'Full name is required.';
    } elseif (mb_strlen($fullName) < 3) {
        $errors['full_name'] = 'Name must be at least 3 characters.';
    } elseif (mb_strlen($fullName) > 120) {
        $errors['full_name'] = 'Name must be 120 characters or fewer.';
    } elseif (!preg_match('/^[\p{L}\s\'\-\.]+$/u', $fullName)) {
        $errors['full_name'] = 'Name contains invalid characters.';
    }

    // ── 3. Validate email ─────────────────────────────────────────────────
    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    } elseif (mb_strlen($email) > 180) {
        $errors['email'] = 'Email address is too long.';
    }

    // ── 4. Validate & normalise phone ─────────────────────────────────────
    $normalisedPhone = null;
    if ($phone === '') {
        $errors['phone'] = 'Phone number is required.';
    } else {
        // Same normalisation logic as assets/js/main.js Phone.normalise()
        $stripped = preg_replace('/[\s\-().]/', '', $phone);

        if (str_starts_with($stripped, '+254') && strlen($stripped) === 13) {
            $normalisedPhone = '254' . substr($stripped, 4);
        } elseif (str_starts_with($stripped, '254') && strlen($stripped) === 12) {
            $normalisedPhone = $stripped;
        } elseif (str_starts_with($stripped, '0') && strlen($stripped) === 10) {
            $normalisedPhone = '254' . substr($stripped, 1);
        }

        if ($normalisedPhone === null) {
            $errors['phone'] = 'Enter a valid Kenyan number (e.g. 0712 345 678).';
        } elseif (!preg_match('/^254(7[0-9]{8}|1[0-1][0-9]{7})$/', $normalisedPhone)) {
            $errors['phone'] = 'Number not recognised as a valid Kenyan mobile number.';
        }
    }

    // ── 5. Validate password ──────────────────────────────────────────────
    if ($password === '') {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < MIN_PASSWORD_LENGTH) {
        $errors['password'] = 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $errors['password'] = 'Password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $errors['password'] = 'Password must contain at least one number.';
    }

    if (!isset($errors['password'])) {
        if ($passwordConfirm === '') {
            $errors['password_confirm'] = 'Please confirm your password.';
        } elseif (!hash_equals($password, $passwordConfirm)) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }
    }

    // ── 6. Check for duplicate email (only if no prior errors) ───────────
    if (empty($errors)) {
        $pdo  = db();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $errors['email'] = 'An account with this email already exists.';
        }
    }

    // ── 7. Check for duplicate phone ─────────────────────────────────────
    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
        $stmt->execute([$normalisedPhone]);

        if ($stmt->fetch()) {
            $errors['phone'] = 'An account with this phone number already exists.';
        }
    }

    // ── 8. Save new user ──────────────────────────────────────────────────
    if (empty($errors)) {
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $pdo->prepare(
            'INSERT INTO users (full_name, email, phone, password_hash, role)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $fullName,
            $email,
            $normalisedPhone,
            $passwordHash,
            'customer',
        ]);

        // Redirect to login with success message
        redirect(
            '/login.php',
            'Account created successfully! Please log in.',
            'success'
        );
    }
}

// ── Page meta ──────────────────────────────────────────────────────────────
$pageTitle = 'Create Account';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════
     REGISTER PAGE
══════════════════════════════════════════════════════════════════════ -->

<div class="min-h-[70vh] flex items-center justify-center py-10 px-4">
    <div class="w-full max-w-md">

        <!-- Card -->
        <div class="bg-white rounded-2xl shadow-lg overflow-hidden">

            <!-- Card header -->
            <div class="bg-gray-900 px-8 py-6 text-center">
                <!-- Hammer icon -->
                <div class="inline-flex items-center justify-center w-14 h-14
                            rounded-full bg-yellow-400/10 mb-3">
                    <svg class="w-7 h-7 text-yellow-400" xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor"
                         stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M14.121 14.121L19 19m-7-7 7-7-3-3-7 7
                                 M9.172 9.172 4.343 4.343 1.515 7.172
                                 5.172 10.828l4-4z" />
                    </svg>
                </div>
                <h1 class="text-white text-2xl font-bold">Create an account</h1>
                <p class="text-gray-400 text-sm mt-1">
                    Join Jengo Hardware and start shopping
                </p>
            </div>

            <!-- Form body -->
            <div class="px-8 py-8">

                <?php if (!empty($errors)): ?>
                <!-- Summary error alert -->
                <div class="alert alert-error mb-6" role="alert" aria-live="polite">
                    <svg class="w-5 h-5 flex-shrink-0" xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor"
                         stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>Please fix the errors below before continuing.</span>
                </div>
                <?php endif; ?>

                <form method="POST" action="register.php"
                      novalidate
                      data-submit-guard
                      aria-label="Registration form">

                    <!-- Full name -->
                    <div class="form-group">
                        <label for="full_name" class="form-label">
                            Full Name <span class="required" aria-hidden="true">*</span>
                        </label>
                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            class="form-input <?= isset($errors['full_name']) ? 'is-error' : '' ?>"
                            value="<?= htmlspecialchars($old['full_name'] ?? '') ?>"
                            placeholder="e.g. Jane Wanjiku"
                            maxlength="120"
                            autocomplete="name"
                            required
                            aria-required="true"
                            aria-describedby="<?= isset($errors['full_name']) ? 'err-full_name' : '' ?>"
                        />
                        <?php if (isset($errors['full_name'])): ?>
                        <p id="err-full_name" class="form-error-msg" role="alert">
                            <?= htmlspecialchars($errors['full_name']) ?>
                        </p>
                        <?php endif; ?>
                    </div>

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
                        />
                        <?php if (isset($errors['email'])): ?>
                        <p id="err-email" class="form-error-msg" role="alert">
                            <?= htmlspecialchars($errors['email']) ?>
                        </p>
                        <?php endif; ?>
                    </div>

                    <!-- Phone number -->
                    <div class="form-group">
                        <label for="phone" class="form-label">
                            Phone Number <span class="required" aria-hidden="true">*</span>
                        </label>
                        <div class="relative">
                            <!-- Kenya flag prefix -->
                            <span class="absolute inset-y-0 left-0 flex items-center
                                         pl-3 pointer-events-none text-sm text-gray-500
                                         select-none"
                                  aria-hidden="true">
                                🇰🇪 +254
                            </span>
                            <input
                                type="tel"
                                id="phone"
                                name="phone"
                                class="form-input pl-20 <?= isset($errors['phone']) ? 'is-error' : '' ?>"
                                value="<?= htmlspecialchars($old['phone'] ?? '') ?>"
                                placeholder="0712 345 678"
                                maxlength="15"
                                autocomplete="tel"
                                data-phone-input
                                data-error-id="err-phone"
                                required
                                aria-required="true"
                                aria-describedby="err-phone"
                            />
                        </div>
                        <p id="err-phone"
                           class="form-error-msg <?= isset($errors['phone']) ? '' : 'hidden' ?>"
                           role="alert">
                            <?= htmlspecialchars($errors['phone'] ?? '') ?>
                        </p>
                        <p class="form-hint">Accepts: 07XXXXXXXX, 01XXXXXXXX, or +254XXXXXXXXX</p>
                    </div>

                    <!-- Password -->
                    <div class="form-group">
                        <label for="password" class="form-label">
                            Password <span class="required" aria-hidden="true">*</span>
                        </label>
                        <div class="relative">
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="form-input pr-11 <?= isset($errors['password']) ? 'is-error' : '' ?>"
                                placeholder="Min. 8 characters"
                                maxlength="72"
                                autocomplete="new-password"
                                required
                                aria-required="true"
                                aria-describedby="password-hint <?= isset($errors['password']) ? 'err-password' : '' ?>"
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
                                          d="M2.458 12C3.732 7.943 7.523 5
                                             12 5c4.478 0 8.268 2.943
                                             9.542 7-1.274 4.057-5.064
                                             7-9.542 7-4.477 0-8.268-2.943
                                             -9.542-7z"/>
                                </svg>
                            </button>
                        </div>
                        <p id="password-hint" class="form-hint">
                            Must be at least 8 characters and include an uppercase letter and a number.
                        </p>
                        <?php if (isset($errors['password'])): ?>
                        <p id="err-password" class="form-error-msg" role="alert">
                            <?= htmlspecialchars($errors['password']) ?>
                        </p>
                        <?php endif; ?>
                    </div>

                    <!-- Confirm password -->
                    <div class="form-group">
                        <label for="password_confirm" class="form-label">
                            Confirm Password <span class="required" aria-hidden="true">*</span>
                        </label>
                        <div class="relative">
                            <input
                                type="password"
                                id="password_confirm"
                                name="password_confirm"
                                class="form-input pr-11 <?= isset($errors['password_confirm']) ? 'is-error' : '' ?>"
                                placeholder="Re-enter your password"
                                maxlength="72"
                                autocomplete="new-password"
                                required
                                aria-required="true"
                                aria-describedby="<?= isset($errors['password_confirm']) ? 'err-password_confirm' : '' ?>"
                            />
                            <button type="button"
                                    class="absolute inset-y-0 right-0 flex items-center
                                           px-3 text-gray-400 hover:text-gray-600"
                                    data-toggle-password="password_confirm"
                                    aria-label="Show confirm password"
                                    tabindex="-1">
                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg"
                                     fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                     stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M2.458 12C3.732 7.943 7.523 5
                                             12 5c4.478 0 8.268 2.943
                                             9.542 7-1.274 4.057-5.064
                                             7-9.542 7-4.477 0-8.268-2.943
                                             -9.542-7z"/>
                                </svg>
                            </button>
                        </div>
                        <?php if (isset($errors['password_confirm'])): ?>
                        <p id="err-password_confirm" class="form-error-msg" role="alert">
                            <?= htmlspecialchars($errors['password_confirm']) ?>
                        </p>
                        <?php endif; ?>
                    </div>

                    <!-- Submit -->
                    <button
                        type="submit"
                        class="btn btn-primary btn-block btn-lg mt-2"
                        data-loading-text="Creating account…">
                        Create Account
                    </button>

                </form>

                <!-- Divider -->
                <div class="divider mt-6">or</div>

                <!-- Login link -->
                <p class="text-center text-sm text-gray-600">
                    Already have an account?
                    <a href="<?= $base ?>/login.php"
                       class="font-semibold text-orange hover:text-orange-dark transition-colors">
                        Sign in
                    </a>
                </p>

            </div><!-- /form body -->
        </div><!-- /card -->

        <!-- Terms note -->
        <p class="text-center text-xs text-gray-400 mt-4 px-4">
            By creating an account you agree to our
            <a href="#" class="underline hover:text-gray-600">Terms of Service</a>
            and
            <a href="#" class="underline hover:text-gray-600">Privacy Policy</a>.
        </p>

    </div><!-- /max-w-md -->
</div>

<!-- Page-specific JS: password show/hide toggle + live password match check -->
<script>
(function () {
    // ── Show / hide password toggle ──────────────────────────────────────
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-toggle-password');
            var input    = document.getElementById(targetId);
            if (!input) return;

            var isText = input.type === 'text';
            input.type = isText ? 'password' : 'text';
            btn.setAttribute('aria-label', isText ? 'Show password' : 'Hide password');
        });
    });

    // ── Live password match indicator ───────────────────────────────────
    var pwInput    = document.getElementById('password');
    var pwConfirm  = document.getElementById('password_confirm');
    var errConfirm = document.getElementById('err-password_confirm');

    if (pwConfirm && pwInput) {
        pwConfirm.addEventListener('input', function () {
            if (pwConfirm.value === '') {
                pwConfirm.classList.remove('is-error', 'is-valid');
                if (errConfirm) { errConfirm.textContent = ''; errConfirm.classList.add('hidden'); }
                return;
            }

            if (pwConfirm.value === pwInput.value) {
                pwConfirm.classList.remove('is-error');
                pwConfirm.classList.add('is-valid');
                if (errConfirm) { errConfirm.textContent = ''; errConfirm.classList.add('hidden'); }
            } else {
                pwConfirm.classList.add('is-error');
                pwConfirm.classList.remove('is-valid');
                if (errConfirm) {
                    errConfirm.textContent = 'Passwords do not match.';
                    errConfirm.classList.remove('hidden');
                }
            }
        });
    }

    // ── Password strength indicator ──────────────────────────────────────
    var pwStrengthBar = null;

    if (pwInput) {
        // Inject a strength bar below the password hint
        var hint = document.getElementById('password-hint');
        if (hint) {
            pwStrengthBar = document.createElement('div');
            pwStrengthBar.className = 'mt-1.5 h-1.5 rounded-full bg-gray-200 overflow-hidden';
            pwStrengthBar.innerHTML = '<div id="strength-fill" class="h-full rounded-full transition-all duration-300 w-0"></div>';
            hint.after(pwStrengthBar);
        }

        pwInput.addEventListener('input', function () {
            var fill = document.getElementById('strength-fill');
            if (!fill) return;

            var v = pwInput.value;
            var score = 0;
            if (v.length >= 8)               score++;
            if (/[A-Z]/.test(v))             score++;
            if (/[0-9]/.test(v))             score++;
            if (/[^A-Za-z0-9]/.test(v))      score++;
            if (v.length >= 12)              score++;

            var widths = ['0%', '20%', '40%', '65%', '85%', '100%'];
            var colors = ['', 'bg-red-400', 'bg-orange-400', 'bg-yellow-400',
                          'bg-lime-500', 'bg-green-500'];

            fill.style.width = widths[score];
            fill.className   = 'h-full rounded-full transition-all duration-300 ' + (colors[score] || '');
        });
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
