<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/image_runtime.php';
require_once __DIR__ . '/../../src/settings_runtime.php';

initialize_storage();
$admin = require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') abort_request(405, 'Method not allowed.');
verify_csrf();

function account_result(): never
{
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        $message = flash('notice'); $ok = flash('notice_type') !== 'error';
        http_response_code($ok ? 200 : 422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message, 'csrf' => csrf_token(), 'emailPending' => isset($_SESSION['admin_email_change'])], JSON_UNESCAPED_UNICODE);
        exit;
    }
    redirect('settings.php');
}

try {
    $account = admin_account_by_identity($admin);
    if ($account === null) throw new RuntimeException('Administrator account is unavailable.');

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'cancel_email_change') {
        unset($_SESSION['admin_email_change']);
        flash('notice', '邮箱更换验证已取消。');
        flash('notice_type', 'success');
        account_result();
    }

    if ($action === 'request_password_change_code') {
        $email = trim((string) ($account['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new RuntimeException('请先在个人资料中设置有效的管理员邮箱。');
        $previous = is_array($_SESSION['admin_password_change'] ?? null) ? $_SESSION['admin_password_change'] : null;
        if ($previous !== null && (int) ($previous['requested_at'] ?? 0) > time() - 60) {
            throw new RuntimeException('验证码已发送，请在一分钟后再试。');
        }
        if (!smtp_is_configured(smtp_settings())) throw new RuntimeException('请先配置并保存可用的 SMTP 邮件服务。');

        $code = (string) random_int(100000, 999999);
        $mailFailureReason = null;
        if (!send_site_notification('更新管理员密码验证码', '你正在更新 PicAPI 管理员密码，请使用下方验证码完成邮箱验证。', [
            'code' => $code,
            'detail' => '验证码将在 15 分钟后失效；若非本人操作，请忽略此邮件。',
        ], $email, 'password_reset_email_html_template', $mailFailureReason)) {
            throw new RuntimeException('验证码发送失败：' . ($mailFailureReason !== null && $mailFailureReason !== '' ? $mailFailureReason : '请检查 SMTP 配置。'));
        }
        $_SESSION['admin_password_change'] = [
            'current_username' => $admin,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => time() + 900,
            'requested_at' => time(),
            'attempts' => 0,
        ];
        flash('notice', '验证码已发送到管理员绑定邮箱，请在 15 分钟内完成验证。');
        flash('notice_type', 'success');
        account_result();
    }

    if ($action === 'change_password') {
        $pending = is_array($_SESSION['admin_password_change'] ?? null) ? $_SESSION['admin_password_change'] : null;
        $code = trim((string) ($_POST['verification_code'] ?? ''));
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmation = (string) ($_POST['new_password_confirmation'] ?? '');
        if ($pending === null || (string) ($pending['current_username'] ?? '') !== $admin || (int) ($pending['expires_at'] ?? 0) < time()) {
            unset($_SESSION['admin_password_change']);
            throw new RuntimeException('邮箱验证码已过期，请重新获取。');
        }
        if ((int) ($pending['attempts'] ?? 0) >= 5) {
            unset($_SESSION['admin_password_change']);
            throw new RuntimeException('验证码错误次数过多，请重新获取。');
        }
        if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, (string) ($pending['code_hash'] ?? ''))) {
            $attempts = (int) ($pending['attempts'] ?? 0) + 1;
            if ($attempts >= 5) {
                unset($_SESSION['admin_password_change']);
                throw new RuntimeException('验证码错误次数过多，请重新获取。');
            }
            $_SESSION['admin_password_change']['attempts'] = $attempts;
            throw new RuntimeException('邮箱验证码不正确。');
        }
        if (strlen($newPassword) < 8) throw new RuntimeException('新密码至少需要 8 位。');
        if (!hash_equals($newPassword, $confirmation)) throw new RuntimeException('两次输入的新密码不一致。');
        if (admin_password_matches($admin, $newPassword)) throw new RuntimeException('新密码不能与当前密码相同。');
        if (!update_admin_password($admin, $newPassword)) throw new RuntimeException('密码更新失败，请稍后重试。');
        unset($_SESSION['admin_email_change'], $_SESSION['admin_password_change']);
        rotate_admin_session_generation();
        begin_admin_session($admin);
        flash('notice', '密码已更新，所有旧登录会话已失效。');
        flash('notice_type', 'success');
        account_result();
    }

    if ($action === 'verify_email_change') {
        $pending = is_array($_SESSION['admin_email_change'] ?? null) ? $_SESSION['admin_email_change'] : null;
        $code = trim((string) ($_POST['verification_code'] ?? ''));
        if ($pending === null || (string) ($pending['current_username'] ?? '') !== $admin || (int) ($pending['expires_at'] ?? 0) < time()) {
            unset($_SESSION['admin_email_change']);
            throw new RuntimeException('验证码已过期，请重新发起邮箱更换。');
        }
        if ((int) ($pending['attempts'] ?? 0) >= 5) {
            unset($_SESSION['admin_email_change']);
            throw new RuntimeException('验证码错误次数过多，请重新发起邮箱更换。');
        }
        if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, (string) ($pending['code_hash'] ?? ''))) {
            $_SESSION['admin_email_change']['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;
            throw new RuntimeException('验证码不正确。');
        }

        $newUsername = (string) $pending['new_username'];
        $newEmail = (string) $pending['new_email'];
        update_admin_profile($admin, $newUsername, $newEmail);
        unset($_SESSION['admin_email_change']);
        rotate_admin_session_generation();
        begin_admin_session($newUsername);
        flash('notice', '用户资料和绑定邮箱已更新，其他登录会话已失效。');
        flash('notice_type', 'success');
        account_result();
    }

    if ($action !== 'save_profile') throw new RuntimeException('Invalid account action.');
    $newUsername = trim((string) ($_POST['username'] ?? ''));
    $newEmail = trim((string) ($_POST['email'] ?? ''));
    if (!preg_match('/^[\p{Han}a-zA-Z0-9._-]{2,48}$/u', $newUsername) || filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('请输入有效的用户名和邮箱地址。');
    }

    if (strcasecmp($newEmail, (string) $account['email']) === 0) {
        update_admin_profile($admin, $newUsername, (string) $account['email']);
        rotate_admin_session_generation();
        begin_admin_session($newUsername);
        flash('notice', '用户名已更新，其他登录会话已失效。');
        flash('notice_type', 'success');
        account_result();
    }

    $previous = is_array($_SESSION['admin_email_change'] ?? null) ? $_SESSION['admin_email_change'] : null;
    if ($previous !== null && (int) ($previous['requested_at'] ?? 0) > time() - 60) {
        throw new RuntimeException('验证码已发送，请在一分钟后再试。');
    }
    if (!smtp_is_configured(smtp_settings())) throw new RuntimeException('请先保存可用的 SMTP 邮件配置。');

    $code = (string) random_int(100000, 999999);
    $mailFailureReason = null;
    if (!send_site_notification('验证新的绑定邮箱', '你正在为 PicAPI 管理员账户更换绑定邮箱。请使用下方验证码完成确认。', [
        'code' => $code,
        'detail' => '验证码将在 15 分钟后失效；若非本人操作，请忽略此邮件。',
    ], $newEmail, 'password_reset_email_html_template', $mailFailureReason)) {
        throw new RuntimeException('验证码发送失败：' . ($mailFailureReason !== null && $mailFailureReason !== '' ? $mailFailureReason : '请检查 SMTP 配置。'));
    }
    $_SESSION['admin_email_change'] = [
        'current_username' => $admin,
        'new_username' => $newUsername,
        'new_email' => $newEmail,
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'expires_at' => time() + 900,
        'requested_at' => time(),
        'attempts' => 0,
    ];
    flash('notice', '验证码已发送到新邮箱，请完成验证后保存更改。');
    flash('notice_type', 'success');
} catch (Throwable $error) {
    flash('notice', $error->getMessage());
    flash('notice_type', 'error');
}
account_result();
