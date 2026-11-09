<?php
/**
 * Submit a staff action for admin approval.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_attendant();
if (!has_role('attendant')) {
    redirect('/attendant/index.php', 'Only attendants can submit approval requests.', 'error');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/attendant/index.php', 'Submit approval requests using the form.', 'error');
}

$csrfToken = (string) ($_SESSION['attendant_approval_csrf'] ?? '');
$submittedToken = $_POST['csrf_token'] ?? '';
if ($csrfToken === '' || !is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
    redirect('/attendant/index.php', 'Your request form expired. Reload and try again.', 'error');
}

$actionTypeInput = $_POST['action_type'] ?? '';
$payloadValue = $_POST['action_payload'] ?? '';
$actionType = is_string($actionTypeInput) ? trim($actionTypeInput) : '';
$payloadInput = is_string($payloadValue) ? trim($payloadValue) : '';
$payload = json_decode($payloadInput);

if (!preg_match('/^[a-z][a-z0-9_]{2,79}$/', $actionType)) {
    redirect('/attendant/index.php', 'Enter a valid action type.', 'error');
}
if (
    $payloadInput === '' ||
    strlen($payloadInput) > 10000 ||
    json_last_error() !== JSON_ERROR_NONE ||
    !is_object($payload)
) {
    redirect('/attendant/index.php', 'Request details must be a valid JSON object under 10 KB.', 'error');
}

$statement = db()->prepare(
    'INSERT INTO approval_requests (attendant_id, action_type, action_payload)
     VALUES (?, ?, ?)'
);
$statement->execute([
    get_user_id(),
    $actionType,
    json_encode($payload, JSON_THROW_ON_ERROR),
]);

redirect(
    '/attendant/index.php',
    'Pending Admin Approval... Your request has been submitted.',
    'info'
);
