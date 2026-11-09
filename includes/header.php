<?php
/**
 * includes/header.php
 *
 * Shared page header: HTML <head>, Tailwind CDN, and responsive navbar.
 * Include at the very top of every public-facing page.
 *
 * Accepts optional variables set before the include:
 *   $pageTitle       — string  Browser tab / <title> (default: "Jengo Hardware")
 *   $bodyClass       — string  Extra classes added to <body> (optional)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

// ── Resolve page title ────────────────────────────────────────────────────
$pageTitle = isset($pageTitle) && $pageTitle !== ''
    ? htmlspecialchars($pageTitle) . ' — Jengo Hardware'
    : 'Jengo Hardware';

// ── Cart item count (guests get 0) ────────────────────────────────────────
$cartCount = 0;
if (is_logged_in()) {
    $cartCount = (int) ($_SESSION['cart_count'] ?? 0);
} else {
    // Guest cart stored in session array
    $cartCount = isset($_SESSION['cart']) ? array_sum($_SESSION['cart']) : 0;
}

// ── Base URL helper (no trailing slash) ───────────────────────────────────
$base = rtrim(env('APP_URL', ''), '/');

// ── Current page path for active-link highlighting ────────────────────────
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
function nav_active(string $href): string
{
    global $currentPath;
    // Match exact path or sub-paths (e.g. /shop.php matches /shop.php?page=2)
    return str_starts_with($currentPath, parse_url($href, PHP_URL_PATH))
        ? 'text-yellow-400 font-semibold'
        : 'text-gray-200 hover:text-yellow-400';
}

// ── Flash message (retrieved once so it clears after display) ─────────────
$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="description" content="Jengo Hardware — Quality tools, building materials, and electrical supplies." />
    <title><?= $pageTitle ?></title>

    <!-- Tailwind CSS (CDN — swap for compiled build in production) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            DEFAULT: '#b45309', // amber-700
                            dark:    '#92400e', // amber-800
                            light:   '#f59e0b', // amber-400
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <!-- Google Font: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />

    <!-- Custom styles -->
    <link rel="stylesheet" href="<?= $base ?>/assets/css/style.css" />

    <!-- Favicon -->
    <link rel="icon" href="<?= $base ?>/assets/img/favicon.ico" type="image/x-icon" />
</head>

<body class="bg-gray-50 text-gray-800 font-sans antialiased <?= htmlspecialchars($bodyClass ?? '') ?>">

<!-- ═══════════════════════════════════════════════════════════════════════
     TOP NAVIGATION BAR
════════════════════════════════════════════════════════════════════════ -->
<nav class="bg-gray-900 shadow-lg sticky top-0 z-50" aria-label="Main navigation">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">

            <!-- ── Brand logo / name ─────────────────────────────────── -->
            <a href="<?= $base ?>/index.php"
               class="flex items-center gap-2 flex-shrink-0"
               aria-label="Jengo Hardware home">
                <!-- Hammer icon (inline SVG — no external dependency) -->
                <svg class="w-7 h-7 text-yellow-400" xmlns="http://www.w3.org/2000/svg"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"
                     aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M14.121 14.121L19 19m-7-7 7-7-3-3-7 7
                             M9.172 9.172 4.343 4.343 1.515 7.172
                             5.172 10.828l4-4z" />
                </svg>
                <span class="text-white text-xl font-bold tracking-tight">
                    Jengo <span class="text-yellow-400">Hardware</span>
                </span>
            </a>

            <!-- ── Desktop nav links ─────────────────────────────────── -->
            <div class="hidden md:flex items-center gap-6" role="menubar">
                <a href="<?= $base ?>/index.php"
                   class="text-sm transition-colors duration-150 <?= nav_active($base . '/index.php') ?>"
                   role="menuitem">Home</a>

                <a href="<?= $base ?>/shop.php"
                   class="text-sm transition-colors duration-150 <?= nav_active($base . '/shop.php') ?>"
                   role="menuitem">Shop</a>

                <!-- Cart with badge -->
                <a href="<?= $base ?>/cart.php"
                   class="relative text-sm transition-colors duration-150 <?= nav_active($base . '/cart.php') ?>"
                   role="menuitem"
                   aria-label="Shopping cart<?= $cartCount > 0 ? ", {$cartCount} items" : '' ?>">
                    <span class="flex items-center gap-1">
                        <!-- Cart icon -->
                        <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor"
                             stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M3 3h2l.4 2M7 13h10l4-8H5.4
                                     M7 13L5.4 5M7 13l-2 9m12-9l2 9
                                     m-9-4h4" />
                        </svg>
                        Cart
                    </span>
                    <?php if ($cartCount > 0): ?>
                    <span class="absolute -top-2 -right-3 bg-yellow-400 text-gray-900
                                 text-xs font-bold rounded-full w-4 h-4 flex items-center
                                 justify-center leading-none"
                          aria-hidden="true">
                        <?= $cartCount > 9 ? '9+' : $cartCount ?>
                    </span>
                    <?php endif; ?>
                </a>

                <!-- ── Auth / role links ── -->
                <?php if (is_logged_in()): ?>

                    <!-- Dashboard link — varies by role -->
                    <?php
                    [$dashLabel, $dashPath] = match (get_user_role()) {
                        'admin'     => ['Admin Panel',  $base . '/admin/index.php'],
                        'attendant' => ['POS Dashboard', $base . '/attendant/index.php'],
                        default     => ['My Account',   $base . '/customer/dashboard.php'],
                    };
                    ?>
                    <a href="<?= $dashPath ?>"
                       class="text-sm transition-colors duration-150 <?= nav_active($dashPath) ?>"
                       role="menuitem">
                        <?= htmlspecialchars($dashLabel) ?>
                    </a>

                    <!-- User dropdown -->
                    <div class="relative" x-data="{ open: false }">
                        <button
                            onclick="document.getElementById('user-menu').classList.toggle('hidden')"
                            class="flex items-center gap-2 text-sm text-gray-200
                                   hover:text-yellow-400 transition-colors duration-150
                                   focus:outline-none focus:ring-2 focus:ring-yellow-400
                                   focus:ring-offset-2 focus:ring-offset-gray-900 rounded"
                            aria-haspopup="true"
                            aria-expanded="false"
                            id="user-menu-btn">
                            <!-- Avatar initials -->
                            <span class="w-7 h-7 rounded-full bg-yellow-500 text-gray-900
                                         font-bold text-xs flex items-center justify-center
                                         uppercase select-none" aria-hidden="true">
                                <?= mb_substr(htmlspecialchars(get_user_name() ?? 'U'), 0, 1) ?>
                            </span>
                            <span class="hidden lg:inline max-w-[120px] truncate">
                                <?= htmlspecialchars(get_user_name() ?? '') ?>
                            </span>
                            <!-- Chevron -->
                            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                 stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        <!-- Dropdown panel -->
                        <div id="user-menu"
                             class="hidden absolute right-0 mt-2 w-44 bg-white rounded-lg
                                    shadow-xl ring-1 ring-black/5 py-1 z-50"
                             role="menu"
                             aria-labelledby="user-menu-btn">

                            <div class="px-4 py-2 border-b border-gray-100">
                                <p class="text-xs text-gray-500 truncate">Signed in as</p>
                                <p class="text-sm font-medium text-gray-800 truncate">
                                    <?= htmlspecialchars(get_user_name() ?? '') ?>
                                </p>
                                <span class="inline-block mt-0.5 text-xs bg-amber-100
                                             text-amber-700 rounded px-1.5 py-0.5 capitalize font-medium">
                                    <?= htmlspecialchars(get_user_role() ?? '') ?>
                                </span>
                            </div>

                            <a href="<?= $dashPath ?>"
                               class="block px-4 py-2 text-sm text-gray-700
                                      hover:bg-gray-50 hover:text-amber-700 transition-colors"
                               role="menuitem">Dashboard</a>

                            <?php if (has_role('customer')): ?>
                            <a href="<?= $base ?>/customer/dashboard.php"
                               class="block px-4 py-2 text-sm text-gray-700
                                      hover:bg-gray-50 hover:text-amber-700 transition-colors"
                               role="menuitem">My Orders</a>
                            <?php endif; ?>

                            <a href="<?= $base ?>/logout.php"
                               class="block px-4 py-2 text-sm text-red-600
                                      hover:bg-red-50 transition-colors"
                               role="menuitem">Sign Out</a>
                        </div>
                    </div>

                <?php else: ?>

                    <!-- Guest links -->
                    <a href="<?= $base ?>/login.php"
                       class="text-sm transition-colors duration-150 <?= nav_active($base . '/login.php') ?>"
                       role="menuitem">Login</a>

                    <a href="<?= $base ?>/register.php"
                       class="text-sm bg-yellow-400 text-gray-900 font-semibold
                              px-4 py-1.5 rounded-md hover:bg-yellow-300
                              transition-colors duration-150 focus:outline-none
                              focus:ring-2 focus:ring-yellow-400 focus:ring-offset-2
                              focus:ring-offset-gray-900"
                       role="menuitem">Register</a>

                <?php endif; ?>
            </div>

            <!-- ── Mobile: cart icon + hamburger ────────────────────── -->
            <div class="flex md:hidden items-center gap-3">
                <!-- Mobile cart -->
                <a href="<?= $base ?>/cart.php"
                   class="relative text-gray-200 hover:text-yellow-400"
                   aria-label="Shopping cart<?= $cartCount > 0 ? ", {$cartCount} items" : '' ?>">
                    <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor"
                         stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M3 3h2l.4 2M7 13h10l4-8H5.4
                                 M7 13L5.4 5M7 13l-2 9m12-9l2 9
                                 m-9-4h4" />
                    </svg>
                    <?php if ($cartCount > 0): ?>
                    <span class="absolute -top-1.5 -right-1.5 bg-yellow-400 text-gray-900
                                 text-xs font-bold rounded-full w-4 h-4 flex items-center
                                 justify-center leading-none" aria-hidden="true">
                        <?= $cartCount > 9 ? '9+' : $cartCount ?>
                    </span>
                    <?php endif; ?>
                </a>

                <!-- Hamburger button -->
                <button id="mobile-menu-btn"
                        onclick="document.getElementById('mobile-menu').classList.toggle('hidden')"
                        class="text-gray-200 hover:text-yellow-400 p-1 rounded
                               focus:outline-none focus:ring-2 focus:ring-yellow-400"
                        aria-controls="mobile-menu"
                        aria-expanded="false"
                        aria-label="Toggle navigation menu">
                    <svg class="w-6 h-6" id="hamburger-icon"
                         xmlns="http://www.w3.org/2000/svg" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                         aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
            </div>

        </div><!-- /flex row -->
    </div><!-- /max-w-7xl -->

    <!-- ── Mobile slide-down menu ────────────────────────────────────── -->
    <div id="mobile-menu"
         class="hidden md:hidden bg-gray-800 border-t border-gray-700"
         role="menu"
         aria-label="Mobile navigation">
        <div class="px-4 pt-3 pb-4 space-y-1">

            <a href="<?= $base ?>/index.php"
               class="block px-3 py-2 rounded-md text-sm font-medium
                      text-gray-200 hover:bg-gray-700 hover:text-yellow-400"
               role="menuitem">🏠 Home</a>

            <a href="<?= $base ?>/shop.php"
               class="block px-3 py-2 rounded-md text-sm font-medium
                      text-gray-200 hover:bg-gray-700 hover:text-yellow-400"
               role="menuitem">🛒 Shop</a>

            <?php if (is_logged_in()): ?>

                <a href="<?= $dashPath ?>"
                   class="block px-3 py-2 rounded-md text-sm font-medium
                          text-gray-200 hover:bg-gray-700 hover:text-yellow-400"
                   role="menuitem">📊 <?= htmlspecialchars($dashLabel) ?></a>

                <?php if (has_role('customer')): ?>
                <a href="<?= $base ?>/customer/dashboard.php"
                   class="block px-3 py-2 rounded-md text-sm font-medium
                          text-gray-200 hover:bg-gray-700 hover:text-yellow-400"
                   role="menuitem">📦 My Orders</a>
                <?php endif; ?>

                <div class="border-t border-gray-700 my-2"></div>

                <div class="px-3 py-1">
                    <p class="text-xs text-gray-400">
                        Signed in as
                        <span class="text-white font-medium">
                            <?= htmlspecialchars(get_user_name() ?? '') ?>
                        </span>
                        <span class="ml-1 text-amber-400 capitalize">(<?= get_user_role() ?>)</span>
                    </p>
                </div>

                <a href="<?= $base ?>/logout.php"
                   class="block px-3 py-2 rounded-md text-sm font-medium
                          text-red-400 hover:bg-red-900/30 hover:text-red-300"
                   role="menuitem">🚪 Sign Out</a>

            <?php else: ?>

                <div class="border-t border-gray-700 my-2"></div>

                <a href="<?= $base ?>/login.php"
                   class="block px-3 py-2 rounded-md text-sm font-medium
                          text-gray-200 hover:bg-gray-700 hover:text-yellow-400"
                   role="menuitem">🔑 Login</a>

                <a href="<?= $base ?>/register.php"
                   class="block px-3 py-2 rounded-md text-sm font-medium
                          bg-yellow-400 text-gray-900 hover:bg-yellow-300 text-center"
                   role="menuitem">Register</a>

            <?php endif; ?>

        </div>
    </div><!-- /mobile-menu -->
</nav>
<?php if (
    is_logged_in()
    && (
        has_role('attendant')
        || (has_any_role(['attendant', 'admin']) && str_contains((string) $currentPath, '/attendant/'))
    )
): ?>
    <?php require __DIR__ . '/../attendant/includes/nav.php'; ?>
<?php endif; ?>
<!-- ═══════════════════════════════════════════════════════════════════════
     END NAVBAR
════════════════════════════════════════════════════════════════════════ -->


<!-- ── Flash message banner ──────────────────────────────────────────── -->
<?php if ($flash): ?>
<?php
$flashClasses = match ($flash['type']) {
    'success' => 'bg-green-50  border-green-400  text-green-800',
    'error'   => 'bg-red-50    border-red-400    text-red-800',
    'warning' => 'bg-yellow-50 border-yellow-400 text-yellow-800',
    default   => 'bg-blue-50   border-blue-400   text-blue-800',
};
$flashIcon = match ($flash['type']) {
    'success' => '✓',
    'error'   => '✕',
    'warning' => '⚠',
    default   => 'ℹ',
};
?>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4" role="alert" aria-live="polite">
    <div class="flex items-start gap-3 border-l-4 rounded-md px-4 py-3 <?= $flashClasses ?>">
        <span class="text-lg font-bold leading-none mt-0.5" aria-hidden="true"><?= $flashIcon ?></span>
        <p class="text-sm font-medium flex-1">
            <?= htmlspecialchars($flash['message']) ?>
        </p>
        <button onclick="this.parentElement.parentElement.remove()"
                class="ml-auto text-current opacity-60 hover:opacity-100 text-lg leading-none"
                aria-label="Dismiss notification">&times;</button>
    </div>
</div>
<?php endif; ?>

<!-- ── Main content wrapper (closed in footer.php) ───────────────────── -->
<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8" id="main-content">
