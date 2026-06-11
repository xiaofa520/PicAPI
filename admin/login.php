<?php

require_once __DIR__ . '/common.php';

if (admin_is_logged_in()) {
    admin_redirect('index.php');
}

$submittedUsername = trim((string) ($_POST['username'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    admin_require_csrf();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $cooldownKey = admin_login_cooldown_key($username);

    try {
        admin_login_cooldown_assert($cooldownKey);

        if (admin_login($username, $password)) {
            admin_login_cooldown_clear($cooldownKey);
            admin_finalize_login($username);
            admin_set_flash('success', '已进入');
            admin_redirect('index.php');
        }

        admin_login_cooldown_record_failure($cooldownKey);
        $cooldownStatus = admin_login_cooldown_status($cooldownKey);
        admin_maybe_send_failed_login_alert($username, admin_client_ip(), (int) ($cooldownStatus['failures'] ?? 0));
        admin_set_flash('error', '账号或密码不正确');
    } catch (Throwable $throwable) {
        admin_set_flash('error', $throwable->getMessage());
    }

    admin_redirect('login.php?username=' . rawurlencode($username));
}

$flash = admin_pull_flash();
$config = admin_load_config();
$configuredUsername = trim((string) ($_GET['username'] ?? admin_configured_username($config)));
$cooldownStatus = admin_login_cooldown_status(admin_login_cooldown_key($configuredUsername));
$retryAfter = max(0, (int) ($cooldownStatus['retry_after'] ?? 0));
$isBlocked = $retryAfter > 0;

admin_render_auth_page_start('登录', [
    'eyebrow' => 'IMAGE',
    'headline' => '回来',
    'description' => '继续',
]);
?>
                <section class="auth-panel">
                    <?php if ($flash !== null): ?>
                        <div class="notice <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <form method="post" class="auth-stack" style="margin-top: 8px;">
                        <input type="hidden" name="action" value="login">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="form-grid">
                            <div class="field full">
                                <label for="username">账号</label>
                                <input
                                    id="username"
                                    type="text"
                                    name="username"
                                    value="<?= htmlspecialchars($configuredUsername, ENT_QUOTES, 'UTF-8') ?>"
                                    autocomplete="username"
                                    required
                                    <?= $isBlocked ? 'readonly' : '' ?>
                                >
                            </div>
                            <div class="field full">
                                <label for="password">密码</label>
                                <input
                                    id="password"
                                    type="password"
                                    name="password"
                                    autocomplete="current-password"
                                    required
                                    <?= $isBlocked ? 'disabled' : '' ?>
                                >
                            </div>
                            <div class="field full" id="cooldown-wrap" style="<?= $isBlocked ? '' : 'display:none;' ?>">
                                <div class="notice warning" style="margin-bottom:0;">
                                    请等待 <strong id="cooldown-seconds"><?= $retryAfter ?></strong> 秒后再试
                                </div>
                            </div>
                            <div class="field full" style="margin-top: 8px;">
                                <button id="login-submit" type="submit" <?= $isBlocked ? 'disabled' : '' ?>>进入</button>
                            </div>
                            <div class="field full" style="margin-top: -4px;">
                                <a href="forgot_password.php" class="auth-back-link">忘记密码</a>
                            </div>
                        </div>
                    </form>
                </section>

<script>
(() => {
    const secondsNode = document.getElementById('cooldown-seconds');
    const cooldownWrap = document.getElementById('cooldown-wrap');
    const passwordInput = document.getElementById('password');
    const usernameInput = document.getElementById('username');
    const submitButton = document.getElementById('login-submit');

    if (!secondsNode || !cooldownWrap || !submitButton) {
        return;
    }

    let remaining = Number(<?= json_encode($retryAfter) ?>);
    if (!Number.isFinite(remaining) || remaining <= 0) {
        return;
    }

    const tick = () => {
        remaining = Math.max(0, remaining);
        secondsNode.textContent = String(remaining);

        if (remaining <= 0) {
            cooldownWrap.style.display = 'none';
            submitButton.disabled = false;
            if (passwordInput) {
                passwordInput.disabled = false;
                passwordInput.focus();
            }
            if (usernameInput) {
                usernameInput.readOnly = false;
            }
            return;
        }

        remaining -= 1;
        window.setTimeout(tick, 1000);
    };

    tick();
})();
</script>
<?php admin_render_auth_page_end(); ?>
