<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/setup_runtime.php';

initialize_storage();
enforce_admin_transport_policy();
start_secure_session();
send_admin_security_headers();
header('Content-Type: application/json; charset=utf-8');

if (admin_is_configured()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '系统已经初始化。'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (installation_config() !== null && !installation_sqlite_admin_table_missing()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => '系统已经初始化，数据库当前不可用。'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => '请求方式不受支持。'], JSON_UNESCAPED_UNICODE);
    exit;
}

verify_csrf();
try {
    require_setup_draft($_POST);
    require_setup_code($_POST);
    echo json_encode([
        'ok' => true,
        'setupCodeVerified' => setup_code_is_verified(),
        'message' => '验证码正确，已自动验证并删除验证码文件。',
    ], JSON_UNESCAPED_UNICODE);
} catch (SetupDraftExpiredException $exception) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'setupCodeVerified' => setup_code_is_verified(), 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'setupCodeVerified' => setup_code_is_verified(), 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    error_log('Setup code verification failed: ' . $exception->getMessage());
    echo json_encode(['ok' => false, 'setupCodeVerified' => setup_code_is_verified(), 'message' => '验证码校验暂时无法完成，请检查项目目录权限后重试。'], JSON_UNESCAPED_UNICODE);
}
