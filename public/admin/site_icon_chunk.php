<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/image_runtime.php';
require_once __DIR__ . '/../../src/settings_runtime.php';

initialize_storage();
require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') abort_request(405, '请求方式不正确。');
verify_csrf();
header('Content-Type: application/json; charset=utf-8');

function site_icon_chunk_cleanup(string $directory): void
{
    if (!is_dir($directory)) return;
    foreach (glob($directory . '/*') ?: [] as $entry) {
        if (is_file($entry)) @unlink($entry);
        elseif (is_dir($entry)) {
            foreach (glob($entry . '/*') ?: [] as $part) if (is_file($part)) @unlink($part);
            @rmdir($entry);
        }
    }
    @unlink($directory . '/.lock');
    @rmdir($directory);
}

$uploadId = (string) ($_POST['upload_id'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => '网站图标上传参数不正确。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$root = STORAGE_DIR . '/site-icon-chunks';
ensure_directory($root);
foreach (glob($root . '/*') ?: [] as $stale) {
    if (is_dir($stale) && filemtime($stale) !== false && filemtime($stale) < time() - 86400) site_icon_chunk_cleanup($stale);
}
$directory = $root . '/' . $uploadId;
ensure_directory($directory);
$lock = fopen($directory . '/.lock', 'c+');
if ($lock === false || !flock($lock, LOCK_EX)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => '无法准备网站图标上传。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$status = 200;
$response = ['ok' => true];
$cleanup = false;
try {
    $action = (string) ($_POST['action'] ?? 'chunk');
    $manifestPath = $directory . '/manifest.json';
    $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : [];
    if (!is_array($manifest)) $manifest = [];

    if ($action === 'chunk') {
        $kind = (string) ($_POST['kind'] ?? '');
        $index = filter_var($_POST['index'] ?? null, FILTER_VALIDATE_INT);
        $total = filter_var($_POST['total'] ?? null, FILTER_VALIDATE_INT);
        $chunk = $_FILES['chunk'] ?? null;
        if (!in_array($kind, ['original', 'compressed'], true) || $index === false || $total === false || $total < 1 || $total > 10 || $index < 0 || $index >= $total) {
            throw new RuntimeException('网站图标分片参数不正确。');
        }
        if (!is_array($chunk) || (int) ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($chunk['tmp_name'] ?? ''))) {
            throw new RuntimeException('未收到完整的网站图标分片，请重试。');
        }
        if ((int) ($chunk['size'] ?? 0) < 1 || (int) $chunk['size'] > 2 * 1024 * 1024) throw new RuntimeException('单个网站图标分片不能超过 2 MB。');
        if (isset($manifest[$kind]) && (int) $manifest[$kind] !== $total) throw new RuntimeException('网站图标分片总数不一致，请重新选择图片。');
        $manifest[$kind] = $total;
        $kindDirectory = $directory . '/' . $kind;
        ensure_directory($kindDirectory);
        $partPath = $kindDirectory . DIRECTORY_SEPARATOR . sprintf('%03d.part', $index);
        if (is_file($partPath)) @unlink($partPath);
        if (!move_uploaded_file((string) $chunk['tmp_name'], $partPath)) throw new RuntimeException('无法保存网站图标分片。');
        if (file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) throw new RuntimeException('无法记录网站图标上传进度。');
        $response['complete'] = false;
        $response['progress'] = (int) floor((($index + 1) / $total) * 100);
    } elseif ($action === 'finalize') {
        if (!isset($manifest['original'], $manifest['compressed'])) throw new RuntimeException('网站图标上传尚未完成。');
        $assembled = [];
        foreach (['original', 'compressed'] as $kind) {
            $count = (int) $manifest[$kind];
            $path = $directory . '/' . $kind . '.assembled';
            $output = fopen($path, 'wb');
            if ($output === false) throw new RuntimeException('无法合并网站图标分片。');
            $written = 0;
            try {
                for ($index = 0; $index < $count; $index++) {
                    $partPath = $directory . '/' . $kind . '/' . sprintf('%03d.part', $index);
                    if (!is_file($partPath)) throw new RuntimeException('网站图标分片缺失，请重新上传。');
                    $input = fopen($partPath, 'rb');
                    if ($input === false) throw new RuntimeException('无法读取网站图标分片。');
                    try {
                        while (!feof($input)) {
                            $chunk = fread($input, 65536);
                            if ($chunk === false) throw new RuntimeException('读取网站图标分片失败。');
                            if ($chunk === '') continue;
                            $length = strlen($chunk);
                            if (fwrite($output, $chunk) !== $length) throw new RuntimeException('合并网站图标分片失败。');
                            $written += $length;
                        }
                    } finally { fclose($input); }
                }
            } finally { fclose($output); }
            $maxBytes = $kind === 'original' ? 5 * 1024 * 1024 : 1024 * 1024;
            if ($written < 1 || $written > $maxBytes) throw new RuntimeException($kind === 'original' ? '网站图标原图不能超过 5 MB。' : '网站图标压缩图不能超过 1 MB。');
            $assembled[$kind] = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'size' => $written];
        }
        save_site_icon_upload_pair($assembled['original'], $assembled['compressed'], false);
        $response['complete'] = true;
        $cleanup = true;
    } else {
        throw new RuntimeException('网站图标上传操作不正确。');
    }
} catch (Throwable $error) {
    $status = 422;
    $response = ['ok' => false, 'message' => $error->getMessage()];
}

flock($lock, LOCK_UN);
fclose($lock);
if ($cleanup) site_icon_chunk_cleanup($directory);
http_response_code($status);
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
