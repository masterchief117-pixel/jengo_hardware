<?php
/**
 * Apply the database-controlled maintenance mode to normal application pages.
 */

declare(strict_types=1);

function is_maintenance_exempt_request(): bool
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $script = basename($scriptName);
    $parent = basename(dirname($scriptName));

    if (in_array($script, ['login.php', 'logout.php'], true)) {
        return true;
    }

    if ($parent === 'api' || ($parent === 'attendant' && str_starts_with($script, 'api_'))) {
        return true;
    }

    return in_array($script, [
        'mpesa_callback.php',
        'mpesa_status.php',
        'paystack_verify.php',
        'paystack_webhook.php',
    ], true);
}

function enforce_maintenance_mode(): void
{
    $statement = db()->query(
        'SELECT system_active FROM system_settings WHERE id = 1 LIMIT 1'
    );
    $systemActive = $statement->fetchColumn();

    if ((int) $systemActive === 1) {
        return;
    }

    if (($_SESSION['user_role'] ?? null) === 'owner' || is_maintenance_exempt_request()) {
        return;
    }

    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Retry-After: 300');

    exit(
        '<!doctype html><html lang="en"><meta charset="utf-8">' .
        '<meta name="viewport" content="width=device-width, initial-scale=1">' .
        '<title>System Offline</title>' .
        '<body style="display:grid;min-height:100vh;place-items:center;margin:0;' .
        'background:#182026;color:#fff;font:16px/1.5 Arial,sans-serif;text-align:center">' .
        '<main><h1 style="color:#D9531E;font-size:3rem;margin-bottom:.5rem">SYSTEM OFFLINE</h1>' .
        '<p>Jengo Hardware operations are temporarily unavailable during maintenance.</p>' .
        '</main></body></html>'
    );
}
