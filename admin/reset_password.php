<?php

require_once __DIR__ . '/common.php';

if (admin_is_logged_in()) {
    admin_redirect('index.php');
}

$flash = admin_pull_flash();
$errorMessage = null;

if (isset($_GET['token'])) {
    try {
        $user = admin_consume_action_token(
            (string) $_GET['token'],
            ADMIN_TOKEN_PURPOSE_PASSWORD_RESET,
            admin_device_fingerprint()
        );

        admin_store_password_reset_grant([
            'user_id' => (int) ($user['id'] ?? 0),
            'username' => (string) ($user['username'] ?? ''),
            'expires_at' => time() + 300,
        ]);

        admin_set_flash('success', '链接已确认，请设置新密码。');
        admin_redirect('reset_password.php');
    } catch (Throwable $throwable) {
        $errorMessage = $throwable->getMessage();
        admin_clear_password_reset_grant();
    }
}

$grant = admin_password_reset_grant();
if (is_array($grant) && (int) ($grant['expires_at'] ?? 0) < time()) {
    admin_clear_password_reset_grant();
    $grant = null;
    $errorMessage = '链接已过期，请重新获取。';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    admin_require_csrf();
    $grant = admin_password_reset_grant();

    try {
        if (!is_array($grant) || (int) ($grant['expires_at'] ?? 0) < time()) {
            throw new RuntimeException('重置凭证已失效，请重新获取。');
        }

        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['password_confirm'] ?? '');

        if ($password === '' || $confirmPassword === '') {
            throw new RuntimeException('请输入新密码。');
        }

        if (strlen($password) < 8) {
            throw new RuntimeException('密码至少需要 8 位。');
        }

        if (!hash_equals($password, $confirmPassword)) {
            throw new RuntimeException('两次输入的密码不一致。');
        }

        $pdo = admin_open_database();
        admin_ensure_database_schema($pdo);
        admin_update_admin_password($pdo, (int) ($grant['user_id'] ?? 0), $password);
        admin_clear_password_reset_grant();
        admin_login_cooldown_clear(admin_login_cooldown_key((string) ($grant['username'] ?? '')));
        admin_set_flash('success', '密码已更新，请重新登录。');
        admin_redirect('login.php');
    } catch (Throwable $throwable) {
        $errorMessage = $throwable->getMessage();
    }
}

admin_render_auth_page_start('重置密码', [
    'eyebrow' => 'IMAGE',
    'headline' => '重置',
    'description' => '新密码',
]);
?>
                <section class="auth-panel">
                    <?php if ($flash !== null): ?>
                        <div class="notice <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($errorMessage !== null): ?>
                        <div class="notice error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <?php if (is_array($grant)): ?>
                        <form method="post" class="auth-stack" style="margin-top: 8px;">
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                            <div class="form-grid">
                                <div class="field full">
                                    <label for="password">新密码</label>
                                    <input id="password" type="password" name="password" autocomplete="new-password" required>
                                </div>
                                <div class="field full">
                                    <label for="password_confirm">确认</label>
                                    <input id="password_confirm" type="password" name="password_confirm" autocomplete="new-password" required>
                                </div>
                                <div class="field full" style="margin-top: 8px;">
                                    <button type="submit">更新</button>
                                </div>
                                <div class="field full">
                                    <a href="login.php" class="auth-back-link">返回</a>
                                </div>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="auth-stack">
                            <a href="forgot_password.php" class="auth-back-link">重新获取</a>
                            <a href="login.php" class="auth-back-link">返回</a>
                        </div>
                    <?php endif; ?>
                </section>
<?php admin_render_auth_page_end(); ?>
