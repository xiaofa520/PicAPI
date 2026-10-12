<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/image_runtime.php';

initialize_storage();
require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    abort_request(405, 'Method not allowed.');
}
verify_csrf();

$uploadId = (string) ($_POST['upload_id'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
    abort_request(422, 'Invalid upload id.');
}

$chunksRoot = realpath(STORAGE_DIR . '/chunks');
$directory = realpath(STORAGE_DIR . '/chunks/' . $uploadId);
if ($chunksRoot !== false && $directory !== false && str_starts_with($directory, $chunksRoot . DIRECTORY_SEPARATOR) && is_dir($directory)) {
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isFile() && !$entry->isLink()) @unlink($entry->getPathname());
    }
    @rmdir($directory);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
