<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/settings_runtime.php';

initialize_storage();
require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') abort_request(405, '请求方式不正确。');
verify_csrf();
$expectsJson = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

try {
    $action = (string) ($_POST['action'] ?? '');
    $ip = trim((string) ($_POST['ip'] ?? ''));
    $message = '';
    if ($action === 'add_whitelist') {
        add_ip_to_whitelist($ip);
        $message = '已加入白名单，该 IP 不再受自动封禁限制。';
    } elseif ($action === 'add_blacklist') {
        $permanent = !empty($_POST['permanent']);
        $minutes = (int) ($_POST['duration_minutes'] ?? 30);
        $scope = (string) ($_POST['blacklist_scope'] ?? 'request');
        if (!in_array($scope, ['request', 'login'], true)) throw new RuntimeException('黑名单类型无效。');
        if (!$permanent && ($minutes < 1 || $minutes > 525600)) throw new RuntimeException('封禁时长需在 1 分钟至 365 天之间。');
        $reason = $scope === 'login' ? 'manual_login' : 'manual';
        add_ip_to_blacklist($ip, $minutes * 60, $reason, $permanent);
        $label = $scope === 'login' ? '登录黑名单' : '频繁请求黑名单';
        $message = $permanent ? '已加入' . $label . '并永久封禁。' : '已加入' . $label . '，封禁时间为 ' . $minutes . ' 分钟。';
    } elseif ($action === 'remove_whitelist') {
        remove_ip_from_access_list($ip, 'whitelist');
        $message = '已从白名单移除该 IP。';
    } elseif ($action === 'remove_blacklist') {
        remove_ip_from_access_list($ip, 'blacklist');
        $message = '已解除该 IP 的访问封禁。';
    } elseif ($action === 'batch_remove_whitelist') {
        $removed = remove_ips_from_access_list(is_array($_POST['ips'] ?? null) ? $_POST['ips'] : [], 'whitelist');
        $message = '已批量移除 ' . $removed . ' 个白名单 IP。';
    } elseif ($action === 'batch_remove_blacklist') {
        $removed = remove_ips_from_access_list(is_array($_POST['ips'] ?? null) ? $_POST['ips'] : [], 'blacklist');
        $message = '已批量解除 ' . $removed . ' 个 IP 的封禁。';
    } elseif ($action === 'update_threshold') {
        $threshold = $_POST['request_threshold'] ?? '';
        update_site_settings([
            'request_window_seconds' => $_POST['request_window_seconds'] ?? '',
            'request_threshold' => $threshold,
            'ip_blacklist_threshold' => $threshold,
        ]);
        $message = 'API 请求频率提醒与自动封禁策略已同步更新。';
    } else {
        throw new RuntimeException('IP 管控操作无效。');
    }
    if ($expectsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash('notice', $message);
    flash('notice_type', 'success');
} catch (Throwable $error) {
    if ($expectsJson) {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash('notice', $error->getMessage());
    flash('notice_type', 'error');
}

redirect('ip_control.php');
