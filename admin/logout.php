<?php
/**
 * End only the independent admin-portal session.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Strict',
    ]);
}

session_destroy();
header('Location: ' . rtrim((string) env('APP_URL', ''), '/') . '/admin/login.php');
exit;
