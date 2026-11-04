<?php
declare(strict_types=1);

$pageTitle = 'Create an account';
require __DIR__ . '/../components/header.php';
?>

<main class="flex min-h-[calc(100vh-13rem)] items-center justify-center px-4 py-12 sm:px-6">
    <section class="w-full max-w-lg rounded-2xl border border-gray-200 bg-white p-6 shadow-xl sm:p-8"
             aria-labelledby="register-heading">
        <div class="text-center">
            <p class="text-sm font-bold uppercase tracking-widest" style="color: var(--jengo-safety-orange);">Join Jengo Hardware</p>
            <h1 id="register-heading" class="mt-2 text-3xl font-extrabold tracking-tight text-gray-900">Create your account</h1>
            <p class="mt-2 text-sm text-gray-600">Set up your account to make your next project easier.</p>
        </div>

        <form action="register.php" method="post" class="mt-8 space-y-5">
            <div>
                <label for="register-name" class="mb-2 block text-sm font-semibold text-gray-700">Full name</label>
                <input id="register-name"
                       name="full_name"
                       type="text"
                       autocomplete="name"
                       required
                       minlength="2"
                       class="block w-full rounded-lg border border-gray-300 px-4 py-3 text-gray-900 placeholder:text-gray-400 focus:border-[#ff6600] focus:outline-none focus:ring-2 focus:ring-[#ff6600]/30"
                       placeholder="Your full name">
            </div>

            <div>
                <label for="register-email" class="mb-2 block text-sm font-semibold text-gray-700">Email address</label>
                <input id="register-email"
                       name="email"
                       type="email"
                       autocomplete="email"
                       inputmode="email"
                       required
                       class="block w-full rounded-lg border border-gray-300 px-4 py-3 text-gray-900 placeholder:text-gray-400 focus:border-[#ff6600] focus:outline-none focus:ring-2 focus:ring-[#ff6600]/30"
                       placeholder="you@example.com">
            </div>

            <div>
                <label for="register-phone" class="mb-2 block text-sm font-semibold text-gray-700">Kenyan mobile number</label>
                <input id="register-phone"
                       name="phone"
                       type="tel"
                       autocomplete="tel"
                       inputmode="tel"
                       required
                       pattern="\+254 [17][0-9]{2} [0-9]{3} [0-9]{3}"
                       title="Enter a Kenyan mobile number in this format: +254 712 345 678"
                       data-format-phone
                       class="block w-full rounded-lg border border-gray-300 px-4 py-3 text-gray-900 placeholder:text-gray-400 focus:border-[#ff6600] focus:outline-none focus:ring-2 focus:ring-[#ff6600]/30"
                       placeholder="+254 712 345 678"
                       aria-describedby="register-phone-help">
                <p id="register-phone-help" class="mt-1.5 text-xs text-gray-500">Format: +254 712 345 678</p>
            </div>

            <div>
                <label for="register-password" class="mb-2 block text-sm font-semibold text-gray-700">Password</label>
                <input id="register-password"
                       name="password"
                       type="password"
                       autocomplete="new-password"
                       required
                       minlength="8"
                       class="block w-full rounded-lg border border-gray-300 px-4 py-3 text-gray-900 placeholder:text-gray-400 focus:border-[#ff6600] focus:outline-none focus:ring-2 focus:ring-[#ff6600]/30"
                       placeholder="At least 8 characters">
            </div>

            <button type="submit"
                    class="jengo-hover-transition w-full rounded-lg px-4 py-3 font-bold text-white hover:brightness-90 focus:outline-none focus:ring-2 focus:ring-[#ff6600] focus:ring-offset-2"
                    style="background-color: var(--jengo-safety-orange);">
                Create account
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-gray-600">
            Already have an account?
            <a href="login.php" class="font-bold hover:underline" style="color: var(--jengo-dark-charcoal);">
                Log in
            </a>
        </p>
    </section>
</main>

<?php require __DIR__ . '/../components/footer.php'; ?>