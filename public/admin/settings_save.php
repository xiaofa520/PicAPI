<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/image_runtime.php';

initialize_storage();
require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') abort_request(405, '请求方式不正确。');
verify_csrf();

try {
    $section = (string) ($_POST['settings_section'] ?? '');
    if ($section === '' || $section === 'security') {
        $_POST['login_notification'] = isset($_POST['login_notification']) ? '1' : '';
        $_POST['password_breach_alert'] = isset($_POST['password_breach_alert']) ? '1' : '';
        $_POST['request_rate_alert'] = isset($_POST['request_rate_alert']) ? '1' : '';
    }
    save_site_settings(array_replace(site_settings(), $_POST));
    $smtpFields = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption'];
    $hasSmtpChange = false;
    foreach ($smtpFields as $field) {
        if (trim((string) ($_POST[$field] ?? '')) !== '') {
            $hasSmtpChange = true;
            break;
        }
    }
    if ($hasSmtpChange) save_smtp_settings($_POST);

    $upload = $_FILES['site_icon'] ?? null;
    if (is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        save_site_icon_upload($upload);
    }
    flash('notice', '设置已保存并立即生效。');
    flash('notice_type', 'success');
} catch (Throwable $error) {
    flash('notice', $error->getMessage());
    flash('notice_type', 'error');
}
if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
    $message = flash('notice'); $ok = flash('notice_type') !== 'error';
    header('Content-Type: application/json; charset=utf-8'); http_response_code($ok ? 200 : 422);
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE); exit;
}
redirect('settings.php');
