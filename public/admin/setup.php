<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/setup_runtime.php';
require_once __DIR__ . '/../../src/smtp_runtime.php';
require_once __DIR__ . '/../../src/cache_runtime.php';

initialize_storage();
enforce_admin_transport_policy();
start_secure_session();
send_admin_security_headers();
if (admin_is_configured()) {
    redirect('login.php');
}
// Keep the setup wizard closed for normal database outages. A blank local
// SQLite database with no administrator table is the one recoverable reset case.
if (installation_config() !== null && !installation_sqlite_admin_table_missing()) {
    abort_request(503, admin_database_unavailable_message());
}
$setupCodeVerified = setup_code_is_verified();
$setupCodeError = null;
if (!$setupCodeVerified) {
    try { setup_code_ensure(); }
    catch (Throwable $exception) { $setupCodeError = $exception->getMessage(); }
}

$draftWindow = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    ? setup_draft_window((string) ($_POST['setup_draft_token'] ?? ''))
    : setup_draft_window();
$error = $setupCodeError;
$errorStep = 1;
$errorFocus = 'db_host';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        verify_csrf();
        require_setup_draft($_POST);
        if (!setup_code_is_verified()) {
            $errorStep = 1;
            $errorFocus = 'setup_code';
            require_setup_code($_POST);
        }
        require_setup_connection_tests($_POST);
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $email = trim((string) ($_POST['admin_email'] ?? ''));
        if (!preg_match('/^[\p{Han}a-zA-Z0-9._-]{2,48}$/u', $username) || !setup_password_has_minimum_length($password) || !hash_equals($password, (string) ($_POST['password_confirmation'] ?? '')) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errorStep = 2;
            $errorFocus = !hash_equals($password, (string) ($_POST['password_confirmation'] ?? '')) ? 'password_confirmation' : (filter_var($email, FILTER_VALIDATE_EMAIL) === false ? 'admin_email' : (!setup_password_has_minimum_length($password) ? 'password' : 'username'));
            throw new RuntimeException(!setup_password_has_minimum_length($password) ? '登录密码至少需要 8 位字符。' : (!hash_equals($password, (string) ($_POST['password_confirmation'] ?? '')) ? '两次输入的密码不一致。' : '请检查管理员用户名和邮箱。'));
        }

        $database = setup_database_options($_POST);
        prepare_setup_database($_POST, false);
        test_setup_redis($_POST);
        $errorStep = 3;
        $errorFocus = 'smtp_host';
        $smtp = setup_mail_configuration($_POST, $email);
        require_setup_draft($_POST);
        $errorStep = 1;
        prepare_setup_database($_POST);
        require_setup_draft($_POST);
        if (!create_database_admin($database, $username, $email, $password)) {
            redirect('login.php');
        }
        save_installation_config([
            'database' => $database,
            'redis' => ['enabled' => isset($_POST['redis_enabled']), 'host' => trim((string) ($_POST['redis_host'] ?? '')), 'password' => (string) ($_POST['redis_password'] ?? '')],
            'smtp' => $smtp,
            'admin_email' => $email,
            'created_at' => gmdate('c'),
            // This changes on every completed initialization. It is checked
            // against each browser session so no old login token is reused.
            'session_generation' => bin2hex(random_bytes(32)),
        ]);
        $setupCodePath = setup_code_path();
        if (is_file($setupCodePath) && !is_link($setupCodePath)) @unlink($setupCodePath);
        clear_setup_code_verified_cookie();
        clear_setup_connection_test_cookie('database');
        clear_setup_connection_test_cookie('redis');
        unset($_SESSION['setup_code_verified'], $_SESSION['setup_draft_window'], $_SESSION['setup_connection_tests']);
        begin_admin_session($username);
        redirect('dashboard.php');
    } catch (SetupDraftExpiredException $exception) {
        http_response_code(419);
        $error = $exception->getMessage();
        $errorStep = 1;
        $_POST = [];
        $draftWindow = [];
    } catch (Throwable $exception) {
        // Never echo raw SMTP connection details (host/port) back to visitors.
        $error = $errorStep === 3 ? '邮件配置不完整，请检查服务器地址、端口、账号、授权密码与加密方式。' : $exception->getMessage();
    }
}

$setupCodeVerified = setup_code_is_verified();

require __DIR__ . '/../../templates/admin/setup_view.php';
