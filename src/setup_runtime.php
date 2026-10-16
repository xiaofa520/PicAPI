<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

const SETUP_CONNECTION_TEST_TTL_SECONDS = 300;

class SetupDraftExpiredException extends InvalidArgumentException {}

function setup_signed_token(string $purpose, string $nonce): ?string
{
    $key = setup_local_signing_key();
    if ($key === null) return null;
    $domain = request_domain_binding() . ':';
    return $nonce . '.' . hash_hmac('sha256', 'setup:' . $purpose . ':' . $domain . $nonce, $key);
}

function setup_signed_token_is_valid(string $purpose, string $token): bool
{
    if (preg_match('/^([a-f0-9]{64})\.([a-f0-9]{64})$/D', $token, $matches) !== 1) return false;
    $expected = setup_signed_token($purpose, $matches[1]);
    return $expected !== null && hash_equals($expected, $token);
}

function setup_draft_legacy_nonce(string $token): ?string
{
    if (preg_match('/^([a-f0-9]{64})\.([a-f0-9]{64})$/D', $token, $matches) !== 1) return null;
    $key = setup_local_signing_key();
    if ($key === null) return null;
    $nonce = $matches[1];
    $legacySignatures = [
        hash_hmac('sha256', 'setup:setup-draft::' . $nonce, $key),
        hash_hmac('sha256', 'setup:setup-draft:unknown:' . $nonce, $key),
        hash_hmac('sha256', 'setup-draft:' . $nonce, $key),
    ];
    $legacyDomains = [request_domain_binding(), (string) ($_SESSION['setup_draft_window']['domain'] ?? '')];
    // Older builds could bind drafts to PICAPI_BASE_URL. Accept that value
    // only for migration; the replacement token is always signed to this request.
    $configuredAuthority = normalize_request_authority((string) (getenv('PICAPI_BASE_URL') ?: ''));
    if ($configuredAuthority !== null) $legacyDomains[] = $configuredAuthority;
    foreach (['HTTP_HOST', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_ORIGINAL_HOST'] as $header) {
        $domain = normalize_request_authority(explode(',', (string) ($_SERVER[$header] ?? ''))[0]);
        if ($domain !== null) $legacyDomains[] = $domain;
    }
    $forwarded = explode(',', (string) ($_SERVER['HTTP_FORWARDED'] ?? ''))[0];
    if (preg_match('/(?:^|;)\s*host=(?:"([^"]+)"|([^;\s]+))/i', $forwarded, $forwardedMatches) === 1) {
        $domain = normalize_request_authority((string) (($forwardedMatches[1] ?? '') !== '' ? $forwardedMatches[1] : ($forwardedMatches[2] ?? '')));
        if ($domain !== null) $legacyDomains[] = $domain;
    }
    foreach (array_unique($legacyDomains) as $domain) {
        if ($domain !== '') $legacySignatures[] = hash_hmac('sha256', 'setup:setup-draft:' . $domain . ':' . $nonce, $key);
    }
    foreach ($legacySignatures as $signature) if (hash_equals($signature, $matches[2])) return $nonce;
    return null;
}

function rebind_setup_draft(string $previousToken, ?string $nonce = null): array
{
    $nonce ??= bin2hex(random_bytes(32));
    $token = setup_signed_token('setup-draft', $nonce) ?? bin2hex(random_bytes(32));
    $window = [
        'token' => $token,
        'previous_token' => $previousToken,
        'created_at' => time(),
        'domain' => request_domain_binding(),
    ];
    $_SESSION['setup_draft_window'] = $window;
    unset($_SESSION['setup_connection_tests']);
    return $window;
}

function setup_draft_window(?string $candidate = null): array
{
    $domain = request_domain_binding();
    if (is_string($candidate) && setup_signed_token_is_valid('setup-draft', $candidate)) {
        $window = ['token' => $candidate, 'created_at' => time(), 'domain' => $domain];
        $_SESSION['setup_draft_window'] = $window;
        return $window;
    }
    $window = $_SESSION['setup_draft_window'] ?? [];
    $candidateMatchesWindow = is_string($candidate) && $candidate !== ''
        && hash_equals((string) ($window['token'] ?? ''), $candidate);
    $legacyNonce = is_string($candidate) ? setup_draft_legacy_nonce($candidate) : null;
    if (is_string($candidate) && $candidate !== '' && csrf_request_has_same_origin_proof()
        && ($candidateMatchesWindow || $legacyNonce !== null)) {
        return rebind_setup_draft($candidate, $legacyNonce);
    }
    if (hash_equals($domain, (string) ($window['domain'] ?? ''))
        && setup_signed_token_is_valid('setup-draft', (string) ($window['token'] ?? ''))) return $window;

    $nonce = bin2hex(random_bytes(32));
    $token = setup_signed_token('setup-draft', $nonce) ?? $nonce;
    $window = ['token' => $token, 'created_at' => time(), 'domain' => $domain];
    $_SESSION['setup_draft_window'] = $window;
    unset($_SESSION['setup_connection_tests']);
    return $window;
}

function require_setup_draft(array $input): void
{
    $token = is_string($input['setup_draft_token'] ?? null) ? $input['setup_draft_token'] : '';
    if (setup_signed_token_is_valid('setup-draft', $token)) return;
    $window = $_SESSION['setup_draft_window'] ?? [];
    $domain = request_domain_binding();
    $sameOrigin = csrf_request_has_same_origin_proof();
    if ($sameOrigin && hash_equals((string) ($window['domain'] ?? ''), $domain)) {
        if (hash_equals((string) ($window['token'] ?? ''), $token)
            || hash_equals((string) ($window['previous_token'] ?? ''), $token)) return;
    }
    $legacyNonce = setup_draft_legacy_nonce($token);
    if ($sameOrigin && ($legacyNonce !== null || hash_equals((string) ($window['token'] ?? ''), $token))) {
        rebind_setup_draft($token, $legacyNonce);
        return;
    }
    throw new SetupDraftExpiredException('初始化会话已失效，请刷新页面后重新继续。');
}

function setup_browser_binding_payload(?string $candidate = null): array
{
    $window = setup_draft_window($candidate);
    return ['csrf' => csrf_token(), 'setupDraftToken' => (string) ($window['token'] ?? '')];
}

function setup_password_has_minimum_length(string $password): bool
{
    $length = preg_match_all('/./us', $password);
    return $length !== false && $length >= 8;
}

// Saving mail settings only validates the fields; the email step's test button performs the SMTP connection and send.
function setup_mail_configuration(array $input, string $recipient): array
{
    $mode = (string) ($input['smtp_mode'] ?? 'configure');
    if ($mode === 'skip') return [];
    if ($mode !== 'configure') throw new InvalidArgumentException('请选择配置邮件或稍后配置。');
    $host = trim((string) ($input['smtp_host'] ?? ''));
    $port = trim((string) ($input['smtp_port'] ?? ''));
    $username = trim((string) ($input['smtp_username'] ?? ''));
    $password = (string) ($input['smtp_password'] ?? '');
    $encryption = (string) ($input['smtp_encryption'] ?? 'ssl');
    if ($host === '' || !preg_match('/^\d{1,5}$/', $port) || (int) $port < 1 || (int) $port > 65535
        || $username === '' || $password === '' || !in_array($encryption, ['ssl', 'tls'], true)) {
        throw new InvalidArgumentException('邮件配置不完整，请检查服务器地址、端口、账号、密码与加密方式。');
    }
    return [
        'host' => $host,
        'port' => $port,
        'username' => $username,
        'password' => $password,
        'encryption' => $encryption,
    ];
}

// Bind successful checks to the exact connection settings; signed cookies let
// the setup flow survive proxy routing to another PHP worker.
function setup_connection_fingerprint(array $input, string $stage): string
{
    $database = setup_database_options($input);
    $values = ['domain' => request_domain_binding(), 'database' => $database];
    if ($stage !== 'database') $values += [
        'enabled' => !empty($input['redis_enabled']),
        'host' => trim((string) ($input['redis_host'] ?? '127.0.0.1')),
        'password' => (string) ($input['redis_password'] ?? ''),
    ];
    return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
}

function setup_connection_test_record(array $input, string $stage): void
{
    $fingerprint = setup_connection_fingerprint($input, $stage);
    $time = time();
    $_SESSION['setup_connection_tests'][$stage] = ['fingerprint' => $fingerprint, 'time' => $time];
    $key = setup_local_signing_key();
    if ($key === null) return;
    $payload = request_domain_binding() . ':' . $stage . ':' . $fingerprint . ':' . $time;
    $value = $time . '.' . $fingerprint . '.' . hash_hmac('sha256', 'setup-connection-test:' . $payload, $key);
    $name = setup_connection_test_cookie_name($stage);
    setcookie($name, $value, [
        'expires' => $time + SETUP_CONNECTION_TEST_TTL_SECONDS,
        'path' => '/',
        'secure' => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[$name] = $value;
}

function setup_connection_test_valid(array $input, string $stage): bool
{
    $fingerprint = setup_connection_fingerprint($input, $stage);
    $cookie = (string) ($_COOKIE[setup_connection_test_cookie_name($stage)] ?? '');
    if (preg_match('/^(\d+)\.([a-f0-9]{64})\.([a-f0-9]{64})$/D', $cookie, $matches) === 1) {
        $time = (int) $matches[1];
        $cookieFingerprint = $matches[2];
        $key = setup_local_signing_key();
        $payload = request_domain_binding() . ':' . $stage . ':' . $cookieFingerprint . ':' . $time;
        $expected = $key === null ? '' : hash_hmac('sha256', 'setup-connection-test:' . $payload, $key);
        if ($time > time() - SETUP_CONNECTION_TEST_TTL_SECONDS
            && hash_equals($fingerprint, $cookieFingerprint)
            && $expected !== ''
            && hash_equals($expected, $matches[3])) return true;
    }
    $record = $_SESSION['setup_connection_tests'][$stage] ?? [];
    return ($record['time'] ?? 0) > time() - SETUP_CONNECTION_TEST_TTL_SECONDS
        && hash_equals($fingerprint, (string) ($record['fingerprint'] ?? ''));
}

function setup_connection_test_cookie_name(string $stage): string
{
    return 'picapi_setup_test_' . preg_replace('/[^a-z0-9_-]/i', '', $stage) . '_' . substr(app_instance_id(), 0, 12)
        . '_' . substr(hash('sha256', request_domain_binding()), 0, 12);
}

function clear_setup_connection_test_cookie(string $stage): void
{
    $options = [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    $name = setup_connection_test_cookie_name($stage);
    setcookie($name, '', $options);
    unset($_COOKIE[$name]);
    $legacyName = 'picapi_setup_test_' . preg_replace('/[^a-z0-9_-]/i', '', $stage) . '_' . substr(app_instance_id(), 0, 12);
    if ($legacyName !== $name) {
        setcookie($legacyName, '', $options);
        unset($_COOKIE[$legacyName]);
    }
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
        if ($create && $created) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $database['name'])) {
                throw new InvalidArgumentException('本地自动创建数据库时，库名只能使用字母、数字和下划线，且不能以数字开头。');
            }
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $database['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
    } elseif ($database['type'] === 'pgsql') {
        $serverDsn = 'pgsql:host=' . $database['host'] . ($port === '' ? '' : ';port=' . $port) . ';dbname=postgres';
        $pdo = new PDO($serverDsn, $database['username'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $before = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $before->execute([$database['name']]);
        $created = $before->fetchColumn() === false;
        if ($create && $created) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $database['name'])) {
                throw new InvalidArgumentException('本地自动创建数据库时，库名只能使用字母、数字和下划线，且不能以数字开头。');
            }
            $pdo->exec('CREATE DATABASE "' . str_replace('"', '""', $database['name']) . '"');
        }
    } else {
        $serverDsn = 'sqlsrv:Server=' . $database['host'] . ($port === '' ? '' : ',' . $port);
        $pdo = new PDO($serverDsn, $database['username'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $before = $pdo->prepare('SELECT DB_ID(?)');
        $before->execute([$database['name']]);
        $created = $before->fetchColumn() === null;
        if ($create && $created) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $database['name'])) {
                throw new InvalidArgumentException('本地自动创建数据库时，库名只能使用字母、数字和下划线，且不能以数字开头。');
            }
            $pdo->exec('CREATE DATABASE [' . str_replace(']', ']]', $database['name']) . ']');
        }
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
    $domain = request_domain_binding();
    $sessionState = $_SESSION['setup_code_verified'] ?? null;
    if ($domain === 'unknown') {
        $sessionDomain = (string) ($_SESSION['_picapi_domain_binding'] ?? '');
        if ($sessionDomain !== '' && ($sessionState === true || (is_string($sessionState) && hash_equals($sessionDomain, $sessionState)))) return true;
        $key = setup_local_signing_key();
        if ($key === null) return false;
        $legacyDomains = [$sessionDomain];
        if (is_string($sessionState) && $sessionState !== '') $legacyDomains[] = $sessionState;
        $configuredAuthority = normalize_request_authority((string) (getenv('PICAPI_BASE_URL') ?: ''));
        if ($configuredAuthority !== null) $legacyDomains[] = $configuredAuthority;
        foreach (['HTTP_HOST', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_ORIGINAL_HOST'] as $header) {
            $candidate = normalize_request_authority(explode(',', (string) ($_SERVER[$header] ?? ''))[0]);
            if ($candidate !== null) $legacyDomains[] = $candidate;
        }
        $forwarded = explode(',', (string) ($_SERVER['HTTP_FORWARDED'] ?? ''))[0];
        if (preg_match('/(?:^|;)\s*host=(?:"([^"]+)"|([^;\s]+))/i', $forwarded, $forwardedMatches) === 1) {
            $candidate = normalize_request_authority((string) (($forwardedMatches[1] ?? '') !== '' ? $forwardedMatches[1] : ($forwardedMatches[2] ?? '')));
            if ($candidate !== null) $legacyDomains[] = $candidate;
        }
        foreach ($_COOKIE as $name => $legacyCookie) {
            if (!is_string($name) || !str_starts_with($name, 'picapi_setup_verified_') || !is_string($legacyCookie)) continue;
            if (preg_match('/^([a-f0-9]{64})\.([a-f0-9]{64})$/D', $legacyCookie, $legacyMatches) !== 1) continue;
            $oldSignatures = [hash_hmac('sha256', 'setup-code-verified:' . $legacyMatches[1], $key)];
            foreach (array_unique($legacyDomains) as $legacyDomain) {
                if ($legacyDomain !== '') $oldSignatures[] = hash_hmac('sha256', 'setup-code-verified:' . $legacyDomain . ':' . $legacyMatches[1], $key);
            }
            foreach ($oldSignatures as $signature) if (hash_equals($signature, $legacyMatches[2])) return true;
        }
        return false;
    }
    if ($sessionState === true) {
        // Migrate a verified state from the host-only PHP session.
        mark_setup_code_verified();
        return true;
    }
    if (is_string($sessionState) && $sessionState !== '' && hash_equals($domain, $sessionState)) return true;
    if ($sessionState !== null) unset($_SESSION['setup_code_verified']);
    $key = setup_local_signing_key();
    if ($key === null) return false;
    $cookie = (string) ($_COOKIE[setup_code_verified_cookie_name()] ?? '');
    if (preg_match('/^([a-f0-9]{64})\.([a-f0-9]{64})$/D', $cookie, $matches) === 1) {
        $expected = hash_hmac('sha256', 'setup-code-verified:' . $domain . ':' . $matches[1], $key);
        if (hash_equals($expected, $matches[2])) return true;
    }

    // Rebind proofs created by earlier releases. Host-only cookies are only
    // sent back to the authority that received them, and the new cookie is
    // signed for that authority before it is reused.
    $legacyName = 'picapi_setup_verified_' . substr(app_instance_id(), 0, 12);
    $legacyCookies = [$legacyName => (string) ($_COOKIE[$legacyName] ?? '')];
    foreach ($_COOKIE as $name => $legacyCookie) {
        if (is_string($name) && str_starts_with($name, 'picapi_setup_verified_') && is_string($legacyCookie)) $legacyCookies[$name] = $legacyCookie;
    }
    $legacyDomains = [$domain];
    if (is_string($sessionState) && $sessionState !== '') $legacyDomains[] = $sessionState;
    $configuredAuthority = normalize_request_authority((string) (getenv('PICAPI_BASE_URL') ?: ''));
    if ($configuredAuthority !== null) $legacyDomains[] = $configuredAuthority;
    foreach (['HTTP_HOST', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_ORIGINAL_HOST'] as $header) {
        $candidate = normalize_request_authority(explode(',', (string) ($_SERVER[$header] ?? ''))[0]);
        if ($candidate !== null) $legacyDomains[] = $candidate;
    }
    $forwarded = explode(',', (string) ($_SERVER['HTTP_FORWARDED'] ?? ''))[0];
    if (preg_match('/(?:^|;)\s*host=(?:"([^"]+)"|([^;\s]+))/i', $forwarded, $forwardedMatches) === 1) {
        $candidate = normalize_request_authority((string) (($forwardedMatches[1] ?? '') !== '' ? $forwardedMatches[1] : ($forwardedMatches[2] ?? '')));
        if ($candidate !== null) $legacyDomains[] = $candidate;
    }
    foreach ($legacyCookies as $legacyCookie) {
        if (preg_match('/^([a-f0-9]{64})\.([a-f0-9]{64})$/D', $legacyCookie, $legacyMatches) !== 1) continue;
        $oldSignatures = [hash_hmac('sha256', 'setup-code-verified:' . $legacyMatches[1], $key)];
        foreach (array_unique($legacyDomains) as $legacyDomain) {
            if ($legacyDomain !== '') $oldSignatures[] = hash_hmac('sha256', 'setup-code-verified:' . $legacyDomain . ':' . $legacyMatches[1], $key);
        }
        foreach ($oldSignatures as $signature) {
            if (hash_equals($signature, $legacyMatches[2])) {
                mark_setup_code_verified();
                return true;
            }
        }
    }
    return false;
}

function setup_code_verified_cookie_name(): string
{
    return 'picapi_setup_verified_' . substr(app_instance_id(), 0, 12)
        . '_' . substr(hash('sha256', request_domain_binding()), 0, 12);
}

function mark_setup_code_verified(): void
{
    $domain = request_domain_binding();
    if ($domain === 'unknown') {
        $_SESSION['setup_code_verified'] = (string) ($_SESSION['_picapi_domain_binding'] ?? 'unknown');
        return;
    }
    $_SESSION['setup_code_verified'] = $domain;
    $key = setup_local_signing_key();
    if ($key === null) return;
    $nonce = bin2hex(random_bytes(32));
    $value = $nonce . '.' . hash_hmac('sha256', 'setup-code-verified:' . $domain . ':' . $nonce, $key);
    setcookie(setup_code_verified_cookie_name(), $value, [
        'expires' => time() + 7 * 86400,
        'path' => '/',
        'secure' => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[setup_code_verified_cookie_name()] = $value;
}

function clear_setup_code_verified_cookie(): void
{
    $options = [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    $currentName = setup_code_verified_cookie_name();
    setcookie($currentName, '', $options);
    unset($_COOKIE[$currentName]);
    foreach ($_COOKIE as $name => $_value) {
        if (!is_string($name) || $name === $currentName || !str_starts_with($name, 'picapi_setup_verified_')) continue;
        setcookie($name, '', $options);
        unset($_COOKIE[$name]);
    }
}

function setup_code_matches(string $submitted): bool
{
    $path = setup_code_path();
    if (!is_file($path) || is_link($path)) return false;
    $expected = trim((string) @file_get_contents($path));
    return $expected !== '' && hash_equals(strtoupper($expected), strtoupper(trim($submitted)));
}

/** Validate once and bind success to a signed browser cookie through setup. */
function require_setup_code(array $input): void
{
    if (setup_code_is_verified()) return;
    if (!is_string($input['setup_code'] ?? null) || !setup_code_matches($input['setup_code'])) {
        throw new InvalidArgumentException('验证码错误，请检查项目根目录 setup.txt 中的内容。');
    }
    mark_setup_code_verified();
}

function require_setup_code_verified(): void
{
    if (!setup_code_is_verified()) {
        throw new InvalidArgumentException('请先输入并验证初始化验证码。');
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
