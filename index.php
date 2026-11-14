<?php
/**
 * index.php
 *
 * Home / landing page for Jengo Hardware.
 * Sections:
 *   1. Hero banner
 *   2. Trust strip
 *   3. Featured categories
 *   4. Featured products (pulled from DB)
 *   5. How to Order
 *   6. Promo / CTA banner
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/product_images.php';

// ── Fetch featured products (latest 8 active products) ───────────────────
$featuredProducts = [];
try {
    $stmt = db()->prepare(
        'SELECT p.id, p.name, p.slug, p.description, p.price, p.stock_qty,
                p.image_url, c.name AS category_name
         FROM   products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE  p.is_active = 1
         ORDER BY p.created_at DESC
         LIMIT 8'
    );
    $stmt->execute();
    $featuredProducts = $stmt->fetchAll();
} catch (\Exception $e) {
    // Silently degrade — page still renders without products
}

$pageTitle = 'Home';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════
     1. HERO SECTION
══════════════════════════════════════════════════════════════════════ -->
<section class="hero-hardware relative overflow-hidden mb-10"
         aria-label="Hero banner">
    <div class="relative max-w-7xl mx-auto
                flex flex-col lg:flex-row items-center gap-10">

        <!-- Hero copy -->
        <div class="flex-1 text-center lg:text-left">
            <span class="inline-block bg-yellow-400/20 text-yellow-400 text-xs
                         font-bold uppercase tracking-widest px-3 py-1
                         rounded-full mb-4">
                Kenya's Trusted Hardware Store
            </span>

            <h1 class="text-white text-4xl sm:text-5xl lg:text-6xl font-extrabold
                       leading-tight mb-5">
                Build Stronger.<br/>
                <span class="text-yellow-400">Build Smarter.</span>
            </h1>

            <p class="text-gray-300 text-lg max-w-xl mx-auto lg:mx-0 mb-8">
                Quality hand tools, power tools, plumbing, electrical supplies
                and building materials — delivered to your door or ready
                for in-store pickup in Nairobi.
            </p>

            <div class="flex flex-col sm:flex-row gap-3 justify-center lg:justify-start">
                <a href="<?= $base ?>/shop.php"
                   class="btn btn-primary btn-lg">
                    <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor"
                         stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1
                                 12H4L5 9z"/>
                    </svg>
                    Shop Now
                </a>
                <?php if (!is_logged_in()): ?>
                <a href="<?= $base ?>/register.php"
                   class="btn btn-secondary btn-lg border-gray-400 text-gray-100
                          hover:bg-white hover:text-gray-900">
                    Create Free Account
                </a>
                <?php else: ?>
                <a href="<?= $base ?>/<?= get_user_role() === 'customer' ? 'customer/dashboard.php' : role_dashboard_path() ?>"
                   class="btn btn-secondary btn-lg border-gray-400 text-gray-100
                          hover:bg-white hover:text-gray-900">
                    My Dashboard
                </a>
                <?php endif; ?>
            </div>

            <!-- Stats row -->
            <div class="flex flex-wrap justify-center lg:justify-start gap-8 mt-10">
                <?php
                $stats = [
                    ['500+', 'Products'],
                    ['5,000+', 'Happy Customers'],
                    ['Same Day', 'Nairobi Delivery'],
                ];
                foreach ($stats as [$num, $label]): ?>
                <div class="text-center lg:text-left">
                    <p class="text-yellow-400 text-2xl font-extrabold leading-none">
                        <?= $num ?>
                    </p>
                    <p class="text-gray-400 text-xs mt-0.5"><?= $label ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Hero illustration (inline SVG — no image file needed) -->
        <div class="flex-shrink-0 hidden lg:flex items-center justify-center
                    w-72 h-72 xl:w-80 xl:h-80" aria-hidden="true">
            <div class="relative w-full h-full rounded-full
                        bg-white/5 border border-white/10 flex items-center
                        justify-center">
                <!-- Large hammer icon -->
                <svg class="w-44 h-44 text-yellow-400 drop-shadow-2xl"
                     xmlns="http://www.w3.org/2000/svg"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor"
                     stroke-width="1.2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M14.121 14.121L19 19m-7-7 7-7-3-3-7 7
                             M9.172 9.172 4.343 4.343 1.515 7.172
                             5.172 10.828l4-4z"/>
                </svg>
                <!-- Orbit dots -->
                <span class="absolute top-6 right-8 w-3 h-3 rounded-full
                             bg-yellow-400 opacity-70"></span>
                <span class="absolute bottom-10 left-6 w-2 h-2 rounded-full
                             bg-orange-400 opacity-60"></span>
                <span class="absolute top-1/2 right-4 w-1.5 h-1.5 rounded-full
                             bg-white opacity-40"></span>
            </div>
        </div>

    </div>
</section>


<!-- ══════════════════════════════════════════════════════════════════════
     2. TRUST STRIP
══════════════════════════════════════════════════════════════════════ -->
<section class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-12"
         aria-label="Key benefits">
    <?php
    $trustItems = [
        [
            'icon'  => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
            'title' => '100% Genuine',
            'sub'   => 'Certified quality',
        ],
        [
            'icon'  => 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z',
            'title' => 'Secure Payments',
            'sub'   => 'M-Pesa & Paystack',
        ],
        [
            'icon'  => 'M13 10V3L4 14h7v7l9-11h-7z',
            'title' => 'Fast Delivery',
            'sub'   => 'Same day in Nairobi',
        ],
        [
            'icon'  => 'M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z',
            'title' => '24 / 7 Support',
            'sub'   => 'Always here to help',
        ],
    ];
    foreach ($trustItems as $item): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4
                flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-orange-subtle flex-shrink-0
                    flex items-center justify-center" aria-hidden="true">
            <svg class="w-5 h-5 text-orange" xmlns="http://www.w3.org/2000/svg"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor"
                 stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="<?= $item['icon'] ?>"/>
            </svg>
        </div>
        <div>
            <p class="text-sm font-semibold text-gray-800"><?= $item['title'] ?></p>
            <p class="text-xs text-gray-500"><?= $item['sub'] ?></p>
        </div>
    </div>
    <?php endforeach; ?>
</section>


<!-- ══════════════════════════════════════════════════════════════════════
     3. FEATURED CATEGORIES
══════════════════════════════════════════════════════════════════════ -->
<section class="mb-14" aria-labelledby="categories-heading">
    <div class="flex items-center justify-between mb-6">
        <h2 id="categories-heading" class="section-heading text-xl">
            Shop by Category
        </h2>
        <a href="<?= $base ?>/shop.php"
           class="text-sm font-semibold text-orange hover:text-orange-dark
                  transition-colors flex items-center gap-1">
            View all
            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor"
                 stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M9 5l7 7-7 7"/>
            </svg>
        </a>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
        <?php
        $categories = [
            [
                'name'  => 'Hand Tools',
                'slug'  => 'hand-tools',
                'emoji' => '🔨',
                'color' => 'bg-amber-50  border-amber-200  text-amber-700',
            ],
            [
                'name'  => 'Power Tools',
                'slug'  => 'power-tools',
                'emoji' => '⚡',
                'color' => 'bg-blue-50   border-blue-200   text-blue-700',
            ],
            [
                'name'  => 'Cement & Building',
                'slug'  => 'building-materials',
                'emoji' => '🏗️',
                'color' => 'bg-gray-50   border-gray-200   text-gray-700',
            ],
            [
                'name'  => 'Paint & Finishes',
                'slug'  => 'paint',
                'emoji' => '🎨',
                'color' => 'bg-pink-50   border-pink-200   text-pink-700',
            ],
            [
                'name'  => 'Plumbing',
                'slug'  => 'plumbing',
                'emoji' => '🔧',
                'color' => 'bg-cyan-50   border-cyan-200   text-cyan-700',
            ],
            [
                'name'  => 'Electrical',
                'slug'  => 'electrical',
                'emoji' => '💡',
                'color' => 'bg-yellow-50 border-yellow-200 text-yellow-700',
            ],
        ];
        foreach ($categories as $cat): ?>
        <a href="<?= $base ?>/shop.php?category=<?= $cat['slug'] ?>"
           class="group flex flex-col items-center gap-3 p-5 rounded-xl
                  border-2 bg-white transition-all duration-200
                  hover:border-orange hover:shadow-md hover:-translate-y-1
                  focus:outline-none focus:ring-2 focus:ring-orange">

            <span class="text-4xl leading-none" aria-hidden="true">
                <?= $cat['emoji'] ?>
            </span>
            <span class="text-sm font-semibold text-gray-700 text-center
                         group-hover:text-orange transition-colors">
                <?= htmlspecialchars($cat['name']) ?>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
</section>


<!-- ══════════════════════════════════════════════════════════════════════
     4. FEATURED PRODUCTS
══════════════════════════════════════════════════════════════════════ -->
<?php if (!empty($featuredProducts)): ?>
<section class="mb-14" aria-labelledby="products-heading">
    <div class="flex items-center justify-between mb-6">
        <h2 id="products-heading" class="section-heading text-xl">
            Latest Products
        </h2>
        <a href="<?= $base ?>/shop.php"
           class="text-sm font-semibold text-orange hover:text-orange-dark
                  transition-colors flex items-center gap-1">
            Browse all
            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor"
                 stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M9 5l7 7-7 7"/>
            </svg>
        </a>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-5">
        <?php foreach ($featuredProducts as $product): ?>
        <?php $displayImage = product_image_src($product['image_url'] ?? ''); ?>
        <article class="product-card group" aria-label="<?= htmlspecialchars($product['name']) ?>">
            <!-- Image -->
            <a href="<?= $base ?>/shop.php?product=<?= (int)$product['id'] ?>"
               class="product-card__image-wrap block" tabindex="-1" aria-hidden="true">
                <img src="<?= htmlspecialchars($displayImage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                     alt="<?= htmlspecialchars($product['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                     class="product-thumb" loading="lazy" />

                <!-- Stock badge -->
                <?php if ((int)$product['stock_qty'] === 0): ?>
                <span class="product-card__badge product-card__badge--out-of-stock">
                    Out of Stock
                </span>
                <?php elseif ((int)$product['stock_qty'] <= 5): ?>
                <span class="product-card__badge product-card__badge--low-stock">
                    Low Stock
                </span>
                <?php endif; ?>
            </a>

            <!-- Body -->
            <div class="product-card__body">
                <?php if ($product['category_name']): ?>
                <p class="product-card__category">
                    <?= htmlspecialchars($product['category_name']) ?>
                </p>
                <?php endif; ?>

                <h3 class="product-card__name">
                    <a href="<?= $base ?>/shop.php?product=<?= (int)$product['id'] ?>"
                       class="hover:text-orange transition-colors">
                        <?= htmlspecialchars($product['name']) ?>
                    </a>
                </h3>

                <p class="product-card__price">
                    KES <?= number_format((float)$product['price'], 2) ?>
                </p>
            </div>

            <!-- Footer -->
            <div class="card-footer pt-0 px-4 pb-4">
                <?php if (has_any_role(['admin', 'attendant', 'employee', 'owner'])): ?>
                <button type="button" disabled
                        class="btn btn-secondary btn-sm btn-block cursor-not-allowed">
                    <?= is_system_owner() ? 'Owner View' : 'Staff View' ?>
                </button>
                <?php elseif ((int)$product['stock_qty'] > 0): ?>
                <a href="<?= $base ?>/shop.php?product=<?= (int)$product['id'] ?>"
                   class="btn btn-primary btn-sm btn-block">
                    Add to Cart
                </a>
                <?php else: ?>
                <button type="button" disabled
                        class="btn btn-sm btn-block bg-gray-100
                               text-gray-400 cursor-not-allowed border-0">
                    Out of Stock
                </button>
                <?php endif; ?>
                <button type="button"
                        class="btn btn-sm btn-outline-secondary mt-2 w-100"
                        data-bs-toggle="modal"
                        data-bs-target="#productDescModal"
                        data-name="<?= htmlspecialchars((string) ($product['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-category="<?= htmlspecialchars((string) ($product['category_name'] ?? 'Uncategorized'), ENT_QUOTES, 'UTF-8') ?>"
                        data-price="<?= number_format((float) ($product['price'] ?? 0)) ?>"
                        data-stock="<?= (int) ($product['stock_qty'] ?? 0) ?>"
                        data-image="<?= htmlspecialchars((string) ($displayImage ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-description="<?= htmlspecialchars(
                            trim((string) ($product['description'] ?? '')) !== ''
                                ? (string) $product['description']
                                : 'No detailed description available. Call 0789453215 for specifications.',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>">
                    Description
                </button>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════════════════
     5. HOW TO ORDER
══════════════════════════════════════════════════════════════════════ -->
<section class="mb-14" aria-labelledby="how-to-order-heading">

    <h2 id="how-to-order-heading" class="section-heading text-xl mb-8">
        How to Order
    </h2>

    <!-- Step cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <?php
        $steps = [
            [
                'step'  => '01',
                'icon'  => 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z',
                'title' => 'Browse Products',
                'desc'  => 'Explore our full catalogue of hardware and building materials. Filter by category or search by name.',
                'color' => 'text-blue-600 bg-blue-50',
            ],
            [
                'step'  => '02',
                'icon'  => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2 9m12-9l2 9m-9-4h4',
                'title' => 'Add to Cart',
                'desc'  => 'Select your items and quantities. Review your cart before proceeding to checkout.',
                'color' => 'text-blue bg-blue-subtle',
            ],
            [
                'step'  => '03',
                'icon'  => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z',
                'title' => 'Pay with confidence',
                'desc'  => 'Pay instantly with M-Pesa STK Push or Paystack. Your payment is fully encrypted and secure.',
                'color' => 'text-green-600 bg-green-50',
            [
            ],
                'step'  => '04',
                'icon'  => 'M5 13l4 4L19 7',
                'title' => 'Get Your Order',
                'desc'  => 'Pick up in-store at our Nairobi CBD location or enjoy same-day delivery within Nairobi.',
                'color' => 'text-purple-600 bg-purple-50',
            ],
        ];
        foreach ($steps as $i => $step): ?>
        <div class="relative bg-white rounded-2xl shadow-sm border border-gray-100
                    p-6 flex flex-col items-start">

            <!-- Step number watermark -->
            <span class="absolute top-4 right-4 text-5xl font-black
                         text-gray-100 leading-none select-none"
                  aria-hidden="true"><?= $step['step'] ?></span>

            <!-- Icon -->
            <div class="w-12 h-12 rounded-xl <?= $step['color'] ?>
                        flex items-center justify-center mb-4 flex-shrink-0"
                 aria-hidden="true">
                <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor"
                     stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="<?= $step['icon'] ?>"/>
                </svg>
            </div>

            <h3 class="text-base font-bold text-gray-900 mb-2">
                <?= $step['title'] ?>
            </h3>
            <p class="text-sm text-gray-500 leading-relaxed">
                <?= $step['desc'] ?>
            </p>

            <!-- Connector arrow (hidden on last card) -->
            <?php if ($i < count($steps) - 1): ?>
            <div class="hidden lg:block absolute -right-3 top-1/2 -translate-y-1/2
                        w-6 h-6 bg-white border border-gray-200 rounded-full
                        flex items-center justify-center z-10"
                 aria-hidden="true">
                <svg class="w-3 h-3 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor"
                     stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M9 5l7 7-7 7"/>
                </svg>
            </div>
            <?php endif; ?>

        </div>
        <?php endforeach; ?>
    </div>
</section>


<!-- ══════════════════════════════════════════════════════════════════════
     6. PROMO / CTA BANNER
══════════════════════════════════════════════════════════════════════ -->
<section class="rounded-2xl overflow-hidden mb-4"
         style="background: linear-gradient(120deg, #0f3f5a 0%, #3d1a05 50%, #E8500A 100%);"
         aria-label="Call to action">
    <div class="px-8 py-12 sm:py-14 text-center">
        <h2 class="text-white text-3xl sm:text-4xl font-extrabold mb-3">
            Ready to start your project?
        </h2>
        <p class="text-gray-300 text-base sm:text-lg mb-8 max-w-xl mx-auto">
            Browse over 500 quality hardware products. Order before noon for
            same-day delivery within Nairobi.
        </p>
        <div class="flex flex-col sm:flex-row gap-3 justify-center">
            <a href="<?= $base ?>/shop.php"
               class="btn btn-primary btn-lg">
                Shop Now
            </a>
            <?php if (!is_logged_in()): ?>
            <a href="<?= $base ?>/register.php"
               class="btn btn-lg bg-white/10 border border-white/30
                      text-white hover:bg-white/20 transition-colors">
                Create Free Account
            </a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
