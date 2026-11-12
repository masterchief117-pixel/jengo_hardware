<?php
/**
 * shop.php
 *
 * Product listing page with category filter, search, and sort.
 * Handles Add-to-Cart POST requests and returns JSON for AJAX calls
 * or redirects for plain-HTML fallback.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/product_images.php';

$pdo = db();

if (empty($_SESSION['product_review_csrf'])) {
    $_SESSION['product_review_csrf'] = bin2hex(random_bytes(32));
}
$productReviewCsrf = (string) $_SESSION['product_review_csrf'];

function customer_has_verified_product_purchase(PDO $pdo, int $customerId, int $productId): bool
{
    $purchaseStmt = $pdo->prepare(
        "SELECT 1
         FROM orders o
         INNER JOIN order_items oi ON oi.order_id = o.id
         WHERE o.customer_id = ?
           AND oi.product_id = ?
           AND (
               o.status = 'completed'
               OR EXISTS (
                   SELECT 1
                   FROM deliveries d
                   WHERE d.order_reference = o.order_number
                     AND d.delivery_status = 'delivered'
               )
           )
         LIMIT 1"
    );
    $purchaseStmt->execute([$customerId, $productId]);

    return (bool) $purchaseStmt->fetchColumn();
}

// ══════════════════════════════════════════════════════════════════════════
// PRODUCT REVIEW HANDLER (POST)
// ══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'submit_review') {
    $productIdValue = $_POST['product_id'] ?? null;
    $productId = filter_var(
        is_scalar($productIdValue) ? $productIdValue : null,
        FILTER_VALIDATE_INT
    );
    $csrfValue = $_POST['csrf_token'] ?? '';
    $csrfToken = is_string($csrfValue) ? $csrfValue : '';

    if ($productId === false || $productId === null || $productId < 1) {
        redirect('/shop.php', 'Invalid product.', 'error');
    }

    $reviewUrl = '/shop.php?product=' . $productId . '#reviews';
    if (!hash_equals($productReviewCsrf, $csrfToken)) {
        redirect($reviewUrl, 'Your session expired. Please try submitting your review again.', 'error');
    }

    $customerNameValue = $_POST['customer_name'] ?? '';
    $customerName = is_string($customerNameValue)
        ? trim(strip_tags($customerNameValue))
        : '';
    $customerName = preg_replace('/[\x00-\x1F\x7F]/u', '', $customerName) ?? '';
    $ratingValue = $_POST['rating'] ?? null;
    $rating = filter_var(is_scalar($ratingValue) ? $ratingValue : null, FILTER_VALIDATE_INT);
    $commentValue = $_POST['comment'] ?? '';
    $comment = is_string($commentValue) ? trim(strip_tags($commentValue)) : '';

    if ($customerName === '' || mb_strlen($customerName) > 120) {
        redirect($reviewUrl, 'Enter your name using no more than 120 characters.', 'error');
    }
    if ($rating === false || $rating === null || $rating < 1 || $rating > 5) {
        redirect($reviewUrl, 'Choose a rating from 1 to 5 stars.', 'error');
    }
    if ($comment === '' || mb_strlen($comment) > 5000) {
        redirect($reviewUrl, 'Enter a comment using no more than 5000 characters.', 'error');
    }

    $productStmt = $pdo->prepare(
        'SELECT id FROM products WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $productStmt->execute([$productId]);
    if (!$productStmt->fetchColumn()) {
        redirect('/shop.php', 'Product not found.', 'error');
    }

    $customerId = get_user_id();
    if (!has_role('customer') || $customerId === null) {
        redirect($reviewUrl, 'Only verified buyers who have received this item can submit a review.', 'error');
    }

    if (!customer_has_verified_product_purchase($pdo, $customerId, $productId)) {
        redirect($reviewUrl, 'Only verified buyers who have received this item can submit a review.', 'error');
    }

    $photoUrl = null;
    $photoFile = $_FILES['product_photo'] ?? null;
    if (is_array($photoFile)) {
        $uploadError = $photoFile['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!is_int($uploadError)) {
            redirect($reviewUrl, 'The arrival photo upload was invalid.', 'error');
        }

        if ($uploadError !== UPLOAD_ERR_NO_FILE) {
            if ($uploadError !== UPLOAD_ERR_OK) {
                redirect($reviewUrl, 'The arrival photo could not be uploaded. Please try again.', 'error');
            }

            $temporaryPath = $photoFile['tmp_name'] ?? '';
            $fileSize = is_string($temporaryPath) && is_uploaded_file($temporaryPath)
                ? filesize($temporaryPath)
                : false;
            if ($fileSize === false || $fileSize > 5 * 1024 * 1024) {
                redirect($reviewUrl, 'The arrival photo must be 5 MB or smaller.', 'error');
            }

            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $fileInfo->file($temporaryPath);
            $imageInfo = getimagesize($temporaryPath);
            $extensions = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];
            if (!is_string($mimeType)
                || !isset($extensions[$mimeType])
                || $imageInfo === false
                || ($imageInfo['mime'] ?? '') !== $mimeType) {
                redirect($reviewUrl, 'Upload a valid JPEG, PNG, or WebP image.', 'error');
            }

            $uploadDirectory = __DIR__ . '/uploads/reviews';
            if (!is_dir($uploadDirectory)
                && !mkdir($uploadDirectory, 0755, true)
                && !is_dir($uploadDirectory)) {
                redirect($reviewUrl, 'The arrival photo could not be saved. Please try again later.', 'error');
            }

            $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
            if (!move_uploaded_file($temporaryPath, $uploadDirectory . DIRECTORY_SEPARATOR . $filename)) {
                redirect($reviewUrl, 'The arrival photo could not be saved. Please try again.', 'error');
            }
            $photoUrl = 'uploads/reviews/' . $filename;
        }
    } elseif ($photoFile !== null) {
        redirect($reviewUrl, 'The arrival photo upload was invalid.', 'error');
    }

    $reviewStmt = $pdo->prepare(
        'INSERT INTO product_reviews (product_id, customer_name, rating, comment, photo_url)
         VALUES (?, ?, ?, ?, ?)'
    );
    try {
        $reviewStmt->execute([$productId, $customerName, $rating, $comment, $photoUrl]);
    } catch (Throwable $exception) {
        if ($photoUrl !== null) {
            $savedPhoto = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $photoUrl);
            if (is_file($savedPhoto) && !unlink($savedPhoto)) {
                error_log('Unable to remove an orphaned product review photo: ' . $savedPhoto);
            }
        }
        throw $exception;
    }

    redirect($reviewUrl, 'Thank you for sharing your review.', 'success');
}

// ══════════════════════════════════════════════════════════════════════════
// ADD-TO-CART HANDLER (POST)
// Accepts both AJAX (expects JSON response) and plain form POST.
// ══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_to_cart') {

    $productId = (int) ($_POST['product_id'] ?? 0);
    $qty       = max(1, min(99, (int) ($_POST['qty'] ?? 1)));
    $isAjax    = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                 strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    $response  = ['success' => false, 'message' => '', 'cart_count' => 0];

    if (has_any_role(['admin', 'attendant', 'employee', 'owner'])) {
        $response['message'] = is_system_owner()
            ? 'Owner accounts cannot add products to the customer cart.'
            : 'Staff accounts cannot add products to the customer cart.';
    } elseif ($productId < 1) {
        $response['message'] = 'Invalid product.';
    } else {
        // Verify product exists and has stock
        $stmt = $pdo->prepare(
            'SELECT id, name, price, stock_qty FROM products
             WHERE id = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if (!$product) {
            $response['message'] = 'Product not found.';
        } elseif ((int)$product['stock_qty'] < 1) {
            $response['message'] = 'This product is out of stock.';
        } else {
            // Cart stored in session as [product_id => qty]
            if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }

            $currentQty = (int) ($_SESSION['cart'][$productId] ?? 0);
            $newQty     = min($currentQty + $qty, (int)$product['stock_qty']);

            $_SESSION['cart'][$productId] = $newQty;

            $cartCount = array_sum($_SESSION['cart']);
            $response  = [
                'success'    => true,
                'message'    => htmlspecialchars($product['name']) . ' added to cart.',
                'cart_count' => $cartCount,
            ];
        }
    }

    if ($isAjax) {
        if (has_any_role(['admin', 'attendant', 'employee', 'owner'])) {
            http_response_code(403);
        }
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    // Plain-HTML fallback
    if ($response['success']) {
        redirect('/shop.php', $response['message'], 'success');
    } else {
        redirect('/shop.php', $response['message'], 'error');
    }
}

// ══════════════════════════════════════════════════════════════════════════
// PRODUCT DETAILS AND REVIEWS
// ══════════════════════════════════════════════════════════════════════════
if (isset($_GET['product'])) {
    $productValue = $_GET['product'];
    $productId = filter_var(
        is_scalar($productValue) ? $productValue : null,
        FILTER_VALIDATE_INT
    );
    if ($productId === false || $productId === null || $productId < 1) {
        redirect('/shop.php', 'Product not found.', 'error');
    }

    $detailStmt = $pdo->prepare(
        'SELECT p.id, p.name, p.description, p.sku, p.price, p.stock_qty,
                p.image_url, c.name AS category_name, c.slug AS category_slug
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.id = ? AND p.is_active = 1
         LIMIT 1'
    );
    $detailStmt->execute([$productId]);
    $detailProduct = $detailStmt->fetch();
    if (!$detailProduct) {
        redirect('/shop.php', 'Product not found.', 'error');
    }

    $canReview = false;
    $customerId = get_user_id();
    if (has_role('customer') && $customerId !== null) {
        $canReview = customer_has_verified_product_purchase($pdo, $customerId, $productId);
    }

    $ratingStmt = $pdo->prepare(
        'SELECT AVG(rating) AS avg_rating, COUNT(*) AS total_reviews
         FROM product_reviews
         WHERE product_id = ?'
    );
    $ratingStmt->execute([$productId]);
    $ratingSummary = $ratingStmt->fetch();
    $averageRating = $ratingSummary['avg_rating'] !== null
        ? (float) $ratingSummary['avg_rating']
        : 0.0;
    $totalReviews = (int) $ratingSummary['total_reviews'];

    $reviewsStmt = $pdo->prepare(
        'SELECT customer_name, rating, comment, photo_url, created_at
         FROM product_reviews
         WHERE product_id = ?
         ORDER BY created_at DESC, id DESC'
    );
    $reviewsStmt->execute([$productId]);
    $productReviews = $reviewsStmt->fetchAll();

    $pageTitle = $detailProduct['name'];
    $displayImage = product_image_src($detailProduct['image_url'] ?? '');
    require_once __DIR__ . '/includes/header.php';
    ?>

    <nav class="mb-6 text-sm text-gray-500" aria-label="Breadcrumb">
        <a href="<?= $base ?>/shop.php" class="hover:text-orange">Shop</a>
        <span class="mx-2" aria-hidden="true">/</span>
        <span aria-current="page"><?= htmlspecialchars($detailProduct['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
    </nav>

    <section class="grid gap-8 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm md:grid-cols-2 md:p-8"
             aria-labelledby="product-title">
        <div class="overflow-hidden rounded-xl bg-gray-50">
            <img src="<?= htmlspecialchars($displayImage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($detailProduct['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                 class="h-full max-h-[28rem] w-full object-contain"
                 loading="lazy">
        </div>
        <div>
            <?php if (!empty($detailProduct['category_name'])): ?>
                <a href="<?= $base ?>/shop.php?category=<?= urlencode((string) $detailProduct['category_slug']) ?>"
                   class="text-sm font-semibold text-orange hover:underline">
                    <?= htmlspecialchars($detailProduct['category_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </a>
            <?php endif; ?>
            <h1 id="product-title" class="mt-2 text-3xl font-extrabold text-gray-900">
                <?= htmlspecialchars($detailProduct['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </h1>
            <p class="mt-3 text-lg font-bold text-gray-900">
                KES <?= number_format((float) $detailProduct['price'], 2) ?>
            </p>
            <a href="#reviews" class="mt-3 inline-flex items-center gap-2 text-sm text-amber-500 hover:underline">
                <span aria-label="<?= number_format($averageRating, 1) ?> out of 5 stars">
                    <?= str_repeat('★', (int) round($averageRating)) ?><?= str_repeat('☆', 5 - (int) round($averageRating)) ?>
                </span>
                <span class="text-gray-700">
                    <?php if ($totalReviews > 0): ?>
                        <?= number_format($averageRating, 1) ?> / 5 based on <?= $totalReviews ?>
                        review<?= $totalReviews === 1 ? '' : 's' ?>
                    <?php else: ?>
                        No reviews yet
                    <?php endif; ?>
                </span>
            </a>
            <?php if (!empty($detailProduct['sku'])): ?>
                <p class="mt-3 text-sm text-gray-500">
                    SKU: <?= htmlspecialchars($detailProduct['sku'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </p>
            <?php endif; ?>
            <p class="mt-5 whitespace-pre-line leading-7 text-gray-600">
                <?= htmlspecialchars(trim((string) ($detailProduct['description'] ?? '')) ?: 'No product description is available.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </p>
            <p class="mt-4 text-sm <?= (int) $detailProduct['stock_qty'] > 0 ? 'text-green-700' : 'text-red-600' ?>">
                <?= (int) $detailProduct['stock_qty'] > 0 ? 'In stock' : 'Out of stock' ?>
            </p>
            <?php if (has_any_role(['admin', 'attendant', 'employee', 'owner'])): ?>
                <button type="button" disabled class="btn btn-secondary btn-sm mt-4 cursor-not-allowed">
                    <?= is_system_owner() ? 'Owner View' : 'Staff View' ?>
                </button>
            <?php elseif ((int) $detailProduct['stock_qty'] > 0): ?>
                <form method="POST" action="<?= $base ?>/shop.php" class="add-to-cart-form mt-4"
                      data-product-id="<?= (int) $detailProduct['id'] ?>"
                      data-product-name="<?= htmlspecialchars($detailProduct['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="add_to_cart">
                    <input type="hidden" name="product_id" value="<?= (int) $detailProduct['id'] ?>">
                    <input type="hidden" name="qty" value="1">
                    <button type="submit" class="btn btn-primary add-to-cart-btn">Add to Cart</button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <section id="reviews" class="mt-10 grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
        <div class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
            <h2 class="text-xl font-bold text-gray-900">Leave a Review</h2>
            <p class="mt-1 text-sm text-gray-500">Share your experience with this product.</p>
            <?php if ($canReview): ?>
            <form method="POST" enctype="multipart/form-data"
                  action="<?= $base ?>/shop.php?product=<?= (int) $detailProduct['id'] ?>#reviews"
                  class="mt-5 space-y-4">
                <input type="hidden" name="action" value="submit_review">
                <input type="hidden" name="product_id" value="<?= (int) $detailProduct['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($productReviewCsrf, ENT_QUOTES, 'UTF-8') ?>">
                <div>
                    <label for="review-name" class="mb-1 block text-sm font-semibold text-gray-700">Your Name</label>
                    <input id="review-name" name="customer_name" type="text" maxlength="120" required
                           class="form-input w-full" autocomplete="name">
                </div>
                <div>
                    <label for="review-rating" class="mb-1 block text-sm font-semibold text-gray-700">Rating</label>
                    <select id="review-rating" name="rating" required class="form-select w-full">
                        <option value="">Select a rating</option>
                        <option value="5">5 Stars</option>
                        <option value="4">4 Stars</option>
                        <option value="3">3 Stars</option>
                        <option value="2">2 Stars</option>
                        <option value="1">1 Star</option>
                    </select>
                </div>
                <div>
                    <label for="review-comment" class="mb-1 block text-sm font-semibold text-gray-700">Your Comment</label>
                    <textarea id="review-comment" name="comment" rows="5" maxlength="5000" required
                              class="form-input w-full"></textarea>
                </div>
                <div>
                    <label for="product_photo" class="mb-1 block text-sm font-semibold text-gray-700">
                        Upload Arrival Photo (Verify Condition):
                    </label>
                    <input type="file" name="product_photo" id="product_photo"
                           accept="image/jpeg,image/png,image/webp"
                           class="form-input w-full">
                    <small class="mt-1 block text-sm text-gray-500">
                        Upload a photo showing the item&apos;s condition upon delivery. Maximum 5 MB.
                    </small>
                </div>
                <button type="submit" class="btn btn-primary">Submit Review</button>
            </form>
            <?php else: ?>
                <p class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    Only verified buyers who have received this item can submit a review.
                </p>
            <?php endif; ?>
        </div>

        <div>
            <h2 class="text-xl font-bold text-gray-900">Customer Reviews</h2>
            <?php if ($productReviews === []): ?>
                <p class="mt-4 rounded-xl border border-dashed border-gray-300 bg-white p-6 text-gray-500">
                    Be the first to review this product.
                </p>
            <?php else: ?>
                <div class="mt-4 space-y-4">
                    <?php foreach ($productReviews as $review): ?>
                        <article class="rounded-xl border border-gray-100 bg-white p-5 shadow-sm">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="font-semibold text-gray-900">
                                    <?= htmlspecialchars($review['customer_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </h3>
                                <time class="text-sm text-gray-500" datetime="<?= htmlspecialchars($review['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars(date('M j, Y', strtotime($review['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                                </time>
                            </div>
                            <p class="mt-1 text-amber-500" aria-label="<?= (int) $review['rating'] ?> out of 5 stars">
                                <?= str_repeat('★', (int) $review['rating']) ?><?= str_repeat('☆', 5 - (int) $review['rating']) ?>
                            </p>
                            <p class="mt-3 whitespace-pre-line break-words text-gray-700">
                                <?= htmlspecialchars($review['comment'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </p>
                            <?php
                            $reviewPhoto = (string) ($review['photo_url'] ?? '');
                            $isReviewPhoto = preg_match(
                                '#\Auploads/reviews/[a-f0-9]{32}\.(?:jpg|png|webp)\z#',
                                $reviewPhoto
                            ) === 1;
                            ?>
                            <?php if ($isReviewPhoto): ?>
                                <a href="<?= $base ?>/<?= htmlspecialchars($reviewPhoto, ENT_QUOTES, 'UTF-8') ?>"
                                   target="_blank" rel="noopener noreferrer"
                                   class="mt-4 inline-block"
                                   aria-label="View arrival photo from <?= htmlspecialchars($review['customer_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                    <img src="<?= $base ?>/<?= htmlspecialchars($reviewPhoto, ENT_QUOTES, 'UTF-8') ?>"
                                         alt="Arrival condition photo submitted with this review"
                                         class="h-28 w-28 rounded-lg border border-gray-200 object-cover"
                                         loading="lazy">
                                    <span class="mt-1 block text-xs text-gray-500">Open photo</span>
                                </a>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ══════════════════════════════════════════════════════════════════════════
// READ QUERY PARAMS
// ══════════════════════════════════════════════════════════════════════════

// Search
$search   = trim($_GET['q'] ?? '');

// Category slug
$catSlug  = trim($_GET['category'] ?? '');

// Sort
$sortMap  = [
    'latest'     => 'p.created_at DESC',
    'price_asc'  => 'p.price ASC',
    'price_desc' => 'p.price DESC',
    'name_asc'   => 'p.name ASC',
];
$sortKey  = array_key_exists($_GET['sort'] ?? '', $sortMap)
            ? ($_GET['sort'] ?? 'latest')
            : 'latest';
$orderBy  = $sortMap[$sortKey];

// Pagination
$perPage  = 12;
$page     = max(1, (int) ($_GET['page'] ?? 1));
$offset   = ($page - 1) * $perPage;

// ══════════════════════════════════════════════════════════════════════════
// FETCH CATEGORIES (for sidebar filter)
// ══════════════════════════════════════════════════════════════════════════
$categories = $pdo->query(
    'SELECT id, name, slug,
            (SELECT COUNT(*) FROM products p
             WHERE p.category_id = c.id AND p.is_active = 1) AS product_count
     FROM categories c
     ORDER BY name ASC'
)->fetchAll();

// Resolve active category id from slug
$activeCategoryId = null;
$activeCategoryName = '';
foreach ($categories as $cat) {
    if ($cat['slug'] === $catSlug) {
        $activeCategoryId   = (int) $cat['id'];
        $activeCategoryName = $cat['name'];
        break;
    }
}

// ══════════════════════════════════════════════════════════════════════════
// BUILD PRODUCT QUERY
// ══════════════════════════════════════════════════════════════════════════
$conditions = ['p.is_active = 1'];
$bindings   = [];

if ($search !== '') {
    $conditions[] = '(p.name LIKE ? OR p.description LIKE ? OR p.sku LIKE ?)';
    $like = '%' . $search . '%';
    $bindings = array_merge($bindings, [$like, $like, $like]);
}

if ($activeCategoryId !== null) {
    $conditions[] = 'p.category_id = ?';
    $bindings[]   = $activeCategoryId;
}

$where = 'WHERE ' . implode(' AND ', $conditions);

// Total count for pagination
$countSql = "SELECT COUNT(*) FROM products p $where";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($bindings);
$totalProducts = (int) $countStmt->fetchColumn();
$totalPages    = (int) ceil($totalProducts / $perPage);
$page          = min($page, max(1, $totalPages)); // clamp

// Products for current page
$productSql = "
    SELECT p.id, p.name, p.slug, p.description, p.price, p.stock_qty,
           p.image_url, p.low_stock_threshold, p.sku,
           c.name AS category_name, c.slug AS category_slug
    FROM   products p
    LEFT JOIN categories c ON c.id = p.category_id
    $where
    ORDER BY $orderBy
    LIMIT ? OFFSET ?
";
$productStmt = $pdo->prepare($productSql);
$productStmt->execute(array_merge($bindings, [$perPage, $offset]));
$products = $productStmt->fetchAll();

// ── Page meta ─────────────────────────────────────────────────────────────
$pageTitle = $activeCategoryName !== '' ? $activeCategoryName
           : ($search !== '' ? 'Search: ' . $search : 'Shop');

require_once __DIR__ . '/includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════
     PAGE HERO / BREADCRUMB
══════════════════════════════════════════════════════════════════════ -->
<div class="page-hero hero-hardware mb-8 -mx-0">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="<?= $base ?>/index.php">Home</a>
        <span class="breadcrumb-sep" aria-hidden="true">/</span>
        <?php if ($activeCategoryName !== ''): ?>
            <a href="<?= $base ?>/shop.php">Shop</a>
            <span class="breadcrumb-sep" aria-hidden="true">/</span>
            <span aria-current="page"><?= htmlspecialchars($activeCategoryName) ?></span>
        <?php elseif ($search !== ''): ?>
            <a href="<?= $base ?>/shop.php">Shop</a>
            <span class="breadcrumb-sep" aria-hidden="true">/</span>
            <span aria-current="page">Search results</span>
        <?php else: ?>
            <span aria-current="page">Shop</span>
        <?php endif; ?>
    </nav>

    <h1 class="text-2xl sm:text-3xl font-extrabold text-white">
        <?php if ($search !== ''): ?>
            Search results for &ldquo;<?= htmlspecialchars($search) ?>&rdquo;
        <?php elseif ($activeCategoryName !== ''): ?>
            <?= htmlspecialchars($activeCategoryName) ?>
        <?php else: ?>
            All Products
        <?php endif; ?>
    </h1>

    <?php if ($totalProducts > 0): ?>
    <p class="text-gray-400 text-sm mt-1">
        <?= number_format($totalProducts) ?> product<?= $totalProducts !== 1 ? 's' : '' ?> found
    </p>
    <?php endif; ?>
</div>


<!-- ══════════════════════════════════════════════════════════════════════
     SEARCH BAR
══════════════════════════════════════════════════════════════════════ -->
<form method="GET" action="shop.php" class="mb-6" role="search">
    <?php if ($catSlug !== ''): ?>
        <input type="hidden" name="category" value="<?= htmlspecialchars($catSlug) ?>">
    <?php endif; ?>
    <div class="relative max-w-xl">
        <label for="search-input" class="sr-only">Search products</label>
        <div class="absolute inset-y-0 left-0 flex items-center pl-3.5
                    pointer-events-none" aria-hidden="true">
            <svg class="w-5 h-5 text-gray-400" xmlns="http://www.w3.org/2000/svg"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </div>
        <input type="search"
               id="search-input"
               name="q"
               value="<?= htmlspecialchars($search) ?>"
               placeholder="Search products, tools, materials…"
               class="form-input pl-11 pr-28"
               autocomplete="off" />
        <button type="submit"
                class="absolute inset-y-0 right-0 px-4 text-sm font-semibold
                       bg-orange text-white rounded-r-md hover:bg-orange-dark
                       transition-colors focus:outline-none focus:ring-2
                       focus:ring-orange focus:ring-offset-1">
            Search
        </button>
    </div>
</form>


<!-- ══════════════════════════════════════════════════════════════════════
     MAIN LAYOUT: SIDEBAR + PRODUCT GRID
══════════════════════════════════════════════════════════════════════ -->
<div class="flex flex-col lg:flex-row gap-8">

    <!-- ── SIDEBAR ────────────────────────────────────────────────────── -->
    <aside class="lg:w-56 xl:w-64 flex-shrink-0" aria-label="Product filters">

        <!-- Category filter -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-4">
            <h2 class="text-sm font-bold text-gray-800 uppercase tracking-wider mb-4">
                Categories
            </h2>
            <ul class="space-y-1" role="list">
                <li>
                    <a href="<?= $base ?>/shop.php<?= $search !== '' ? '?q=' . urlencode($search) : '' ?>"
                       class="flex items-center justify-between px-3 py-2 rounded-lg
                              text-sm transition-colors
                              <?= $catSlug === '' ? 'bg-orange-subtle text-orange font-semibold' : 'text-gray-600 hover:bg-gray-50' ?>">
                        <span>All Products</span>
                        <span class="text-xs text-gray-400 font-normal">
                            <?= number_format($totalProducts) ?>
                        </span>
                    </a>
                </li>
                <?php foreach ($categories as $cat): ?>
                <li>
                    <a href="<?= $base ?>/shop.php?category=<?= urlencode($cat['slug']) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"
                       class="flex items-center justify-between px-3 py-2 rounded-lg
                              text-sm transition-colors
                              <?= $cat['slug'] === $catSlug ? 'bg-orange-subtle text-orange font-semibold' : 'text-gray-600 hover:bg-gray-50' ?>">
                        <span><?= htmlspecialchars($cat['name']) ?></span>
                        <span class="text-xs text-gray-400 font-normal">
                            <?= (int)$cat['product_count'] ?>
                        </span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Clear filters -->
        <?php if ($search !== '' || $catSlug !== ''): ?>
        <a href="<?= $base ?>/shop.php"
           class="flex items-center gap-2 text-sm text-red-500
                  hover:text-red-700 font-medium px-1 transition-colors">
            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor"
                 stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M6 18L18 6M6 6l12 12"/>
            </svg>
            Clear filters
        </a>
        <?php endif; ?>
    </aside>


    <!-- ── PRODUCT GRID ───────────────────────────────────────────────── -->
    <div class="flex-1 min-w-0">

        <!-- Toolbar: result count + sort -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between
                    gap-3 mb-5">
            <p class="text-sm text-gray-500">
                <?php if ($totalProducts === 0): ?>
                    No products found.
                <?php else: ?>
                    Showing
                    <strong class="text-gray-700">
                        <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalProducts)) ?>
                    </strong>
                    of <strong class="text-gray-700"><?= number_format($totalProducts) ?></strong>
                    product<?= $totalProducts !== 1 ? 's' : '' ?>
                <?php endif; ?>
            </p>

            <!-- Sort -->
            <form method="GET" action="shop.php" id="sort-form">
                <?php if ($catSlug !== ''): ?>
                    <input type="hidden" name="category" value="<?= htmlspecialchars($catSlug) ?>">
                <?php endif; ?>
                <?php if ($search !== ''): ?>
                    <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
                <?php endif; ?>
                <label for="sort-select" class="sr-only">Sort products</label>
                <select id="sort-select"
                        name="sort"
                        class="form-select text-sm py-2 w-44"
                        onchange="document.getElementById('sort-form').submit()">
                    <option value="latest"     <?= $sortKey === 'latest'     ? 'selected' : '' ?>>Latest</option>
                    <option value="price_asc"  <?= $sortKey === 'price_asc'  ? 'selected' : '' ?>>Price: Low to High</option>
                    <option value="price_desc" <?= $sortKey === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
                    <option value="name_asc"   <?= $sortKey === 'name_asc'   ? 'selected' : '' ?>>Name A–Z</option>
                </select>
            </form>
        </div>

        <!-- Empty state -->
        <?php if (empty($products)): ?>
        <div class="bg-white rounded-2xl border border-dashed border-gray-200
                    py-20 text-center" role="status">
            <div class="text-6xl mb-4" aria-hidden="true">🔍</div>
            <h3 class="text-lg font-semibold text-gray-700 mb-2">
                No products found
            </h3>
            <p class="text-gray-400 text-sm mb-6">
                <?= $search !== '' ? 'Try a different search term or' : 'Try' ?>
                browsing all categories.
            </p>
            <a href="<?= $base ?>/shop.php" class="btn btn-primary">
                View All Products
            </a>
        </div>

        <?php else: ?>

        <!-- Product grid -->
        <div class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-5"
             id="product-grid">
            <?php foreach ($products as $product):
                $stockQty  = (int) $product['stock_qty'];
                $threshold = (int) $product['low_stock_threshold'];
                $inStock   = $stockQty > 0;
                $lowStock  = $inStock && $stockQty <= $threshold;
                $displayImage = product_image_src($product['image_url'] ?? '');
            ?>
            <article class="product-card group"
                     aria-label="<?= htmlspecialchars($product['name']) ?>">

                <!-- Image -->
                <div class="product-card__image-wrap">
                    <img src="<?= htmlspecialchars($displayImage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($product['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                         class="product-thumb" loading="lazy" />

                    <!-- Stock badge -->
                    <?php if (!$inStock): ?>
                        <span class="product-card__badge product-card__badge--out-of-stock">
                            Out of Stock
                        </span>
                    <?php elseif ($lowStock): ?>
                        <span class="product-card__badge product-card__badge--low-stock">
                            Only <?= $stockQty ?> left
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Body -->
                <div class="product-card__body">
                    <?php if ($product['category_name']): ?>
                    <p class="product-card__category">
                        <a href="<?= $base ?>/shop.php?category=<?= urlencode($product['category_slug'] ?? '') ?>"
                           class="hover:text-orange transition-colors">
                            <?= htmlspecialchars($product['category_name']) ?>
                        </a>
                    </p>
                    <?php endif; ?>

                    <h3 class="product-card__name">
                        <a href="<?= $base ?>/shop.php?product=<?= $product['id'] ?>"
                           class="hover:text-orange transition-colors">
                            <?= htmlspecialchars($product['name']) ?>
                        </a>
                    </h3>

                    <p class="product-card__spec">
                        <?php if ($product['sku']): ?>
                            SKU <?= htmlspecialchars($product['sku']) ?> ·
                        <?php endif; ?>
                        Specification ready for site install
                    </p>

                    <p class="product-card__price">
                        KES <?= number_format((float)$product['price'], 2) ?>
                    </p>
                </div>

                <!-- Footer: Add to Cart -->
                <div class="px-4 pb-4">
                    <?php if (has_any_role(['admin', 'attendant', 'employee', 'owner'])): ?>
                    <button type="button" disabled
                            class="btn btn-secondary btn-sm btn-block cursor-not-allowed"
                            aria-disabled="true"><?= is_system_owner() ? 'Owner View' : 'Staff View' ?></button>
                    <?php elseif ($inStock): ?>
                    <form method="POST" action="shop.php"
                          class="add-to-cart-form"
                          data-product-id="<?= $product['id'] ?>"
                          data-product-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>">
                        <input type="hidden" name="action"     value="add_to_cart">
                        <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                        <input type="hidden" name="qty"        value="1">
                        <button type="submit"
                                class="btn btn-primary btn-sm btn-block add-to-cart-btn">
                            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                 stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M3 3h2l.4 2M7 13h10l4-8H5.4M7
                                         13L5.4 5M7 13l-2 9m12-9l2 9m-9-4h4"/>
                            </svg>
                            Add to Cart
                        </button>
                    </form>
                    <?php else: ?>
                    <button type="button" disabled
                            class="btn btn-sm btn-block bg-gray-100 text-gray-400
                                   border-0 cursor-not-allowed"
                            aria-disabled="true">
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
        </div><!-- /#product-grid -->


        <!-- ── PAGINATION ─────────────────────────────────────────────── -->
        <?php if ($totalPages > 1): ?>
        <nav class="flex items-center justify-center gap-1 mt-10"
             aria-label="Product pages">
            <?php
            // Build base URL for pagination links
            $paginationParams = [];
            if ($search  !== '') $paginationParams['q']        = $search;
            if ($catSlug !== '') $paginationParams['category']  = $catSlug;
            if ($sortKey !== 'latest') $paginationParams['sort'] = $sortKey;

            function paginationUrl(int $p, array $params, string $base): string
            {
                $params['page'] = $p;
                return $base . '/shop.php?' . http_build_query($params);
            }

            $prevPage = $page - 1;
            $nextPage = $page + 1;
            ?>

            <!-- Previous -->
            <?php if ($page > 1): ?>
            <a href="<?= paginationUrl($prevPage, $paginationParams, $base) ?>"
               class="px-3 py-2 text-sm rounded-lg border border-gray-200
                      text-gray-600 hover:border-orange hover:text-orange
                      transition-colors"
               aria-label="Previous page">
                &lsaquo;
            </a>
            <?php endif; ?>

            <!-- Page numbers -->
            <?php
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);
            if ($start > 1): ?>
                <a href="<?= paginationUrl(1, $paginationParams, $base) ?>"
                   class="px-3 py-2 text-sm rounded-lg border border-gray-200
                          text-gray-600 hover:border-orange hover:text-orange
                          transition-colors">1</a>
                <?php if ($start > 2): ?>
                    <span class="px-2 py-2 text-gray-400 text-sm">&hellip;</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($p = $start; $p <= $end; $p++): ?>
            <a href="<?= paginationUrl($p, $paginationParams, $base) ?>"
               class="px-3 py-2 text-sm rounded-lg border transition-colors
                      <?= $p === $page
                          ? 'bg-orange border-orange text-white font-semibold'
                          : 'border-gray-200 text-gray-600 hover:border-orange hover:text-orange' ?>"
               <?= $p === $page ? 'aria-current="page"' : '' ?>>
                <?= $p ?>
            </a>
            <?php endfor; ?>

            <?php if ($end < $totalPages): ?>
                <?php if ($end < $totalPages - 1): ?>
                    <span class="px-2 py-2 text-gray-400 text-sm">&hellip;</span>
                <?php endif; ?>
                <a href="<?= paginationUrl($totalPages, $paginationParams, $base) ?>"
                   class="px-3 py-2 text-sm rounded-lg border border-gray-200
                          text-gray-600 hover:border-orange hover:text-orange
                          transition-colors"><?= $totalPages ?></a>
            <?php endif; ?>

            <!-- Next -->
            <?php if ($page < $totalPages): ?>
            <a href="<?= paginationUrl($nextPage, $paginationParams, $base) ?>"
               class="px-3 py-2 text-sm rounded-lg border border-gray-200
                      text-gray-600 hover:border-orange hover:text-orange
                      transition-colors"
               aria-label="Next page">
                &rsaquo;
            </a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>

        <?php endif; // end products not empty ?>

    </div><!-- /product grid column -->
</div><!-- /sidebar + grid flex row -->


<!-- Page-specific JS: AJAX add-to-cart ───────────────────────────────── -->
<script>
(function () {
    async function addToCart(productId, quantity) {
        var response = await fetch('add_to_cart.php', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                product_id: Number(productId),
                quantity: Number(quantity)
            })
        });
        var json = await response.json().catch(function () {
            return null;
        });

        if (!json || response.ok === false || json.success !== true) {
            throw new Error(json && json.message ? json.message : 'Unable to add this product to your cart.');
        }

        return json;
    }

    // Intercept all Add-to-Cart forms with AJAX
    document.querySelectorAll('.add-to-cart-form').forEach(function (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            var btn          = form.querySelector('.add-to-cart-btn');
            var originalHtml = btn ? btn.innerHTML : '';

            if (btn) {
                btn.disabled  = true;
                btn.innerHTML = '<span class="spinner" aria-hidden="true"></span>';
            }

            try {
                var json = await addToCart(
                    form.elements.namedItem('product_id').value,
                    form.elements.namedItem('qty').value
                );
                alert(json.message);

                if (window.Jengo && window.Jengo.CartBadge) {
                    window.Jengo.CartBadge.update(json.cart_count);
                }

                document.querySelectorAll('[data-cart-badge]').forEach(function (el) {
                    el.textContent = json.cart_count > 9 ? '9+' : String(json.cart_count);
                    el.classList.toggle('hidden', json.cart_count < 1);
                });
                document.querySelectorAll('[data-cart-link]').forEach(function (el) {
                    el.setAttribute(
                        'aria-label',
                        json.cart_count > 0
                            ? 'Shopping cart, ' + json.cart_count + ' items'
                            : 'Shopping cart'
                    );
                });
            } catch (error) {
                alert(error.message || 'Something went wrong. Please try again.');
            } finally {
                if (btn) {
                    btn.disabled  = false;
                    btn.innerHTML = originalHtml;
                }
            }
        });
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
