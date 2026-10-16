<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/settings_runtime.php';

initialize_storage();
require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') abort_request(405, '请求方式不正确。');
verify_csrf();

try {
    $allowed = ['admin_session_days', 'site_name', 'font_size', 'theme_mode', 'theme_color', 'theme_background', 'theme_surface', 'theme_text', 'theme_muted', 'dark_theme_color', 'dark_theme_background', 'dark_theme_surface', 'dark_theme_text', 'dark_theme_muted', 'light_theme_color', 'light_theme_background', 'light_theme_surface', 'light_theme_text', 'light_theme_muted', 'notification_email', 'login_notification', 'password_breach_alert', 'request_rate_alert', 'request_window_seconds', 'request_threshold', 'ip_blacklist_threshold', 'access_log_auto_cleanup', 'access_log_retention_months', 'upload_log_auto_cleanup', 'upload_log_retention_months', 'login_log_auto_cleanup', 'login_log_retention_months'];
    $changes = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $_POST)) $changes[$key] = $_POST[$key];
    }
    if (isset($changes['request_threshold'])) {
        $changes['ip_blacklist_threshold'] = $changes['request_threshold'];
    } elseif (isset($changes['ip_blacklist_threshold'])) {
        $changes['request_threshold'] = $changes['ip_blacklist_threshold'];
    }
    if ($changes !== []) {
        update_site_settings($changes);
        if (array_key_exists('access_log_auto_cleanup', $changes) || array_key_exists('access_log_retention_months', $changes)) {
            purge_expired_access_logs(true);
        }
        if (array_key_exists('upload_log_auto_cleanup', $changes) || array_key_exists('upload_log_retention_months', $changes)) {
            purge_expired_upload_audit_logs(true);
        }
        if (array_key_exists('login_log_auto_cleanup', $changes) || array_key_exists('login_log_retention_months', $changes)) {
            purge_expired_login_logs(true);
        }
    }
    $icon = $_FILES['site_icon'] ?? null;
    if (is_array($icon) && (int) ($icon['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) save_site_icon_upload($icon);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'message' => '设置已即时应用。'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
