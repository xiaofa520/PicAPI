<?php if (!isset($errorStep)) { http_response_code(404); exit; } ?>
<?php
function setup_field(string $name, string $label, string $default = '', string $type = 'text', string $attributes = ''): string {
    $value = $type === 'password' ? '' : (string) ($_POST[$name] ?? $default);
    return '<div class="field"><label for="' . $name . '">' . html($label) . '</label><input id="' . $name . '" name="' . $name . '" type="' . $type . '" value="' . html($value) . '" ' . $attributes . '></div>';
}
function setup_password_field(string $name, string $label, string $attributes = '', string $after = ''): string {
    return '<div class="field"><label for="' . html($name) . '">' . html($label) . '</label><div class="setup-password-control"><input id="' . html($name) . '" name="' . html($name) . '" type="password" value="" ' . $attributes . '><button class="setup-password-toggle" type="button" data-password-toggle="' . html($name) . '" aria-label="显示' . html($label) . '" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></div>' . $after . '</div>';
}
function setup_icon(string $name): string {
    $paths = [
        'database'=>'<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0"/>',
        'file'=>'<path d="M14 3H5v18h14V8zM14 3v5h5M8 13h8m-8 4h6"/>',
        'chevron'=>'<path d="m9 5 7 7-7 7"/>',
        'arrow'=>'<path d="M4 12h16m-6-6 6 6-6 6"/>',
        'auto'=>'<path d="m5 20 7-16 7 16M8 14h8"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">' . $paths[$name] . '</svg>';
}
$databaseType = (string) ($_POST['db_type'] ?? 'mysql');
$steps = [['数据库','连接数据存储'],['管理员','设置登录账号'],['邮件服务','配置通知与验证'],['完成','进入管理后台']];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
  <title>初始化设置 · PicAPI</title><link rel="icon" href="assets/mark.svg">
  <link rel="stylesheet" href="assets/styles.css"><link rel="stylesheet" href="assets/studio.css"><link rel="stylesheet" href="assets/setup-page.css?v=setup-code-4">
  <link rel="stylesheet" href="assets/password-strength.css?v=2"><script src="assets/password-strength.js?v=2" defer></script>
  <script src="assets/setup.js?v=setup-code-8" defer></script>
</head>
<body class="setup-mode setup-install">
<a class="skip-link" href="#setup-heading">跳到配置表单</a>
<header class="topbar"><div class="breadcrumb">PicAPI<span>/</span><strong>初始化设置</strong></div><button type="button" class="icon-button" id="setup-theme" aria-label="自动主题，跟随系统"><?= setup_icon('auto') ?></button></header>
<main class="setup-shell">
  <aside class="setup-intro" aria-label="初始化步骤">
    <div class="setup-brand"><img src="assets/mark.svg" width="36" height="36" alt="">PicAPI</div>
    <h1>初始化设置</h1><p>完成数据库与账号配置，开始管理你的图片服务。邮件服务可稍后配置。</p>
    <nav class="setup-steps"><?php foreach ($steps as $index => [$title, $description]): ?><button type="button" class="setup-step" data-go-step="<?= $index + 1 ?>" <?= $index === 3 ? 'disabled' : '' ?>><b><?= sprintf('%02d', $index + 1) ?></b><span><?= $title ?><small><?= $description ?></small></span></button><?php endforeach; ?></nav>
  </aside>
  <div class="setup-content">
    <section class="setup-card">
      <form id="setup-form" method="post" data-step="<?= $errorStep ?>" data-code-verified="<?= $setupCodeVerified ? '1' : '0' ?>">
        <input type="hidden" name="setup_draft_token" value="<?= html($draftWindow['token'] ?? '') ?>">
        <div class="setup-draft-notice"><span id="setup-draft-time" role="status"></span><button id="setup-restart" class="text-button" type="button" hidden>重新开始</button></div>
        <input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>">
        <header class="setup-card-head"><div><h2 id="setup-heading" tabindex="-1">连接数据库</h2><p id="setup-description">选择用于保存配置、账号和访问记录的数据库。</p></div><span id="setup-counter">01 / 03</span></header>
        <div class="setup-form-body">
          <?php if ($error !== null): ?><p class="setup-server-error notice error" role="alert"><?= html($error) ?></p><?php endif; ?>
          <section data-step="1">
            <p class="setup-field-title" id="database-label">数据库类型</p>
            <div class="database-choices" role="radiogroup" aria-labelledby="database-label"><?php foreach (['mysql'=>'MySQL','pgsql'=>'PostgreSQL','sqlserver'=>'SQL Server','sqlite'=>'SQLite'] as $value=>$label): ?><label class="database-choice"><input type="radio" name="db_type" value="<?= $value ?>" <?= $value === $databaseType ? 'checked' : '' ?>><?= setup_icon($value === 'sqlite' ? 'file' : 'database') ?><span><?= $label ?></span></label><?php endforeach; ?></div>
            <div id="database-fields" class="setup-field-grid">
              <div class="wide setup-inline-fields"><?= setup_field('db_host','主机地址','127.0.0.1','text','required') ?><?= setup_field('db_port','端口','3306','number','required min="1" max="65535"') ?></div>
              <?= setup_field('db_name','数据库名称','picapi','text','required') ?><?= setup_field('db_username','数据库用户名','picapi','text','required') ?>
              <div class="wide"><?= setup_field('db_password','数据库密码','','password','autocomplete="new-password" placeholder="输入数据库密码"') ?></div>
            </div>
            <p id="sqlite-hint" class="callout" hidden>SQLite 使用本地数据库文件，无需填写主机和账号。数据文件保存在受保护的数据库目录。</p>
            <div class="setup-code-block">
              <?= setup_field('setup_code','验证码','','password',($setupCodeVerified ? 'readonly autocomplete="off" placeholder="已验证"' : 'required autocomplete="off" placeholder="从项目根目录 setup.txt 复制粘贴"')) ?>
              <small id="setup-code-status" data-state="<?= $setupCodeVerified ? 'verified' : 'idle' ?>" role="status"><?= $setupCodeVerified ? '此浏览器已验证，完成初始化后会自动移除验证码文件。' : '打开项目根目录的 setup.txt，粘贴后自动校验；完成初始化后会自动移除验证码文件。' ?></small>
            </div>
            <details class="setup-optional" <?= isset($_POST['redis_enabled']) ? 'open' : '' ?>><summary><?= setup_icon('chevron') ?>高级选项 · Redis</summary>
              <label class="setup-check"><input type="checkbox" name="redis_enabled" <?= isset($_POST['redis_enabled']) ? 'checked' : '' ?>>启用 Redis 缓存</label>
              <div class="setup-field-grid" id="redis-fields"><?= setup_field('redis_host','Redis 主机','127.0.0.1') ?><?= setup_field('redis_password','Redis 密码','','password','placeholder="未设置可留空"') ?></div>
            </details>
          </section>
          <section data-step="2" hidden><div class="setup-field-grid">
            <div class="wide"><?= setup_field('username','管理员用户名','','text','required minlength="2" maxlength="48" autocomplete="username" placeholder="例如：admin"') ?></div>
            <div class="wide"><?= setup_field('admin_email','管理员邮箱','','email','required autocomplete="email" placeholder="admin@example.com"') ?></div>
            <?= setup_password_field('password','登录密码','required minlength="8" autocomplete="new-password" placeholder="至少 8 位字符"') ?><?= setup_password_field('password_confirmation','确认密码','required minlength="8" autocomplete="new-password" placeholder="再次输入密码" aria-describedby="setup-password-confirmation-error"','<small id="setup-password-confirmation-error" class="setup-password-error" role="alert"' . (($errorStep === 2 && $errorFocus === 'password_confirmation') ? '' : ' hidden') . '>两次输入的密码不一致。</small>') ?>
          </div></section>
          <section data-step="3" hidden>
            <div class="setup-field-grid" role="radiogroup" aria-label="邮件配置方式">
              <label class="setup-check"><input type="radio" name="smtp_mode" value="configure" <?= ($_POST['smtp_mode'] ?? 'configure') !== 'skip' ? 'checked' : '' ?>>现在配置</label>
              <label class="setup-check"><input type="radio" name="smtp_mode" value="skip" <?= ($_POST['smtp_mode'] ?? '') === 'skip' ? 'checked' : '' ?>>稍后配置（跳过）</label>
            </div>
            <p id="setup-mail-skip-note" class="callout" hidden>可先进入后台使用。配置邮件服务后，才能发送验证码、找回密码和接收邮件通知。</p>
            <div id="setup-mail-fields"><div class="setup-field-grid">
            <div class="wide setup-inline-fields"><?= setup_field('smtp_host','SMTP 服务器','','text','required placeholder="smtp.example.com"') ?><?= setup_field('smtp_port','端口','465','number','required min="1" max="65535"') ?></div>
            <div class="wide"><?= setup_field('smtp_username','发信账号','','text','required') ?></div><div class="wide"><?= setup_field('smtp_password','邮箱授权码','','password','required autocomplete="new-password"') ?></div>
            <div class="field wide"><label for="smtp_encryption">连接加密</label><select id="smtp_encryption" name="smtp_encryption"><?php foreach (['ssl'=>'SSL / TLS · 465 端口','tls'=>'STARTTLS · 587 端口'] as $value=>$label): ?><option value="<?= $value ?>" <?= ($_POST['smtp_encryption'] ?? 'ssl') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
          </div><div class="setup-sample"><span>测试收件人：<span id="setup-recipient">管理员邮箱</span></span><button type="button" class="text-button" data-go-step="2">修改邮箱</button></div></div></section>
          <p id="setup-feedback" class="setup-status" role="status" aria-live="polite"></p>
        </div>
        <footer class="setup-footer"><button type="button" class="text-button" id="setup-back">上一步</button><div><button type="button" class="button" id="setup-test">测试连接</button><button type="button" class="button primary" id="setup-next">下一步 <?= setup_icon('arrow') ?></button><button type="submit" class="button primary" id="setup-submit" hidden>完成初始化 <?= setup_icon('arrow') ?></button></div></footer>
      </form>
    </section>
  </div>
</main>
</body>
</html>
