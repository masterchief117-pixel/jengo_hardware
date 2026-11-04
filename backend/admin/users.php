<?php
/**
 * admin/users.php
 *
 * User management panel.
 *
 * GET  ?view=list          — paginated user table (default)
 * GET  ?view=add           — blank "Add User" form
 * GET  ?view=edit&id=X     — pre-filled edit form
 * POST action=save         — insert or update user
 * POST action=set_role     — quick role change from the list
 * POST action=toggle_active — activate / deactivate a user
 * POST action=reset_password — force a new password on a user
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_admin();

$pdo    = db();
$selfId = get_user_id();   // Logged-in admin's own ID (protected from demotion)

// ══════════════════════════════════════════════════════════════════════════
// VALID ROLES
// ══════════════════════════════════════════════════════════════════════════
const ROLES = ['customer', 'attendant', 'admin'];

// Role badge helper
function role_badge(string $role): string
{
    $map = [
        'customer'  => 'badge-info',
        'attendant' => 'badge-warning',
        'admin'     => 'badge-orange',
    ];
    $cls = $map[$role] ?? 'badge-charcoal';
    return "<span class=\"badge {$cls} capitalize\">{$role}</span>";
}

// ══════════════════════════════════════════════════════════════════════════
// POST HANDLER
// ══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int) ($_POST['user_id'] ?? 0);

    // ── SAVE (add or edit) ────────────────────────────────────────────────
    if ($action === 'save') {
        $isNew    = $userId === 0;
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $phone    = trim($_POST['phone'] ?? '');
        $role     = $_POST['role'] ?? 'customer';
        $password = $_POST['password'] ?? '';
        $errors   = [];

        // Validate name
        if ($fullName === '') {
            $errors['full_name'] = 'Full name is required.';
        } elseif (mb_strlen($fullName) < 3) {
            $errors['full_name'] = 'Name must be at least 3 characters.';
        }

        // Validate email
        if ($email === '') {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } else {
            $s = $pdo->prepare(
                'SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1'
            );
            $s->execute([$email, $userId]);
            if ($s->fetch()) $errors['email'] = 'This email is already registered.';
        }

        // Validate phone
        $normPhone = null;
        if ($phone !== '') {
            $stripped = preg_replace('/[\s\-().]/', '', $phone);
            if (str_starts_with($stripped, '+254') && strlen($stripped) === 13) {
                $normPhone = '254' . substr($stripped, 4);
            } elseif (str_starts_with($stripped, '254') && strlen($stripped) === 12) {
                $normPhone = $stripped;
            } elseif (str_starts_with($stripped, '0') && strlen($stripped) === 10) {
                $normPhone = '254' . substr($stripped, 1);
            }
            if (!$normPhone || !preg_match('/^254(7[0-9]{8}|1[0-1][0-9]{7})$/', $normPhone)) {
                $errors['phone'] = 'Enter a valid Kenyan mobile number.';
                $normPhone = null;
            } else {
                // Phone uniqueness
                $s = $pdo->prepare(
                    'SELECT id FROM users WHERE phone = ? AND id != ? LIMIT 1'
                );
                $s->execute([$normPhone, $userId]);
                if ($s->fetch()) $errors['phone'] = 'This phone number is already registered.';
            }
        } else {
            if ($isNew) $errors['phone'] = 'Phone number is required.';
        }

        // Validate role
        if (!in_array($role, ROLES, true)) {
            $errors['role'] = 'Invalid role selected.';
        }

        // Protect self from demotion
        if (!$isNew && $userId === $selfId && $role !== 'admin') {
            $errors['role'] = 'You cannot change your own role.';
        }

        // Password (required for new, optional for edit)
        $passwordHash = null;
        if ($isNew) {
            if ($password === '') {
                $errors['password'] = 'Password is required for new users.';
            } elseif (strlen($password) < 8) {
                $errors['password'] = 'Password must be at least 8 characters.';
            } elseif (!preg_match('/[A-Z]/', $password)) {
                $errors['password'] = 'Password must contain at least one uppercase letter.';
            } elseif (!preg_match('/[0-9]/', $password)) {
                $errors['password'] = 'Password must contain at least one number.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            }
        } elseif ($password !== '') {
            if (strlen($password) < 8) {
                $errors['password'] = 'New password must be at least 8 characters.';
            } else {
                $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            }
        }

        if (empty($errors)) {
            if ($isNew) {
                $pdo->prepare(
                    'INSERT INTO users
                        (full_name, email, phone, password_hash, role, is_active)
                     VALUES (?, ?, ?, ?, ?, 1)'
                )->execute([
                    $fullName, $email,
                    $normPhone ?? $phone,
                    $passwordHash, $role,
                ]);
                redirect(
                    '/admin/users.php',
                    "User \"{$fullName}\" created successfully.",
                    'success'
                );
            } else {
                // Update — only update password if provided
                if ($passwordHash !== null) {
                    $pdo->prepare(
                        'UPDATE users SET full_name=?, email=?, phone=?,
                                          password_hash=?, role=?
                         WHERE id=?'
                    )->execute([
                        $fullName, $email,
                        $normPhone ?? $phone,
                        $passwordHash, $role, $userId,
                    ]);
                } else {
                    $pdo->prepare(
                        'UPDATE users SET full_name=?, email=?, phone=?, role=?
                         WHERE id=?'
                    )->execute([
                        $fullName, $email,
                        $normPhone ?? $phone,
                        $role, $userId,
                    ]);
                }
                redirect(
                    '/admin/users.php',
                    "User \"{$fullName}\" updated.",
                    'success'
                );
            }
        }

        // Validation failed — fall through to show form
        $_GET['view'] = $isNew ? 'add' : 'edit';
        if (!$isNew) $_GET['id'] = $userId;
    }

    // ── QUICK ROLE CHANGE ─────────────────────────────────────────────────
    elseif ($action === 'set_role') {
        $newRole = $_POST['new_role'] ?? '';

        if (!in_array($newRole, ROLES, true)) {
            redirect('/admin/users.php', 'Invalid role.', 'error');
        }
        if ($userId === $selfId) {
            redirect('/admin/users.php', 'You cannot change your own role.', 'error');
        }

        $pdo->prepare(
            'UPDATE users SET role = ? WHERE id = ?'
        )->execute([$newRole, $userId]);

        redirect(
            '/admin/users.php',
            "Role updated to \"{$newRole}\".",
            'success'
        );
    }

    // ── TOGGLE ACTIVE ─────────────────────────────────────────────────────
    elseif ($action === 'toggle_active') {
        if ($userId === $selfId) {
            redirect('/admin/users.php', 'You cannot deactivate your own account.', 'error');
        }
        // Flip the current is_active value
        $pdo->prepare(
            'UPDATE users SET is_active = NOT is_active WHERE id = ?'
        )->execute([$userId]);

        redirect('/admin/users.php', 'User status updated.', 'info');
    }

    // ── RESET PASSWORD ────────────────────────────────────────────────────
    elseif ($action === 'reset_password') {
        $newPass = trim($_POST['new_password'] ?? '');

        if (strlen($newPass) < 8) {
            redirect('/admin/users.php', 'Password must be at least 8 characters.', 'error');
        }

        $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare(
            'UPDATE users SET password_hash = ? WHERE id = ?'
        )->execute([$hash, $userId]);

        redirect('/admin/users.php', 'Password reset successfully.', 'success');
    }

    else {
        redirect('/admin/users.php', 'Unknown action.', 'error');
    }
}

// ══════════════════════════════════════════════════════════════════════════
// GET ROUTING
// ══════════════════════════════════════════════════════════════════════════
$view   = $_GET['view'] ?? 'list';
$editId = (int) ($_GET['id'] ?? 0);
$editUser = null;

if ($view === 'edit' && $editId > 0) {
    $s = $pdo->prepare(
        'SELECT id, full_name, email, phone, role, is_active, created_at
         FROM users WHERE id = ? LIMIT 1'
    );
    $s->execute([$editId]);
    $editUser = $s->fetch();
    if (!$editUser) {
        redirect('/admin/users.php', 'User not found.', 'error');
    }
}

// ── List filters ──────────────────────────────────────────────────────────
$search      = trim($_GET['q']      ?? '');
$filterRole  = $_GET['role']        ?? '';
$filterActive= $_GET['active']      ?? '';   // '1' | '0' | ''
$perPage     = 20;
$page        = max(1, (int) ($_GET['page'] ?? 1));
$offset      = ($page - 1) * $perPage;

$conditions  = [];
$bindings    = [];

if ($search !== '') {
    $conditions[] = '(full_name LIKE ? OR email LIKE ? OR phone LIKE ?)';
    $like = '%' . $search . '%';
    $bindings = array_merge($bindings, [$like, $like, $like]);
}
if (in_array($filterRole, ROLES, true)) {
    $conditions[] = 'role = ?';
    $bindings[]   = $filterRole;
}
if ($filterActive === '1') {
    $conditions[] = 'is_active = 1';
} elseif ($filterActive === '0') {
    $conditions[] = 'is_active = 0';
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users $where");
$countStmt->execute($bindings);
$totalUsers = (int) $countStmt->fetchColumn();
$totalPages = (int) ceil($totalUsers / $perPage);
$page       = min($page, max(1, $totalPages ?: 1));

$usersStmt = $pdo->prepare(
    "SELECT u.id, u.full_name, u.email, u.phone,
            u.role, u.is_active, u.created_at,
            (SELECT COUNT(*) FROM orders o
             WHERE o.customer_id = u.id) AS order_count
     FROM users u
     $where
     ORDER BY u.created_at DESC
     LIMIT ? OFFSET ?"
);
$usersStmt->execute(array_merge($bindings, [$perPage, $offset]));
$users = $usersStmt->fetchAll();

// Role counts for tab bar
$roleCounts = [];
foreach ($pdo->query(
    "SELECT role, SUM(is_active) AS active_count,
            COUNT(*) AS total_count
     FROM users GROUP BY role"
)->fetchAll() as $r) {
    $roleCounts[$r['role']] = [
        'active' => (int)$r['active_count'],
        'total'  => (int)$r['total_count'],
    ];
}

$pageTitle = $view === 'add'  ? 'Add User'
           : ($view === 'edit' ? 'Edit User'
           : 'Users');

require_once __DIR__ . '/../includes/header.php';

// ── Reusable sidebar ─────────────────────────────────────────────────────
function admin_sidebar(string $base): void
{
    $links = [
        ['Dashboard', 'index.php',           'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
        ['Products',  'products.php',        'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10'],
        ['Orders',    'orders.php',          'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
        ['Users',     'users.php',           'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
        ['Payments',  'payments_record.php', 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z'],
    ];
    $cur = basename($_SERVER['PHP_SELF']);
    echo '<aside class="admin-sidebar hidden lg:flex flex-col w-56 xl:w-60 sticky top-16 self-start h-[calc(100vh-4rem)] overflow-y-auto" aria-label="Admin navigation">';
    echo '<div class="admin-sidebar__brand"><svg class="w-5 h-5 text-yellow-400 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.121 14.121L19 19m-7-7 7-7-3-3-7 7M9.172 9.172 4.343 4.343 1.515 7.172 5.172 10.828l4-4z"/></svg>Admin Panel</div>';
    foreach ($links as [$lbl, $file, $icon]) {
        $a = $file === $cur ? 'active' : '';
        echo "<a href=\"{$base}/admin/{$file}\" class=\"admin-sidebar__link {$a}\" " . ($a ? 'aria-current="page"' : '') . ">";
        echo "<svg class=\"w-4 h-4 flex-shrink-0\" xmlns=\"http://www.w3.org/2000/svg\" fill=\"none\" viewBox=\"0 0 24 24\" stroke=\"currentColor\" stroke-width=\"1.8\" aria-hidden=\"true\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" d=\"{$icon}\"/></svg>";
        echo "{$lbl}</a>";
    }
    echo '<div class="border-t border-gray-700/50 my-3 mx-3"></div>';
    echo "<a href=\"{$base}/shop.php\" class=\"admin-sidebar__link\">🛒 View Shop</a>";
    echo "<a href=\"{$base}/logout.php\" class=\"admin-sidebar__link\">🚪 Sign Out</a>";
    echo '</aside>';
}
?>

<!-- ══════════════════════════════════════════════════════════════════════
     ADMIN LAYOUT
══════════════════════════════════════════════════════════════════════ -->
<div class="flex gap-0 -mx-4 sm:-mx-6 lg:-mx-8 min-h-screen">

    <?php admin_sidebar($base); ?>

    <div class="flex-1 min-w-0 px-4 sm:px-6 lg:px-8 py-6">

    <?php if (in_array($view, ['add', 'edit'])): ?>
    <!-- ══════════════════════════════════════════════════════════════════
         ADD / EDIT FORM
    ══════════════════════════════════════════════════════════════════ -->

    <?php $isEdit = $view === 'edit' && $editUser !== null; ?>

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-extrabold text-gray-900">
            <?= $isEdit ? 'Edit User' : 'Add New User' ?>
        </h1>
        <a href="<?= $base ?>/admin/users.php"
           class="btn btn-secondary btn-sm">← Back to Users</a>
    </div>

    <?php if (!empty($errors ?? [])): ?>
    <div class="alert alert-error mb-6" role="alert">
        <svg class="w-5 h-5 flex-shrink-0" xmlns="http://www.w3.org/2000/svg"
             fill="none" viewBox="0 0 24 24" stroke="currentColor"
             stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <span>Please fix the errors below.</span>
    </div>
    <?php endif; ?>

    <form method="POST" action="users.php"
          data-submit-guard
          aria-label="<?= $isEdit ? 'Edit' : 'Add' ?> user form">
        <input type="hidden" name="action"  value="save">
        <input type="hidden" name="user_id" value="<?= $isEdit ? (int)$editUser['id'] : 0 ?>">

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- ── Left: core fields ─────────────────────────────────── -->
            <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-100
                        shadow-sm p-6 space-y-5">

                <!-- Full name -->
                <div class="form-group">
                    <label for="full_name" class="form-label">
                        Full Name <span class="required">*</span>
                    </label>
                    <input type="text" id="full_name" name="full_name"
                           class="form-input <?= isset($errors['full_name'] ?? null) ? 'is-error' : '' ?>"
                           value="<?= htmlspecialchars($editUser['full_name'] ?? ($_POST['full_name'] ?? '')) ?>"
                           maxlength="120" required autocomplete="name" />
                    <?php if (isset($errors['full_name'])): ?>
                    <p class="form-error-msg" role="alert">
                        <?= htmlspecialchars($errors['full_name']) ?>
                    </p>
                    <?php endif; ?>
                </div>

                <!-- Email + Phone -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="form-group mb-0">
                        <label for="email" class="form-label">
                            Email <span class="required">*</span>
                        </label>
                        <input type="email" id="email" name="email"
                               class="form-input <?= isset($errors['email'] ?? null) ? 'is-error' : '' ?>"
                               value="<?= htmlspecialchars($editUser['email'] ?? ($_POST['email'] ?? '')) ?>"
                               maxlength="180" required autocomplete="email" />
                        <?php if (isset($errors['email'])): ?>
                        <p class="form-error-msg" role="alert">
                            <?= htmlspecialchars($errors['email']) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <div class="form-group mb-0">
                        <label for="phone" class="form-label">
                            Phone <?= !$isEdit ? '<span class="required">*</span>' : '' ?>
                        </label>
                        <input type="tel" id="phone" name="phone"
                               class="form-input <?= isset($errors['phone'] ?? null) ? 'is-error' : '' ?>"
                               value="<?= htmlspecialchars($editUser['phone'] ?? ($_POST['phone'] ?? '')) ?>"
                               placeholder="07XX XXX XXX"
                               maxlength="15"
                               data-phone-input data-error-id="err-phone" />
                        <p id="err-phone"
                           class="form-error-msg <?= isset($errors['phone']) ? '' : 'hidden' ?>"
                           role="alert">
                            <?= htmlspecialchars($errors['phone'] ?? '') ?>
                        </p>
                    </div>
                </div>

                <!-- Role -->
                <div class="form-group">
                    <label for="role" class="form-label">
                        Role <span class="required">*</span>
                    </label>
                    <div class="grid grid-cols-3 gap-3">
                        <?php
                        $roleDescriptions = [
                            'customer'  => 'Can browse & buy products',
                            'attendant' => 'POS access, manages fulfilment',
                            'admin'     => 'Full system access',
                        ];
                        $currentRole = $editUser['role'] ?? ($_POST['role'] ?? 'customer');
                        foreach (ROLES as $r):
                            $isProtected = $isEdit && (int)$editUser['id'] === $selfId && $r !== 'admin';
                        ?>
                        <label class="flex flex-col gap-1 p-3 border-2 rounded-xl
                                      cursor-pointer transition-all
                                      <?= $currentRole === $r
                                          ? 'border-orange bg-orange-subtle'
                                          : 'border-gray-200 hover:border-orange/50' ?>
                                      <?= $isProtected ? 'opacity-50 cursor-not-allowed' : '' ?>">
                            <input type="radio" name="role" value="<?= $r ?>"
                                   class="sr-only"
                                   <?= $currentRole === $r ? 'checked' : '' ?>
                                   <?= $isProtected ? 'disabled' : '' ?> />
                            <span class="font-semibold text-sm text-gray-800 capitalize">
                                <?= $r ?>
                            </span>
                            <span class="text-xs text-gray-500">
                                <?= $roleDescriptions[$r] ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if (isset($errors['role'])): ?>
                    <p class="form-error-msg mt-2" role="alert">
                        <?= htmlspecialchars($errors['role']) ?>
                    </p>
                    <?php endif; ?>
                    <?php if ($isEdit && (int)$editUser['id'] === $selfId): ?>
                    <p class="form-hint">You cannot change your own role.</p>
                    <?php endif; ?>
                </div>

                <!-- Password -->
                <div class="form-group mb-0">
                    <label for="password" class="form-label">
                        Password
                        <?= !$isEdit
                            ? '<span class="required">*</span>'
                            : '<span class="text-gray-400 font-normal">(leave blank to keep current)</span>' ?>
                    </label>
                    <div class="relative">
                        <input type="password" id="password" name="password"
                               class="form-input pr-11 <?= isset($errors['password'] ?? null) ? 'is-error' : '' ?>"
                               placeholder="<?= $isEdit ? 'Enter new password to change…' : 'Min. 8 chars, 1 uppercase, 1 number' ?>"
                               maxlength="72"
                               autocomplete="new-password"
                               <?= !$isEdit ? 'required' : '' ?> />
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
                                      d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478
                                         0 8.268 2.943 9.542 7-1.274 4.057-5.064
                                         7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                        </button>
                    </div>
                    <?php if (isset($errors['password'])): ?>
                    <p class="form-error-msg" role="alert">
                        <?= htmlspecialchars($errors['password']) ?>
                    </p>
                    <?php endif; ?>
                </div>

            </div><!-- /left -->


            <!-- ── Right: status + quick reset ──────────────────────── -->
            <div class="space-y-5">

                <!-- Active status -->
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <p class="text-sm font-semibold text-gray-700 mb-3">
                        Account Status
                    </p>
                    <?php
                    $isActive = isset($editUser) ? (int)$editUser['is_active'] : 1;
                    ?>
                    <div class="flex items-center gap-3">
                        <span class="w-3 h-3 rounded-full <?= $isActive ? 'bg-green-500' : 'bg-gray-300' ?>
                                     flex-shrink-0" aria-hidden="true"></span>
                        <span class="text-sm font-medium text-gray-700">
                            <?= $isActive ? 'Active' : 'Deactivated' ?>
                        </span>
                    </div>
                    <?php if ($isEdit && (int)$editUser['id'] !== $selfId): ?>
                    <form method="POST" action="users.php" class="mt-3">
                        <input type="hidden" name="action"  value="toggle_active">
                        <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
                        <button type="submit"
                                class="btn btn-sm btn-block
                                       <?= $isActive
                                           ? 'btn-danger'
                                           : 'bg-green-600 text-white hover:bg-green-700 btn' ?>"
                                data-confirm="<?= $isActive ? 'Deactivate this user?' : 'Reactivate this user?' ?>">
                            <?= $isActive ? 'Deactivate Account' : 'Reactivate Account' ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if ($isEdit): ?>
                <!-- Quick password reset -->
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <p class="text-sm font-semibold text-gray-700 mb-3">
                        Force Password Reset
                    </p>
                    <form method="POST" action="users.php">
                        <input type="hidden" name="action"  value="reset_password">
                        <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
                        <div class="form-group mb-3">
                            <input type="password" name="new_password"
                                   class="form-input py-2 text-sm"
                                   placeholder="New password (min 8 chars)"
                                   minlength="8" required />
                        </div>
                        <button type="submit"
                                class="btn btn-secondary btn-sm btn-block"
                                data-confirm="Reset password for this user?">
                            Reset Password
                        </button>
                    </form>
                </div>

                <!-- Member info -->
                <div class="bg-gray-50 rounded-2xl border border-gray-100 p-5 text-sm">
                    <p class="font-semibold text-gray-700 mb-3">Account Info</p>
                    <dl class="space-y-2 text-gray-500">
                        <div class="flex justify-between">
                            <dt>Member since</dt>
                            <dd class="font-medium text-gray-700">
                                <?= date('d M Y', strtotime($editUser['created_at'])) ?>
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>Total orders</dt>
                            <dd class="font-medium text-gray-700">
                                <?php
                                $oc = $pdo->prepare(
                                    'SELECT COUNT(*) FROM orders WHERE customer_id = ?'
                                );
                                $oc->execute([$editUser['id']]);
                                echo number_format((int)$oc->fetchColumn());
                                ?>
                            </dd>
                        </div>
                    </dl>
                </div>
                <?php endif; ?>

                <!-- Submit -->
                <button type="submit"
                        class="btn btn-primary btn-block"
                        form="<?= isset($GLOBALS['formId']) ? $GLOBALS['formId'] : '' ?>"
                        data-loading-text="Saving…">
                    <?= $isEdit ? 'Save Changes' : 'Create User' ?>
                </button>
                <a href="<?= $base ?>/admin/users.php"
                   class="btn btn-ghost btn-block">Cancel</a>

            </div><!-- /right -->
        </div>
    </form>


    <?php else: ?>
    <!-- ══════════════════════════════════════════════════════════════════
         USER LIST VIEW
    ══════════════════════════════════════════════════════════════════ -->

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center
                sm:justify-between gap-3 mb-6">
        <h1 class="text-2xl font-extrabold text-gray-900">Users</h1>
        <a href="<?= $base ?>/admin/users.php?view=add"
           class="btn btn-primary btn-sm">+ Add User</a>
    </div>

    <!-- Role summary cards -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <?php
        $totalAll = array_sum(array_column($roleCounts, 'total'));
        $summaries = [
            [null,       'All Users',  $totalAll, 'border-l-gray-400',   'text-gray-600 bg-gray-50'],
            ['customer', 'Customers',  $roleCounts['customer']['total']  ?? 0, 'border-l-blue-500',  'text-blue-600 bg-blue-50'],
            ['attendant','Attendants', $roleCounts['attendant']['total'] ?? 0, 'border-l-amber-500', 'text-amber-600 bg-amber-50'],
            ['admin',    'Admins',     $roleCounts['admin']['total']     ?? 0, 'border-l-orange',    'text-orange bg-orange-subtle'],
        ];
        foreach ($summaries as [$rVal, $rLabel, $rCount, $borderCls, $iconCls]): ?>
        <a href="<?= $base ?>/admin/users.php<?= $rVal ? '?role=' . $rVal : '' ?>"
           class="bg-white rounded-xl shadow-sm border border-gray-100
                  border-l-4 <?= $borderCls ?> p-4 flex items-center gap-3
                  hover:shadow-md transition-shadow">
            <div class="w-9 h-9 rounded-lg <?= $iconCls ?>
                        flex items-center justify-center flex-shrink-0"
                 aria-hidden="true">
                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor"
                     stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10
                             0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3
                             3 0 015.356-1.857M7 20v-2c0-.656.126-1.283
                             .356-1.857m0 0a5.002 5.002 0 019.288 0M15 7
                             a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0
                             2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
            </div>
            <div>
                <p class="text-xl font-extrabold text-gray-900 leading-none">
                    <?= number_format($rCount) ?>
                </p>
                <p class="text-xs text-gray-500 mt-0.5"><?= $rLabel ?></p>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <form method="GET" action="users.php"
          class="bg-white rounded-xl border border-gray-100 shadow-sm
                 p-4 mb-5 flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-40">
            <label for="q" class="form-label text-xs mb-1">Search</label>
            <input type="search" id="q" name="q"
                   value="<?= htmlspecialchars($search) ?>"
                   placeholder="Name, email, or phone…"
                   class="form-input py-2 text-sm" />
        </div>
        <div class="w-36">
            <label for="role-filter" class="form-label text-xs mb-1">Role</label>
            <select id="role-filter" name="role" class="form-select py-2 text-sm">
                <option value="">All Roles</option>
                <?php foreach (ROLES as $r): ?>
                <option value="<?= $r ?>" <?= $filterRole === $r ? 'selected' : '' ?>>
                    <?= ucfirst($r) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="w-32">
            <label for="active-filter" class="form-label text-xs mb-1">Status</label>
            <select id="active-filter" name="active" class="form-select py-2 text-sm">
                <option value="">All</option>
                <option value="1" <?= $filterActive === '1' ? 'selected' : '' ?>>Active</option>
                <option value="0" <?= $filterActive === '0' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <?php if ($search || $filterRole || $filterActive !== ''): ?>
            <a href="<?= $base ?>/admin/users.php"
               class="btn btn-secondary btn-sm">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Results count -->
    <p class="text-sm text-gray-500 mb-3">
        <?= number_format($totalUsers) ?> user<?= $totalUsers !== 1 ? 's' : '' ?> found
    </p>

    <!-- Table -->
    <?php if (empty($users)): ?>
    <div class="bg-white rounded-2xl border border-dashed border-gray-200
                py-20 text-center">
        <p class="text-gray-400">No users match your filters.</p>
    </div>

    <?php else: ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="data-table" aria-label="Users list">
            <thead>
                <tr>
                    <th scope="col">User</th>
                    <th scope="col">Phone</th>
                    <th scope="col" class="text-center">Role</th>
                    <th scope="col" class="text-center">Orders</th>
                    <th scope="col">Joined</th>
                    <th scope="col" class="text-center">Status</th>
                    <th scope="col" class="text-center">Upgrade Role</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u):
                    $isSelf   = (int)$u['id'] === $selfId;
                    $isActive = (int)$u['is_active'] === 1;
                ?>
                <tr class="<?= !$isActive ? 'opacity-60' : '' ?>">

                    <!-- Name + email -->
                    <td>
                        <div class="flex items-center gap-3">
                            <!-- Avatar -->
                            <div class="w-8 h-8 rounded-full bg-orange flex items-center
                                        justify-center text-white text-xs font-bold
                                        select-none flex-shrink-0" aria-hidden="true">
                                <?= mb_strtoupper(mb_substr($u['full_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <p class="font-semibold text-gray-800 text-sm">
                                    <?= htmlspecialchars($u['full_name']) ?>
                                    <?php if ($isSelf): ?>
                                    <span class="text-xs text-orange font-normal">(you)</span>
                                    <?php endif; ?>
                                </p>
                                <p class="text-xs text-gray-400 truncate max-w-[180px]">
                                    <?= htmlspecialchars($u['email']) ?>
                                </p>
                            </div>
                        </div>
                    </td>

                    <!-- Phone -->
                    <td class="text-sm text-gray-500 whitespace-nowrap">
                        <?php
                        $ph = $u['phone'];
                        echo strlen($ph) === 12 && str_starts_with($ph, '254')
                            ? htmlspecialchars('+' . substr($ph, 0, 3) . ' ' . substr($ph, 3, 3) . ' ' . substr($ph, 6, 3) . ' ' . substr($ph, 9))
                            : htmlspecialchars($ph);
                        ?>
                    </td>

                    <!-- Role -->
                    <td class="text-center">
                        <?= role_badge($u['role']) ?>
                    </td>

                    <!-- Order count -->
                    <td class="text-center">
                        <span class="inline-block bg-gray-100 text-gray-600 text-xs
                                     font-semibold px-2 py-0.5 rounded-full">
                            <?= number_format((int)$u['order_count']) ?>
                        </span>
                    </td>

                    <!-- Joined -->
                    <td class="text-sm text-gray-500 whitespace-nowrap">
                        <?= date('d M Y', strtotime($u['created_at'])) ?>
                    </td>

                    <!-- Status -->
                    <td class="text-center">
                        <?php if ($isActive): ?>
                        <span class="badge badge-success">Active</span>
                        <?php else: ?>
                        <span class="badge badge-error">Inactive</span>
                        <?php endif; ?>
                    </td>

                    <!-- Quick role upgrade/change -->
                    <td class="text-center">
                        <?php if (!$isSelf): ?>
                        <form method="POST" action="users.php"
                              class="inline-flex items-center gap-1">
                            <input type="hidden" name="action"  value="set_role">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <select name="new_role"
                                    onchange="this.form.submit()"
                                    class="form-select py-1 px-2 text-xs w-28 rounded-lg"
                                    aria-label="Set role for <?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>">
                                <?php foreach (ROLES as $r): ?>
                                <option value="<?= $r ?>"
                                        <?= $u['role'] === $r ? 'selected' : '' ?>>
                                    <?= ucfirst($r) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <?php else: ?>
                        <span class="text-xs text-gray-400">—</span>
                        <?php endif; ?>
                    </td>

                    <!-- Edit + Toggle -->
                    <td class="text-center">
                        <div class="flex items-center justify-center gap-2">
                            <a href="<?= $base ?>/admin/users.php?view=edit&id=<?= $u['id'] ?>"
                               class="btn btn-ghost btn-sm py-1 px-2.5">
                                Edit
                            </a>
                            <?php if (!$isSelf): ?>
                            <form method="POST" action="users.php" class="inline">
                                <input type="hidden" name="action"  value="toggle_active">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit"
                                        class="btn btn-sm py-1 px-2.5
                                               <?= $isActive
                                                   ? 'btn-danger'
                                                   : 'bg-green-600 text-white hover:bg-green-700' ?>"
                                        data-confirm="<?= $isActive ? 'Deactivate' : 'Reactivate' ?> this user?">
                                    <?= $isActive ? 'Deactivate' : 'Restore' ?>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>

                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div><!-- /overflow-x-auto -->
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1):
        $qp = array_filter([
            'q'      => $search,
            'role'   => $filterRole,
            'active' => $filterActive,
        ]);
    ?>
    <nav class="flex items-center justify-center gap-1 mt-6"
         aria-label="Users pages">
        <?php
        $range = range(max(1, $page - 2), min($totalPages, $page + 2));
        if ($range[0] > 1): ?>
            <a href="?<?= http_build_query(array_merge($qp, ['page' => 1])) ?>"
               class="px-3 py-1.5 text-sm rounded-lg border border-gray-200
                      text-gray-600 hover:border-orange hover:text-orange">1</a>
            <?php if ($range[0] > 2): ?>
            <span class="text-gray-400 px-1">…</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php foreach ($range as $p): ?>
        <a href="?<?= http_build_query(array_merge($qp, ['page' => $p])) ?>"
           class="px-3 py-1.5 text-sm rounded-lg border transition-colors
                  <?= $p === $page
                      ? 'bg-orange border-orange text-white font-bold'
                      : 'border-gray-200 text-gray-600 hover:border-orange hover:text-orange' ?>"
           <?= $p === $page ? 'aria-current="page"' : '' ?>>
            <?= $p ?>
        </a>
        <?php endforeach; ?>

        <?php $lastR = end($range);
        if ($lastR < $totalPages): ?>
            <?php if ($lastR < $totalPages - 1): ?>
            <span class="text-gray-400 px-1">…</span>
            <?php endif; ?>
            <a href="?<?= http_build_query(array_merge($qp, ['page' => $totalPages])) ?>"
               class="px-3 py-1.5 text-sm rounded-lg border border-gray-200
                      text-gray-600 hover:border-orange hover:text-orange">
                <?= $totalPages ?>
            </a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

    <?php endif; // users not empty ?>
    <?php endif; // list/form view ?>

    </div><!-- /main content -->
</div><!-- /admin flex -->

<!-- Page JS -->
<script>
(function () {
    // Show/hide password toggle (shared with register.php logic)
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-toggle-password'));
            if (!input) return;
            var isText = input.type === 'text';
            input.type = isText ? 'password' : 'text';
            btn.setAttribute('aria-label', isText ? 'Show password' : 'Hide password');
        });
    });

    // Role card radio — highlight on click
    document.querySelectorAll('[name="role"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            document.querySelectorAll('label:has([name="role"])').forEach(function (lbl) {
                lbl.classList.remove('border-orange', 'bg-orange-subtle');
                lbl.classList.add('border-gray-200');
            });
            var card = radio.closest('label');
            if (card) {
                card.classList.remove('border-gray-200');
                card.classList.add('border-orange', 'bg-orange-subtle');
            }
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
