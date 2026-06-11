<?php

require_once __DIR__ . '/lib/image_runtime.php';
require_once __DIR__ . '/lib/public_api_runtime.php';

const IMAGE_FOLDER_PC = 'pc/img';
const IMAGE_FOLDER_PHONE = 'm/img';
const LOG_FOLDER = __DIR__ . '/log';
const STAT_LOG_FILE = LOG_FOLDER . '/count.log';
const COUNT_LOCK_FILE = LOG_FOLDER . '/count.lock';

image_api_handle_favicon_request();

function get_device_type(): string
{
    if (isset($_GET['screen_width'])) {
        return ((int) $_GET['screen_width']) <= 768 ? 'phone' : 'pc';
    }

    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return preg_match('/(android|iphone|ipad|ipod|blackberry|windows phone)/i', $userAgent)
        ? 'phone'
        : 'pc';
}

$settings = public_api_read_settings(__DIR__);
$requestedCategory = public_api_requested_category($settings);
$requestedType = public_api_requested_type($settings);
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestedCategory !== null) {
    if (!public_api_validate_category_name($requestedCategory)) {
        public_api_json_error(400, 'Invalid category name.');
    }

    if (!in_array($requestedCategory, public_api_read_categories(__DIR__), true)) {
        public_api_json_error(404, 'Category not found.', ['category' => $requestedCategory]);
    }

    $catalog = public_api_load_category_catalog(__DIR__, 'root', $requestedCategory);
    if ($catalog === []) {
        public_api_json_error(404, 'No images available in this category.', ['category' => $requestedCategory]);
    }

    $selectedImage = public_api_pick_category_image(__DIR__, 'root', $requestedCategory, $catalog, $settings);
    image_api_update_call_count(LOG_FOLDER, STAT_LOG_FILE, COUNT_LOCK_FILE);
    public_api_emit_selected_image(
        $selectedImage,
        public_api_base_url_for_path(''),
        LOG_FOLDER,
        $requestMethod,
        $requestedType,
        $settings,
        'root'
    );
}

$deviceType = get_device_type();
$publicFolder = $deviceType === 'phone' ? IMAGE_FOLDER_PHONE : IMAGE_FOLDER_PC;
$absoluteFolder = __DIR__ . '/' . $publicFolder;
$catalog = image_api_find_images($absoluteFolder, $publicFolder);

if (empty($catalog)) {
    header('HTTP/1.1 503 Service Unavailable');
    echo sprintf('No images available in %s', $publicFolder);
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
$baseUrl = public_api_base_url_for_path('');

image_api_update_call_count(LOG_FOLDER, STAT_LOG_FILE, COUNT_LOCK_FILE);

if (public_api_is_json_request($requestedType, $settings)) {
    public_api_output_json([
        ...$selectedImage,
        'category' => dirname($publicPath) !== '.' ? dirname($publicPath) : 'uncategorized',
        'library' => $deviceType === 'phone' ? 'm' : 'pc',
        'public_path' => $publicPath,
    ], $imageInfo, $baseUrl, $settings, 'root');
    image_api_write_log(LOG_FOLDER, $publicPath, 200, $requestMethod, 'json');
    exit;
}

if ($requestedType === 'url') {
    header('Content-Type: text/plain; charset=utf-8');
    echo rtrim($baseUrl, '/') . '/' . image_api_encode_public_path($publicPath);
    image_api_write_log(LOG_FOLDER, $publicPath, 200, $requestMethod, 'url');
    exit;
}

header('Expires: Tue, 01 Jan 2000 00:00:00 GMT');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: ' . image_api_detect_mime_type($imageInfo['path']));
header('Content-Length: ' . $imageInfo['size']);
readfile($imageInfo['path']);
image_api_write_log(LOG_FOLDER, $publicPath, 200, $requestMethod, 'image');
