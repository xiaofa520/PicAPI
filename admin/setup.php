<?php

require_once __DIR__ . '/common.php';

if (admin_is_initialized()) {
    admin_redirect(admin_is_logged_in() ? 'index.php' : 'login.php');
}

$flash = admin_pull_flash();
$errorMessage = null;
$defaults = admin_pending_database_settings() ?? [
    'driver' => 'sqlite',
    'path' => 'data/admin.sqlite',
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => '',
    'username' => '',
    'password' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_database') {
    admin_require_csrf();

    $rawSettings = [
        'driver' => (string) ($_POST['driver'] ?? 'sqlite'),
        'path' => (string) ($_POST['path'] ?? 'data/admin.sqlite'),
        'host' => (string) ($_POST['host'] ?? ''),
        'port' => (string) ($_POST['port'] ?? '3306'),
        'database' => (string) ($_POST['database'] ?? ''),
        'username' => (string) ($_POST['db_username'] ?? ''),
        'password' => (string) ($_POST['db_password'] ?? ''),
    ];

    try {
        $prepared = admin_validate_database_settings($rawSettings);
        admin_store_pending_database_settings($prepared);
        admin_redirect('setup_account.php');
    } catch (Throwable $throwable) {
        $defaults = $rawSettings;
        $errorMessage = $throwable->getMessage();
    }
}

admin_render_auth_page_start('初始化', [
    'eyebrow' => 'SETUP',
    'headline' => '先连库',
    'description' => '第 1 步',
    'step_label' => 'STEP',
    'step_title' => '数据库',
    'step_index' => 1,
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
                        <input type="hidden" name="action" value="save_database">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="form-grid">
                            <div class="field full">
                                <label for="driver">类型</label>
                                <select id="driver" name="driver" data-driver-select>
                                    <option value="sqlite" <?= (($defaults['driver'] ?? 'sqlite') === 'sqlite') ? 'selected' : '' ?>>SQLite</option>
                                    <option value="mysql" <?= (($defaults['driver'] ?? '') === 'mysql') ? 'selected' : '' ?>>MySQL</option>
                                </select>
                            </div>

                            <div class="field full" data-driver-panel="sqlite">
                                <label for="path">SQLite 路径</label>
                                <input id="path" type="text" name="path" value="<?= htmlspecialchars((string) ($defaults['path'] ?? 'data/admin.sqlite'), ENT_QUOTES, 'UTF-8') ?>" placeholder="data/admin.sqlite">
                            </div>

                            <div class="field" data-driver-panel="mysql">
                                <label for="host">主机</label>
                                <input id="host" type="text" name="host" value="<?= htmlspecialchars((string) ($defaults['host'] ?? '127.0.0.1'), ENT_QUOTES, 'UTF-8') ?>" placeholder="127.0.0.1">
                            </div>
                            <div class="field" data-driver-panel="mysql">
                                <label for="port">端口</label>
                                <input id="port" type="number" name="port" value="<?= htmlspecialchars((string) ($defaults['port'] ?? '3306'), ENT_QUOTES, 'UTF-8') ?>" placeholder="3306">
                            </div>
                            <div class="field" data-driver-panel="mysql">
                                <label for="database">库名</label>
                                <input id="database" type="text" name="database" value="<?= htmlspecialchars((string) ($defaults['database'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="field" data-driver-panel="mysql">
                                <label for="db_username">账号</label>
                                <input id="db_username" type="text" name="db_username" value="<?= htmlspecialchars((string) ($defaults['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="field full" data-driver-panel="mysql">
                                <label for="db_password">密码</label>
                                <input id="db_password" type="password" name="db_password" value="<?= htmlspecialchars((string) ($defaults['password'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                            </div>

                            <div class="field full" style="margin-top: 8px;">
                                <button type="submit">下一步</button>
                            </div>
                        </div>
                    </form>
                </section>
                <script>
                (() => {
                    const select = document.querySelector('[data-driver-select]');
                    const panels = Array.from(document.querySelectorAll('[data-driver-panel]'));
                    if (!select || !panels.length) {
                        return;
                    }

                    const sync = () => {
                        const value = select.value;
                        panels.forEach((panel) => {
                            const active = panel.getAttribute('data-driver-panel') === value;
                            panel.style.display = active ? '' : 'none';
                            panel.querySelectorAll('input, select').forEach((field) => {
                                if (field.name === 'driver') {
                                    return;
                                }
                                if (field.closest('[data-driver-panel="mysql"]')) {
                                    field.disabled = value !== 'mysql';
                                }
                                if (field.closest('[data-driver-panel="sqlite"]')) {
                                    field.disabled = value !== 'sqlite';
                                }
                            });
                        });
                    };

                    select.addEventListener('change', sync);
                    sync();
                })();
                </script>
<?php admin_render_auth_page_end(); ?>
