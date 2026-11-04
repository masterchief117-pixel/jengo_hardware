<?php
declare(strict_types=1);

$pageTitle = 'Log in';
require __DIR__ . '/../components/header.php';
?>

<main class="flex min-h-[calc(100vh-13rem)] items-center justify-center px-4 py-12 sm:px-6">
    <section class="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-6 shadow-xl sm:p-8"
             aria-labelledby="login-heading">
        <div class="text-center">
            <p class="text-sm font-bold uppercase tracking-widest" style="color: var(--jengo-safety-orange);">Welcome back</p>
            <h1 id="login-heading" class="mt-2 text-3xl font-extrabold tracking-tight text-gray-900">Log in to your account</h1>
            <p class="mt-2 text-sm text-gray-600">Access your account to continue shopping.</p>
        </div>

        <form action="login.php" method="post" class="mt-8 space-y-5">
            <div>
                <label for="login-email" class="mb-2 block text-sm font-semibold text-gray-700">Email address</label>
                <input id="login-email"
                       name="email"
                       type="email"
                       autocomplete="email"
                       inputmode="email"
                       required
                       class="block w-full rounded-lg border border-gray-300 px-4 py-3 text-gray-900 placeholder:text-gray-400 focus:border-[#ff6600] focus:outline-none focus:ring-2 focus:ring-[#ff6600]/30"
                       placeholder="you@example.com">
            </div>

            <div>
                <label for="login-password" class="mb-2 block text-sm font-semibold text-gray-700">Password</label>
                <input id="login-password"
                       name="password"
                       type="password"
                       autocomplete="current-password"
                       required
                       class="block w-full rounded-lg border border-gray-300 px-4 py-3 text-gray-900 placeholder:text-gray-400 focus:border-[#ff6600] focus:outline-none focus:ring-2 focus:ring-[#ff6600]/30"
                       placeholder="Enter your password">
            </div>

            <button type="submit"
                    class="jengo-hover-transition w-full rounded-lg px-4 py-3 font-bold text-white hover:brightness-90 focus:outline-none focus:ring-2 focus:ring-[#ff6600] focus:ring-offset-2"
                    style="background-color: var(--jengo-safety-orange);">
                Log in
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-gray-600">
            New to Jengo Hardware?
            <a href="register.php" class="font-bold hover:underline" style="color: var(--jengo-dark-charcoal);">
                Create an account
            </a>
        </p>
    </section>
</main>

<?php require __DIR__ . '/../components/footer.php'; ?>