<?php
/**
 * includes/auth.php
 *
 * Session initialisation and role-based access control helpers.
 *
 * Include this file at the top of any page that needs auth awareness:
 *   require_once __DIR__ . '/../includes/auth.php';
 *
 * Then guard pages with:
 *   require_login();            // any authenticated user
 *   require_role('admin');      // admin only
 *   require_admin();            // shorthand for admin
 *   require_attendant();        // admin OR attendant
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

// ---------------------------------------------------------------------------
// 1. SESSION BOOTSTRAP
// ---------------------------------------------------------------------------
// Start the session once with secure defaults.
// Called automatically when this file is included.
// ---------------------------------------------------------------------------

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return; // Already started — nothing to do
    }

    $lifetime = (int) env('SESSION_LIFETIME', 3600);
    $isSecure = env('APP_ENV', 'development') === 'production'; // HTTPS-only cookie in prod

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => '',           // Current domain only
        'secure'   => $isSecure,    // Send cookie over HTTPS only (production)
        'httponly' => true,         // Inaccessible to JavaScript (XSS mitigation)
        'samesite' => 'Lax',        // CSRF mitigation
    ]);

    session_name('JENGO_SESS');
    session_start();

    // Regenerate session ID periodically to prevent session fixation.
    // We store the last regeneration time in the session itself.
    if (!isset($_SESSION['_last_regenerated'])) {
        session_regenerate_id(true);
        $_SESSION['_last_regenerated'] = time();
    } elseif (time() - $_SESSION['_last_regenerated'] > 300) {
        // Regenerate every 5 minutes
        session_regenerate_id(true);
        $_SESSION['_last_regenerated'] = time();
    }

    // Enforce session lifetime manually (handles server-side expiry regardless
    // of cookie settings — important for shared/XAMPP environments).
    if (isset($_SESSION['_expires_at']) && time() > $_SESSION['_expires_at']) {
        session_unset();
        session_destroy();
        session_start();
        session_regenerate_id(true);
    }

    // Refresh expiry on every active request
    $_SESSION['_expires_at'] = time() + $lifetime;
}

// Kick off session as soon as this file is included
start_session();

require_once __DIR__ . '/../../includes/maintenance.php';
enforce_maintenance_mode();


// ---------------------------------------------------------------------------
// 2. LOGIN / LOGOUT HELPERS
// ---------------------------------------------------------------------------

/**
 * Log a user in by writing their data to the session.
 * Call this after verifying credentials in login.php.
 *
 * @param array $user  Associative array row from the `users` table.
 */
function login_user(array $user): void
{
    // Rotate the session ID on login to prevent session fixation attacks
    session_regenerate_id(true);
    $_SESSION['_last_regenerated'] = time();
    $_SESSION['_expires_at']       = time() + (int) env('SESSION_LIFETIME', 3600);

    $_SESSION['user_id']   = (int)    $user['id'];
    $_SESSION['user_name'] = (string) $user['full_name'];
    $_SESSION['user_email']= (string) $user['email'];
    $_SESSION['user_role'] = (string) $user['role'];
}

/**
 * Destroy the current session and redirect to the login page.
 */
function logout_user(): void
{
    session_unset();
    session_destroy();
    redirect('/login.php');
}


// ---------------------------------------------------------------------------
// 3. STATE CHECKS
// ---------------------------------------------------------------------------

/**
 * Returns true when a user is authenticated in the current session.
 */
function is_logged_in(): bool
{
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0;
}

/**
 * Returns the role string of the logged-in user, or null if not logged in.
 *
 * Possible values: 'customer' | 'attendant' | 'admin' | null
 */
function get_user_role(): ?string
{
    return $_SESSION['user_role'] ?? null;
}

/**
 * Returns the ID of the currently logged-in user, or null.
 */
function get_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/**
 * Returns the full name of the currently logged-in user, or null.
 */
function get_user_name(): ?string
{
    return $_SESSION['user_name'] ?? null;
}

/**
 * Check whether the logged-in user has a specific role.
 *
 * @param string $role  'customer' | 'attendant' | 'admin'
 */
function has_role(string $role): bool
{
    return is_logged_in() && get_user_role() === $role;
}

function is_system_owner(): bool
{
    return has_role('owner');
}

/**
 * Check whether the logged-in user has ANY of the given roles.
 *
 * @param string[] $roles
 */
function has_any_role(array $roles): bool
{
    return is_logged_in() && in_array(get_user_role(), $roles, true);
}


// ---------------------------------------------------------------------------
// 4. REDIRECT HELPERS
// ---------------------------------------------------------------------------

/**
 * Redirect to a URL relative to APP_URL, or to an absolute URL.
 * Appends an optional flash message to the query string.
 *
 * Examples:
 *   redirect('/login.php');
 *   redirect('/login.php', 'You must be logged in.', 'error');
 */
function redirect(string $path, string $message = '', string $type = 'info'): never
{
    if ($message !== '') {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type']    = $type;   // 'info' | 'success' | 'error' | 'warning'
    }

    // Absolute URLs pass through; relative paths are resolved against APP_URL
    $url = str_starts_with($path, 'http') ? $path : rtrim(env('APP_URL', ''), '/') . $path;

    header('Location: ' . $url);
    exit;
}

/**
 * Retrieve and clear a stored flash message.
 * Returns ['message' => '...', 'type' => '...'] or null.
 */
function get_flash(): ?array
{
    if (!isset($_SESSION['flash_message'])) {
        return null;
    }

    $flash = [
        'message' => $_SESSION['flash_message'],
        'type'    => $_SESSION['flash_type'] ?? 'info',
    ];

    unset($_SESSION['flash_message'], $_SESSION['flash_type']);

    return $flash;
}


// ---------------------------------------------------------------------------
// 5. ROUTE GUARDS
// ---------------------------------------------------------------------------

/**
 * Require the user to be logged in.
 * Guests are redirected to login.php with a flash message.
 * Passes for any authenticated role.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        redirect('/login.php', 'Please log in to continue.', 'error');
    }
}

/**
 * Require the user to have a specific role.
 * Redirects unauthenticated users to login, unauthorised users to their
 * own dashboard (or home if no dashboard is defined for their role).
 *
 * @param string $role  'customer' | 'attendant' | 'admin'
 */
function require_role(string $role): void
{
    if (!is_logged_in()) {
        redirect('/login.php', 'Please log in to continue.', 'error');
    }

    if (get_user_role() !== $role) {
        redirect(
            role_dashboard_path(),
            'You do not have permission to access that page.',
            'error'
        );
    }
}

/**
 * Restrict the page to admins only.
 */
function require_admin(): void
{
    require_role('admin');
}

function require_owner(): void
{
    if (!is_logged_in()) {
        redirect('/login.php', 'Please log in to continue.', 'error');
    }

    if (!is_system_owner()) {
        redirect(
            role_dashboard_path(),
            'You do not have permission to access that page.',
            'error'
        );
    }
}

/**
 * Restrict the page to attendants OR admins.
 * (Admins can always access attendant views.)
 */
function require_attendant(): void
{
    if (!is_logged_in()) {
        redirect('/login.php', 'Please log in to continue.', 'error');
    }

    if (!has_any_role(['attendant', 'admin'])) {
        redirect(
            role_dashboard_path(),
            'You do not have permission to access that page.',
            'error'
        );
    }

    $statement = db()->prepare(
        'SELECT employment_status FROM employees WHERE user_id = ? LIMIT 1'
    );
    $statement->execute([get_user_id()]);
    if ($statement->fetchColumn() !== 'employed') {
        $_SESSION['user_role'] = 'customer';
        redirect(
            '/index.php',
            'Your staff access is no longer active. You are signed in as a customer.',
            'info'
        );
    }
}

/**
 * Prevent already-logged-in users from viewing the login / register pages.
 * Redirects them straight to their own dashboard.
 */
function redirect_if_logged_in(): void
{
    if (is_logged_in()) {
        redirect(role_dashboard_path());
    }
}


// ---------------------------------------------------------------------------
// 6. UTILITY
// ---------------------------------------------------------------------------

/**
 * Return the default dashboard path for the current user's role.
 * Used by guards when redirecting unauthorised users away from a page.
 */
function role_dashboard_path(): string
{
    return match (get_user_role()) {
        'owner'     => '/admin/owner_dashboard.php',
        'admin'     => '/admin/login.php',
        'attendant' => '/attendant/index.php',
        'customer'  => '/customer/dashboard.php',
        default     => '/index.php',
    };
}

/**
 * Re-read the user's current record from the database and refresh the session.
 * Useful after a profile update so the nav bar reflects new data immediately.
 */
function refresh_user_session(): void
{
    $id = get_user_id();
    if ($id === null) {
        return;
    }

    $stmt = db()->prepare(
        'SELECT id, full_name, email, role FROM users WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if ($user === false) {
        // User deactivated or deleted — force logout
        logout_user();
    }

    login_user($user); // Overwrites session data with fresh DB values
}
