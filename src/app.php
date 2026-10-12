<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
const APP_VERSION = '1.0.0';
const PUBLIC_DIR = APP_ROOT . '/public';
// Runtime data remains inside the project for portable local deployments.
define('STORAGE_DIR', APP_ROOT . '/storage');
const LEGACY_STORAGE_DIR = APP_ROOT . '/database';
define('PREVIOUS_STORAGE_DIR', dirname(__DIR__, 2) . '/picapi-private');
const LOCAL_DATABASE_DIR = STORAGE_DIR . '/database';
const CACHE_DIR = STORAGE_DIR . '/cache';
const THUMBNAIL_DIR = CACHE_DIR . '/thumbnails';
const IMAGE_PC_DIR = PUBLIC_DIR . '/pc/img';
const IMAGE_MOBILE_DIR = PUBLIC_DIR . '/m/img';
// Keep uploads large enough for high-resolution images without allowing an
// authenticated request to consume unbounded disk space while it is staged.
const MAX_UPLOAD_BYTES = 50 * 1024 * 1024;
const MAX_UPLOAD_FILES_PER_REQUEST = 20;
const MAX_IMAGE_PIXELS = 50_000_000;
const MAX_CHUNK_BYTES = 2 * 1024 * 1024;
const MAX_CHUNK_COUNT = 32;
const CHUNK_UPLOAD_TTL_SECONDS = 24 * 60 * 60;
const LOGIN_MAX_LOCK_SECONDS = 5 * 60 * 60;
// Keep the final lock state briefly after it expires so a repeated failure can be escalated.
const LOGIN_FAILURE_RETENTION_SECONDS = LOGIN_MAX_LOCK_SECONDS + 60 * 60;
const PASSWORD_RESET_REQUEST_WINDOW_SECONDS = 15 * 60;
const PASSWORD_RESET_ACCOUNT_REQUEST_LIMIT = 3;
const PASSWORD_RESET_IP_REQUEST_LIMIT = 6;
const ADMIN_AVATAR_MAX_BYTES = 3 * 1024 * 1024;

/** @return never */
function abort_request(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function admin_database_unavailable_message(): string
{
    return '系统已存在安装记录，但管理员数据库当前无法连接。请先恢复数据库文件或检查数据库服务与连接配置。若这是已删除本地测试数据库的环境，只有在确认旧管理员、配置和会话均可丢弃后，才备份并移除 storage/installation.php，再访问 /admin/setup.php 重新初始化。生产环境不要删除该文件；系统不会在数据库故障时自动开放初始化页。';
}

function ensure_directory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException("Unable to create required directory: {$path}");
    }
}

/**
 * Runtime directories can be deleted and recreated by a deployment, backup,
 * or cleanup task. Recreate the Apache access guard with the directory rather
 * than relying on a one-time packaged .htaccess file.
 */
function ensure_private_directory_access_guard(string $directory): void
{
    $path = $directory . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_file($path)) return;

    $contents = "Options -Indexes\n\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
        @unlink($temporary);
        throw new RuntimeException("Unable to create directory access guard: {$directory}");
    }
    if (@rename($temporary, $path)) return;
    // Another concurrent request may have won the race to create the guard.
    if (is_file($path)) {
        @unlink($temporary);
        return;
    }
    @unlink($temporary);
    throw new RuntimeException("Unable to enable directory access guard: {$directory}");
}

function path_is_allowed_by_open_basedir(string $path): bool
{
    $openBasedir = trim((string) ini_get('open_basedir'));
    if ($openBasedir === '') return true;

    $normalize = static function (string $value): string {
        $resolved = @realpath($value);
        if (is_string($resolved)) $value = $resolved;
        $value = str_replace('\\', '/', trim($value));
        // Do not collapse the Unix filesystem root to an empty string. A
        // shared-hosting rule of open_basedir=/ permits every absolute path.
        if ($value === '/') return '/';
        return rtrim($value, '/');
    };
    $candidate = $normalize($path);
    foreach (explode(PATH_SEPARATOR, $openBasedir) as $allowed) {
        $allowed = $normalize(trim($allowed));
        if ($allowed === '') continue;
        if ($allowed === '/' && str_starts_with($candidate, '/')) return true;
        $matches = DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($candidate, $allowed, strlen($allowed)) === 0
            : strncmp($candidate, $allowed, strlen($allowed)) === 0;
        if ($matches && ($candidate === $allowed || str_starts_with($candidate, $allowed . '/'))) return true;
    }
    return false;
}

function migrate_runtime_directory(string $source, string $destination): void
{
    // Shared-hosting open_basedir policies can prohibit the legacy private
    // location. It is optional migration input, so skip it without warnings.
    if (!path_is_allowed_by_open_basedir($source) || !@is_dir($source) || realpath($source) === realpath($destination)) return;
    ensure_directory($destination);
    foreach (new DirectoryIterator($source) as $entry) {
        if ($entry->isDot()) continue;
        // Never follow symlinks while migrating private runtime data.
        if ($entry->isLink()) continue;
        $from = $entry->getPathname();
        $to = $destination . '/' . $entry->getFilename();
        if ($entry->isDir()) {
            if (!is_dir($to) && @rename($from, $to)) continue;
            migrate_runtime_directory($from, $to);
            @rmdir($from);
        } elseif (!file_exists($to)) {
            @rename($from, $to);
        }
    }
}

function initialize_storage(): void
{
    ensure_directory(STORAGE_DIR);
    ensure_private_directory_access_guard(STORAGE_DIR);
    migrate_runtime_directory(LEGACY_STORAGE_DIR, STORAGE_DIR);
    migrate_runtime_directory(PREVIOUS_STORAGE_DIR, STORAGE_DIR);
    ensure_directory(LOCAL_DATABASE_DIR);
    // Import the former flat SQLite location without overwriting new data.
    $oldDatabase = STORAGE_DIR . '/picapi.sqlite';
    $newDatabase = LOCAL_DATABASE_DIR . '/picapi.sqlite';
    if (is_file($oldDatabase) && !is_file($newDatabase) && !rename($oldDatabase, $newDatabase)) {
        throw new RuntimeException('Unable to migrate the local SQLite database.');
    }
    ensure_directory(THUMBNAIL_DIR);
    ensure_directory(STORAGE_DIR . '/logs');
    ensure_directory(STORAGE_DIR . '/logs/access');
    ensure_directory(STORAGE_DIR . '/logs/upload');
    ensure_directory(STORAGE_DIR . '/logs/login');
    // Keep endpoint counters and logs outside the web document root.
    ensure_directory(STORAGE_DIR . '/logs/endpoints/auto');
    ensure_directory(STORAGE_DIR . '/logs/endpoints/pc');
    ensure_directory(STORAGE_DIR . '/logs/endpoints/mobile');
    ensure_directory(IMAGE_PC_DIR);
    ensure_directory(IMAGE_MOBILE_DIR);
    ensure_category_endpoint('pc', 'default');
    ensure_category_endpoint('mobile', 'default');
    repair_migrated_sqlite_database_configuration();
    migrate_runtime_state_files();
}

function request_is_from_trusted_proxy(): bool
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $trusted = array_filter(array_map('trim', explode(',', (string) (getenv('PICAPI_TRUSTED_PROXIES') ?: ''))));
    return $trusted !== [] && in_array($remote, $trusted, true);
}

function request_is_https(): bool
{
    // Forwarded protocol headers are client-controlled unless the immediate
    // peer is an explicitly trusted reverse proxy.
    if (request_is_from_trusted_proxy()) {
        foreach (['HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_PROTOCOL', 'HTTP_X_URL_SCHEME'] as $header) {
            $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER[$header] ?? ''))[0]));
            if ($forwardedProto === 'https') {
                return true;
            }
        }
        $cloudflareVisitor = json_decode((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), true);
        if (is_array($cloudflareVisitor) && strtolower((string) ($cloudflareVisitor['scheme'] ?? '')) === 'https') {
            return true;
        }
        $forwardedSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? $_SERVER['HTTP_FRONT_END_HTTPS'] ?? '')));
        if (in_array($forwardedSsl, ['on', '1', 'https'], true)) {
            return true;
        }
    }
    if (strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https') {
        return true;
    }
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function request_is_loopback_client(): bool
{
    // Host is client-controlled. A loopback proxy also needs a valid client
    // address before its requests can qualify for the local-development exemption.
    if (request_is_from_trusted_proxy()) {
        $forwarded = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
        if (filter_var($forwarded, FILTER_VALIDATE_IP) === false) return false;
    }
    $ip = request_client_ip();
    return $ip === '::1'
        || (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($ip, '127.'));
}

function require_secure_admin_transport(): void
{
    if (request_is_https() || request_is_loopback_client()) return;

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/admin/login.php');
    if (in_array($method, ['GET', 'HEAD'], true) && preg_match('/^[a-z0-9.-]+(?::\d{1,5})?$/i', $host) === 1 && str_starts_with($uri, '/')) {
        header('Cache-Control: no-store, max-age=0');
        header('Location: https://' . $host . $uri, true, 302);
        exit;
    }

    abort_request(403, '后台登录仅允许通过 HTTPS 访问。请使用 https:// 地址后重试。');
}

/**
 * Applies the administrator HTTPS policy from Settings > Security.
 *
 * The requirement is deliberately opt-in and defaults to off, so deployments
 * without a certificate (local development, intranets, plain shared hosts)
 * keep working unchanged until the administrator enables it.
 */
function enforce_admin_transport_policy(): void
{
    try {
        require_once __DIR__ . '/settings_runtime.php';
        $settings = site_settings();
        if (!empty($settings['force_admin_https'])) {
            require_secure_admin_transport();
        }
    } catch (Throwable) {
        // Unreadable settings must not block admin pages; the previous
        // behavior (no forced transport) is kept in that rare case.
    }
}

function application_base_url(): string
{
    $configured = trim((string) (getenv('PICAPI_BASE_URL') ?: ''));
    if ($configured !== '') {
        if (request_is_https() && str_starts_with(strtolower($configured), 'http://')) {
            $configured = 'https://' . substr($configured, 7);
        }
        return rtrim($configured, '/');
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[a-z0-9.-]+(?::\d{1,5})?$/i', $host)) {
        return '';
    }
    return (request_is_https() ? 'https' : 'http') . '://' . $host;
}

function openssl_configuration_path(): ?string
{
    $candidates = [
        trim((string) (getenv('OPENSSL_CONF') ?: '')),
        dirname(PHP_BINARY) . '/openssl.cnf',
        dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
    ];
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate) && is_readable($candidate)) return $candidate;
    }
    return null;
}

/** @return array{private:OpenSSLAsymmetricKey|resource,public:string} */
function login_transport_key_pair(): array
{
    static $pair = null;
    if (is_array($pair)) return $pair;
    if (!function_exists('openssl_pkey_new')) throw new RuntimeException('服务器未启用 OpenSSL，无法安全处理登录凭据。');

    ensure_directory(STORAGE_DIR);
    $path = STORAGE_DIR . '/login-transport-private.pem';
    $load = static function () use ($path): ?array {
        $pem = is_file($path) ? @file_get_contents($path) : false;
        $private = is_string($pem) ? openssl_pkey_get_private($pem) : false;
        $details = $private !== false ? openssl_pkey_get_details($private) : false;
        return $private !== false && is_array($details) && isset($details['key'])
            ? ['private' => $private, 'public' => (string) $details['key']]
            : null;
    };
    $existing = $load();
    if ($existing !== null) return $pair = $existing;

    $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $opensslConfiguration = openssl_configuration_path();
    if ($opensslConfiguration !== null) $options['config'] = $opensslConfiguration;
    $private = openssl_pkey_new($options);
    if ($private === false || !openssl_pkey_export($private, $privatePem, null, $options)) {
        throw new RuntimeException('无法生成登录传输密钥。');
    }
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, $privatePem, LOCK_EX) === false) {
        @unlink($temporary);
        throw new RuntimeException('无法保存登录传输密钥。');
    }
    @chmod($temporary, 0600);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        $existing = $load();
        if ($existing !== null) return $pair = $existing;
        throw new RuntimeException('无法启用登录传输密钥。');
    }
    @chmod($path, 0600);
    $details = openssl_pkey_get_details($private);
    if (!is_array($details) || !isset($details['key'])) throw new RuntimeException('无法读取登录传输公钥。');
    return $pair = ['private' => $private, 'public' => (string) $details['key']];
}

function login_transport_public_key(): string
{
    return login_transport_key_pair()['public'];
}

/**
 * Digest used for the login RSA-OAEP envelope.
 *
 * PHP 8.5+ can decrypt SHA-256 OAEP padding; older releases fall back to
 * SHA-1 (the previous default). The browser is told which algorithm to use
 * on every login page render so both versions keep working end to end.
 */
function login_transport_digest_algo(): string
{
    return PHP_VERSION_ID >= 80500 ? 'sha256' : 'sha1';
}

/** @return array{username:string,password:string}|null */
function decrypt_login_transport_credentials(string $envelope): ?array
{
    if ($envelope === '' || strlen($envelope) > 20_000) return null;
    $data = json_decode($envelope, true);
    if (!is_array($data)) return null;
    $encryptedKey = base64_decode((string) ($data['key'] ?? ''), true);
    $iv = base64_decode((string) ($data['iv'] ?? ''), true);
    $ciphertext = base64_decode((string) ($data['ciphertext'] ?? ''), true);
    $tag = base64_decode((string) ($data['tag'] ?? ''), true);
    if (!is_string($encryptedKey) || !is_string($iv) || !is_string($ciphertext) || !is_string($tag) || strlen($iv) !== 12 || strlen($tag) !== 16) return null;

    $contentKey = '';
    $key = login_transport_key_pair()['private'];
    $decrypted = PHP_VERSION_ID >= 80500
        ? openssl_private_decrypt($encryptedKey, $contentKey, $key, OPENSSL_PKCS1_OAEP_PADDING, login_transport_digest_algo())
        : openssl_private_decrypt($encryptedKey, $contentKey, $key, OPENSSL_PKCS1_OAEP_PADDING);
    if (!$decrypted || strlen($contentKey) !== 32) return null;
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $contentKey, OPENSSL_RAW_DATA, $iv, $tag);
    $credentials = is_string($plaintext) ? json_decode($plaintext, true) : null;
    if (!is_array($credentials) || !is_string($credentials['username'] ?? null) || !is_string($credentials['password'] ?? null)) return null;
    if (strlen($credentials['username']) > 255 || strlen($credentials['password']) > 4096) return null;
    return ['username' => $credentials['username'], 'password' => $credentials['password']];
}

function request_client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN');
    if (request_is_from_trusted_proxy()) {
        $forwarded = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        $candidate = trim($forwarded[0] ?? '');
        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) return $candidate;
    }
    return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : 'UNKNOWN';
}

function public_ip_location_label(string $ip): ?string
{
    static $resolved = [];
    if (array_key_exists($ip, $resolved)) return $resolved[$ip];

    $url = 'https://ipwho.is/' . rawurlencode($ip) . '?lang=zh-CN';
    $payload = null;
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle !== false) {
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'PicAPI-IP-Location/1.0',
            ]);
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);
            if (is_string($response) && $status === 200) $payload = $response;
        }
    } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => 3,
            'follow_location' => 0,
            'user_agent' => 'PicAPI-IP-Location/1.0',
        ]]);
        $response = @file_get_contents($url, false, $context);
        if (is_string($response)) $payload = $response;
    }

    $data = is_string($payload) ? json_decode($payload, true) : null;
    if (!is_array($data) || empty($data['success'])) return $resolved[$ip] = null;
    $parts = [];
    foreach (['country', 'region', 'city'] as $key) {
        $part = trim((string) ($data[$key] ?? ''));
        if ($part !== '' && !in_array($part, $parts, true)) $parts[] = $part;
    }
    return $resolved[$ip] = $parts === [] ? null : implode(' · ', $parts);
}

function request_client_location_label(string $ip): string
{
    if ($ip === 'UNKNOWN') return '未知位置';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return '本地或保留网络地址';
    }
    $location = public_ip_location_label($ip);
    if ($location !== null) return $location;
    // Country headers are only accepted from an explicitly trusted proxy.
    // Precise city resolution intentionally requires a separately deployed
    // IP geolocation service rather than an untrusted public lookup request.
    if (request_is_from_trusted_proxy()) {
        $country = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $country) === 1 && $country !== 'XX') {
            return '国家/地区代码：' . $country;
        }
    }
    return '公网 IP（未配置精确地理定位）';
}

function request_client_device_label(): string
{
    $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($userAgent === '') return '未知设备';
    $browser = preg_match('/Edg\//i', $userAgent) ? 'Microsoft Edge'
        : (preg_match('/Firefox\//i', $userAgent) ? 'Firefox'
        : (preg_match('/Chrome\//i', $userAgent) ? 'Chrome'
        : (preg_match('/Safari\//i', $userAgent) ? 'Safari' : '未知浏览器')));
    $platform = preg_match('/Windows NT/i', $userAgent) ? 'Windows'
        : (preg_match('/Android/i', $userAgent) ? 'Android'
        : (preg_match('/iPhone|iPad|iPod/i', $userAgent) ? 'iOS'
        : (preg_match('/Mac OS X/i', $userAgent) ? 'macOS'
        : (preg_match('/Linux/i', $userAgent) ? 'Linux' : '未知系统'))));
    return $browser . ' · ' . $platform;
}

function admin_session_ttl_seconds(): int
{
    require_once __DIR__ . '/settings_runtime.php';
    return (int) site_settings()['admin_session_days'] * 86400;
}

function app_instance_id(): string
{
    static $instanceId = null;
    if ($instanceId === null) {
        $root = realpath(APP_ROOT);
        $instanceId = hash('sha256', $root !== false ? $root : APP_ROOT);
    }
    return $instanceId;
}

function admin_session_cookie_name(): string
{
    // Browser cookies are not scoped by TCP port. Isolate every installed
    // checkout so sibling PicAPI instances on localhost cannot replace the
    // session cookie that carries this installation's CSRF token.
    return 'picapi_admin_' . substr(app_instance_id(), 0, 16);
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Keep application sessions separate from other sites' shorter GC policies.
    if (ini_get('session.save_handler') === 'files') {
        $directory = STORAGE_DIR . '/sessions';
        ensure_directory($directory);
        ensure_private_directory_access_guard($directory);
        session_save_path($directory);
    }
    $lifetime = admin_session_ttl_seconds();
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.cookie_lifetime', (string) $lifetime);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_name(admin_session_cookie_name());
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'httponly' => true,
        'secure' => request_is_https(),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function refresh_admin_session_cookie(): void
{
    if (headers_sent() || session_status() !== PHP_SESSION_ACTIVE) return;
    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => time() + admin_session_ttl_seconds(),
        'path' => (string) ($params['path'] ?? '/'),
        'domain' => (string) ($params['domain'] ?? ''),
        'secure' => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function send_admin_security_headers(): void
{
    // Keep every administrative response out of search indexes, including
    // non-HTML responses where a meta robots tag cannot be used.
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; object-src 'none'; img-src 'self' data: blob:; connect-src 'self' https://cdn.jsdelivr.net https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://unpkg.com https://static.cloudflareinsights.com");
}

function login_throttle_path(): string
{
    $directory = STORAGE_DIR . '/security';
    ensure_directory($directory);
    return $directory . '/login-attempts.json';
}

/** Atomically replaces a JSON state file (temporary file + rename). */
function write_json_atomic(string $path, array $data): bool
{
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false || file_put_contents($temporary, $encoded, LOCK_EX) === false) {
        @unlink($temporary);
        return false;
    }
    return @rename($temporary, $path);
}

function login_throttle_key(): string
{
    return hash('sha256', request_client_ip());
}

/** @param callable(array<string,mixed>&): mixed $callback */
function with_login_throttle(callable $callback): mixed
{
    $path = login_throttle_path();
    $legacy = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    $legacy = is_array($legacy) ? $legacy : [];
    if (runtime_state_database() !== null) {
        return with_database_runtime_state('login-throttle', $legacy, $callback);
    }
    $lock = fopen($path . '.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Unable to access login protection state.');
    }
    try {
        // Re-read under the lock. A snapshot taken before flock would let two
        // concurrent requests overwrite each other's increments, diluting
        // brute-force throttling and IP auto-bans.
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $data = is_array($data) ? $data : [];
        $result = $callback($data);
        if (!write_json_atomic($path, $data)) {
            throw new RuntimeException('Unable to persist login protection state.');
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function login_retry_after(): int
{
    require_once __DIR__ . '/security_runtime.php';
    if (security_policy_active()) {
        $status = security_status(request_client_ip(), 'login');
        return $status['permanent'] ? 86400 : max(0, $status['until'] - time());
    }
    return with_login_throttle(static function (array &$data): int {
        $key = login_throttle_key();
        $lastFailure = (int) ($data[$key]['last_failure_at'] ?? 0);
        if ($lastFailure < time() - LOGIN_FAILURE_RETENTION_SECONDS) {
            unset($data[$key]);
            return 0;
        }
        $until = (int) ($data[$key]['locked_until'] ?? 0);
        return max(0, $until - time());
    });
}

/** @return array{failures:int,lock_seconds:int,max_lock_expired:bool,ip:string} */
function record_login_failure(): array
{
    require_once __DIR__ . '/security_runtime.php';
    if (security_policy_active()) {
        $status = security_status(request_client_ip(), 'login', true);
        return ['failures' => $status['count'], 'lock_seconds' => $status['permanent'] ? 86400 : max(0, $status['until'] - time()), 'max_lock_expired' => false, 'ip' => request_client_ip()];
    }
    return with_login_throttle(static function (array &$data): array {
        $key = login_throttle_key();
        $now = time();
        $previous = is_array($data[$key] ?? null) ? $data[$key] : [];
        $previousLockedUntil = (int) ($previous['locked_until'] ?? 0);
        $previousLockSeconds = (int) ($previous['lock_seconds'] ?? max(0, $previousLockedUntil - (int) ($previous['last_failure_at'] ?? 0)));
        $maxLockExpired = $previousLockSeconds >= LOGIN_MAX_LOCK_SECONDS && $previousLockedUntil > 0 && $previousLockedUntil <= $now;
        $failures = (int) ($previous['last_failure_at'] ?? 0) >= $now - LOGIN_FAILURE_RETENTION_SECONDS
            ? (int) ($previous['failures'] ?? 0) + 1
            : 1;
        $lockSeconds = $failures < 3 ? 0 : ($failures === 3 ? 60 : min(($failures - 3) * 600, LOGIN_MAX_LOCK_SECONDS));
        $data[$key] = ['failures' => $failures, 'last_failure_at' => $now, 'lock_seconds' => $lockSeconds, 'locked_until' => $lockSeconds > 0 ? $now + $lockSeconds : 0];
        return ['failures' => $failures, 'lock_seconds' => $lockSeconds, 'max_lock_expired' => $maxLockExpired, 'ip' => request_client_ip()];
    });
}

function clear_login_failures(): void
{
    require_once __DIR__ . '/security_runtime.php';
    if (security_policy_active()) { security_login_success(request_client_ip()); return; }
    with_login_throttle(static function (array &$data): void {
        unset($data[login_throttle_key()]);
    });
}

function password_reset_throttle_path(): string
{
    $directory = STORAGE_DIR . '/security';
    ensure_directory($directory);
    return $directory . '/password-reset-attempts.json';
}

/** @param callable(array<string,mixed>&): mixed $callback */
function with_password_reset_throttle(callable $callback): mixed
{
    $path = password_reset_throttle_path();
    $legacy = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    $legacy = is_array($legacy) ? $legacy : [];
    if (runtime_state_database() !== null) {
        return with_database_runtime_state('password-reset-throttle', $legacy, $callback);
    }

    $lock = fopen($path . '.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Unable to access password-reset protection state.');
    }
    try {
        // Re-read under the lock so concurrent requests cannot overwrite each
        // other's counters from a stale pre-lock snapshot.
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $data = is_array($data) ? $data : [];
        $result = $callback($data);
        if (!write_json_atomic($path, $data)) {
            throw new RuntimeException('Unable to persist password-reset protection state.');
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Atomically reserves one password-reset email slot. Both the source IP and
 * the administrator account are capped, so a new browser session cannot be
 * used to bypass the reset-email cooldown.
 */
function claim_password_reset_request(string $username): int
{
    return with_password_reset_throttle(static function (array &$data) use ($username): int {
        $now = time();
        $windowStart = $now - PASSWORD_RESET_REQUEST_WINDOW_SECONDS;
        $data['events'] = is_array($data['events'] ?? null) ? $data['events'] : [];

        $ipKey = 'ip:' . hash('sha256', request_client_ip());
        $accountKey = 'account:' . hash('sha256', strtolower(trim($username)));
        $eventsFor = static function (string $key) use (&$data, $windowStart): array {
            $events = array_values(array_filter((array) ($data['events'][$key] ?? []), static fn(mixed $value): bool => is_int($value) && $value > $windowStart));
            $data['events'][$key] = $events;
            return $events;
        };
        $ipEvents = $eventsFor($ipKey);
        $accountEvents = $eventsFor($accountKey);

        $retryAfter = 0;
        if (count($ipEvents) >= PASSWORD_RESET_IP_REQUEST_LIMIT) {
            $retryAfter = max($retryAfter, ((int) $ipEvents[0] + PASSWORD_RESET_REQUEST_WINDOW_SECONDS) - $now);
        }
        if (count($accountEvents) >= PASSWORD_RESET_ACCOUNT_REQUEST_LIMIT) {
            $retryAfter = max($retryAfter, ((int) $accountEvents[0] + PASSWORD_RESET_REQUEST_WINDOW_SECONDS) - $now);
        }
        if ($retryAfter > 0) return $retryAfter;

        $data['events'][$ipKey][] = $now;
        $data['events'][$accountKey][] = $now;
        return 0;
    });
}

function admin_config_path(): string
{
    return STORAGE_DIR . '/admin.php';
}

/** @return array{username:string,password_hash:string}|null */
function load_admin_config(): ?array
{
    $path = admin_config_path();
    if (!is_file($path)) {
        return null;
    }
    $config = require $path;
    if (!is_array($config) || !isset($config['username'], $config['password_hash'])) {
        return null;
    }
    return $config;
}

/** @return array<string,mixed>|null */
function installation_config(): ?array
{
    $path = STORAGE_DIR . '/installation.php';
    if (!is_file($path)) return null;
    $config = require $path;
    return is_array($config) ? $config : null;
}

/**
 * Returns the server-side generation bound to administrator sessions.
 *
 * A fresh value is written during every installation.  It is deliberately
 * stored outside the browser, so a session cookie issued by an earlier
 * installation cannot become valid again after the system is initialized.
 */
function installation_session_generation(): string
{
    $config = installation_config() ?? [];
    $database = is_array($config['database'] ?? null) ? $config['database'] : [];
    $generation = $config['session_generation'] ?? null;
    if (is_string($generation) && preg_match('/^[a-f0-9]{64}$/D', $generation) === 1) {
        return $generation;
    }

    // Compatibility for installations created before session_generation was
    // introduced. Their existing sessions have no matching field and are
    // therefore invalidated once; subsequent initializations use randomness.
    return hash('sha256', serialize([
        $config['created_at'] ?? '',
        $config['admin_email'] ?? '',
        $database['dsn'] ?? '',
    ]));
}

/**
 * Revokes every current administrator session. This is used after a password
 * change and does not reuse a value from the prior installation or session.
 */
function rotate_admin_session_generation(): void
{
    $config = installation_config();
    if (!is_array($config)) {
        throw new RuntimeException('Installation configuration is unavailable.');
    }

    $config['session_generation'] = bin2hex(random_bytes(32));
    $path = STORAGE_DIR . '/installation.php';
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $contents = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to rotate administrator session credentials.');
    }
    // PHP-FPM / OPcache must see the new generation before issuing a new session.
    if (function_exists('opcache_invalidate')) opcache_invalidate($path, true);
    clearstatcache(true, $path);
}

/**
 * Keeps older installations portable after their private runtime folder was
 * migrated into the project. The setup wizard only creates SQLite databases
 * under /database, so a legacy SQLite DSN must not keep pointing at the
 * removed picapi-private or storage directory.
 */
function repair_migrated_sqlite_database_configuration(): void
{
    $config = installation_config();
    $database = is_array($config['database'] ?? null) ? $config['database'] : null;
    if ($database === null || ($database['type'] ?? null) !== 'sqlite') return;

    $localPath = LOCAL_DATABASE_DIR . '/picapi.sqlite';
    $localDsn = 'sqlite:' . $localPath;
    if (($database['dsn'] ?? null) === $localDsn || !is_file($localPath)) return;

    try {
        $statement = (new PDO($localDsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))
            ->query('SELECT password_hash FROM picapi_admin_users WHERE password_hash IS NOT NULL AND TRIM(password_hash) <> "" LIMIT 1');
        if ($statement->fetchColumn() === false) return;
    } catch (Throwable) {
        return;
    }

    $config['database']['dsn'] = $localDsn;
    $config['database']['name'] = 'picapi.sqlite';
    $config['database']['host'] = '';
    $config['database']['port'] = '';
    $config['database']['username'] = '';
    $config['database']['password'] = '';
    $path = STORAGE_DIR . '/installation.php';
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $contents = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
    if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
    }
}

function admin_database_connection(): PDO
{
    $config = installation_config();
    $database = is_array($config['database'] ?? null) ? $config['database'] : null;
    if ($database === null || !is_string($database['dsn'] ?? null)) throw new RuntimeException('Database configuration is unavailable.');
    return new PDO($database['dsn'], (string) ($database['username'] ?? ''), (string) ($database['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/**
 * A deleted local SQLite test database is recreated by PDO as an empty file.
 * Permit setup only when that file has no administrator table at all; a remote
 * database outage or an existing but empty administrator table stays blocked.
 */
function installation_sqlite_admin_table_missing(): bool
{
    $installation = installation_config();
    $database = is_array($installation['database'] ?? null) ? $installation['database'] : [];
    if (($database['type'] ?? '') !== 'sqlite' || !str_starts_with((string) ($database['dsn'] ?? ''), 'sqlite:')) return false;
    try {
        $pdo = admin_database_connection();
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table");
        $statement->execute(['table' => 'picapi_admin_users']);
        return $statement->fetchColumn() === false;
    } catch (Throwable) {
        return false;
    }
}

function ensure_application_database_schema(PDO $pdo): void
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlsrv') {
        $statements = [
            "IF OBJECT_ID('picapi_runtime_states', 'U') IS NULL CREATE TABLE picapi_runtime_states (state_key NVARCHAR(120) PRIMARY KEY, payload NVARCHAR(MAX) NOT NULL, updated_at NVARCHAR(40) NOT NULL)",
            "IF OBJECT_ID('picapi_config_documents', 'U') IS NULL CREATE TABLE picapi_config_documents (document_key NVARCHAR(80) PRIMARY KEY, payload NVARCHAR(MAX) NOT NULL, updated_at NVARCHAR(40) NOT NULL)",
            "IF OBJECT_ID('picapi_categories', 'U') IS NULL CREATE TABLE picapi_categories (category_alias NVARCHAR(50) PRIMARY KEY, display_name NVARCHAR(120) NOT NULL, created_at NVARCHAR(40) NOT NULL, sort_order INT NOT NULL DEFAULT 0)",
            "IF OBJECT_ID('picapi_request_logs', 'U') IS NULL CREATE TABLE picapi_request_logs (record_id NVARCHAR(64) PRIMARY KEY, logged_at NVARCHAR(40) NOT NULL, log_day NVARCHAR(10) NOT NULL, ip_address NVARCHAR(64) NOT NULL, image_path NVARCHAR(MAX) NOT NULL, status_code INT NOT NULL, request_method NVARCHAR(16) NOT NULL, output_type NVARCHAR(16) NOT NULL, output_bytes BIGINT NOT NULL, device NVARCHAR(16) NOT NULL, channel NVARCHAR(16) NOT NULL)",
            "IF OBJECT_ID('picapi_upload_audits', 'U') IS NULL CREATE TABLE picapi_upload_audits (record_id NVARCHAR(64) PRIMARY KEY, logged_at NVARCHAR(40) NOT NULL, log_day NVARCHAR(10) NOT NULL, file_name NVARCHAR(512) NOT NULL, relative_path NVARCHAR(MAX) NOT NULL, category_alias NVARCHAR(80) NOT NULL, device NVARCHAR(16) NOT NULL, width INT NOT NULL, height INT NOT NULL, file_size BIGINT NOT NULL, source NVARCHAR(32) NOT NULL)",
            "IF OBJECT_ID('picapi_daily_statistics', 'U') IS NULL CREATE TABLE picapi_daily_statistics (stat_day NVARCHAR(10) NOT NULL, channel NVARCHAR(16) NOT NULL, request_count BIGINT NOT NULL DEFAULT 0, output_bytes BIGINT NOT NULL DEFAULT 0, PRIMARY KEY (stat_day, channel))",
        ];
    } else {
        $statements = [
            'CREATE TABLE IF NOT EXISTS picapi_runtime_states (state_key VARCHAR(120) PRIMARY KEY, payload TEXT NOT NULL, updated_at VARCHAR(40) NOT NULL)',
            'CREATE TABLE IF NOT EXISTS picapi_config_documents (document_key VARCHAR(80) PRIMARY KEY, payload TEXT NOT NULL, updated_at VARCHAR(40) NOT NULL)',
            'CREATE TABLE IF NOT EXISTS picapi_categories (category_alias VARCHAR(50) PRIMARY KEY, display_name VARCHAR(120) NOT NULL, created_at VARCHAR(40) NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0)',
            'CREATE TABLE IF NOT EXISTS picapi_request_logs (record_id VARCHAR(64) PRIMARY KEY, logged_at VARCHAR(40) NOT NULL, log_day VARCHAR(10) NOT NULL, ip_address VARCHAR(64) NOT NULL, image_path TEXT NOT NULL, status_code INTEGER NOT NULL, request_method VARCHAR(16) NOT NULL, output_type VARCHAR(16) NOT NULL, output_bytes BIGINT NOT NULL, device VARCHAR(16) NOT NULL, channel VARCHAR(16) NOT NULL)',
            'CREATE TABLE IF NOT EXISTS picapi_upload_audits (record_id VARCHAR(64) PRIMARY KEY, logged_at VARCHAR(40) NOT NULL, log_day VARCHAR(10) NOT NULL, file_name VARCHAR(512) NOT NULL, relative_path TEXT NOT NULL, category_alias VARCHAR(80) NOT NULL, device VARCHAR(16) NOT NULL, width INTEGER NOT NULL, height INTEGER NOT NULL, file_size BIGINT NOT NULL, source VARCHAR(32) NOT NULL)',
            'CREATE TABLE IF NOT EXISTS picapi_daily_statistics (stat_day VARCHAR(10) NOT NULL, channel VARCHAR(16) NOT NULL, request_count BIGINT NOT NULL DEFAULT 0, output_bytes BIGINT NOT NULL DEFAULT 0, PRIMARY KEY (stat_day, channel))',
        ];
    }
    foreach ($statements as $statement) $pdo->exec($statement);
    ensure_database_index($pdo, 'picapi_request_logs', 'logged_at', 'picapi_request_logs_time_idx');
    ensure_database_index($pdo, 'picapi_request_logs', 'log_day', 'picapi_request_logs_day_idx');
    ensure_database_index($pdo, 'picapi_upload_audits', 'logged_at', 'picapi_upload_audits_time_idx');
    ensure_database_index($pdo, 'picapi_upload_audits', 'log_day', 'picapi_upload_audits_day_idx');
}

function ensure_database_index(PDO $pdo, string $table, string $column, string $name): void
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
        $check->execute([$table, $name]);
        if ((int) $check->fetchColumn() > 0) return;
        $pdo->exec("CREATE INDEX {$name} ON {$table} ({$column})");
        return;
    }
    if ($driver === 'sqlsrv') {
        $pdo->exec("IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = '{$name}' AND object_id = OBJECT_ID('{$table}')) CREATE INDEX {$name} ON {$table} ({$column})");
        return;
    }
    $pdo->exec("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$column})");
}

/** @return array<string,mixed>|null */
function database_document(string $key): ?array
{
    try {
        $pdo = runtime_state_database();
        if ($pdo === null) return null;
        $statement = $pdo->prepare('SELECT payload FROM picapi_config_documents WHERE document_key = :key');
        $statement->execute(['key' => $key]);
        $payload = $statement->fetchColumn();
        $decoded = is_string($payload) ? json_decode($payload, true) : null;
        return is_array($decoded) ? $decoded : null;
    } catch (Throwable) {
        return null;
    }
}

/** @param array<string,mixed> $payload */
function save_database_document(string $key, array $payload): bool
{
    try {
        $pdo = runtime_state_database();
        if ($pdo === null) return false;
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $exists = $pdo->prepare('SELECT 1 FROM picapi_config_documents WHERE document_key = :key');
        $exists->execute(['key' => $key]);
        if ($exists->fetchColumn() !== false) {
            $statement = $pdo->prepare('UPDATE picapi_config_documents SET payload = :payload, updated_at = :updated_at WHERE document_key = :key');
        } else {
            $statement = $pdo->prepare('INSERT INTO picapi_config_documents (document_key, payload, updated_at) VALUES (:key, :payload, :updated_at)');
        }
        $statement->execute(['key' => $key, 'payload' => $encoded, 'updated_at' => gmdate('c')]);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function runtime_state_database(): ?PDO
{
    static $attempted = false;
    static $connection = null;
    if ($attempted) return $connection;
    $attempted = true;
    try {
        $connection = admin_database_connection();
        $driver = (string) $connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        ensure_application_database_schema($connection);
    } catch (Throwable) {
        $connection = null;
    }
    return $connection;
}

/** @param callable(array<string,mixed>&): mixed $callback */
function with_database_runtime_state(string $key, array $initial, callable $callback): mixed
{
    $pdo = runtime_state_database();
    if ($pdo === null) return null;
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $attempt = 0;
    while (true) {
        try {
            $pdo->beginTransaction();
            $query = 'SELECT payload FROM picapi_runtime_states WHERE state_key = :key';
            if (in_array($driver, ['mysql', 'pgsql'], true)) $query .= ' FOR UPDATE';
            if ($driver === 'sqlsrv') $query = 'SELECT payload FROM picapi_runtime_states WITH (UPDLOCK, HOLDLOCK) WHERE state_key = :key';
            $statement = $pdo->prepare($query);
            $statement->execute(['key' => $key]);
            $stored = $statement->fetchColumn();
            $stateExists = $stored !== false;
            $state = is_string($stored) ? json_decode($stored, true) : $initial;
            $state = is_array($state) ? $state : $initial;
            $result = $callback($state);
            $payload = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($stateExists) {
                $updated = $pdo->prepare('UPDATE picapi_runtime_states SET payload = :payload, updated_at = :updated_at WHERE state_key = :key');
                $updated->execute(['payload' => $payload, 'updated_at' => gmdate('c'), 'key' => $key]);
            } else {
                $insert = $pdo->prepare('INSERT INTO picapi_runtime_states (state_key, payload, updated_at) VALUES (:key, :payload, :updated_at)');
                $insert->execute(['key' => $key, 'payload' => $payload, 'updated_at' => gmdate('c')]);
            }
            $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (++$attempt >= 3) throw $error;
            usleep(20_000 * $attempt);
        }
    }
}

function migrate_runtime_state_files(): void
{
    static $complete = false;
    if ($complete) return;
    $pdo = runtime_state_database();
    if ($pdo === null) return;
    $complete = true;
    $sources = [
        'category-metadata' => STORAGE_DIR . '/categories.json',
        'login-throttle' => STORAGE_DIR . '/security/login-attempts.json',
        'ip-access-control' => STORAGE_DIR . '/security/ip-access-control.json',
        'request-rate-alerts' => STORAGE_DIR . '/security/request-rate-alerts.json',
        'request-counters' => STORAGE_DIR . '/counters.json',
        'api-sequence' => STORAGE_DIR . '/api_sequence.json',
    ];
    $exists = $pdo->prepare('SELECT 1 FROM picapi_runtime_states WHERE state_key = :key');
    foreach ($sources as $key => $path) {
        try {
            $exists->execute(['key' => $key]);
            if ($exists->fetchColumn() !== false) continue;
            $legacy = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
            $legacy = is_array($legacy) ? $legacy : [];
            with_database_runtime_state($key, $legacy, static function (array &$state): void {
                // The initial payload is written by the database state helper.
            });
        } catch (Throwable) {
            // JSON remains available as a compatibility fallback if migration cannot complete.
        }
    }
}

function resolve_admin_login(string $identity, string $password): ?string
{
    try {
        $statement = admin_database_connection()->prepare('SELECT username, password_hash FROM picapi_admin_users WHERE username = :username OR email = :email');
        $statement->execute(['username' => $identity, 'email' => $identity]);
        $admin = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($admin) || !is_string($admin['password_hash'] ?? null)) {
            // Keep an unknown account close to the cost of a wrong password,
            // reducing username/email discovery through response timing.
            password_verify($password, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            return null;
        }
        return password_verify($password, $admin['password_hash']) ? (string) $admin['username'] : null;
    } catch (Throwable) {
        $legacy = load_admin_config();
        if ($legacy === null || !password_verify($password, $legacy['password_hash'])) return null;
        $email = (string) (installation_config()['admin_email'] ?? '');
        return (hash_equals($legacy['username'], $identity) || ($email !== '' && hash_equals($email, $identity))) ? $legacy['username'] : null;
    }
}

/** @return array{username:string,email:string}|null */
function admin_account_by_identity(string $identity): ?array
{
    try {
        $statement = admin_database_connection()->prepare('SELECT username, email FROM picapi_admin_users WHERE username = :username OR email = :email');
        $statement->execute(['username' => $identity, 'email' => $identity]);
        $account = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account) || !is_string($account['username'] ?? null) || !is_string($account['email'] ?? null)) return null;
        return ['username' => $account['username'], 'email' => $account['email']];
    } catch (Throwable) {
        return null;
    }
}

function update_admin_password(string $username, string $password): bool
{
    if (strlen($password) < 8) return false;
    try {
        $statement = admin_database_connection()->prepare('UPDATE picapi_admin_users SET password_hash = :password_hash WHERE username = :username');
        $statement->execute(['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'username' => $username]);
        return $statement->rowCount() === 1;
    } catch (Throwable) {
        return false;
    }
}

function admin_password_matches(string $username, string $password): bool
{
    try {
        $statement = admin_database_connection()->prepare('SELECT password_hash FROM picapi_admin_users WHERE username = :username');
        $statement->execute(['username' => $username]);
        $hash = $statement->fetchColumn();
        return is_string($hash) && password_verify($password, $hash);
    } catch (Throwable) {
        return false;
    }
}

function update_admin_profile(string $currentUsername, string $newUsername, string $newEmail): void
{
    if (!preg_match('/^[\p{Han}a-zA-Z0-9._-]{2,48}$/u', $newUsername)
        || filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Invalid administrator profile.');
    }

    $pdo = admin_database_connection();
    $existing = $pdo->prepare('SELECT username FROM picapi_admin_users WHERE (username = :username OR email = :email) AND username <> :current');
    $existing->execute(['username' => $newUsername, 'email' => $newEmail, 'current' => $currentUsername]);
    if ($existing->fetchColumn() !== false) {
        throw new RuntimeException('The username or email address is already in use.');
    }

    $statement = $pdo->prepare('UPDATE picapi_admin_users SET username = :new_username, email = :new_email WHERE username = :current_username');
    $statement->execute(['new_username' => $newUsername, 'new_email' => $newEmail, 'current_username' => $currentUsername]);
    if ($statement->rowCount() !== 1) {
        $check = $pdo->prepare('SELECT 1 FROM picapi_admin_users WHERE username = :username');
        $check->execute(['username' => $newUsername]);
        if ($check->fetchColumn() === false) throw new RuntimeException('Unable to update the administrator profile.');
    }
}

function verify_admin_credentials(string $identity, string $password): bool
{
    return resolve_admin_login($identity, $password) !== null;
}

function admin_is_configured(): bool
{
    try {
        $statement = admin_database_connection()->query('SELECT password_hash FROM picapi_admin_users');
        while (($hash = $statement->fetchColumn()) !== false) {
            if (is_string($hash) && trim($hash) !== '') return true;
        }
        return false;
    } catch (Throwable) {
        return false;
    }
}

function invalidate_admin_session(): void
{
    start_secure_session();
    $_SESSION = [];

    // Give the visitor a new, empty session immediately. This prevents a
    // rejected session identifier from continuing to carry any old values on
    // the login form or on an AJAX response that follows this check.
    if (!headers_sent()) {
        session_regenerate_id(true);
        refresh_admin_session_cookie();
    }
}

function begin_admin_session(string $username): void
{
    start_secure_session();
    session_regenerate_id(true);
    $_SESSION = [
        'admin' => $username,
        'admin_last_seen_at' => time(),
        'admin_generation' => installation_session_generation(),
    ];
    refresh_admin_session_cookie();
}

function current_admin(): ?string
{
    start_secure_session();
    if (!isset($_SESSION['admin']) || !is_string($_SESSION['admin'])) return null;

    $sessionGeneration = (string) ($_SESSION['admin_generation'] ?? '');
    if ($sessionGeneration === '' || !hash_equals(installation_session_generation(), $sessionGeneration)) {
        invalidate_admin_session();
        return null;
    }

    $lastSeen = (int) ($_SESSION['admin_last_seen_at'] ?? 0);
    if ($lastSeen !== 0 && $lastSeen <= time() - admin_session_ttl_seconds()) {
        invalidate_admin_session();
        return null;
    }
    $_SESSION['admin_last_seen_at'] = time();
    refresh_admin_session_cookie();
    return $_SESSION['admin'];
}

function display_initial(string $value): string
{
    if (preg_match('/^./u', trim($value), $matches) !== 1) return '?';
    $initial = $matches[0];
    return function_exists('mb_strtoupper') ? mb_strtoupper($initial, 'UTF-8') : strtoupper($initial);
}

function admin_avatar_directory(): string
{
    $directory = STORAGE_DIR . '/avatars';
    ensure_directory($directory);
    ensure_private_directory_access_guard($directory);
    return $directory;
}

function admin_avatar_path(): ?string
{
    $directory = admin_avatar_directory();
    foreach (['webp', 'jpg', 'png'] as $extension) {
        $path = $directory . '/admin.' . $extension;
        if (is_file($path)) return $path;
    }
    return null;
}

function admin_avatar_url(): ?string
{
    $path = admin_avatar_path();
    if ($path === null) return null;
    $version = (string) (filemtime($path) ?: 0);
    return 'avatar.php?v=' . rawurlencode($version);
}

/** @param array<string,mixed> $upload */
function save_admin_avatar_upload(array $upload): void
{
    if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
        throw new RuntimeException('头像上传失败，请重新选择图片。');
    }
    if ((int) ($upload['size'] ?? 0) < 1 || (int) ($upload['size'] ?? 0) > ADMIN_AVATAR_MAX_BYTES) {
        throw new RuntimeException('裁剪后的头像不能超过 3 MB。');
    }

    $temporary = (string) $upload['tmp_name'];
    $dimensions = @getimagesize($temporary);
    $mime = is_array($dimensions) ? (string) ($dimensions['mime'] ?? '') : '';
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) throw new RuntimeException('头像仅支持 JPEG、PNG 或 WebP 图片。');
    $width = (int) ($dimensions[0] ?? 0);
    $height = (int) ($dimensions[1] ?? 0);
    if ($width < 96 || $height < 96 || $width > 2048 || $height > 2048) {
        throw new RuntimeException('头像尺寸应介于 96 和 2048 像素之间。');
    }

    $directory = admin_avatar_directory();
    $extension = $extensions[$mime];
    $staged = $directory . '/admin-' . bin2hex(random_bytes(8)) . '.' . $extension;
    if (!move_uploaded_file($temporary, $staged)) throw new RuntimeException('无法保存裁剪后的头像。');

    foreach (['webp', 'jpg', 'png'] as $existingExtension) {
        $existing = $directory . '/admin.' . $existingExtension;
        if (is_file($existing)) @unlink($existing);
    }
    $destination = $directory . '/admin.' . $extension;
    if (!rename($staged, $destination)) {
        @unlink($staged);
        throw new RuntimeException('无法启用新的头像。');
    }
}

function require_admin(): string
{
    enforce_admin_transport_policy();
    // A configured but unavailable database is an operational error, not a
    // request to reopen the public setup wizard. Only a genuinely fresh
    // deployment (no installation record at all) may enter setup.
    if (!admin_is_configured()) {
        if (installation_config() === null) {
            redirect('setup.php');
        }
        if (installation_sqlite_admin_table_missing()) redirect('setup.php');
        abort_request(503, admin_database_unavailable_message());
    }
    $admin = current_admin();
    if ($admin === null) {
        redirect('login.php');
    }
    send_admin_security_headers();
    return $admin;
}

function csrf_token(): string
{
    start_secure_session();
    $instanceId = app_instance_id();
    if (empty($_SESSION['csrf']) || !hash_equals($instanceId, (string) ($_SESSION['csrf_instance'] ?? ''))) {
        $_SESSION['csrf'] = hash_hmac('sha256', random_bytes(32), $instanceId);
        $_SESSION['csrf_instance'] = $instanceId;
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    start_secure_session();
    $submitted = (string) ($_POST['csrf'] ?? '');
    if (empty($_SESSION['csrf'])
        || !hash_equals(app_instance_id(), (string) ($_SESSION['csrf_instance'] ?? ''))
        || !hash_equals((string) $_SESSION['csrf'], $submitted)) {
        abort_request(403, 'CSRF verification failed.');
    }
}

function flash(string $key, ?string $value = null): ?string
{
    start_secure_session();
    if ($value !== null) {
        $_SESSION['flash'][$key] = $value;
        return null;
    }
    $message = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return is_string($message) ? $message : null;
}

function redirect(string $location): never
{
    // A relative Location header is resolved against the URL typed by the
    // browser. When `/admin` (without its trailing slash) runs
    // `/admin/index.php`, `Location: setup.php` would incorrectly become
    // `/setup.php`. Build an application-root-aware absolute path instead.
    if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $location) && !str_starts_with($location, '/')) {
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        // Some directory-index implementations expose `/admin` as the script
        // name instead of `/admin/index.php`; in that form the script name is
        // itself the redirect directory.
        $directory = str_contains(basename($scriptName), '.')
            ? rtrim(str_replace('\\', '/', dirname($scriptName)), '/')
            : rtrim($scriptName, '/');
        if ($directory !== '' && $directory !== '.' && !str_starts_with($directory, '/')) {
            $directory = '/' . $directory;
        }
        $location = ($directory === '' || $directory === '.' ? '' : $directory) . '/' . ltrim($location, '/');
    }
    header('Location: ' . $location, true, 303);
    exit;
}

function html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function valid_category(string $category): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,48}$/i', $category);
}

/** Reject traversal segments while preserving ordinary names such as a..b.png. */
function valid_relative_image_path(string $relative): bool
{
    $normalized = ltrim(str_replace('\\', '/', $relative), '/');
    if ($normalized === '' || str_contains($normalized, "\0")) return false;
    foreach (explode('/', $normalized) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') return false;
    }
    return true;
}

function valid_category_name(string $name): bool
{
    $name = trim($name);
    $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    return $length >= 1 && $length <= 60 && !preg_match('~[\\\\/\x00-\x1F\x7F]~u', $name);
}

function category_metadata_path(): string
{
    return STORAGE_DIR . '/categories.json';
}

/** @return array<string,array{name:string,created_at:string}> */
function category_metadata(): array
{
    $path = category_metadata_path();
    $stored = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    $stored = is_array($stored) ? $stored : [];
    $normalize = static function (array &$data): array {
        $metadata = [];
        foreach ($data as $alias => $item) {
            if (!is_string($alias) || !valid_category($alias) || !is_array($item) || !valid_category_name((string) ($item['name'] ?? ''))) continue;
            $metadata[$alias] = ['name' => trim((string) $item['name']), 'created_at' => (string) ($item['created_at'] ?? '')];
        }
        $data = $metadata;
        return $metadata;
    };
    $fallback = $normalize($stored);
    $pdo = runtime_state_database();
    if ($pdo !== null) {
        try {
            $rows = $pdo->query('SELECT category_alias, display_name, created_at FROM picapi_categories ORDER BY sort_order ASC, created_at ASC, category_alias ASC')->fetchAll(PDO::FETCH_ASSOC);
            $metadata = [];
            foreach ($rows as $row) {
                $alias = (string) ($row['category_alias'] ?? '');
                $name = (string) ($row['display_name'] ?? '');
                if (valid_category($alias) && valid_category_name($name)) {
                    $metadata[$alias] = ['name' => $name, 'created_at' => (string) ($row['created_at'] ?? '')];
                }
            }
            if ($metadata !== [] || $fallback === []) return $metadata;
            save_category_metadata($fallback);
            return $fallback;
        } catch (Throwable) {
            // The generic state table and JSON file remain safe fallbacks.
        }
        return with_database_runtime_state('category-metadata', $fallback, $normalize);
    }
    return $fallback;
}

/** @param array<string,array{name:string,created_at:string}> $metadata */
function save_category_metadata(array $metadata): void
{
    $stored = $metadata;
    $normalize = static function (array &$data): array {
        $normalized = [];
        foreach ($data as $alias => $item) {
            if (!is_string($alias) || !valid_category($alias) || !is_array($item) || !valid_category_name((string) ($item['name'] ?? ''))) continue;
            $normalized[$alias] = ['name' => trim((string) $item['name']), 'created_at' => (string) ($item['created_at'] ?? '')];
        }
        $data = $normalized;
        return $normalized;
    };
    $metadata = $normalize($stored);
    $pdo = runtime_state_database();
    if ($pdo !== null) {
        try {
            $pdo->beginTransaction();
            $pdo->exec('DELETE FROM picapi_categories');
            $statement = $pdo->prepare('INSERT INTO picapi_categories (category_alias, display_name, created_at, sort_order) VALUES (:alias, :name, :created_at, :sort_order)');
            $sortOrder = 1;
            foreach ($metadata as $alias => $item) {
                $statement->execute(['alias' => $alias, 'name' => $item['name'], 'created_at' => $item['created_at'], 'sort_order' => $sortOrder++]);
            }
            $pdo->commit();
            return;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw new RuntimeException('无法保存分类信息到数据库。', 0, $error);
        }
    }
    $path = category_metadata_path();
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('无法保存分类信息。');
    }
}

function category_display_name(string $alias): string
{
    $metadata = category_metadata();
    return $metadata[$alias]['name'] ?? ($alias === 'default' ? '默认分类' : $alias);
}

/** @return array<string,string> */
function category_display_names(): array
{
    $names = ['default' => '默认分类'];
    foreach (category_metadata() as $alias => $item) $names[$alias] = $item['name'];
    return $names;
}

function category_display_name_exists(string $name, string $exceptAlias = ''): bool
{
    $normalize = static function (string $value): string {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    };
    $expected = $normalize($name);
    foreach (all_categories() as $alias) {
        if ($alias === $exceptAlias) continue;
        if ($normalize(category_display_name($alias)) === $expected) return true;
    }
    return false;
}

function generate_category_alias(): string
{
    $used = array_fill_keys(all_categories(), true);
    for ($number = 1; $number <= 9999; $number++) {
        $alias = 'category-' . $number;
        if (!isset($used[$alias])) return $alias;
    }
    throw new RuntimeException('无法生成新的分类请求名称。');
}

function category_path(string $device, string $category = ''): string
{
    $base = $device === 'mobile' ? IMAGE_MOBILE_DIR : IMAGE_PC_DIR;
    if ($category === '') {
        return $base;
    }
    if (!valid_category($category)) {
        throw new InvalidArgumentException('Invalid category identifier.');
    }
    return $base . DIRECTORY_SEPARATOR . $category;
}

function ensure_category_endpoint(string $device, string $category): void
{
    if (!in_array($device, ['pc', 'mobile'], true) || !valid_category($category)) {
        throw new InvalidArgumentException('Invalid category endpoint.');
    }
    $directory = category_path($device, $category);
    ensure_directory($directory);
    $endpoint = $directory . DIRECTORY_SEPARATOR . 'index.php';
    if (is_file($endpoint)) {
        return;
    }
    $deviceLiteral = var_export($device, true);
    $contents = "<?php\ndeclare(strict_types=1);\n\nrequire dirname(__DIR__, 4) . '/src/public_api_runtime.php';\n\nrun_public_api({$deviceLiteral}, basename(__DIR__));\n";
    if (file_put_contents($endpoint, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Unable to create category endpoint.');
    }
}

function image_url(string $device, string $relativePath): string
{
    $prefix = $device === 'mobile' ? 'm/img/' : 'pc/img/';
    return application_base_url() . '/' . $prefix . str_replace('%2F', '/', rawurlencode(str_replace('\\', '/', $relativePath)));
}

function all_categories(): array
{
    initialize_storage();
    $categories = [];
    foreach ([IMAGE_PC_DIR, IMAGE_MOBILE_DIR] as $root) {
        foreach (new DirectoryIterator($root) as $entry) {
            if (!$entry->isDot() && $entry->isDir() && !$entry->isLink() && valid_category($entry->getFilename())) {
                $categories[$entry->getFilename()] = true;
            }
        }
    }
    $names = array_keys($categories);
    $metadata = category_metadata();
    usort($names, static function (string $left, string $right) use ($metadata): int {
        if ($left === 'default') return -1;
        if ($right === 'default') return 1;
        $leftCreated = (string) ($metadata[$left]['created_at'] ?? '');
        $rightCreated = (string) ($metadata[$right]['created_at'] ?? '');
        if ($leftCreated !== $rightCreated) return $leftCreated <=> $rightCreated;
        return strnatcasecmp($left, $right);
    });
    return $names;
}
