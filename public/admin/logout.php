<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/app.php';
initialize_storage();
enforce_admin_transport_policy();
start_secure_session();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    abort_request(405, '仅支持 POST 方式退出登录。');
}
verify_csrf();
$_SESSION = [];
session_destroy();
if (!headers_sent()) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => (string) ($params['path'] ?? '/'),
        'domain' => (string) ($params['domain'] ?? ''),
        'secure' => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
redirect('login.php');
