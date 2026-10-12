<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/image_runtime.php';

initialize_storage();
require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') abort_request(405, '请求方式不正确。');
verify_csrf();

$nonce = bin2hex(random_bytes(6));
$files = [];
foreach (array_merge(glob(STORAGE_DIR . '/site-icon.*') ?: [], glob(STORAGE_DIR . '/site-icon-original.*') ?: []) as $path) {
    if (preg_match('/^site-icon(?:-original)?\.(?:jpg|png|gif|webp)$/i', basename($path)) && is_file($path)) $files[] = $path;
}
$backups = [];
try {
    foreach (array_unique($files) as $path) {
        $backup = $path . '.restore-' . $nonce;
        if (!rename($path, $backup)) throw new RuntimeException('无法恢复默认网站图标，请检查存储目录权限。');
        $backups[$path] = $backup;
    }
    sync_site_favicon_root();
    foreach ($backups as $backup) @unlink($backup);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'favicon' => site_favicon_url()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    foreach ($backups as $path => $backup) if (is_file($backup)) @rename($backup, $path);
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
