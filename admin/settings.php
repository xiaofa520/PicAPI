<?php

require_once __DIR__ . '/common.php';

admin_require_login();

$config = admin_load_config(true);
$storedEmailSettings = admin_email_service_config($config);
$emailFormState = [
    'host' => (string) ($storedEmailSettings['host'] ?? ''),
    'port' => (string) ($storedEmailSettings['port'] ?? 587),
    'encryption' => (string) ($storedEmailSettings['encryption'] ?? 'tls'),
    'username' => (string) ($storedEmailSettings['username'] ?? ''),
    'password' => '',
    'from_email' => (string) ($storedEmailSettings['from_email'] ?? ''),
    'from_name' => (string) ($storedEmailSettings['from_name'] ?? '图片后台'),
];
$testRecipient = admin_configured_admin_email($config);
$noticeTarget = (string) ($_GET['notice'] ?? '');
$flash = admin_pull_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_require_csrf();

    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_email_settings') {
            $postedPassword = (string) ($_POST['password'] ?? '');
            $emailSettings = [
                'enabled' => true,
                'host' => (string) ($_POST['host'] ?? ''),
                'port' => (string) ($_POST['port'] ?? ''),
                'encryption' => (string) ($_POST['encryption'] ?? 'tls'),
                'username' => (string) ($_POST['username'] ?? ''),
                'password' => $postedPassword !== '' ? $postedPassword : (string) ($storedEmailSettings['password'] ?? ''),
                'from_email' => (string) ($_POST['from_email'] ?? ''),
                'from_name' => (string) ($_POST['from_name'] ?? ''),
                'site_url' => admin_current_admin_base_url(),
            ];

            admin_assert_email_transport_supported($emailSettings);
            $config = admin_update_email_service_settings($emailSettings);
            admin_set_flash('success', '邮箱配置已保存');
            admin_redirect('settings.php?notice=mail');
        }

        if ($action === 'send_test_email') {
            $testRecipient = trim((string) ($_POST['test_email'] ?? ''));
            admin_assert_email_transport_supported(admin_email_service_config($config));
            admin_send_test_email($testRecipient, $config);
            admin_set_flash('success', '测试邮件已发送');
            admin_redirect('settings.php?notice=test');
        }
    } catch (Throwable $throwable) {
        $flash = [
            'type' => 'error',
            'message' => $throwable->getMessage(),
        ];

        if (($action ?? '') === 'save_email_settings') {
            $noticeTarget = 'mail';
            $emailFormState = [
                'host' => (string) ($_POST['host'] ?? ''),
                'port' => (string) ($_POST['port'] ?? ''),
                'encryption' => (string) ($_POST['encryption'] ?? 'tls'),
                'username' => (string) ($_POST['username'] ?? ''),
                'password' => '',
                'from_email' => (string) ($_POST['from_email'] ?? ''),
                'from_name' => (string) ($_POST['from_name'] ?? '图片后台'),
            ];
        } elseif (($action ?? '') === 'send_test_email') {
            $noticeTarget = 'test';
            $testRecipient = trim((string) ($_POST['test_email'] ?? ''));
        }
    }
}

$mailFlash = $noticeTarget === 'mail' ? $flash : null;
$testFlash = $noticeTarget === 'test' ? $flash : null;
$topFlash = ($noticeTarget !== 'mail' && $noticeTarget !== 'test') ? $flash : null;
$maskedPasswordNote = !empty($storedEmailSettings['password'])
    ? '已保存旧密码，留空则继续使用'
    : '当前还没有保存 SMTP 密码';
$mailPreviewSender = trim(($emailFormState['from_name'] ?: '图片后台') . ' <' . ($emailFormState['from_email'] ?: 'noreply@example.com') . '>');
$mailPreviewUrl = admin_build_admin_url('settings.php', [], $config);
$emailConfigured = !empty($storedEmailSettings['enabled']) && trim((string) ($storedEmailSettings['host'] ?? '')) !== '';

admin_render_layout_start('页面设置', 'settings.php', [
    'headline' => '页面设置',
    'description' => '简约设置与实时预览',
    'badges' => ['主题', '字体', '邮箱'],
    'auto_refresh_seconds' => 0,
    'mini_stats' => [
        [
            'label' => '账号',
            'value' => admin_current_username(),
            'note' => '管理员',
        ],
        [
            'label' => '邮箱',
            'value' => $emailConfigured ? '已就绪' : '未就绪',
            'note' => $emailConfigured ? 'SMTP 已保存' : 'SMTP 未配置',
        ],
    ],
]);
?>
<?php if ($topFlash !== null): ?>
    <div class="notice <?= htmlspecialchars($topFlash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($topFlash['message'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<style>
.settings-shell {
    display: grid;
    gap: 18px;
}

.settings-panel {
    display: grid;
    gap: 16px;
}

.settings-layout,
.mail-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.1fr) minmax(280px, 0.9fr);
    gap: 18px;
    align-items: start;
}

.settings-copy {
    color: color-mix(in srgb, var(--ink) 70%, var(--muted));
    font-size: 13px;
    line-height: 1.75;
}

.settings-form {
    display: grid;
    gap: 16px;
}

.fieldset-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.field.full {
    grid-column: 1 / -1;
}

.choice-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}

.font-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.choice-button {
    display: grid;
    gap: 8px;
    align-content: start;
    min-height: 116px;
    padding: 16px;
    text-align: left;
    border: 1px solid var(--line);
    border-radius: 18px;
    background: var(--panel-strong);
    color: var(--ink);
    cursor: pointer;
    transition: border-color 140ms ease, background 140ms ease, transform 140ms ease;
}

.choice-button:hover {
    transform: translateY(-1px);
    border-color: var(--line-strong);
    background: var(--panel-soft);
}

.choice-button.is-active {
    border-color: var(--accent);
    background: var(--accent-soft);
}

.choice-title {
    color: var(--ink);
    font-size: 17px;
    font-weight: 700;
    line-height: 1.2;
}

.choice-copy {
    color: color-mix(in srgb, var(--ink) 74%, var(--muted));
    font-size: 13px;
    line-height: 1.7;
}

.inline-actions,
.size-presets,
.settings-toolbar,
.background-actions,
.preview-meta {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.inline-preview {
    display: grid;
    gap: 14px;
    padding: 18px;
    border: 1px solid var(--line);
    border-radius: 20px;
    background: linear-gradient(180deg, color-mix(in srgb, var(--panel-strong) 95%, #fff 5%), var(--panel));
}

.inline-preview-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.preview-card {
    display: grid;
    gap: 12px;
    padding: 18px;
    border: 1px solid var(--line);
    border-radius: 18px;
    background: var(--panel-soft);
}

.preview-heading {
    margin: 0;
    font-size: 1.2em;
    line-height: 1.2;
    color: var(--ink);
}

.preview-copy {
    margin: 0;
    color: color-mix(in srgb, var(--ink) 74%, var(--muted));
    font-size: 13px;
    line-height: 1.75;
}

.preview-pills {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.preview-pill {
    display: inline-flex;
    align-items: center;
    padding: 8px 10px;
    border-radius: 999px;
    border: 1px solid var(--line);
    background: var(--panel-strong);
    color: var(--ink);
    font-size: 12px;
}

.size-panel {
    display: grid;
    gap: 12px;
}

.size-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.size-value {
    min-width: 72px;
    padding: 8px 10px;
    border: 1px solid var(--line);
    border-radius: 999px;
    text-align: center;
    font-weight: 700;
    color: var(--ink);
    background: color-mix(in srgb, var(--panel) 90%, transparent);
    font-family: "JetBrains Mono", Consolas, monospace;
}

.size-range {
    width: 100%;
    accent-color: var(--accent);
}

.size-preset {
    width: auto;
    min-width: 72px;
    padding: 10px 12px;
    border-radius: 999px;
    border: 1px solid var(--line);
    background: var(--panel-strong);
    color: var(--ink);
}

.size-preset.is-active {
    background: var(--accent-soft);
    border-color: var(--line-strong);
}

.mail-preview {
    display: grid;
    gap: 0;
    border: 1px solid var(--line);
    border-radius: 18px;
    overflow: hidden;
    background: var(--panel-strong);
}

.mail-preview-head {
    padding: 14px 16px;
    background: var(--panel-soft);
    border-bottom: 1px solid var(--line);
}

.mail-preview-body {
    padding: 18px 16px;
    display: grid;
    gap: 12px;
}

.mail-preview-tag {
    font-size: 12px;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--muted);
    font-weight: 700;
}

.mail-preview-title {
    font-size: 22px;
    line-height: 1.2;
    color: var(--ink);
    font-weight: 700;
}

.mail-preview-copy {
    color: var(--ink);
    font-size: 14px;
    line-height: 1.8;
    opacity: 0.85;
}

.mail-preview-button {
    display: inline-flex;
    width: fit-content;
    align-items: center;
    justify-content: center;
    padding: 12px 18px;
    border-radius: 999px;
    background: var(--accent);
    color: var(--button-primary-text);
    font-size: 14px;
    font-weight: 700;
}

.mail-preview-meta {
    display: grid;
    gap: 8px;
    padding: 14px 16px;
    border: 1px solid var(--line);
    border-radius: 16px;
    background: var(--panel-soft);
}

.mail-preview-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.7;
}

.mail-preview-row strong {
    color: var(--ink);
    word-break: break-all;
}

html[data-admin-theme="dark"] .choice-button,
html[data-admin-theme="dark"] .preview-card,
html[data-admin-theme="dark"] .inline-preview {
    background: color-mix(in srgb, var(--panel) 92%, transparent);
}

@media (max-width: 1100px) {
    .settings-layout,
    .mail-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 760px) {
    .choice-grid,
    .font-grid,
    .fieldset-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="content-stack">
    <div class="settings-shell">
        <section class="panel section settings-panel">
            <div class="section-head">
                <div>
                    <div class="eyebrow">Theme</div>
                    <h2 class="section-title">主题</h2>
                </div>
                <div class="section-kicker">设置和预览放在一起</div>
            </div>

            <div class="settings-layout">
                <div class="settings-form">
                    <div class="choice-grid">
                        <button type="button" class="choice-button" data-theme-option="light">
                            <div class="choice-title">浅色</div>
                            <div class="choice-copy">更亮、更清爽，白天更适合</div>
                        </button>
                        <button type="button" class="choice-button" data-theme-option="dark">
                            <div class="choice-title">深色</div>
                            <div class="choice-copy">更稳、更柔和，夜间更舒服</div>
                        </button>
                        <button type="button" class="choice-button" data-theme-option="system">
                            <div class="choice-title">跟随系统</div>
                            <div class="choice-copy">自动跟着系统主题切换</div>
                        </button>
                    </div>
                    <div class="settings-copy" id="theme-status-copy">当前主题会按你的设置即时生效。</div>
                </div>

                <div class="inline-preview">
                    <div class="inline-preview-head">
                        <strong>预览</strong>
                        <span class="preview-pill" id="preview-theme-pill">主题</span>
                    </div>
                    <div class="preview-card">
                        <h3 class="preview-heading">主题效果</h3>
                        <p class="preview-copy" id="theme-preview-copy">主题切换会立刻反映在这里。</p>
                        <div class="preview-pills">
                            <span class="preview-pill" id="theme-preview-mode-pill">模式</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel section settings-panel">
            <div class="section-head">
                <div>
                    <div class="eyebrow">Font</div>
                    <h2 class="section-title">字体</h2>
                </div>
                <div class="section-kicker">右侧就是当前字体效果</div>
            </div>

            <div class="settings-layout">
                <div class="settings-form">
                    <div class="font-grid">
                        <button type="button" class="choice-button" data-font-option="system">
                            <div class="choice-title">系统</div>
                            <div class="choice-copy">稳定、熟悉、兼容性最好</div>
                        </button>
                        <button type="button" class="choice-button" data-font-option="modern">
                            <div class="choice-title">现代</div>
                            <div class="choice-copy">更有控制台和面板感</div>
                        </button>
                        <button type="button" class="choice-button" data-font-option="chinese">
                            <div class="choice-title">中文</div>
                            <div class="choice-copy">更适合中文内容较多的界面</div>
                        </button>
                        <button type="button" class="choice-button" data-font-option="serif">
                            <div class="choice-title">衬线</div>
                            <div class="choice-copy">对比更强，风格更明显</div>
                        </button>
                    </div>
                </div>

                <div class="inline-preview">
                    <div class="inline-preview-head">
                        <strong>预览</strong>
                        <span class="preview-pill" id="preview-font-pill">字体</span>
                    </div>
                    <div class="preview-card">
                        <h3 class="preview-heading" id="font-preview-heading">图片 API 控制台</h3>
                        <p class="preview-copy" id="font-preview-copy">字体变化会立刻反映在这里。</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel section settings-panel">
            <div class="section-head">
                <div>
                    <div class="eyebrow">Scale</div>
                    <h2 class="section-title">字号</h2>
                </div>
                <div class="section-kicker">调整和预览同步变化</div>
            </div>

            <div class="settings-layout">
                <div class="settings-form">
                    <div class="size-panel">
                        <div class="size-header">
                            <div class="settings-copy">拖动滑块后会立刻生效。</div>
                            <div id="font-size-value" class="size-value">16 px</div>
                        </div>
                        <input id="font-size-range" class="size-range" type="range" min="14" max="20" step="1" value="16">
                        <div class="size-presets">
                            <button type="button" class="size-preset" data-font-size-option="14">14 px</button>
                            <button type="button" class="size-preset" data-font-size-option="16">16 px</button>
                            <button type="button" class="size-preset" data-font-size-option="18">18 px</button>
                            <button type="button" class="size-preset" data-font-size-option="20">20 px</button>
                        </div>
                    </div>

                    <div class="settings-toolbar">
                        <button type="button" id="reset-ui-settings" class="secondary">恢复默认</button>
                    </div>
                </div>

                <div class="inline-preview">
                    <div class="inline-preview-head">
                        <strong>预览</strong>
                        <span class="preview-pill" id="preview-size-pill">16 px</span>
                    </div>
                    <div class="preview-card">
                        <h3 class="preview-heading" id="size-preview-heading">阅读尺度</h3>
                        <p class="preview-copy" id="size-preview-copy">这段文字会随着滑块一起变化。</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel section settings-panel">
            <div class="section-head">
                <div>
                    <div class="eyebrow">Mail</div>
                    <h2 class="section-title">邮箱</h2>
                </div>
                <div class="section-kicker">配置和发信预览一起看</div>
            </div>

            <?php if ($mailFlash !== null): ?>
                <div class="notice <?= htmlspecialchars($mailFlash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($mailFlash['message'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <div class="mail-layout">
                <form method="post" class="settings-form">
                    <input type="hidden" name="action" value="save_email_settings">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

                    <div class="fieldset-grid">
                        <div class="field">
                            <label for="host">SMTP</label>
                            <input id="host" type="text" name="host" value="<?= htmlspecialchars($emailFormState['host'], ENT_QUOTES, 'UTF-8') ?>" placeholder="smtp.example.com">
                        </div>
                        <div class="field">
                            <label for="port">端口</label>
                            <input id="port" type="number" name="port" value="<?= htmlspecialchars($emailFormState['port'], ENT_QUOTES, 'UTF-8') ?>" placeholder="587">
                        </div>
                        <div class="field">
                            <label for="encryption">加密</label>
                            <select id="encryption" name="encryption">
                                <option value="tls" <?= $emailFormState['encryption'] === 'tls' ? 'selected' : '' ?>>TLS</option>
                                <option value="ssl" <?= $emailFormState['encryption'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                <option value="none" <?= $emailFormState['encryption'] === 'none' ? 'selected' : '' ?>>无</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="username">账号</label>
                            <input id="username" type="text" name="username" value="<?= htmlspecialchars($emailFormState['username'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="field full">
                            <label for="password">密码</label>
                            <input id="password" type="password" name="password" value="" autocomplete="off" placeholder="留空则继续使用当前密码">
                            <div class="settings-copy"><?= htmlspecialchars($maskedPasswordNote, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="field">
                            <label for="from_email">发件邮箱</label>
                            <input id="from_email" type="email" name="from_email" value="<?= htmlspecialchars($emailFormState['from_email'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="field">
                            <label for="from_name">发件名</label>
                            <input id="from_name" type="text" name="from_name" value="<?= htmlspecialchars($emailFormState['from_name'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>

                    <div class="inline-actions">
                        <button type="submit">保存配置</button>
                    </div>
                </form>

                <div class="inline-preview">
                    <div class="inline-preview-head">
                        <strong>预览</strong>
                        <span class="preview-pill" id="mail-preview-status-pill"><?= $emailConfigured ? '已就绪' : '未就绪' ?></span>
                    </div>
                    <div class="mail-preview">
                        <div class="mail-preview-head">
                            <div class="settings-copy" style="margin:0;">发件人: <span id="mail-preview-sender"><?= htmlspecialchars($mailPreviewSender, ENT_QUOTES, 'UTF-8') ?></span></div>
                        </div>
                        <div class="mail-preview-body">
                            <div class="mail-preview-tag">Image API</div>
                            <div class="mail-preview-title">安全提醒</div>
                            <div class="mail-preview-copy">找回密码、登录提醒和安全通知都会使用这种样式。</div>
                            <div><span class="mail-preview-button">打开后台</span></div>
                            <div class="mail-preview-meta">
                                <div class="mail-preview-row"><span>地址</span><strong id="mail-preview-url"><?= htmlspecialchars($mailPreviewUrl, ENT_QUOTES, 'UTF-8') ?></strong></div>
                                <div class="mail-preview-row"><span>名称</span><strong id="mail-preview-name"><?= htmlspecialchars($emailFormState['from_name'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                                <div class="mail-preview-row"><span>邮箱</span><strong id="mail-preview-email"><?= htmlspecialchars($emailFormState['from_email'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="panel section settings-panel">
            <div class="section-head">
                <div>
                    <div class="eyebrow">Mail Test</div>
                    <h2 class="section-title">测试发信</h2>
                </div>
                <div class="section-kicker">发一封测试邮件确认 SMTP</div>
            </div>

            <?php if ($testFlash !== null): ?>
                <div class="notice <?= htmlspecialchars($testFlash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($testFlash['message'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post" class="settings-form">
                <input type="hidden" name="action" value="send_test_email">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

                <div class="fieldset-grid">
                    <div class="field full">
                        <label for="test_email">测试邮箱</label>
                        <input id="test_email" type="email" name="test_email" value="<?= htmlspecialchars($testRecipient, ENT_QUOTES, 'UTF-8') ?>" placeholder="name@example.com" required>
                    </div>
                </div>

                <div class="inline-actions">
                    <button type="submit">发送测试</button>
                </div>
            </form>
        </section>
    </div>
</div>

<script>
(() => {
    const setup = () => {
        const manager = window.AdminUiSettings;
        if (!manager || document.body.dataset.settingsBound === '1') {
            return;
        }

        document.body.dataset.settingsBound = '1';

        const themeButtons = Array.from(document.querySelectorAll('[data-theme-option]'));
        const fontButtons = Array.from(document.querySelectorAll('[data-font-option]'));
        const fontSizeButtons = Array.from(document.querySelectorAll('[data-font-size-option]'));
        const fontSizeRange = document.getElementById('font-size-range');
        const fontSizeValue = document.getElementById('font-size-value');
        const resetButton = document.getElementById('reset-ui-settings');
        const themeStatusCopy = document.getElementById('theme-status-copy');
        const previewThemePill = document.getElementById('preview-theme-pill');
        const themePreviewCopy = document.getElementById('theme-preview-copy');
        const themePreviewModePill = document.getElementById('theme-preview-mode-pill');
        const previewFontPill = document.getElementById('preview-font-pill');
        const fontPreviewHeading = document.getElementById('font-preview-heading');
        const fontPreviewCopy = document.getElementById('font-preview-copy');
        const previewSizePill = document.getElementById('preview-size-pill');
        const sizePreviewHeading = document.getElementById('size-preview-heading');
        const sizePreviewCopy = document.getElementById('size-preview-copy');
        const fromNameInput = document.getElementById('from_name');
        const fromEmailInput = document.getElementById('from_email');
        const mailPreviewSender = document.getElementById('mail-preview-sender');
        const mailPreviewName = document.getElementById('mail-preview-name');
        const mailPreviewEmail = document.getElementById('mail-preview-email');
        const mailPreviewStatusPill = document.getElementById('mail-preview-status-pill');

        const themeLabelMap = {
            light: '浅色',
            dark: '深色',
            system: '跟随系统',
        };

        const fontLabelMap = {
            system: '系统',
            modern: '现代',
            chinese: '中文',
            serif: '衬线',
        };

        const getEffectiveTheme = (theme) => {
            if (theme === 'system') {
                return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            return theme;
        };

        const syncMailPreview = () => {
            const name = (fromNameInput?.value || '').trim() || '图片后台';
            const email = (fromEmailInput?.value || '').trim() || 'noreply@example.com';
            if (mailPreviewSender) {
                mailPreviewSender.textContent = `${name} <${email}>`;
            }
            if (mailPreviewName) {
                mailPreviewName.textContent = name;
            }
            if (mailPreviewEmail) {
                mailPreviewEmail.textContent = email;
            }
            if (mailPreviewStatusPill) {
                mailPreviewStatusPill.textContent = email && name ? '已就绪' : '未就绪';
            }
        };

        const syncView = (settings) => {
            themeButtons.forEach((button) => {
                button.classList.toggle('is-active', button.dataset.themeOption === settings.theme);
            });

            fontButtons.forEach((button) => {
                button.classList.toggle('is-active', button.dataset.fontOption === settings.font);
            });

            fontSizeButtons.forEach((button) => {
                button.classList.toggle('is-active', Number(button.dataset.fontSizeOption) === Number(settings.fontSize));
            });

            if (fontSizeRange) {
                fontSizeRange.value = String(settings.fontSize);
            }
            if (fontSizeValue) {
                fontSizeValue.textContent = `${settings.fontSize} px`;
            }

            const effectiveTheme = getEffectiveTheme(settings.theme);

            if (themeStatusCopy) {
                themeStatusCopy.textContent = settings.theme === 'system'
                    ? `已设为跟随系统，当前是${effectiveTheme === 'dark' ? '深色' : '浅色'}。`
                    : `当前固定使用${themeLabelMap[settings.theme]}。`;
            }

            if (previewThemePill) {
                previewThemePill.textContent = themeLabelMap[settings.theme];
            }
            if (themePreviewModePill) {
                themePreviewModePill.textContent = effectiveTheme === 'dark' ? '深色' : '浅色';
            }
            if (themePreviewCopy) {
                themePreviewCopy.textContent = settings.theme === 'system'
                    ? '会随着系统主题自动切换。'
                    : `当前使用${themeLabelMap[settings.theme]}。`;
            }

            if (previewFontPill) {
                previewFontPill.textContent = fontLabelMap[settings.font];
            }
            if (fontPreviewHeading) {
                fontPreviewHeading.textContent = '图片 API 控制台';
            }
            if (fontPreviewCopy) {
                fontPreviewCopy.textContent = `当前字体：${fontLabelMap[settings.font]}。`;
            }

            if (previewSizePill) {
                previewSizePill.textContent = `${settings.fontSize} px`;
            }
            if (sizePreviewHeading) {
                sizePreviewHeading.style.fontSize = `${Math.max(18, settings.fontSize + 4)}px`;
            }
            if (sizePreviewCopy) {
                sizePreviewCopy.style.fontSize = `${settings.fontSize}px`;
            }

        };

        themeButtons.forEach((button) => {
            button.addEventListener('click', () => {
                manager.set({ theme: button.dataset.themeOption });
            });
        });

        fontButtons.forEach((button) => {
            button.addEventListener('click', () => {
                manager.set({ font: button.dataset.fontOption });
            });
        });

        fontSizeButtons.forEach((button) => {
            button.addEventListener('click', () => {
                manager.set({ fontSize: Number(button.dataset.fontSizeOption) });
            });
        });

        if (fontSizeRange) {
            fontSizeRange.addEventListener('input', () => {
                manager.set({ fontSize: Number(fontSizeRange.value) });
            });
        }

        if (resetButton) {
            resetButton.addEventListener('click', () => {
                manager.reset();
            });
        }

        fromNameInput?.addEventListener('input', syncMailPreview);
        fromEmailInput?.addEventListener('input', syncMailPreview);

        manager.subscribe(syncView);
        syncMailPreview();
    };

    setup();
    window.addEventListener('admin-ui-settings-ready', setup, { once: true });
})();
</script>
<?php admin_render_layout_end(); ?>
