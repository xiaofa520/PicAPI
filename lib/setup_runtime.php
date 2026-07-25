<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

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
function prepare_setup_database(array $input): array
{
    $database = setup_database_options($input);
    if ($database['type'] === 'sqlite') {
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
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $database['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    } elseif ($database['type'] === 'pgsql') {
        $serverDsn = 'pgsql:host=' . $database['host'] . ($port === '' ? '' : ';port=' . $port) . ';dbname=postgres';
        $pdo = new PDO($serverDsn, $database['username'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $before = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $before->execute([$database['name']]);
        $created = $before->fetchColumn() === false;
        if ($created) $pdo->exec('CREATE DATABASE "' . str_replace('"', '""', $database['name']) . '"');
    } else {
        $serverDsn = 'sqlsrv:Server=' . $database['host'] . ($port === '' ? '' : ',' . $port);
        $pdo = new PDO($serverDsn, $database['username'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $before = $pdo->prepare('SELECT DB_ID(?)');
        $before->execute([$database['name']]);
        $created = $before->fetchColumn() === null;
        if ($created) $pdo->exec('CREATE DATABASE [' . str_replace(']', ']]', $database['name']) . ']');
    }

    test_setup_database($input);
    return ['created' => $created, 'message' => $created ? '本地数据库已创建并连接成功。' : '本地数据库连接成功。'];
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
