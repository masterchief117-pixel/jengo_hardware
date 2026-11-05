<?php
/**
 * Independent authentication for the admin portal.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function start_admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isSecure = env('APP_ENV', 'development') === 'production';
    session_set_cookie_params([
        'lifetime' => (int) env('SESSION_LIFETIME', 3600),
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_name('JENGO_ADMIN_SESS');
    session_start();

    $lifetime = (int) env('SESSION_LIFETIME', 3600);
    if (
        isset($_SESSION['admin_expires_at']) &&
        time() > (int) $_SESSION['admin_expires_at']
    ) {
        $_SESSION = [];
        session_destroy();
        session_start();
        session_regenerate_id(true);
    }

    if (!isset($_SESSION['admin_session_started_at'])) {
        session_regenerate_id(true);
        $_SESSION['admin_session_started_at'] = time();
    } elseif (time() - (int) $_SESSION['admin_session_started_at'] > 300) {
        session_regenerate_id(true);
        $_SESSION['admin_session_started_at'] = time();
    }

    $_SESSION['admin_expires_at'] = time() + $lifetime;
}

start_admin_session();

function admin_user_id(): ?int
{
    return isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;
}

function admin_user_name(): ?string
{
    return isset($_SESSION['admin_user_name'])
        ? (string) $_SESSION['admin_user_name']
        : null;
}

function require_admin_login(): void
{
    $adminId = admin_user_id();
    if ($adminId === null) {
        header('Location: ' . rtrim((string) env('APP_URL', ''), '/') . '/admin/login.php');
        exit;
    }

    $statement = db()->prepare(
        'SELECT is_active FROM admin_users WHERE id = ? LIMIT 1'
    );
    $statement->execute([$adminId]);
    $isActive = $statement->fetchColumn();

    if ($isActive === false || (int) $isActive !== 1) {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . rtrim((string) env('APP_URL', ''), '/') . '/admin/login.php');
        exit;
    }
}
