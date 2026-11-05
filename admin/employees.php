<?php
/**
 * Admin employee roster and employment history.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_admin_login();

$pdo = db();
$base = rtrim((string) env('APP_URL', ''), '/');
$escape = static fn (string $value): string =>
    htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if (empty($_SESSION['admin_operations_csrf'])) {
    $_SESSION['admin_operations_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['admin_operations_csrf'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $submittedToken)) {
        http_response_code(400);
        $error = 'This form expired. Reload the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $employeeId = filter_var($_POST['employee_id'] ?? 0, FILTER_VALIDATE_INT);
        $employeeId = $employeeId === false ? 0 : (int) $employeeId;

        if ($action === 'save') {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $jobTitle = trim((string) ($_POST['job_title'] ?? ''));
            $salaryInput = trim((string) ($_POST['monthly_salary'] ?? ''));
            $dateOfBirth = trim((string) ($_POST['date_of_birth'] ?? ''));
            $sex = (string) ($_POST['sex'] ?? '');
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $hiredAt = trim((string) ($_POST['hired_at'] ?? ''));
            $validSexes = ['female', 'male', 'other', 'prefer_not_to_say'];
            $validDate = static function (string $date): bool {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                return $parsed !== false && $parsed->format('Y-m-d') === $date;
            };
            $errors = [];

            if ($fullName === '' || mb_strlen($fullName) > 120) {
                $errors[] = 'Enter an employee name (up to 120 characters).';
            }
            if ($jobTitle === '' || mb_strlen($jobTitle) > 120) {
                $errors[] = 'Enter a role or job title (up to 120 characters).';
            }
            if (
                $salaryInput === '' ||
                !is_numeric($salaryInput) ||
                !is_finite((float) $salaryInput) ||
                (float) $salaryInput < 0 ||
                (float) $salaryInput > 9999999999.99
            ) {
                $errors[] = 'Enter a valid non-negative monthly salary in KES.';
            }
            if (
                !$validDate($dateOfBirth) ||
                new DateTimeImmutable($dateOfBirth) >= new DateTimeImmutable('today')
            ) {
                $errors[] = 'Enter a valid date of birth in the past.';
            }
            if (!in_array($sex, $validSexes, true)) {
                $errors[] = 'Choose a valid sex option.';
            }
            if (strlen($phone) > 40) {
                $errors[] = 'Phone number must be 40 characters or fewer.';
            }
            if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180)) {
                $errors[] = 'Enter a valid email address (up to 180 characters), or leave it blank.';
            }
            if (
                !$validDate($hiredAt) ||
                new DateTimeImmutable($hiredAt) > new DateTimeImmutable('today')
            ) {
                $errors[] = 'Enter a valid hire date that is not in the future.';
            }

            if ($employeeId > 0) {
                $check = $pdo->prepare('SELECT id FROM employees WHERE id = ?');
                $check->execute([$employeeId]);
                if (!$check->fetchColumn()) {
                    $errors[] = 'That employee record could not be found.';
                }
            }

            if ($errors === []) {
                try {
                    $pdo->beginTransaction();
                    if ($employeeId > 0) {
                        $statement = $pdo->prepare(
                            'UPDATE employees
                             SET full_name = ?, job_title = ?, monthly_salary = ?,
                                 date_of_birth = ?, sex = ?, phone = ?, email = ?, hired_at = ?
                             WHERE id = ?'
                        );
                        $statement->execute([
                            $fullName,
                            $jobTitle,
                            $salaryInput,
                            $dateOfBirth,
                            $sex,
                            $phone !== '' ? $phone : null,
                            $email !== '' ? $email : null,
                            $hiredAt,
                            $employeeId,
                        ]);
                    } else {
                        $statement = $pdo->prepare(
                            'INSERT INTO employees
                                (full_name, job_title, monthly_salary, date_of_birth, sex,
                                 phone, email, hired_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $statement->execute([
                            $fullName,
                            $jobTitle,
                            $salaryInput,
                            $dateOfBirth,
                            $sex,
                            $phone !== '' ? $phone : null,
                            $email !== '' ? $email : null,
                            $hiredAt,
                        ]);
                        $employeeId = (int) $pdo->lastInsertId();
                        $event = $pdo->prepare(
                            "INSERT INTO employee_employment_events
                                (employee_id, event_type, admin_user_id)
                             VALUES (?, 'hired', ?)"
                        );
                        $event->execute([$employeeId, admin_user_id()]);
                    }
                    $pdo->commit();
                    header('Location: ' . $base . '/admin/employees.php?saved=1');
                    exit;
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('[Admin Employees] Save failed: ' . $exception->getMessage());
                    $error = 'The employee record could not be saved. Please try again.';
                }
            } else {
                $error = implode(' ', $errors);
            }
        } elseif (in_array($action, ['terminate', 'rehire'], true) && $employeeId > 0) {
            try {
                $pdo->beginTransaction();
                $statement = $pdo->prepare(
                    'SELECT employment_status FROM employees WHERE id = ? FOR UPDATE'
                );
                $statement->execute([$employeeId]);
                $currentStatus = $statement->fetchColumn();

                if ($currentStatus === false) {
                    $pdo->rollBack();
                    $error = 'That employee record could not be found.';
                } else {
                    $targetStatus = $action === 'terminate' ? 'employed' : 'terminated';
                    if ($currentStatus !== $targetStatus) {
                        $pdo->rollBack();
                        $error = 'The employee status has already changed. Refresh and try again.';
                    } else {
                        if ($action === 'terminate') {
                            $update = $pdo->prepare(
                                "UPDATE employees SET employment_status = 'terminated' WHERE id = ?"
                            );
                            $eventType = 'terminated';
                        } else {
                            $update = $pdo->prepare(
                                "UPDATE employees
                                 SET employment_status = 'employed', hired_at = CURRENT_DATE
                                 WHERE id = ?"
                            );
                            $eventType = 'rehired';
                        }
                        $update->execute([$employeeId]);
                        $event = $pdo->prepare(
                            'INSERT INTO employee_employment_events
                                (employee_id, event_type, admin_user_id)
                             VALUES (?, ?, ?)'
                        );
                        $event->execute([$employeeId, $eventType, admin_user_id()]);
                        $pdo->commit();
                        header('Location: ' . $base . '/admin/employees.php?updated=1');
                        exit;
                    }
                }
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[Admin Employees] Status update failed: ' . $exception->getMessage());
                $error = 'The employment status could not be updated. Please try again.';
            }
        } else {
            http_response_code(400);
            $error = 'Invalid employee action.';
        }
    }
}

$view = (string) ($_GET['view'] ?? 'list');
$employeeId = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
$employeeId = $employeeId === false ? 0 : (int) $employeeId;
$employee = null;
$history = [];

if (in_array($view, ['edit', 'history'], true) && $employeeId > 0) {
    $statement = $pdo->prepare('SELECT * FROM employees WHERE id = ? LIMIT 1');
    $statement->execute([$employeeId]);
    $employee = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($employee && $view === 'history') {
        $statement = $pdo->prepare(
            'SELECT events.event_type, events.event_at, admins.full_name AS admin_name
             FROM employee_employment_events AS events
             LEFT JOIN admin_users AS admins ON admins.id = events.admin_user_id
             WHERE events.employee_id = ?
             ORDER BY events.event_at DESC, events.id DESC'
        );
        $statement->execute([$employeeId]);
        $history = $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}

$statusFilter = (string) ($_GET['status'] ?? 'employed');
if (!in_array($statusFilter, ['employed', 'terminated', 'all'], true)) {
    $statusFilter = 'employed';
}
$search = trim((string) ($_GET['q'] ?? ''));
$where = [];
$params = [];
if ($statusFilter !== 'all') {
    $where[] = 'employment_status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(full_name LIKE ? OR job_title LIKE ? OR email LIKE ? OR phone LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}
$sql = 'SELECT * FROM employees';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY employment_status = \'employed\' DESC, full_name ASC';
$statement = $pdo->prepare($sql);
$statement->execute($params);
$employees = $statement->fetchAll(PDO::FETCH_ASSOC);

$totals = $pdo->query(
    "SELECT
        SUM(employment_status = 'employed') AS employed_count,
        SUM(employment_status = 'terminated') AS former_count
     FROM employees"
)->fetch(PDO::FETCH_ASSOC);
$sexLabels = [
    'female' => 'Female',
    'male' => 'Male',
    'other' => 'Other',
    'prefer_not_to_say' => 'Prefer not to say',
];

$pageTitle = 'Employees';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-6">
        <div>
            <p class="text-sm text-gray-500 mb-1">Admin Portal / People</p>
            <h1 class="text-3xl font-extrabold text-gray-900">Employees</h1>
            <p class="text-gray-600 mt-1">Manage employee details, monthly salaries and employment status.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="btn btn-secondary" href="<?= $escape($base) ?>/admin/index.php">Admin dashboard</a>
            <a class="btn btn-secondary" href="<?= $escape($base) ?>/admin/dashboard.php">Owner analytics</a>
            <a class="btn btn-secondary" href="<?= $escape($base) ?>/admin/deliveries.php">Deliveries</a>
            <?php if ($view !== 'add'): ?>
                <a class="btn btn-primary" href="<?= $escape($base) ?>/admin/employees.php?view=add">Add employee</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error mb-5" role="alert"><?= $escape($error) ?></div>
    <?php elseif (isset($_GET['saved'])): ?>
        <div class="alert alert-success mb-5" role="status">Employee record saved.</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="alert alert-success mb-5" role="status">Employment status updated and recorded.</div>
    <?php endif; ?>

    <?php if ($view === 'add' || ($view === 'edit' && $employee)): ?>
        <?php
        $formData = $_SERVER['REQUEST_METHOD'] === 'POST'
            ? $_POST
            : ($employee ?? [
                'full_name' => '',
                'job_title' => '',
                'monthly_salary' => '',
                'date_of_birth' => '',
                'sex' => '',
                'phone' => '',
                'email' => '',
                'hired_at' => date('Y-m-d'),
            ]);
        ?>
        <section class="bg-white rounded-xl shadow p-5 sm:p-7 max-w-3xl">
            <h2 class="text-xl font-bold mb-5"><?= $view === 'edit' ? 'Edit employee' : 'Add employee' ?></h2>
            <form method="post" action="<?= $escape($base) ?>/admin/employees.php?view=<?= $view === 'edit' ? 'edit&id=' . $employeeId : 'add' ?>" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="employee_id" value="<?= $view === 'edit' ? (int) $employeeId : 0 ?>">
                <label class="block">
                    <span class="form-label">Full name</span>
                    <input class="form-input" name="full_name" maxlength="120" required value="<?= $escape((string) ($formData['full_name'] ?? '')) ?>">
                </label>
                <label class="block">
                    <span class="form-label">Role / job title</span>
                    <input class="form-input" name="job_title" maxlength="120" required value="<?= $escape((string) ($formData['job_title'] ?? '')) ?>">
                </label>
                <label class="block">
                    <span class="form-label">Monthly salary (KES)</span>
                    <input class="form-input" name="monthly_salary" type="number" min="0" step="0.01" required value="<?= $escape((string) ($formData['monthly_salary'] ?? '')) ?>">
                </label>
                <label class="block">
                    <span class="form-label">Date of birth (used to calculate age)</span>
                    <input class="form-input" name="date_of_birth" type="date" max="<?= date('Y-m-d', strtotime('-1 day')) ?>" required value="<?= $escape((string) ($formData['date_of_birth'] ?? '')) ?>">
                </label>
                <label class="block">
                    <span class="form-label">Sex</span>
                    <select class="form-input" name="sex" required>
                        <option value="">Select…</option>
                        <?php foreach ($sexLabels as $value => $label): ?>
                            <option value="<?= $value ?>" <?= ($formData['sex'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="form-label">Hire date</span>
                    <input class="form-input" name="hired_at" type="date" max="<?= date('Y-m-d') ?>" required value="<?= $escape((string) ($formData['hired_at'] ?? date('Y-m-d'))) ?>">
                </label>
                <label class="block">
                    <span class="form-label">Phone (optional)</span>
                    <input class="form-input" name="phone" type="tel" maxlength="40" value="<?= $escape((string) ($formData['phone'] ?? '')) ?>">
                </label>
                <label class="block">
                    <span class="form-label">Email (optional)</span>
                    <input class="form-input" name="email" type="email" maxlength="180" value="<?= $escape((string) ($formData['email'] ?? '')) ?>">
                </label>
                <div class="sm:col-span-2 flex gap-3 pt-2">
                    <button class="btn btn-primary" type="submit">Save employee</button>
                    <a class="btn btn-secondary" href="<?= $escape($base) ?>/admin/employees.php">Cancel</a>
                </div>
            </form>
        </section>
    <?php elseif ($view === 'history' && $employee): ?>
        <section class="bg-white rounded-xl shadow p-5 sm:p-7">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
                <div>
                    <h2 class="text-xl font-bold"><?= $escape((string) $employee['full_name']) ?> — employment history</h2>
                    <p class="text-sm text-gray-600"><?= $escape((string) $employee['job_title']) ?></p>
                </div>
                <a class="btn btn-secondary" href="<?= $escape($base) ?>/admin/employees.php">Back to employees</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b text-gray-500"><th class="py-3 pr-4">Event</th><th class="py-3 pr-4">Date and time</th><th class="py-3">Recorded by</th></tr></thead>
                    <tbody>
                    <?php foreach ($history as $event): ?>
                        <tr class="border-b last:border-0">
                            <td class="py-3 pr-4 capitalize"><?= $escape((string) $event['event_type']) ?></td>
                            <td class="py-3 pr-4"><?= $escape((string) $event['event_at']) ?></td>
                            <td class="py-3"><?= $escape((string) ($event['admin_name'] ?? 'Admin account removed')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($history === []): ?><tr><td class="py-5 text-gray-500" colspan="3">No employment events recorded.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php else: ?>
        <section class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
            <div class="bg-white rounded-xl shadow p-5"><p class="text-sm text-gray-500">Currently employed</p><p class="text-3xl font-bold mt-1"><?= (int) ($totals['employed_count'] ?? 0) ?></p></div>
            <div class="bg-white rounded-xl shadow p-5"><p class="text-sm text-gray-500">Former employees</p><p class="text-3xl font-bold mt-1"><?= (int) ($totals['former_count'] ?? 0) ?></p></div>
        </section>
        <section class="bg-white rounded-xl shadow p-5">
            <form method="get" class="flex flex-col sm:flex-row gap-3 mb-5">
                <input class="form-input flex-1" type="search" name="q" placeholder="Search name, role, email or phone" value="<?= $escape($search) ?>">
                <select class="form-input sm:w-48" name="status" aria-label="Employment status">
                    <option value="employed" <?= $statusFilter === 'employed' ? 'selected' : '' ?>>Currently employed</option>
                    <option value="terminated" <?= $statusFilter === 'terminated' ? 'selected' : '' ?>>Former employees</option>
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All employees</option>
                </select>
                <button class="btn btn-secondary" type="submit">Filter</button>
            </form>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b text-gray-500">
                        <th class="py-3 pr-4">Employee</th><th class="py-3 pr-4">Role</th><th class="py-3 pr-4">Age / sex</th><th class="py-3 pr-4">Monthly salary</th><th class="py-3 pr-4">Status</th><th class="py-3">Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($employees as $row): ?>
                        <?php
                        $age = (new DateTimeImmutable((string) $row['date_of_birth']))->diff(new DateTimeImmutable('today'))->y;
                        $employed = $row['employment_status'] === 'employed';
                        ?>
                        <tr class="border-b last:border-0 align-top">
                            <td class="py-4 pr-4">
                                <div class="font-semibold"><?= $escape((string) $row['full_name']) ?></div>
                                <div class="text-gray-500"><?= $escape((string) ($row['phone'] ?: $row['email'] ?: 'No contact details')) ?></div>
                            </td>
                            <td class="py-4 pr-4"><?= $escape((string) $row['job_title']) ?></td>
                            <td class="py-4 pr-4"><?= $age ?> · <?= $escape($sexLabels[$row['sex']] ?? '—') ?></td>
                            <td class="py-4 pr-4 whitespace-nowrap">KES <?= number_format((float) $row['monthly_salary'], 2) ?></td>
                            <td class="py-4 pr-4"><span class="badge <?= $employed ? 'badge-success' : 'badge-charcoal' ?>"><?= $employed ? 'Employed' : 'Terminated' ?></span></td>
                            <td class="py-4">
                                <div class="flex flex-wrap gap-2">
                                    <a class="btn btn-secondary" href="<?= $escape($base) ?>/admin/employees.php?view=edit&id=<?= (int) $row['id'] ?>">Edit</a>
                                    <a class="btn btn-secondary" href="<?= $escape($base) ?>/admin/employees.php?view=history&id=<?= (int) $row['id'] ?>">History</a>
                                    <form method="post" onsubmit="return confirm('<?= $employed ? 'Terminate this employee?' : 'Rehire this employee?' ?>');">
                                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                        <input type="hidden" name="employee_id" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="action" value="<?= $employed ? 'terminate' : 'rehire' ?>">
                                        <button class="btn <?= $employed ? 'btn-danger' : 'btn-primary' ?>" type="submit"><?= $employed ? 'Terminate' : 'Rehire' ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($employees === []): ?><tr><td class="py-8 text-center text-gray-500" colspan="6">No employees match this filter. Add an employee to get started.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
