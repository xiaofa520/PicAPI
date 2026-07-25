<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/app.php';
require_once __DIR__ . '/../lib/smtp_runtime.php';
require_once __DIR__ . '/../lib/settings_runtime.php';

initialize_storage();
start_secure_session();
send_admin_security_headers();
if (!admin_is_configured()) {
    if (installation_config() === null) redirect('setup.php');
    abort_request(503, '系统已初始化，但管理员数据库当前不可用。请检查数据库连接后重试。');
}
if (current_admin() !== null) redirect('dashboard.php');

$siteSettings = site_settings();
$loginTransportPublicKey = login_transport_public_key();
$error = flash('login_error');
$notice = null;
$loginIdentity = (string) (flash('login_identity') ?? '');
$loginRetryAfter = login_retry_after();
$loginIpAccess = ['blocked' => false, 'permanent' => false, 'until' => 0, 'reason' => ''];
try {
    $loginIpAccess = public_ip_access_status(request_client_ip());
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
            flash('login_error', '登录凭据加密校验失败，请刷新页面后重试。');
            redirect('login.php');
        }
        $loginIdentity = trim($credentials['username']);
        if (!empty($loginIpAccess['blocked'])) {
            flash('login_identity', $loginIdentity);
            flash('login_error', '当前 IP 已被列入黑名单，无法继续登录。');
            redirect('login.php');
        }
        $retryAfter = login_retry_after();
        if ($retryAfter > 0) {
            flash('login_identity', $loginIdentity);
            flash('login_error', '登录已被临时限制，请等待解锁后再试。');
            redirect('login.php');
        } else {
        $identity = $loginIdentity;
        $password = $credentials['password'];
        $adminUsername = resolve_admin_login($identity, $password);
        if ($adminUsername !== null) {
            clear_login_failures();
            begin_admin_session($adminUsername);
            if (!empty($siteSettings['login_notification'])) {
                try {
                    $loginIp = request_client_ip();
                    send_site_notification('管理员登录提醒', '检测到管理员账户成功登录。', [
                        'ip' => $loginIp,
                        'detail' => '登录账户：' . $adminUsername
                            . "\n登录位置：" . request_client_location_label($loginIp)
                            . "\n浏览器/设备：" . request_client_device_label(),
                    ]);
                } catch (Throwable) {
                    // A failed notification must never block a valid administrator login.
                }
            }
            redirect('dashboard.php');
        }
        $failure = record_login_failure();
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
        $lastRequested = (int) ($reset['requested_at'] ?? 0);
        if ($lastRequested > time() - 60) {
            $error = '验证码已发送，请等待一分钟后再申请新的验证码。';
        } elseif ($identity === '') {
            $error = '请输入用户名或邮箱。';
        } else {
            $account = admin_account_by_identity($identity);
            // SMTP settings are stored in the configured database after
            // installation. Reading installation.php here would ignore later
            // changes from the Settings page and make password recovery fail.
            $smtp = smtp_settings();
            if (!smtp_is_configured($smtp)) {
                $error = '系统未完成邮件配置，暂时无法找回密码。';
            } elseif ($account === null || filter_var($account['email'], FILTER_VALIDATE_EMAIL) === false) {
                // Do not reveal whether a particular administrator account exists.
                $notice = '若该账户存在且已配置邮箱，验证码已发送，请查收邮件。';
            } else {
                $retryAfter = claim_password_reset_request($account['username']);
                if ($retryAfter > 0) {
                    // Keep the response generic so the rate limit cannot be
                    // used as an account-existence oracle.
                    $notice = '若该账户存在且已配置邮箱，验证码已发送或正处于安全冷却期，请稍后再试。';
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
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= html((string) $siteSettings['theme_mode']) ?>">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
    <title><?= html((string) $siteSettings['site_name']) ?> 后台登录</title>
    <link rel="icon" href="<?= html(site_favicon_url()) ?>">
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.js" integrity="sha384-LtmWBcrD5iuFIR4sFphS8IiiaftkypL5dJzLKywbyd9ATLB7ZbPz3JYnI9nvXHkV" crossorigin="anonymous"></script>
    <style>@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap');body{font-family:'Inter','Helvetica Neue',sans-serif}</style><style><?= site_theme_css($siteSettings) ?></style>
</head>
<body class="min-h-screen bg-slate-950 px-4 text-slate-100"><main class="flex min-h-screen items-center justify-center"><section class="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900 p-8 shadow-2xl">
    <div class="mb-7 text-center"><div class="mb-3 inline-flex rounded-xl bg-slate-800 p-3 text-indigo-400"><i data-lucide="<?= $showReset ? 'key-round' : 'image' ?>" class="h-8 w-8"></i></div><h1 class="text-2xl font-semibold tracking-tight text-white"><?= $showReset ? '找回密码' : html((string) $siteSettings['site_name']) ?></h1><p class="mt-1 text-sm text-slate-400"><?= $showReset ? '通过绑定邮箱完成安全验证' : '随机图片服务后台管理' ?></p></div>
    <?php if ($error !== null): ?><p class="mb-5 rounded-lg border border-rose-900 bg-rose-950/50 px-3 py-2 text-sm text-rose-200"><?= html($error) ?></p><?php endif; ?>
    <?php if ($notice !== null): ?><p class="mb-5 rounded-lg border border-emerald-900 bg-emerald-950/50 px-3 py-2 text-sm text-emerald-200"><?= html($notice) ?></p><?php endif; ?>
    <?php if (!$showReset): ?>
        <?php if ($loginIpBlocked): ?><p class="mb-5 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-200"><?= html($loginIpBlockMessage) ?></p><?php endif; ?>
        <?php if ($loginRetryAfter > 0): ?><div class="mb-5 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-300"><span>登录暂时受限，</span><strong data-login-unlock-countdown data-seconds="<?= $loginRetryAfter ?>" class="font-mono text-amber-200"><?= $loginRetryAfter ?></strong><span> 秒后解锁。</span></div><?php endif; ?>
        <form method="post" class="space-y-5"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="login"><div><label class="mb-1.5 block text-xs font-medium text-slate-400">管理员账户或邮箱</label><input name="username" type="text" required autocomplete="username" value="<?= html($loginIdentity) ?>" <?= $loginRetryAfter > 0 ? 'disabled' : '' ?> class="w-full rounded-lg border border-slate-700 bg-slate-800 px-4 py-2.5 text-sm text-white outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/40 disabled:cursor-not-allowed disabled:opacity-60"></div><div><div class="mb-1.5 flex items-center justify-between"><label class="text-xs font-medium text-slate-400">密码</label><a href="login.php?reset=1" class="text-xs text-indigo-400 hover:text-indigo-300">忘记密码？</a></div><input name="password" type="password" required autocomplete="current-password" <?= $loginRetryAfter > 0 ? 'disabled' : '' ?> class="w-full rounded-lg border border-slate-700 bg-slate-800 px-4 py-2.5 text-sm text-white outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/40 disabled:cursor-not-allowed disabled:opacity-60"></div><button type="submit" <?= $loginRetryAfter > 0 ? 'disabled' : '' ?> class="w-full rounded-lg bg-indigo-600 py-2.5 text-sm font-medium text-white shadow-lg shadow-indigo-600/20 transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60">进入控制台</button></form>
    <?php elseif ($reset === null): ?>
        <form method="post" class="space-y-5"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="reset_request"><div><label class="mb-1.5 block text-xs font-medium text-slate-400">用户名或邮箱</label><input name="identity" type="text" required autocomplete="username" placeholder="输入用户名或邮箱" class="w-full rounded-lg border border-slate-700 bg-slate-800 px-4 py-2.5 text-sm text-white outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/40"></div><button type="submit" class="w-full rounded-lg bg-indigo-600 py-2.5 text-sm font-medium text-white transition hover:bg-indigo-700">发送验证码</button></form>
    <?php elseif (!$resetVerified): ?>
        <form method="post" class="space-y-5"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="reset_verify"><div><label class="mb-1.5 block text-xs font-medium text-slate-400">邮箱验证码</label><input name="code" type="text" inputmode="numeric" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code" placeholder="6 位数字验证码" class="w-full rounded-lg border border-slate-700 bg-slate-800 px-4 py-2.5 text-center font-mono text-lg tracking-[0.4em] text-white outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/40"></div><button type="submit" class="w-full rounded-lg bg-indigo-600 py-2.5 text-sm font-medium text-white transition hover:bg-indigo-700">验证验证码</button></form><p class="mt-4 text-center text-xs text-slate-500">验证码已发送到 <?= html((string) $reset['email']) ?>，有效期 15 分钟。</p>
    <?php else: ?>
        <form method="post" class="space-y-5"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="reset_password"><div><label class="mb-1.5 block text-xs font-medium text-slate-400">新密码</label><input id="new-password" name="password" type="password" required minlength="8" autocomplete="new-password" class="w-full rounded-lg border border-slate-700 bg-slate-800 px-4 py-2.5 text-sm text-white outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/40"><p id="password-strength" class="mt-1 text-xs text-slate-500">至少 8 位</p></div><div><label class="mb-1.5 block text-xs font-medium text-slate-400">确认新密码</label><input name="password_confirm" type="password" required minlength="8" autocomplete="new-password" class="w-full rounded-lg border border-slate-700 bg-slate-800 px-4 py-2.5 text-sm text-white outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/40"></div><button type="submit" class="w-full rounded-lg bg-indigo-600 py-2.5 text-sm font-medium text-white transition hover:bg-indigo-700">保存新密码</button></form>
    <?php endif; ?>
    <?php if ($showReset): ?><p class="mt-5 text-center"><a href="login.php?cancel_reset=1" class="text-xs text-slate-400 hover:text-white">返回登录</a></p><?php endif; ?>
</section></main><script>lucide.createIcons();const password=document.getElementById('new-password');const strength=document.getElementById('password-strength');if(password&&strength)password.addEventListener('input',()=>{const n=password.value.length;strength.textContent=n<8?'至少 8 位':n<12?'密码强度：一般':'密码强度：良好';strength.className='mt-1 text-xs '+(n<8?'text-rose-400':n<12?'text-amber-400':'text-emerald-400')});const unlockCountdown=document.querySelector('[data-login-unlock-countdown]');if(unlockCountdown){let seconds=Math.max(0,Number(unlockCountdown.dataset.seconds)||0);const update=()=>{unlockCountdown.textContent=String(seconds);if(seconds<=0){window.location.reload();return}seconds-=1;window.setTimeout(update,1000)};update()}</script></body>
<script>
(() => {
    const publicKeyPem = <?= json_encode($loginTransportPublicKey, JSON_UNESCAPED_SLASHES) ?>;
    const form = Array.from(document.forms).find(item => item.querySelector('input[name="action"][value="login"]'));
    if (!form) return;
    const username = form.querySelector('input[name="username"]');
    const password = form.querySelector('input[name="password"]');
    const submitButton = form.querySelector('button[type="submit"]');
    if (!username || !password || !submitButton) return;
    const credentials = document.createElement('input');
    credentials.type = 'hidden';
    credentials.name = 'credentials';
    form.append(credentials);
    const toBase64 = bytes => {
        let binary = '';
        bytes.forEach(byte => { binary += String.fromCharCode(byte); });
        return btoa(binary);
    };
    const publicKeyBytes = () => {
        const base64 = publicKeyPem.replace(/-----(BEGIN|END) PUBLIC KEY-----/g, '').replace(/\s/g, '');
        const binary = atob(base64);
        return Uint8Array.from(binary, character => character.charCodeAt(0));
    };
    form.addEventListener('submit', async event => {
        if (form.dataset.encrypted === 'true') return;
        event.preventDefault();
        if (!window.crypto?.subtle) {
            alert('当前浏览器不支持登录凭据加密，请使用现代浏览器后重试。');
            return;
        }
        submitButton.disabled = true;
        try {
            const rsaKey = await crypto.subtle.importKey('spki', publicKeyBytes(), { name: 'RSA-OAEP', hash: 'SHA-1' }, false, ['encrypt']);
            const contentKeyBytes = crypto.getRandomValues(new Uint8Array(32));
            const iv = crypto.getRandomValues(new Uint8Array(12));
            const contentKey = await crypto.subtle.importKey('raw', contentKeyBytes, 'AES-GCM', false, ['encrypt']);
            const plaintext = new TextEncoder().encode(JSON.stringify({ username: username.value, password: password.value }));
            const encryptedContent = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, contentKey, plaintext));
            const encryptedKey = new Uint8Array(await crypto.subtle.encrypt({ name: 'RSA-OAEP' }, rsaKey, contentKeyBytes));
            const tagLength = 16;
            credentials.value = JSON.stringify({
                key: toBase64(encryptedKey),
                iv: toBase64(iv),
                ciphertext: toBase64(encryptedContent.slice(0, -tagLength)),
                tag: toBase64(encryptedContent.slice(-tagLength)),
            });
            username.disabled = true;
            password.disabled = true;
            form.dataset.encrypted = 'true';
            HTMLFormElement.prototype.submit.call(form);
        } catch (_) {
            submitButton.disabled = false;
            alert('登录凭据加密失败，请刷新页面后重试。');
        }
    });
})();
</script>
</html>
