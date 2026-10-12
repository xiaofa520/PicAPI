<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/app.php';
require_once __DIR__ . '/../../src/settings_runtime.php';
require_once __DIR__ . '/../../src/image_runtime.php';

initialize_storage();
$settings = site_settings();
$basename = (string) ($settings['login_background_image'] ?? '');
if (($settings['login_background_type'] ?? 'color') !== 'image'
    || preg_match('/^login-wallpaper-[a-f0-9]{24}\.(?:webp|jpe?g)$/', $basename) !== 1) {
    abort_request(404, 'Login wallpaper not found.');
}
$directory = realpath(STORAGE_DIR . '/login-wallpapers');
$path = $directory === false ? false : realpath($directory . DIRECTORY_SEPARATOR . $basename);
if ($directory === false || $path === false || dirname($path) !== $directory || !is_file($path)) {
    abort_request(404, 'Login wallpaper not found.');
}
$mime = detect_image_mime($path);
if (!in_array($mime, ['image/webp', 'image/jpeg'], true)) abort_request(415, 'Unsupported wallpaper format.');
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
readfile($path);
