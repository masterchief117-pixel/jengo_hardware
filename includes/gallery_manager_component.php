<?php
/**
 * Shared staff album and gallery-photo manager.
 *
 * Expects $pdo, $base, and $galleryManagerAction from its including page.
 */

declare(strict_types=1);

require_once __DIR__ . '/gallery_images.php';

$galleryEscape = static fn (string $value): string =>
    htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$galleryDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads'
    . DIRECTORY_SEPARATOR . 'gallery';
$galleryImageUrl = static fn (mixed $value): string =>
    gallery_image_src($value, $base);
$galleryLocalFile = static function (mixed $value) use ($galleryDirectory): ?string {
    $imagePath = (string) ($value ?? '');
    if (preg_match('~^uploads/gallery/([a-f0-9]{32}\.(?:jpg|png|gif|webp))$~', $imagePath, $matches) !== 1) {
        return null;
    }

    $realDirectory = realpath($galleryDirectory);
    if ($realDirectory === false) {
        return null;
    }
    $realFile = realpath($realDirectory . DIRECTORY_SEPARATOR . $matches[1]);
    return $realFile !== false && dirname($realFile) === $realDirectory && is_file($realFile)
        ? $realFile
        : null;
};
$galleryDeleteLocalFiles = static function (array $paths) use ($galleryLocalFile): bool {
    $success = true;
    foreach (array_unique(array_map('strval', $paths)) as $path) {
        $localFile = $galleryLocalFile($path);
        if ($localFile !== null && !unlink($localFile)) {
            error_log('[Gallery Manager] Could not delete local photo: ' . $localFile);
            $success = false;
        }
    }
    return $success;
};
$galleryReceiveImage = static function (string $fileKey, string $urlKey) use ($galleryDirectory): array {
    $urlInput = $_POST[$urlKey] ?? '';
    $imageUrl = is_string($urlInput) ? trim($urlInput) : '';
    $urlParts = $imageUrl !== '' ? parse_url($imageUrl) : false;
    if (
        $imageUrl !== '' &&
        (
            strlen($imageUrl) > 255 ||
            filter_var($imageUrl, FILTER_VALIDATE_URL) === false ||
            !is_array($urlParts) ||
            !in_array(strtolower((string) ($urlParts['scheme'] ?? '')), ['http', 'https'], true) ||
            empty($urlParts['host']) ||
            isset($urlParts['user']) ||
            isset($urlParts['pass'])
        )
    ) {
        throw new InvalidArgumentException('Enter a valid HTTP or HTTPS image URL (up to 255 characters).');
    }

    $file = $_FILES[$fileKey] ?? null;
    $uploadError = is_array($file) && isset($file['error']) && is_int($file['error'])
        ? $file['error']
        : UPLOAD_ERR_NO_FILE;
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        if ($imageUrl === '') {
            throw new InvalidArgumentException('Choose an image file or enter an HTTP/HTTPS image URL.');
        }
        return ['path' => $imageUrl, 'uploaded_file' => null];
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(match ($uploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The image exceeds the upload size limit.',
            UPLOAD_ERR_PARTIAL => 'The image upload was incomplete. Please try again.',
            default => 'The image could not be uploaded. Please try again.',
        });
    }
    if (
        !is_array($file) ||
        !isset($file['tmp_name'], $file['size']) ||
        !is_string($file['tmp_name']) ||
        !is_int($file['size']) ||
        $file['size'] < 1 ||
        $file['size'] > 5 * 1024 * 1024 ||
        !is_uploaded_file($file['tmp_name'])
    ) {
        throw new InvalidArgumentException('Choose a valid image file no larger than 5 MB.');
    }

    $imageInfo = @getimagesize($file['tmp_name']);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    if (
        $imageInfo === false ||
        !is_string($mimeType) ||
        !isset($allowedTypes[$mimeType]) ||
        ($imageInfo['mime'] ?? '') !== $mimeType
    ) {
        throw new InvalidArgumentException('Upload a valid JPEG, PNG, GIF or WebP image.');
    }

    if (!is_dir($galleryDirectory) && !mkdir($galleryDirectory, 0755, true) && !is_dir($galleryDirectory)) {
        throw new RuntimeException('Unable to create the gallery upload directory.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
    $uploadedFile = $galleryDirectory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $uploadedFile)) {
        throw new RuntimeException('The image could not be saved. Check upload directory permissions.');
    }

    return [
        'path' => 'uploads/gallery/' . $filename,
        'uploaded_file' => $uploadedFile,
    ];
};

if (empty($_SESSION['gallery_manager_csrf'])) {
    $_SESSION['gallery_manager_csrf'] = bin2hex(random_bytes(32));
}
$galleryCsrfToken = (string) $_SESSION['gallery_manager_csrf'];
$galleryErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $galleryAction = $_POST['action'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($galleryCsrfToken, $submittedToken)) {
        $galleryErrors[] = 'Your form expired. Reload the page and try again.';
    } elseif (in_array($galleryAction, ['create_folder', 'add_photo'], true)) {
        $uploadedFiles = [];
        try {
            if ($galleryAction === 'create_folder') {
                $folderNameInput = $_POST['folder_name'] ?? '';
                $folderName = is_string($folderNameInput) ? trim($folderNameInput) : '';
                if ($folderName === '' || mb_strlen($folderName) > 100) {
                    throw new InvalidArgumentException('Enter an album name of 1 to 100 characters.');
                }

                $cover = $galleryReceiveImage('cover_file', 'cover_image_url');
                if ($cover['uploaded_file'] !== null) {
                    $uploadedFiles[] = $cover['uploaded_file'];
                }
                $insert = $pdo->prepare(
                    'INSERT INTO gallery_folders (folder_name, cover_image) VALUES (?, ?)'
                );
                $insert->execute([$folderName, $cover['path']]);
                $_SESSION['flash_message'] = 'Album created.';
            } else {
                $folderId = filter_var($_POST['folder_id'] ?? null, FILTER_VALIDATE_INT);
                $titleInput = $_POST['title'] ?? '';
                $title = is_string($titleInput) ? trim($titleInput) : '';
                if ($folderId === false || $folderId === null || $folderId < 1) {
                    throw new InvalidArgumentException('Select a valid album.');
                }
                if (mb_strlen($title) > 255) {
                    throw new InvalidArgumentException('The photo caption must be 255 characters or fewer.');
                }

                $folderCheck = $pdo->prepare('SELECT id FROM gallery_folders WHERE id = ? LIMIT 1');
                $folderCheck->execute([$folderId]);
                if ($folderCheck->fetchColumn() === false) {
                    throw new InvalidArgumentException('That album no longer exists.');
                }
                $photo = $galleryReceiveImage('photo_file', 'image_url');
                if ($photo['uploaded_file'] !== null) {
                    $uploadedFiles[] = $photo['uploaded_file'];
                }
                $insert = $pdo->prepare(
                    'INSERT INTO gallery_photos (folder_id, title, image_url) VALUES (?, ?, ?)'
                );
                $insert->execute([
                    $folderId,
                    $title !== '' ? $title : null,
                    $photo['path'],
                ]);
                $_SESSION['flash_message'] = 'Photo added to the album.';
            }

            $_SESSION['flash_type'] = 'success';
            header('Location: ' . $galleryManagerAction);
            exit;
        } catch (InvalidArgumentException $exception) {
            $galleryDeleteLocalFiles(array_map(
                static fn (string $file): string => 'uploads/gallery/' . basename($file),
                $uploadedFiles
            ));
            $galleryErrors[] = $exception->getMessage();
        } catch (PDOException $exception) {
            $galleryDeleteLocalFiles(array_map(
                static fn (string $file): string => 'uploads/gallery/' . basename($file),
                $uploadedFiles
            ));
            error_log('[Gallery Manager] Could not save album/photo: ' . $exception->getMessage());
            $galleryErrors[] = 'The album or photo could not be saved. Please try again.';
        } catch (RuntimeException $exception) {
            $galleryErrors[] = $exception->getMessage();
        }
    } elseif (in_array($galleryAction, ['delete_folder', 'delete_photo'], true)) {
        $recordId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT);
        if ($recordId === false || $recordId === null || $recordId < 1) {
            $galleryErrors[] = 'Choose a valid album or photo.';
        } else {
            try {
                $pdo->beginTransaction();
                if ($galleryAction === 'delete_folder') {
                    $folderStatement = $pdo->prepare(
                        'SELECT cover_image FROM gallery_folders WHERE id = ? LIMIT 1 FOR UPDATE'
                    );
                    $folderStatement->execute([$recordId]);
                    $folder = $folderStatement->fetch(PDO::FETCH_ASSOC);
                    if ($folder === false) {
                        $pdo->rollBack();
                        $galleryErrors[] = 'That album no longer exists.';
                    } else {
                        $photoStatement = $pdo->prepare(
                            'SELECT image_url FROM gallery_photos WHERE folder_id = ?'
                        );
                        $photoStatement->execute([$recordId]);
                        $imagePaths = array_column($photoStatement->fetchAll(PDO::FETCH_ASSOC), 'image_url');
                        $imagePaths[] = $folder['cover_image'];
                        $delete = $pdo->prepare('DELETE FROM gallery_folders WHERE id = ?');
                        $delete->execute([$recordId]);
                        $pdo->commit();

                        if (!$galleryDeleteLocalFiles($imagePaths)) {
                            $_SESSION['flash_message'] = 'Album deleted, but a local image file could not be removed.';
                            $_SESSION['flash_type'] = 'error';
                        } else {
                            $_SESSION['flash_message'] = 'Album and its photos deleted.';
                            $_SESSION['flash_type'] = 'success';
                        }
                    }
                } else {
                    $photoStatement = $pdo->prepare(
                        'SELECT image_url FROM gallery_photos WHERE id = ? LIMIT 1 FOR UPDATE'
                    );
                    $photoStatement->execute([$recordId]);
                    $photo = $photoStatement->fetch(PDO::FETCH_ASSOC);
                    if ($photo === false) {
                        $pdo->rollBack();
                        $galleryErrors[] = 'That photo no longer exists.';
                    } else {
                        $delete = $pdo->prepare('DELETE FROM gallery_photos WHERE id = ?');
                        $delete->execute([$recordId]);
                        $pdo->commit();

                        if (!$galleryDeleteLocalFiles([(string) $photo['image_url']])) {
                            $_SESSION['flash_message'] = 'Photo removed from the album, but its local file could not be deleted.';
                            $_SESSION['flash_type'] = 'error';
                        } else {
                            $_SESSION['flash_message'] = 'Photo deleted.';
                            $_SESSION['flash_type'] = 'success';
                        }
                    }
                }

                if ($galleryErrors === []) {
                    header('Location: ' . $galleryManagerAction);
                    exit;
                }
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[Gallery Manager] Delete failed: ' . $exception->getMessage());
                $galleryErrors[] = 'The album or photo could not be deleted. Please try again.';
            }
        }
    } else {
        $galleryErrors[] = 'Invalid gallery action.';
    }
}

$galleryFolders = $pdo->query(
    'SELECT f.id, f.folder_name, f.cover_image, f.created_at, COUNT(p.id) AS photo_count
     FROM gallery_folders AS f
     LEFT JOIN gallery_photos AS p ON p.folder_id = f.id
     GROUP BY f.id, f.folder_name, f.cover_image, f.created_at
     ORDER BY f.created_at DESC, f.id DESC'
)->fetchAll(PDO::FETCH_ASSOC);
$galleryPhotos = $pdo->query(
    'SELECT p.id, p.folder_id, p.title, p.image_url, p.uploaded_at, f.folder_name
     FROM gallery_photos AS p
     INNER JOIN gallery_folders AS f ON f.id = p.folder_id
     ORDER BY p.uploaded_at DESC, p.id DESC'
)->fetchAll(PDO::FETCH_ASSOC);
$galleryPhotosByFolder = [];
foreach ($galleryPhotos as $galleryPhoto) {
    $galleryPhotosByFolder[(int) $galleryPhoto['folder_id']][] = $galleryPhoto;
}
$galleryFlashMessage = $_SESSION['flash_message'] ?? '';
$galleryFlashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);
?>

<?php foreach ($galleryErrors as $galleryError): ?>
    <div class="alert alert-error mb-4" role="alert"><?= $galleryEscape($galleryError) ?></div>
<?php endforeach; ?>

<?php if (is_string($galleryFlashMessage) && $galleryFlashMessage !== ''): ?>
    <div class="alert <?= $galleryFlashType === 'success' ? 'alert-success' : ($galleryFlashType === 'error' ? 'alert-error' : 'alert-info') ?> mb-4"
         role="status"><?= $galleryEscape($galleryFlashMessage) ?></div>
<?php endif; ?>

<section class="card mb-6 border-t-4 border-orange-700 p-5"
         aria-labelledby="gallery-create-folder-heading">
    <h2 id="gallery-create-folder-heading" class="mb-4 text-lg font-bold text-gray-900">
        1. Create a New Album
    </h2>
    <form action="<?= $galleryEscape($galleryManagerAction) ?>" method="POST"
          enctype="multipart/form-data" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <input type="hidden" name="csrf_token" value="<?= $galleryEscape($galleryCsrfToken) ?>">
        <input type="hidden" name="action" value="create_folder">
        <label class="form-group mb-0">
            <span class="form-label">Album name</span>
            <input type="text" name="folder_name" class="form-input" maxlength="100"
                   placeholder="e.g., Cement" required>
        </label>
        <label class="form-group mb-0">
            <span class="form-label">Option 1: Cover image file</span>
            <input type="file" name="cover_file" class="form-input"
                   accept="image/jpeg,image/png,image/gif,image/webp">
        </label>
        <label class="form-group mb-0">
            <span class="form-label">Option 2: Cover image URL</span>
            <input type="url" name="cover_image_url" class="form-input"
                   maxlength="255" placeholder="https://example.com/cover.jpg">
        </label>
        <div class="flex items-end">
            <button type="submit" class="btn btn-primary w-full">Create Album</button>
        </div>
    </form>
</section>

<section class="card mb-6 border-t-4 border-green-700 p-5"
         aria-labelledby="gallery-add-photo-heading">
    <h2 id="gallery-add-photo-heading" class="mb-4 text-lg font-bold text-gray-900">
        2. Add Photo to an Album
    </h2>
    <?php if ($galleryFolders === []): ?>
        <p class="text-sm text-gray-600">Create an album before adding photos.</p>
    <?php else: ?>
        <form action="<?= $galleryEscape($galleryManagerAction) ?>" method="POST"
              enctype="multipart/form-data" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <input type="hidden" name="csrf_token" value="<?= $galleryEscape($galleryCsrfToken) ?>">
            <input type="hidden" name="action" value="add_photo">
            <label class="form-group mb-0">
                <span class="form-label">Album</span>
                <select name="folder_id" class="form-input" required>
                    <option value="">Select album...</option>
                    <?php foreach ($galleryFolders as $folder): ?>
                        <option value="<?= (int) $folder['id'] ?>">
                            <?= $galleryEscape((string) $folder['folder_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="form-group mb-0">
                <span class="form-label">Caption (optional)</span>
                <input type="text" name="title" class="form-input" maxlength="255"
                       placeholder="Photo caption">
            </label>
            <label class="form-group mb-0">
                <span class="form-label">Option 1: Image file</span>
                <input type="file" name="photo_file" class="form-input"
                       accept="image/jpeg,image/png,image/gif,image/webp">
            </label>
            <label class="form-group mb-0">
                <span class="form-label">Option 2: Image URL</span>
                <input type="url" name="image_url" class="form-input" maxlength="255"
                       placeholder="https://example.com/photo.jpg">
            </label>
            <div class="flex items-end">
                <button type="submit" class="btn btn-primary w-full">Add Photo</button>
            </div>
        </form>
        <p class="mt-2 text-xs text-gray-500">Files must be JPEG, PNG, GIF or WebP and no larger than 5 MB. Files take priority over URLs.</p>
    <?php endif; ?>
</section>

<section aria-labelledby="gallery-albums-heading">
    <h2 id="gallery-albums-heading" class="mb-4 text-lg font-bold text-gray-900">
        Albums <span class="text-sm font-normal text-gray-500">(<?= count($galleryFolders) ?>)</span>
    </h2>
    <?php if ($galleryFolders === []): ?>
        <div class="card p-6 text-sm text-gray-600">No albums have been created yet.</div>
    <?php else: ?>
        <div class="space-y-6">
            <?php foreach ($galleryFolders as $folder): ?>
                <?php $coverUrl = $galleryImageUrl($folder['cover_image']); ?>
                <article class="card overflow-hidden">
                    <div class="flex flex-col gap-4 p-4 sm:flex-row">
                        <?php if ($coverUrl !== ''): ?>
                            <img src="<?= $galleryEscape($coverUrl) ?>"
                                 class="h-36 w-full rounded object-cover sm:w-56"
                                 alt="<?= $galleryEscape((string) $folder['folder_name']) ?> album cover">
                        <?php endif; ?>
                        <div class="flex min-w-0 flex-1 items-start justify-between gap-3">
                            <div>
                                <h3 class="text-lg font-bold text-gray-900">
                                    <?= $galleryEscape((string) $folder['folder_name']) ?>
                                </h3>
                                <p class="text-sm text-gray-500">
                                    <?= (int) $folder['photo_count'] ?> photo<?= (int) $folder['photo_count'] === 1 ? '' : 's' ?>
                                </p>
                                <a class="mt-2 inline-block text-sm font-semibold text-orange-700 hover:underline"
                                   href="<?= $galleryEscape(rtrim((string) env('APP_URL', ''), '/') . '/gallery.php?folder=' . (int) $folder['id']) ?>">
                                    View on storefront
                                </a>
                            </div>
                            <form method="POST" action="<?= $galleryEscape($galleryManagerAction) ?>"
                                  data-confirm="Delete this album and all of its photos?">
                                <input type="hidden" name="csrf_token" value="<?= $galleryEscape($galleryCsrfToken) ?>">
                                <input type="hidden" name="action" value="delete_folder">
                                <input type="hidden" name="record_id" value="<?= (int) $folder['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-secondary">Delete Album</button>
                            </form>
                        </div>
                    </div>

                    <?php $folderPhotos = $galleryPhotosByFolder[(int) $folder['id']] ?? []; ?>
                    <?php if ($folderPhotos !== []): ?>
                        <div class="grid grid-cols-2 gap-3 border-t border-gray-200 p-4 sm:grid-cols-3 xl:grid-cols-5">
                            <?php foreach ($folderPhotos as $photo): ?>
                                <?php $photoUrl = $galleryImageUrl($photo['image_url']); ?>
                                <?php if ($photoUrl !== ''): ?>
                                    <div class="overflow-hidden rounded border border-gray-200">
                                        <img src="<?= $galleryEscape($photoUrl) ?>"
                                             class="h-28 w-full object-cover"
                                             loading="lazy"
                                             alt="<?= $galleryEscape((string) ($photo['title'] ?: $folder['folder_name'])) ?>">
                                        <div class="flex items-center justify-between gap-2 p-2">
                                            <span class="truncate text-xs font-semibold text-gray-700">
                                                <?= $galleryEscape((string) ($photo['title'] ?: 'Untitled')) ?>
                                            </span>
                                            <form method="POST" action="<?= $galleryEscape($galleryManagerAction) ?>"
                                                  data-confirm="Delete this photo?">
                                                <input type="hidden" name="csrf_token" value="<?= $galleryEscape($galleryCsrfToken) ?>">
                                                <input type="hidden" name="action" value="delete_photo">
                                                <input type="hidden" name="record_id" value="<?= (int) $photo['id'] ?>">
                                                <button type="submit" class="text-xs font-semibold text-red-700 hover:underline">Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
