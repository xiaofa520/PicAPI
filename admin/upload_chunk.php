<?php

require_once __DIR__ . '/common.php';

admin_require_login();
admin_require_csrf();
ignore_user_abort(true);
@set_time_limit(120);
ob_start();

header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8', true, 500);
    }

    $detail = trim((string) ($error['message'] ?? 'Unknown fatal error'));
    $detail .= ' in ' . basename((string) ($error['file'] ?? 'unknown')) . ':' . (int) ($error['line'] ?? 0);

    echo json_encode([
        'ok' => false,
        'message' => '上传失败，请重试。',
        'detail' => $detail,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
});

function upload_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        http_response_code(500);
        echo '{"ok":false,"message":"Upload response encoding failed"}';
        exit;
    }

    echo $json;
    exit;
}

function upload_cleanup_files(string $assembledPath, string $chunkFolder): void
{
    if ($assembledPath !== '' && is_file($assembledPath)) {
        @unlink($assembledPath);
    }

    foreach (glob($chunkFolder . '/*.part') ?: [] as $part) {
        @unlink($part);
    }

    @rmdir($chunkFolder);
}

function upload_memory_limit_bytes(): int
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

function upload_can_generate_preview(int $width, int $height, string $mime): bool
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
    $limit = upload_memory_limit_bytes();

    if ($limit < 0) {
        return $estimatedNeed <= 256 * 1024 * 1024;
    }

    $available = max(0, $limit - memory_get_usage(true));
    return $estimatedNeed < max(16 * 1024 * 1024, (int) floor($available * 0.72));
}

function upload_generate_preview_if_safe(string $sourcePath, string $libraryKey, string $relativePath, int $width, int $height, string $mime): bool
{
    if (!upload_can_generate_preview($width, $height, $mime)) {
        return false;
    }

    admin_create_preview_png($sourcePath, $libraryKey, $relativePath);
    return true;
}

function upload_find_duplicate_image(string $targetDir, string $sourcePath): ?string
{
    if (!is_dir($targetDir) || !is_file($sourcePath)) {
        return null;
    }

    $sourceSize = filesize($sourcePath);
    if ($sourceSize === false) {
        return null;
    }

    $sourceHash = hash_file('sha1', $sourcePath);
    if ($sourceHash === false) {
        return null;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($targetDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $extension = strtolower($fileInfo->getExtension());
        if (!in_array($extension, admin_allowed_extensions(), true)) {
            continue;
        }

        if ($fileInfo->getSize() !== $sourceSize) {
            continue;
        }

        $existingHash = hash_file('sha1', $fileInfo->getPathname());
        if ($existingHash !== false && hash_equals($sourceHash, $existingHash)) {
            return $fileInfo->getPathname();
        }
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    upload_json(['ok' => false, 'message' => 'Only POST is allowed'], 405);
}

if (!isset($_FILES['chunk']) || ($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    upload_json(['ok' => false, 'message' => 'Missing chunk upload'], 400);
}

$uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['upload_id'] ?? ''));
$originalName = (string) ($_POST['file_name'] ?? '');
$chunkIndex = (int) ($_POST['chunk_index'] ?? -1);
$totalChunks = (int) ($_POST['total_chunks'] ?? 0);
$totalSize = (int) ($_POST['total_size'] ?? 0);
$declaredMime = strtolower(trim((string) ($_POST['file_type'] ?? '')));
$category = trim((string) ($_POST['category'] ?? admin_default_category_name()));

if ($category === '') {
    $category = admin_default_category_name();
}

if ($category !== admin_default_category_name()) {
    try {
        $category = admin_validate_category_name($category);
    } catch (Throwable $throwable) {
        upload_json(['ok' => false, 'message' => $throwable->getMessage()], 400);
    }
}

if ($uploadId === '' || $originalName === '' || $chunkIndex < 0 || $totalChunks <= 0 || $totalSize <= 0) {
    upload_json(['ok' => false, 'message' => 'Incomplete upload metadata'], 400);
}

if ($totalChunks > ADMIN_MAX_UPLOAD_CHUNKS) {
    upload_json(['ok' => false, 'message' => 'Upload chunk count exceeds the allowed limit'], 400);
}

if ($totalSize > ADMIN_MAX_UPLOAD_FILE_SIZE) {
    upload_json(['ok' => false, 'message' => 'Image file is too large'], 400);
}

if ($chunkIndex >= $totalChunks) {
    upload_json(['ok' => false, 'message' => 'Invalid chunk index'], 400);
}

$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($extension, admin_allowed_extensions(), true)) {
    upload_json(['ok' => false, 'message' => 'Unsupported image extension'], 400);
}

$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if ($declaredMime !== '' && !in_array($declaredMime, $allowedMimeTypes, true)) {
    upload_json(['ok' => false, 'message' => 'Unsupported image type'], 400);
}

$chunkFileSize = (int) ($_FILES['chunk']['size'] ?? 0);
if ($chunkFileSize <= 0 || $chunkFileSize > ADMIN_CHUNK_SIZE) {
    upload_json(['ok' => false, 'message' => 'Chunk size is invalid'], 400);
}

$chunkFolder = ADMIN_CHUNK_DIR . '/' . $uploadId;
image_api_ensure_directory($chunkFolder);

$chunkPath = $chunkFolder . '/chunk_' . $chunkIndex . '.part';
if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkPath)) {
    upload_json(['ok' => false, 'message' => 'Failed to persist chunk'], 500);
}

for ($i = 0; $i < $totalChunks; $i++) {
    if (!is_file($chunkFolder . '/chunk_' . $i . '.part')) {
        upload_json(['ok' => true, 'completed' => false]);
    }
}

$safeName = admin_sanitize_file_name($originalName);
$assembledPath = ADMIN_TEMP_DIR . '/' . $uploadId . '-' . $safeName;
$outputHandle = fopen($assembledPath, 'wb');
if ($outputHandle === false) {
    upload_json(['ok' => false, 'message' => 'Failed to assemble upload'], 500);
}

try {
    for ($i = 0; $i < $totalChunks; $i++) {
        $partPath = $chunkFolder . '/chunk_' . $i . '.part';
        $inputHandle = fopen($partPath, 'rb');
        if ($inputHandle === false) {
            throw new RuntimeException('Missing chunk during assembly');
        }
        stream_copy_to_stream($inputHandle, $outputHandle);
        fclose($inputHandle);
    }
} catch (Throwable $throwable) {
    fclose($outputHandle);
    @unlink($assembledPath);
    upload_json(['ok' => false, 'message' => $throwable->getMessage()], 500);
}

fclose($outputHandle);

$assembledSize = filesize($assembledPath);
if ($assembledSize === false || $assembledSize <= 0 || $assembledSize > ADMIN_MAX_UPLOAD_FILE_SIZE) {
    upload_cleanup_files($assembledPath, $chunkFolder);
    upload_json(['ok' => false, 'message' => 'Merged image size is invalid'], 400);
}

if (!admin_uploaded_file_is_image($assembledPath)) {
    upload_cleanup_files($assembledPath, $chunkFolder);
    upload_json(['ok' => false, 'message' => 'Merged file is not a valid image'], 400);
}

$imageSize = @getimagesize($assembledPath);
if (!is_array($imageSize)) {
    upload_cleanup_files($assembledPath, $chunkFolder);
    upload_json(['ok' => false, 'message' => 'Unable to read image metadata'], 400);
}

$mime = strtolower((string) ($imageSize['mime'] ?? ''));
if (!in_array($mime, $allowedMimeTypes, true)) {
    upload_cleanup_files($assembledPath, $chunkFolder);
    upload_json(['ok' => false, 'message' => 'Server rejected the image type'], 400);
}

$width = (int) ($imageSize[0] ?? 0);
$height = (int) ($imageSize[1] ?? 0);

try {
    admin_validate_image_dimensions($width, $height);
} catch (Throwable $throwable) {
    upload_cleanup_files($assembledPath, $chunkFolder);
    upload_json(['ok' => false, 'message' => $throwable->getMessage()], 400);
}

$libraryKey = admin_classify_image($width, $height);
$libraryConfig = $libraryConfigs[$libraryKey];
$targetDir = $libraryConfig['root_dir'] . '/' . $category;
image_api_ensure_directory($targetDir);

try {
    $duplicatePath = upload_find_duplicate_image($targetDir, $assembledPath);
} catch (Throwable $throwable) {
    upload_cleanup_files($assembledPath, $chunkFolder);
    upload_json(['ok' => false, 'message' => 'Duplicate check failed: ' . $throwable->getMessage()], 500);
}

if ($duplicatePath !== null) {
    $relativePath = ltrim(str_replace('\\', '/', substr($duplicatePath, strlen($libraryConfig['root_dir']))), '/');
    $previewCreated = upload_generate_preview_if_safe($duplicatePath, $libraryKey, $relativePath, $width, $height, $mime);

    $record = [
        'uploaded_at' => date('Y-m-d H:i:s'),
        'operator' => admin_current_username(),
        'original_name' => $originalName,
        'saved_name' => basename($duplicatePath),
        'category' => $category,
        'library' => $libraryKey,
        'library_label' => $libraryConfig['label'],
        'public_path' => str_replace('\\', '/', $libraryConfig['public_prefix'] . '/' . $relativePath),
        'preview_path' => $previewCreated ? admin_preview_url($libraryKey, $relativePath, (int) filemtime($duplicatePath)) : '',
        'width' => $width,
        'height' => $height,
        'size' => filesize($duplicatePath) ?: 0,
        'transport' => 'chunked',
        'skipped_duplicate' => true,
        'preview_skipped' => !$previewCreated,
    ];

    upload_cleanup_files($assembledPath, $chunkFolder);
    upload_json([
        'ok' => true,
        'completed' => true,
        'duplicate' => true,
        'skipped' => true,
        'message' => '图片已存在',
        'record' => $record,
    ]);
}

$finalName = admin_unique_file_name($targetDir, $safeName);
$finalPath = $targetDir . '/' . $finalName;
if (!rename($assembledPath, $finalPath)) {
    upload_cleanup_files($assembledPath, $chunkFolder);
    upload_json(['ok' => false, 'message' => 'Failed to move final file'], 500);
}

$relativePath = $category . '/' . $finalName;
$publicPath = $libraryConfig['public_prefix'] . '/' . $relativePath;
$previewCreated = upload_generate_preview_if_safe($finalPath, $libraryKey, $relativePath, $width, $height, $mime);

$record = [
    'uploaded_at' => date('Y-m-d H:i:s'),
    'operator' => admin_current_username(),
    'original_name' => $originalName,
    'saved_name' => $finalName,
    'category' => $category,
    'library' => $libraryKey,
    'library_label' => $libraryConfig['label'],
    'public_path' => str_replace('\\', '/', $publicPath),
    'preview_path' => $previewCreated ? admin_preview_url($libraryKey, $relativePath, (int) filemtime($finalPath)) : '',
    'width' => $width,
    'height' => $height,
    'size' => filesize($finalPath) ?: 0,
    'transport' => 'chunked',
    'preview_skipped' => !$previewCreated,
];
admin_append_upload_log($record);
upload_cleanup_files('', $chunkFolder);

upload_json([
    'ok' => true,
    'completed' => true,
    'record' => $record,
]);
