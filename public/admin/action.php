<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/media_management.php';
require_once __DIR__ . '/../../src/settings_runtime.php';

initialize_storage();
require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    abort_request(405, 'Method not allowed.');
}
verify_csrf();
$action = (string) ($_POST['action'] ?? '');

function action_redirect(string $message, string $section = 'gallery', string $type = 'success'): never
{
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        http_response_code($type === 'error' ? 422 : 200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $type !== 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash('notice', $message);
    flash('notice_type', $type === 'error' ? 'error' : 'success');
    $pages = [
        'stats' => 'dashboard.php',
        'upload' => 'upload.php',
        'gallery' => 'media.php',
        'folders' => 'folders.php',
        'logs' => 'logs.php',
    ];
    redirect($pages[$section] ?? 'dashboard.php');
}

function uploaded_image_extension(array $upload): string
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !isset($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('请选择一个有效的上传文件。');
    }
    if ((int) ($upload['size'] ?? 0) <= 0) {
        throw new RuntimeException('图片文件不能为空。');
    }
    if ((int) $upload['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('图片大小不能超过 ' . (int) (MAX_UPLOAD_BYTES / 1048576) . ' MB。');
    }
    $mime = detect_image_mime($upload['tmp_name']);
    if ($mime === null || @getimagesize($upload['tmp_name']) === false) {
        throw new RuntimeException('只允许 JPEG、PNG、GIF 或 WebP 图片。');
    }
    return ALLOWED_IMAGE_MIME_TYPES[$mime];
}

/** @return list<array{name:mixed,type:mixed,tmp_name:mixed,error:mixed,size:mixed}> */
function uploaded_image_entries(array $field): array
{
    if (!is_array($field['name'] ?? null)) {
        return $field === [] ? [] : [$field];
    }

    $entries = [];
    foreach (array_keys($field['name']) as $key) {
        $entries[] = [
            'name' => $field['name'][$key] ?? '',
            'type' => $field['type'][$key] ?? '',
            'tmp_name' => $field['tmp_name'][$key] ?? '',
            'error' => $field['error'][$key] ?? UPLOAD_ERR_NO_FILE,
            'size' => $field['size'][$key] ?? 0,
        ];
    }
    return $entries;
}

function publish_uploaded_image(array $upload, string $category): string
{
    $extension = uploaded_image_extension($upload);
    $dimensions = getimagesize((string) $upload['tmp_name']);
    if ($dimensions === false) {
        throw new RuntimeException('Unable to read image dimensions.');
    }
    assert_safe_image_dimensions($dimensions);

    $device = (int) $dimensions[1] > (int) $dimensions[0] ? 'mobile' : 'pc';
    if ($category === '') {
        $directory = category_path($device);
        ensure_directory($directory);
    } else {
        ensure_category_endpoint($device, $category);
        $directory = category_path($device, $category);
    }
    $filename = original_upload_filename((string) ($upload['name'] ?? ''), $extension);
    $destination = $directory . DIRECTORY_SEPARATOR . $filename;
    if (is_file($destination)) {
        throw new RuntimeException('A file with this name already exists in the selected category.', 409);
    }
    if (!move_uploaded_file((string) $upload['tmp_name'], $destination) && !copy((string) $upload['tmp_name'], $destination)) {
        throw new RuntimeException('Unable to store uploaded image.');
    }
    $relative = $category === '' ? $filename : $category . '/' . $filename;
    record_upload_audit_safely($device, $relative, (int) $dimensions[0], (int) $dimensions[1], max(0, (int) filesize($destination)), 'form');
    return $device;
}

try {
    if ($action === 'upload') {
        $category = trim((string) ($_POST['category'] ?? ''));
        if ($category !== '' && (!valid_category($category) || !in_array($category, all_categories(), true))) {
            throw new RuntimeException('所选分类不存在或已被删除，请重新选择分类。');
        }
        // Serialize the publish step: the existence check and the file write
        // must not interleave between concurrent uploads of the same name.
        $publishLock = fopen(STORAGE_DIR . '/uploads.lock', 'c+');
        if ($publishLock === false || !flock($publishLock, LOCK_EX)) {
            throw new RuntimeException('上传服务繁忙，请稍后重试。');
        }
        try {
            if (is_array($_FILES['image']['name'] ?? null)) {
                // Skip untouched multi-file slots (UPLOAD_ERR_NO_FILE) instead
                // of failing the whole batch because of an empty input slot.
                $uploads = array_values(array_filter(
                    uploaded_image_entries($_FILES['image']),
                    static fn(array $entry): bool => ($entry['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
                ));
                if ($uploads === []) {
                    throw new RuntimeException('Please choose at least one image.');
                }
                if (count($uploads) > MAX_UPLOAD_FILES_PER_REQUEST) {
                    throw new RuntimeException('Too many images in one upload.', 413);
                }
                $stored = 0;
                foreach ($uploads as $upload) {
                    publish_uploaded_image($upload, $category);
                    $stored++;
                }
                invalidate_image_index();
                action_redirect("Uploaded {$stored} image(s).", 'upload');
            }
            $extension = uploaded_image_extension($_FILES['image'] ?? []);
            $dimensions = getimagesize($_FILES['image']['tmp_name']);
            if ($dimensions === false) {
                throw new RuntimeException('无法读取图片尺寸。');
            }
            assert_safe_image_dimensions($dimensions);
            // Portrait images go to the mobile pool; landscape and square images go to PC.
            $devices = [(int) $dimensions[1] > (int) $dimensions[0] ? 'mobile' : 'pc'];
            $moved = 0;
            foreach ($devices as $device) {
                if ($category === '') {
                    $directory = category_path($device);
                    ensure_directory($directory);
                } else {
                    ensure_category_endpoint($device, $category);
                    $directory = category_path($device, $category);
                }
                $filename = original_upload_filename((string) ($_FILES['image']['name'] ?? ''), $extension);
                if (is_file($directory . DIRECTORY_SEPARATOR . $filename)) {
                    throw new RuntimeException('A file with this name already exists in the selected category.', 409);
                }
                if (!copy($_FILES['image']['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
                    throw new RuntimeException('图片写入失败。');
                }
                $relative = $category === '' ? $filename : $category . '/' . $filename;
                record_upload_audit_safely($device, $relative, (int) $dimensions[0], (int) $dimensions[1], max(0, (int) filesize($directory . DIRECTORY_SEPARATOR . $filename)), 'form');
                $moved++;
            }
            invalidate_image_index();
            action_redirect("已上传至 {$moved} 个终端目录。", 'upload');
        } finally {
            flock($publishLock, LOCK_UN);
            fclose($publishLock);
        }
    }

    if ($action === 'bulk_delete') {
        $items = $_POST['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new RuntimeException('请选择至少一张图片。');
        }
        $deleted = 0;
        foreach (array_unique(array_map('strval', $items)) as $item) {
            [$device, $relative] = array_pad(explode('|', $item, 2), 2, '');
            $relative = ltrim(str_replace('\\', '/', $relative), '/');
            if (!in_array($device, ['pc', 'mobile'], true) || !valid_relative_image_path($relative)) {
                continue;
            }
            $root = $device === 'mobile' ? IMAGE_MOBILE_DIR : IMAGE_PC_DIR;
            $path = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
            $realRoot = realpath($root);
            if ($path === false || $realRoot === false || !str_starts_with($path, $realRoot . DIRECTORY_SEPARATOR) || !is_file($path) || !unlink($path)) {
                continue;
            }
            $thumbnail = thumbnail_path($device, $relative);
            if (is_file($thumbnail)) {
                @unlink($thumbnail);
            }
            $deleted++;
        }
        if ($deleted > 0) invalidate_image_index();
        action_redirect("已删除 {$deleted} 张图片。");
    }

    if ($action === 'delete_image') {
        $device = (string) ($_POST['device'] ?? '');
        $relative = ltrim(str_replace('\\', '/', (string) ($_POST['relative'] ?? '')), '/');
        if (!in_array($device, ['pc', 'mobile'], true) || !valid_relative_image_path($relative)) {
            throw new RuntimeException('图片标识不合法。');
        }
        $root = $device === 'mobile' ? IMAGE_MOBILE_DIR : IMAGE_PC_DIR;
        $path = realpath($root . DIRECTORY_SEPARATOR . $relative);
        $realRoot = realpath($root);
        if ($path === false || $realRoot === false || !str_starts_with($path, $realRoot . DIRECTORY_SEPARATOR) || !is_file($path) || !unlink($path)) {
            throw new RuntimeException('图片不存在或无法删除。');
        }
        $thumbnail = thumbnail_path($device, $relative);
        if (is_file($thumbnail)) {
            @unlink($thumbnail);
        }
        invalidate_image_index();
        action_redirect('图片已删除。');
    }

    if ($action === 'rename_image') {
        $device = (string) ($_POST['device'] ?? '');
        $relative = ltrim(str_replace('\\', '/', (string) ($_POST['relative'] ?? '')), '/');
        $newName = trim((string) ($_POST['filename'] ?? ''));
        if (!in_array($device, ['pc', 'mobile'], true) || !valid_relative_image_path($relative)) throw new RuntimeException('图片标识不合法。');
        $root = $device === 'mobile' ? IMAGE_MOBILE_DIR : IMAGE_PC_DIR;
        $source = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        $realRoot = realpath($root);
        if ($source === false || $realRoot === false || !str_starts_with($source, $realRoot . DIRECTORY_SEPARATOR) || !is_file($source)) throw new RuntimeException('图片不存在。');
        $extension = pathinfo($relative, PATHINFO_EXTENSION);
        $filename = original_upload_filename($newName, $extension);
        if (strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)) !== strtolower($extension)) throw new RuntimeException('不能修改图片文件扩展名。');
        $newRelative = (dirname($relative) === '.' ? '' : dirname($relative) . '/') . $filename;
        $destination = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newRelative);
        if (is_file($destination)) throw new RuntimeException('目标文件名已存在，请换一个名称。');
        if (!rename($source, $destination)) throw new RuntimeException('图片重命名失败。');
        $oldThumbnail = thumbnail_path($device, $relative);
        $newThumbnail = thumbnail_path($device, $newRelative);
        if (is_file($oldThumbnail)) {
            ensure_directory(dirname($newThumbnail));
            @rename($oldThumbnail, $newThumbnail);
        }
        invalidate_image_index();
        action_redirect('图片名称已更新。');
    }

    if ($action === 'move_image') {
        $device = (string) ($_POST['device'] ?? '');
        $relative = ltrim(str_replace('\\', '/', (string) ($_POST['relative'] ?? '')), '/');
        $category = trim((string) ($_POST['category'] ?? 'default'));
        if (!in_array($device, ['pc', 'mobile'], true) || !valid_relative_image_path($relative) || !in_array($category, all_categories(), true)) throw new RuntimeException('图片或目标分类不合法。');
        $root = $device === 'mobile' ? IMAGE_MOBILE_DIR : IMAGE_PC_DIR;
        $source = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        $realRoot = realpath($root);
        if ($source === false || $realRoot === false || !str_starts_with($source, $realRoot . DIRECTORY_SEPARATOR) || !is_file($source)) throw new RuntimeException('图片不存在。');
        $filename = basename($relative);
        ensure_category_endpoint($device, $category);
        $newRelative = $category . '/' . $filename;
        $destination = category_path($device, $category) . DIRECTORY_SEPARATOR . $filename;
        if (is_file($destination)) throw new RuntimeException('目标分类中已有同名图片。');
        if (!rename($source, $destination)) throw new RuntimeException('移动图片失败。');
        $oldThumbnail = thumbnail_path($device, $relative);
        $newThumbnail = thumbnail_path($device, $newRelative);
        if (is_file($oldThumbnail)) {
            ensure_directory(dirname($newThumbnail));
            @rename($oldThumbnail, $newThumbnail);
        }
        invalidate_image_index();
        action_redirect('图片已移动到目标分类。');
    }

    if ($action === 'create_category') {
        $name = trim((string) ($_POST['category_name'] ?? $_POST['category'] ?? ''));
        $category = strtolower(trim((string) ($_POST['request_alias'] ?? '')));
        if ($category === '') $category = generate_category_alias();
        if ($category === 'default' || $category === default_category_request_alias()) {
            throw new RuntimeException('默认分类是内置根目录，不能重复创建。');
        }
        if (!valid_category_name($name)) {
            throw new RuntimeException('分类文件夹名称不能为空，且不能包含斜杠或控制字符。');
        }
        if (category_display_name_exists($name)) {
            throw new RuntimeException('分类文件夹名称已存在，请换一个名称。');
        }
        if (!valid_category($category)) {
            throw new RuntimeException('请求名称只能使用英文、数字、下划线或连字符，且须以英文或数字开始。');
        }
        if (in_array($category, all_categories(), true)) {
            throw new RuntimeException('该请求名称已被使用，请换一个名称。');
        }
        foreach (['pc', 'mobile'] as $device) {
            ensure_category_endpoint($device, $category);
        }
        $metadata = category_metadata();
        $metadata[$category] = ['name' => $name, 'created_at' => gmdate('c')];
        save_category_metadata($metadata);
        action_redirect('分类已创建。', 'folders');
    }

    if ($action === 'rename_category') {
        $old = trim((string) ($_POST['old_category'] ?? ''));
        $name = trim((string) ($_POST['category_name'] ?? $_POST['new_category'] ?? ''));
        $new = strtolower(trim((string) ($_POST['request_alias'] ?? $old)));
        if ($old === 'default') {
            save_default_category($name, $new);
            action_redirect('默认分类已更新。', 'folders');
        }
        if ($new === default_category_request_alias()) throw new RuntimeException('请求名称已被默认图库使用。');
        if (!valid_category($old) || !valid_category($new) || !valid_category_name($name)) {
            throw new RuntimeException('分类名称或请求名称不合法。');
        }
        if (category_display_name_exists($name, $old)) {
            throw new RuntimeException('分类文件夹名称已存在，请换一个名称。');
        }
        if ($old === $new) {
            $metadata = category_metadata();
            $metadata[$old] = ['name' => $name, 'created_at' => (string) ($metadata[$old]['created_at'] ?? gmdate('c'))];
            save_category_metadata($metadata);
            action_redirect('分类信息已更新。', 'folders');
        }
        $moves = [];
        foreach (['pc', 'mobile'] as $device) {
            $source = category_path($device, $old); $destination = category_path($device, $new);
            if (file_exists($destination) || is_link($source)) throw new RuntimeException('目标请求名称已存在，或源分类路径无效。');
            if (is_dir($source)) $moves[] = [$source, $destination];
        }
        if (!$moves) throw new RuntimeException('分类不存在。');
        $completed = [];
        try {
            foreach ($moves as [$source,$destination]) {
                if (!rename($source,$destination)) throw new RuntimeException('分类重命名失败。');
                $completed[] = [$source,$destination];
            }
        } catch (Throwable $error) {
            foreach (array_reverse($completed) as [$source,$destination]) rename($destination,$source);
            invalidate_image_index(); throw $error;
        }
        invalidate_image_index();
        $metadata = category_metadata();
        $createdAt = (string) ($metadata[$old]['created_at'] ?? gmdate('c'));
        unset($metadata[$old]);
        $metadata[$new] = ['name' => $name, 'created_at' => $createdAt];
        save_category_metadata($metadata);
        update_api_category_reference($old, $new);
        action_redirect('分类名称和请求名称已更新。', 'folders');
    }

    if ($action === 'delete_category') {
        $category = trim((string) ($_POST['category'] ?? ''));
        if ($category === 'default' || $category === default_category_request_alias()) {
            throw new RuntimeException('默认分类是内置根目录，不能删除。');
        }
        if (!valid_category($category)) {
            throw new RuntimeException('分类标识不合法。');
        }
        foreach (['pc', 'mobile'] as $device) {
            $directory = category_path($device, $category);
            if (is_dir($directory)) {
                $files = iterator_to_array(new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS));
                $nonEndpointFiles = array_filter($files, static fn(SplFileInfo $file): bool => $file->getFilename() !== 'index.php');
                if ($nonEndpointFiles !== []) {
                    throw new RuntimeException('分类包含图片或子目录，不能直接删除。');
                }
            }
        }
        foreach (['pc', 'mobile'] as $device) {
            $directory = category_path($device, $category);
            $endpoint = $directory . DIRECTORY_SEPARATOR . 'index.php';
            if (is_file($endpoint) && !unlink($endpoint)) {
                throw new RuntimeException('分类请求入口无法删除。');
            }
            if (is_dir($directory) && !rmdir($directory)) {
                throw new RuntimeException('分类删除失败。');
            }
        }
        $metadata = category_metadata();
        unset($metadata[$category]);
        save_category_metadata($metadata);
        action_redirect('空分类已删除。', 'folders');
    }

    throw new RuntimeException('未知操作。');
} catch (Throwable $exception) {
    $section = match ($action) {
        'upload' => 'upload',
        'create_category', 'rename_category', 'delete_category' => 'folders',
        'rename_image', 'move_image' => 'gallery',
        default => 'gallery',
    };
    action_redirect($exception->getMessage(), $section, 'error');
}
