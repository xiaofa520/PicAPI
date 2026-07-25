<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/setup_runtime.php';
require_once __DIR__ . '/../lib/smtp_runtime.php';

initialize_storage();
start_secure_session();
send_admin_security_headers();
if (admin_is_configured()) {
    redirect('login.php');
}
// An existing installation record means this site has already completed (or
// started) installation. Do not expose the setup wizard again if its database
// later becomes unavailable.
if (installation_config() !== null) {
    abort_request(503, '系统已经初始化，但管理员数据库当前不可用。请修复数据库连接后访问登录页。');
}

$error = null;
$errorStep = 1;
$errorFocus = 'db_host';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verify_csrf();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $email = trim((string) ($_POST['admin_email'] ?? ''));
        if (!preg_match('/^[\p{Han}a-zA-Z0-9._-]{2,48}$/u', $username) || strlen($password) < 8 || !hash_equals($password, (string) ($_POST['password_confirmation'] ?? '')) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errorStep = 2;
            $errorFocus = !hash_equals($password, (string) ($_POST['password_confirmation'] ?? '')) ? 'password_confirmation' : (filter_var($email, FILTER_VALIDATE_EMAIL) === false ? 'admin_email' : (strlen($password) < 8 ? 'password' : 'username'));
            throw new RuntimeException('请检查管理员用户名、邮箱和两次输入的密码。');
        }

        $database = setup_database_options($_POST);
        prepare_setup_database($_POST);
        $errorStep = 3;
        $errorFocus = 'smtp_host';
        smtp_test_message($_POST, $email);
        if (!create_database_admin($database, $username, $email, $password)) {
            redirect('login.php');
        }
        save_installation_config([
            'database' => $database,
            'redis' => ['enabled' => isset($_POST['redis_enabled']), 'host' => trim((string) ($_POST['redis_host'] ?? '')), 'password' => (string) ($_POST['redis_password'] ?? '')],
            'smtp' => ['host' => trim((string) ($_POST['smtp_host'] ?? '')), 'port' => trim((string) ($_POST['smtp_port'] ?? '')), 'username' => trim((string) ($_POST['smtp_username'] ?? '')), 'password' => (string) ($_POST['smtp_password'] ?? ''), 'encryption' => (string) ($_POST['smtp_encryption'] ?? 'ssl')],
            'admin_email' => $email,
            'created_at' => gmdate('c'),
            // This changes on every completed initialization. It is checked
            // against each browser session so no old login token is reused.
            'session_generation' => bin2hex(random_bytes(32)),
        ]);
        begin_admin_session($username);
        redirect('dashboard.php');
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

function fallback_setup_template(): string
{
    return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PicAPI - 系统安装与初始化</title>
<script src="https://cdn.tailwindcss.com/3.4.17"></script>
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.js" integrity="sha384-LtmWBcrD5iuFIR4sFphS8IiiaftkypL5dJzLKywbyd9ATLB7ZbPz3JYnI9nvXHkV" crossorigin="anonymous"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap');
        body { font-family: 'Inter', 'Helvetica Neue', sans-serif; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-2xl bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl p-6 md:p-8 space-y-8 my-8">
        <div class="text-center">
            <div class="inline-flex p-3 bg-slate-800 text-indigo-400 rounded-xl mb-3 border border-slate-700/50 shadow-inner">
                <i data-lucide="wrench" class="w-8 h-8"></i>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white">PicAPI Initialization</h1>
            <p class="text-sm text-slate-400 mt-1">欢迎使用随机图片服务，请跟随向导完成系统部署配置</p>
        </div>

        <div class="relative flex justify-between items-center max-w-md mx-auto px-4">
            <div class="absolute inset-x-0 top-1/2 -translate-y-1/2 h-0.5 bg-slate-800 z-0"></div>
            <div id="step-progress-line" class="absolute left-0 top-1/2 -translate-y-1/2 h-0.5 bg-indigo-500 transition-all duration-300 z-0" style="width: 0%;"></div>
            <div class="step-node relative z-10 flex flex-col items-center gap-2">
                <div id="node-1" class="w-8 h-8 rounded-full bg-indigo-600 text-white flex items-center justify-center text-xs font-semibold ring-4 ring-indigo-500/20 transition duration-300">1</div>
                <span id="text-1" class="text-[11px] font-medium text-indigo-400 transition">环境库配置</span>
            </div>
            <div class="step-node relative z-10 flex flex-col items-center gap-2">
                <div id="node-2" class="w-8 h-8 rounded-full bg-slate-800 text-slate-400 flex items-center justify-center text-xs font-semibold border border-slate-700 transition duration-300">2</div>
                <span id="text-2" class="text-[11px] font-medium text-slate-500 transition">管理员设置</span>
            </div>
            <div class="step-node relative z-10 flex flex-col items-center gap-2">
                <div id="node-3" class="w-8 h-8 rounded-full bg-slate-800 text-slate-400 flex items-center justify-center text-xs font-semibold border border-slate-700 transition duration-300">3</div>
                <span id="text-3" class="text-[11px] font-medium text-slate-500 transition">邮件服务</span>
            </div>
        </div>

        <hr class="border-slate-800">

        <form id="install-form" onsubmit="handleSubmit(event)" class="space-y-6">
            <div id="step-content-1" class="step-content space-y-6">
                <div>
                    <h3 class="text-sm font-semibold text-slate-200 mb-3 flex items-center gap-2">
                        <i data-lucide="database" class="w-4 h-4 text-indigo-400"></i>选择系统主要数据库
                    </h3>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <label class="relative bg-slate-950 border-2 border-indigo-600 rounded-xl p-3.5 flex flex-col items-center justify-center text-center cursor-pointer hover:border-indigo-500/60 transition group" id="db-label-mysql">
                            <input type="radio" name="db_type" value="mysql" checked onchange="handleDbChange('mysql')" class="absolute opacity-0">
                            <div class="text-indigo-400 group-hover:scale-105 transition mb-2"><i data-lucide="layers" class="w-6 h-6"></i></div>
                            <span class="text-xs font-semibold text-white">MySQL</span>
                        </label>
                        <label class="relative bg-slate-950 border-2 border-slate-800 rounded-xl p-3.5 flex flex-col items-center justify-center text-center cursor-pointer hover:border-slate-700 transition group" id="db-label-sqlserver">
                            <input type="radio" name="db_type" value="sqlserver" onchange="handleDbChange('sqlserver')" class="absolute opacity-0">
                            <div class="text-slate-400 group-hover:scale-105 transition mb-2"><i data-lucide="server" class="w-6 h-6"></i></div>
                            <span class="text-xs font-semibold text-slate-300">SQLServer</span>
                        </label>
                        <label class="relative bg-slate-950 border-2 border-slate-800 rounded-xl p-3.5 flex flex-col items-center justify-center text-center cursor-pointer hover:border-slate-700 transition group" id="db-label-pgsql">
                            <input type="radio" name="db_type" value="pgsql" onchange="handleDbChange('pgsql')" class="absolute opacity-0">
                            <div class="text-slate-400 group-hover:scale-105 transition mb-2"><i data-lucide="box" class="w-6 h-6"></i></div>
                            <span class="text-xs font-semibold text-slate-300">PostgreSQL</span>
                        </label>
                        <label class="relative bg-slate-950 border-2 border-slate-800 rounded-xl p-3.5 flex flex-col items-center justify-center text-center cursor-pointer hover:border-slate-700 transition group" id="db-label-sqlite">
                            <input type="radio" name="db_type" value="sqlite" onchange="handleDbChange('sqlite')" class="absolute opacity-0">
                            <div class="text-slate-400 group-hover:scale-105 transition mb-2"><i data-lucide="file-text" class="w-6 h-6"></i></div>
                            <span class="text-xs font-semibold text-slate-300">SQLite</span>
                        </label>
                    </div>
                </div>

                <div id="db-credentials-fields" class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div class="sm:col-span-2">
                        <label class="block text-slate-400 mb-1.5">主机地址 / 端口</label>
                        <div class="flex gap-2">
                            <input type="text" placeholder="127.0.0.1" class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                            <input type="text" placeholder="3306" class="w-24 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40 text-center">
                        </div>
                    </div>
                    <div>
                        <label class="block text-slate-400 mb-1.5">数据库用户</label>
                        <input type="text" placeholder="root" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                    </div>
                    <div>
                        <label class="block text-slate-400 mb-1.5">数据库密码</label>
                        <input type="password" placeholder="••••••••" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-slate-400 mb-1.5">库名 (Database Name)</label>
                        <input type="text" placeholder="picapi_db" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                    </div>
                </div>

                <div class="bg-slate-950 border border-slate-800 rounded-xl p-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="p-1.5 bg-red-500/10 text-red-400 border border-red-500/20 rounded-md">
                                <i data-lucide="zap" class="w-4 h-4"></i>
                            </div>
                            <div>
                                <h4 class="text-xs font-semibold text-white">使用 Redis 进行高速缓存控制</h4>
                                <p class="text-[11px] text-slate-500">开启后将大幅降低数据库穿透率，缓存高频分流数据</p>
                            </div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" id="toggle-redis" onchange="toggleRedisFields(this)" class="sr-only peer">
                            <div class="w-9 h-5 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-slate-400 after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600 peer-checked:after:bg-white"></div>
                        </label>
                    </div>
                    <div id="redis-fields" class="hidden grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs pt-2 border-t border-slate-800/60">
                        <div>
                            <label class="block text-slate-400 mb-1">Redis 主机</label>
                            <input type="text" placeholder="127.0.0.1" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-white focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-slate-400 mb-1">密码 (可选)</label>
                            <input type="password" placeholder="无密码请留空" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-1.5 text-white focus:outline-none">
                        </div>
                    </div>
                </div>
            </div>

            <div id="step-content-2" class="step-content hidden space-y-5 text-xs">
                <div>
                    <h3 class="text-sm font-semibold text-slate-200 mb-1 flex items-center gap-2">
                        <i data-lucide="shield-alert" class="w-4 h-4 text-emerald-400"></i>初始化创建主管理单元
                    </h3>
                    <p class="text-slate-400 text-[11px]">该账户拥有最高特权，可登录后台管理控制台。</p>
                </div>
                <div class="space-y-4">
                    <div>
                        <label class="block text-slate-400 mb-1.5">管理员用户名 (Admin Name)</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-500"><i data-lucide="user" class="w-4 h-4"></i></span>
                            <input type="text" required placeholder="例如: admin / 管理员" class="w-full bg-slate-800 border border-slate-700 rounded-lg pl-9 pr-4 py-2.5 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                        </div>
                    </div>
                    <div>
                        <label class="block text-slate-400 mb-1.5">管理员绑定邮箱 (Email)</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-500"><i data-lucide="mail" class="w-4 h-4"></i></span>
                            <input type="email" required placeholder="admin@yourdomain.com" class="w-full bg-slate-800 border border-slate-700 rounded-lg pl-9 pr-4 py-2.5 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-slate-400 mb-1.5">设置登录密码</label>
                            <input type="password" required placeholder="请设置强密码" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                        </div>
                        <div>
                            <label class="block text-slate-400 mb-1.5">重复确认密码</label>
                            <input type="password" required placeholder="请再次键入密码" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                        </div>
                    </div>
                </div>
            </div>

            <div id="step-content-3" class="step-content hidden space-y-5 text-xs">
                <div>
                    <h3 class="text-sm font-semibold text-slate-200 mb-1 flex items-center gap-2">
                        <i data-lucide="send" class="w-4 h-4 text-amber-400"></i>外部发信网关配置 (SMTP)
                    </h3>
                    <p class="text-slate-400 text-[11px]">配置此阶段后，系统才可发送验证码、安全审计和密码重置等通知邮件。</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-slate-400 mb-1.5">SMTP 服务器地址</label>
                        <input type="text" placeholder="smtp.exmail.qq.com / smtp.gmail.com" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                    </div>
                    <div>
                        <label class="block text-slate-400 mb-1.5">发信端口</label>
                        <input type="text" placeholder="465" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-white text-center focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-slate-400 mb-1.5">发信账号 (通常同邮箱地址)</label>
                        <input type="email" placeholder="noreply@yourdomain.com" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                    </div>
                    <div>
                        <label class="block text-slate-400 mb-1.5">发信凭证 / 授权密码</label>
                        <input type="password" placeholder="••••••••••••" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                    </div>
                    <div class="sm:col-span-3">
                        <label class="block text-slate-400 mb-1.5">加密策略协议</label>
                        <select class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2.5 text-white focus:outline-none">
                            <option value="ssl">SSL / TLS 加密安全传输 (推荐端口 465)</option>
                            <option value="tls">STARTTLS 轻量转换协议 (推荐端口 587)</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between pt-4 border-t border-slate-800 text-xs">
                <button type="button" id="prev-btn" onclick="moveStep(-1)" class="invisible px-4 py-2 bg-slate-800 border border-slate-700 text-slate-300 rounded-lg hover:bg-slate-700 transition flex items-center gap-1.5">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i>上一步
                </button>
                <div class="flex gap-2">
                    <button type="button" id="test-conn-btn" onclick="simulateTestConnection()" class="px-4 py-2 bg-slate-950 border border-slate-800 hover:border-slate-700 text-indigo-400 rounded-lg transition flex items-center gap-1.5">
                        <i data-lucide="link" class="w-4 h-4"></i>测试连接
                    </button>
                    <button type="button" id="next-btn" onclick="moveStep(1)" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg transition shadow-lg shadow-indigo-600/10 flex items-center gap-1.5">
                        下一步<i data-lucide="arrow-right" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>
<script>
window.lucide = window.lucide || { createIcons() {} };
let currentStep = 1;
const totalSteps = 3;
function handleDbChange(type) {
    ['mysql', 'sqlserver', 'pgsql', 'sqlite'].forEach(db => {
        const label = document.getElementById('db-label-' + db);
        const icon = label.querySelector('div');
        const labelText = label.querySelector('span');
        const active = db === type;
        label.classList.toggle('border-indigo-600', active);
        label.classList.toggle('border-slate-800', !active);
        icon.classList.toggle('text-indigo-400', active);
        icon.classList.toggle('text-slate-400', !active);
        labelText.classList.toggle('text-white', active);
        labelText.classList.toggle('text-slate-300', !active);
    });
    document.getElementById('db-credentials-fields').classList.toggle('opacity-30', type === 'sqlite');
    document.getElementById('db-credentials-fields').classList.toggle('pointer-events-none', type === 'sqlite');
}
function toggleRedisFields(input) { document.getElementById('redis-fields').classList.toggle('hidden', !input.checked); }
function updateStepUI() {
    for (let step = 1; step <= totalSteps; step++) {
        document.getElementById('step-content-' + step).classList.toggle('hidden', step !== currentStep);
        const active = step <= currentStep;
        document.getElementById('node-' + step).className = 'w-8 h-8 rounded-full flex items-center justify-center text-xs font-semibold transition duration-300 ' + (active ? 'bg-indigo-600 text-white ring-4 ring-indigo-500/20' : 'bg-slate-800 text-slate-400 border border-slate-700');
        document.getElementById('text-' + step).className = 'text-[11px] font-medium transition ' + (active ? 'text-indigo-400' : 'text-slate-500');
    }
    document.getElementById('prev-btn').classList.toggle('invisible', currentStep === 1);
    const nextBtn = document.getElementById('next-btn');
    const testBtn = document.getElementById('test-conn-btn');
    if (currentStep === totalSteps) {
        testBtn.classList.add('hidden');
        nextBtn.type = 'submit';
        nextBtn.innerHTML = '<i data-lucide="check" class="w-4 h-4"></i> 写入环境并初始化';
    } else {
        nextBtn.type = 'button';
        nextBtn.innerHTML = '下一步<i data-lucide="arrow-right" class="w-4 h-4"></i>';
    }
    document.getElementById('step-progress-line').style.width = (((currentStep - 1) / (totalSteps - 1)) * 100) + '%';
    window.lucide.createIcons();
}
function moveStep(direction) { const next = currentStep + direction; if (next < 1 || next > totalSteps) return; currentStep = next; updateStepUI(); }
function handleSubmit(event) { event.preventDefault(); }
window.addEventListener('DOMContentLoaded', () => { handleDbChange('mysql'); updateStepUI(); window.lucide.createIcons(); });
</script>
</body>
</html>
HTML;
}

// The setup screen is intentionally self-contained. Deployment does not rely
// on 2.html or its embedded styles, so a missing static template cannot block
// database recovery and re-initialisation.
$template = fallback_setup_template();

$formOpen = '<form id="install-form" method="post" onsubmit="handleSubmit(event)" class="space-y-6"><input type="hidden" name="csrf" value="' . html(csrf_token()) . '">';
$template = preg_replace('/<form id="install-form"[^>]*>/', $formOpen, $template, 1) ?? $template;
$errorHtml = $error === null ? '' : '<p class="rounded-lg border border-rose-900 bg-rose-950/50 px-3 py-2 text-sm text-rose-200">' . html($error) . '</p><input type="hidden" id="setup-error-step" value="' . $errorStep . '"><input type="hidden" id="setup-error-focus" value="' . html($errorFocus) . '">';
$template = str_replace($formOpen, $errorHtml . $formOpen, $template);

$bindingScript = <<<'JS'
<script>
(() => {
    const form = document.getElementById('install-form');
    const progress = document.createElement('div');
    progress.className = 'hidden space-y-1 text-xs';
    progress.innerHTML = '<div class="flex justify-between text-slate-400"><span id="setup-progress-text"></span><span id="setup-progress-percent"></span></div><div class="h-1.5 overflow-hidden rounded-full bg-slate-800"><div id="setup-progress-bar" class="h-full w-0 bg-indigo-500 transition-all duration-300"></div></div>';
    const feedback = document.createElement('p');
    feedback.className = 'hidden text-xs';
    const footer = form.querySelector('.flex.items-center.justify-between.pt-4');
    footer.before(progress, feedback);

    const setName = (selector, name) => {
        const element = document.querySelector(selector);
        if (element) element.name = name;
        return element;
    };
    document.querySelectorAll('#step-content-1 input[type="radio"]').forEach(input => input.name = 'db_type');
    document.querySelectorAll('#step-content-1 input[type="radio"]').forEach(input => input.addEventListener('change', () => {
        input.closest('label')?.animate(
            [{ transform: 'scale(1)' }, { transform: 'scale(1.035)' }, { transform: 'scale(1)' }],
            { duration: 180, easing: 'ease-out' }
        );
    }));
    const dbInputs = document.querySelectorAll('#db-credentials-fields input');
    ['db_host', 'db_port', 'db_username', 'db_password', 'db_name'].forEach((name, index) => { if (dbInputs[index]) dbInputs[index].name = name; });
    setName('#toggle-redis', 'redis_enabled');
    const redisInputs = document.querySelectorAll('#redis-fields input');
    if (redisInputs[0]) redisInputs[0].name = 'redis_host';
    if (redisInputs[1]) redisInputs[1].name = 'redis_password';
    const adminInputs = document.querySelectorAll('#step-content-2 input');
    ['username', 'admin_email', 'password', 'password_confirmation'].forEach((name, index) => { if (adminInputs[index]) adminInputs[index].name = name; });
    const smtpInputs = document.querySelectorAll('#step-content-3 input');
    ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password'].forEach((name, index) => { if (smtpInputs[index]) smtpInputs[index].name = name; });
    setName('#step-content-3 select', 'smtp_encryption');
    const passwordInput = adminInputs[2];
    const confirmPasswordInput = adminInputs[3];
    passwordInput.minLength = 8;
    confirmPasswordInput.minLength = 8;
    const strength = document.createElement('p');
    strength.className = 'hidden mt-1.5 text-[11px]';
    passwordInput.parentElement.append(strength);
    passwordInput.addEventListener('input', () => {
        const length = passwordInput.value.length;
        if (length === 0) { strength.className = 'hidden mt-1.5 text-[11px]'; return; }
        const variety = [/[a-z]/.test(passwordInput.value), /[A-Z]/.test(passwordInput.value), /\d/.test(passwordInput.value), /[^A-Za-z0-9]/.test(passwordInput.value)].filter(Boolean).length;
        const level = length < 8 ? '密码长度不足 8 位' : length >= 14 && variety >= 3 ? '密码强度：强' : length >= 10 || variety >= 2 ? '密码强度：中' : '密码强度：基础';
        strength.textContent = level + '（仅要求至少 8 位）';
        strength.className = 'mt-1.5 text-[11px] ' + (length < 8 ? 'text-rose-400' : length >= 14 && variety >= 3 ? 'text-emerald-400' : 'text-amber-400');
    });

    let emailVerified = false;
    const message = (ok, text) => {
        feedback.textContent = text;
        feedback.className = 'text-xs ' + (ok ? 'text-emerald-400' : 'text-rose-400');
    };
    const showProgress = (text, percent) => {
        progress.classList.remove('hidden');
        document.getElementById('setup-progress-text').textContent = text;
        document.getElementById('setup-progress-percent').textContent = percent + '%';
        document.getElementById('setup-progress-bar').style.width = percent + '%';
    };
    const postTest = async (url, button) => {
        const original = button.innerHTML;
        button.disabled = true;
        const databaseTest = url.includes('connection');
        showProgress(databaseTest ? '正在检查数据库配置...' : '正在发送测试邮件...', 25);
        try {
            const response = await fetch(url, { method: 'POST', body: new FormData(form) });
            const result = await response.json();
            showProgress(databaseTest ? (result.created ? '本地数据库创建完成，正在验证连接...' : '数据库连接验证完成。') : '邮箱服务验证完成。', 100);
            if (result.ok) {
                showProgress(databaseTest ? (result.created ? '本地数据库已创建并完成连接验证。' : '数据库连接验证完成。') : '邮箱服务验证完成。', 100);
            }
            message(Boolean(result.ok), result.message || (result.ok ? '验证成功。' : '验证失败。'));
            if (!result.ok) {
                showProgress(databaseTest ? '数据库测试失败。' : '邮箱验证失败。', 100);
                if (databaseTest) showProgress('数据库连接或创建失败。', 0);
            }
            return Boolean(result.ok);
        } catch (_) {
            showProgress(databaseTest ? '数据库测试失败。' : '邮箱验证失败。', 100);
            if (databaseTest) showProgress('数据库连接或创建失败。', 0);
            message(false, '无法连接验证接口，请检查服务器配置。');
            return false;
        } finally {
            button.disabled = false;
            button.innerHTML = original;
            lucide.createIcons();
        }
    };

    window.simulateTestConnection = () => postTest('setup_test_connection.php', document.getElementById('test-conn-btn'));
    const originalMoveStep = window.moveStep;
    const errorStep = Number(document.getElementById('setup-error-step')?.value || 1);
    const errorFocus = document.getElementById('setup-error-focus')?.value || '';
    const setupStepKey = 'picapi.setup.current-step';
    const savedStep = Math.min(3, Math.max(1, Number(window.sessionStorage.getItem(setupStepKey) || 1)));
    let persistedStep = savedStep;
    const configureTestButton = () => {
        const button = document.getElementById('test-conn-btn');
        if (!document.getElementById('step-content-3').classList.contains('hidden')) {
            button.classList.remove('hidden');
            button.innerHTML = '<i data-lucide="mail-check" class="w-4 h-4"></i>验证邮箱';
            button.onclick = async () => {
                const smtpValues = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password'].map(name => (document.querySelector('[name="' + name + '"]')?.value || '').trim());
                if (!smtpValues.every(Boolean)) {
                    message(false, '请完整填写 SMTP 地址、端口、账号和授权密码后再验证邮箱。');
                    return;
                }
                emailVerified = await postTest('setup_test_email.php', button);
            };
        } else if (!document.getElementById('step-content-1').classList.contains('hidden')) {
            button.classList.remove('hidden');
            button.innerHTML = '<i data-lucide="link" class="w-4 h-4"></i>测试连接';
            button.onclick = window.simulateTestConnection;
        } else {
            button.classList.add('hidden');
        }
        lucide.createIcons();
    };
    window.moveStep = async direction => {
        if (direction > 0 && !document.getElementById('step-content-1').classList.contains('hidden')) {
            if (!await postTest('setup_test_connection.php', document.getElementById('test-conn-btn'))) return;
            await new Promise(resolve => window.setTimeout(resolve, 650));
            progress.classList.add('hidden');
        }
        originalMoveStep(direction);
        persistedStep = Math.max(1, Math.min(3, persistedStep + direction));
        window.sessionStorage.setItem(setupStepKey, String(persistedStep));
        configureTestButton();
    };
    window.handleSubmit = async event => {
        event.preventDefault();
        if (passwordInput.value.length < 8) {
            message(false, '管理员密码至少需要 8 位。');
            originalMoveStep(-1);
            configureTestButton();
            passwordInput.focus();
            return;
        }
        if (passwordInput.value !== confirmPasswordInput.value) {
            message(false, '两次输入的管理员密码不一致。');
            originalMoveStep(-1);
            configureTestButton();
            confirmPasswordInput.focus();
            return;
        }
        const smtpValues = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password'].map(name => (document.querySelector('[name="' + name + '"]')?.value || '').trim());
        if (!smtpValues.every(Boolean)) {
            message(false, '请完整填写 SMTP 地址、端口、账号和授权密码。');
            return;
        }
        if (!emailVerified) {
            message(false, '请先点击“验证邮箱”，确认 SMTP 配置可用后再初始化。');
            return;
        }
        window.sessionStorage.removeItem(setupStepKey);
        form.submit();
    };
    configureTestButton();
    const initialStep = errorStep > 1 ? errorStep : savedStep;
    if (initialStep > 1) {
        for (let step = 1; step < initialStep; step++) originalMoveStep(1);
        configureTestButton();
    }
    persistedStep = initialStep;
    window.sessionStorage.setItem(setupStepKey, String(initialStep));
    if (errorFocus) window.setTimeout(() => document.querySelector('[name="' + CSS.escape(errorFocus) + '"]')?.focus(), 0);
})();
</script>
JS;

$template = str_replace('</body>', $bindingScript . '</body>', $template);
echo $template;
