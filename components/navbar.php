<?php
$isLoggedIn = isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0;

if ($isLoggedIn) {
    $cartCount = (int) ($_SESSION['cart_count'] ?? 0);
} else {
    $cart = $_SESSION['cart'] ?? [];
    $cartCount = is_array($cart) ? array_sum(array_map('intval', $cart)) : 0;
}

$cartCount = max(0, $cartCount);
$navBasePath = isset($frontendBasePath) ? rtrim((string) $frontendBasePath, '/') : '';
?>
<nav class="relative z-50 bg-gray-900 text-white shadow-lg" aria-label="Main navigation">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
        <a href="<?= htmlspecialchars($navBasePath, ENT_QUOTES, 'UTF-8') ?>/views/home.php"
           class="text-xl font-bold tracking-tight text-white hover:text-orange-400">
            Jengo <span class="text-orange-500">Hardware</span>
        </a>

        <div class="hidden items-center gap-6 md:flex">
            <a class="jengo-hover-transition text-sm text-gray-200 hover:text-orange-400"
               href="<?= htmlspecialchars($navBasePath, ENT_QUOTES, 'UTF-8') ?>/views/home.php">Home</a>
            <a class="jengo-hover-transition text-sm text-gray-200 hover:text-orange-400"
               href="<?= htmlspecialchars($navBasePath, ENT_QUOTES, 'UTF-8') ?>/views/shop.php">Shop</a>
            <?php if (!in_array($_SESSION['user_role'] ?? null, ['admin', 'attendant', 'employee'], true)): ?>
            <a class="jengo-hover-transition relative text-sm text-gray-200 hover:text-orange-400"
               href="<?= htmlspecialchars($navBasePath, ENT_QUOTES, 'UTF-8') ?>/views/cart.php"
               aria-label="Cart<?= $cartCount > 0 ? ', ' . $cartCount . ' items' : '' ?>">
                Cart
                <span data-cart-count class="ml-1 inline-flex min-w-5 items-center justify-center rounded-full bg-orange-600 px-1.5 py-0.5 text-xs font-semibold text-white"
                      aria-hidden="true"><?= $cartCount ?></span>
            </a>
            <?php endif; ?>
            <?php if ($isLoggedIn): ?>
                <a class="jengo-hover-transition text-sm text-gray-200 hover:text-orange-400"
                   href="<?= htmlspecialchars($navBasePath, ENT_QUOTES, 'UTF-8') ?>/views/dashboard.php">Dashboard</a>
            <?php else: ?>
                <a class="jengo-hover-transition rounded-md bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700"
                   href="<?= htmlspecialchars($navBasePath, ENT_QUOTES, 'UTF-8') ?>/views/login.php">Login</a>
            <?php endif; ?>
        </div>

        <button id="mobile-menu-toggle"
                class="inline-flex items-center justify-center rounded-md p-2 text-gray-200 hover:bg-gray-800 hover:text-white focus:outline-none focus:ring-2 focus:ring-orange-500 md:hidden"
                type="button"
                aria-controls="mobile-menu"
                aria-expanded="false"
                aria-label="Open navigation menu">
            <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none"
                 viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>
    </div>

    <div id="mobile-menu" class="hidden border-t border-gray-800 px-4 pb-4 md:hidden">
        <div class="flex flex-col gap-1 pt-2">
            <a class="jengo-hover-transition rounded-md px-3 py-2 text-sm text-gray-200 hover:bg-gray-800 hover:text-orange-400"
               href="<?= htmlspecialchars($navBasePath, ENT_QUOTES, 'UTF-8') ?>/views/home.php">Home</a>
            <a class="jengo-hover-transition rounded-md px-3 py-2 text-sm text-gray-200 hover:bg-gray-800 hover:text-orange-400"
               href="<?= htmlspecialchars($navBasePath, ENT_QUOTES, 'UTF-8') ?>/views/shop.php">Shop</a>
            <?php if (!in_array($_SESSION['user_role'] ?? null, ['admin', 'attendant', 'employee'], true)): ?>
            <a class="jengo-hover-transition flex items-center justify-between rounded-md px-3 py-2 text-sm text-gray-200 hover:bg-gray-800 hover:text-orange-400"
               href="<?= htmlspecialchars($navBasePath, ENT_QUOTES, 'UTF-8') ?>/views/cart.php">
                <span>Cart</span>
                <span data-cart-count class="inline-flex min-w-5 items-center justify-center rounded-full bg-orange-600 px-1.5 py-0.5 text-xs font-semibold text-white"
                      aria-label="<?= $cartCount ?> items"><?= $cartCount ?></span>
            </a>
            <?php endif; ?>
            <?php if ($isLoggedIn): ?>
                <a class="jengo-hover-transition rounded-md px-3 py-2 text-sm text-gray-200 hover:bg-gray-800 hover:text-orange-400"
                   href="<?= htmlspecialchars($navBasePath, ENT_QUOTES, 'UTF-8') ?>/views/dashboard.php">Dashboard</a>
            <?php else: ?>
                <a class="jengo-hover-transition rounded-md px-3 py-2 text-sm text-gray-200 hover:bg-gray-800 hover:text-orange-400"
                   href="<?= htmlspecialchars($navBasePath, ENT_QUOTES, 'UTF-8') ?>/views/login.php">Login</a>
            <?php endif; ?>
        </div>
    </div>
</nav>
