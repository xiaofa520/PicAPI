<?php

require_once __DIR__ . '/common.php';

if (admin_is_logged_in()) {
    admin_redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forgot_password') {
    admin_require_csrf();

    $identity = trim((string) ($_POST['identity'] ?? ''));

    try {
        if ($identity === '') {
            throw new RuntimeException('请输入账号或邮箱。');
        }

        admin_send_password_reset_email_for_identity($identity);
        admin_set_flash('success', '如果信息匹配，邮件已经发出。');
        admin_redirect('forgot_password.php');
    } catch (Throwable $throwable) {
        admin_set_flash('error', $throwable->getMessage());
        admin_redirect('forgot_password.php');
    }
}

$flash = admin_pull_flash();

admin_render_auth_page_start('找回密码', [
    'eyebrow' => 'IMAGE',
    'headline' => '找回',
    'description' => '邮箱重置',
]);
?>
                <section class="auth-panel">
                    <?php if ($flash !== null): ?>
                        <div class="notice <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <form method="post" class="auth-stack" style="margin-top: 8px;">
                        <input type="hidden" name="action" value="forgot_password">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="form-grid">
                            <div class="field full">
                                <label for="identity">账号 / 邮箱</label>
                                <input id="identity" type="text" name="identity" autocomplete="username" required>
                            </div>
                            <div class="field full" style="margin-top: 8px;">
                                <button type="submit">发送链接</button>
                            </div>
                            <div class="field full">
                                <a href="login.php" class="auth-back-link">返回</a>
                            </div>
                        </div>
                    </form>
                </section>
<?php admin_render_auth_page_end(); ?>
