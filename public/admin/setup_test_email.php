<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/smtp_runtime.php';
require_once __DIR__ . '/../../src/setup_runtime.php';

initialize_storage();
enforce_admin_transport_policy();
start_secure_session();
if (admin_is_configured()) abort_request(403, '初始化已经完成。');
if (installation_config() !== null && !installation_sqlite_admin_table_missing()) abort_request(503, admin_database_unavailable_message());
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') abort_request(405, '请求方式不正确。');
verify_csrf();
header('Content-Type: application/json; charset=utf-8');
try {
    require_setup_code_verified();
    require_setup_draft($_POST);
    if (!smtp_is_configured($_POST)) throw new RuntimeException('请先填写 SMTP 配置。');
    smtp_test_message($_POST, trim((string) ($_POST['admin_email'] ?? '')));
    require_setup_draft($_POST);
    echo json_encode(['ok' => true, 'message' => '测试邮件已发送至管理员邮箱。', 'setupCodeVerified' => setup_code_is_verified()], JSON_UNESCAPED_UNICODE);
} catch (SetupDraftExpiredException $exception) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage(), 'setupCodeVerified' => setup_code_is_verified()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'message' => '邮箱验证失败，请检查 SMTP 地址、端口、账号、授权密码和加密方式。', 'setupCodeVerified' => setup_code_is_verified()], JSON_UNESCAPED_UNICODE);
}
