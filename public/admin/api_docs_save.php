<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/api_docs_runtime.php';
require_once __DIR__ . '/../../src/settings_runtime.php';

initialize_storage();
require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') abort_request(405, 'Method not allowed.');
verify_csrf();
header('Content-Type: application/json; charset=utf-8');
try {
    save_api_docs_configuration($_POST);
    echo json_encode(['ok' => true, 'message' => '文档设置已保存并立即应用。'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
