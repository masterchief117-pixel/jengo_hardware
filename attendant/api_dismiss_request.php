<?php
/**
 * Hide a reviewed request from the current attendant's dashboard.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_attendant();

$base = rtrim((string) env('APP_URL', ''), '/');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/attendant/index.php', 'Only POST requests are allowed.', 'error');
}

$csrfToken = $_SESSION['attendant_approval_csrf'] ?? '';
$submittedToken = $_POST['csrf_token'] ?? '';
if (!is_string($csrfToken)
    || $csrfToken === ''
    || !is_string($submittedToken)
    || !hash_equals($csrfToken, $submittedToken)) {
    redirect('/attendant/index.php', 'Your form expired. Reload and try again.', 'error');
}

$requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
if ($requestId === false || $requestId === null || $requestId < 1) {
    redirect('/attendant/index.php', 'Invalid request.', 'error');
}

$statement = db()->prepare(
    "UPDATE requests
     SET hidden_by_attendant = 1
     WHERE id = ?
       AND attendant_id = ?
       AND hidden_by_attendant = 0
       AND admin_status <> 'Pending'"
);
$statement->execute([$requestId, get_user_id()]);

if ($statement->rowCount() !== 1) {
    redirect(
        '/attendant/index.php',
        'The request could not be cleared. It may still be pending or already hidden.',
        'warning'
    );
}

redirect('/attendant/index.php', 'Reviewed request cleared from your dashboard.', 'success');
