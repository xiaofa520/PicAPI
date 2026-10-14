<?php
declare(strict_types=1);
require_once __DIR__ . '/image_runtime.php';

function default_category_request_alias(): string
{
    return (string) (database_document('default-category-alias')['alias'] ?? 'default');
}

function save_default_category(string $name, string $alias): void
{
    $name = trim($name); $alias = strtolower(trim($alias));
    if (!valid_category_name($name) || !valid_category($alias) || category_display_name_exists($name, 'default')) throw new InvalidArgumentException('分类名称或请求别名无效。');
    if (in_array($alias, ['json','url','image'], true)) throw new InvalidArgumentException('请求别名不能使用 json、url 或 image。');
    if ($alias !== 'default' && in_array($alias, all_categories(), true)) throw new InvalidArgumentException('请求别名已被使用。');
    if (!save_database_document('default-category-alias', ['alias' => $alias])) throw new RuntimeException('无法保存请求别名。');
    $metadata = category_metadata();
    $metadata['default'] = ['name' => $name, 'created_at' => $metadata['default']['created_at'] ?? gmdate('c')];
    save_category_metadata($metadata);
}

function media_items(array $keys): array
{
    if (!$keys || count($keys) > 10000) throw new InvalidArgumentException('请选择 1–10000 张图片。');
    $items = [];
    foreach (array_unique($keys) as $key) {
        if (!is_string($key)) throw new InvalidArgumentException('图片标识无效。');
        [$device, $relative] = array_pad(explode('|', $key, 2), 2, '');
        if (!in_array($device, ['pc', 'mobile'], true) || !valid_relative_image_path($relative)) throw new InvalidArgumentException('图片标识无效。');
        $root = $device === 'pc' ? IMAGE_PC_DIR : IMAGE_MOBILE_DIR;
        $path = realpath($root . '/' . $relative); $base = realpath($root);
        if (!$path || !$base || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path) || is_link($root . '/' . $relative) || !has_supported_image_extension($path)) throw new InvalidArgumentException('图片不存在或路径无效。');
        $items[] = compact('device', 'relative', 'path');
    }
    return $items;
}

function move_media_items(array $keys, string $category): void
{
    if (!in_array($category, all_categories(), true)) throw new InvalidArgumentException('目标分类不存在。');
    $items = media_items($keys); $moves = []; $destinations = [];
    foreach ($items as $item) {
        ensure_category_endpoint($item['device'], $category);
        $destination = category_path($item['device'], $category) . DIRECTORY_SEPARATOR . basename($item['relative']);
        if (realpath($destination) === $item['path']) continue;
        if (file_exists($destination) || isset($destinations[$destination])) throw new InvalidArgumentException('目标分类存在同名图片，未移动任何文件。');
        $destinations[$destination] = true; $moves[] = $item + ['destination' => $destination];
    }
    $completed = [];
    try {
        foreach ($moves as $item) {
            if (!rename($item['path'], $item['destination'])) throw new RuntimeException('移动图片失败。');
            make_public_image_readable($item['destination']);
            $completed[] = $item;
        }
    } catch (Throwable $error) {
        foreach (array_reverse($completed) as $item) rename($item['destination'], $item['path']);
        invalidate_image_index(); throw $error;
    }
    foreach ($completed as $item) { $thumb = thumbnail_path($item['device'], $item['relative']); if (is_file($thumb)) @unlink($thumb); }
    invalidate_image_index();
}

function delete_category_with_images(string $category, string $confirmation): void
{
    if ($category === 'default' || !valid_category($category) || !in_array($category, all_categories(), true)) throw new InvalidArgumentException('默认图库不可删除，或分类不存在。');
    if (!hash_equals(category_display_name($category), $confirmation)) throw new InvalidArgumentException('请输入完整分类名称确认删除。');
    $directories = []; $files = [];
    foreach (['pc', 'mobile'] as $device) {
        $dir = category_path($device, $category);
        if (!is_dir($dir)) continue;
        $root = realpath($device === 'pc' ? IMAGE_PC_DIR : IMAGE_MOBILE_DIR); $resolved = realpath($dir);
        if (!$root || !$resolved || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR) || is_link($dir)) throw new RuntimeException('分类路径无效。');
        foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isLink() || !$file->isFile() || ($file->getFilename() !== 'index.php' && !has_supported_image_extension($file->getFilename()))) throw new RuntimeException('分类包含未知文件或子目录，请先检查后再删除。');
            $files[] = ['path' => $file->getPathname(), 'thumbnail' => $file->getFilename() === 'index.php' ? null : thumbnail_path($device, $category . '/' . $file->getFilename())];
        }
        $directories[] = $dir;
    }
    foreach ($files as $file) {
        if (!unlink($file['path'])) { invalidate_image_index(); throw new RuntimeException('部分文件无法删除，请检查权限后重试。'); }
        if ($file['thumbnail'] && is_file($file['thumbnail'])) @unlink($file['thumbnail']);
    }
    foreach ($directories as $dir) if (!rmdir($dir)) throw new RuntimeException('分类目录无法删除。');
    $metadata = category_metadata(); unset($metadata[$category]); save_category_metadata($metadata); invalidate_image_index();
    update_api_category_reference($category, '');
}

function update_api_category_reference(string $old, string $new): void
{
    require_once __DIR__ . '/api_config_runtime.php';
    $config = api_configuration(); $changed = false;
    foreach ($config as &$channel) if (($channel['category'] ?? '') === $old) { $channel['category'] = $new; $changed = true; }
    unset($channel);
    if ($changed) save_api_configuration($config);
}
