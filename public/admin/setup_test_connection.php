<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/setup_runtime.php';
require_once __DIR__ . '/../../src/cache_runtime.php';

initialize_storage();
enforce_admin_transport_policy();
start_secure_session();
if (admin_is_configured()) {
    abort_request(403, 'Setup is already complete.');
}
if (installation_config() !== null && !installation_sqlite_admin_table_missing()) {
    abort_request(503, '系统已经初始化，数据库当前不可用。');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    abort_request(405, 'Method not allowed.');
}
verify_csrf();
header('Content-Type: application/json; charset=utf-8');
$stage = (string) ($_POST['test_stage'] ?? 'database');
try {
    require_setup_draft($_POST);
    if ($stage === 'database') {
        require_setup_code($_POST);
        unset($_SESSION['setup_connection_tests']);
        $result = prepare_setup_database($_POST, false);
        require_setup_draft($_POST);
        setup_connection_test_record($_POST, 'database');
    } elseif ($stage === 'redis') {
        require_setup_code_verified();
        unset($_SESSION['setup_connection_tests']['redis']);
        if (!setup_connection_test_valid($_POST, 'database')) throw new InvalidArgumentException('请先测试数据库连接。');
        if (empty($_POST['redis_enabled'])) throw new InvalidArgumentException('Redis 尚未启用。');
        test_setup_redis($_POST);
        require_setup_draft($_POST);
        setup_connection_test_record($_POST, 'redis');
        $result = ['message' => 'Redis 连接成功。', 'created' => false];
    } else {
        throw new InvalidArgumentException('未知的连接测试阶段。');
    }
    require_setup_draft($_POST);
    echo json_encode(['ok' => true, 'stage' => $stage, 'message' => $result['message'], 'created' => $result['created'], 'setupCodeVerified' => setup_code_is_verified()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (SetupDraftExpiredException $exception) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage(), 'setupCodeVerified' => setup_code_is_verified()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    $message = $exception instanceof InvalidArgumentException ? $exception->getMessage() : ($stage === 'redis' ? '数据库连接成功，但 Redis 测试失败，请检查主机、端口和密码。' : '数据库连接测试失败，请检查配置与数据库驱动。');
    echo json_encode(['ok' => false, 'stage' => $stage, 'message' => $message, 'setupCodeVerified' => setup_code_is_verified()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
