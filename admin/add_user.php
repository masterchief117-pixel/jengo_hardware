<?php
/**
 * admin/add_user.php
 *
 * Create a customer, attendant, or admin account.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_admin_login();

$pdo = db();
$errors = [];
$values = [
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'role' => 'customer',
    'monthly_salary' => '0.00',
    'date_of_birth' => '',
    'sex' => '',
    'mpesa_phone_number' => '',
];

if (empty($_SESSION['admin_operations_csrf'])) {
    $_SESSION['admin_operations_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['admin_operations_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($values) as $field) {
        $postedValue = $_POST[$field] ?? null;
        $values[$field] = is_string($postedValue) ? trim($postedValue) : '';
    }

    $passwordValue = $_POST['password'] ?? null;
    $password = is_string($passwordValue) ? $passwordValue : '';
    $salaryInput = $values['monthly_salary'];
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $errors['form'] = 'Your session token is invalid. Please reload the form and try again.';
    }

    if ($values['full_name'] === '') {
        $errors['full_name'] = 'Full name is required.';
    } elseif (mb_strlen($values['full_name']) < 3 || mb_strlen($values['full_name']) > 120) {
        $errors['full_name'] = 'Name must be between 3 and 120 characters.';
    }

    $values['email'] = strtolower($values['email']);
    if ($values['email'] === '') {
        $errors['email'] = 'Email is required.';
    } elseif (strlen($values['email']) > 180 || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    } else {
        try {
            $statement = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $statement->execute([$values['email']]);
        } catch (PDOException $exception) {
            die('Database Error: ' . $exception->getMessage());
        }
        if ($statement->fetchColumn() !== false) {
            die('Error: Email already exists in the system.');
        }
    }

    if ($values['phone'] === '') {
        $errors['phone'] = 'Phone number is required.';
    } else {
        $strippedPhone = preg_replace('/[\s\-().]/', '', $values['phone']);
        $normalizedPhone = null;
        if (is_string($strippedPhone)) {
            if (str_starts_with($strippedPhone, '+254') && strlen($strippedPhone) === 13) {
                $normalizedPhone = '254' . substr($strippedPhone, 4);
            } elseif (str_starts_with($strippedPhone, '254') && strlen($strippedPhone) === 12) {
                $normalizedPhone = $strippedPhone;
            } elseif (str_starts_with($strippedPhone, '0') && strlen($strippedPhone) === 10) {
                $normalizedPhone = '254' . substr($strippedPhone, 1);
            }
        }
        if (
            $normalizedPhone === null ||
            !preg_match('/^254(7[0-9]{8}|1[0-1][0-9]{7})$/', $normalizedPhone)
        ) {
            $errors['phone'] = 'Enter a valid Kenyan mobile number.';
        } else {
            $values['phone'] = $normalizedPhone;
        }
    }

    if (!in_array($values['role'], ['customer', 'attendant', 'admin'], true)) {
        $errors['role'] = 'Select a valid role.';
    }

    $dob = $values['date_of_birth'];
    $sex = $values['sex'];
    if (in_array($values['role'], ['attendant', 'admin'], true)) {
        $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
        if ($dob === '' || $birthDate === false || $birthDate->format('Y-m-d') !== $dob) {
            $errors['date_of_birth'] = 'Enter a valid date of birth.';
        } elseif ($birthDate >= new DateTimeImmutable('today')) {
            $errors['date_of_birth'] = 'Date of birth must be in the past.';
        }
        if (!in_array($sex, ['male', 'female'], true)) {
            $errors['sex'] = 'Select a valid sex.';
        }
    }

    if (in_array($values['role'], ['attendant', 'admin'], true)) {
        if (!preg_match('/^2547[0-9]{8}$/', $values['mpesa_phone_number'])) {
            $errors['mpesa_phone_number'] = 'Enter the M-Pesa salary number in 2547XXXXXXXX format.';
        }
        if ($salaryInput === '') {
            $salaryInput = '0.00';
            $values['monthly_salary'] = $salaryInput;
        }
        if (
            !is_numeric($salaryInput) ||
            !is_finite((float) $salaryInput) ||
            (float) $salaryInput < 0 ||
            (float) $salaryInput > 9999999999.99
        ) {
            $errors['monthly_salary'] = 'Enter a valid non-negative monthly salary in KES.';
        }
    } else {
        $salaryInput = '0.00';
    }

    if ($password === '') {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 8 || strlen($password) > 72) {
        $errors['password'] = 'Password must be between 8 and 72 characters.';
    } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors['password'] = 'Password must contain at least one uppercase letter and one number.';
    }

    if ($errors === []) {
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'INSERT INTO users (full_name, email, password_hash, phone, role)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $values['full_name'],
                $values['email'],
                $passwordHash,
                $values['phone'],
                $values['role'],
            ]);

            if (in_array($values['role'], ['attendant', 'admin'], true)) {
                $userId = (int) $pdo->lastInsertId();
                $employee = $pdo->prepare(
                    "INSERT INTO employees
                        (user_id, full_name, job_title, monthly_salary, date_of_birth,
                         sex, phone, mpesa_phone_number, email, hired_at, employment_status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE, 'employed')"
                );
                $employee->execute([
                    $userId,
                    $values['full_name'],
                    ucfirst($values['role']),
                    number_format((float) $salaryInput, 2, '.', ''),
                    $dob,
                    $sex,
                    $values['phone'],
                    $values['mpesa_phone_number'],
                    $values['email'],
                ]);
                $employmentEvent = $pdo->prepare(
                    "INSERT INTO employee_employment_events
                        (employee_id, event_type, admin_user_id)
                     VALUES (?, 'hired', ?)"
                );
                $employmentEvent->execute([
                    (int) $pdo->lastInsertId(),
                    admin_user_id(),
                ]);
            }
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            die('Database Error: ' . $exception->getMessage());
        }

        if ($errors === []) {
            $_SESSION['flash_message'] = 'User created successfully';
            $_SESSION['flash_type'] = 'success';
            header('Location: ' . rtrim((string) env('APP_URL', ''), '/') . '/admin/users.php');
            exit;
        }
    }
}

$pageTitle = 'Add User';
require_once __DIR__ . '/../includes/header.php';

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>

<div class="max-w-3xl mx-auto px-4 py-10">
    <div class="flex items-center justify-between gap-4 mb-6">
        <h1 class="text-2xl font-extrabold text-gray-900">Add User</h1>
        <a href="<?= $escape($base) ?>/admin/users.php"
           class="text-sm font-semibold text-orange-700 hover:underline">Back to Users</a>
    </div>

    <?php if (isset($errors['form'])): ?>
        <p class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700" role="alert">
            <?= $escape($errors['form']) ?>
        </p>
    <?php endif; ?>

    <form method="POST" action="<?= $escape($base) ?>/admin/add_user.php"
          class="space-y-5 rounded-2xl border border-gray-100 bg-white p-6 shadow-sm"
          aria-label="Add user form">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">

        <div>
            <label for="full_name" class="mb-1 block text-sm font-semibold text-gray-700">Full Name</label>
            <input id="full_name" name="full_name" type="text" required maxlength="120"
                   autocomplete="name" value="<?= $escape($values['full_name']) ?>"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2">
            <?php if (isset($errors['full_name'])): ?>
                <p class="mt-1 text-sm text-red-600" role="alert"><?= $escape($errors['full_name']) ?></p>
            <?php endif; ?>
        </div>

        <div>
            <label for="email" class="mb-1 block text-sm font-semibold text-gray-700">Email</label>
            <input id="email" name="email" type="email" required maxlength="180"
                   autocomplete="email" value="<?= $escape($values['email']) ?>"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2">
            <?php if (isset($errors['email'])): ?>
                <p class="mt-1 text-sm text-red-600" role="alert"><?= $escape($errors['email']) ?></p>
            <?php endif; ?>
        </div>

        <div>
            <label for="phone" class="mb-1 block text-sm font-semibold text-gray-700">Phone Number</label>
            <input id="phone" name="phone" type="tel" required maxlength="20"
                   autocomplete="tel" placeholder="07XX XXX XXX" value="<?= $escape($values['phone']) ?>"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2">
            <?php if (isset($errors['phone'])): ?>
                <p class="mt-1 text-sm text-red-600" role="alert"><?= $escape($errors['phone']) ?></p>
            <?php endif; ?>
        </div>

        <div id="salary-field" <?= in_array($values['role'], ['attendant', 'admin'], true) ? '' : 'hidden' ?>>
            <label for="monthly_salary" class="mb-1 block text-sm font-semibold text-gray-700">
                Monthly Salary (KES) <span class="text-gray-500 font-normal">(optional; defaults to 0.00)</span>
            </label>
            <input id="monthly_salary" name="monthly_salary" type="number" min="0"
                   max="9999999999.99" step="0.01"
                   value="<?= $escape($values['monthly_salary']) ?>"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2">
            <?php if (isset($errors['monthly_salary'])): ?>
                <p class="mt-1 text-sm text-red-600" role="alert"><?= $escape($errors['monthly_salary']) ?></p>
            <?php endif; ?>
        </div>

        <div id="employee-profile-fields" <?= in_array($values['role'], ['attendant', 'admin'], true) ? '' : 'hidden' ?>>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="date_of_birth" class="mb-1 block text-sm font-semibold text-gray-700">Date of Birth</label>
                    <input id="date_of_birth" name="date_of_birth" type="date"
                           max="<?= date('Y-m-d', strtotime('-1 day')) ?>"
                           value="<?= $escape($values['date_of_birth']) ?>"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2">
                    <?php if (isset($errors['date_of_birth'])): ?>
                        <p class="mt-1 text-sm text-red-600" role="alert"><?= $escape($errors['date_of_birth']) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="sex" class="mb-1 block text-sm font-semibold text-gray-700">Sex</label>
                    <select id="sex" name="sex"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2">
                        <option value="">Select...</option>
                        <option value="male" <?= $values['sex'] === 'male' ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?= $values['sex'] === 'female' ? 'selected' : '' ?>>Female</option>
                    </select>
                    <?php if (isset($errors['sex'])): ?>
                        <p class="mt-1 text-sm text-red-600" role="alert"><?= $escape($errors['sex']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="sm:col-span-2">
                    <label for="mpesa_phone_number" class="mb-1 block text-sm font-semibold text-gray-700">
                        M-Pesa Salary Phone
                    </label>
                    <input id="mpesa_phone_number" name="mpesa_phone_number" type="tel"
                           inputmode="numeric" pattern="2547[0-9]{8}" maxlength="12" minlength="12"
                           value="<?= $escape($values['mpesa_phone_number']) ?>"
                           placeholder="2547XXXXXXXX"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2">
                    <p class="mt-1 text-xs text-gray-500">Required for employee accounts. Use 2547XXXXXXXX.</p>
                    <?php if (isset($errors['mpesa_phone_number'])): ?>
                        <p class="mt-1 text-sm text-red-600" role="alert"><?= $escape($errors['mpesa_phone_number']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div>
            <label for="password" class="mb-1 block text-sm font-semibold text-gray-700">Password</label>
            <input id="password" name="password" type="password" required minlength="8" maxlength="72"
                   autocomplete="new-password" class="w-full rounded-lg border border-gray-300 px-3 py-2">
            <?php if (isset($errors['password'])): ?>
                <p class="mt-1 text-sm text-red-600" role="alert"><?= $escape($errors['password']) ?></p>
            <?php else: ?>
                <p class="mt-1 text-xs text-gray-500">At least 8 characters, including an uppercase letter and a number.</p>
            <?php endif; ?>
        </div>

        <div>
            <label for="role" class="mb-1 block text-sm font-semibold text-gray-700">Role</label>
            <select id="role" name="role" required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2">
                <?php foreach (['customer', 'attendant', 'admin'] as $role): ?>
                    <option value="<?= $escape($role) ?>"
                        <?= $values['role'] === $role ? 'selected' : '' ?>>
                        <?= ucfirst($escape($role)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['role'])): ?>
                <p class="mt-1 text-sm text-red-600" role="alert"><?= $escape($errors['role']) ?></p>
            <?php endif; ?>
        </div>

        <div class="flex flex-wrap gap-3 pt-2">
            <button type="submit"
                    class="rounded-lg bg-orange-600 px-5 py-2 font-semibold text-white hover:bg-orange-700">
                Create User
            </button>
            <a href="<?= $escape($base) ?>/admin/users.php"
               class="rounded-lg border border-gray-300 px-5 py-2 font-semibold text-gray-700 hover:bg-gray-50">
                Cancel
            </a>
        </div>
    </form>
</div>

<script>
    const roleField = document.getElementById('role');
    const salaryField = document.getElementById('salary-field');
    const salaryInput = document.getElementById('monthly_salary');
    const employeeProfileFields = document.getElementById('employee-profile-fields');
    const dateOfBirthInput = document.getElementById('date_of_birth');
    const sexInput = document.getElementById('sex');
    const mpesaPhoneInput = document.getElementById('mpesa_phone_number');

    function updateSalaryVisibility() {
        const requiresEmployeeRecord = ['attendant', 'admin'].includes(roleField.value);
        salaryField.hidden = !requiresEmployeeRecord;
        salaryInput.disabled = !requiresEmployeeRecord;
        employeeProfileFields.hidden = !requiresEmployeeRecord;
        dateOfBirthInput.required = requiresEmployeeRecord;
        dateOfBirthInput.disabled = !requiresEmployeeRecord;
        sexInput.required = requiresEmployeeRecord;
        sexInput.disabled = !requiresEmployeeRecord;
        mpesaPhoneInput.required = requiresEmployeeRecord;
        mpesaPhoneInput.disabled = !requiresEmployeeRecord;
    }

    roleField.addEventListener('change', updateSalaryVisibility);
    updateSalaryVisibility();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
