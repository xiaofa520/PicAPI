<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/app.php';
require_once __DIR__ . '/../../src/settings_runtime.php';

initialize_storage();
start_secure_session();
require_admin();
verify_csrf();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function wallpaper_json(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$settings = site_settings();
$directory = STORAGE_DIR . '/login-wallpapers';
if ((string) ($_POST['action'] ?? '') === 'clear') {
    $previous = (string) ($settings['login_background_image'] ?? '');
    $settings['login_background_image'] = '';
    $settings['login_background_type'] = 'color';
    $settings = save_site_settings($settings);
    if (preg_match('/^login-wallpaper-[a-f0-9]{24}\.(?:webp|jpe?g)$/', $previous) === 1) {
        $oldPath = $directory . DIRECTORY_SEPARATOR . $previous;
        if (is_file($oldPath) && realpath(dirname($oldPath)) === realpath($directory)) @unlink($oldPath);
    }
    wallpaper_json(['ok' => true, 'settings' => $settings]);
}

$upload = $_FILES['wallpaper'] ?? null;
if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    wallpaper_json(['ok' => false, 'message' => '请先选择壁纸图片。'], 400);
}
$temporary = (string) ($upload['tmp_name'] ?? '');
$bytes = (int) ($upload['size'] ?? 0);
if ($bytes < 1 || $bytes > 3 * 1024 * 1024 || !is_uploaded_file($temporary)) {
    wallpaper_json(['ok' => false, 'message' => '压缩后的壁纸不能超过 3 MB。'], 413);
}
$image = @getimagesize($temporary);
$mime = is_array($image) ? (string) ($image['mime'] ?? '') : '';
if (!is_array($image) || (int) ($image[0] ?? 0) < 1 || (int) ($image[1] ?? 0) < 1
    || (int) $image[0] > 1920 || (int) $image[1] > 1920
    || !in_array($mime, ['image/webp', 'image/jpeg'], true)) {
    wallpaper_json(['ok' => false, 'message' => '壁纸必须是已压缩的 WebP 或 JPEG，最长边不能超过 1920 像素。'], 415);
}

ensure_directory($directory);
$extension = $mime === 'image/webp' ? 'webp' : 'jpg';
$basename = 'login-wallpaper-' . bin2hex(random_bytes(12)) . '.' . $extension;
$destination = $directory . DIRECTORY_SEPARATOR . $basename;
if (!move_uploaded_file($temporary, $destination)) wallpaper_json(['ok' => false, 'message' => '无法保存登录壁纸。'], 500);

try {
    $previous = (string) ($settings['login_background_image'] ?? '');
    $settings['login_background_image'] = $basename;
    $settings['login_background_type'] = 'image';
    $settings = save_site_settings($settings);
    if (preg_match('/^login-wallpaper-[a-f0-9]{24}\.(?:webp|jpe?g)$/', $previous) === 1) {
        $oldPath = $directory . DIRECTORY_SEPARATOR . $previous;
        if (is_file($oldPath) && realpath(dirname($oldPath)) === realpath($directory)) @unlink($oldPath);
    }
} catch (Throwable $error) {
    @unlink($destination);
    wallpaper_json(['ok' => false, 'message' => $error->getMessage()], 500);
}

wallpaper_json(['ok' => true, 'settings' => $settings, 'bytes' => $bytes]);
