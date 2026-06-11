<?php

require_once __DIR__ . '/../lib/image_runtime.php';
require_once __DIR__ . '/../lib/public_api_runtime.php';

const IMAGE_FOLDER = 'img';
const LOG_FOLDER = __DIR__ . '/log';
const STAT_LOG_FILE = LOG_FOLDER . '/count.log';
const COUNT_LOCK_FILE = LOG_FOLDER . '/count.lock';

image_api_handle_favicon_request();

$projectRoot = dirname(__DIR__);
$settings = public_api_read_settings($projectRoot);
$requestedCategory = public_api_requested_category($settings);
$requestedType = public_api_requested_type($settings);
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestedCategory !== null) {
    if (!public_api_validate_category_name($requestedCategory)) {
        public_api_json_error(400, 'Invalid category name.');
    }

    if (!in_array($requestedCategory, public_api_read_categories($projectRoot), true)) {
        public_api_json_error(404, 'Category not found.', ['category' => $requestedCategory]);
    }

    $catalog = public_api_load_category_catalog($projectRoot, 'm', $requestedCategory);
    if ($catalog === []) {
        public_api_json_error(404, 'No images available in this category.', ['category' => $requestedCategory]);
    }

    $selectedImage = public_api_pick_category_image($projectRoot, 'm', $requestedCategory, $catalog, $settings);
    image_api_update_call_count(LOG_FOLDER, STAT_LOG_FILE, COUNT_LOCK_FILE);
    public_api_emit_selected_image(
        $selectedImage,
        public_api_base_url_for_path(''),
        LOG_FOLDER,
        $requestMethod,
        $requestedType,
        $settings,
        'm'
    );
}

$catalog = image_api_find_images(__DIR__ . '/' . IMAGE_FOLDER, IMAGE_FOLDER);
if (empty($catalog)) {
    header('HTTP/1.1 503 Service Unavailable');
    echo 'No images available.';
    exit;
}

$selectedImage = image_api_pick_random_image($catalog);

try {
    $imageInfo = image_api_get_image_info($selectedImage['fs_path']);
} catch (RuntimeException $exception) {
    header('HTTP/1.1 500 Internal Server Error');
    echo $exception->getMessage();
    exit;
}

$publicPath = $selectedImage['public_path'];
$baseUrl = public_api_base_url_for_path('m');

image_api_update_call_count(LOG_FOLDER, STAT_LOG_FILE, COUNT_LOCK_FILE);

if (public_api_is_json_request($requestedType, $settings)) {
    public_api_output_json([
        ...$selectedImage,
        'category' => dirname($publicPath) !== '.' ? dirname($publicPath) : 'uncategorized',
        'library' => 'm',
        'public_path' => $publicPath,
    ], $imageInfo, $baseUrl, $settings, 'm');
    image_api_write_log(LOG_FOLDER, $publicPath, 200, $requestMethod, 'json');
    exit;
}

if ($requestedType === 'url') {
    header('Content-Type: text/plain; charset=utf-8');
    echo rtrim($baseUrl, '/') . '/' . image_api_encode_public_path($publicPath);
    image_api_write_log(LOG_FOLDER, $publicPath, 200, $requestMethod, 'url');
    exit;
}

header('Content-Type: ' . image_api_detect_mime_type($imageInfo['path']));
header('Content-Length: ' . $imageInfo['size']);
readfile($imageInfo['path']);
image_api_write_log(LOG_FOLDER, $publicPath, 200, $requestMethod, 'image');
