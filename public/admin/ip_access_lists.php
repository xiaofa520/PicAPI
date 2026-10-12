<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/settings_runtime.php';

initialize_storage();
require_admin();

try {
    $lists = ip_access_control_lists();
    $loginBlacklist = array_values(array_filter($lists['blacklist'], static fn(array $entry): bool => in_array((string) ($entry['reason'] ?? ''), ['login', 'manual_login'], true)));
    $requestBlacklist = array_values(array_filter($lists['blacklist'], static fn(array $entry): bool => !in_array((string) ($entry['reason'] ?? ''), ['login', 'manual_login'], true)));
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'request_blacklist' => $requestBlacklist,
        'login_blacklist' => $loginBlacklist,
        'whitelist' => $lists['whitelist'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => '无法刷新 IP 管控列表。'], JSON_UNESCAPED_UNICODE);
}
