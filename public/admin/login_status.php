<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/security_runtime.php';

initialize_storage();
enforce_admin_transport_policy();
start_secure_session();
send_admin_security_headers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

try {
    $access = login_ip_access_status(request_client_ip());
    echo json_encode([
        'ok' => true,
        'authenticated' => current_admin() !== null,
        'retry_after' => login_retry_after(),
        'blocked' => !empty($access['blocked']),
        'permanent' => !empty($access['permanent']),
        'until' => (int) ($access['until'] ?? 0),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
}
