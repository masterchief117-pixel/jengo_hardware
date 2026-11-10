<?php
/**
 * includes/footer.php
 *
 * Shared page footer. Closes the <main> tag opened in header.php,
 * renders the full-width footer, and loads assets/js/main.js.
 *
 * Include at the very bottom of every public-facing page:
 *   require_once __DIR__ . '/../includes/footer.php';
 */

declare(strict_types=1);

// Ensure $base is available even if header.php wasn't included on this page
if (!isset($base)) {
    require_once __DIR__ . '/../config/database.php';
    $base = rtrim(env('APP_URL', ''), '/');
}

$currentYear = date('Y');
?>

</main>
<!-- /#main-content — opened in header.php -->


<!-- ═══════════════════════════════════════════════════════════════════════
     TRUST BADGES STRIP
════════════════════════════════════════════════════════════════════════ -->
<section class="bg-amber-50 border-t border-amber-100 py-6" aria-label="Trust badges">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-6 text-center">

            <!-- Badge 1: Genuine products -->
            <div class="flex flex-col items-center gap-2">
                <div class="w-12 h-12 rounded-full bg-amber-100 flex items-center
                            justify-center text-amber-700" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <p class="text-sm font-semibold text-gray-800">100% Genuine</p>
                <p class="text-xs text-gray-500">Certified quality products</p>
            </div>

            <!-- Badge 2: Secure payments -->
            <div class="flex flex-col items-center gap-2">
                <div class="w-12 h-12 rounded-full bg-amber-100 flex items-center
                            justify-center text-amber-700" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 15v2m-6 4h12a2 2 0 002-2v-6
                                 a2 2 0 00-2-2H6a2 2 0 00-2 2v6
                                 a2 2 0 002 2zm10-10V7a4 4 0
                                 00-8 0v4h8z" />
                    </svg>
                </div>
                <p class="text-sm font-semibold text-gray-800">Secure Payments</p>
                <p class="text-xs text-gray-500">M-Pesa &amp; Paystack protected</p>
            </div>

            <!-- Badge 3: Fast delivery -->
            <div class="flex flex-col items-center gap-2">
                <div class="w-12 h-12 rounded-full bg-amber-100 flex items-center
                            justify-center text-amber-700" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                </div>
                <p class="text-sm font-semibold text-gray-800">Fast Delivery</p>
                <p class="text-xs text-gray-500">Same-day within Nairobi</p>
            </div>

            <!-- Badge 4: Customer support -->
            <div class="flex flex-col items-center gap-2">
                <div class="w-12 h-12 rounded-full bg-amber-100 flex items-center
                            justify-center text-amber-700" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M18.364 5.636l-3.536 3.536m0 5.656
                                 l3.536 3.536M9.172 9.172L5.636 5.636
                                 m3.536 9.192l-3.536 3.536M21 12a9 9
                                 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0
                                 11-8 0 4 4 0 018 0z" />
                    </svg>
                </div>
                <p class="text-sm font-semibold text-gray-800">24/7 Support</p>
                <p class="text-xs text-gray-500">Always here to help</p>
            </div>

        </div>
    </div>
</section>


<!-- ═══════════════════════════════════════════════════════════════════════
     MAIN FOOTER
════════════════════════════════════════════════════════════════════════ -->
<footer class="bg-gray-900 text-gray-300" aria-label="Site footer">

    <!-- ── Four-column link grid ─────────────────────────────────────── -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-14 pb-10">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-10">

            <!-- Col 1: Brand & about -->
            <div>
                <a href="<?= $base ?>/index.php"
                   class="flex items-center gap-2 mb-4"
                   aria-label="Jengo Hardware home">
                    <svg class="w-7 h-7 text-yellow-400" xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor"
                         stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M14.121 14.121L19 19m-7-7 7-7-3-3-7 7
                                 M9.172 9.172 4.343 4.343 1.515 7.172
                                 5.172 10.828l4-4z" />
                    </svg>
                    <span class="text-white text-lg font-bold tracking-tight">
                        Jengo <span class="text-yellow-400">Hardware</span>
                    </span>
                </a>
                <p class="text-sm text-gray-400 leading-relaxed">
                    Your trusted partner for quality hand tools, power tools, plumbing,
                    electrical supplies, and building materials across Kenya.
                </p>

                <!-- Social icons -->
                <div class="flex items-center gap-4 mt-5" aria-label="Social media links">
                    <!-- Facebook -->
                    <a href="#" class="text-gray-400 hover:text-yellow-400 transition-colors"
                       aria-label="Facebook" rel="noopener noreferrer" target="_blank">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"
                             aria-hidden="true">
                            <path d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0
                                     4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797
                                     c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195
                                     2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12
                                     h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22
                                     16.991 22 12z"/>
                        </svg>
                    </a>
                    <!-- Twitter / X -->
                    <a href="#" class="text-gray-400 hover:text-yellow-400 transition-colors"
                       aria-label="Twitter / X" rel="noopener noreferrer" target="_blank">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"
                             aria-hidden="true">
                            <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17
                                     l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254
                                     2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084
                                     4.126H5.117z"/>
                        </svg>
                    </a>
                    <!-- WhatsApp -->
                    <a href="https://wa.me/254700000000"
                       class="text-gray-400 hover:text-yellow-400 transition-colors"
                       aria-label="WhatsApp" rel="noopener noreferrer" target="_blank">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"
                             aria-hidden="true">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967
                                     -.273-.099-.471-.148-.67.15-.197.297-.767.966
                                     -.94 1.164-.173.199-.347.223-.644.075-.297-.15
                                     -1.255-.463-2.39-1.475-.883-.788-1.48-1.761
                                     -1.653-2.059-.173-.297-.018-.458.13-.606.134
                                     -.133.298-.347.446-.52.149-.174.198-.298.298
                                     -.497.099-.198.05-.371-.025-.52-.075-.149-.669
                                     -1.612-.916-2.207-.242-.579-.487-.5-.669-.51
                                     -.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792
                                     .372-.272.297-1.04 1.016-1.04 2.479 0 1.462
                                     1.065 2.875 1.213 3.074.149.198 2.096 3.2
                                     5.077 4.487.709.306 1.262.489 1.694.625.712
                                     .227 1.36.195 1.871.118.571-.085 1.758-.719
                                     2.006-1.413.248-.694.248-1.289.173-1.413
                                     -.074-.124-.272-.198-.57-.347m-5.421 7.403h
                                     -.004a9.87 9.87 0 01-5.031-1.378l-.361-.214
                                     -3.741.982.998-3.648-.235-.374a9.86 9.86 0
                                     01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884
                                     2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0
                                     012.893 6.994c-.003 5.45-4.437 9.884-9.885
                                     9.884m8.413-18.297A11.815 11.815 0 0012.05
                                     0C5.495 0 .16 5.335.157 11.892c0 2.096.547
                                     4.142 1.588 5.945L.057 24l6.305-1.654a11.882
                                     11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335
                                     11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                        </svg>
                    </a>
                    <!-- Instagram -->
                    <a href="#" class="text-gray-400 hover:text-yellow-400 transition-colors"
                       aria-label="Instagram" rel="noopener noreferrer" target="_blank">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"
                             aria-hidden="true">
                            <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148
                                     4.771 1.691 4.919 4.919.058 1.265.069 1.645.069
                                     4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225
                                     -1.664 4.771-4.919 4.919-1.266.058-1.644.07
                                     -4.85.07-3.204 0-3.584-.012-4.849-.07-3.26
                                     -.149-4.771-1.699-4.919-4.92-.058-1.265-.07
                                     -1.644-.07-4.849 0-3.204.013-3.583.07-4.849
                                     .149-3.227 1.664-4.771 4.919-4.919 1.266-.057
                                     1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014
                                     7.053.072 2.695.272.273 2.69.073 7.052.014
                                     8.333 0 8.741 0 12c0 3.259.014 3.668.072
                                     4.948.2 4.358 2.618 6.78 6.98 6.98C8.333
                                     23.986 8.741 24 12 24c3.259 0 3.668-.014
                                     4.948-.072 4.354-.2 6.782-2.618 6.979-6.98
                                     .059-1.28.073-1.689.073-4.948 0-3.259-.014
                                     -3.667-.072-4.947-.196-4.354-2.617-6.78-6.979
                                     -6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162
                                     6.162 0 100 12.324 6.162 6.162 0 000-12.324
                                     zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845
                                     a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/>
                        </svg>
                    </a>
                </div>
            </div>

            <!-- Col 2: Quick links -->
            <div>
                <h3 class="text-white text-sm font-semibold uppercase tracking-wider mb-4">
                    Quick Links
                </h3>
                <ul class="space-y-2.5" role="list">
                    <li>
                        <a href="<?= $base ?>/index.php"
                           class="text-sm text-gray-400 hover:text-yellow-400
                                  transition-colors">Home</a>
                    </li>
                    <li>
                        <a href="<?= $base ?>/shop.php"
                           class="text-sm text-gray-400 hover:text-yellow-400
                                  transition-colors">Shop</a>
                    </li>
                    <?php if (!has_any_role(['admin', 'attendant', 'employee'])): ?>
                    <li>
                        <a href="<?= $base ?>/cart.php"
                           class="text-sm text-gray-400 hover:text-yellow-400
                                  transition-colors">Cart</a>
                    </li>
                    <li>
                        <a href="<?= $base ?>/checkout.php"
                           class="text-sm text-gray-400 hover:text-yellow-400
                                  transition-colors">Checkout</a>
                    </li>
                    <?php endif; ?>
                    <li>
                        <a href="<?= $base ?>/register.php"
                           class="text-sm text-gray-400 hover:text-yellow-400
                                  transition-colors">Create Account</a>
                    </li>
                    <li>
                        <a href="<?= $base ?>/login.php"
                           class="text-sm text-gray-400 hover:text-yellow-400
                                  transition-colors">Login</a>
                    </li>
                </ul>
            </div>

            <!-- Col 3: Product categories -->
            <div>
                <h3 class="text-white text-sm font-semibold uppercase tracking-wider mb-4">
                    Categories
                </h3>
                <ul class="space-y-2.5" role="list">
                    <?php
                    $categories = [
                        'Hand Tools'         => 'hand-tools',
                        'Power Tools'        => 'power-tools',
                        'Plumbing'           => 'plumbing',
                        'Electrical'         => 'electrical',
                        'Building Materials' => 'building-materials',
                        'Safety &amp; PPE'   => 'safety-ppe',
                    ];
                    foreach ($categories as $label => $slug): ?>
                    <li>
                        <a href="<?= $base ?>/shop.php?category=<?= $slug ?>"
                           class="text-sm text-gray-400 hover:text-yellow-400
                                  transition-colors"><?= $label ?></a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Col 4: Contact & payment methods -->
            <div>
                <h3 class="text-white text-sm font-semibold uppercase tracking-wider mb-4">
                    Contact Us
                </h3>
                <ul class="space-y-3 text-sm text-gray-400" role="list">
                    <li class="flex items-start gap-2">
                        <svg class="w-4 h-4 mt-0.5 flex-shrink-0 text-yellow-400"
                             xmlns="http://www.w3.org/2000/svg" fill="none"
                             viewBox="0 0 24 24" stroke="currentColor"
                             stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M17.657 16.657L13.414 20.9a1.998 1.998 0
                                     01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <span>Kirinyaga Road, Nairobi CBD, Kenya</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <svg class="w-4 h-4 mt-0.5 flex-shrink-0 text-yellow-400"
                             xmlns="http://www.w3.org/2000/svg" fill="none"
                             viewBox="0 0 24 24" stroke="currentColor"
                             stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498
                                     4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042
                                     11.042 0 005.516 5.516l1.13-2.257a1 1 0
                                     011.21-.502l4.493 1.498a1 1 0 01.684.949V19
                                     a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                        </svg>
                        <a href="tel:+254700000000"
                           class="hover:text-yellow-400 transition-colors">
                            +254 700 000 000
                        </a>
                    </li>
                    <li class="flex items-start gap-2">
                        <svg class="w-4 h-4 mt-0.5 flex-shrink-0 text-yellow-400"
                             xmlns="http://www.w3.org/2000/svg" fill="none"
                             viewBox="0 0 24 24" stroke="currentColor"
                             stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14
                                     a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2
                                     2v10a2 2 0 002 2z"/>
                        </svg>
                        <a href="mailto:info@jengohardware.co.ke"
                           class="hover:text-yellow-400 transition-colors">
                            info@jengohardware.co.ke
                        </a>
                    </li>
                    <li class="flex items-start gap-2">
                        <svg class="w-4 h-4 mt-0.5 flex-shrink-0 text-yellow-400"
                             xmlns="http://www.w3.org/2000/svg" fill="none"
                             viewBox="0 0 24 24" stroke="currentColor"
                             stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>Mon – Sat: 7:30 AM – 6:00 PM</span>
                    </li>
                </ul>

                <!-- Payment method badges -->
                <div class="mt-6">
                    <p class="text-xs text-gray-500 uppercase tracking-wider mb-3 font-medium">
                        We Accept
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- M-Pesa badge -->
                        <span class="inline-flex items-center gap-1 bg-green-900/50
                                     border border-green-700 text-green-300 text-xs
                                     font-semibold px-2.5 py-1 rounded-md">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"
                                 aria-hidden="true">
                                <circle cx="12" cy="12" r="10"/>
                            </svg>
                            M-Pesa
                        </span>
                        <!-- Paystack badge -->
                        <span class="inline-flex items-center gap-1 bg-blue-900/50
                                     border border-blue-700 text-blue-300 text-xs
                                     font-semibold px-2.5 py-1 rounded-md">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"
                                 aria-hidden="true">
                                <rect x="2" y="5" width="20" height="14" rx="2"/>
                            </svg>
                            Paystack
                        </span>
                        <!-- Cash badge -->
                        <span class="inline-flex items-center gap-1 bg-gray-700
                                     border border-gray-600 text-gray-300 text-xs
                                     font-semibold px-2.5 py-1 rounded-md">
                            <svg class="w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                 stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6
                                         a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6
                                         a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0
                                         002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                            Cash
                        </span>
                    </div>
                </div>
            </div>

        </div><!-- /grid -->
    </div><!-- /max-w-7xl -->

    <!-- ── Bottom bar ────────────────────────────────────────────────── -->
    <div class="border-t border-gray-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5
                    flex flex-col sm:flex-row items-center justify-between gap-3">

            <p class="text-xs text-gray-500 text-center sm:text-left">
                &copy; <?= $currentYear ?> Jengo Hardware. All rights reserved.
            </p>

            <div class="flex items-center gap-4 text-xs text-gray-500">
                <a href="#" class="hover:text-yellow-400 transition-colors">
                    Privacy Policy
                </a>
                <span aria-hidden="true">&middot;</span>
                <a href="#" class="hover:text-yellow-400 transition-colors">
                    Terms of Service
                </a>
                <span aria-hidden="true">&middot;</span>
                <a href="#" class="hover:text-yellow-400 transition-colors">
                    Returns &amp; Refunds
                </a>
            </div>

        </div>
    </div>

</footer>
<!-- ═══════════════════════════════════════════════════════════════════════
     END FOOTER
════════════════════════════════════════════════════════════════════════ -->


<!-- ── JavaScript ────────────────────────────────────────────────────── -->
<script src="<?= $base ?>/assets/js/main.js" defer></script>

</body>
</html>
