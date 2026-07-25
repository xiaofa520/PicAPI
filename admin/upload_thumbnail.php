<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/image_runtime.php';

initialize_storage();
require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    abort_request(405, 'Method not allowed.');
}
verify_csrf();

$device = (string) ($_POST['device'] ?? '');
$relative = ltrim(str_replace('\\', '/', (string) ($_POST['relative'] ?? '')), '/');
$thumbnail = $_FILES['thumbnail'] ?? null;
if (!in_array($device, ['pc', 'mobile'], true) || $relative === '' || str_contains($relative, '..') || !is_array($thumbnail)) {
    abort_request(422, 'Invalid thumbnail metadata.');
}
if (($thumbnail['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !isset($thumbnail['tmp_name']) || !is_uploaded_file($thumbnail['tmp_name'])) {
    abort_request(422, 'Missing thumbnail file.');
}
if (detect_image_mime($thumbnail['tmp_name']) !== 'image/png' || @getimagesize($thumbnail['tmp_name']) === false) {
    abort_request(422, 'Thumbnail must be a PNG image.');
}

$sourceRoot = $device === 'mobile' ? IMAGE_MOBILE_DIR : IMAGE_PC_DIR;
$source = realpath($sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
$realSourceRoot = realpath($sourceRoot);
if ($source === false || $realSourceRoot === false || !str_starts_with($source, $realSourceRoot . DIRECTORY_SEPARATOR) || !is_file($source)) {
    abort_request(404, 'Original image not found.');
}

$target = thumbnail_path($device, $relative);
ensure_directory(dirname($target));
if (!move_uploaded_file($thumbnail['tmp_name'], $target)) {
    abort_request(500, 'Unable to store thumbnail.');
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
