<?php

require_once __DIR__ . '/common.php';

admin_require_login();
admin_require_csrf();

header('Content-Type: application/json; charset=utf-8');

function delete_media_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    delete_media_json(['ok' => false, 'message' => 'Only POST is allowed.'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
    delete_media_json(['ok' => false, 'message' => 'Invalid request payload.'], 400);
}

$deleted = 0;
$errors = [];

foreach ($payload['items'] as $item) {
    if (!is_array($item)) {
        $errors[] = 'Invalid item payload.';
        continue;
    }

    $libraryKey = (string) ($item['library'] ?? '');
    $relativePath = image_api_normalize_relative_path((string) ($item['relative_path'] ?? ''));
    if (!isset($libraryConfigs[$libraryKey]) || $relativePath === '' || str_contains($relativePath, '../')) {
        $errors[] = 'Invalid media item.';
        continue;
    }

    $resolvedPath = admin_resolve_library_file_path($libraryConfigs, $libraryKey, $relativePath);
    if ($resolvedPath === null) {
        $errors[] = 'Unable to resolve media path: ' . $relativePath;
        continue;
    }

    if (is_file($resolvedPath)) {
        if (!@unlink($resolvedPath)) {
            $errors[] = 'Failed to delete image: ' . $relativePath;
            continue;
        }

        admin_cleanup_empty_dirs($libraryConfigs[$libraryKey]['root_dir'], $resolvedPath);
    }

    admin_delete_preview_png($libraryKey, $relativePath);
    $deleted++;
}

delete_media_json([
    'ok' => $errors === [],
    'message' => $errors === [] ? '删除完成。' : '部分图片删除失败。',
    'deleted' => $deleted,
    'errors' => $errors,
], $errors === [] ? 200 : 207);
