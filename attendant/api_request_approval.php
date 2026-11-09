<?php
/**
 * Universal approval submitter for attendant forms and AJAX.
 *
 * POST action_type and payload (JSON string). Also accepts action_payload,
 * or the stock / void fields the attendant forms post directly.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/approval_submit.php';

require_attendant();

/** @var array<string, mixed> $approvalRequestData */
$approvalRequestData = $_POST;

function approval_wants_json(): bool
{
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $xhr = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    $response = $GLOBALS['approvalRequestData']['response'] ?? $_GET['response'] ?? '';

    return str_contains($accept, 'application/json')
        || strcasecmp($xhr, 'XMLHttpRequest') === 0
        || str_contains(strtolower($contentType), 'application/json')
        || $response === 'json';
}

function approval_redirect_path(): string
{
    $redirect = $GLOBALS['approvalRequestData']['redirect_to'] ?? '/attendant/index.php';
    if (!is_string($redirect) || !preg_match('#^/attendant/[a-z_]+\.php$#', $redirect)) {
        return '/attendant/index.php';
    }

    return $redirect;
}

function approval_respond(bool $ok, string $message, int $status = 200): never
{
    if (approval_wants_json()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => $ok,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    redirect(approval_redirect_path(), $message, $ok ? 'success' : 'error');
}

if (str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || strlen($rawBody) > 65535) {
        approval_respond(false, 'Request details must be under 64 KB.', 413);
    }

    try {
        $decodedRequest = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        approval_respond(false, 'Request body must be valid JSON.', 400);
    }
    if (!is_array($decodedRequest) || str_starts_with(ltrim($rawBody), '[')) {
        approval_respond(false, 'Request body must be a JSON object.', 400);
    }
    $approvalRequestData = $decodedRequest;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    approval_respond(false, 'Submit approval requests using the form.', 405);
}

$csrfToken = (string) ($_SESSION['attendant_approval_csrf'] ?? '');
$submittedToken = $approvalRequestData['csrf_token'] ?? '';
if ($csrfToken === '' || !is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
    approval_respond(false, 'Your request form expired. Reload and try again.', 403);
}

$attendantId = get_user_id();
if ($attendantId === null) {
    approval_respond(false, 'Please log in to continue.', 401);
}

$actionTypeValue = $approvalRequestData['action_type'] ?? '';
if (!is_string($actionTypeValue)) {
    approval_respond(false, 'Enter a valid action type.', 422);
}
$actionType = trim($actionTypeValue);

try {
    if ($actionType === 'stock_adjustment' && isset($approvalRequestData['product_id'])) {
        $payload = [
            'product_id' => $approvalRequestData['product_id'],
            'old_stock' => $approvalRequestData['old_stock'] ?? null,
            'new_stock' => $approvalRequestData['new_stock'] ?? null,
            'reason' => trim((string) ($approvalRequestData['reason'] ?? '')),
        ];
    } elseif ($actionType === 'void_sale' && isset($approvalRequestData['sale_id'])) {
        $payload = [
            'sale_id' => $approvalRequestData['sale_id'],
            'sale_number' => trim((string) ($approvalRequestData['sale_number'] ?? '')),
            'reason' => trim((string) ($approvalRequestData['reason'] ?? '')),
        ];
    } else {
        $payload = approval_payload_from_request($approvalRequestData);
    }

    submit_approval_request($attendantId, $actionType, $payload);
} catch (InvalidArgumentException $exception) {
    approval_respond(false, $exception->getMessage(), 422);
} catch (JsonException) {
    approval_respond(false, 'Request details must be a JSON object.', 422);
} catch (Throwable $exception) {
    approval_respond(
        false,
        env('APP_DEBUG', false) === true
            ? $exception->getMessage()
            : 'The approval request could not be saved.',
        500
    );
}

approval_respond(true, APPROVAL_SUCCESS_MESSAGE);
