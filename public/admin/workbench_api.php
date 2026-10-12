<?php
declare(strict_types=1);
require_once __DIR__ . '/../../src/media_management.php';
require_once __DIR__ . '/../../src/security_runtime.php';
require_once __DIR__ . '/../../src/login_log_runtime.php';
require_once __DIR__ . '/../../src/dashboard_runtime.php';
require_once __DIR__ . '/../../src/api_config_runtime.php';
require_once __DIR__ . '/../../src/api_docs_runtime.php';
require_once __DIR__ . '/../../src/developer_tools_runtime.php';
require_once __DIR__ . '/../../src/update_runtime.php';
initialize_storage();
$admin = require_admin();
send_admin_security_headers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function workbench_library(): array
{
    $images = []; $names = category_display_names();
    foreach (list_images() as $index => $image) {
        $alias = dirname($image['relative']); if ($alias === '.') $alias = 'default';
        $images[] = ['id' => $index + 1, 'key' => $image['device'] . '|' . $image['relative'], 'original' => basename($image['relative']), 'title' => basename($image['relative']), 'relative' => $image['relative'], 'device' => $image['device'], 'category' => $names[$alias] ?? $alias, 'categoryAlias' => $alias, 'width' => $image['width'], 'height' => $image['height'], 'size' => $image['size'] / 1048576, 'bytes' => $image['size'], 'url' => image_url($image['device'], $image['relative']), 'src' => 'thumbnail.php?device=' . $image['device'] . '&relative=' . rawurlencode($image['relative']) . '&v=preview-webp-1', 'date' => date('Y-m-d', (int) filemtime($image['path']))];
    }
    $categories = [];
    foreach (all_categories() as $alias) {
        $items = array_values(array_filter($images, static fn(array $item): bool => $item['categoryAlias'] === $alias));
        $categories[] = ['name' => $names[$alias] ?? $alias, 'alias' => $alias === 'default' ? default_category_request_alias() : $alias, 'storageAlias' => $alias, 'default' => $alias === 'default', 'count' => count($items), 'pc' => count(array_filter($items, static fn(array $item): bool => $item['device'] === 'pc')), 'mobile' => count(array_filter($items, static fn(array $item): bool => $item['device'] === 'mobile')), 'cover' => []];
    }
    return compact('images', 'categories');
}

/** @return array{?string,?string} */
function workbench_log_date_range(): array
{
    $startValue = trim((string) ($_GET['start_date'] ?? ''));
    $endValue = trim((string) ($_GET['end_date'] ?? ''));
    $parse = static function (string $value): ?string {
        if ($value === '') return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || $date->format('Y-m-d') !== $value || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('日期格式无效。');
        }
        return $value;
    };
    $startDate = $parse($startValue);
    $endDate = $parse($endValue);
    if ($startDate !== null && $endDate !== null && $startDate > $endDate) throw new InvalidArgumentException('开始日期不能晚于结束日期。');
    return [$startDate, $endDate];
}

try {
    $operation = (string) ($_GET['op'] ?? $_POST['op'] ?? 'bootstrap');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        verify_csrf();
        $payload = json_decode((string) ($_POST['payload'] ?? '{}'), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new InvalidArgumentException('请求数据无效。');
        $operationResult = null;
        $operationMessage = '已保存';
        switch ($operation) {
            case 'security-policy': security_policy_save((string) ($payload['scope'] ?? ''), $payload); break;
            case 'security-add': security_entry_change($payload); break;
            case 'security-remove': security_entry_change($payload, true); break;
            case 'move': move_media_items((array) ($payload['items'] ?? []), (string) ($payload['category'] ?? '')); break;
            case 'delete-category': delete_category_with_images((string) ($payload['category'] ?? ''), (string) ($payload['confirmation'] ?? '')); break;
            case 'default-category': save_default_category((string) ($payload['name'] ?? ''), (string) ($payload['alias'] ?? '')); break;
            case 'api': save_api_configuration_channel((string) ($payload['channel'] ?? ''), (array) ($payload['config'] ?? [])); break;
            case 'docs': save_api_docs_configuration(array_replace(api_docs_configuration(), $payload)); break;
            case 'settings':
                $allowed = array_keys(default_site_settings());
                $changes = array_intersect_key($payload, array_flip($allowed));
                update_site_settings($changes);
                if (array_key_exists('access_log_auto_cleanup', $changes) || array_key_exists('access_log_retention_months', $changes)) purge_expired_access_logs(true);
                if (array_key_exists('upload_log_auto_cleanup', $changes) || array_key_exists('upload_log_retention_months', $changes)) purge_expired_upload_audit_logs(true);
                if (array_key_exists('login_log_auto_cleanup', $changes) || array_key_exists('login_log_retention_months', $changes)) purge_expired_login_logs(true);
                break;
            case 'developer-cleanup':
                $operationResult = clear_developer_test_data((string) ($payload['confirmation'] ?? ''));
                $operationMessage = '已恢复初始状态，请重新初始化';
                break;
            case 'developer-diagnostics':
                $operationResult = developer_run_diagnostics();
                $operationMessage = '环境检查完成';
                break;
            case 'developer-rebuild-index':
                $operationResult = ['images' => developer_rebuild_image_index()];
                $operationMessage = '图库索引已重建';
                break;
            case 'update-check':
                $operationResult = picapi_update_check();
                $operationMessage = $operationResult['available'] ? '发现新版本' : '当前已是最新版本';
                break;
            case 'update-install':
                $operationResult = picapi_update_install_latest();
                $operationMessage = $operationResult['updated'] ? '更新安装完成' : '当前已是最新版本';
                break;
            case 'smtp': save_smtp_settings($payload); break;
            case 'test-mail':
                $settings = site_settings();
                $recipient = $settings['notification_email'] ?: (admin_account_by_identity($admin)['email'] ?? '');
                if (!send_site_notification('测试邮件', 'PicAPI 邮件配置测试。', [], $recipient)) throw new RuntimeException('测试邮件发送失败。');
                break;
            case 'remove-avatar':
                $path = admin_avatar_path(); if ($path !== null && !unlink($path)) throw new RuntimeException('无法移除头像。');
                break;
            default: throw new InvalidArgumentException('操作不存在。');
        }
        echo json_encode(['ok' => true, 'message' => $operationMessage, 'result' => $operationResult], JSON_UNESCAPED_UNICODE); exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') abort_request(405, 'Method not allowed.');
    if ($operation === 'library') $result = workbench_library();
    elseif ($operation === 'security') $result = ['policies' => security_policy_configuration(), 'entries' => security_entries()];
    elseif ($operation === 'login-logs') {
        [$startDate, $endDate] = workbench_log_date_range();
        $result = ['rows' => login_log_records(5000, $startDate, $endDate), 'dates' => login_log_dates(), 'startDate' => $startDate ?? '', 'endDate' => $endDate ?? ''];
    }
    elseif ($operation === 'logs') {
        [$startDate, $endDate] = workbench_log_date_range();
        $result = ['rows' => database_access_log_records(5000, $startDate, $endDate), 'uploads' => list_upload_audit_records(5000, $startDate, $endDate), 'accessDates' => database_access_log_dates(), 'uploadDates' => upload_audit_log_dates(), 'accessLimit' => 5000, 'startDate' => $startDate ?? '', 'endDate' => $endDate ?? ''];
    }
    elseif ($operation === 'sync') {
        $view = (string) ($_GET['view'] ?? '');
        $result = match ($view) {
            'overview' => ['statistics' => dashboard_statistics()],
            'gallery', 'categories', 'upload' => workbench_library(),
            'security' => ['policies' => security_policy_configuration(), 'entries' => security_entries()],
            'logs' => (static function (): array {
                [$startDate, $endDate] = workbench_log_date_range();
                return ['rows' => database_access_log_records(5000, $startDate, $endDate), 'uploads' => list_upload_audit_records(5000, $startDate, $endDate), 'accessDates' => database_access_log_dates(), 'uploadDates' => upload_audit_log_dates(), 'accessLimit' => 5000, 'startDate' => $startDate ?? '', 'endDate' => $endDate ?? ''];
            })(),
            'login-logs' => (static function (): array {
                [$startDate, $endDate] = workbench_log_date_range();
                return ['rows' => login_log_records(5000, $startDate, $endDate), 'dates' => login_log_dates(), 'startDate' => $startDate ?? '', 'endDate' => $endDate ?? ''];
            })(),
            'api' => ['api' => api_configuration()],
            'docs', 'docs-public' => ['docs' => api_docs_configuration(), 'docsRequestStatistics' => api_docs_request_statistics(), 'docsVisitStatistics' => api_docs_visit_statistics(false)],
            'settings' => (static function () use ($admin): array {
                $account = admin_account_by_identity($admin);
                $smtp = smtp_settings(); unset($smtp['password']);
                $pending = $_SESSION['admin_email_change'] ?? null;
                return ['settings' => site_settings(), 'smtp' => $smtp, 'profile' => ['name' => $admin, 'email' => $account['email'] ?? '', 'avatar' => admin_avatar_url() ?? ''], 'emailPending' => is_array($pending) && ($pending['expires_at'] ?? 0) > time(), 'favicon' => site_favicon_url(), 'policies' => security_policy_configuration(), 'entries' => security_entries()];
            })(),
            default => throw new InvalidArgumentException('不支持同步此页面。'),
        };
    }
    elseif ($operation === 'bootstrap') {
        if (!security_policy_active()) security_policy_save('api', security_policy_defaults()['api']);
        $account = admin_account_by_identity($admin);
        $smtp = smtp_settings(); unset($smtp['password']);
        $pending = $_SESSION['admin_email_change'] ?? null;
        $databaseDriver = '不可用';
        try { $databaseDriver = (string) admin_database_connection()->getAttribute(PDO::ATTR_DRIVER_NAME); } catch (Throwable) {}
        $result = workbench_library() + ['csrf' => csrf_token(), 'favicon' => site_favicon_url(), 'baseURL' => application_base_url(), 'settings' => site_settings(), 'smtp' => $smtp, 'profile' => ['name' => $admin, 'email' => $account['email'] ?? '', 'avatar' => admin_avatar_url() ?? ''], 'emailPending' => is_array($pending) && ($pending['expires_at'] ?? 0) > time(), 'statistics' => dashboard_statistics(), 'policies' => security_policy_configuration(), 'entries' => security_entries(), 'api' => api_configuration(), 'docs' => api_docs_configuration(), 'docsDefaultMarkdown' => default_api_docs_markdown(), 'docsRequestStatistics' => api_docs_request_statistics(), 'docsVisitStatistics' => api_docs_visit_statistics(false), 'environment' => ['PHP' => PHP_VERSION, '数据库' => $databaseDriver, 'GD' => extension_loaded('gd') ? '已启用' : '未安装', 'HTTPS' => request_is_https() ? '已启用' : '未启用', '存储目录' => is_writable(STORAGE_DIR) ? '可写' : '不可写']];
    } else throw new InvalidArgumentException('请求不存在。');
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    if (($operation ?? '') === 'developer-cleanup') {
        error_log('[PicAPI] developer-cleanup failed: ' . get_class($error) . ': ' . $error->getMessage());
    }
    http_response_code($error instanceof InvalidArgumentException ? 422 : 500);
    $message = $error instanceof InvalidArgumentException || $error instanceof PicApiUpdateException ? $error->getMessage() : '操作未完成，请检查服务器日志后重试。';
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
}
