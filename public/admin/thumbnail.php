<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/image_runtime.php';

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
 * Creates a compact, lossy WebP preview. The 320 px longest side is enough
 * for gallery cards and list rows while keeping weak-network transfers small.
 */
function generate_preview_thumbnail(string $source, string $target): bool
{
    $size = @getimagesize($source);
    if (!is_array($size) || (int) ($size[0] ?? 0) < 1 || (int) ($size[1] ?? 0) < 1) return false;
    $width = (int) $size[0];
    $height = (int) $size[1];
    $maxEdge = 320;

    // Imagick is preferred on servers that provide it: it can process large
    // originals with disk-backed memory management and encode compact WebP.
    if (class_exists('Imagick') && in_array('WEBP', Imagick::queryFormats('WEBP'), true)) {
        try {
            $image = new Imagick();
            $image->readImage($source);
            $image->thumbnailImage($maxEdge, $maxEdge, true, true);
            $image->stripImage();
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(68);
            $image->setOption('webp:method', '5');
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

    // Some shared hosts do not enable PHP image extensions but do provide
    // ImageMagick as a command-line utility. Use an argument array (never a
    // shell command string), then verify the generated WebP before caching it.
    if (function_exists('proc_open')) {
        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        if (!in_array('proc_open', $disabled, true)) {
            $configured = trim((string) getenv('PICAPI_IMAGEMAGICK_BINARY'));
            $binaries = $configured !== '' ? [$configured] : (PHP_OS_FAMILY === 'Windows' ? ['magick'] : ['magick', 'convert']);
            foreach ($binaries as $binary) {
                $pipes = [];
                $process = @proc_open([$binary, $source, '-thumbnail', $maxEdge . 'x' . $maxEdge . '>', '-strip', '-quality', '68', '-define', 'webp:method=5', $target], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
                if (!is_resource($process)) continue;
                if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
                $stdout = isset($pipes[1]) ? stream_get_contents($pipes[1]) : '';
                if (isset($pipes[1]) && is_resource($pipes[1])) fclose($pipes[1]);
                $stderr = isset($pipes[2]) ? stream_get_contents($pipes[2]) : '';
                if (isset($pipes[2]) && is_resource($pipes[2])) fclose($pipes[2]);
                $exitCode = proc_close($process);
                $generated = @getimagesize($target);
                if ($exitCode === 0 && is_array($generated) && ($generated['mime'] ?? '') === 'image/webp'
                    && (int) $generated[0] <= $maxEdge && (int) $generated[1] <= $maxEdge && filesize($target) > 0) {
                    return true;
                }
                @unlink($target);
                // If the first candidate was not ImageMagick, the next one
                // may still be available; output is intentionally not exposed.
                unset($stdout, $stderr);
            }
        }
    }

    // GD decodes the entire source into memory, so keep its path bounded.
    if ($width * $height > 16_000_000) return false;

    if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) return false;
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
        && imagewebp($canvas, $target, 68);
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
    header('Content-Type: image/webp');
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// Reuse an older PNG preview when this server cannot encode WebP. The browser
// upgrades it to WebP without downloading the full original image again.
$legacyTarget = substr($target, 0, -5) . '.png';
$legacyPath = realpath($legacyTarget);
if ($root !== false && $legacyPath !== false && str_starts_with($legacyPath, $root . DIRECTORY_SEPARATOR)
    && is_file($legacyPath) && filemtime($legacyPath) >= filemtime($source)) {
    header('Content-Type: image/png');
    header('Cache-Control: private, no-cache');
    header('Content-Length: ' . filesize($legacyPath));
    readfile($legacyPath);
    exit;
}

// If PHP extensions and ImageMagick are both unavailable, never send a large
// original as a thumbnail. The workbench lazily converts the visible source
// in the browser and stores the compact WebP through upload_thumbnail.php.
if (max((int) $size[0], (int) $size[1]) <= 320) {
    header('Content-Type: ' . (detect_image_mime($source) ?? 'application/octet-stream'));
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . filesize($source));
    readfile($source);
    exit;
}
$placeholder = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aGxkAAAAASUVORK5CYII=', true);
header('Content-Type: image/png');
header('Cache-Control: private, no-store');
header('X-PicAPI-Thumbnail-Pending: 1');
header('Content-Length: ' . strlen((string) $placeholder));
echo $placeholder;
