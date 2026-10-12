<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/settings_runtime.php';

initialize_storage();
require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') abort_request(405, '请求方式不正确。');
verify_csrf();

try {
    $settings = site_settings();
    $installation = installation_config() ?? [];
    $recipient = (string) ($settings['notification_email'] !== '' ? $settings['notification_email'] : ($installation['admin_email'] ?? ''));
    $mailFailureReason = null;
    if (!send_site_notification('测试邮件', '这是一封由 PicAPI 设置页面发出的测试邮件。', ['detail' => '若收到此邮件，SMTP 配置和 HTML 模板均可正常使用。'], $recipient, 'notification_email_html_template', $mailFailureReason)) {
        throw new RuntimeException('测试邮件发送失败：' . ($mailFailureReason !== null && $mailFailureReason !== '' ? $mailFailureReason : '未知错误。'));
    }
    flash('notice', '测试邮件已发送，请检查收件箱。');
    flash('notice_type', 'success');
} catch (Throwable $error) {
    flash('notice', $error->getMessage());
    flash('notice_type', 'error');
}
redirect('settings.php');
