<?php
/**
 * Admin payroll dashboard and payment ledger.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../includes/mpesa_b2c.php';
require_admin_login();

$pdo = db();
$base = rtrim((string) env('APP_URL', ''), '/');
$escape = static fn (string $value): string =>
    htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if (empty($_SESSION['admin_payroll_csrf'])) {
    $_SESSION['admin_payroll_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['admin_payroll_csrf'];
$payrollTokens = is_array($_SESSION['admin_payroll_tokens'] ?? null)
    ? $_SESSION['admin_payroll_tokens']
    : [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_salary') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $submittedPayrollToken = $_POST['payroll_token'] ?? '';
    $employeeIdInput = $_POST['employee_id'] ?? null;
    $employeeId = is_string($employeeIdInput) || is_int($employeeIdInput)
        ? filter_var($employeeIdInput, FILTER_VALIDATE_INT)
        : false;

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(400);
        $error = 'This form expired. Reload the page and try again.';
    } elseif ($employeeId === false || $employeeId === null || $employeeId < 1) {
        http_response_code(400);
        $error = 'Select a valid employee.';
    } elseif (
        !is_string($submittedPayrollToken) ||
        !isset($payrollTokens[$employeeId]) ||
        !hash_equals((string) $payrollTokens[$employeeId], $submittedPayrollToken)
    ) {
        http_response_code(409);
        $error = 'This payment form was already submitted or has expired. Reload the page before trying again.';
    } else {
        unset($payrollTokens[$employeeId]);
        $_SESSION['admin_payroll_tokens'] = $payrollTokens;
        $paymentId = null;

        try {
            $pdo->beginTransaction();
            $employeeStatement = $pdo->prepare(
                "SELECT id, full_name, monthly_salary, mpesa_phone_number
                 FROM employees
                 WHERE id = ? AND employment_status = 'employed'
                 LIMIT 1
                 FOR UPDATE"
            );
            $employeeStatement->execute([$employeeId]);
            $employee = $employeeStatement->fetch(PDO::FETCH_ASSOC);

            if ($employee === false) {
                $pdo->rollBack();
                http_response_code(400);
                $error = 'That employee is not active and cannot be paid.';
            } elseif (!preg_match('/^2547[0-9]{8}$/', (string) $employee['mpesa_phone_number'])) {
                $pdo->rollBack();
                http_response_code(400);
                $error = 'Add a valid 2547XXXXXXXX M-Pesa number to this employee before paying.';
            } elseif ((float) $employee['monthly_salary'] <= 0) {
                $pdo->rollBack();
                http_response_code(400);
                $error = 'The employee has no payable monthly salary.';
            } elseif ((float) $employee['monthly_salary'] !== floor((float) $employee['monthly_salary'])) {
                $pdo->rollBack();
                http_response_code(400);
                $error = 'Daraja B2C requires a whole-shilling salary amount. Update the employee salary first.';
            } else {
                $periodStart = new DateTimeImmutable('first day of this month 00:00:00');
                $periodEnd = $periodStart->modify('first day of next month');
                $existingPayment = $pdo->prepare(
                    "SELECT id, status
                     FROM payroll_records
                     WHERE employee_id = ?
                       AND payment_date >= ?
                       AND payment_date < ?
                       AND status IN (
                           'Initiating', 'Processing (M-Pesa)', 'Submission Unknown',
                           'Review Required', 'Paid'
                       )
                     LIMIT 1
                     FOR UPDATE"
                );
                $existingPayment->execute([
                    (int) $employee['id'],
                    $periodStart->format('Y-m-d H:i:s'),
                    $periodEnd->format('Y-m-d H:i:s'),
                ]);
                $existing = $existingPayment->fetch(PDO::FETCH_ASSOC);

                if ($existing !== false) {
                    $pdo->rollBack();
                    http_response_code(409);
                    $error = 'A salary payment for this employee is already recorded or awaiting confirmation this month.';
                } else {
                    $paymentStatement = $pdo->prepare(
                        "INSERT INTO payroll_records
                            (employee_id, amount_paid, status, mpesa_phone_number)
                         VALUES (?, ?, 'Initiating', ?)"
                    );
                    $paymentStatement->execute([
                        (int) $employee['id'],
                        $employee['monthly_salary'],
                        $employee['mpesa_phone_number'],
                    ]);
                    $paymentId = (int) $pdo->lastInsertId();
                    $pdo->commit();

                    try {
                        $result = sendMpesaSalary(
                            (string) $employee['mpesa_phone_number'],
                            (int) $employee['monthly_salary'],
                            'Salary for ' . (string) $employee['full_name']
                        );
                        $responseCode = (string) ($result['ResponseCode'] ?? '');
                        $conversationId = trim((string) ($result['ConversationID'] ?? ''));
                        $originatorConversationId = trim((string) ($result['OriginatorConversationID'] ?? ''));
                        $acceptedResponse = $responseCode === '0';
                        $accepted = $acceptedResponse
                            && $conversationId !== ''
                            && $originatorConversationId !== '';
                        $status = $accepted
                            ? 'Processing (M-Pesa)'
                            : ($acceptedResponse ? 'Submission Unknown' : 'Failed');
                        $description = (string) (
                            $result['ResponseDescription']
                            ?? $result['errorMessage']
                            ?? ($accepted ? 'Request accepted for processing.' : 'Daraja rejected the salary request.')
                        );
                        $savedResponse = true;
                        try {
                            $update = $pdo->prepare(
                                'UPDATE payroll_records
                                 SET status = ?, conversation_id = ?, originator_conversation_id = ?,
                                     result_code = ?, result_description = ?
                                 WHERE id = ? AND status = \'Initiating\''
                            );
                            $update->execute([
                                $status,
                                $conversationId !== '' ? $conversationId : null,
                                $originatorConversationId !== '' ? $originatorConversationId : null,
                                $responseCode !== '' ? $responseCode : null,
                                mb_substr($description, 0, 255),
                                $paymentId,
                            ]);
                        } catch (PDOException $updateException) {
                            $savedResponse = false;
                            error_log(
                                '[Admin Payroll] Could not save Daraja response for record '
                                . $paymentId . ': ' . $updateException->getMessage()
                            );
                        }

                        if (!$savedResponse) {
                            http_response_code(500);
                            $error = 'Safaricom responded, but the result could not be saved. Check the ledger and M-Pesa statement before retrying.';
                        } elseif ($accepted) {
                            header('Location: ' . $base . '/admin/admin_payroll.php?initiated=1');
                            exit;
                        } else {
                            $error = $acceptedResponse
                                ? 'Safaricom accepted the request without returning the identifiers needed to reconcile it. Check the ledger and M-Pesa statement before retrying.'
                                : 'Safaricom did not accept the salary request: ' . $description;
                        }
                    } catch (MpesaB2cException $exception) {
                        $status = $exception->requestMayHaveReachedGateway
                            ? 'Submission Unknown'
                            : 'Failed';
                        try {
                            $update = $pdo->prepare(
                                'UPDATE payroll_records
                                 SET status = ?, result_description = ?
                                 WHERE id = ? AND status = \'Initiating\''
                            );
                            $update->execute([
                                $status,
                                mb_substr($exception->getMessage(), 0, 255),
                                $paymentId,
                            ]);
                        } catch (PDOException $updateException) {
                            error_log(
                                '[Admin Payroll] Could not update M-Pesa status for record '
                                . $paymentId . ': ' . $updateException->getMessage()
                            );
                        }

                        error_log('[Admin Payroll] Daraja B2C request failed: ' . $exception->getMessage());
                        $error = $exception->requestMayHaveReachedGateway
                            ? 'The request may have reached Safaricom, but its response was not confirmed. Check the ledger and M-Pesa statement before retrying.'
                            : 'The M-Pesa salary request could not be submitted: ' . $exception->getMessage();
                    }
                }
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[Admin Payroll] Payment recording failed: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'The salary payment could not be recorded. Please try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(400);
    $error = 'Invalid payroll action.';
}

$employees = $pdo->query(
    "SELECT id, full_name, job_title, monthly_salary, mpesa_phone_number
     FROM employees
     WHERE employment_status = 'employed'
     ORDER BY full_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$history = $pdo->query(
    'SELECT pr.id, pr.amount_paid, pr.payment_date, pr.status,
            pr.mpesa_phone_number, pr.mpesa_receipt, pr.result_description,
            e.full_name, e.job_title
     FROM payroll_records AS pr
     INNER JOIN employees AS e ON e.id = pr.employee_id
     ORDER BY pr.payment_date DESC, pr.id DESC
     LIMIT 50'
)->fetchAll(PDO::FETCH_ASSOC);
$mpesaEnvironment = (string) env('MPESA_ENV', 'sandbox');

$pageTitle = 'Payroll';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="mb-1 text-sm text-gray-500">Admin Portal / People</p>
            <h1 class="text-3xl font-extrabold text-gray-900">Payroll</h1>
            <p class="mt-1 text-gray-600">Initiate M-Pesa salary transfers for active employees.</p>
        </div>
        <nav class="flex flex-wrap gap-2" aria-label="Admin quick navigation">
            <a class="btn btn-secondary" href="<?= $escape($base) ?>/admin/index.php">Admin dashboard</a>
            <a class="btn btn-secondary" href="<?= $escape($base) ?>/admin/employees.php">Employees</a>
            <a class="btn btn-primary" href="<?= $escape($base) ?>/admin/admin_payroll.php" aria-current="page">Payroll</a>
        </nav>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><?= $escape($error) ?></div>
    <?php elseif (($_GET['initiated'] ?? '') === '1'): ?>
        <div class="alert alert-success" role="status">
            Safaricom accepted the salary request. The ledger will show Paid after its result callback confirms the transfer.
        </div>
    <?php endif; ?>

    <section class="card p-5" aria-labelledby="payroll-staff-heading">
        <div class="mb-4">
            <h2 id="payroll-staff-heading" class="text-xl font-bold text-gray-900">
                Initiate Salary Payments
            </h2>
            <p class="mt-1 text-sm text-gray-600">
                Daraja environment: <strong><?= $escape($mpesaEnvironment) ?></strong>.
                A request is not considered paid until Safaricom confirms it.
                Only one pending or confirmed salary transfer per employee is allowed each calendar month.
            </p>
        </div>

        <?php if ($employees === []): ?>
            <p class="text-sm text-gray-600">There are no active employees to pay.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table" aria-label="Active employees and salaries">
                    <thead>
                        <tr>
                            <th scope="col">Employee</th>
                            <th scope="col">Role</th>
                            <th scope="col">M-Pesa number</th>
                            <th scope="col">Monthly Salary</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $employee): ?>
                            <?php
                            $employeeToken = bin2hex(random_bytes(32));
                            $payrollTokens[(int) $employee['id']] = $employeeToken;
                            $validMpesaNumber = preg_match(
                                '/^2547[0-9]{8}$/',
                                (string) $employee['mpesa_phone_number']
                            ) === 1;
                            $validSalary = (float) $employee['monthly_salary'] > 0
                                && (float) $employee['monthly_salary'] === floor((float) $employee['monthly_salary']);
                            ?>
                            <tr>
                                <td class="font-semibold">
                                    <?= $escape((string) $employee['full_name']) ?>
                                </td>
                                <td><?= $escape((string) $employee['job_title']) ?></td>
                                <td class="whitespace-nowrap">
                                    <?= $escape((string) ($employee['mpesa_phone_number'] ?: 'Not set')) ?>
                                </td>
                                <td class="whitespace-nowrap">
                                    KES <?= number_format((float) $employee['monthly_salary'], 2) ?>
                                </td>
                                <td>
                                    <form method="POST" action="<?= $escape($base) ?>/admin/admin_payroll.php"
                                          data-confirm="Initiate a real M-Pesa salary transfer to this employee?"
                                          class="m-0">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= $escape($csrfToken) ?>">
                                        <input type="hidden" name="action" value="pay_salary">
                                        <input type="hidden" name="employee_id"
                                               value="<?= (int) $employee['id'] ?>">
                                        <input type="hidden" name="payroll_token"
                                               value="<?= $escape($employeeToken) ?>">
                                        <button type="submit" class="btn btn-primary btn-sm"
                                                <?= (!$validMpesaNumber || !$validSalary) ? 'disabled' : '' ?>
                                                style="background-color: #D9531E; border-color: #D9531E;">
                                            Send M-Pesa
                                        </button>
                                    </form>
                                    <?php if (!$validMpesaNumber): ?>
                                        <p class="mt-1 text-xs text-red-700">Set a valid employee M-Pesa number.</p>
                                    <?php elseif (!$validSalary): ?>
                                        <p class="mt-1 text-xs text-red-700">Salary must be a positive whole KES amount.</p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php $_SESSION['admin_payroll_tokens'] = $payrollTokens; ?>
        <?php endif; ?>
    </section>

    <section class="card p-5" aria-labelledby="payroll-ledger-heading">
        <div class="mb-4">
            <h2 id="payroll-ledger-heading" class="text-xl font-bold text-gray-900">
                Payroll Ledger
            </h2>
            <p class="mt-1 text-sm text-gray-600">The 50 most recent salary payment records.</p>
        </div>

        <?php if ($history === []): ?>
            <p class="text-sm text-gray-600">No payroll payments have been recorded yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table" aria-label="Recent payroll payments">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Employee</th>
                            <th scope="col">M-Pesa number</th>
                            <th scope="col">Amount Paid</th>
                            <th scope="col">M-Pesa receipt</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $record): ?>
                            <?php
                            $statusClass = match ((string) $record['status']) {
                                'Paid' => 'badge-success',
                                'Failed', 'Review Required' => 'badge-error',
                                default => 'badge-warning',
                            };
                            ?>
                            <tr>
                                <td class="whitespace-nowrap">
                                    <?= $escape(date('d M Y, H:i', strtotime((string) $record['payment_date']))) ?>
                                </td>
                                <td class="font-semibold">
                                    <?= $escape((string) $record['full_name']) ?>
                                </td>
                                <td class="whitespace-nowrap">
                                    <?= $escape((string) ($record['mpesa_phone_number'] ?? '—')) ?>
                                </td>
                                <td class="whitespace-nowrap font-semibold text-green-700">
                                    KES <?= number_format((float) $record['amount_paid'], 2) ?>
                                </td>
                                <td class="whitespace-nowrap">
                                    <?= $escape((string) ($record['mpesa_receipt'] ?? '—')) ?>
                                </td>
                                <td>
                                    <span class="badge <?= $statusClass ?>">
                                        <?= $escape((string) $record['status']) ?>
                                    </span>
                                    <?php if (!empty($record['result_description'])): ?>
                                        <p class="mt-1 max-w-xs text-xs text-gray-500">
                                            <?= $escape((string) $record['result_description']) ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
