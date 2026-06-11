<?php

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

require_once __DIR__ . '/../lib/image_runtime.php';
require_once __DIR__ . '/auth.php';

const ADMIN_DATA_DIR = __DIR__ . '/data';
const ADMIN_UPLOAD_LOG_FILE = ADMIN_DATA_DIR . '/upload_history.jsonl';
const ADMIN_CHUNK_DIR = ADMIN_DATA_DIR . '/chunks';
const ADMIN_TEMP_DIR = ADMIN_DATA_DIR . '/tmp';
const ADMIN_PREVIEW_DIR = ADMIN_DATA_DIR . '/previews';
const ADMIN_LOGIN_RATE_LIMIT_FILE = ADMIN_DATA_DIR . '/login_rate_limit.json';
const ADMIN_REMEMBER_TOKEN_FILE = ADMIN_DATA_DIR . '/remember_tokens.json';
const ADMIN_CUSTOM_API_FILE = ADMIN_DATA_DIR . '/custom_apis.json';
const ADMIN_CUSTOM_API_STATE_FILE = ADMIN_DATA_DIR . '/custom_api_state.json';
const ADMIN_CATEGORY_FILE = ADMIN_DATA_DIR . '/categories.json';
const ADMIN_ENDPOINT_SETTINGS_FILE = ADMIN_DATA_DIR . '/api_settings.json';
const ADMIN_ENDPOINT_STATE_FILE = ADMIN_DATA_DIR . '/endpoint_state.json';
const ADMIN_CHUNK_SIZE = 2_097_152;
const ADMIN_MAX_UPLOAD_FILE_SIZE = 31_457_280;
const ADMIN_MAX_UPLOAD_CHUNKS = 64;
const ADMIN_MAX_IMAGE_WIDTH = 20_000;
const ADMIN_MAX_IMAGE_HEIGHT = 20_000;
const ADMIN_PREVIEW_MAX_EDGE = 480;
const ADMIN_LOGIN_TTL = 604800;

$projectRoot = dirname(__DIR__);
$libraryConfigs = [
    'pc' => [
        'label' => 'PC 图库',
        'root_dir' => $projectRoot . '/pc/img',
        'public_prefix' => 'pc/img',
    ],
    'm' => [
        'label' => '手机图库',
        'root_dir' => $projectRoot . '/m/img',
        'public_prefix' => 'm/img',
    ],
];

image_api_ensure_directory(ADMIN_DATA_DIR);
image_api_ensure_directory(ADMIN_CHUNK_DIR);
image_api_ensure_directory(ADMIN_TEMP_DIR);
image_api_ensure_directory(ADMIN_PREVIEW_DIR);
foreach ($libraryConfigs as $config) {
    image_api_ensure_directory($config['root_dir']);
}

admin_bootstrap_setup_flow();
admin_bootstrap_authentication();
admin_ensure_device_cookie();
admin_prevent_indexing();
admin_track_request_spike();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    admin_require_csrf();
    admin_forget_login();
    admin_set_flash('success', '已退出后台。');
    admin_redirect('login.php');
}

function admin_is_logged_in(): bool
{
    return !empty($_SESSION['image_api_admin_logged_in'])
        && is_string($_SESSION['image_api_admin_username'] ?? null)
        && ($_SESSION['image_api_admin_username'] ?? '') !== '';
}

function admin_require_login(): void
{
    if (!admin_is_logged_in()) {
        admin_set_flash('error', '请先登录后台。');
        admin_redirect('login.php');
    }
}

function admin_login(string $username, string $password): bool
{
    if (!admin_is_initialized()) {
        return false;
    }

    $normalizedUsername = trim($username);
    if ($normalizedUsername === '') {
        return false;
    }

    if (!admin_verify_login($normalizedUsername, $password)) {
        return false;
    }

    $_SESSION['image_api_admin_username'] = $normalizedUsername;
    return true;
}

function admin_prevent_indexing(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex', true);
}

function admin_set_flash(string $type, string $message): void
{
    $_SESSION['image_api_admin_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function admin_pull_flash(): ?array
{
    if (!isset($_SESSION['image_api_admin_flash'])) {
        return null;
    }

    $flash = $_SESSION['image_api_admin_flash'];
    unset($_SESSION['image_api_admin_flash']);
    return is_array($flash) ? $flash : null;
}

function admin_session_login(string $username): void
{
    session_regenerate_id(true);
    $_SESSION['image_api_admin_logged_in'] = true;
    $_SESSION['image_api_admin_username'] = $username;
    $_SESSION['image_api_admin_last_seen_at'] = time();
}

function admin_login_ttl(): int
{
    return ADMIN_LOGIN_TTL;
}

function admin_device_cookie_name(): string
{
    return 'image_api_admin_device';
}

function admin_ensure_device_cookie(): string
{
    $cookie = trim((string) ($_COOKIE[admin_device_cookie_name()] ?? ''));
    if ($cookie !== '') {
        return $cookie;
    }

    $cookie = bin2hex(random_bytes(32));
    setcookie(admin_device_cookie_name(), $cookie, admin_cookie_options(time() + 31_536_000));
    $_COOKIE[admin_device_cookie_name()] = $cookie;
    return $cookie;
}

function admin_device_fingerprint(string $username = ''): string
{
    $device = admin_ensure_device_cookie();
    $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown-agent'));
    $config = admin_load_config();
    $secret = (string) ($config['security']['secret'] ?? '');
    if ($secret === '') {
        $secret = bin2hex(random_bytes(24));
        $config['security']['secret'] = $secret;
        admin_write_config($config);
    }
    return hash('sha256', $secret . $device . '|' . $userAgent);
}

function admin_remember_cookie_name(): string
{
    return 'image_api_admin_remember';
}

function admin_cookie_options(int $expiresAt): array
{
    global $isHttps;

    return [
        'expires' => $expiresAt,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function admin_set_remember_cookie(string $cookieValue, int $expiresAt): void
{
    setcookie(admin_remember_cookie_name(), $cookieValue, admin_cookie_options($expiresAt));
    $_COOKIE[admin_remember_cookie_name()] = $cookieValue;
}

function admin_clear_remember_cookie(): void
{
    setcookie(admin_remember_cookie_name(), '', admin_cookie_options(time() - 3600));
    unset($_COOKIE[admin_remember_cookie_name()]);
}

function admin_read_remember_tokens(): array
{
    if (!is_file(ADMIN_REMEMBER_TOKEN_FILE)) {
        return [];
    }

    $raw = file_get_contents(ADMIN_REMEMBER_TOKEN_FILE);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function admin_write_remember_tokens(array $tokens): void
{
    file_put_contents(
        ADMIN_REMEMBER_TOKEN_FILE,
        json_encode($tokens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function admin_prune_remember_tokens(array $tokens): array
{
    $now = time();
    foreach ($tokens as $selector => $entry) {
        if ((int) ($entry['expires_at'] ?? 0) <= $now) {
            unset($tokens[$selector]);
        }
    }

    return $tokens;
}

function admin_issue_remember_token(string $username): void
{
    $tokens = admin_prune_remember_tokens(admin_read_remember_tokens());
    $selector = bin2hex(random_bytes(12));
    $token = bin2hex(random_bytes(32));
    $expiresAt = time() + admin_login_ttl();

    $tokens[$selector] = [
        'username' => $username,
        'token_hash' => hash('sha256', $token),
        'expires_at' => $expiresAt,
    ];

    admin_write_remember_tokens($tokens);
    admin_set_remember_cookie($selector . ':' . $token, $expiresAt);
}

function admin_forget_login(): void
{
    $cookie = trim((string) ($_COOKIE[admin_remember_cookie_name()] ?? ''));
    if ($cookie !== '' && str_contains($cookie, ':')) {
        [$selector] = explode(':', $cookie, 2);
        $tokens = admin_read_remember_tokens();
        if (isset($tokens[$selector])) {
            unset($tokens[$selector]);
            admin_write_remember_tokens($tokens);
        }
    }

    admin_clear_remember_cookie();
    unset($_SESSION['image_api_admin_logged_in'], $_SESSION['image_api_admin_username'], $_SESSION['image_api_admin_last_seen_at']);
    session_regenerate_id(true);
}

function admin_finalize_login(string $username): void
{
    admin_session_login($username);
    admin_issue_remember_token($username);
}

function admin_restore_login_from_cookie(): bool
{
    $cookie = trim((string) ($_COOKIE[admin_remember_cookie_name()] ?? ''));
    if ($cookie === '' || !str_contains($cookie, ':')) {
        return false;
    }

    [$selector, $token] = explode(':', $cookie, 2);
    if ($selector === '' || $token === '') {
        admin_clear_remember_cookie();
        return false;
    }

    $tokens = admin_prune_remember_tokens(admin_read_remember_tokens());
    $entry = $tokens[$selector] ?? null;
    if (!is_array($entry)) {
        admin_write_remember_tokens($tokens);
        admin_clear_remember_cookie();
        return false;
    }

    $username = trim((string) ($entry['username'] ?? ''));
    $knownHash = (string) ($entry['token_hash'] ?? '');
    $expiresAt = (int) ($entry['expires_at'] ?? 0);
    $isValid = $username !== ''
        && $knownHash !== ''
        && $expiresAt > time()
        && hash_equals($knownHash, hash('sha256', $token));

    unset($tokens[$selector]);
    admin_write_remember_tokens($tokens);

    if (!$isValid) {
        admin_clear_remember_cookie();
        return false;
    }

    admin_finalize_login($username);
    return true;
}

function admin_bootstrap_authentication(): void
{
    if (admin_is_logged_in()) {
        $lastSeenAt = (int) ($_SESSION['image_api_admin_last_seen_at'] ?? 0);
        if ($lastSeenAt > 0 && (time() - $lastSeenAt) <= admin_login_ttl()) {
            $_SESSION['image_api_admin_last_seen_at'] = time();
            admin_issue_remember_token((string) $_SESSION['image_api_admin_username']);
            return;
        }

        admin_forget_login();
        admin_set_flash('warning', '登录已过期，请重新输入密码。');
        if (basename((string) ($_SERVER['PHP_SELF'] ?? '')) !== 'login.php') {
            admin_redirect('login.php');
        }
        return;
    }

    if (!admin_restore_login_from_cookie() && trim((string) ($_COOKIE[admin_remember_cookie_name()] ?? '')) !== '') {
        admin_set_flash('warning', '登录已过期，请重新输入密码。');
        if (basename((string) ($_SERVER['PHP_SELF'] ?? '')) !== 'login.php') {
            admin_redirect('login.php');
        }
    }
}

function admin_csrf_token(): string
{
    $token = $_SESSION['image_api_admin_csrf'] ?? '';
    if (!is_string($token) || $token === '') {
        $token = bin2hex(random_bytes(32));
        $_SESSION['image_api_admin_csrf'] = $token;
    }

    return $token;
}

function admin_request_csrf_token(): string
{
    $headerToken = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($headerToken !== '') {
        return $headerToken;
    }

    return trim((string) ($_POST['_csrf'] ?? ''));
}

function admin_require_csrf(): void
{
    $knownToken = admin_csrf_token();
    $submittedToken = admin_request_csrf_token();

    if ($submittedToken === '' || !hash_equals($knownToken, $submittedToken)) {
        http_response_code(419);
        exit('Invalid CSRF token.');
    }
}

function admin_client_ip(): string
{
    return trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function admin_login_rate_limit_window(): int
{
    return 900;
}

function admin_login_rate_limit_max_attempts(): int
{
    return 5;
}

function admin_read_login_rate_limit_bucket(): array
{
    if (!is_file(ADMIN_LOGIN_RATE_LIMIT_FILE)) {
        return [];
    }

    $raw = file_get_contents(ADMIN_LOGIN_RATE_LIMIT_FILE);
    if ($raw === false || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function admin_write_login_rate_limit_bucket(array $bucket): void
{
    file_put_contents(
        ADMIN_LOGIN_RATE_LIMIT_FILE,
        json_encode($bucket, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function admin_login_rate_limit_remaining(string $ip): array
{
    $bucket = admin_read_login_rate_limit_bucket();
    $window = admin_login_rate_limit_window();
    $now = time();
    $entries = array_values(array_filter(
        array_map('intval', $bucket[$ip] ?? []),
        static fn(int $timestamp): bool => $timestamp >= ($now - $window)
    ));

    if ($entries === []) {
        return ['blocked' => false, 'retry_after' => 0, 'entries' => []];
    }

    $maxAttempts = admin_login_rate_limit_max_attempts();
    $blocked = count($entries) >= $maxAttempts;
    $retryAfter = $blocked ? max(1, ($entries[0] + $window) - $now) : 0;

    return ['blocked' => $blocked, 'retry_after' => $retryAfter, 'entries' => $entries];
}

function admin_login_rate_limit_assert(string $ip): void
{
    $status = admin_login_rate_limit_remaining($ip);
    if ($status['blocked']) {
        throw new RuntimeException('登录失败次数过多，请在 ' . $status['retry_after'] . ' 秒后重试。');
    }
}

function admin_login_rate_limit_record_failure(string $ip): void
{
    $bucket = admin_read_login_rate_limit_bucket();
    $status = admin_login_rate_limit_remaining($ip);
    $entries = $status['entries'];
    $entries[] = time();
    $bucket[$ip] = $entries;
    admin_write_login_rate_limit_bucket($bucket);
}

function admin_login_rate_limit_clear(string $ip): void
{
    $bucket = admin_read_login_rate_limit_bucket();
    unset($bucket[$ip]);
    admin_write_login_rate_limit_bucket($bucket);
}

function admin_login_cooldown_key(string $username = ''): string
{
    $normalizedUsername = trim(strtolower($username));
    return $normalizedUsername !== '' ? $normalizedUsername : admin_client_ip();
}

function admin_login_cooldown_for_failures(int $failures): int
{
    return match (true) {
        $failures <= 2 => 0,
        $failures === 3 => 5,
        $failures === 4 => 60,
        $failures === 5 => 60,
        $failures === 6 => 120,
        $failures === 7 => 300,
        $failures === 8 => 600,
        $failures === 9 => 1200,
        $failures === 10 => 1800,
        default => 1800 + (($failures - 10) * 600),
    };
}

function admin_login_cooldown_normalize_record(mixed $record): array
{
    if (!is_array($record)) {
        return [
            'failures' => 0,
            'cooldown_until' => 0,
            'last_failure_at' => 0,
        ];
    }

    if (array_is_list($record)) {
        $failures = count($record);
        $lastFailureAt = (int) max(array_map('intval', $record) ?: [0]);
        return [
            'failures' => $failures,
            'cooldown_until' => 0,
            'last_failure_at' => $lastFailureAt,
        ];
    }

    $normalized = [
        'failures' => max(0, (int) ($record['failures'] ?? 0)),
        'cooldown_until' => max(0, (int) ($record['cooldown_until'] ?? 0)),
        'last_failure_at' => max(0, (int) ($record['last_failure_at'] ?? 0)),
    ];

    if ($normalized['last_failure_at'] > 0 && (time() - $normalized['last_failure_at']) > 86400) {
        return [
            'failures' => 0,
            'cooldown_until' => 0,
            'last_failure_at' => 0,
        ];
    }

    return $normalized;
}

function admin_login_cooldown_status(string $key): array
{
    $bucket = admin_read_login_rate_limit_bucket();
    $record = admin_login_cooldown_normalize_record($bucket[$key] ?? null);
    $retryAfter = max(0, $record['cooldown_until'] - time());

    return [
        'blocked' => $retryAfter > 0,
        'retry_after' => $retryAfter,
        'failures' => $record['failures'],
        'record' => $record,
    ];
}

function admin_login_cooldown_assert(string $key): void
{
    $status = admin_login_cooldown_status($key);
    if ($status['blocked']) {
        throw new RuntimeException('登录失败次数过多，请在 ' . $status['retry_after'] . ' 秒后重试。');
    }
}

function admin_login_cooldown_record_failure(string $key): void
{
    $bucket = admin_read_login_rate_limit_bucket();
    $record = admin_login_cooldown_normalize_record($bucket[$key] ?? null);
    $record['failures']++;
    $record['last_failure_at'] = time();
    $cooldown = admin_login_cooldown_for_failures($record['failures']);
    $record['cooldown_until'] = $cooldown > 0 ? (time() + $cooldown) : 0;
    $bucket[$key] = $record;
    admin_write_login_rate_limit_bucket($bucket);
}

function admin_login_cooldown_clear(string $key): void
{
    $bucket = admin_read_login_rate_limit_bucket();
    unset($bucket[$key]);
    admin_write_login_rate_limit_bucket($bucket);
}

function admin_redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function admin_extract_field_value(string $segment): string
{
    $position = strpos($segment, ':');
    return $position === false ? trim($segment) : trim(substr($segment, $position + 1));
}

function admin_normalize_search_text(string $value): string
{
    $normalized = trim($value);
    if ($normalized === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($normalized, 'UTF-8');
    }

    return strtolower($normalized);
}

function admin_allowed_extensions(): array
{
    return ['jpg', 'jpeg', 'png', 'gif', 'webp'];
}

function admin_default_category_name(): string
{
    return 'default';
}

function admin_category_name_pattern(): string
{
    return '/^[A-Za-z0-9_-]+$/';
}

function admin_is_valid_category_name(string $name): bool
{
    return preg_match(admin_category_name_pattern(), $name) === 1;
}

function admin_validate_category_name(string $name): string
{
    $normalized = trim($name);
    if ($normalized === '') {
        throw new RuntimeException('分类名称不能为空。');
    }

    if (!admin_is_valid_category_name($normalized)) {
        throw new RuntimeException('分类名称只能使用英文、数字、下划线和短横线。');
    }

    return $normalized;
}

function admin_normalize_category_record(array $record, array $existing = []): array
{
    $name = (string) ($record['name'] ?? $existing['name'] ?? admin_default_category_name());
    if ($name === admin_default_category_name()) {
        $name = admin_default_category_name();
    } else {
        $name = admin_validate_category_name($name);
    }

    $note = trim((string) ($record['note'] ?? $existing['note'] ?? ''));
    $createdAt = (string) ($existing['created_at'] ?? $record['created_at'] ?? date('c'));
    $updatedAt = (string) ($record['updated_at'] ?? date('c'));

    return [
        'name' => $name,
        'note' => $note,
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
    ];
}

function admin_sort_categories(array $items): array
{
    usort($items, static function (array $left, array $right): int {
        $default = admin_default_category_name();
        if (($left['name'] ?? '') === $default && ($right['name'] ?? '') !== $default) {
            return -1;
        }
        if (($right['name'] ?? '') === $default && ($left['name'] ?? '') !== $default) {
            return 1;
        }

        return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
    });

    return array_values($items);
}

function admin_ensure_default_category(array $categories): array
{
    $default = admin_default_category_name();
    $found = false;

    foreach ($categories as $index => $record) {
        if (($record['name'] ?? '') !== $default) {
            continue;
        }

        $categories[$index] = admin_normalize_category_record($record, $record);
        $found = true;
        break;
    }

    if (!$found) {
        $categories[] = admin_normalize_category_record([
            'name' => $default,
            'note' => '',
        ]);
    }

    return admin_sort_categories($categories);
}

function admin_read_categories(): array
{
    if (!is_file(ADMIN_CATEGORY_FILE)) {
        $defaultCategories = admin_ensure_default_category([]);
        admin_write_categories($defaultCategories);
        return $defaultCategories;
    }

    $raw = file_get_contents(ADMIN_CATEGORY_FILE);
    if ($raw === false || trim($raw) === '') {
        $defaultCategories = admin_ensure_default_category([]);
        admin_write_categories($defaultCategories);
        return $defaultCategories;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $defaultCategories = admin_ensure_default_category([]);
        admin_write_categories($defaultCategories);
        return $defaultCategories;
    }

    $categories = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }

        try {
            $categories[] = admin_normalize_category_record($item, $item);
        } catch (Throwable) {
            continue;
        }
    }

    $categories = admin_ensure_default_category($categories);
    admin_write_categories($categories);

    return $categories;
}

function admin_write_categories(array $categories): void
{
    $indexed = [];
    foreach ($categories as $record) {
        if (!is_array($record)) {
            continue;
        }

        try {
            $normalized = admin_normalize_category_record($record, $record);
        } catch (Throwable) {
            continue;
        }

        $indexed[strtolower((string) $normalized['name'])] = $normalized;
    }

    $normalizedCategories = admin_ensure_default_category(array_values($indexed));

    file_put_contents(
        ADMIN_CATEGORY_FILE,
        json_encode($normalizedCategories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function admin_find_category(array $categories, string $name): ?array
{
    foreach ($categories as $category) {
        if (($category['name'] ?? '') === $name) {
            return $category;
        }
    }

    return null;
}

function admin_upsert_category(string $name, string $note): array
{
    $name = $name === admin_default_category_name()
        ? admin_default_category_name()
        : admin_validate_category_name($name);

    $categories = admin_read_categories();
    $existing = admin_find_category($categories, $name);
    $record = admin_normalize_category_record([
        'name' => $name,
        'note' => trim($note),
        'updated_at' => date('c'),
    ], $existing ?? []);

    $updated = [];
    $stored = false;
    foreach ($categories as $category) {
        if (($category['name'] ?? '') === $name) {
            $updated[] = $record;
            $stored = true;
            continue;
        }

        $updated[] = $category;
    }

    if (!$stored) {
        $updated[] = $record;
    }

    admin_write_categories($updated);
    return $record;
}

function admin_category_note_for(array $categories, string $name): string
{
    $record = admin_find_category($categories, $name);
    return trim((string) ($record['note'] ?? ''));
}

function admin_extract_category_parts(string $relativePath): array
{
    $normalized = image_api_normalize_relative_path($relativePath);
    if ($normalized === '') {
        return [
            'category' => admin_default_category_name(),
            'category_relative_path' => '',
            'is_legacy_root' => false,
        ];
    }

    $segments = explode('/', $normalized);
    if (count($segments) > 1 && admin_is_valid_category_name($segments[0])) {
        return [
            'category' => $segments[0],
            'category_relative_path' => implode('/', array_slice($segments, 1)),
            'is_legacy_root' => false,
        ];
    }

    return [
        'category' => admin_default_category_name(),
        'category_relative_path' => $normalized,
        'is_legacy_root' => true,
    ];
}

function admin_filter_images_by_category(array $items, string $category): array
{
    return array_values(array_filter($items, static function (array $item) use ($category): bool {
        return (string) ($item['category'] ?? admin_default_category_name()) === $category;
    }));
}

function admin_build_category_summaries(array $libraryItems, ?array $categories = null): array
{
    $categories = $categories ?? admin_read_categories();
    $summaries = [];

    foreach ($categories as $category) {
        $name = (string) ($category['name'] ?? '');
        if ($name === '') {
            continue;
        }

        $summaries[$name] = [
            'name' => $name,
            'note' => trim((string) ($category['note'] ?? '')),
            'created_at' => (string) ($category['created_at'] ?? date('c')),
            'updated_at' => (string) ($category['updated_at'] ?? date('c')),
            'pc_count' => 0,
            'm_count' => 0,
            'total_count' => 0,
            'bytes' => 0,
            'latest_mtime' => null,
        ];
    }

    foreach ($libraryItems as $libraryKey => $items) {
        foreach ($items as $item) {
            $categoryName = (string) ($item['category'] ?? admin_default_category_name());
            if (!isset($summaries[$categoryName])) {
                $summaries[$categoryName] = [
                    'name' => $categoryName,
                    'note' => '',
                    'created_at' => date('c'),
                    'updated_at' => date('c'),
                    'pc_count' => 0,
                    'm_count' => 0,
                    'total_count' => 0,
                    'bytes' => 0,
                    'latest_mtime' => null,
                ];
            }

            $summaries[$categoryName]['total_count']++;
            $summaries[$categoryName]['bytes'] += (int) ($item['size'] ?? 0);
            if ($libraryKey === 'pc') {
                $summaries[$categoryName]['pc_count']++;
            } elseif ($libraryKey === 'm') {
                $summaries[$categoryName]['m_count']++;
            }

            $mtime = (int) ($item['modified_at'] ?? 0);
            if ($mtime > 0 && ($summaries[$categoryName]['latest_mtime'] === null || $mtime > $summaries[$categoryName]['latest_mtime'])) {
                $summaries[$categoryName]['latest_mtime'] = $mtime;
            }
        }
    }

    return admin_sort_categories(array_values($summaries));
}

function admin_default_endpoint_settings(): array
{
    return [
        'shared' => [
            'type_param' => 'type',
            'type_value' => 'json',
            'category_param' => 'category',
            'missing_category_behavior' => 'legacy',
        ],
        'endpoints' => [
            'root' => ['mode' => 'random', 'template' => admin_default_endpoint_template()],
            'm' => ['mode' => 'random', 'template' => admin_default_endpoint_template()],
            'pc' => ['mode' => 'random', 'template' => admin_default_endpoint_template()],
        ],
    ];
}

function admin_default_endpoint_template(): string
{
    return <<<'JSON'
{
  "url": {{url}},
  "size": {{size}},
  "width": {{width}},
  "height": {{height}},
  "category": {{category}}
}
JSON;
}

function admin_normalize_endpoint_settings(array $settings): array
{
    $defaults = admin_default_endpoint_settings();
    $shared = is_array($settings['shared'] ?? null) ? $settings['shared'] : [];
    $endpoints = is_array($settings['endpoints'] ?? null) ? $settings['endpoints'] : [];

    $typeParam = trim((string) ($shared['type_param'] ?? $shared['type_key'] ?? 'type'));
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $typeParam)) {
        $typeParam = $defaults['shared']['type_param'];
    }

    $typeValue = trim((string) ($shared['type_value'] ?? $shared['type'] ?? $defaults['shared']['type_value']));
    if ($typeValue === '') {
        $typeValue = $defaults['shared']['type_value'];
    }

    $categoryParam = trim((string) ($shared['category_param'] ?? $defaults['shared']['category_param']));
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $categoryParam)) {
        $categoryParam = $defaults['shared']['category_param'];
    }

    $missingBehavior = (string) ($shared['missing_category_behavior'] ?? $defaults['shared']['missing_category_behavior']);
    if (!in_array($missingBehavior, ['legacy'], true)) {
        $missingBehavior = 'legacy';
    }

    $normalizedEndpoints = [];
    foreach ($defaults['endpoints'] as $endpointKey => $endpointDefaults) {
        $mode = (string) ($endpoints[$endpointKey]['mode'] ?? $endpointDefaults['mode']);
        if (!in_array($mode, ['random', 'sequential'], true)) {
            $mode = $endpointDefaults['mode'];
        }

        $template = trim((string) ($endpoints[$endpointKey]['template'] ?? $endpointDefaults['template']));
        if ($template === '') {
            $template = $endpointDefaults['template'];
        }

        $normalizedEndpoints[$endpointKey] = [
            'mode' => $mode,
            'template' => $template,
        ];
    }

    return [
        'shared' => [
            'type_param' => $typeParam,
            'type_value' => $typeValue,
            'category_param' => $categoryParam,
            'missing_category_behavior' => $missingBehavior,
        ],
        'endpoints' => $normalizedEndpoints,
    ];
}

function admin_read_endpoint_settings(): array
{
    if (!is_file(ADMIN_ENDPOINT_SETTINGS_FILE)) {
        $defaults = admin_default_endpoint_settings();
        admin_write_endpoint_settings($defaults);
        return $defaults;
    }

    $raw = file_get_contents(ADMIN_ENDPOINT_SETTINGS_FILE);
    if ($raw === false || trim($raw) === '') {
        $defaults = admin_default_endpoint_settings();
        admin_write_endpoint_settings($defaults);
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    $settings = is_array($decoded) ? admin_normalize_endpoint_settings($decoded) : admin_default_endpoint_settings();
    admin_write_endpoint_settings($settings);
    return $settings;
}

function admin_write_endpoint_settings(array $settings): void
{
    $normalized = admin_normalize_endpoint_settings($settings);
    file_put_contents(
        ADMIN_ENDPOINT_SETTINGS_FILE,
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function admin_endpoint_display_name(string $endpointKey): string
{
    return match ($endpointKey) {
        'm' => '手机接口',
        'pc' => 'PC 接口',
        default => '自适应接口',
    };
}

function admin_endpoint_public_url(string $endpointKey, ?string $category = null, ?array $settings = null): string
{
    $settings = $settings ?? admin_read_endpoint_settings();
    $basePath = match ($endpointKey) {
        'm' => '../m/',
        'pc' => '../pc/',
        default => '../',
    };

    $query = [
        (string) ($settings['shared']['type_param'] ?? 'type') => (string) ($settings['shared']['type_value'] ?? 'json'),
    ];
    if ($category !== null && $category !== '') {
        $query[(string) ($settings['shared']['category_param'] ?? 'category')] = $category;
    }

    return $basePath . '?' . http_build_query($query);
}

function admin_endpoint_preview_payload(array $settings, string $endpointKey, ?array $image = null): array
{
    $mode = (string) ($settings['endpoints'][$endpointKey]['mode'] ?? 'random');
    $category = (string) ($image['category'] ?? admin_default_category_name());
    $path = '';
    $preview = '';
    $url = '';
    $fileName = '';
    $width = 0;
    $height = 0;
    $size = '0 B';

    if ($image !== null) {
        $path = (string) ($image['relative_path'] ?? '');
        $preview = admin_preview_url((string) ($image['library'] ?? 'pc'), $path, (int) ($image['modified_at'] ?? time()));
        $publicBase = ($image['library'] ?? 'pc') === 'm' ? '../m/img/' : '../pc/img/';
        $url = $publicBase . image_api_encode_public_path($path);
        $fileName = (string) ($image['basename'] ?? basename($path));
        $width = (int) ($image['width'] ?? 0);
        $height = (int) ($image['height'] ?? 0);
        $size = image_api_format_file_size((int) ($image['size'] ?? 0));
    }

    return [
        'endpoint' => $endpointKey,
        'name' => admin_endpoint_display_name($endpointKey),
        'mode' => $mode,
        'library' => $endpointKey === 'root' ? 'adaptive' : $endpointKey,
        'category' => $category,
        'file_name' => $fileName,
        'path' => $path,
        'url' => $url,
        'preview' => $preview,
        'width' => $width,
        'height' => $height,
        'size' => $size,
        'timestamp' => date('c'),
    ];
}

function admin_render_endpoint_template(string $template, array $payload): string
{
    $rendered = $template;
    foreach ($payload as $key => $value) {
        $rendered = str_replace('{{' . $key . '}}', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rendered);
    }

    return $rendered;
}

function admin_endpoint_preview(string $template, array $payload): array
{
    $rendered = admin_render_endpoint_template($template, $payload);
    $pretty = $rendered;
    $isValid = true;

    try {
        $decoded = json_decode($rendered, true, 512, JSON_THROW_ON_ERROR);
        $pretty = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    } catch (Throwable $throwable) {
        $isValid = false;
    }

    return [
        'preview' => $pretty,
        'is_valid_json' => $isValid,
        'rendered' => $rendered,
    ];
}

function admin_uploaded_file_is_image(string $tmpPath): bool
{
    return is_array(@getimagesize($tmpPath));
}

function admin_sanitize_file_name(string $originalName): string
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $baseName = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]+/u', ' ', $baseName);
    $baseName = trim((string) preg_replace('/\s+/u', ' ', $baseName), ". \t\n\r\0\x0B");
    if ($baseName === '') {
        $baseName = 'image_' . date('Ymd_His');
    }

    return $extension !== '' ? $baseName . '.' . $extension : $baseName;
}

function admin_unique_file_name(string $targetDir, string $fileName): string
{
    $extension = pathinfo($fileName, PATHINFO_EXTENSION);
    $baseName = pathinfo($fileName, PATHINFO_FILENAME);
    $candidate = $fileName;
    $suffix = 1;

    while (file_exists($targetDir . DIRECTORY_SEPARATOR . $candidate)) {
        $candidate = $baseName . '-' . $suffix;
        if ($extension !== '') {
            $candidate .= '.' . $extension;
        }
        $suffix++;
    }

    return $candidate;
}

function admin_append_upload_log(array $record): void
{
    file_put_contents(
        ADMIN_UPLOAD_LOG_FILE,
        json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function admin_read_upload_logs(int $limit = 100): array
{
    if (!is_readable(ADMIN_UPLOAD_LOG_FILE)) {
        return [];
    }

    $lines = file(ADMIN_UPLOAD_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $records = [];
    $slice = $limit > 0 ? array_slice($lines, -$limit) : $lines;
    foreach (array_reverse($slice) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $records[] = $decoded;
        }
    }

    return $records;
}

function admin_clear_upload_logs(): void
{
    if (is_file(ADMIN_UPLOAD_LOG_FILE)) {
        file_put_contents(ADMIN_UPLOAD_LOG_FILE, '', LOCK_EX);
    }
}

function admin_scan_library(string $rootDir, string $libraryKey): array
{
    $items = [];
    if (!is_dir($rootDir)) {
        return $items;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $extension = strtolower($fileInfo->getExtension());
        if (!in_array($extension, admin_allowed_extensions(), true)) {
            continue;
        }

        $relativePath = substr($fileInfo->getPathname(), strlen($rootDir));
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $dimensions = @getimagesize($fileInfo->getPathname());
        $categoryParts = admin_extract_category_parts($relativePath);

        $items[] = [
            'library' => $libraryKey,
            'full_path' => $fileInfo->getPathname(),
            'relative_path' => $relativePath,
            'basename' => $fileInfo->getBasename(),
            'size' => $fileInfo->getSize(),
            'width' => $dimensions[0] ?? 0,
            'height' => $dimensions[1] ?? 0,
            'modified_at' => $fileInfo->getMTime(),
            'category' => $categoryParts['category'],
            'category_relative_path' => $categoryParts['category_relative_path'],
            'is_legacy_root' => $categoryParts['is_legacy_root'],
        ];
    }

    return $items;
}

function admin_resolve_library_file_path(array $libraryConfigs, string $libraryKey, string $relativePath): ?string
{
    if (!isset($libraryConfigs[$libraryKey])) {
        return null;
    }

    $relativePath = image_api_normalize_relative_path($relativePath);
    if ($relativePath === '' || str_contains($relativePath, '../')) {
        return null;
    }

    $rootDir = str_replace('\\', '/', $libraryConfigs[$libraryKey]['root_dir']);
    $candidate = str_replace('\\', '/', $rootDir . '/' . ltrim($relativePath, '/'));

    if (!str_starts_with($candidate, rtrim($rootDir, '/') . '/')) {
        return null;
    }

    return $candidate;
}

function admin_cleanup_empty_dirs(string $rootDir, string $filePath): void
{
    $rootDir = rtrim(str_replace('\\', '/', $rootDir), '/');
    $directory = str_replace('\\', '/', dirname($filePath));

    while ($directory !== $rootDir && str_starts_with($directory, $rootDir . '/')) {
        if ((glob($directory . '/*') ?: []) !== []) {
            break;
        }

        @rmdir($directory);
        $parent = str_replace('\\', '/', dirname($directory));
        if ($parent === $directory) {
            break;
        }
        $directory = $parent;
    }
}

function admin_library_summary(array $libraryItems): array
{
    $summary = [];
    foreach ($libraryItems as $key => $items) {
        $summary[$key] = [
            'count' => count($items),
            'bytes' => array_sum(array_column($items, 'size')),
            'latest_mtime' => empty($items) ? null : max(array_column($items, 'modified_at')),
        ];
    }

    return $summary;
}

function admin_validate_image_dimensions(int $width, int $height): void
{
    if ($width <= 0 || $height <= 0) {
        throw new RuntimeException('图片尺寸无效。');
    }

    if ($width > ADMIN_MAX_IMAGE_WIDTH || $height > ADMIN_MAX_IMAGE_HEIGHT) {
        throw new RuntimeException('图片尺寸超过系统限制。');
    }
}

function admin_preview_storage_dir(string $libraryKey): string
{
    $directory = ADMIN_PREVIEW_DIR . '/' . preg_replace('/[^a-z0-9_-]/i', '', $libraryKey);
    image_api_ensure_directory($directory);
    return $directory;
}

function admin_preview_storage_path(string $libraryKey, string $relativePath): string
{
    $normalized = image_api_normalize_relative_path($relativePath);
    return admin_preview_storage_dir($libraryKey) . '/' . sha1($libraryKey . '|' . $normalized) . '.png';
}

function admin_preview_url(string $libraryKey, string $relativePath, ?int $modifiedAt = null): string
{
    $query = [
        'library' => $libraryKey,
        'path' => $relativePath,
    ];

    if ($modifiedAt !== null) {
        $query['v'] = (string) $modifiedAt;
    }

    return 'preview.php?' . http_build_query($query);
}

function admin_image_create_from_path(string $sourcePath, string $mime)
{
    return match ($mime) {
        'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($sourcePath) : null,
        'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($sourcePath) : null,
        'image/gif' => function_exists('imagecreatefromgif') ? @imagecreatefromgif($sourcePath) : null,
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : null,
        default => null,
    };
}

function admin_create_preview_png(string $sourcePath, string $libraryKey, string $relativePath): ?string
{
    if (
        !function_exists('imagecreatetruecolor')
        || !function_exists('imagepng')
        || !is_file($sourcePath)
    ) {
        return null;
    }

    $imageInfo = @getimagesize($sourcePath);
    if (!is_array($imageInfo)) {
        return null;
    }

    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);
    admin_validate_image_dimensions($width, $height);

    $mime = strtolower((string) ($imageInfo['mime'] ?? ''));
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        return null;
    }

    $destination = admin_preview_storage_path($libraryKey, $relativePath);
    $destinationDir = dirname($destination);
    image_api_ensure_directory($destinationDir);

    if (is_file($destination) && filemtime($destination) !== false && filemtime($sourcePath) !== false && filemtime($destination) >= filemtime($sourcePath)) {
        return $destination;
    }

    $sourceImage = admin_image_create_from_path($sourcePath, $mime);
    if ($sourceImage === false) {
        return null;
    }

    $scale = min(1, ADMIN_PREVIEW_MAX_EDGE / max($width, $height));
    $targetWidth = max(1, (int) round($width * $scale));
    $targetHeight = max(1, (int) round($height * $scale));
    $previewImage = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($previewImage === false) {
        imagedestroy($sourceImage);
        return null;
    }

    imagealphablending($previewImage, false);
    imagesavealpha($previewImage, true);
    $transparent = imagecolorallocatealpha($previewImage, 0, 0, 0, 127);
    imagefill($previewImage, 0, 0, $transparent);

    imagecopyresampled(
        $previewImage,
        $sourceImage,
        0,
        0,
        0,
        0,
        $targetWidth,
        $targetHeight,
        $width,
        $height
    );

    $written = @imagepng($previewImage, $destination, 6);
    imagedestroy($previewImage);
    imagedestroy($sourceImage);

    return $written ? $destination : null;
}

function admin_delete_preview_png(string $libraryKey, string $relativePath): void
{
    $previewPath = admin_preview_storage_path($libraryKey, $relativePath);
    if (is_file($previewPath)) {
        @unlink($previewPath);
    }
}

function admin_read_counter_snapshot(string $projectRoot): array
{
    return [
        'root' => image_api_read_counter_value($projectRoot . '/log/count.log'),
        'pc' => image_api_read_counter_value($projectRoot . '/pc/log/count.log'),
        'm' => image_api_read_counter_value($projectRoot . '/m/log/count.log'),
        'icon' => image_api_read_counter_value($projectRoot . '/icon/log/count.txt'),
    ];
}

function admin_collect_dated_log_files(string $baseDir, string $source): array
{
    if (!is_dir($baseDir)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'log') {
            continue;
        }

        if (!preg_match('/^\d{2}\.log$/', $fileInfo->getBasename())) {
            continue;
        }

        $normalizedPath = str_replace('\\', '/', $fileInfo->getPathname());
        $logDate = null;
        if (preg_match('~/(\d{4})/(\d{2})/(\d{2})\.log$~', $normalizedPath, $matches) === 1) {
            $logDate = sprintf('%s-%s-%s', $matches[1], $matches[2], $matches[3]);
        }

        $files[] = [
            'path' => $fileInfo->getPathname(),
            'source' => $source,
            'date' => $logDate,
            'mtime' => $fileInfo->getMTime(),
        ];
    }

    usort($files, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
    return $files;
}

function admin_parse_access_line(string $line): ?array
{
    $segments = explode(' | ', trim($line));
    if (count($segments) < 5) {
        return null;
    }

    if (preg_match('/^\[(.+?)\]\s+IP:\s+(.+)$/u', $segments[0], $matches) !== 1) {
        return null;
    }

    $timestamp = trim($matches[1]);
    return [
        'timestamp' => $timestamp,
        'date' => substr($timestamp, 0, 10),
        'ip' => trim($matches[2]),
        'image_path' => image_api_normalize_relative_path(admin_extract_field_value($segments[1])),
        'status' => (int) admin_extract_field_value($segments[2]),
        'method' => admin_extract_field_value($segments[3]),
        'type' => admin_extract_field_value($segments[4]),
    ];
}

function admin_resolve_log_image_path(string $projectRoot, string $source, string $imagePath): ?string
{
    $imagePath = image_api_normalize_relative_path($imagePath);
    if ($imagePath === '') {
        return null;
    }

    if (str_starts_with($imagePath, 'pc/img/') || str_starts_with($imagePath, 'm/img/')) {
        $resolved = $projectRoot . '/' . $imagePath;
        return is_file($resolved) ? $resolved : null;
    }

    if ($source === 'pc' && str_starts_with($imagePath, 'img/')) {
        $resolved = $projectRoot . '/pc/' . $imagePath;
        return is_file($resolved) ? $resolved : null;
    }

    if ($source === 'm' && str_starts_with($imagePath, 'img/')) {
        $resolved = $projectRoot . '/m/' . $imagePath;
        return is_file($resolved) ? $resolved : null;
    }

    $fallback = $projectRoot . '/' . $imagePath;
    return is_file($fallback) ? $fallback : null;
}

function admin_build_access_overview(string $projectRoot, int $days = 14, int $recentLimit = 120): array
{
    $files = array_merge(
        admin_collect_dated_log_files($projectRoot . '/log', 'root'),
        admin_collect_dated_log_files($projectRoot . '/pc/log', 'pc'),
        admin_collect_dated_log_files($projectRoot . '/m/log', 'm')
    );

    $threshold = (new DateTimeImmutable('today'))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    $daily = [];
    $recent = [];
    $sizeCache = [];

    foreach ($files as $file) {
        if ($file['date'] !== null && $file['date'] < $threshold) {
            continue;
        }

        $lines = @file($file['path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            continue;
        }

        foreach ($lines as $line) {
            $entry = admin_parse_access_line($line);
            if ($entry === null || $entry['date'] < $threshold) {
                continue;
            }

            $resolvedImage = admin_resolve_log_image_path($projectRoot, $file['source'], $entry['image_path']);
            $bytes = 0;
            if ($resolvedImage !== null) {
                if (!array_key_exists($resolvedImage, $sizeCache)) {
                    $sizeCache[$resolvedImage] = filesize($resolvedImage) ?: 0;
                }
                $bytes = $sizeCache[$resolvedImage];
            }

            if (!isset($daily[$entry['date']])) {
                $daily[$entry['date']] = [
                    'hits' => 0,
                    'bandwidth' => 0,
                    'visitors' => [],
                    'root_hits' => 0,
                    'pc_hits' => 0,
                    'm_hits' => 0,
                ];
            }

            $daily[$entry['date']]['hits']++;
            $daily[$entry['date']]['bandwidth'] += $bytes;
            $daily[$entry['date']]['visitors'][$entry['ip']] = true;
            $sourceKey = $file['source'] . '_hits';
            if (isset($daily[$entry['date']][$sourceKey])) {
                $daily[$entry['date']][$sourceKey]++;
            }

            $recent[] = $entry + [
                'source' => $file['source'],
                'bandwidth' => $bytes,
            ];
        }
    }

    foreach ($daily as &$row) {
        $row['visitor_count'] = count($row['visitors']);
    }
    unset($row);

    krsort($daily);
    usort($recent, static fn(array $a, array $b): int => strcmp($b['timestamp'], $a['timestamp']));

    return [
        'daily' => $daily,
        'recent' => array_slice($recent, 0, $recentLimit),
    ];
}

function admin_chart_points(array $values, int $width, int $height, int $padding): string
{
    $count = count($values);
    if ($count === 0) {
        return '';
    }

    $max = max($values);
    if ($max <= 0) {
        $max = 1;
    }

    $usableWidth = $width - ($padding * 2);
    $usableHeight = $height - ($padding * 2);
    $stepX = $count > 1 ? $usableWidth / ($count - 1) : 0;
    $points = [];

    foreach ($values as $index => $value) {
        $x = $padding + ($stepX * $index);
        $y = $height - $padding - (($value / $max) * $usableHeight);
        $points[] = round($x, 2) . ',' . round($y, 2);
    }

    return implode(' ', $points);
}

function admin_prepare_chart_series(array $daily, int $days = 14): array
{
    $series = [];
    $start = new DateTimeImmutable('today');
    $start = $start->modify('-' . ($days - 1) . ' days');

    for ($i = 0; $i < $days; $i++) {
        $date = $start->modify('+' . $i . ' days')->format('Y-m-d');
        $series[] = [
            'date' => $date,
            'hits' => $daily[$date]['hits'] ?? 0,
            'bandwidth' => $daily[$date]['bandwidth'] ?? 0,
        ];
    }

    return $series;
}

function admin_classify_image(int $width, int $height): string
{
    return $width >= $height ? 'pc' : 'm';
}

function admin_page_base(string $active): string
{
    return basename($active);
}

function admin_query_int(string $key, int $default = 1, int $min = 1): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        return $default;
    }

    return max($min, $value);
}

function admin_allowed_per_page_options(): array
{
    return [25, 30, 50, 100, 200];
}

function admin_query_per_page(string $key, int $default = 25): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    $allowed = admin_allowed_per_page_options();
    if ($value === false || $value === null) {
        return $default;
    }

    return in_array($value, $allowed, true) ? $value : $default;
}

function admin_paginate_array(array $items, int $page, int $perPage): array
{
    $totalItems = count($items);
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $page = min(max(1, $page), $totalPages);
    $offset = ($page - 1) * $perPage;

    return [
        'items' => array_slice($items, $offset, $perPage),
        'page' => $page,
        'per_page' => $perPage,
        'total_items' => $totalItems,
        'total_pages' => $totalPages,
        'offset' => $offset,
    ];
}

function admin_build_page_url(array $overrides = []): string
{
    $query = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
            continue;
        }
        $query[$key] = $value;
    }

    $path = basename($_SERVER['PHP_SELF'] ?? '');
    $queryString = http_build_query($query);
    return $path . ($queryString !== '' ? '?' . $queryString : '');
}

function admin_render_pagination(string $pageKey, array $pagination): void
{
    if (($pagination['total_pages'] ?? 1) <= 1) {
        return;
    }

    $current = (int) $pagination['page'];
    $total = (int) $pagination['total_pages'];
    $start = max(1, $current - 2);
    $end = min($total, $current + 2);
    ?>
    <div class="pagination">
        <a class="page-link<?= $current <= 1 ? ' disabled' : '' ?>" href="<?= htmlspecialchars(admin_build_page_url([$pageKey => $current - 1]), ENT_QUOTES, 'UTF-8') ?>">上一页</a>
        <?php for ($page = $start; $page <= $end; $page++): ?>
            <a class="page-link<?= $page === $current ? ' active' : '' ?>" href="<?= htmlspecialchars(admin_build_page_url([$pageKey => $page]), ENT_QUOTES, 'UTF-8') ?>"><?= $page ?></a>
        <?php endfor; ?>
        <a class="page-link<?= $current >= $total ? ' disabled' : '' ?>" href="<?= htmlspecialchars(admin_build_page_url([$pageKey => $current + 1]), ENT_QUOTES, 'UTF-8') ?>">下一页</a>
    </div>
    <?php
}

function admin_render_per_page_selector(string $queryKey, int $currentValue, string $elementId): void
{
    ?>
    <div class="per-page">
        <label for="<?= htmlspecialchars($elementId, ENT_QUOTES, 'UTF-8') ?>">每页</label>
        <select
            id="<?= htmlspecialchars($elementId, ENT_QUOTES, 'UTF-8') ?>"
            class="per-page-select"
            data-query-key="<?= htmlspecialchars($queryKey, ENT_QUOTES, 'UTF-8') ?>"
        >
            <?php foreach (admin_allowed_per_page_options() as $option): ?>
                <option value="<?= $option ?>"<?= $option === $currentValue ? ' selected' : '' ?>><?= $option ?></option>
            <?php endforeach; ?>
        </select>
        <span>行</span>
    </div>
    <?php
}

function admin_sidebar_links(string $active): array
{
    return [
        ['href' => 'index.php', 'key' => 'index.php', 'title' => '数据总览', 'desc' => '访问、流量与趋势'],
        ['href' => 'media.php', 'key' => 'media.php', 'title' => '图片管理', 'desc' => '分类、上传与资源浏览'],
        ['href' => 'logs.php', 'key' => 'logs.php', 'title' => '日志管理', 'desc' => '访问与上传记录'],
    ];
}

function admin_render_layout_start(string $title, string $active, array $context = []): void
{
    $links = admin_sidebar_links($active);
    $isLoggedIn = admin_is_logged_in();
    $miniStats = $context['mini_stats'] ?? [];
    $headline = $context['headline'] ?? '';
    $description = $context['description'] ?? '';
    $badges = $context['badges'] ?? [];
    $autoRefreshSeconds = max(0, (int) ($context['auto_refresh_seconds'] ?? 0));
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <meta name="csrf-token" content="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <script>
        (() => {
            const storageKey = 'image_api_admin_ui_settings_v1';
            const defaults = { theme: 'system', font: 'system', fontSize: 16, backgroundImage: '' };
            const fontMap = {
                system: 'system-ui, -apple-system, "Segoe UI", Roboto, "Microsoft YaHei UI", "PingFang SC", sans-serif',
                modern: '"Segoe UI", "Helvetica Neue", Arial, "Microsoft YaHei UI", sans-serif',
                chinese: '"Microsoft YaHei UI", "Microsoft YaHei", "PingFang SC", "Hiragino Sans GB", sans-serif',
                serif: 'Georgia, "Times New Roman", "Noto Serif SC", serif'
            };

            const normalize = (value) => {
                const theme = ['light', 'dark', 'system'].includes(value?.theme) ? value.theme : defaults.theme;
                const font = Object.prototype.hasOwnProperty.call(fontMap, value?.font) ? value.font : defaults.font;
                const fontSize = Number.isFinite(Number(value?.fontSize))
                    ? Math.min(20, Math.max(14, Number(value.fontSize)))
                    : defaults.fontSize;
                const backgroundImage = typeof value?.backgroundImage === 'string' && value.backgroundImage.startsWith('data:image/')
                    ? value.backgroundImage.slice(0, 3_500_000)
                    : '';
                return { theme, font, fontSize, backgroundImage };
            };

            let stored = defaults;
            try {
                const raw = window.localStorage.getItem(storageKey);
                stored = raw ? normalize(JSON.parse(raw)) : defaults;
            } catch (error) {
                stored = defaults;
            }

            const resolvedTheme = stored.theme === 'system'
                ? (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : stored.theme;

            const root = document.documentElement;
            root.dataset.adminTheme = resolvedTheme;
            root.dataset.adminThemeMode = stored.theme;
            root.dataset.adminFont = stored.font;
            root.style.setProperty('--font-sans', fontMap[stored.font]);
            root.style.setProperty('--font-size-base', `${stored.fontSize}px`);
            root.style.setProperty('--bg-image-layer', stored.backgroundImage ? `url("${stored.backgroundImage}")` : 'none');

            document.addEventListener('DOMContentLoaded', () => {
                const apiLink = document.querySelector('.sidebar-nav a[href="custom_api.php"]');
                if (!apiLink) {
                    return;
                }

                const title = apiLink.querySelector('strong');
                const desc = apiLink.querySelector('span');
                if (title) {
                    title.textContent = '接口管理';
                }
                if (desc) {
                    desc.textContent = '自适应 / 手机 / PC 接口';
                }
            });
        })();
    </script>
    <style>
        :root {
            --bg: #f3f5f7;
            --panel: #ffffff;
            --panel-strong: #fcfcfd;
            --panel-soft: #f5f7fa;
            --panel-muted: #eef2f6;
            --ink: #111111;
            --muted: #5f6b7a;
            --line: #dde3ea;
            --line-strong: #c5d0db;
            --accent: #111111;
            --accent-soft: #e9eef3;
            --warn: #b45309;
            --font-sans: system-ui, -apple-system, "Segoe UI", Roboto, "Microsoft YaHei UI", "PingFang SC", sans-serif;
            --font-size-base: 16px;
            --danger: #be123c;
            --success: #0f766e;
            --success-soft: #ecfdf5;
            --danger-soft: #fef2f2;
            --warn-soft: #fffbeb;
            --input-bg: #ffffff;
            --progress-track: #e3e8ee;
            --placeholder-bg: #edf1f5;
            --selection-soft: #f5f7fa;
            --scroll-track: #edf1f5;
            --scroll-thumb: #c2ccd7;
            --scroll-thumb-hover: #94a3b8;
            --button-primary-bg: #111111;
            --button-primary-text: #ffffff;
            --button-primary-hover: #222222;
            --bg-image-layer: none;
            --shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
            --radius-lg: 6px;
            --radius-md: 4px;
            --radius-sm: 2px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            color: var(--ink);
            background-color: var(--bg);
            background-image:
                linear-gradient(rgba(243, 245, 247, 0.9), rgba(243, 245, 247, 0.94)),
                var(--bg-image-layer);
            background-position: center, center;
            background-repeat: no-repeat, no-repeat;
            background-size: cover, cover;
            background-attachment: fixed, fixed;
            background-blend-mode: normal, normal;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", "PingFang SC", "Microsoft YaHei", sans-serif;
            overflow: hidden;
            font-size: 15px; /* Scaled up default size */
        }

        h1, h2, h3, h4 {
            margin: 0;
            font-family: system-ui, -apple-system, sans-serif;
            font-weight: 600;
            letter-spacing: -0.02em;
        }

        .shell {
            position: relative;
            width: min(1560px, calc(100% - 48px)); /* Scaled up container */
            margin: 24px auto;
            display: grid;
            grid-template-columns: 290px minmax(0, 1fr); /* Scaled up sidebar column */
            gap: 24px;
            align-items: start;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
        }

        .sidebar {
            position: sticky;
            top: 24px;
            min-height: calc(100vh - 48px);
            padding: 24px 20px;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .sidebar-brand {
            padding: 4px 4px 20px;
            border-bottom: 1px solid var(--line);
        }

        .brand-kicker, .eyebrow {
            font-size: 11.5px; /* Scaled up metadata text */
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .brand-kicker { margin-bottom: 10px; }
        .brand-title { font-size: 23px; line-height: 1.2; margin-bottom: 8px; font-weight: 600; } /* Scaled up title */
        .brand-copy, .soft, .metric-note, .section-kicker, .muted {
            color: var(--muted);
            font-size: 13.5px; /* Scaled up description font */
            line-height: 1.65;
        }

        .sidebar-nav { display: flex; flex-direction: column; gap: 6px; }

        .sidebar-link {
            display: block;
            text-decoration: none;
            color: var(--ink);
            border: 1px solid transparent;
            background: transparent;
            border-radius: var(--radius-md);
            padding: 12px 14px; /* Scaled up links */
            transition: background 120ms ease, border-color 120ms ease;
        }

        .sidebar-link:hover {
            background: var(--accent-soft);
        }

        .sidebar-link.active {
            background: var(--accent-soft);
            border-color: var(--line);
            font-weight: 600;
        }

        .sidebar-link strong { display: block; font-size: 14.5px; line-height: 1.25; } /* Scaled up link titles */
        .sidebar-link span { display: block; margin-top: 4px; color: var(--muted); font-size: 12.5px; font-weight: normal; }

        .sidebar-mini { display: grid; gap: 12px; margin-top: auto; }
        .mini-card, .metric-card, .subpanel { background: var(--panel-strong); border: 1px solid var(--line); border-radius: var(--radius-md); }
        .mini-card { padding: 16px; }
        .mini-value, .metric-value {
            font-family: "JetBrains Mono", "Fira Code", "Geist Mono", Consolas, monospace;
            line-height: 1;
            font-weight: 600;
            letter-spacing: -0.02em;
        }
        .mini-value { font-size: 21px; margin: 8px 0 5px; color: var(--ink); } /* Scaled up status numbers */
        .metric-value { font-size: clamp(28px, 3.5vw, 38px); margin-bottom: 8px; color: var(--ink); } /* Scaled up values */

        .main {
            display: grid;
            gap: 24px;
            min-width: 0;
            max-height: calc(100vh - 48px);
            overflow: auto;
            padding-right: 4px;
        }

        .topbar {
            padding: 24px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 24px;
        }

        .topbar-copy { max-width: 820px; }
        .topbar h1 { font-size: clamp(26px, 3.5vw, 38px); line-height: 1.2; margin: 4px 0 8px; font-weight: 600; } /* Scaled up main header */
        .topbar-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: center; gap: 10px; }

        .chip-row, .toolbar { display: flex; flex-wrap: wrap; gap: 8px; }
        .chip, .badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px; /* Scaled up padding */
            border-radius: var(--radius-sm);
            border: 1px solid var(--line);
            background: var(--panel-strong);
            font-size: 11px; /* Scaled up badges */
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .badge {
            background: var(--accent-soft);
            color: var(--ink);
            font-size: 12px;
            text-transform: none;
            letter-spacing: 0;
        }

        .notice {
            padding: 12px 16px;
            border-radius: var(--radius-md);
            font-size: 14.5px; /* Scaled up notices */
            line-height: 1.6;
        }
        .notice.success { background: var(--success-soft); color: var(--success); border: 1px solid rgba(15, 118, 110, 0.18); }
        .notice.error { background: var(--danger-soft); color: var(--danger); border: 1px solid rgba(190, 18, 60, 0.18); }
        .notice.warning { background: var(--warn-soft); color: var(--warn); border: 1px solid rgba(180, 83, 9, 0.18); }

        .content-stack { display: grid; gap: 24px; }
        .metric-grid, .card-grid, .form-grid, .status-grid { display: grid; gap: 20px; }
        .metric-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .card-grid { grid-template-columns: 1.1fr .9fr; }
        .status-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .metric-card, .status-box { padding: 20px; } /* Scaled up padding */
        .status-box { background: var(--panel-strong); border: 1px solid var(--line); border-radius: var(--radius-md); }
        .section { padding: 24px; } /* Scaled up section padding */
        .section-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; margin-bottom: 20px; flex-direction: column; }
        .section-title { font-size: 21px; line-height: 1.2; font-weight: 600; color: var(--ink); } /* Scaled up section titles */
        .form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .field { display: flex; flex-direction: column; gap: 8px; }
        .field.full { grid-column: 1 / -1; }

        label { font-size: 12.5px; color: var(--muted); letter-spacing: 0.05em; text-transform: uppercase; font-weight: 600; } /* Scaled up labels */
        input, select, button, textarea {
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: 12px 14px; /* Scaled up input field padding */
            font: inherit;
            color: var(--ink);
            background: var(--input-bg);
            font-size: 14.5px; /* Scaled up form elements */
        }
        input:hover, select:hover, textarea:hover {
            border-color: var(--line-strong);
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(17, 17, 17, 0.08);
        }
        button {
            cursor: pointer;
            background: var(--button-primary-bg);
            color: var(--button-primary-text);
            font-weight: 600;
            letter-spacing: 0.02em;
            text-transform: none;
            transition: background 120ms ease, box-shadow 120ms ease;
            border: 1px solid var(--button-primary-bg);
            padding: 12px 16px; /* Scaled up button padding */
        }
        button.secondary {
            background: var(--panel);
            color: var(--ink);
            border: 1px solid var(--line);
        }
        button:hover { background: var(--button-primary-hover); }
        button.secondary:hover { background: var(--panel-soft); border-color: var(--line-strong); }

        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        th, td {
            text-align: left;
            padding: 13px 15px; /* Scaled up table padding */
            border-bottom: 1px solid var(--line);
            font-size: 14.5px; /* Scaled up td fonts */
            vertical-align: middle;
        }
        th {
            font-size: 12px; /* Scaled up th fonts */
            color: var(--muted);
            letter-spacing: 0.05em;
            text-transform: uppercase;
            font-weight: 700;
            background: var(--panel-soft);
        }
        tr:last-child td { border-bottom: none; }
        .table-wrap {
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            background: var(--panel);
        }
        .mono { font-family: "JetBrains Mono", "Fira Code", "Geist Mono", Consolas, monospace; font-size: 12.5px; color: var(--ink); word-break: break-all; } /* Scaled up mono paths */
        .empty { padding: 28px 0; color: var(--muted); text-align: center; font-size: 14.5px; }

        .main,
        .table-wrap,
        .upload-queue {
            scrollbar-width: thin;
            scrollbar-color: var(--scroll-thumb) var(--scroll-track);
        }

        .main::-webkit-scrollbar,
        .table-wrap::-webkit-scrollbar,
        .upload-queue::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        .main::-webkit-scrollbar-track,
        .table-wrap::-webkit-scrollbar-track,
        .upload-queue::-webkit-scrollbar-track {
            background: var(--scroll-track);
            border-radius: 4px;
        }

        .main::-webkit-scrollbar-thumb,
        .table-wrap::-webkit-scrollbar-thumb,
        .upload-queue::-webkit-scrollbar-thumb {
            background: var(--scroll-thumb);
            border-radius: 4px;
        }

        .main::-webkit-scrollbar-thumb:hover,
        .table-wrap::-webkit-scrollbar-thumb:hover,
        .upload-queue::-webkit-scrollbar-thumb:hover {
            background: var(--scroll-thumb-hover);
        }

        .bars { display: flex; flex-direction: column; gap: 12px; }
        .bar-pair { display: grid; grid-template-columns: 110px 1fr; gap: 14px; align-items: center; }
        .bar-stack { display: grid; gap: 6px; }
        .bar-line { position: relative; height: 8px; border-radius: 4px; overflow: hidden; background: var(--progress-track); }
        .bar-fill { position: absolute; inset: 0 auto 0 0; border-radius: inherit; }
        .bar-fill.hits { background: var(--accent); }
        .bar-fill.bandwidth { background: var(--line-strong); }
        .legend { display: flex; gap: 16px; font-size: 12px; color: var(--muted); margin-top: 14px; font-weight: 500; }
        .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 6px; }

        .curve-wrap {
            background: var(--panel-strong);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: 16px;
        }
        .curve-head { display: flex; justify-content: space-between; gap: 14px; margin-bottom: 12px; }
        .curve-svg { width: 100%; height: auto; display: block; }
        .curve-labels {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 8px;
            margin-top: 10px;
            font-size: 10.5px;
            color: var(--muted);
            font-family: "JetBrains Mono", monospace;
        }
        .curve-key { display: flex; gap: 12px; flex-wrap: wrap; font-size: 11px; color: var(--muted); }
        .curve-key span::before {
            content: "";
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 5px;
            vertical-align: middle;
        }
        .curve-key .hits::before { background: var(--accent); }
        .curve-key .bandwidth::before { background: var(--line-strong); }

        .upload-drop {
            border: 1px dashed var(--line-strong);
            border-radius: var(--radius-md);
            padding: 16px;
            background: var(--panel-soft);
        }
        .upload-queue { display: grid; gap: 8px; margin-top: 12px; }
        .upload-item { border: 1px solid var(--line); border-radius: var(--radius-md); padding: 10px 12px; background: var(--panel-strong); }
        .upload-progress { height: 6px; border-radius: 3px; background: var(--progress-track); overflow: hidden; margin-top: 6px; }
        .upload-progress > div { height: 100%; width: 0; background: var(--accent); border-radius: inherit; transition: width 120ms ease; }
        .thumb {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-sm);
            object-fit: cover;
            background: var(--placeholder-bg);
            border: 1px solid var(--line);
        }
        .media-row {
            display: grid;
            grid-template-columns: 44px minmax(0, 1fr);
            gap: 12px;
            align-items: center;
        }

        .pagination {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            justify-content: flex-end;
            margin-top: 12px;
        }

        .pagination-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .page-link {
            text-decoration: none;
            color: var(--ink);
            border: 1px solid var(--line);
            background: var(--panel);
            border-radius: 12px;
            padding: 9px 12px;
            font-size: 13px;
            line-height: 1;
            transition: background 140ms ease, border-color 140ms ease, color 140ms ease;
        }

        .page-link:hover {
            background: var(--panel-strong);
            border-color: var(--line-strong);
        }

        .page-link.active {
            background: var(--accent-soft);
            border-color: var(--line-strong);
            color: var(--accent);
        }

        .page-link.disabled {
            pointer-events: none;
            opacity: 0.42;
        }

        .per-page {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-size: 13px;
        }

        .per-page label,
        .per-page span {
            font-size: 13px;
            letter-spacing: 0;
            text-transform: none;
            color: var(--muted);
        }

        .per-page-select {
            min-width: 92px;
            padding: 9px 12px;
        }

        @media (max-width: 1120px) {
            .shell, .metric-grid, .card-grid, .form-grid {
                grid-template-columns: 1fr;
            }
            .sidebar { position: static; min-height: auto; }
            .topbar { align-items: start; flex-direction: column; }
            body { overflow: auto; }
            .main { max-height: none; overflow: visible; padding-right: 0; }
        }

        @media (max-width: 720px) {
            .shell { width: min(100% - 14px, 100%); margin: 12px auto 22px; gap: 14px; }
            .sidebar, .topbar, .section { padding: 18px; }
            .status-grid, .curve-labels { grid-template-columns: 1fr; }
            .bar-pair { grid-template-columns: 1fr; }
        }

        /* Modern minimal redesign overrides */
        /* Overrides removed to avoid double definition */
        body {
            background-color: var(--bg) !important;
            background-image:
                linear-gradient(rgba(243, 245, 247, 0.9), rgba(243, 245, 247, 0.94)),
                var(--bg-image-layer) !important;
            background-position: center, center !important;
            background-repeat: no-repeat, no-repeat !important;
            background-size: cover, cover !important;
            background-attachment: fixed, fixed !important;
            font-family: var(--font-sans) !important;
            font-size: var(--font-size-base);
        }

        body, button, input, select, textarea {
            font-family: var(--font-sans) !important;
        }

        html[data-admin-theme="dark"] {
            --bg: #0d1117;
            --panel: #131922;
            --panel-strong: #18212c;
            --panel-soft: #10161e;
            --panel-muted: #1b2532;
            --ink: #edf2f7;
            --muted: #9ca9b8;
            --line: #263241;
            --line-strong: #3a4a5e;
            --accent: #f1f5f9;
            --accent-soft: #1f2a38;
            --success: #6ee7b7;
            --danger: #fda4af;
            --warn: #fcd34d;
            --success-soft: rgba(16, 185, 129, 0.14);
            --danger-soft: rgba(244, 63, 94, 0.14);
            --warn-soft: rgba(245, 158, 11, 0.16);
            --input-bg: #0f151d;
            --progress-track: #202a38;
            --placeholder-bg: #202937;
            --selection-soft: #192230;
            --scroll-track: #10161f;
            --scroll-thumb: #344255;
            --scroll-thumb-hover: #445770;
            --button-primary-bg: #eef2f7;
            --button-primary-text: #10161d;
            --button-primary-hover: #dbe4ef;
            --shadow: 0 18px 38px rgba(0, 0, 0, 0.32);
        }

        html[data-admin-theme="dark"] body {
            background-image:
                linear-gradient(rgba(13, 17, 23, 0.86), rgba(13, 17, 23, 0.92)),
                var(--bg-image-layer) !important;
        }

        html[data-admin-theme="dark"] body,
        html[data-admin-theme="dark"] .panel,
        html[data-admin-theme="dark"] .section,
        html[data-admin-theme="dark"] .sidebar,
        html[data-admin-theme="dark"] .topbar,
        html[data-admin-theme="dark"] .metric-card,
        html[data-admin-theme="dark"] .status-box,
        html[data-admin-theme="dark"] .mini-card,
        html[data-admin-theme="dark"] .table-wrap,
        html[data-admin-theme="dark"] table,
        html[data-admin-theme="dark"] .page-link,
        html[data-admin-theme="dark"] .badge,
        html[data-admin-theme="dark"] .chip,
        html[data-admin-theme="dark"] .auth-card {
            color: var(--ink) !important;
            background: var(--panel) !important;
            border-color: var(--line) !important;
        }

        html[data-admin-theme="dark"] .sidebar-link span,
        html[data-admin-theme="dark"] .muted,
        html[data-admin-theme="dark"] .soft,
        html[data-admin-theme="dark"] .metric-note,
        html[data-admin-theme="dark"] .section-kicker,
        html[data-admin-theme="dark"] .brand-copy {
            color: var(--muted) !important;
        }

        html[data-admin-theme="dark"] .sidebar-link.active,
        html[data-admin-theme="dark"] .page-link.active,
        html[data-admin-theme="dark"] .chip,
        html[data-admin-theme="dark"] .badge {
            background: var(--accent-soft) !important;
        }

        html[data-admin-theme="dark"] input,
        html[data-admin-theme="dark"] select,
        html[data-admin-theme="dark"] textarea,
        html[data-admin-theme="dark"] button.secondary {
            background: var(--input-bg) !important;
            color: var(--ink) !important;
            border-color: var(--line) !important;
        }

        html[data-admin-theme="dark"] input:focus,
        html[data-admin-theme="dark"] select:focus,
        html[data-admin-theme="dark"] textarea:focus {
            border-color: var(--accent) !important;
            box-shadow: 0 0 0 3px rgba(241, 245, 249, 0.12) !important;
        }

        html[data-admin-theme="dark"] button {
            color: var(--button-primary-text) !important;
        }

    </style>
</head>
<body data-auto-refresh="<?= $autoRefreshSeconds ?>">
    <div class="shell">
        <aside class="panel sidebar">
            <div class="sidebar-brand">
                <div class="brand-kicker">Image API Console</div>
                <h2 class="brand-title">控制面板</h2>
                <div class="brand-copy">图片托管接口的性能指标与媒体库管理。</div>
            </div>

            <nav class="sidebar-nav">
                <?php foreach ($links as $link): ?>
                    <a class="sidebar-link <?= admin_page_base($active) === $link['key'] ? 'active' : '' ?>" href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>">
                        <strong><?= htmlspecialchars($link['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars($link['desc'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                    <?php if (($link['key'] ?? '') === 'media.php'): ?>
                        <a class="sidebar-link <?= admin_page_base($active) === 'custom_api.php' ? 'active' : '' ?>" href="custom_api.php">
                            <strong>自定义 API</strong>
                            <span>自适应 / PC / 手机接口</span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <a class="sidebar-link <?= admin_page_base($active) === 'settings.php' ? 'active' : '' ?>" href="settings.php">
                    <strong>页面设置</strong>
                    <span>字体、字号与主题模式</span>
                </a>
            </nav>

            <div class="sidebar-mini">
                <?php if (false): ?>
                <div class="mini-card">
                    <div class="eyebrow">登录状态</div>
                    <div class="mini-value"><?= $isLoggedIn ? 'Active' : 'Locked' ?></div>
                    <div class="soft"><?= $isLoggedIn ? '凭证验证通过，后台已解锁。' : '未授权访问，请输入管理员凭证。' ?></div>
                </div>
                <?php endif; ?>
                <?php foreach ($miniStats as $mini): ?>
                    <div class="mini-card">
                        <div class="eyebrow"><?= htmlspecialchars($mini['label'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="mini-value"><?= htmlspecialchars($mini['value'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="soft"><?= htmlspecialchars($mini['note'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (admin_default_password_active()): ?>
                    <div class="mini-card" style="border-color: rgba(180, 83, 9, 0.24); background: var(--warn-soft);">
                        <div class="eyebrow" style="color: var(--warn);">安全警告</div>
                        <div class="soft" style="color: var(--warn); font-size: 11px;">系统检测到默认密码仍在使用。建议尽快配置 <span class="mono" style="color: var(--warn);">IMAGE_API_ADMIN_PASSWORD</span> 环境变量。</div>
                    </div>
                <?php endif; ?>
            </div>
        </aside>

        <main class="main">
            <section class="panel topbar" id="overview">
                <div class="topbar-copy">
                    <div class="eyebrow">IMAGE API SERVER</div>
                    <h1><?= htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') ?></h1>
                    <div class="muted" style="font-size: 13px;"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($badges)): ?>
                        <div class="chip-row" style="margin-top: 12px;">
                            <?php foreach ($badges as $badge): ?>
                                <div class="chip"><?= htmlspecialchars($badge, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="topbar-actions">
                    <?php if ($isLoggedIn): ?>
                        <div class="badge" style="background: var(--panel-soft); color: var(--ink); border-color: var(--line);">在线</div>
                        <form method="post">
                            <input type="hidden" name="action" value="logout">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                            <button class="secondary" type="submit" style="padding: 8px 12px; font-size: 12px;">登出</button>
                        </form>
                    <?php else: ?>
                        <div class="badge" style="background: var(--danger-soft); color: var(--danger); border-color: rgba(190, 18, 60, 0.2);">锁定</div>
                    <?php endif; ?>
                </div>
            </section>
<?php
}

function admin_render_layout_end(): void
{
    ?>
        </main>
    </div>
    <script>
    (() => {
        const storageKey = 'image_api_admin_ui_settings_v1';
        const defaults = { theme: 'system', font: 'system', fontSize: 16, backgroundImage: '' };
        const listeners = new Set();
        const fontMap = {
            system: 'system-ui, -apple-system, "Segoe UI", Roboto, "Microsoft YaHei UI", "PingFang SC", sans-serif',
            modern: '"Segoe UI", "Helvetica Neue", Arial, "Microsoft YaHei UI", sans-serif',
            chinese: '"Microsoft YaHei UI", "Microsoft YaHei", "PingFang SC", "Hiragino Sans GB", sans-serif',
            serif: 'Georgia, "Times New Roman", "Noto Serif SC", serif'
        };

        const normalizeSettings = (value) => {
            const theme = ['light', 'dark', 'system'].includes(value?.theme) ? value.theme : defaults.theme;
            const font = Object.prototype.hasOwnProperty.call(fontMap, value?.font) ? value.font : defaults.font;
            const fontSize = Number.isFinite(Number(value?.fontSize))
                ? Math.min(20, Math.max(14, Number(value.fontSize)))
                : defaults.fontSize;
            const backgroundImage = typeof value?.backgroundImage === 'string' && value.backgroundImage.startsWith('data:image/')
                ? value.backgroundImage.slice(0, 3_500_000)
                : '';
            return { theme, font, fontSize, backgroundImage };
        };

        const readSettings = () => {
            try {
                const raw = window.localStorage.getItem(storageKey);
                return raw ? normalizeSettings(JSON.parse(raw)) : { ...defaults };
            } catch (error) {
                return { ...defaults };
            }
        };

        const resolveTheme = (theme) => {
            if (theme === 'system') {
                return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            return theme;
        };

        const applySettings = (settings) => {
            const next = normalizeSettings(settings);
            const root = document.documentElement;
            root.dataset.adminTheme = resolveTheme(next.theme);
            root.dataset.adminThemeMode = next.theme;
            root.dataset.adminFont = next.font;
            root.style.setProperty('--font-sans', fontMap[next.font]);
            root.style.setProperty('--font-size-base', `${next.fontSize}px`);
            root.style.setProperty('--bg-image-layer', next.backgroundImage ? `url("${next.backgroundImage}")` : 'none');
            return next;
        };

        let currentSettings = applySettings(readSettings());

        const persistSettings = (settings) => {
            currentSettings = applySettings(settings);
            try {
                window.localStorage.setItem(storageKey, JSON.stringify(currentSettings));
            } catch (error) {
                // Ignore storage write failures and keep the in-memory state.
            }
            listeners.forEach((listener) => listener({ ...currentSettings }));
            return { ...currentSettings };
        };

        const media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
        if (media) {
            const syncWithSystem = () => {
                if (currentSettings.theme === 'system') {
                    applySettings(currentSettings);
                    listeners.forEach((listener) => listener({ ...currentSettings }));
                }
            };
            if (typeof media.addEventListener === 'function') {
                media.addEventListener('change', syncWithSystem);
            } else if (typeof media.addListener === 'function') {
                media.addListener(syncWithSystem);
            }
        }

        window.AdminUiSettings = {
            defaults: { ...defaults },
            fontOptions: { ...fontMap },
            get() {
                return { ...currentSettings };
            },
            set(partial) {
                return persistSettings({ ...currentSettings, ...partial });
            },
            reset() {
                return persistSettings({ ...defaults });
            },
            subscribe(listener) {
                if (typeof listener !== 'function') {
                    return () => {};
                }
                listeners.add(listener);
                listener({ ...currentSettings });
                return () => listeners.delete(listener);
            }
        };
        window.dispatchEvent(new CustomEvent('admin-ui-settings-ready', {
            detail: window.AdminUiSettings.get()
        }));

        const autoRefreshSeconds = Number(document.body.dataset.autoRefresh || '0');
        if (autoRefreshSeconds > 0) {
            window.setInterval(() => {
                if (document.visibilityState === 'visible') {
                    window.location.reload();
                }
            }, autoRefreshSeconds * 1000);
        }

        document.querySelectorAll('.per-page-select').forEach((select) => {
            select.addEventListener('change', () => {
                if (select.dataset.asyncPagination === '1') {
                    return;
                }
                const url = new URL(window.location.href);
                const queryKey = select.dataset.queryKey;
                if (!queryKey) {
                    return;
                }
                url.searchParams.set(queryKey, select.value);
                if (queryKey === 'per_page') {
                    url.searchParams.set('page', '1');
                }
                if (queryKey === 'access_per_page') {
                    url.searchParams.set('access_page', '1');
                }
                if (queryKey === 'upload_per_page') {
                    url.searchParams.set('upload_page', '1');
                }
                window.location.href = url.toString();
            });
        });
    })();
    </script>
</body>
</html>
<?php
}

function admin_default_custom_api_template(): string
{
    return <<<'JSON'
{
  "code": 200,
  "message": "ok",
  "data": {
    "api": {{id}},
    "name": {{name}},
    "library": {{library}},
    "mode": {{mode}},
    "file_name": {{file_name}},
    "path": {{path}},
    "url": {{url}},
    "preview": {{preview}},
    "width": {{width}},
    "height": {{height}},
    "size": {{size}},
    "timestamp": {{timestamp}}
  }
}
JSON;
}

function admin_normalize_custom_api_id(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/[^a-z0-9_-]+/', '-', $normalized);
    $normalized = trim((string) $normalized, '-_');
    return $normalized;
}

function admin_normalize_custom_api_record(array $record, array $existing = []): array
{
    $id = admin_normalize_custom_api_id((string) ($record['id'] ?? $record['slug'] ?? $existing['id'] ?? ''));
    $name = trim((string) ($record['name'] ?? $existing['name'] ?? ''));

    if ($id === '' && $name !== '') {
        $id = admin_normalize_custom_api_id($name);
    }
    if ($id === '') {
        $id = 'api-' . substr(hash('sha256', json_encode($record) . microtime(true)), 0, 8);
    }
    if ($name === '') {
        $name = strtoupper($id);
    }

    $library = (string) ($record['library'] ?? $record['source'] ?? $existing['library'] ?? 'all');
    if (!in_array($library, ['all', 'pc', 'm'], true)) {
        $library = 'all';
    }

    $mode = (string) ($record['mode'] ?? $existing['mode'] ?? 'random');
    if (!in_array($mode, ['random', 'sequential'], true)) {
        $mode = 'random';
    }

    $template = trim((string) ($record['template'] ?? $existing['template'] ?? ''));
    if ($template === '') {
        $template = admin_default_custom_api_template();
    }

    $createdAt = (string) ($existing['created_at'] ?? $record['created_at'] ?? date('c'));
    $updatedAt = (string) ($record['updated_at'] ?? $existing['updated_at'] ?? date('c'));

    return [
        'id' => $id,
        'name' => $name,
        'library' => $library,
        'mode' => $mode,
        'template' => $template,
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
    ];
}

function admin_read_custom_api_catalog(): array
{
    if (!is_file(ADMIN_CUSTOM_API_FILE)) {
        return [];
    }

    $raw = file_get_contents(ADMIN_CUSTOM_API_FILE);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $items = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }
        $items[] = admin_normalize_custom_api_record($item, $item);
    }

    usort($items, static function (array $left, array $right): int {
        return strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
    });

    return $items;
}

function admin_write_custom_api_catalog(array $items): void
{
    file_put_contents(
        ADMIN_CUSTOM_API_FILE,
        json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function admin_find_custom_api(array $catalog, string $id): ?array
{
    foreach ($catalog as $item) {
        if (($item['id'] ?? '') === $id) {
            return $item;
        }
    }

    return null;
}

function admin_custom_api_upsert(array $catalog, array $payload, ?string $editingId = null): array
{
    $existing = $editingId !== null ? (admin_find_custom_api($catalog, $editingId) ?? []) : [];
    $record = admin_normalize_custom_api_record($payload, $existing);
    $record['updated_at'] = date('c');

    if ($editingId !== null && $editingId !== '' && $editingId !== $record['id']) {
        foreach ($catalog as $item) {
            if (($item['id'] ?? '') === $record['id']) {
                throw new RuntimeException('API 标识已存在，请更换一个名称。');
            }
        }
    } elseif ($editingId === null || $editingId === '') {
        foreach ($catalog as $item) {
            if (($item['id'] ?? '') === $record['id']) {
                throw new RuntimeException('API 标识已存在，请更换一个名称。');
            }
        }
    }

    $updated = [];
    $replaced = false;

    foreach ($catalog as $item) {
        if (($item['id'] ?? '') === $editingId) {
            $updated[] = $record;
            $replaced = true;
            continue;
        }
        $updated[] = $item;
    }

    if (!$replaced) {
        $updated[] = $record;
    }

    usort($updated, static function (array $left, array $right): int {
        return strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
    });

    return [$updated, $record];
}

function admin_custom_api_delete(array $catalog, string $id): array
{
    return array_values(array_filter(
        $catalog,
        static fn(array $item): bool => ($item['id'] ?? '') !== $id
    ));
}

function admin_public_base_path(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $directory = str_replace('\\', '/', dirname($scriptName));
    if ($directory === '/' || $directory === '.') {
        return '';
    }

    if (str_ends_with($directory, '/admin')) {
        $directory = substr($directory, 0, -6);
    }

    return rtrim($directory, '/');
}

function admin_public_base_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $scheme = $isHttps ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));

    return $scheme . '://' . $host . admin_public_base_path();
}

function admin_custom_api_endpoint_url(string $id): string
{
    return '../?type=json';
}

function admin_custom_api_public_asset_path(array $image): string
{
    $prefix = ($image['library'] ?? '') === 'pc' ? 'pc/img/' : 'm/img/';
    return $prefix . image_api_encode_public_path((string) ($image['relative_path'] ?? ''));
}

function admin_custom_api_preview_asset_path(array $image): string
{
    return admin_preview_url(
        (string) ($image['library'] ?? 'pc'),
        (string) ($image['relative_path'] ?? ''),
        (int) ($image['modified_at'] ?? time())
    );
}

function admin_custom_api_images_for_library(string $library, ?array $libraryItems = null): array
{
    global $libraryConfigs;

    static $cached = null;
    if ($libraryItems === null) {
        if ($cached === null) {
            $cached = [];
            foreach ($libraryConfigs as $libraryKey => $config) {
                $cached[$libraryKey] = admin_scan_library($config['root_dir'], $libraryKey);
            }
        }
        $libraryItems = $cached;
    }

    if ($library === 'pc' || $library === 'm') {
        return array_values($libraryItems[$library] ?? []);
    }

    $items = array_merge($libraryItems['pc'] ?? [], $libraryItems['m'] ?? []);
    usort($items, static fn(array $left, array $right): int => $right['modified_at'] <=> $left['modified_at']);
    return $items;
}

function admin_custom_api_sample_image(array $api, ?array $libraryItems = null): ?array
{
    $items = admin_custom_api_images_for_library((string) ($api['library'] ?? 'all'), $libraryItems);
    return $items[0] ?? null;
}

function admin_custom_api_payload(array $api, ?array $image): array
{
    $image = $image ?? [];
    $path = $image !== [] ? admin_custom_api_public_asset_path($image) : '';
    $url = $path !== '' ? admin_public_base_url() . '/' . ltrim($path, '/') : '';
    $library = (string) ($image['library'] ?? $api['library'] ?? 'all');
    $libraryLabel = match ($library) {
        'pc' => 'pc',
        'm' => 'mobile',
        default => 'all',
    };

    return [
        'id' => (string) ($api['id'] ?? ''),
        'name' => (string) ($api['name'] ?? ''),
        'library' => $libraryLabel,
        'mode' => (string) ($api['mode'] ?? 'random'),
        'file_name' => (string) ($image['basename'] ?? ''),
        'path' => $path,
        'url' => $url,
        'preview' => $url,
        'width' => (int) ($image['width'] ?? 0),
        'height' => (int) ($image['height'] ?? 0),
        'size' => (int) ($image['size'] ?? 0),
        'timestamp' => date('c'),
    ];
}

function admin_render_custom_api_template(string $template, array $payload): string
{
    $rendered = $template;
    foreach ($payload as $key => $value) {
        $rendered = str_replace('{{' . $key . '}}', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rendered);
    }

    return $rendered;
}

function admin_custom_api_preview(array $api, ?array $image): array
{
    $payload = admin_custom_api_payload($api, $image);
    $rendered = admin_render_custom_api_template((string) ($api['template'] ?? ''), $payload);
    $decoded = json_decode($rendered, true);

    return [
        'payload' => $payload,
        'preview' => $decoded === null && strtolower(trim($rendered)) !== 'null'
            ? $rendered
            : json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        'is_valid_json' => $decoded !== null || strtolower(trim($rendered)) === 'null',
        'endpoint' => admin_custom_api_endpoint_url((string) ($api['id'] ?? '')),
    ];
}

function admin_render_auth_page_start(string $title, array $context = []): void
{
    $eyebrow = $context['eyebrow'] ?? 'IMAGE API';
    $headline = $context['headline'] ?? '';
    $description = $context['description'] ?? '';
    $stepLabel = $context['step_label'] ?? '';
    $stepTitle = $context['step_title'] ?? '';
    $stepIndex = max(0, (int) ($context['step_index'] ?? 0));
    $stepTotal = max(0, (int) ($context['step_total'] ?? 0));
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
    <meta name="csrf-token" content="<?= htmlspecialchars(admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <script>
        (() => {
            const storageKey = 'image_api_admin_ui_settings_v1';
            const defaults = { theme: 'system', font: 'system', fontSize: 16, backgroundImage: '' };
            const fontMap = {
                system: 'system-ui, -apple-system, "Segoe UI", Roboto, "Microsoft YaHei UI", "PingFang SC", sans-serif',
                modern: '"Segoe UI", "Helvetica Neue", Arial, "Microsoft YaHei UI", sans-serif',
                chinese: '"Microsoft YaHei UI", "Microsoft YaHei", "PingFang SC", "Hiragino Sans GB", sans-serif',
                serif: 'Georgia, "Times New Roman", "Noto Serif SC", serif'
            };

            const normalize = (value) => {
                const theme = ['light', 'dark', 'system'].includes(value?.theme) ? value.theme : defaults.theme;
                const font = Object.prototype.hasOwnProperty.call(fontMap, value?.font) ? value.font : defaults.font;
                const fontSize = Number.isFinite(Number(value?.fontSize))
                    ? Math.min(20, Math.max(14, Number(value.fontSize)))
                    : defaults.fontSize;
                const backgroundImage = typeof value?.backgroundImage === 'string' && value.backgroundImage.startsWith('data:image/')
                    ? value.backgroundImage.slice(0, 3_500_000)
                    : '';
                return { theme, font, fontSize, backgroundImage };
            };

            let stored = defaults;
            try {
                const raw = window.localStorage.getItem(storageKey);
                stored = raw ? normalize(JSON.parse(raw)) : defaults;
            } catch (error) {
                stored = defaults;
            }

            const resolvedTheme = stored.theme === 'system'
                ? (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : stored.theme;

            const root = document.documentElement;
            root.dataset.adminTheme = resolvedTheme;
            root.dataset.adminThemeMode = stored.theme;
            root.dataset.adminFont = stored.font;
            root.style.setProperty('--font-sans', fontMap[stored.font]);
            root.style.setProperty('--font-size-base', `${stored.fontSize}px`);
            root.style.setProperty('--bg-image-layer', stored.backgroundImage ? `url("${stored.backgroundImage}")` : 'none');
        })();
    </script>
    <style>
        :root {
            --bg: #f3f5f7;
            --panel: #ffffff;
            --panel-soft: #f5f7fa;
            --ink: #111111;
            --muted: #5f6b7a;
            --line: #dde3ea;
            --line-strong: #c5d0db;
            --accent: #111111;
            --accent-soft: #e9eef3;
            --success: #0f766e;
            --danger: #be123c;
            --warn: #b45309;
            --success-soft: #ecfdf5;
            --danger-soft: #fef2f2;
            --warn-soft: #fffbeb;
            --input-bg: #ffffff;
            --button-primary-bg: #111111;
            --button-primary-text: #ffffff;
            --button-primary-hover: #222222;
            --bg-image-layer: none;
            --shadow: 0 18px 38px rgba(15, 23, 42, 0.07);
            --radius-lg: 6px;
            --radius-md: 4px;
            --radius-sm: 2px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            color: var(--ink);
            background:
                linear-gradient(rgba(243, 245, 247, 0.84), rgba(243, 245, 247, 0.92)),
                var(--bg-image-layer),
                radial-gradient(circle at top, rgba(255, 255, 255, 0.8), transparent 42%),
                linear-gradient(180deg, var(--bg), #edf1f5);
            background-position: center, center, center, center;
            background-repeat: no-repeat, no-repeat, no-repeat, no-repeat;
            background-size: cover, cover, auto, auto;
            background-attachment: fixed, fixed, fixed, fixed;
            background-color: var(--bg);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }

        .auth-shell {
            width: min(100%, 580px); /* Scaled up auth card container */
            display: grid;
            gap: 24px;
        }

        .auth-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            padding: 42px; /* Scaled up padding */
        }

        .auth-hero {
            display: grid;
            gap: 8px;
            margin-bottom: 28px;
            text-align: center;
        }

        .auth-eyebrow {
            font-size: 11px; /* Scaled up eyebrow */
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .auth-title {
            margin: 0;
            font-size: 26px; /* Scaled up header */
            line-height: 1.2;
            font-weight: 600;
            letter-spacing: -0.02em;
        }

        .auth-copy {
            color: var(--muted);
            font-size: 14.5px; /* Scaled up description font */
            line-height: 1.6;
            margin-top: 6px;
        }

        .auth-step {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: var(--radius-sm);
            background: var(--accent-soft);
            color: var(--ink);
            font-size: 12.5px; /* Scaled up step label */
            font-weight: 600;
            margin: 8px auto 0;
            border: 1px solid var(--line);
        }

        .auth-step strong {
            font-size: 11px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .auth-grid {
            display: block;
        }

        .auth-panel {
            border: none;
            background: transparent;
            padding: 0;
        }

        .auth-panel h2 {
            margin: 0 0 10px;
            font-size: 16px;
            line-height: 1.2;
            font-weight: 600;
            color: var(--ink);
            display: none; /* Hide redundant connection headers */
        }

        .auth-note {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
            display: none; /* Hide redundant connection details */
        }

        .auth-stack {
            display: grid;
            gap: 16px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 12.5px; /* Scaled up labels */
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            font-weight: 600;
        }

        input, select, button {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: 12px 14px; /* Scaled up paddings */
            font: inherit;
            color: var(--ink);
            background: var(--input-bg);
            font-size: 14.5px; /* Scaled up text */
        }

        input:hover, select:hover {
            border-color: var(--line-strong);
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(17, 17, 17, 0.08);
        }

        button {
            cursor: pointer;
            background: var(--button-primary-bg);
            color: var(--button-primary-text);
            font-weight: 600;
            letter-spacing: 0.02em;
            transition: background 120ms ease;
            border: 1px solid var(--button-primary-bg);
            box-shadow: none;
            padding: 13px 15px; /* Scaled up submit button padding */
        }

        button:hover {
            background: var(--button-primary-hover);
        }

        .auth-back-link {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            width: 100%;
            padding: 12px 14px; /* Scaled up padding */
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            background: var(--panel);
            color: var(--ink);
            text-decoration: none;
            font-weight: 600;
            font-size: 14.5px; /* Scaled up back link */
            transition: background 120ms ease, border-color 120ms ease;
        }

        .auth-back-link:hover {
            background: var(--accent-soft);
            border-color: var(--line-strong);
        }

        .notice {
            padding: 12px 14px;
            border-radius: var(--radius-md);
            font-size: 14.5px; /* Scaled up notices font */
            line-height: 1.6;
            margin-bottom: 14px;
        }

        .notice.success { background: var(--success-soft); color: var(--success); border: 1px solid rgba(15, 118, 110, 0.18); }
        .notice.error { background: var(--danger-soft); color: var(--danger); border: 1px solid rgba(190, 18, 60, 0.18); }
        .notice.warning { background: var(--warn-soft); color: var(--warn); border: 1px solid rgba(180, 83, 9, 0.18); }

        .stat-list {
            display: grid;
            gap: 10px;
        }

        .stat-box {
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: 12px;
            background: var(--panel);
        }

        .stat-label {
            font-size: 10px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 600;
        }

        .stat-value {
            margin: 4px 0 2px;
            font-size: 18px;
            line-height: 1.2;
            font-weight: 600;
            font-family: "JetBrains Mono", Consolas, monospace;
            color: var(--ink);
        }

        .mono {
            font-family: "JetBrains Mono", Consolas, monospace;
            font-size: 11.5px;
            word-break: break-all;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0d1117;
                --panel: #131922;
                --panel-soft: #10161e;
                --ink: #edf2f7;
                --muted: #9ca9b8;
                --line: #263241;
                --line-strong: #3a4a5e;
                --accent: #f1f5f9;
                --accent-soft: #1f2a38;
                --success: #6ee7b7;
                --danger: #fda4af;
                --warn: #fcd34d;
                --success-soft: rgba(16, 185, 129, 0.14);
                --danger-soft: rgba(244, 63, 94, 0.14);
                --warn-soft: rgba(245, 158, 11, 0.16);
                --input-bg: #0f151d;
                --button-primary-bg: #eef2f7;
                --button-primary-text: #10161d;
                --button-primary-hover: #dbe4ef;
                --shadow: 0 18px 42px rgba(0, 0, 0, 0.34);
            }

            body {
                background:
                    linear-gradient(rgba(13, 17, 23, 0.84), rgba(13, 17, 23, 0.92)),
                    var(--bg-image-layer),
                    radial-gradient(circle at top, rgba(255, 255, 255, 0.06), transparent 36%),
                    linear-gradient(180deg, var(--bg), #0b1016);
            }
        }

        @media (max-width: 600px) {
            .auth-card {
                padding: 20px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="auth-shell">
        <section class="auth-card">
            <div class="auth-hero">
                <div class="auth-eyebrow"><?= htmlspecialchars($eyebrow, ENT_QUOTES, 'UTF-8') ?></div>
                <h1 class="auth-title"><?= htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') ?></h1>
                <?php if ($description !== ''): ?>
                    <div class="auth-copy"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($stepLabel !== '' || $stepTitle !== ''): ?>
                    <div class="auth-step">
                        <strong><?= htmlspecialchars($stepLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars($stepTitle, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($stepIndex > 0 && $stepTotal > 0): ?>
                            <span><?= $stepIndex ?> / <?= $stepTotal ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="auth-grid">
<?php
}

function admin_render_auth_page_end(): void
{
    ?>
            </div>
        </section>
    </div>
</body>
</html>
<?php
}
