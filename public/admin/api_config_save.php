<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/api_config_runtime.php';
require_once __DIR__ . '/../../src/settings_runtime.php';

initialize_storage();
require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') abort_request(405, 'Method not allowed.');
verify_csrf();
header('Content-Type: application/json; charset=utf-8');
try {
    $payload = json_decode((string) ($_POST['config'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) throw new InvalidArgumentException('配置数据格式不正确。');
    $channel = (string) ($_POST['channel'] ?? '');
    if ($channel === '') {
        save_api_configuration($payload);
    } else {
        save_api_configuration_channel($channel, $payload);
    }
    echo json_encode(['ok' => true, 'message' => 'API 自定义配置已保存并即时生效。'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
