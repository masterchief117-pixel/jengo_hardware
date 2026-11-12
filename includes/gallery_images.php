<?php
/**
 * Validate and resolve gallery image references for storefront rendering.
 */

declare(strict_types=1);

function gallery_image_src(mixed $imageValue, string $base): string
{
    $imagePath = trim((string) ($imageValue ?? ''));
    if ($imagePath === '' || strlen($imagePath) > 255) {
        return '';
    }

    if (filter_var($imagePath, FILTER_VALIDATE_URL) !== false) {
        $parts = parse_url($imagePath);
        if (
            is_array($parts) &&
            in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) &&
            !empty($parts['host']) &&
            !isset($parts['user']) &&
            !isset($parts['pass'])
        ) {
            return $imagePath;
        }

        return '';
    }

    if (preg_match('~^uploads/gallery/([a-f0-9]{32}\.(?:jpg|png|gif|webp))$~', $imagePath, $matches) !== 1) {
        return '';
    }

    return rtrim($base, '/') . '/uploads/gallery/' . rawurlencode($matches[1]);
}
