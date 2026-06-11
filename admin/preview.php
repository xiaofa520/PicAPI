<?php

require_once __DIR__ . '/common.php';

admin_require_login();

function preview_placeholder_png(): void
{
    $image = imagecreatetruecolor(32, 32);
    if ($image === false) {
        http_response_code(404);
        exit;
    }

    $background = imagecolorallocate($image, 224, 228, 233);
    $stripe = imagecolorallocate($image, 173, 181, 189);
    imagefilledrectangle($image, 0, 0, 31, 31, $background);
    imagefilledrectangle($image, 0, 13, 31, 19, $stripe);

    header('Content-Type: image/png');
    header('Cache-Control: no-store, max-age=0');
    imagepng($image);
    imagedestroy($image);
    exit;
}

function preview_output_file(string $path, string $contentType = 'image/png'): void
{
    if (!is_file($path)) {
        preview_placeholder_png();
    }

    header('Content-Type: ' . $contentType);
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    exit;
}

function preview_memory_limit_bytes(): int
{
    $value = trim((string) ini_get('memory_limit'));
    if ($value === '' || $value === '-1') {
        return -1;
    }

    $unit = strtolower(substr($value, -1));
    $bytes = (int) $value;

    return match ($unit) {
        'g' => $bytes * 1024 * 1024 * 1024,
        'm' => $bytes * 1024 * 1024,
        'k' => $bytes * 1024,
        default => (int) $value,
    };
}

function preview_can_build(int $width, int $height, string $mime): bool
{
    if ($width <= 0 || $height <= 0) {
        return false;
    }

    $pixels = $width * $height;
    $maxPixels = $mime === 'image/png' ? 8_000_000 : 12_000_000;
    if ($pixels > $maxPixels) {
        return false;
    }

    $estimatedSourceBytes = $pixels * 8;
    $estimatedPreviewBytes = ADMIN_PREVIEW_MAX_EDGE * ADMIN_PREVIEW_MAX_EDGE * 8;
    $estimatedNeed = $estimatedSourceBytes + $estimatedPreviewBytes + 12 * 1024 * 1024;
    $limit = preview_memory_limit_bytes();

    if ($limit < 0) {
        return $estimatedNeed <= 256 * 1024 * 1024;
    }

    $available = max(0, $limit - memory_get_usage(true));
    return $estimatedNeed < max(16 * 1024 * 1024, (int) floor($available * 0.72));
}

function preview_cache_path(string $libraryKey, string $relativePath): string
{
    $cacheDir = ADMIN_PREVIEW_DIR . '/' . $libraryKey;
    image_api_ensure_directory($cacheDir);
    return $cacheDir . '/' . sha1($libraryKey . '|' . $relativePath) . '.png';
}

function preview_open_source_image(string $path, string $mime)
{
    return match ($mime) {
        'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
        'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
        'image/gif' => function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) : false,
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default => false,
    };
}

function preview_build_cache(string $sourcePath, string $cachePath): bool
{
    $info = @getimagesize($sourcePath);
    if (!is_array($info)) {
        return false;
    }

    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    $mime = strtolower((string) ($info['mime'] ?? ''));

    if (!preview_can_build($width, $height, $mime)) {
        return false;
    }

    $source = preview_open_source_image($sourcePath, $mime);
    if ($source === false) {
        return false;
    }

    $scale = min(1, ADMIN_PREVIEW_MAX_EDGE / max($width, $height));
    $targetWidth = max(1, (int) round($width * $scale));
    $targetHeight = max(1, (int) round($height * $scale));

    $preview = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($preview === false) {
        imagedestroy($source);
        return false;
    }

    imagealphablending($preview, false);
    imagesavealpha($preview, true);
    $transparent = imagecolorallocatealpha($preview, 255, 255, 255, 127);
    imagefilledrectangle($preview, 0, 0, $targetWidth, $targetHeight, $transparent);

    $copied = imagecopyresampled(
        $preview,
        $source,
        0,
        0,
        0,
        0,
        $targetWidth,
        $targetHeight,
        $width,
        $height
    );

    if ($copied) {
        imagepng($preview, $cachePath, 6);
    }

    imagedestroy($preview);
    imagedestroy($source);

    return $copied && is_file($cachePath);
}

$libraryKey = trim((string) ($_GET['library'] ?? ''));
$relativePath = image_api_normalize_relative_path((string) ($_GET['path'] ?? ''));

if ($libraryKey === '' || $relativePath === '' || !isset($libraryConfigs[$libraryKey])) {
    preview_placeholder_png();
}

$sourcePath = $libraryConfigs[$libraryKey]['root_dir'] . '/' . $relativePath;
if (!is_file($sourcePath)) {
    preview_placeholder_png();
}

$cachePath = preview_cache_path($libraryKey, $relativePath);
$sourceModifiedAt = (int) (filemtime($sourcePath) ?: 0);
$cacheModifiedAt = is_file($cachePath) ? (int) (filemtime($cachePath) ?: 0) : 0;

if ($cacheModifiedAt < $sourceModifiedAt) {
    @unlink($cachePath);
    preview_build_cache($sourcePath, $cachePath);
}

if (is_file($cachePath)) {
    preview_output_file($cachePath, 'image/png');
}

$sourceInfo = @getimagesize($sourcePath);
$sourceMime = is_array($sourceInfo) ? (string) ($sourceInfo['mime'] ?? 'image/png') : 'image/png';
preview_output_file($sourcePath, $sourceMime !== '' ? $sourceMime : 'image/png');
