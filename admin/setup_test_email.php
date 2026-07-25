<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/smtp_runtime.php';
require_once __DIR__ . '/../lib/app.php';

initialize_storage();
start_secure_session();
if (admin_is_configured()) abort_request(403, '初始化已经完成。');
if (installation_config() !== null) abort_request(503, '系统已经初始化，数据库当前不可用。');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') abort_request(405, '请求方式不正确。');
verify_csrf();
header('Content-Type: application/json; charset=utf-8');
try {
    if (!smtp_is_configured($_POST)) throw new RuntimeException('请先填写 SMTP 配置。');
    smtp_test_message($_POST, trim((string) ($_POST['admin_email'] ?? '')));
    echo json_encode(['ok' => true, 'message' => '测试邮件已发送至管理员邮箱。'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    echo json_encode(['ok' => false, 'message' => '邮箱验证失败，请检查 SMTP 地址、端口、账号、授权密码和加密方式。'], JSON_UNESCAPED_UNICODE);
}
