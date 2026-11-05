<?php
declare(strict_types=1);

$pageTitle = 'Quality Tools & Building Supplies';
require __DIR__ . '/../components/header.php';
?>

<main>
    <section class="relative isolate overflow-hidden bg-gray-950 text-white">
        <div class="absolute inset-0 -z-10 opacity-20"
             style="background: radial-gradient(ellipse at 80% 20%, var(--jengo-safety-orange), transparent 55%);"
             aria-hidden="true"></div>
        <div class="mx-auto grid max-w-7xl items-center gap-12 px-4 py-20 sm:px-6 sm:py-28 lg:grid-cols-2 lg:px-8 lg:py-32">
            <div class="max-w-2xl">
                <p class="mb-5 inline-flex items-center gap-2 rounded-full border border-orange-400/40 bg-orange-500/10 px-4 py-2 text-sm font-semibold text-orange-300">
                    <span class="h-2 w-2 rounded-full" style="background-color: var(--jengo-safety-orange);" aria-hidden="true"></span>
                    Built for the work ahead
                </p>
                <h1 class="text-4xl font-extrabold leading-tight tracking-tight sm:text-5xl lg:text-6xl">
                    Make your next project
                    <span style="color: var(--jengo-safety-orange);">a strong one.</span>
                </h1>
                <p class="mt-6 max-w-xl text-lg leading-8 text-gray-300">
                    Find dependable tools, building materials, and hardware essentials for every job, big or small.
                </p>
                <div class="mt-9 flex flex-wrap items-center gap-4">
                    <a href="shop.php"
                       class="jengo-hover-transition inline-flex items-center gap-2 rounded-lg px-6 py-3.5 text-base font-bold text-white shadow-lg hover:brightness-90 focus:outline-none focus:ring-2 focus:ring-orange-400 focus:ring-offset-2 focus:ring-offset-gray-950"
                       style="background-color: var(--jengo-safety-orange);">
                        Shop Now
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none"
                             viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                        </svg>
                    </a>
                    <a href="#how-to-order" class="rounded-lg px-5 py-3.5 font-semibold text-gray-200 hover:text-white">
                        How to order
                    </a>
                </div>
            </div>

            <div class="relative mx-auto w-full max-w-lg lg:max-w-none" aria-hidden="true">
                <div class="absolute -inset-5 rounded-3xl opacity-20 blur-2xl"
                     style="background-color: var(--jengo-safety-orange);"></div>
                <div class="relative overflow-hidden rounded-3xl border border-white/10 bg-gray-900 p-6 shadow-2xl sm:p-8">
                    <div class="flex items-center justify-between border-b border-white/10 pb-5">
                        <div>
                            <p class="text-sm font-medium text-gray-400">READY FOR YOUR NEXT JOB?</p>
                            <p class="mt-1 text-xl font-bold text-white">Get equipped. Get building.</p>
                        </div>
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl text-white"
                              style="background-color: var(--jengo-safety-orange);">
                            <svg class="h-7 w-7" xmlns="http://www.w3.org/2000/svg" fill="none"
                                 viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                      d="M14.7 6.3a4 4 0 005.6 5.6l-9.5 9.5a2.1 2.1 0 01-3-3l9.5-9.5a4 4 0 01-5.6-5.6l2.8 2.8 2.8-2.8-2.6-2.6z"/>
                            </svg>
                        </span>
                    </div>
                    <div class="mt-6 space-y-4">
                        <div class="flex items-center gap-4 rounded-xl bg-white/5 p-4">
                            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-orange-500/15 text-orange-300">
                                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none"
                                     viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                          d="M3 7h18M5 7l1 14h12l1-14M9 7V5a3 3 0 016 0v2"/>
                                </svg>
                            </span>
                            <div>
                                <p class="font-semibold text-white">The essentials, all in one place</p>
                                <p class="mt-1 text-sm text-gray-400">Browse tools, materials, and more</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4 rounded-xl bg-white/5 p-4">
                            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-green-500/15 text-green-300">
                                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none"
                                     viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                          d="M5 12l4 4L19 6"/>
                                </svg>
                            </span>
                            <div>
                                <p class="font-semibold text-white">A simple way to get started</p>
                                <p class="mt-1 text-sm text-gray-400">Choose what you need and check out</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="how-to-order" class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-sm font-bold uppercase tracking-widest" style="color: var(--jengo-safety-orange);">Simple from start to finish</p>
                <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl">How to order</h2>
                <p class="mt-4 text-lg text-gray-600">The supplies you need are only a few steps away.</p>
            </div>

            <div class="mt-14 grid gap-6 md:grid-cols-3">
                <article class="jengo-hover-lift rounded-2xl border border-gray-200 bg-gray-50 p-7">
                    <span class="flex h-12 w-12 items-center justify-center rounded-xl text-lg font-extrabold text-white"
                          style="background-color: var(--jengo-safety-orange);">1</span>
                    <h3 class="mt-6 text-xl font-bold text-gray-900">Find what you need</h3>
                    <p class="mt-2 leading-7 text-gray-600">Browse the shop and choose the tools and materials for your project.</p>
                </article>
                <article class="jengo-hover-lift rounded-2xl border border-gray-200 bg-gray-50 p-7">
                    <span class="flex h-12 w-12 items-center justify-center rounded-xl text-lg font-extrabold text-white"
                          style="background-color: var(--jengo-safety-orange);">2</span>
                    <h3 class="mt-6 text-xl font-bold text-gray-900">Add to your cart</h3>
                    <p class="mt-2 leading-7 text-gray-600">Select the products you want, then review your items and quantities.</p>
                </article>
                <article class="jengo-hover-lift rounded-2xl border border-gray-200 bg-gray-50 p-7">
                    <span class="flex h-12 w-12 items-center justify-center rounded-xl text-lg font-extrabold text-white"
                          style="background-color: var(--jengo-safety-orange);">3</span>
                    <h3 class="mt-6 text-xl font-bold text-gray-900">Checkout securely</h3>
                    <p class="mt-2 leading-7 text-gray-600">Enter your details, choose a payment option, and place your order.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="border-y border-gray-200 bg-gray-50 py-12" aria-label="Shopping benefits">
        <div class="mx-auto grid max-w-7xl gap-8 px-4 sm:px-6 md:grid-cols-2 lg:px-8">
            <div class="flex items-center gap-5 rounded-2xl bg-white p-6 shadow-sm">
                <span class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-xl bg-green-100 text-green-700">
                    <svg class="h-7 w-7" xmlns="http://www.w3.org/2000/svg" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                              d="M12 3l8 4v5c0 5-3.4 8-8 9-4.6-1-8-4-8-9V7l8-4z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                              d="M9 12l2 2 4-4"/>
                    </svg>
                </span>
                <div>
                    <h2 class="text-lg font-bold text-gray-900">Secure Payments</h2>
                    <p class="mt-1 text-sm leading-6 text-gray-600">Checkout with confidence using secure payment options.</p>
                </div>
            </div>
            <div class="flex items-center gap-5 rounded-2xl bg-white p-6 shadow-sm">
                <span class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-700">
                    <svg class="h-7 w-7" xmlns="http://www.w3.org/2000/svg" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                              d="M3 7h11v10H3zM14 10h4l3 3v4h-7z"/>
                        <circle cx="7.5" cy="18" r="1.5" stroke-width="1.8"/>
                        <circle cx="17.5" cy="18" r="1.5" stroke-width="1.8"/>
                    </svg>
                </span>
                <div>
                    <h2 class="text-lg font-bold text-gray-900">Fast Delivery</h2>
                    <p class="mt-1 text-sm leading-6 text-gray-600">Get your project supplies delivered without the extra hassle.</p>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require __DIR__ . '/../components/footer.php'; ?>