<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/image_runtime.php';

function chunk_response(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

initialize_storage();
require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    chunk_response(405, ['ok' => false, 'message' => 'Method not allowed.']);
}
verify_csrf();

$uploadId = (string) ($_POST['upload_id'] ?? '');
$index = filter_var($_POST['index'] ?? null, FILTER_VALIDATE_INT);
$total = filter_var($_POST['total'] ?? null, FILTER_VALIDATE_INT);
$category = trim((string) ($_POST['category'] ?? ''));
$category = $category === '' ? 'default' : $category;
$chunk = $_FILES['chunk'] ?? null;
if (!preg_match('/^[a-f0-9]{32}$/', $uploadId) || $index === false || $total === false || $index < 0 || $total < 1 || $total > MAX_CHUNK_COUNT || $index >= $total) {
    chunk_response(422, ['ok' => false, 'message' => '上传参数不正确。']);
}
if ($category !== '' && (!valid_category($category) || !in_array($category, all_categories(), true))) {
    chunk_response(422, ['ok' => false, 'message' => '所选分类不存在或已被删除，请重新选择分类。']);
}
if (!is_array($chunk) || ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !isset($chunk['tmp_name']) || !is_uploaded_file($chunk['tmp_name'])) {
    chunk_response(422, ['ok' => false, 'message' => '未接收到上传分片。']);
}
if ((int) ($chunk['size'] ?? 0) < 1 || (int) $chunk['size'] > MAX_CHUNK_BYTES) {
    chunk_response(413, ['ok' => false, 'message' => '单个分片不能超过 ' . (int) (MAX_CHUNK_BYTES / 1048576) . ' MB。']);
}

$chunkRoot = STORAGE_DIR . '/chunks/' . $uploadId;
cleanup_expired_chunk_uploads();
ensure_directory($chunkRoot);
$partPath = $chunkRoot . DIRECTORY_SEPARATOR . sprintf('%05d.part', $index);
if (is_file($partPath)) @unlink($partPath);
if (!move_uploaded_file($chunk['tmp_name'], $partPath)) {
    chunk_response(500, ['ok' => false, 'message' => 'Unable to store upload chunk.']);
}
if ($index !== $total - 1) {
    chunk_response(200, ['ok' => true, 'complete' => false, 'progress' => (int) floor((($index + 1) / $total) * 100)]);
}

$lock = fopen(STORAGE_DIR . '/chunks/.finalize.lock', 'c+');
if ($lock === false || !flock($lock, LOCK_EX)) {
    chunk_response(500, ['ok' => false, 'message' => 'Unable to finalize upload.']);
}
$responseStatus = 422;
$responsePayload = ['ok' => false, 'message' => 'Unable to finalize upload.'];
$cleanup = false;
try {
    for ($part = 0; $part < $total; $part++) {
        if (!is_file($chunkRoot . DIRECTORY_SEPARATOR . sprintf('%05d.part', $part))) {
            throw new RuntimeException('Upload is incomplete.', 409);
        }
    }
    $assembled = $chunkRoot . DIRECTORY_SEPARATOR . 'assembled.tmp';
    $output = fopen($assembled, 'wb');
    if ($output === false) {
        throw new RuntimeException('Unable to assemble upload.');
    }
    $written = 0;
    for ($part = 0; $part < $total; $part++) {
        $input = fopen($chunkRoot . DIRECTORY_SEPARATOR . sprintf('%05d.part', $part), 'rb');
        $copied = $input === false ? false : stream_copy_to_stream($input, $output);
        if ($input === false || $copied === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            fclose($output);
            throw new RuntimeException('Unable to assemble upload.');
        }
        fclose($input);
        $written += $copied;
        if ($written > MAX_UPLOAD_BYTES) {
            fclose($output);
            throw new RuntimeException('Image exceeds the 50 MB upload limit.', 413);
        }
    }
    fclose($output);
    $size = filesize($assembled);
    $dimensions = @getimagesize($assembled);
    $mime = detect_image_mime($assembled);
    if ($size === false || $size < 1 || $mime === null || $dimensions === false) {
        throw new RuntimeException('图片格式不支持、文件已损坏或无法读取图片尺寸。');
    }
    assert_safe_image_dimensions($dimensions);
    $device = (int) $dimensions[1] > (int) $dimensions[0] ? 'mobile' : 'pc';
    $requestedDevice = (string) ($_POST['device'] ?? 'auto');
    if (in_array($requestedDevice, ['pc', 'mobile'], true)) $device = $requestedDevice;
    ensure_category_endpoint($device, $category);
    $targetDirectory = category_path($device, $category);
    $filename = original_upload_filename((string) ($chunk['name'] ?? ''), ALLOWED_IMAGE_MIME_TYPES[$mime]);
    $conflictAction = (string) ($_POST['conflict_action'] ?? '');
    $manualFilename = trim((string) ($_POST['renamed_filename'] ?? ''));
    $conflictPending = false;
    $existingFilename = existing_upload_filename($targetDirectory, $filename);
    if ($conflictAction === 'skip') {
        @unlink($assembled);
        for ($part = 0; $part < $total; $part++) @unlink($chunkRoot . DIRECTORY_SEPARATOR . sprintf('%05d.part', $part));
        $cleanup = true;
        $responseStatus = 200;
        $responsePayload = ['ok' => true, 'complete' => true, 'skipped' => true, 'progress' => 100, 'message' => '已保留图库中的图片。'];
    } elseif ($existingFilename !== null) {
        if ($conflictAction === 'append') {
            $filename = unique_upload_filename($targetDirectory, $filename);
        } elseif ($conflictAction === 'replace') {
            $filename = $existingFilename;
        } elseif ($conflictAction === 'rename' && $manualFilename !== '') {
            $filename = original_upload_filename($manualFilename, ALLOWED_IMAGE_MIME_TYPES[$mime]);
            if (existing_upload_filename($targetDirectory, $filename) !== null) {
                $conflictPending = true;
            }
        } else {
            $filename = $existingFilename;
            $conflictPending = true;
        }
    }
    if ($conflictPending) {
        $conflictRelative = $category . '/' . $filename;
        $responseStatus = 409;
        $responsePayload = ['ok' => false, 'conflict' => true, 'filename' => $filename, 'server_image_url' => image_url($device, $conflictRelative), 'message' => '所选分类中已存在同名图片，请选择处理方式。'];
    } elseif ($conflictAction !== 'skip') {
        // Serialize publishing across concurrent uploads (including regular
        // form uploads) so the same filename cannot be published twice.
        $publishLock = fopen(STORAGE_DIR . '/uploads.lock', 'c+');
        if ($publishLock === false || !flock($publishLock, LOCK_EX)) {
            throw new RuntimeException('上传服务繁忙，请稍后重试。');
        }
        try {
            $existingFilename = existing_upload_filename($targetDirectory, $filename);
            if ($existingFilename !== null && $conflictAction === 'append') {
                $filename = unique_upload_filename($targetDirectory, $filename);
                $existingFilename = null;
            }
            if ($existingFilename !== null && $conflictAction === 'replace') {
                $filename = $existingFilename;
                $existingPath = $targetDirectory . DIRECTORY_SEPARATOR . $existingFilename;
                $replaceBackup = $existingPath . '.replace-' . $uploadId;
                if (!rename($existingPath, $replaceBackup)) throw new RuntimeException('无法备份同名图片，请检查图库目录权限。');
                if (!rename($assembled, $existingPath)) {
                    @rename($replaceBackup, $existingPath);
                    throw new RuntimeException('无法替换同名图片，原图片已恢复。');
                }
            } elseif ($existingFilename !== null) {
                $filename = $existingFilename;
                $conflictPending = true;
                $conflictAction = 'conflict';
            } else {
                if (!rename($assembled, $targetDirectory . DIRECTORY_SEPARATOR . $filename)) {
                    throw new RuntimeException('Unable to publish image.');
                }
            }
        } finally {
            flock($publishLock, LOCK_UN);
            fclose($publishLock);
        }
        if ($conflictAction === 'conflict') {
            $conflictRelative = $category . '/' . $filename;
            $responseStatus = 409;
            $responsePayload = ['ok' => false, 'conflict' => true, 'filename' => $filename, 'server_image_url' => image_url($device, $conflictRelative), 'message' => '所选分类中已存在同名图片，请选择处理方式。'];
        } else {
            if (!empty($replaceBackup) && is_file($replaceBackup)) {
                @unlink($replaceBackup);
                $oldThumbnail = thumbnail_path($device, $category . '/' . $filename);
                if (is_file($oldThumbnail)) @unlink($oldThumbnail);
            }
            for ($part = 0; $part < $total; $part++) {
                @unlink($chunkRoot . DIRECTORY_SEPARATOR . sprintf('%05d.part', $part));
            }
            $cleanup = true;
            $responseStatus = 200;
            $relative = $category . '/' . $filename;
            record_upload_audit_safely($device, $relative, (int) $dimensions[0], (int) $dimensions[1], max(0, (int) filesize($targetDirectory . DIRECTORY_SEPARATOR . $filename)), 'chunk');
            invalidate_image_index();
            $responsePayload = ['ok' => true, 'complete' => true, 'progress' => 100, 'device' => $device, 'relative' => $relative, 'category' => $category];
        }
    }
} catch (Throwable $exception) {
    $responseStatus = $exception->getCode() === 409 ? 409 : 422;
    $responsePayload = ['ok' => false, 'message' => $exception->getMessage()];
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
if ($cleanup) {
    @rmdir($chunkRoot);
}
chunk_response($responseStatus, $responsePayload);
