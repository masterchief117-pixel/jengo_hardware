<?php
/**
 * Public Jengo Hardware album and photo gallery.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/gallery_images.php';

$pdo = db();
$base = rtrim((string) env('APP_URL', ''), '/');
$escape = static fn (string $value): string =>
    htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$folderInput = $_GET['folder'] ?? '0';
$folderId = is_string($folderInput) ? filter_var($folderInput, FILTER_VALIDATE_INT) : false;
$folderId = $folderId === false || $folderId === null || $folderId < 0 ? 0 : $folderId;
$folder = null;
$photos = [];
$folders = [];

if ($folderId > 0) {
    $folderStatement = $pdo->prepare(
        'SELECT id, folder_name FROM gallery_folders WHERE id = ? LIMIT 1'
    );
    $folderStatement->execute([$folderId]);
    $folder = $folderStatement->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($folder === null) {
        http_response_code(404);
    } else {
        $photoStatement = $pdo->prepare(
            'SELECT id, title, image_url, uploaded_at
             FROM gallery_photos
             WHERE folder_id = ?
             ORDER BY uploaded_at DESC, id DESC'
        );
        $photoStatement->execute([$folderId]);
        $photos = $photoStatement->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    $folders = $pdo->query(
        'SELECT f.id, f.folder_name, f.cover_image, f.created_at, COUNT(p.id) AS photo_count
         FROM gallery_folders AS f
         LEFT JOIN gallery_photos AS p ON p.folder_id = f.id
         GROUP BY f.id, f.folder_name, f.cover_image, f.created_at
         ORDER BY f.created_at DESC, f.id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = $folder !== null
    ? (string) $folder['folder_name']
    : ($folderId > 0 ? 'Album Not Found' : 'Our Albums');
require_once __DIR__ . '/includes/header.php';
?>

<header class="mb-8">
    <p class="mb-2 text-sm font-semibold uppercase tracking-wider text-orange-700">
        Inside Jengo Hardware
    </p>
    <?php if ($folderId > 0 && $folder === null): ?>
        <h1 class="text-3xl font-extrabold text-gray-900">Album Not Found</h1>
    <?php elseif ($folder !== null): ?>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-3xl font-extrabold text-gray-900">
                    <?= $escape((string) $folder['folder_name']) ?>
                </h1>
                <p class="mt-2 text-gray-600">Photos in this album.</p>
            </div>
            <a href="<?= $escape($base) ?>/gallery.php" class="btn btn-secondary">
                &larr; Back to Albums
            </a>
        </div>
    <?php else: ?>
        <h1 class="text-3xl font-extrabold text-gray-900">Our Albums</h1>
        <p class="mt-2 max-w-2xl text-gray-600">
            Explore photos of our store, products and the materials that help build Kenya.
        </p>
    <?php endif; ?>
</header>

<?php if ($folderId > 0 && $folder === null): ?>
    <section class="card p-8 text-center" aria-label="Album not found">
        <p class="text-gray-600">This album may have been removed.</p>
        <a href="<?= $escape($base) ?>/gallery.php" class="btn btn-primary mt-4">Browse Albums</a>
    </section>
<?php elseif ($folder === null): ?>
    <?php if ($folders === []): ?>
        <section class="card p-8 text-center" aria-label="Gallery is empty">
            <h2 class="text-xl font-bold text-gray-900">Albums coming soon</h2>
            <p class="mt-2 text-gray-600">There are no photo albums yet. Please check back soon.</p>
        </section>
    <?php else: ?>
        <section class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4"
                 aria-label="Jengo Hardware photo albums">
            <?php foreach ($folders as $album): ?>
                <?php $coverUrl = gallery_image_src($album['cover_image'], $base); ?>
                <?php if ($coverUrl !== ''): ?>
                    <a href="<?= $escape($base) ?>/gallery.php?folder=<?= (int) $album['id'] ?>"
                       class="card overflow-hidden transition hover:-translate-y-0.5 hover:shadow-md">
                        <img src="<?= $escape($coverUrl) ?>"
                             class="h-44 w-full object-cover sm:h-56"
                             loading="lazy"
                             alt="<?= $escape((string) $album['folder_name']) ?> album cover">
                        <div class="p-3 text-center">
                            <h2 class="font-bold text-gray-900">
                                <?= $escape((string) $album['folder_name']) ?>
                            </h2>
                            <p class="mt-1 text-xs text-gray-500">
                                <?= (int) $album['photo_count'] ?> photo<?= (int) $album['photo_count'] === 1 ? '' : 's' ?>
                                &middot; View photos &rarr;
                            </p>
                        </div>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
<?php elseif ($photos === []): ?>
    <section class="card p-8 text-center" aria-label="Album is empty">
        <p class="text-gray-600">No photos in this album yet.</p>
    </section>
<?php else: ?>
    <section class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4"
             aria-label="<?= $escape((string) $folder['folder_name']) ?> album photos">
        <?php foreach ($photos as $photo): ?>
            <?php $photoUrl = gallery_image_src($photo['image_url'], $base); ?>
            <?php if ($photoUrl !== ''): ?>
                <figure class="card overflow-hidden">
                    <img src="<?= $escape($photoUrl) ?>"
                         class="h-44 w-full object-cover sm:h-56"
                         loading="lazy"
                         alt="<?= $escape((string) ($photo['title'] ?: $folder['folder_name'])) ?>">
                    <?php if (!empty($photo['title'])): ?>
                        <figcaption class="p-3 text-center text-sm font-semibold text-gray-700">
                            <?= $escape((string) $photo['title']) ?>
                        </figcaption>
                    <?php endif; ?>
                </figure>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
