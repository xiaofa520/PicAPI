<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

const SETUP_CONNECTION_TEST_TTL_SECONDS = 300;

class SetupDraftExpiredException extends InvalidArgumentException {}

// The session nonce binds setup requests to one browser session. Drafts do not
// expire on an arbitrary timer; the timestamp is kept in browser sessionStorage.
function setup_draft_window(): array
{
    $window = $_SESSION['setup_draft_window'] ?? [];
    if (!is_string($window['token'] ?? null) || strlen($window['token']) !== 64) {
        $window = ['token' => bin2hex(random_bytes(32)), 'created_at' => time()];
        $_SESSION['setup_draft_window'] = $window;
        unset($_SESSION['setup_connection_tests']);
    } elseif (!isset($window['created_at']) || isset($window['expires_at'])) {
        $window = ['token' => $window['token'], 'created_at' => (int) ($window['created_at'] ?? time())];
        $_SESSION['setup_draft_window'] = $window;
    }
    return $window;
}

function require_setup_draft(array $input): void
{
    $window = $_SESSION['setup_draft_window'] ?? [];
    if (!is_string($input['setup_draft_token'] ?? null)
        || !hash_equals((string) ($window['token'] ?? ''), $input['setup_draft_token'])
        || empty($window['token'])) {
        throw new SetupDraftExpiredException('初始化会话已失效，请刷新页面后重新继续。');
    }
}

function setup_password_has_minimum_length(string $password): bool
{
    $length = preg_match_all('/./us', $password);
    return $length !== false && $length >= 8;
}

// Skipped email configuration must never connect or persist draft credentials.
function setup_mail_configuration(array $input, string $recipient): array
{
    $mode = (string) ($input['smtp_mode'] ?? 'configure');
    if ($mode === 'skip') return [];
    if ($mode !== 'configure') throw new InvalidArgumentException('请选择配置邮件或稍后配置。');
    require_once __DIR__ . '/smtp_runtime.php';
    smtp_test_message($input, $recipient);
    return [
        'host' => trim((string) ($input['smtp_host'] ?? '')),
        'port' => trim((string) ($input['smtp_port'] ?? '')),
        'username' => trim((string) ($input['smtp_username'] ?? '')),
        'password' => (string) ($input['smtp_password'] ?? ''),
        'encryption' => (string) ($input['smtp_encryption'] ?? 'ssl'),
    ];
}

// Bind successful checks to the current session and exact connection settings.
function setup_connection_fingerprint(array $input, string $stage): string
{
    $database = setup_database_options($input);
    $values = $stage === 'database' ? $database : [
        'database' => $database, 'enabled' => !empty($input['redis_enabled']),
        'host' => trim((string) ($input['redis_host'] ?? '127.0.0.1')),
        'password' => (string) ($input['redis_password'] ?? ''),
    ];
    return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
}

function setup_connection_test_record(array $input, string $stage): void
{
    $_SESSION['setup_connection_tests'][$stage] = ['fingerprint' => setup_connection_fingerprint($input, $stage), 'time' => time()];
}

function setup_connection_test_valid(array $input, string $stage): bool
{
    $record = $_SESSION['setup_connection_tests'][$stage] ?? [];
    return ($record['time'] ?? 0) > time() - SETUP_CONNECTION_TEST_TTL_SECONDS
        && hash_equals(setup_connection_fingerprint($input, $stage), (string) ($record['fingerprint'] ?? ''));
}

function require_setup_connection_tests(array $input): void
{
    if (!setup_connection_test_valid($input, 'database')) {
        throw new InvalidArgumentException('请先测试数据库连接，配置修改后需要重新测试。');
    }
    if (!empty($input['redis_enabled']) && !setup_connection_test_valid($input, 'redis')) {
        throw new InvalidArgumentException('请先完成 Redis 连接测试，配置修改后需要重新测试。');
    }
}

/** @return array{type:string,host:string,port:string,name:string,username:string,password:string,dsn:string} */
function setup_database_options(array $input): array
{
    $type = (string) ($input['db_type'] ?? 'sqlite');
    if (!in_array($type, ['mysql', 'sqlserver', 'pgsql', 'sqlite'], true)) {
        throw new InvalidArgumentException('不支持的数据库类型。');
    }
    $host = trim((string) ($input['db_host'] ?? '')) ?: '127.0.0.1';
    $port = trim((string) ($input['db_port'] ?? ''));
    $name = trim((string) ($input['db_name'] ?? '')) ?: 'picapi_db';
    $username = trim((string) ($input['db_username'] ?? ''));
    $password = (string) ($input['db_password'] ?? '');

    if ($type === 'sqlite') {
        return [
            'type' => $type, 'host' => '', 'port' => '', 'name' => 'picapi.sqlite', 'username' => '', 'password' => '',
            'dsn' => 'sqlite:' . LOCAL_DATABASE_DIR . '/picapi.sqlite',
        ];
    }
    if ($host === '' || $name === '' || $username === '' || ($port !== '' && (!ctype_digit($port) || (int) $port > 65535))) {
        throw new InvalidArgumentException('请完整填写数据库主机、端口、库名和用户名。');
    }
    $dsn = match ($type) {
        'mysql' => 'mysql:host=' . $host . ($port === '' ? '' : ';port=' . $port) . ';dbname=' . $name . ';charset=utf8mb4',
        'pgsql' => 'pgsql:host=' . $host . ($port === '' ? '' : ';port=' . $port) . ';dbname=' . $name,
        'sqlserver' => 'sqlsrv:Server=' . $host . ($port === '' ? '' : ',' . $port) . ';Database=' . $name,
    };
    return compact('type', 'host', 'port', 'name', 'username', 'password', 'dsn');
}

function test_setup_database(array $input): void
{
    $database = setup_database_options($input);
    $driver = $database['type'] === 'sqlserver' ? 'sqlsrv' : $database['type'];
    if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException("服务器未安装 {$driver} 数据库驱动，请安装后重试。");
    }
    new PDO($database['dsn'], $database['username'], $database['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

/** @return array{created:bool,message:string} */
function prepare_setup_database(array $input, bool $create = true): array
{
    $database = setup_database_options($input);
    if ($database['type'] === 'sqlite') {
        if (!$create) {
            new PDO('sqlite::memory:');
            return ['created' => false, 'message' => 'SQLite 驱动可用，完成初始化时创建数据库。'];
        }
        $created = !is_file(LOCAL_DATABASE_DIR . '/picapi.sqlite');
        test_setup_database($input);
        return ['created' => $created, 'message' => $created ? '本地 SQLite 数据库已创建并连接成功。' : 'SQLite 数据库连接成功。'];
    }

    if (!in_array(strtolower($database['host']), ['127.0.0.1', 'localhost', '::1'], true)) {
        test_setup_database($input);
        return ['created' => false, 'message' => '远程数据库连接成功。'];
    }

    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $database['name'])) {
        throw new InvalidArgumentException('本地自动创建数据库时，库名只能使用字母、数字和下划线，且不能以数字开头。');
    }

    $driver = $database['type'] === 'sqlserver' ? 'sqlsrv' : $database['type'];
    if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException("服务器未安装 {$driver} 数据库驱动，请安装后重试。");
    }

    $port = $database['port'];
    $created = false;
    if ($database['type'] === 'mysql') {
        $serverDsn = 'mysql:host=' . $database['host'] . ($port === '' ? '' : ';port=' . $port) . ';charset=utf8mb4';
        $pdo = new PDO($serverDsn, $database['username'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $before = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
        $before->execute([$database['name']]);
        $created = $before->fetchColumn() === false;
        if ($create) $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $database['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    } elseif ($database['type'] === 'pgsql') {
        $serverDsn = 'pgsql:host=' . $database['host'] . ($port === '' ? '' : ';port=' . $port) . ';dbname=postgres';
        $pdo = new PDO($serverDsn, $database['username'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $before = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $before->execute([$database['name']]);
        $created = $before->fetchColumn() === false;
        if ($create && $created) $pdo->exec('CREATE DATABASE "' . str_replace('"', '""', $database['name']) . '"');
    } else {
        $serverDsn = 'sqlsrv:Server=' . $database['host'] . ($port === '' ? '' : ',' . $port);
        $pdo = new PDO($serverDsn, $database['username'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $before = $pdo->prepare('SELECT DB_ID(?)');
        $before->execute([$database['name']]);
        $created = $before->fetchColumn() === null;
        if ($create && $created) $pdo->exec('CREATE DATABASE [' . str_replace(']', ']]', $database['name']) . ']');
    }

    if (!$create && $created) return ['created' => false, 'message' => '数据库服务器连接成功，完成初始化时创建数据库。'];
    test_setup_database($input);
    return ['created' => $created, 'message' => $created ? '本地数据库已创建并连接成功。' : '本地数据库连接成功。'];
}

/** One-time installation code is stored in the project root, outside public/. */
function setup_code_path(): string
{
    return APP_ROOT . '/setup.txt';
}

function setup_code_ensure(): void
{
    if (setup_code_is_verified()) return;
    $path = setup_code_path();
    $legacyPath = STORAGE_DIR . '/setup.token';
    if (is_file($legacyPath) && !is_link($legacyPath)) @unlink($legacyPath);
    if (is_file($path) && !is_link($path) && trim((string) @file_get_contents($path)) !== '') return;
    if (is_link($path)) throw new RuntimeException('项目根目录的 setup.txt 不能是符号链接。');

    $lockPath = STORAGE_DIR . '/setup-code.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) throw new RuntimeException('无法准备初始化验证码锁，请检查 storage 目录权限。');
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('无法锁定初始化验证码文件。');
        if (is_file($path) && !is_link($path) && trim((string) @file_get_contents($path)) !== '') return;
        $code = strtoupper(bin2hex(random_bytes(16)));
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, $code . PHP_EOL, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('无法生成项目根目录的 setup.txt，请检查项目目录写入权限。');
        }
        @chmod($path, 0600);
        clearstatcache(true, $path);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function setup_code_is_verified(): bool
{
    return ($_SESSION['setup_code_verified'] ?? false) === true;
}

function setup_code_matches(string $submitted): bool
{
    $path = setup_code_path();
    if (!is_file($path) || is_link($path)) return false;
    $expected = trim((string) @file_get_contents($path));
    return $expected !== '' && hash_equals(strtoupper($expected), strtoupper(trim($submitted)));
}

/** Validate once, bind success to this session, and immediately remove the file. */
function require_setup_code(array $input): void
{
    if (setup_code_is_verified()) return;
    if (!is_string($input['setup_code'] ?? null) || !setup_code_matches($input['setup_code'])) {
        throw new InvalidArgumentException('验证码错误，请检查项目根目录 setup.txt 中的内容。');
    }
    $_SESSION['setup_code_verified'] = true;
    $path = setup_code_path();
    if (is_file($path) && !is_link($path) && !@unlink($path)) {
        unset($_SESSION['setup_code_verified']);
        throw new RuntimeException('验证码验证成功，但无法删除 setup.txt；请检查项目目录权限后重试。');
    }
    clearstatcache(true, $path);
}

function require_setup_code_verified(): void
{
    if (!setup_code_is_verified()) {
        throw new InvalidArgumentException('请先在数据库连接步骤输入项目根目录中的本地验证码。');
    }
}

function save_installation_config(array $config): void
{
    $path = STORAGE_DIR . '/installation.php';
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $contents = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to save installation configuration.');
    }
    if (function_exists('opcache_invalidate')) opcache_invalidate($path, true);
    clearstatcache(true, $path);
    // The file holds database and SMTP credentials; keep it owner-only.
    @chmod($path, 0600);
}

function create_database_admin(array $database, string $username, string $email, string $password): bool
{
    $pdo = new PDO($database['dsn'], $database['username'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $driver = $database['type'] === 'sqlserver' ? 'sqlsrv' : $database['type'];
    if ($driver === 'sqlsrv') {
        $pdo->exec("IF OBJECT_ID('picapi_admin_users', 'U') IS NULL CREATE TABLE picapi_admin_users (username NVARCHAR(48) PRIMARY KEY, email NVARCHAR(255) NOT NULL, password_hash NVARCHAR(255) NOT NULL, created_at NVARCHAR(40) NOT NULL)");
    } else {
        $pdo->exec('CREATE TABLE IF NOT EXISTS picapi_admin_users (username VARCHAR(48) PRIMARY KEY, email VARCHAR(255) NOT NULL, password_hash VARCHAR(255) NOT NULL, created_at VARCHAR(40) NOT NULL)');
    }
    $existing = $pdo->prepare('SELECT 1 FROM picapi_admin_users WHERE username = :username');
    $existing->execute(['username' => $username]);
    if ($existing->fetchColumn() !== false) return false;
    try {
        $statement = $pdo->prepare('INSERT INTO picapi_admin_users (username, email, password_hash, created_at) VALUES (:username, :email, :password_hash, :created_at)');
        $statement->execute(['username' => $username, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'created_at' => gmdate('c')]);
        return true;
    } catch (PDOException $exception) {
        if ((string) $exception->getCode() === '23000') return false;
        throw $exception;
    }
}
