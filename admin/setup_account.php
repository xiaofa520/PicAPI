<?php

require_once __DIR__ . '/common.php';

if (admin_is_initialized()) {
    admin_redirect(admin_is_logged_in() ? 'index.php' : 'login.php');
}

$databaseSettings = admin_pending_database_settings();
if (!is_array($databaseSettings)) {
    admin_set_flash('warning', '请先完成数据库配置。');
    admin_redirect('setup.php');
}

$flash = admin_pull_flash();
$errorMessage = null;
$adminUsername = ADMIN_DEFAULT_USERNAME;
$adminEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_admin') {
    admin_require_csrf();

    $adminUsername = trim((string) ($_POST['admin_username'] ?? ADMIN_DEFAULT_USERNAME));
    $adminEmail = trim((string) ($_POST['admin_email'] ?? ''));
    $adminPassword = (string) ($_POST['admin_password'] ?? '');
    $adminPasswordConfirm = (string) ($_POST['admin_password_confirm'] ?? '');

    try {
        $adminUsername = admin_validate_admin_username($adminUsername);
        $adminEmail = admin_validate_email_address($adminEmail);

        if ($adminPassword === '' || $adminPasswordConfirm === '') {
            throw new RuntimeException('请输入密码。');
        }

        if (strlen($adminPassword) < 8) {
            throw new RuntimeException('密码至少需要 8 位。');
        }

        if (!hash_equals($adminPassword, $adminPasswordConfirm)) {
            throw new RuntimeException('两次输入的密码不一致。');
        }

        $emailSettings = [
            'enabled' => false,
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => '图片服务',
            'site_url' => admin_current_admin_base_url(),
        ];

        admin_initialize_auth($databaseSettings, $emailSettings, $adminUsername, $adminEmail, $adminPassword);
        admin_finalize_login($adminUsername);
        admin_set_flash('success', '初始化已完成。邮箱服务可在页面设置中继续配置。');
        admin_redirect('index.php');
    } catch (Throwable $throwable) {
        $errorMessage = $throwable->getMessage();
    }
}

$driver = (string) ($databaseSettings['driver'] ?? '');

admin_render_auth_page_start('创建账号', [
    'eyebrow' => 'SETUP',
    'headline' => '最后一步',
    'description' => '第 2 步',
    'step_label' => 'STEP',
    'step_title' => '管理员',
    'step_index' => 2,
    'step_total' => 2,
]);
?>
                <section class="auth-panel">
                    <?php if ($flash !== null): ?>
                        <div class="notice <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($errorMessage !== null): ?>
                        <div class="notice error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <form method="post" class="auth-stack" style="margin-top: 8px;">
                        <input type="hidden" name="action" value="create_admin">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="form-grid">
                            <div class="field">
                                <label for="admin_username">账号</label>
                                <input id="admin_username" type="text" name="admin_username" value="<?= htmlspecialchars($adminUsername, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required>
                            </div>
                            <div class="field">
                                <label for="admin_email">邮箱</label>
                                <input id="admin_email" type="email" name="admin_email" value="<?= htmlspecialchars($adminEmail, ENT_QUOTES, 'UTF-8') ?>" autocomplete="email" required>
                            </div>
                            <div class="field">
                                <label for="admin_password">密码</label>
                                <input id="admin_password" type="password" name="admin_password" autocomplete="new-password" required>
                            </div>
                            <div class="field">
                                <label for="admin_password_confirm">确认</label>
                                <input id="admin_password_confirm" type="password" name="admin_password_confirm" autocomplete="new-password" required>
                            </div>
                            <div class="field full">
                                <label for="database_driver">数据库</label>
                                <input id="database_driver" value="<?= htmlspecialchars(admin_database_mode_label($driver), ENT_QUOTES, 'UTF-8') ?>" disabled style="background: var(--accent-soft);">
                            </div>
                            <div class="field full" style="margin-top: 8px;">
                                <button type="submit">完成初始化</button>
                            </div>
                            <div class="field full">
                                <a href="setup.php" class="auth-back-link">上一步</a>
                            </div>
                        </div>
                    </form>
                </section>
<?php admin_render_auth_page_end(); ?>
