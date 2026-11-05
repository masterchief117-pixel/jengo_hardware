<?php
/**
 * Resolve a product image URL or a known local product-image path.
 */
function product_image_src(mixed $imageValue): string
{
    $imageVal = trim((string) ($imageValue ?? ''));

    if (filter_var($imageVal, FILTER_VALIDATE_URL)) {
        $scheme = strtolower((string) parse_url($imageVal, PHP_URL_SCHEME));
        if (in_array($scheme, ['http', 'https'], true)) {
            return $imageVal;
        }
    }

    $normalized = ltrim(str_replace('\\', '/', $imageVal), '/');
    $localDirectories = [
        'uploads' => __DIR__ . '/../uploads',
        'assets/img/products' => __DIR__ . '/../assets/img/products',
    ];

    foreach ($localDirectories as $relativeDirectory => $filesystemDirectory) {
        $prefix = $relativeDirectory . '/';
        $filename = str_starts_with($normalized, $prefix)
            ? substr($normalized, strlen($prefix))
            : ($relativeDirectory === 'uploads' && !str_contains($normalized, '/')
                ? $normalized
                : '');

        if ($filename === '' || basename($filename) !== $filename) {
            continue;
        }

        $directory = realpath($filesystemDirectory);
        $file = $directory === false
            ? false
            : realpath($directory . DIRECTORY_SEPARATOR . $filename);

        if ($file !== false && is_file($file) && dirname($file) === $directory) {
            return $relativeDirectory . '/' . rawurlencode($filename);
        }
    }

    return 'https://via.placeholder.com/300x200?text=No+Image+Available';
}
