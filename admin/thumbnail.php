<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/image_runtime.php';

initialize_storage();
require_admin();

$device = (string) ($_GET['device'] ?? '');
$relative = (string) ($_GET['relative'] ?? '');
if (!in_array($device, ['pc', 'mobile'], true)) {
    abort_request(404, 'Thumbnail not found.');
}

try {
    $target = thumbnail_path($device, $relative);
} catch (InvalidArgumentException) {
    abort_request(404, 'Thumbnail not found.');
}
$sourceRoot = $device === 'mobile' ? IMAGE_MOBILE_DIR : IMAGE_PC_DIR;
$source = realpath($sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim(str_replace('\\', '/', $relative), '/')));
$sourceRootReal = realpath($sourceRoot);
if ($source === false || $sourceRootReal === false || !str_starts_with($source, $sourceRootReal . DIRECTORY_SEPARATOR) || !is_file($source)) {
    abort_request(404, 'Original image not found.');
}

/**
 * Creates a compact, lossless PNG preview. The 360 px longest side is much
 * larger than the 40 px list cell, while keeping decoded browser memory low.
 */
function generate_preview_thumbnail(string $source, string $target): bool
{
    $size = @getimagesize($source);
    if (!is_array($size) || (int) ($size[0] ?? 0) < 1 || (int) ($size[1] ?? 0) < 1) return false;
    $width = (int) $size[0];
    $height = (int) $size[1];
    $maxEdge = 360;

    // Imagick is preferred on servers that provide it: it can process large
    // originals with disk-backed memory management and produces a lossless PNG.
    if (class_exists('Imagick')) {
        try {
            $image = new Imagick();
            $image->readImage($source);
            $image->thumbnailImage($maxEdge, $maxEdge, true, true);
            $image->stripImage();
            $image->setImageFormat('png');
            $image->setImageCompression(Imagick::COMPRESSION_ZIP);
            // PNG is lossless. Level 9 makes the cached preview small without
            // reducing visual quality.
            $image->setImageCompressionQuality(9);
            $image->setOption('png:compression-level', '9');
            $image->setOption('png:compression-filter', '5');
            $image->writeImage($target);
            $image->clear();
            $image->destroy();
            return is_file($target) && filesize($target) > 0;
        } catch (Throwable) {
            if (isset($image) && $image instanceof Imagick) {
                $image->clear();
                $image->destroy();
            }
        }
    }

    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) return false;
    // Prevent an unreasonably large source from exhausting PHP memory. The
    // endpoint transparently falls back to the original in that rare case.
    if ($width * $height > 16_000_000) return false;
    $type = (int) ($size[2] ?? 0);
    $image = match ($type) {
        IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($source) : false,
        IMAGETYPE_PNG => function_exists('imagecreatefrompng') ? @imagecreatefrompng($source) : false,
        IMAGETYPE_GIF => function_exists('imagecreatefromgif') ? @imagecreatefromgif($source) : false,
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
        default => function_exists('imagecreatefromstring') ? @imagecreatefromstring((string) @file_get_contents($source)) : false,
    };
    if ($image === false) return false;
    $scale = min(1, $maxEdge / max($width, $height));
    $targetWidth = max(1, (int) round($width * $scale));
    $targetHeight = max(1, (int) round($height * $scale));
    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($canvas === false) {
        imagedestroy($image);
        return false;
    }
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
    $generated = imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)
        && imagepng($canvas, $target, 9);
    imagedestroy($canvas);
    imagedestroy($image);
    return $generated;
}

$root = realpath(THUMBNAIL_DIR . DIRECTORY_SEPARATOR . $device);
$path = realpath($target);
if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path) || filemtime($path) < filemtime($source)) {
    ensure_directory(dirname($target));
    $lock = @fopen($target . '.lock', 'c+');
    if (is_resource($lock)) {
        try {
            if (flock($lock, LOCK_EX)) {
                if (!is_file($target) || filemtime($target) < filemtime($source)) generate_preview_thumbnail($source, $target);
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }
    $root = realpath(THUMBNAIL_DIR . DIRECTORY_SEPARATOR . $device);
    $path = realpath($target);
}

if ($root !== false && $path !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path)) {
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// GD/Imagick may be unavailable on lightweight servers. Returning the source
// image keeps the gallery usable until thumbnail support is enabled.
header('Content-Type: ' . (mime_content_type($source) ?: 'application/octet-stream'));
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . filesize($source));
readfile($source);
