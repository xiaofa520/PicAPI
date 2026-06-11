<?php

const ADMIN_DEFAULT_USERNAME = 'admin';
const ADMIN_CONFIG_FILE = __DIR__ . '/data/config.php';
const ADMIN_LEGACY_CONFIG_FILE = __DIR__ . '/config.php';
const ADMIN_SQLITE_FILE = __DIR__ . '/data/admin.sqlite';
const ADMIN_SETUP_SESSION_KEY = 'image_api_admin_setup_database';
const ADMIN_SETUP_EMAIL_SESSION_KEY = 'image_api_admin_setup_email';
const ADMIN_PASSWORD_RESET_SESSION_KEY = 'image_api_admin_password_reset';
const ADMIN_TOKEN_PURPOSE_EMAIL_VERIFY = 'email_verify';
const ADMIN_TOKEN_PURPOSE_PASSWORD_RESET = 'password_reset';
const ADMIN_SECURITY_ALERT_FILE = __DIR__ . '/data/security_alerts.json';

function admin_request_is_local(): bool
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return in_array($ip, ['127.0.0.1', '::1'], true);
}

function admin_cached_value(string $key, mixed $value = null, bool $write = false): mixed
{
    if (!isset($GLOBALS['__image_api_admin_cache']) || !is_array($GLOBALS['__image_api_admin_cache'])) {
        $GLOBALS['__image_api_admin_cache'] = [];
    }

    if ($write) {
        $GLOBALS['__image_api_admin_cache'][$key] = $value;
    }

    return $GLOBALS['__image_api_admin_cache'][$key] ?? null;
}

function admin_reset_auth_cache(): void
{
    admin_cached_value('config', null, true);
    admin_cached_value('initialized', null, true);
}

function admin_config_exists(): bool
{
    return is_file(ADMIN_CONFIG_FILE) || is_file(ADMIN_LEGACY_CONFIG_FILE);
}

function admin_load_config(bool $refresh = false): array
{
    if (!$refresh) {
        $cached = admin_cached_value('config');
        if (is_array($cached)) {
            return $cached;
        }
    }

    if (!admin_config_exists()) {
        admin_cached_value('config', [], true);
        return [];
    }

    $configFile = is_file(ADMIN_CONFIG_FILE) ? ADMIN_CONFIG_FILE : ADMIN_LEGACY_CONFIG_FILE;
    $config = require $configFile;
    if (!is_array($config)) {
        $config = [];
    }

    admin_cached_value('config', $config, true);
    return $config;
}

function admin_is_driver_available(string $driver): bool
{
    if (!class_exists(PDO::class)) {
        return false;
    }

    return in_array($driver, PDO::getAvailableDrivers(), true);
}

function admin_database_mode_label(?string $driver = null): string
{
    return match ($driver) {
        'sqlite' => 'SQLite 本地数据库',
        'mysql' => 'MySQL 数据库',
        default => '未配置',
    };
}

function admin_configured_username(array $config = []): string
{
    if ($config === []) {
        $config = admin_load_config();
    }

    $username = trim((string) ($config['admin']['username'] ?? ADMIN_DEFAULT_USERNAME));
    return $username !== '' ? $username : ADMIN_DEFAULT_USERNAME;
}

function admin_configured_admin_email(array $config = []): string
{
    if ($config === []) {
        $config = admin_load_config();
    }

    return trim((string) ($config['admin']['email'] ?? ''));
}

function admin_current_username(): string
{
    $username = trim((string) ($_SESSION['image_api_admin_username'] ?? ''));
    if ($username !== '') {
        return $username;
    }

    return admin_configured_username();
}

function admin_validate_admin_username(string $username): string
{
    $username = trim($username);
    if ($username === '') {
        throw new RuntimeException('请输入管理员账号。');
    }

    if (strlen($username) < 3 || strlen($username) > 32) {
        throw new RuntimeException('管理员账号长度需在 3 到 32 个字符之间。');
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        throw new RuntimeException('管理员账号仅支持字母、数字和下划线。');
    }

    return $username;
}

function admin_validate_email_address(string $email): string
{
    $email = trim($email);
    if ($email === '') {
        throw new RuntimeException('请输入邮箱地址。');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('邮箱地址格式不正确。');
    }

    return $email;
}

function admin_default_password_active(): bool
{
    return false;
}

function admin_store_pending_database_settings(array $databaseSettings): void
{
    $_SESSION[ADMIN_SETUP_SESSION_KEY] = $databaseSettings;
}

function admin_pending_database_settings(): ?array
{
    $settings = $_SESSION[ADMIN_SETUP_SESSION_KEY] ?? null;
    return is_array($settings) ? $settings : null;
}

function admin_clear_pending_database_settings(): void
{
    unset($_SESSION[ADMIN_SETUP_SESSION_KEY]);
}

function admin_store_pending_email_settings(array $emailSettings): void
{
    $_SESSION[ADMIN_SETUP_EMAIL_SESSION_KEY] = $emailSettings;
}

function admin_pending_email_settings(): ?array
{
    $settings = $_SESSION[ADMIN_SETUP_EMAIL_SESSION_KEY] ?? null;
    return is_array($settings) ? $settings : null;
}

function admin_clear_pending_email_settings(): void
{
    unset($_SESSION[ADMIN_SETUP_EMAIL_SESSION_KEY]);
}

function admin_password_reset_grant(): ?array
{
    $grant = $_SESSION[ADMIN_PASSWORD_RESET_SESSION_KEY] ?? null;
    return is_array($grant) ? $grant : null;
}

function admin_store_password_reset_grant(array $grant): void
{
    $_SESSION[ADMIN_PASSWORD_RESET_SESSION_KEY] = $grant;
}

function admin_clear_password_reset_grant(): void
{
    unset($_SESSION[ADMIN_PASSWORD_RESET_SESSION_KEY]);
}

function admin_current_admin_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        ? 'https'
        : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1'));
    $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin')));
    $scriptDir = rtrim($scriptDir, '/');

    return $scheme . '://' . $host . ($scriptDir !== '' ? $scriptDir : '/admin');
}

function admin_prepare_email_settings(array $emailSettings): array
{
    $enabled = !empty($emailSettings['enabled']);
    $siteUrl = rtrim(trim((string) ($emailSettings['site_url'] ?? admin_current_admin_base_url())), '/');
    if ($siteUrl === '') {
        $siteUrl = admin_current_admin_base_url();
    }

    $encryption = strtolower(trim((string) ($emailSettings['encryption'] ?? 'tls')));
    if (!in_array($encryption, ['none', 'ssl', 'tls'], true)) {
        $encryption = 'tls';
    }

    $port = (int) ($emailSettings['port'] ?? 0);
    if ($port <= 0) {
        $port = match ($encryption) {
            'ssl' => 465,
            'tls' => 587,
            default => 25,
        };
    }

    $prepared = [
        'enabled' => $enabled,
        'host' => trim((string) ($emailSettings['host'] ?? '')),
        'port' => $port,
        'encryption' => $encryption,
        'username' => trim((string) ($emailSettings['username'] ?? '')),
        'password' => (string) ($emailSettings['password'] ?? ''),
        'from_email' => trim((string) ($emailSettings['from_email'] ?? '')),
        'from_name' => trim((string) ($emailSettings['from_name'] ?? '图片服务')),
        'site_url' => $siteUrl,
    ];

    if (!$enabled) {
        return $prepared;
    }

    if ($prepared['host'] === '') {
        throw new RuntimeException('请输入 SMTP 服务器地址。');
    }

    if ($prepared['port'] <= 0 || $prepared['port'] > 65535) {
        throw new RuntimeException('SMTP 端口不正确。');
    }

    $prepared['from_email'] = admin_validate_email_address($prepared['from_email']);

    if (!filter_var($prepared['site_url'], FILTER_VALIDATE_URL)) {
        throw new RuntimeException('后台地址必须是有效的 URL。');
    }

    return $prepared;
}

function admin_validate_email_settings(array $emailSettings): array
{
    return admin_prepare_email_settings($emailSettings);
}

function admin_email_service_enabled(array $config = []): bool
{
    if ($config === []) {
        $config = admin_load_config();
    }

    return !empty($config['email_service']['enabled']);
}

function admin_email_service_config(array $config = []): array
{
    if ($config === []) {
        $config = admin_load_config();
    }

    return admin_prepare_email_settings((array) ($config['email_service'] ?? []));
}

function admin_stream_transports_normalized(): array
{
    $transports = stream_get_transports();
    if (!is_array($transports)) {
        return [];
    }

    return array_values(array_unique(array_map(
        static fn(mixed $transport): string => strtolower((string) $transport),
        $transports
    )));
}

function admin_email_transport_supported(array $service): bool
{
    $encryption = strtolower(trim((string) ($service['encryption'] ?? 'none')));
    if ($encryption === '' || $encryption === 'none') {
        return true;
    }

    if (!extension_loaded('openssl')) {
        return false;
    }

    $transports = admin_stream_transports_normalized();
    return in_array('ssl', $transports, true)
        || in_array('tls', $transports, true)
        || in_array('tlsv1.2', $transports, true)
        || in_array('tlsv1.3', $transports, true);
}

function admin_assert_email_transport_supported(array $service): void
{
    if (admin_email_transport_supported($service)) {
        return;
    }

    $encryption = strtoupper((string) ($service['encryption'] ?? 'SSL'));
    throw new RuntimeException(
        '当前 PHP 未启用 OpenSSL / ' . $encryption . ' 传输支持，无法连接邮件服务器。'
        . ' 请在 php.ini 中启用 `extension=openssl` 后重启 PHP。'
    );
}

function admin_config_admin_label(array $config = []): string
{
    if ($config === []) {
        $config = admin_load_config();
    }

    $username = trim((string) ($config['admin']['username'] ?? ''));
    return $username !== '' ? $username : ADMIN_DEFAULT_USERNAME;
}

function admin_build_admin_url(string $script, array $params = [], ?array $config = null): string
{
    if ($config === null) {
        $config = admin_load_config();
    }

    $baseUrl = admin_current_admin_base_url();
    if ($config !== [] && isset($config['email_service']) && is_array($config['email_service'])) {
        $prepared = admin_prepare_email_settings((array) $config['email_service']);
        $baseUrl = rtrim($prepared['site_url'], '/');
    }

    $url = rtrim($baseUrl, '/') . '/' . ltrim($script, '/');
    if ($params !== []) {
        $url .= '?' . http_build_query($params);
    }

    return $url;
}

function admin_open_database_from_settings(array $databaseSettings): PDO
{
    $driver = (string) ($databaseSettings['driver'] ?? '');
    if (!admin_is_driver_available($driver)) {
        throw new RuntimeException('当前环境未启用所选数据库驱动：' . $driver);
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    if (defined('PDO::ATTR_EMULATE_PREPARES')) {
        $options[PDO::ATTR_EMULATE_PREPARES] = false;
    }

    if ($driver === 'sqlite') {
        $path = (string) ($databaseSettings['path'] ?? '');
        if ($path === '') {
            throw new RuntimeException('缺少 SQLite 数据库路径。');
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建 SQLite 数据目录。');
        }

        $pdo = new PDO('sqlite:' . $path, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    if ($driver === 'mysql') {
        $host = trim((string) ($databaseSettings['host'] ?? ''));
        $port = (int) ($databaseSettings['port'] ?? 3306);
        $database = trim((string) ($databaseSettings['database'] ?? ''));
        $charset = trim((string) ($databaseSettings['charset'] ?? 'utf8mb4'));
        $username = (string) ($databaseSettings['username'] ?? '');
        $password = (string) ($databaseSettings['password'] ?? '');

        if ($host === '' || $database === '' || $username === '') {
            throw new RuntimeException('MySQL 连接信息不完整。');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
        return new PDO($dsn, $username, $password, $options);
    }

    throw new RuntimeException('不支持的数据库驱动。');
}

function admin_open_database(?array $config = null): PDO
{
    if ($config === null) {
        $config = admin_load_config();
    }

    $databaseSettings = $config['database'] ?? null;
    if (!is_array($databaseSettings)) {
        throw new RuntimeException('缺少数据库配置。');
    }

    return admin_open_database_from_settings($databaseSettings);
}

function admin_table_columns(PDO $pdo, string $table): array
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $statement = $pdo->query('PRAGMA table_info(' . $table . ')');
        $columns = [];
        foreach ($statement->fetchAll() as $column) {
            $name = trim((string) ($column['name'] ?? ''));
            if ($name !== '') {
                $columns[$name] = $column;
            }
        }
        return $columns;
    }

    $statement = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
    $columns = [];
    foreach ($statement->fetchAll() as $column) {
        $name = trim((string) ($column['Field'] ?? ''));
        if ($name !== '') {
            $columns[$name] = $column;
        }
    }
    return $columns;
}

function admin_db_datetime(int $timestamp): string
{
    return date('Y-m-d H:i:s', $timestamp);
}

function admin_db_timestamp(?string $datetime): int
{
    $value = trim((string) $datetime);
    if ($value === '') {
        return 0;
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? 0 : $timestamp;
}

function admin_ensure_database_schema(PDO $pdo): void
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                email TEXT NOT NULL DEFAULT \'\',
                email_verified_at TEXT NULL,
                password_hash TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_action_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                purpose TEXT NOT NULL,
                selector TEXT NOT NULL UNIQUE,
                token_hash TEXT NOT NULL,
                email TEXT NOT NULL,
                fingerprint_hash TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                consumed_at TEXT NULL,
                created_at TEXT NOT NULL
            )'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(64) NOT NULL UNIQUE,
                email VARCHAR(190) NOT NULL DEFAULT \'\',
                email_verified_at DATETIME NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_action_tokens (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                purpose VARCHAR(32) NOT NULL,
                selector VARCHAR(64) NOT NULL UNIQUE,
                token_hash VARCHAR(255) NOT NULL,
                email VARCHAR(190) NOT NULL,
                fingerprint_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                consumed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_admin_action_tokens_purpose (purpose),
                INDEX idx_admin_action_tokens_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    $columns = admin_table_columns($pdo, 'admin_users');

    if (!isset($columns['email'])) {
        $pdo->exec($driver === 'sqlite'
            ? 'ALTER TABLE admin_users ADD COLUMN email TEXT NOT NULL DEFAULT \'\''
            : 'ALTER TABLE admin_users ADD COLUMN email VARCHAR(190) NOT NULL DEFAULT \'\' AFTER username'
        );
    }

    if (!isset($columns['email_verified_at'])) {
        $pdo->exec($driver === 'sqlite'
            ? 'ALTER TABLE admin_users ADD COLUMN email_verified_at TEXT NULL'
            : 'ALTER TABLE admin_users ADD COLUMN email_verified_at DATETIME NULL AFTER email'
        );
    }
}

function admin_admin_record_exists(PDO $pdo, string $username): bool
{
    $statement = $pdo->prepare('SELECT id FROM admin_users WHERE username = :username LIMIT 1');
    $statement->execute(['username' => $username]);
    return (bool) $statement->fetchColumn();
}

function admin_fetch_admin_user_by_username(PDO $pdo, string $username): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, username, email, email_verified_at, password_hash, created_at, updated_at
         FROM admin_users WHERE username = :username LIMIT 1'
    );
    $statement->execute(['username' => $username]);
    $record = $statement->fetch();
    return is_array($record) ? $record : null;
}

function admin_fetch_admin_user_by_email(PDO $pdo, string $email): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, username, email, email_verified_at, password_hash, created_at, updated_at
         FROM admin_users WHERE email = :email LIMIT 1'
    );
    $statement->execute(['email' => $email]);
    $record = $statement->fetch();
    return is_array($record) ? $record : null;
}

function admin_fetch_admin_user_by_id(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, username, email, email_verified_at, password_hash, created_at, updated_at
         FROM admin_users WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $record = $statement->fetch();
    return is_array($record) ? $record : null;
}

function admin_find_admin_user_for_identity(PDO $pdo, string $identity): ?array
{
    $identity = trim($identity);
    if ($identity === '') {
        return null;
    }

    if (str_contains($identity, '@')) {
        $record = admin_fetch_admin_user_by_email($pdo, $identity);
        if (is_array($record)) {
            return $record;
        }
    }

    return admin_fetch_admin_user_by_username($pdo, $identity);
}

function admin_upsert_admin_account(PDO $pdo, string $username, string $email, string $password): array
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        throw new RuntimeException('管理员密码加密失败。');
    }

    $username = admin_validate_admin_username($username);
    $email = admin_validate_email_address($email);
    $now = admin_db_datetime(time());
    $existing = admin_fetch_admin_user_by_username($pdo, $username);

    if (is_array($existing)) {
        $update = $pdo->prepare(
            'UPDATE admin_users
             SET email = :email, email_verified_at = NULL, password_hash = :password_hash, updated_at = :updated_at
             WHERE id = :id'
        );
        $update->execute([
            'email' => $email,
            'password_hash' => $hash,
            'updated_at' => $now,
            'id' => $existing['id'],
        ]);

        return admin_fetch_admin_user_by_id($pdo, (int) $existing['id']) ?? $existing;
    }

    $insert = $pdo->prepare(
        'INSERT INTO admin_users (username, email, email_verified_at, password_hash, created_at, updated_at)
         VALUES (:username, :email, NULL, :password_hash, :created_at, :updated_at)'
    );
    $insert->execute([
        'username' => $username,
        'email' => $email,
        'password_hash' => $hash,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $userId = (int) $pdo->lastInsertId();
    return admin_fetch_admin_user_by_id($pdo, $userId) ?? [
        'id' => $userId,
        'username' => $username,
        'email' => $email,
        'email_verified_at' => null,
        'password_hash' => $hash,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

function admin_update_admin_password(PDO $pdo, int $userId, string $password): void
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        throw new RuntimeException('新密码加密失败。');
    }

    $statement = $pdo->prepare(
        'UPDATE admin_users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id'
    );
    $statement->execute([
        'password_hash' => $hash,
        'updated_at' => admin_db_datetime(time()),
        'id' => $userId,
    ]);
}

function admin_mark_email_verified(PDO $pdo, int $userId): void
{
    $statement = $pdo->prepare(
        'UPDATE admin_users SET email_verified_at = :verified_at, updated_at = :updated_at WHERE id = :id'
    );
    $now = admin_db_datetime(time());
    $statement->execute([
        'verified_at' => $now,
        'updated_at' => $now,
        'id' => $userId,
    ]);
}

function admin_write_config(array $config): void
{
    $content = "<?php\n\nreturn " . var_export($config, true) . ";\n";
    if (!is_dir(dirname(ADMIN_CONFIG_FILE))) {
        mkdir(dirname(ADMIN_CONFIG_FILE), 0755, true);
    }
    file_put_contents(ADMIN_CONFIG_FILE, $content, LOCK_EX);
    if (DIRECTORY_SEPARATOR !== '\\') {
        @chmod(ADMIN_CONFIG_FILE, 0600);
    }
}

function admin_update_email_service_settings(array $emailSettings): array
{
    $config = admin_load_config(true);
    if ($config === []) {
        throw new RuntimeException('请先完成初始化。');
    }

    $config['email_service'] = admin_prepare_email_settings($emailSettings);
    admin_write_config($config);
    admin_reset_auth_cache();
    return admin_load_config(true);
}

function admin_initialize_auth(
    array $databaseSettings,
    array $emailSettings,
    string $adminUsername,
    string $adminEmail,
    string $adminPassword
): array {
    $username = admin_validate_admin_username($adminUsername);
    $email = admin_validate_email_address($adminEmail);
    $emailSettings = admin_prepare_email_settings($emailSettings);

    $config = [
        'version' => 2,
        'created_at' => date('c'),
        'database' => $databaseSettings,
        'email_service' => $emailSettings,
        'admin' => [
            'username' => $username,
            'email' => $email,
        ],
    ];

    $pdo = admin_open_database_from_settings($databaseSettings);
    admin_ensure_database_schema($pdo);
    admin_upsert_admin_account($pdo, $username, $email, $adminPassword);
    admin_write_config($config);
    admin_reset_auth_cache();
    admin_load_config(true);
    admin_cached_value('initialized', true, true);
    admin_clear_pending_database_settings();
    admin_clear_pending_email_settings();

    return $config;
}

function admin_prepare_database_settings(array $databaseSettings): array
{
    $driver = (string) ($databaseSettings['driver'] ?? '');
    if ($driver === 'sqlite') {
        if (!admin_is_driver_available('sqlite')) {
            throw new RuntimeException('当前 PHP 未启用 PDO SQLite 扩展。');
        }

        $path = str_replace('\\', '/', (string) ($databaseSettings['path'] ?? ''));
        $path = ltrim($path, '/');
        if ($path === '') {
            $path = 'data/admin.sqlite';
        }

        $path = image_api_normalize_relative_path($path);
        if ($path === '' || str_contains($path, '../')) {
            throw new RuntimeException('SQLite 路径无效。');
        }

        return [
            'driver' => 'sqlite',
            'path' => __DIR__ . '/' . $path,
        ];
    }

    if ($driver === 'mysql') {
        if (!admin_is_driver_available('mysql')) {
            throw new RuntimeException('当前 PHP 未启用 PDO MySQL 扩展。');
        }

        $host = trim((string) ($databaseSettings['host'] ?? ''));
        $database = trim((string) ($databaseSettings['database'] ?? ''));
        $username = trim((string) ($databaseSettings['username'] ?? ''));
        $port = (int) ($databaseSettings['port'] ?? 3306);

        if ($host === '' || $database === '' || $username === '') {
            throw new RuntimeException('请完整填写 MySQL 连接信息。');
        }

        if ($port <= 0) {
            $port = 3306;
        }

        return [
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => (string) ($databaseSettings['password'] ?? ''),
            'charset' => 'utf8mb4',
        ];
    }

    throw new RuntimeException('请选择数据库类型。');
}

function admin_validate_database_settings(array $databaseSettings): array
{
    $prepared = admin_prepare_database_settings($databaseSettings);
    $pdo = admin_open_database_from_settings($prepared);
    admin_ensure_database_schema($pdo);
    return $prepared;
}

function admin_is_initialized(bool $refresh = false): bool
{
    if (!$refresh) {
        $cached = admin_cached_value('initialized');
        if (is_bool($cached)) {
            return $cached;
        }
    }

    $config = admin_load_config($refresh);
    if ($config === []) {
        admin_cached_value('initialized', false, true);
        return false;
    }

    try {
        $pdo = admin_open_database($config);
        admin_ensure_database_schema($pdo);
        $initialized = admin_admin_record_exists($pdo, admin_configured_username($config));
    } catch (Throwable) {
        $initialized = false;
    }

    admin_cached_value('initialized', $initialized, true);
    return $initialized;
}

function admin_verify_login(string $username, string $password): bool
{
    if (!admin_is_initialized()) {
        return false;
    }

    $pdo = admin_open_database();
    admin_ensure_database_schema($pdo);
    $record = admin_fetch_admin_user_by_username($pdo, $username);
    if (!is_array($record)) {
        return false;
    }

    return password_verify($password, (string) ($record['password_hash'] ?? ''));
}

function admin_cleanup_action_tokens(PDO $pdo): void
{
    $threshold = admin_db_datetime(time() - 86400);
    $statement = $pdo->prepare(
        'DELETE FROM admin_action_tokens
         WHERE expires_at < :threshold
            OR (consumed_at IS NOT NULL AND consumed_at < :threshold)'
    );
    $statement->execute(['threshold' => $threshold]);
}

function admin_create_action_token(PDO $pdo, array $user, string $purpose, string $fingerprint, int $ttlSeconds = 300): string
{
    admin_cleanup_action_tokens($pdo);

    $selector = bin2hex(random_bytes(12));
    $token = bin2hex(random_bytes(32));
    $statement = $pdo->prepare(
        'INSERT INTO admin_action_tokens
         (user_id, purpose, selector, token_hash, email, fingerprint_hash, expires_at, consumed_at, created_at)
         VALUES (:user_id, :purpose, :selector, :token_hash, :email, :fingerprint_hash, :expires_at, NULL, :created_at)'
    );
    $statement->execute([
        'user_id' => (int) ($user['id'] ?? 0),
        'purpose' => $purpose,
        'selector' => $selector,
        'token_hash' => hash('sha256', $token),
        'email' => (string) ($user['email'] ?? ''),
        'fingerprint_hash' => hash('sha256', $fingerprint),
        'expires_at' => admin_db_datetime(time() + $ttlSeconds),
        'created_at' => admin_db_datetime(time()),
    ]);

    return $selector . '.' . $token;
}

function admin_consume_action_token(string $combinedToken, string $purpose, string $fingerprint): array
{
    $parts = explode('.', trim($combinedToken), 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        throw new RuntimeException('链接无效或已失效。');
    }

    [$selector, $token] = $parts;
    $pdo = admin_open_database();
    admin_ensure_database_schema($pdo);
    admin_cleanup_action_tokens($pdo);

    $statement = $pdo->prepare(
        'SELECT * FROM admin_action_tokens WHERE selector = :selector AND purpose = :purpose LIMIT 1'
    );
    $statement->execute([
        'selector' => $selector,
        'purpose' => $purpose,
    ]);
    $record = $statement->fetch();

    if (!is_array($record)) {
        throw new RuntimeException('链接无效或已失效。');
    }

    if (!hash_equals((string) ($record['token_hash'] ?? ''), hash('sha256', $token))) {
        throw new RuntimeException('链接无效或已失效。');
    }

    if ((string) ($record['consumed_at'] ?? '') !== '') {
        throw new RuntimeException('链接已经使用过。');
    }

    if (admin_db_timestamp((string) ($record['expires_at'] ?? '')) < time()) {
        throw new RuntimeException('链接已过期，请重新获取。');
    }

    if (!hash_equals((string) ($record['fingerprint_hash'] ?? ''), hash('sha256', $fingerprint))) {
        throw new RuntimeException('当前设备无法使用这条链接。');
    }

    $consume = $pdo->prepare('UPDATE admin_action_tokens SET consumed_at = :consumed_at WHERE id = :id');
    $consume->execute([
        'consumed_at' => admin_db_datetime(time()),
        'id' => (int) ($record['id'] ?? 0),
    ]);

    $user = admin_fetch_admin_user_by_id($pdo, (int) ($record['user_id'] ?? 0));
    if (!is_array($user)) {
        throw new RuntimeException('账号不存在。');
    }

    return $user;
}

function admin_encode_mail_header(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($trimmed, 'UTF-8');
    }

    return '=?UTF-8?B?' . base64_encode($trimmed) . '?=';
}

function admin_smtp_read_response($stream): array
{
    $message = '';
    $code = 0;

    while (($line = fgets($stream, 515)) !== false) {
        $message .= $line;
        $lineCode = (int) substr($line, 0, 3);
        if ($lineCode > 0) {
            $code = $lineCode;
        }
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    return [$code, trim($message)];
}

function admin_smtp_expect($stream, array $expectedCodes): void
{
    [$code, $message] = admin_smtp_read_response($stream);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('邮件服务器响应异常：' . $message);
    }
}

function admin_smtp_command($stream, string $command, array $expectedCodes): void
{
    fwrite($stream, $command . "\r\n");
    admin_smtp_expect($stream, $expectedCodes);
}

function admin_render_mail_template(string $title, string $intro, string $actionLabel, string $actionUrl, array $metaLines = [], string $footer = ''): string
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeIntro = nl2br(htmlspecialchars($intro, ENT_QUOTES, 'UTF-8'));
    $safeActionLabel = htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8');
    $safeActionUrl = htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8');

    $metaHtml = '';
    foreach ($metaLines as $line) {
        $metaHtml .= '<div style="margin-top:8px;color:#526072;font-size:13px;line-height:1.7;">' .
            htmlspecialchars($line, ENT_QUOTES, 'UTF-8') .
            '</div>';
    }

    $footerHtml = $footer !== ''
        ? '<div style="margin-top:18px;color:#6b7785;font-size:12px;line-height:1.7;">' . nl2br(htmlspecialchars($footer, ENT_QUOTES, 'UTF-8')) . '</div>'
        : '';

    return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $safeTitle . '</title>
</head>
<body style="margin:0;padding:32px 16px;background:#edf2f7;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Microsoft YaHei UI,Arial,sans-serif;color:#111827;">
    <div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #d8e1ea;border-radius:22px;overflow:hidden;box-shadow:0 16px 36px rgba(15,23,42,0.08);">
        <div style="padding:18px 24px;background:linear-gradient(135deg,#111827,#233044);color:#f8fafc;">
            <div style="font-size:12px;letter-spacing:0.12em;text-transform:uppercase;opacity:0.75;">Image API</div>
            <div style="margin-top:8px;font-size:28px;line-height:1.2;font-weight:700;">' . $safeTitle . '</div>
        </div>
        <div style="padding:28px 24px 30px;">
            <div style="color:#334155;font-size:15px;line-height:1.85;">' . $safeIntro . '</div>
            <div style="margin-top:24px;">
                <a href="' . $safeActionUrl . '" style="display:inline-block;padding:13px 22px;border-radius:999px;background:#111827;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;letter-spacing:0.01em;">' . $safeActionLabel . '</a>
            </div>
            <div style="margin-top:16px;color:#526072;font-size:13px;line-height:1.8;word-break:break-all;">如果按钮无法点击，请复制这个地址到浏览器打开：<br>' . $safeActionUrl . '</div>
            ' . $metaHtml . '
            ' . $footerHtml . '
        </div>
    </div>
</body>
</html>';
}

function admin_send_smtp_mail(array $service, string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
{
    if (empty($service['enabled'])) {
        throw new RuntimeException('邮箱服务未启用。');
    }

    $host = (string) ($service['host'] ?? '');
    $port = (int) ($service['port'] ?? 0);
    $encryption = (string) ($service['encryption'] ?? 'tls');
    $transport = $encryption === 'ssl' ? 'ssl://' : '';
    $stream = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $error,
        15,
        STREAM_CLIENT_CONNECT
    );

    if (!is_resource($stream)) {
        throw new RuntimeException('无法连接到邮件服务器：' . $error);
    }

    stream_set_timeout($stream, 15);
    admin_smtp_expect($stream, [220]);
    $heloHost = preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
    admin_smtp_command($stream, 'EHLO ' . $heloHost, [250]);

    if ($encryption === 'tls') {
        admin_smtp_command($stream, 'STARTTLS', [220]);
        if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('无法启用 TLS 加密。');
        }
        admin_smtp_command($stream, 'EHLO ' . $heloHost, [250]);
    }

    $username = (string) ($service['username'] ?? '');
    $password = (string) ($service['password'] ?? '');
    if ($username !== '') {
        admin_smtp_command($stream, 'AUTH LOGIN', [334]);
        admin_smtp_command($stream, base64_encode($username), [334]);
        admin_smtp_command($stream, base64_encode($password), [235]);
    }

    $fromEmail = (string) ($service['from_email'] ?? '');
    $fromName = (string) ($service['from_name'] ?? '图片服务');
    admin_smtp_command($stream, 'MAIL FROM:<' . $fromEmail . '>', [250]);
    admin_smtp_command($stream, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
    admin_smtp_command($stream, 'DATA', [354]);

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . admin_encode_mail_header($fromName) . ' <' . $fromEmail . '>',
        'To: <' . $toEmail . '>',
        'Subject: ' . admin_encode_mail_header($subject),
        'MIME-Version: 1.0',
    ];

    $plainBody = preg_replace("/(?m)^\./", '..', str_replace(["\r\n", "\r"], "\n", $textBody));
    $plainBody = str_replace("\n", "\r\n", $plainBody);

    if ($htmlBody !== null && trim($htmlBody) !== '') {
        $boundary = 'b_' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $html = preg_replace("/(?m)^\./", '..', str_replace(["\r\n", "\r"], "\n", $htmlBody));
        $html = str_replace("\n", "\r\n", $html);

        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $plainBody . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $html . "\r\n\r\n"
            . '--' . $boundary . '--';
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $body = $plainBody;
    }

    fwrite($stream, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n");
    admin_smtp_expect($stream, [250]);
    admin_smtp_command($stream, 'QUIT', [221]);
    fclose($stream);
}

function admin_send_admin_verification_email(array $user, ?array $config = null): void
{
    if ($config === null) {
        $config = admin_load_config();
    }

    if (!admin_email_service_enabled($config)) {
        return;
    }

    $pdo = admin_open_database($config);
    admin_ensure_database_schema($pdo);
    $token = admin_create_action_token(
        $pdo,
        $user,
        ADMIN_TOKEN_PURPOSE_EMAIL_VERIFY,
        admin_device_fingerprint(),
        300
    );
    $link = admin_build_admin_url('verify_email.php', ['token' => $token], $config);
    $body = "你好，\n\n请在 5 分钟内打开下面的链接验证管理员邮箱：\n{$link}\n\n此链接仅可使用一次，并且只能在当前设备上打开。";
    $html = admin_render_mail_template(
        '验证管理员邮箱',
        "你好，\n请点击下面的按钮完成邮箱验证。",
        '立即验证',
        $link,
        [
            '链接有效期：5 分钟',
            '链接使用次数：仅 1 次',
            '链接限制：仅当前设备可用',
        ],
        '如果这不是你发起的操作，可以忽略这封邮件。'
    );

    admin_send_smtp_mail(
        admin_email_service_config($config),
        (string) ($user['email'] ?? ''),
        '验证管理员邮箱',
        $body,
        $html
    );
}

function admin_send_password_reset_email_for_identity(string $identity): void
{
    $config = admin_load_config();
    if (!admin_email_service_enabled($config)) {
        throw new RuntimeException('当前未启用邮箱服务，暂时无法找回密码。');
    }

    $pdo = admin_open_database($config);
    admin_ensure_database_schema($pdo);
    $user = admin_find_admin_user_for_identity($pdo, $identity);
    if (!is_array($user) || trim((string) ($user['email'] ?? '')) === '') {
        return;
    }

    $token = admin_create_action_token(
        $pdo,
        $user,
        ADMIN_TOKEN_PURPOSE_PASSWORD_RESET,
        admin_device_fingerprint(),
        300
    );
    $link = admin_build_admin_url('reset_password.php', ['token' => $token], $config);
    $body = "你好，\n\n请在 5 分钟内打开下面的链接重置密码：\n{$link}\n\n此链接仅可使用一次，并且只能在当前设备上打开。";
    $html = admin_render_mail_template(
        '重置登录密码',
        "你好，\n请点击下面的按钮重置管理员密码。",
        '重置密码',
        $link,
        [
            '链接有效期：5 分钟',
            '链接使用次数：仅 1 次',
            '链接限制：仅当前设备可用',
        ],
        '如果不是你本人操作，请尽快检查后台账号安全。'
    );

    admin_send_smtp_mail(
        admin_email_service_config($config),
        (string) ($user['email'] ?? ''),
        '重置管理员密码',
        $body,
        $html
    );
}

function admin_send_test_email(string $toEmail, ?array $config = null): void
{
    if ($config === null) {
        $config = admin_load_config();
    }

    $service = admin_email_service_config($config);
    if (empty($service['enabled'])) {
        throw new RuntimeException('请先启用邮箱服务。');
    }

    $toEmail = admin_validate_email_address($toEmail);
    $dashboardUrl = admin_build_admin_url('settings.php', [], $config);
    $title = '邮箱配置测试';
    $plain = "你好，\n\n这是一封测试邮件，说明后台邮箱服务已经可以正常发信。\n\n后台地址：{$dashboardUrl}";
    $html = admin_render_mail_template(
        $title,
        "你好，\n这是一封测试邮件，说明后台邮箱服务已经可以正常发信。",
        '打开后台',
        $dashboardUrl,
        [
            '发信账号：' . ((string) ($service['from_email'] ?? '')),
            'SMTP 服务：' . ((string) ($service['host'] ?? '')),
        ],
        '如果你能收到这封邮件，说明邮箱配置已经生效。'
    );

    admin_send_smtp_mail($service, $toEmail, $title, $plain, $html);
}

function admin_read_security_alert_state(): array
{
    if (!is_file(ADMIN_SECURITY_ALERT_FILE)) {
        return [];
    }

    $raw = file_get_contents(ADMIN_SECURITY_ALERT_FILE);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function admin_write_security_alert_state(array $state): void
{
    file_put_contents(
        ADMIN_SECURITY_ALERT_FILE,
        json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        LOCK_EX
    );
}

function admin_security_alert_email(array $config = []): string
{
    if ($config === []) {
        $config = admin_load_config();
    }

    return trim((string) ($config['admin']['email'] ?? ''));
}

function admin_send_security_notice(string $subject, string $intro, array $metaLines = [], ?array $config = null): void
{
    if ($config === null) {
        $config = admin_load_config();
    }

    if (!admin_email_service_enabled($config)) {
        return;
    }

    $toEmail = admin_security_alert_email($config);
    if ($toEmail === '') {
        return;
    }

    $dashboardUrl = admin_build_admin_url('index.php', [], $config);
    $plain = $intro . "\n\n" . implode("\n", $metaLines) . "\n\n后台地址：" . $dashboardUrl;
    $html = admin_render_mail_template(
        $subject,
        $intro,
        '打开后台',
        $dashboardUrl,
        $metaLines,
        '这是一封系统安全提示邮件。'
    );

    admin_send_smtp_mail(admin_email_service_config($config), $toEmail, $subject, $plain, $html);
}

function admin_maybe_send_failed_login_alert(string $username, string $ip, int $failures): void
{
    if ($failures < 4) {
        return;
    }

    if ($failures !== 4 && (($failures - 4) % 5) !== 0) {
        return;
    }

    try {
        $config = admin_load_config();
        admin_send_security_notice(
            '后台登录失败提醒',
            '检测到后台登录密码连续输错，请尽快确认是否为本人操作。',
            [
                '账号：' . ($username !== '' ? $username : '未填写'),
                'IP：' . $ip,
                '失败次数：' . $failures,
                '时间：' . date('Y-m-d H:i:s'),
            ],
            $config
        );
    } catch (Throwable) {
        // Ignore notice delivery failures to avoid affecting login flow.
    }
}

function admin_track_request_spike(): void
{
    if (!admin_is_initialized()) {
        return;
    }

    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '') {
        return;
    }

    $state = admin_read_security_alert_state();
    $requests = $state['requests'] ?? [];
    $entry = $requests[$ip] ?? ['timestamps' => [], 'last_notified_at' => 0];
    $now = time();
    $timestamps = array_values(array_filter(
        array_map('intval', (array) ($entry['timestamps'] ?? [])),
        static fn(int $timestamp): bool => $timestamp >= ($now - 60)
    ));
    $timestamps[] = $now;

    $entry['timestamps'] = $timestamps;
    $lastNotifiedAt = (int) ($entry['last_notified_at'] ?? 0);

    if (count($timestamps) >= 50 && ($lastNotifiedAt === 0 || ($now - $lastNotifiedAt) >= 60)) {
        $entry['last_notified_at'] = $now;
        try {
            admin_send_security_notice(
                '后台访问频率异常提醒',
                '检测到后台在 1 分钟内出现高频访问，请留意是否存在异常扫描或撞库行为。',
                [
                    'IP：' . $ip,
                    '1 分钟内访问次数：' . count($timestamps),
                    '时间：' . date('Y-m-d H:i:s', $now),
                ]
            );
        } catch (Throwable) {
            // Ignore mail send failures and keep request tracking alive.
        }
    }

    $requests[$ip] = $entry;
    $state['requests'] = $requests;
    admin_write_security_alert_state($state);
}

function admin_bootstrap_setup_flow(): void
{
    $currentScript = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
    $isInitialized = admin_is_initialized();
    $isLoggedIn = !empty($_SESSION['image_api_admin_logged_in']);
    $hasPendingDatabaseSettings = is_array(admin_pending_database_settings());
    $setupPages = ['setup.php', 'setup_account.php'];
    $publicAuthPages = ['login.php', 'forgot_password.php', 'reset_password.php', 'verify_email.php'];

    if (!$isInitialized) {
        if (!$hasPendingDatabaseSettings) {
            if ($currentScript !== 'setup.php') {
                header('Location: setup.php');
                exit;
            }
            return;
        }

        if (!in_array($currentScript, $setupPages, true)) {
            header('Location: setup_account.php');
            exit;
        }
        return;
    }

    if (in_array($currentScript, $setupPages, true)) {
        header('Location: ' . ($isLoggedIn ? 'index.php' : 'login.php'));
        exit;
    }

    if (!$isLoggedIn && !in_array($currentScript, $publicAuthPages, true)) {
        header('Location: login.php');
        exit;
    }

    if ($isLoggedIn && in_array($currentScript, ['login.php', 'forgot_password.php', 'reset_password.php'], true)) {
        header('Location: index.php');
        exit;
    }
}
