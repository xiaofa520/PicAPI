<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/setup_runtime.php';

initialize_storage();
start_secure_session();
if (admin_is_configured()) {
    abort_request(403, 'Setup is already complete.');
}
if (installation_config() !== null) {
    abort_request(503, '系统已经初始化，数据库当前不可用。');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    abort_request(405, 'Method not allowed.');
}
verify_csrf();
header('Content-Type: application/json; charset=utf-8');
try {
    $result = prepare_setup_database($_POST);
    echo json_encode(['ok' => true, 'message' => $result['message'], 'created' => $result['created']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'message' => '数据库测试失败，请检查数据库类型、主机、端口、账号、密码及数据库驱动是否正确安装。'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
