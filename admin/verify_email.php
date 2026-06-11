<?php

require_once __DIR__ . '/common.php';

$flash = admin_pull_flash();
$type = 'success';
$message = '邮箱已验证。';

try {
    $token = trim((string) ($_GET['token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('缺少验证链接。');
    }

    $user = admin_consume_action_token($token, ADMIN_TOKEN_PURPOSE_EMAIL_VERIFY, admin_device_fingerprint());
    $pdo = admin_open_database();
    admin_ensure_database_schema($pdo);
    admin_mark_email_verified($pdo, (int) ($user['id'] ?? 0));
    $message = '邮箱已验证，可以继续使用。';
} catch (Throwable $throwable) {
    $type = 'error';
    $message = $throwable->getMessage();
}

admin_render_auth_page_start('邮箱验证', [
    'eyebrow' => 'IMAGE',
    'headline' => '验证完成',
    'description' => '邮箱状态',
]);
?>
                <section class="auth-panel">
                    <?php if ($flash !== null): ?>
                        <div class="notice <?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <div class="notice <?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="auth-stack" style="margin-top: 8px;">
                        <a href="<?= admin_is_logged_in() ? 'index.php' : 'login.php' ?>" class="auth-back-link"><?= admin_is_logged_in() ? '进入' : '登录' ?></a>
                    </div>
                </section>
<?php admin_render_auth_page_end(); ?>
