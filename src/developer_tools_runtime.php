<?php
declare(strict_types=1);

require_once __DIR__ . '/image_runtime.php';
require_once __DIR__ . '/settings_runtime.php';
require_once __DIR__ . '/login_log_runtime.php';
require_once __DIR__ . '/api_docs_runtime.php';
require_once __DIR__ . '/setup_runtime.php';

/** Restore the application to its uninitialized state after explicit confirmation. */
function clear_developer_test_data(string $confirmation): array
{
    assert_developer_cleanup_authorized(site_settings(), $confirmation);

    $counts = ['images' => 0, 'files' => 0, 'databaseRows' => 0];

    // Remove user-generated media and runtime state while preserving the codebase and access guards.
    foreach ([IMAGE_PC_DIR, IMAGE_MOBILE_DIR] as $imageRoot) {
        $counts['images'] += developer_clear_directory_files($imageRoot, static fn(string $name): bool => has_supported_image_extension($name));
    }
    foreach ([THUMBNAIL_DIR, STORAGE_DIR . '/cache', STORAGE_DIR . '/chunks', STORAGE_DIR . '/site-icon-chunks', STORAGE_DIR . '/avatars', STORAGE_DIR . '/login-wallpapers', STORAGE_DIR . '/test-temp'] as $directory) {
        $counts['files'] += developer_clear_directory_files($directory, static fn(string $name): bool => $name !== '.htaccess' && !str_ends_with($name, '.lock'));
    }
    foreach ([STORAGE_DIR . '/logs', STORAGE_DIR . '/upload-logs', STORAGE_DIR . '/security'] as $directory) {
        $counts['files'] += developer_clear_directory_files($directory, static fn(string $name): bool => $name !== '.htaccess' && !str_ends_with($name, '.lock'));
    }

    $rootFiles = [
        'admin.php', 'site-settings.json', 'api_config.json', 'api-docs.json', 'categories.json',
        'counters.json', 'api_sequence.json', 'login-transport-private.pem', 'setup.token',
    ];
    foreach ($rootFiles as $name) {
        $path = STORAGE_DIR . '/' . $name;
        if (is_file($path) && !is_link($path)) {
            if (!@unlink($path)) throw new RuntimeException('无法删除应用设置文件：' . $name);
            $counts['files']++;
        }
    }
    foreach (glob(STORAGE_DIR . '/' . SITE_ICON_BASENAME . '*') ?: [] as $path) {
        if (is_file($path) && !is_link($path) && !@unlink($path)) throw new RuntimeException('无法移除站点图标文件。');
    }
    $setupCodePath = setup_code_path();
    if (is_file($setupCodePath) && !is_link($setupCodePath) && !@unlink($setupCodePath)) throw new RuntimeException('无法重置初始化验证码，请检查项目根目录权限。');

    // Delete all PicAPI-owned rows. Keep external database schema intact, but
    // remove the local SQLite file after closing it so setup creates a fresh DB.
    $pdo = admin_database_connection();
    $sqlitePath = null;
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $dsn = (string) ((installation_config()['database']['dsn'] ?? ''));
        if (str_starts_with($dsn, 'sqlite:')) {
            $candidate = substr($dsn, 7);
            $localRoot = realpath(LOCAL_DATABASE_DIR);
            $candidateRoot = realpath(dirname($candidate));
            if ($localRoot !== false && $candidateRoot !== false && $candidateRoot === $localRoot && basename($candidate) === 'picapi.sqlite' && is_file($candidate) && !is_link($candidate)) $sqlitePath = $candidate;
        }
    }
    $tables = ['picapi_runtime_states', 'picapi_config_documents', 'picapi_categories', 'picapi_request_logs', 'picapi_upload_audits', 'picapi_daily_statistics', 'picapi_login_logs', 'picapi_admin_users'];
    $pdo->beginTransaction();
    try {
        foreach ($tables as $table) {
            if (!developer_database_table_exists($pdo, $table)) continue;
            $counts['databaseRows'] += (int) $pdo->exec('DELETE FROM ' . $table);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw new RuntimeException('数据库未能完整重置；安装配置仍保留，请检查数据库状态后重试。', 0, $error);
    }
    if ($sqlitePath !== null) {
        try { $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (Throwable) {}
    }
    unset($pdo);

    // This is the final marker: removing it makes the next admin request open
    // setup.php, which generates a fresh local setup.txt after this installation is removed.
    $installationPath = STORAGE_DIR . '/installation.php';
    if (is_file($installationPath) && !@unlink($installationPath)) throw new RuntimeException('数据已清空，但无法移除安装记录。请手动移除 storage/installation.php 后重新初始化。');
    if ($sqlitePath !== null) {
        foreach ([$sqlitePath, $sqlitePath . '-wal', $sqlitePath . '-shm'] as $path) {
            if (is_file($path) && !@unlink($path)) throw new RuntimeException('数据已清空，但无法移除本地 SQLite 文件。请检查文件占用后重新加载初始化页。');
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
    invalidate_image_index();
    return $counts;
}

function developer_database_table_exists(PDO $pdo, string $table): bool
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $statement->execute([$table]);
        return $statement->fetchColumn() !== false;
    }
    if ($driver === 'mysql') {
        $statement = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $statement->execute([$table]);
        return $statement->fetchColumn() !== false;
    }
    if ($driver === 'pgsql') {
        $statement = $pdo->prepare('SELECT to_regclass(?)');
        $statement->execute(['public.' . $table]);
        return $statement->fetchColumn() !== null;
    }
    if ($driver === 'sqlsrv') {
        $statement = $pdo->prepare('SELECT OBJECT_ID(?)');
        $statement->execute([$table]);
        return $statement->fetchColumn() !== null;
    }
    throw new RuntimeException('不支持重置此数据库类型。');
}

/** Run read-only checks useful when testing a fresh deployment. */
function developer_run_diagnostics(): array
{
    assert_developer_mode_enabled(site_settings());
    $checks = [];
    try {
        $pdo = admin_database_connection();
        $pdo->query('SELECT 1');
        $pdo->query('SELECT 1 FROM picapi_request_logs WHERE 1 = 0');
        $checks[] = ['name' => '数据库', 'status' => 'ok', 'detail' => '连接正常，日志表可用'];
    } catch (Throwable) {
        $checks[] = ['name' => '数据库', 'status' => 'error', 'detail' => '连接失败或日志表未就绪'];
    }

    foreach ([
        '图片存储' => [IMAGE_PC_DIR, IMAGE_MOBILE_DIR],
        '缩略图缓存' => [THUMBNAIL_DIR],
        '应用存储' => [STORAGE_DIR],
    ] as $name => $directories) {
        $ready = true;
        foreach ($directories as $directory) {
            if (!is_dir($directory) || !is_writable($directory)) { $ready = false; break; }
        }
        $checks[] = ['name' => $name, 'status' => $ready ? 'ok' : 'error', 'detail' => $ready ? '目录存在且可写' : '目录缺失或 PHP 无写入权限'];
    }

    $imageMagickAvailable = function_exists('shell_exec') && trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'magick -version 2>NUL' : 'magick -version 2>/dev/null')) !== '';
    $imageProcessorAvailable = extension_loaded('gd') || extension_loaded('imagick') || $imageMagickAvailable;
    $checks[] = ['name' => '图片处理', 'status' => $imageProcessorAvailable ? 'ok' : 'warning', 'detail' => $imageProcessorAvailable ? '至少一种图片处理器可用' : '未检测到 GD、Imagick 或 ImageMagick'];
    return $checks;
}

/** Refresh the derived image index without changing the source images. */
function developer_rebuild_image_index(): int
{
    assert_developer_mode_enabled(site_settings());
    invalidate_image_index();
    return count(list_images());
}

/** @param array<string,mixed> $settings */
function assert_developer_cleanup_authorized(array $settings, string $confirmation): void
{
    assert_developer_mode_enabled($settings);
    if (!hash_equals('恢复初始状态', $confirmation)) throw new InvalidArgumentException('二次确认内容不匹配，未执行重置。');
}

/** @param array<string,mixed> $settings */
function assert_developer_mode_enabled(array $settings): void
{
    if (empty($settings['developer_mode'])) throw new InvalidArgumentException('请先在系统设置中开启开发者模式。');
}

/** Remove matching regular files below an application-owned directory only. */
function developer_clear_directory_files(string $directory, callable $matches): int
{
    if (!is_dir($directory) || is_link($directory)) return 0;
    $root = realpath($directory);
    if ($root === false) return 0;
    $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $comparisonRoot = DIRECTORY_SEPARATOR === '\\' ? strtolower($rootPrefix) : $rootPrefix;
    $deleted = 0;

    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isLink()) continue;
        if ($entry->isDir()) {
            $deleted += developer_clear_directory_files($entry->getPathname(), $matches);
            continue;
        }
        if (!$entry->isFile() || !$matches($entry->getFilename())) continue;
        $path = realpath($entry->getPathname());
        if ($path === false) continue;
        $comparisonPath = str_replace('\\', '/', $path);
        if (DIRECTORY_SEPARATOR === '\\') $comparisonPath = strtolower($comparisonPath);
        if (!str_starts_with($comparisonPath, $comparisonRoot)) continue;
        if (!@unlink($path)) throw new RuntimeException('无法删除文件：' . basename($path));
        $deleted++;
    }
    return $deleted;
}

function clear_developer_docs_visit_statistics(): void
{
    ensure_directory(dirname(API_DOCS_VISITS_PATH));
    $lock = @fopen(API_DOCS_VISITS_PATH . '.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('无法锁定公开文档访问统计文件。');
    }
    try {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        if (!write_json_atomic(API_DOCS_VISITS_PATH, ['today' => 0, 'yesterday' => 0, 'total' => 0, 'date' => $today])) {
            throw new RuntimeException('无法重置公开文档访问统计。');
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
