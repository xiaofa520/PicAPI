<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/app.php';
require_once __DIR__ . '/../../src/smtp_runtime.php';
require_once __DIR__ . '/../../src/settings_runtime.php';
require_once __DIR__ . '/../../src/login_log_runtime.php';

initialize_storage();
enforce_admin_transport_policy();
start_secure_session();
send_admin_security_headers();
if (!admin_is_configured()) {
    if (installation_config() === null) redirect('setup.php');
    if (installation_sqlite_admin_table_missing()) redirect('setup.php');
    abort_request(503, admin_database_unavailable_message());
}
if (current_admin() !== null) redirect('dashboard.php');

$siteSettings = site_settings();
$loginBackgroundImageUrl = ($siteSettings['login_background_type'] ?? 'color') === 'image' && !empty($siteSettings['login_background_image'])
    ? 'login_wallpaper.php?v=' . rawurlencode((string) $siteSettings['login_background_image'])
    : '';
$loginTransportPublicKey = login_transport_public_key();
$error = flash('login_error');
$notice = null;
$loginIdentity = (string) (flash('login_identity') ?? '');
$loginRetryAfter = login_retry_after();
$loginIpAccess = ['blocked' => false, 'permanent' => false, 'until' => 0, 'reason' => ''];
try {
    $loginIpAccess = login_ip_access_status(request_client_ip());
} catch (Throwable) {
    // Login remains available if optional IP-control storage cannot be read.
}
if (isset($_GET['cancel_reset'])) unset($_SESSION['password_reset']);
$reset = is_array($_SESSION['password_reset'] ?? null) ? $_SESSION['password_reset'] : null;
if ($reset !== null && (int) ($reset['expires_at'] ?? 0) < time()) {
    unset($_SESSION['password_reset']);
    $reset = null;
}
$showReset = isset($_GET['reset']) || $reset !== null;
$loginIpBlocked = !$showReset && !empty($loginIpAccess['blocked']);
$loginCsrf = csrf_token();
$loginIpBlockMessage = '';
if ($loginIpBlocked) {
    $loginIpBlockMessage = !empty($loginIpAccess['permanent'])
        ? '当前 IP 已被永久列入黑名单。'
        : '当前 IP 已被临时列入黑名单，解封时间：' . (new DateTimeImmutable('@' . (int) $loginIpAccess['until']))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s') . '。';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? 'login');
    if ($action === 'login') {
        $credentials = decrypt_login_transport_credentials((string) ($_POST['credentials'] ?? ''));
        if ($credentials === null) {
            // Allow native form submission only for HTTPS or local development
            // traffic; remote HTTP would expose the password in transit.
            $plainUsername = $_POST['username'] ?? null;
            $plainPassword = $_POST['password'] ?? null;
            if (is_string($plainUsername) && is_string($plainPassword)) {
                if (!request_is_https() && !request_is_loopback_client()) {
                    abort_request(403, '登录凭据加密不可用，请使用 HTTPS 地址后重试。');
                }
                $credentials = ['username' => $plainUsername, 'password' => $plainPassword];
            }
        }
        if ($credentials === null) {
            record_login_log('', 'failure', '登录凭据校验失败');
            flash('login_error', '登录请求校验失败，请刷新页面后重试。');
            redirect('login.php');
        }
        $loginIdentity = trim($credentials['username']);
        if (!empty($loginIpAccess['blocked'])) {
            record_login_log($loginIdentity, 'blocked', '来源 IP 已被封禁');
            flash('login_identity', $loginIdentity);
            flash('login_error', '当前 IP 已被列入黑名单，无法继续登录。');
            redirect('login.php');
        }
        $retryAfter = login_retry_after();
        if ($retryAfter > 0) {
            record_login_log($loginIdentity, 'blocked', '连续登录失败临时限制');
            flash('login_identity', $loginIdentity);
            flash('login_error', '登录已被临时限制，请等待解锁后再试。');
            redirect('login.php');
        } else {
        $identity = $loginIdentity;
        $password = $credentials['password'];
        $adminUsername = resolve_admin_login($identity, $password);
        if ($adminUsername !== null) {
            record_login_log($adminUsername, 'success', '账号验证通过');
            clear_login_failures();
            begin_admin_session($adminUsername);
            $loginIp = request_client_ip();
            $loginLocation = '未知位置';
            try {
                $loginLocation = request_client_location_label($loginIp);
            } catch (Throwable) {
                // Location enrichment is optional and must never block login.
            }
            if (!empty($siteSettings['login_notification'])) {
                try {
                    $mailFailureReason = null;
                    $sent = send_site_notification('管理员登录提醒', '检测到管理员账户成功登录。', [
                        'ip' => $loginIp,
                        'detail' => '登录账户：' . $adminUsername
                            . "\n登录位置：" . $loginLocation
                            . "\n浏览器/设备：" . request_client_device_label(),
                    ], null, 'notification_email_html_template', $mailFailureReason);
                    if (!$sent && $mailFailureReason !== null && $mailFailureReason !== '') {
                    }
                } catch (Throwable) {
                    // A failed notification must never block a valid administrator login.
                }
            }
            redirect('dashboard.php');
        }
        $failure = record_login_failure();
        record_login_log($loginIdentity, 'failure', '账号或密码不匹配');
        $loginBlacklistApplied = false;
        if (!empty($failure['max_lock_expired'])) {
            try {
                add_ip_to_blacklist((string) $failure['ip'], 0, 'login', true);
                $loginBlacklistApplied = true;
            } catch (Throwable) {
                // An explicitly whitelisted IP remains under the normal login lock policy.
            }
        }
        $shouldNotifyBreach = $failure['failures'] >= 3 && (($failure['failures'] - 3) % 2 === 0);
        if ($shouldNotifyBreach && !empty($siteSettings['password_breach_alert'])) {
            try {
                send_site_notification('密码爆破提醒', '检测到同一 IP 连续登录失败，系统已启动临时登录限制。', [
                    'ip' => $failure['ip'],
                    'detail' => '失败次数：' . $failure['failures'] . ' 次；当前限制：' . max(1, (int) ceil($failure['lock_seconds'] / 60)) . ' 分钟。' . ($loginBlacklistApplied ? ' 已加入永久黑名单。' : ''),
                ]);
            } catch (Throwable) {
                // A notification failure must not reveal mail state to the attacker.
            }
        }
        flash('login_identity', $loginIdentity);
        flash('login_error', $loginBlacklistApplied ? '登录爆破限制已达到 5 小时上限，当前 IP 已加入永久黑名单。' : ((int) ($failure['lock_seconds'] ?? 0) > 0 ? '登录失败次数过多，已启用临时限制。' : '账户或密码不正确。'));
        redirect('login.php');
        }
    } elseif ($action === 'reset_request') {
        $showReset = true;
        $identity = trim((string) ($_POST['identity'] ?? ''));
        if ($identity === '') {
            $error = '请输入用户名或邮箱。';
        } else {
            $smtp = smtp_settings();
            if (!smtp_is_configured($smtp)) {
                $error = '系统未完成邮件配置，暂时无法找回密码。';
            } else {
                // Rate-limit every request by IP and identity before any
                // account lookup, so the cooldown cannot be used as an
                // account-existence oracle.
                $retryAfter = claim_password_reset_request($identity);
                $account = admin_account_by_identity($identity);
                if ($retryAfter > 0) {
                    $notice = '请求过于频繁，请稍后再试。';
                } elseif ($account === null || filter_var($account['email'], FILTER_VALIDATE_EMAIL) === false) {
                    // Do not reveal whether a particular administrator account exists.
                    $notice = '若该账户存在且已配置邮箱，验证码已发送，请查收邮件。';
                } else {
                    try {
                        $code = (string) random_int(100000, 999999);
                        send_password_reset_notification($account['email'], $code);
                        $_SESSION['password_reset'] = [
                            'username' => $account['username'],
                            'email' => $account['email'],
                            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
                            'expires_at' => time() + 900,
                            'requested_at' => time(),
                            'attempts' => 0,
                            'verified_until' => 0,
                        ];
                        $reset = $_SESSION['password_reset'];
                        $notice = '验证码已发送至管理员绑定邮箱，请在 15 分钟内完成验证。';
                    } catch (Throwable) {
                        $error = '验证码发送失败，请检查邮件服务器配置后重试。';
                    }
                }
            }
        }
    } elseif ($action === 'reset_verify') {
        $showReset = true;
        $code = trim((string) ($_POST['code'] ?? ''));
        if ($reset === null || (int) ($reset['expires_at'] ?? 0) < time()) {
            unset($_SESSION['password_reset']);
            $reset = null;
            $error = '验证码已过期，请重新申请。';
        } elseif ((int) ($reset['attempts'] ?? 0) >= 5) {
            unset($_SESSION['password_reset']);
            $reset = null;
            $error = '验证码错误次数过多，请重新申请。';
        } elseif (!preg_match('/^\d{6}$/', $code) || !password_verify($code, (string) ($reset['code_hash'] ?? ''))) {
            $_SESSION['password_reset']['attempts'] = (int) ($reset['attempts'] ?? 0) + 1;
            $reset = $_SESSION['password_reset'];
            $error = '验证码不正确，请重新输入。';
        } else {
            $_SESSION['password_reset']['verified_until'] = time() + 600;
            unset($_SESSION['password_reset']['code_hash']);
            $reset = $_SESSION['password_reset'];
            $notice = '邮箱验证成功，请设置新密码。';
        }
    } elseif ($action === 'reset_password') {
        $showReset = true;
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        if ($reset === null || (int) ($reset['verified_until'] ?? 0) < time()) {
            unset($_SESSION['password_reset']);
            $reset = null;
            $error = '验证状态已过期，请重新申请验证码。';
        } elseif (strlen($password) < 8) {
            $error = '新密码至少需要 8 位。';
        } elseif (!hash_equals($password, $confirm)) {
            $error = '两次输入的新密码不一致。';
        } elseif (admin_password_matches((string) $reset['username'], $password)) {
            $error = '新密码不能与旧密码相同。';
        } elseif (!update_admin_password((string) $reset['username'], $password)) {
            $error = '密码更新失败，请稍后重试。';
        } else {
            try {
                // Changing a password must also revoke every previously
                // issued administrator session, including a stolen one.
                rotate_admin_session_generation();
                unset($_SESSION['password_reset']);
                clear_login_failures();
                $reset = null;
                $showReset = false;
                $notice = '密码已更新，所有旧登录会话已失效，请使用新密码登录。';
            } catch (Throwable) {
                $error = '密码已更新，但会话撤销状态无法保存。请检查服务器文件权限后立即重试。';
            }
        }
    }
}

$resetVerified = $reset !== null && (int) ($reset['verified_until'] ?? 0) >= time();
require __DIR__ . '/../../templates/admin/login_view.php';
