<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/settings_runtime.php';

initialize_storage();
require_admin();

function runtime_status_reply(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? 'save_retention');
        if ($action === 'clear_events') {
            $status = clear_runtime_status_events();
        } elseif ($action === 'save_retention') {
            $days = (int) ($_POST['retention_days'] ?? 365);
            $status = set_runtime_status_retention_days($days);
            purge_expired_runtime_logs((int) $status['retention_days'], true);
        } else {
            runtime_status_reply(422, ['ok' => false, 'message' => '无效操作。']);
        }
        // The browser refreshes the selected page after saving. Avoid sending
        // the entire retained local log in this mutation response.
        $status['events'] = [];
        runtime_status_reply(200, ['ok' => true, 'status' => $status, 'smtp_configured' => smtp_is_configured(smtp_settings())]);
    }
    if ($method !== 'GET') runtime_status_reply(405, ['ok' => false, 'message' => '请求方式不正确。']);

    $status = runtime_status();
    // Public API traffic has its own request/access logs. This panel is only
    // for actual management-console operations and their mail failures.
    $adminEvents = array_values(array_reverse(array_filter(
        $status['events'],
        static fn(array $event): bool => ($event['scope'] ?? '') === 'admin'
    )));
    $status['counts'] = [
        'all' => count($adminEvents),
        'normal' => count(array_filter($adminEvents, static fn(array $event): bool => ($event['level'] ?? '') === 'info')),
        'error' => count(array_filter($adminEvents, static fn(array $event): bool => ($event['level'] ?? '') !== 'info')),
        'login' => count(array_filter($adminEvents, static fn(array $event): bool => ($event['category'] ?? '') === 'login')),
    ];
    $filter = (string) ($_GET['filter'] ?? 'all');
    if (!in_array($filter, ['all', 'normal', 'error', 'login'], true)) $filter = 'all';
    $filteredEvents = match ($filter) {
        'normal' => array_values(array_filter($adminEvents, static fn(array $event): bool => ($event['level'] ?? '') === 'info')),
        'error' => array_values(array_filter($adminEvents, static fn(array $event): bool => ($event['level'] ?? '') !== 'info')),
        'login' => array_values(array_filter($adminEvents, static fn(array $event): bool => ($event['category'] ?? '') === 'login')),
        default => $adminEvents,
    };
    $perPage = 20;
    $total = count($filteredEvents);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
    $status['events'] = array_slice($filteredEvents, ($page - 1) * $perPage, $perPage);
    runtime_status_reply(200, [
        'ok' => true,
        'status' => $status,
        'pagination' => ['page' => $page, 'per_page' => $perPage, 'pages' => $pages, 'total' => $total],
        'smtp_configured' => smtp_is_configured(smtp_settings()),
        'storage' => 'storage/security/runtime-status.json',
    ]);
} catch (Throwable $error) {
    runtime_status_reply(500, ['ok' => false, 'message' => '无法读取网站运行状态。']);
}
