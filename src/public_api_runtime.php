<?php
declare(strict_types=1);

require_once __DIR__ . '/api_config_runtime.php';

function api_device_type(?string $forcedDevice = null): string
{
    if ($forcedDevice !== null) {
        return $forcedDevice;
    }
    $requested = strtolower((string) ($_GET['device'] ?? 'auto'));
    if (in_array($requested, ['pc', 'mobile', 'm'], true)) {
        return $requested === 'pc' ? 'pc' : 'mobile';
    }
    if (isset($_GET['screen_width'])) {
        return (int) $_GET['screen_width'] <= (int) site_settings()['adaptive_breakpoint'] ? 'mobile' : 'pc';
    }
    return preg_match('/(android|iphone|ipad|ipod|blackberry|windows phone)/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))
        ? 'mobile'
        : 'pc';
}

function api_is_favicon_request(): bool
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    return is_string($path) && strtolower(basename($path)) === 'favicon.ico';
}

function output_public_ip_block(int $until, bool $permanent = false): void
{
    $remaining = max(0, $until - time());
    $unblockAt = $permanent ? null : (new DateTimeImmutable('@' . $until))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
    $type = strtolower((string) ($_GET['type'] ?? 'image'));
    http_response_code(429);
    if (!$permanent) header('Retry-After: ' . max(1, $remaining));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $messageZh = $permanent ? '当前 IP 已被永久封禁。' : '当前 IP 已被临时封禁，解封时间：' . $unblockAt . '。';
    $messageEn = $permanent ? 'This IP is permanently blocked.' : 'This IP is temporarily blocked. Unblock time: ' . $unblockAt . '.';
    if ($type === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
            echo json_encode(['status' => 429, 'message_zh' => $messageZh, 'message_en' => $messageEn, 'permanent' => $permanent, 'unblock_at' => $unblockAt, 'retry_after' => $permanent ? null : $remaining], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') echo $messageZh . "\n" . $messageEn;
    exit;
}

/** @return never */
function run_public_api(?string $forcedDevice = null, ?string $forcedCategory = null, ?string $configuredChannel = null): void
{
    // Browser tab-icon probes must not become random-image API calls or logs.
    if (api_is_favicon_request()) {
        try {
            initialize_storage();
            if (output_site_favicon()) exit;
        } catch (Throwable) {
            // Preserve the empty favicon response when optional site branding is unavailable.
        }
        http_response_code(204);
        header('Cache-Control: public, max-age=86400');
        exit;
    }
    try {
        initialize_storage();
    } catch (RuntimeException) {
        abort_request(500, 'Service storage is unavailable.');
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        abort_request(405, 'Method not allowed.');
    }
    $ip = visitor_ip();
    try {
        $access = public_ip_access_status($ip);
        if (!empty($access['blocked'])) output_public_ip_block((int) $access['until'], !empty($access['permanent']));
        $access = record_public_ip_request($ip);
        if (!empty($access['blocked'])) {
            if (!empty($access['newly_blocked'])) {
                try {
                    $policy = site_settings();
                    $window = security_policy_active() ? 60 : (int) $policy['request_window_seconds'];
                    send_site_notification('IP 自动封禁提醒', '单个 IP 请求次数超限，已按当前访问策略限制访问。', [
                        'ip' => $ip,
                        'detail' => $window . ' 秒内请求 ' . (int) $access['count'] . ' 次；解封时间：' . (!empty($access['permanent']) ? '永久封禁' : (new DateTimeImmutable('@' . (int) $access['until']))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s')) . '。',
                    ]);
                } catch (Throwable) {
                    // Mail delivery must not change the access-control decision.
                }
            }
            output_public_ip_block((int) $access['until'], !empty($access['permanent']));
        }
    } catch (Throwable) {
        // The public API must remain available if optional access-control storage fails.
    }
    $requestedType = strtolower((string) ($_GET['type'] ?? 'image'));
    $category = $forcedCategory;
    $requestedCategory = strtolower(trim((string) ($_GET['category'] ?? $_GET['folder'] ?? '')));
    require_once __DIR__ . '/media_management.php';
    $defaultAlias = default_category_request_alias();
    if ($requestedCategory === $defaultAlias) $requestedCategory = 'default';
    if ($category === null && $requestedType === $defaultAlias && !in_array($requestedType, ['image', 'json', 'url'], true)) $category = 'default';
    $categories = array_fill_keys(all_categories(), true);
    if ($category === null && $requestedCategory !== '' && valid_category($requestedCategory) && isset($categories[$requestedCategory])) {
        $category = $requestedCategory;
    }
    // `?type=folder-name` is a concise adaptive-category request. JSON and URL
    // output remain available through `?category=folder-name&type=json|url`.
    if ($category === null && !in_array($requestedType, ['image', 'json', 'url'], true)
        && valid_category($requestedType) && isset($categories[$requestedType])) {
        $category = $requestedType;
        $requestedType = 'image';
    }
    $device = api_device_type($forcedDevice);
    $channel = $configuredChannel ?? ($forcedDevice === 'pc' ? 'pc' : ($forcedDevice === 'mobile' ? 'mobile' : 'auto'));
    $config = api_configuration();
    $rules = $config[$channel] ?? $config['auto'];
    if ($category === null && ($rules['category'] ?? '') !== '') $category = (string) $rules['category'];
    $type = $requestedType;
    if (!in_array($type, ['json', 'url'], true)) {
        $type = 'image';
    }
    $image = configured_api_image($device, $category, (string) ($rules['strategy'] ?? 'random'), $channel);
    if ($image === null) {
        try { increment_call_count($channel); } catch (Throwable) {
            // An unavailable counter must not prevent the failed request from being logged.
        }
        try { write_access_log('（无可用图片）', 503, $type, 0, $device, $channel); } catch (Throwable) {
            // Keep the service's 503 response even if optional log storage is unavailable.
        }
        abort_request(503, 'No images are currently available.');
    }
    $allowed = $type === 'image' ? !empty($rules['direct']) : !empty($rules[$type]);
    if (!$allowed) {
        http_response_code(403);
        $messages = [
            'image' => ['zh' => '图片直接输出已被管理员关闭。', 'en' => 'Direct image output has been disabled by the administrator.'],
            'url' => ['zh' => 'URL 数据输出已被管理员关闭。', 'en' => 'URL output has been disabled by the administrator.'],
            'json' => ['zh' => 'JSON 数据输出已被管理员关闭。', 'en' => 'JSON output has been disabled by the administrator.'],
        ];
        $message = $messages[$type];
        if ($type === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            if ($method !== 'HEAD') echo json_encode(['status' => 403, 'message_zh' => $message['zh'], 'message_en' => $message['en']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            if ($method !== 'HEAD') echo $message['zh'] . "\n" . $message['en'];
        }
        exit;
    }
    try {
        // Count the requested entry point, not the image pool selected by auto routing.
        increment_call_count($channel);
        write_access_log($image['relative'], 200, $type, $image['size'], $image['device'], $channel);
    } catch (Throwable) {
        // Delivery remains successful even when non-essential statistics storage is unavailable.
    }
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if ($type === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        if ($method !== 'HEAD') {
            echo json_encode(api_template_output((string) $rules['template'], $image), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    if ($type === 'url') {
        header('Content-Type: text/plain; charset=utf-8');
        if ($method !== 'HEAD') {
            echo image_url($image['device'], $image['relative']);
        }
        exit;
    }
    header('Content-Type: ' . $image['mime']);
    header('Content-Length: ' . $image['size']);
    if ($method !== 'HEAD') {
        readfile($image['path']);
    }
    exit;
}
