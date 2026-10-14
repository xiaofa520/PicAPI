<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/smtp_runtime.php';

const SITE_SETTINGS_FILE = STORAGE_DIR . '/site-settings.json';
const SITE_ICON_BASENAME = 'site-icon';
const IP_ACCESS_CONTROL_FILE = STORAGE_DIR . '/security/ip-access-control.json';

function default_notification_email_html_template(): string
{
    return '<!doctype html><html lang="zh-CN"><body data-picapi-easy-body style="margin:0;padding:32px 16px;background:#0B1020;color:#E6EDF7;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table data-picapi-easy-shell role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;min-height:360px;background:#111827;border:1px solid #334155;border-radius:16px;overflow:hidden;box-shadow:0 20px 48px rgba(2,6,23,.42)"><tr><td data-picapi-easy-header style="padding:26px 30px;background:#312E81;border-bottom:3px solid #6366F1"><strong data-picapi-easy-brand style="color:#C7D2FE;font-size:18px">{{site_name}}</strong><h1 style="margin:18px 0 0;color:#FFFFFF;font-size:22px">{{title}}</h1></td></tr><tr><td style="padding:30px"><p data-picapi-easy-message style="margin:0 0 20px;color:#D7E0EF;line-height:1.75">{{message}}</p><table data-picapi-easy-info role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #334155;border-radius:10px;background:#0F172A"><tr><td data-picapi-easy-info-label style="padding:12px;color:#93C5FD;font-size:13px">来源 IP</td><td data-picapi-easy-info-value style="padding:12px;color:#F8FAFC;font:13px ui-monospace,SFMono-Regular,Menlo,monospace;text-align:right">{{ip}}</td></tr><tr><td data-picapi-easy-info-label style="padding:12px;color:#93C5FD;font-size:13px">发生时间</td><td data-picapi-easy-info-value style="padding:12px;color:#F8FAFC;font-size:13px;text-align:right">{{time}}</td></tr></table><p data-picapi-easy-detail style="margin:20px 0 0;color:#94A3B8;font-size:12px">{{detail}}</p></td></tr></table></td></tr></table></body></html>';
}

function default_password_reset_email_html_template(): string
{
    return '<!doctype html><html lang="zh-CN"><body data-picapi-easy-body style="margin:0;padding:32px 16px;background:#071A1A;color:#E6FFFB;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table data-picapi-easy-shell role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;min-height:360px;background:#102322;border:1px solid #285C59;border-radius:16px;overflow:hidden;box-shadow:0 20px 48px rgba(2,44,34,.42)"><tr><td data-picapi-easy-header style="padding:26px 30px;background:#0F766E;border-bottom:3px solid #2DD4BF"><strong data-picapi-easy-brand style="color:#99F6E4;font-size:18px">{{site_name}}</strong><h1 style="margin:18px 0 0;color:#ECFDF5;font-size:22px">{{title}}</h1></td></tr><tr><td style="padding:30px"><p data-picapi-easy-message style="margin:0 0 20px;color:#D1FAE5;line-height:1.75">{{message}}</p><div data-picapi-easy-code style="margin:20px 0;padding:18px;border:1px solid #38BDF8;border-radius:10px;background:#0C4A6E;color:#E0F2FE;text-align:center;font-size:30px;font-weight:700;letter-spacing:10px">{{code}}</div><p data-picapi-easy-detail style="margin:20px 0 0;color:#99B9B3;font-size:12px">{{detail}}</p></td></tr></table></td></tr></table></body></html>';
}

/** @return array<string,mixed> */
function default_site_settings(): array
{
    return [
        'site_name' => 'PicAPI',
        'site_url' => '',
        'site_description' => 'PicAPI 是自托管随机图片服务，支持按设备与分类提供图片、URL 和 JSON 接口，并提供图库管理、访问统计与访问控制。',
        'seo_title' => 'PicAPI 随机图片 API｜自托管图片服务',
        'seo_description' => '自托管随机图片 API，支持 PC 与手机图片、分类调用、图片 URL 和 JSON 响应，并提供图库管理、访问控制及调用统计。',
        'seo_keywords' => '随机图片 API,图片接口,图片 API,图片管理,自托管图片服务,图片随机接口,PicAPI',
        'admin_session_days' => 7,
        'adaptive_breakpoint' => 768,
        'daily_summary' => false,
        'font_size' => 'normal',
        'theme_mode' => 'light',
        'login_background_type' => 'color',
        'login_background_light_color' => '#F5F7FB',
        'login_background_dark_color' => '#0F172A',
        'login_background_opacity' => 55,
        'login_background_blur' => 0,
        'login_background_image' => '',
        'theme_color' => '#6366f1',
        'theme_background' => '#0F172A',
        'theme_surface' => '#111827',
        'theme_text' => '#F8FAFC',
        'theme_muted' => '#94A3B8',
        'dark_theme_color' => '#6366F1',
        'dark_theme_background' => '#0F172A',
        'dark_theme_surface' => '#111827',
        'dark_theme_text' => '#F8FAFC',
        'dark_theme_muted' => '#94A3B8',
        'light_theme_color' => '#1974D2',
        'light_theme_background' => '#FFFFFF',
        'light_theme_surface' => '#FFFFFF',
        'light_theme_text' => '#2C3B50',
        'light_theme_muted' => '#728198',
        'notification_email' => '',
        'email_subject_template' => '[{{site_name}}] {{title}}',
        'notification_email_html_template' => default_notification_email_html_template(),
        'password_reset_email_html_template' => default_password_reset_email_html_template(),
        'login_notification' => true,
        'password_breach_alert' => true,
        'request_rate_alert' => true,
        'request_window_seconds' => 60,
        'request_threshold' => 100,
        'ip_blacklist_threshold' => 100,
        'access_log_auto_cleanup' => false,
        'access_log_retention_months' => 12,
        'upload_log_auto_cleanup' => false,
        'upload_log_retention_months' => 12,
        'login_log_auto_cleanup' => false,
        'login_log_retention_months' => 12,
        'force_admin_https' => false,
        'developer_mode' => false,
    ];
}

/** @param array<string,mixed> $source @return array<string,mixed> */
function normalize_site_settings(array $source): array
{
    $settings = default_site_settings();
    $siteName = trim((string) ($source['site_name'] ?? $settings['site_name']));
    $siteNameLength = function_exists('mb_strlen') ? mb_strlen($siteName, 'UTF-8') : strlen($siteName);
    $settings['site_name'] = $siteName !== '' && $siteNameLength <= 80 ? $siteName : $settings['site_name'];
    $url = trim((string) ($source['site_url'] ?? ''));
    $settings['site_url'] = preg_match('~^https?://~i', $url) && filter_var($url, FILTER_VALIDATE_URL) ? rtrim($url, '/') : '';
    $settings['site_description'] = trim(substr((string) ($source['site_description'] ?? $settings['site_description']), 0, 1200)) ?: $settings['site_description'];
    $settings['seo_title'] = trim(substr((string) ($source['seo_title'] ?? $settings['seo_title']), 0, 200)) ?: $settings['seo_title'];
    $settings['seo_description'] = trim(substr((string) ($source['seo_description'] ?? $settings['seo_description']), 0, 500)) ?: $settings['seo_description'];
    $settings['seo_keywords'] = trim(substr((string) ($source['seo_keywords'] ?? $settings['seo_keywords']), 0, 500)) ?: $settings['seo_keywords'];
    $settings['adaptive_breakpoint'] = max(1, min(3840, (int) ($source['adaptive_breakpoint'] ?? 768)));
    $settings['daily_summary'] = !empty($source['daily_summary']);
    $days = filter_var($source['admin_session_days'] ?? 7, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]);
    $settings['admin_session_days'] = $days === false ? 7 : $days;

    $fontSize = (string) ($source['font_size'] ?? $settings['font_size']);
    $settings['font_size'] = in_array($fontSize, ['extra_small', 'small', 'normal', 'large', 'extra_large'], true) ? $fontSize : $settings['font_size'];
    $themeMode = (string) ($source['theme_mode'] ?? $settings['theme_mode']);
    $settings['theme_mode'] = in_array($themeMode, ['dark', 'light', 'auto'], true) ? $themeMode : $settings['theme_mode'];

    $loginBackgroundType = (string) ($source['login_background_type'] ?? $settings['login_background_type']);
    $settings['login_background_type'] = in_array($loginBackgroundType, ['color', 'image'], true) ? $loginBackgroundType : 'color';
    foreach (['light', 'dark'] as $mode) {
        $key = 'login_background_' . $mode . '_color';
        $color = strtoupper(trim((string) ($source[$key] ?? $settings[$key])));
        $settings[$key] = preg_match('/^#[0-9A-F]{6}$/', $color) === 1 ? $color : $settings[$key];
    }
    $settings['login_background_opacity'] = max(0, min(100, (int) ($source['login_background_opacity'] ?? $settings['login_background_opacity'])));
    $settings['login_background_blur'] = max(0, min(50, (int) ($source['login_background_blur'] ?? $settings['login_background_blur'])));
    $wallpaper = basename(str_replace('\\', '/', (string) ($source['login_background_image'] ?? '')));
    $settings['login_background_image'] = preg_match('/^login-wallpaper-[a-f0-9]{24}\\.(?:webp|jpe?g)$/', $wallpaper) === 1 ? $wallpaper : '';

    foreach (['theme_color', 'theme_background', 'theme_surface', 'theme_text', 'theme_muted'] as $key) {
        $color = strtoupper(trim((string) ($source[$key] ?? $settings[$key])));
        $settings[$key] = preg_match('/^#[0-9A-F]{6}$/', $color) === 1 ? $color : $settings[$key];
    }
    foreach (['dark', 'light'] as $mode) {
        foreach (['color', 'background', 'surface', 'text', 'muted'] as $part) {
            $key = $mode . '_theme_' . $part;
            $activeKey = 'theme_' . $part;
            $fallback = $mode === $settings['theme_mode'] && array_key_exists($activeKey, $source) ? $settings[$activeKey] : $settings[$key];
            $color = strtoupper(trim((string) ($source[$key] ?? $fallback)));
            $settings[$key] = preg_match('/^#[0-9A-F]{6}$/', $color) === 1 ? $color : $settings[$key];
        }
    }

    $recipient = trim((string) ($source['notification_email'] ?? ''));
    $settings['notification_email'] = $recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false ? $recipient : '';

    $templates = [
        'notification_email_html_template' => $source['notification_email_html_template'] ?? $source['email_html_template'] ?? $settings['notification_email_html_template'],
        'password_reset_email_html_template' => $source['password_reset_email_html_template'] ?? $settings['password_reset_email_html_template'],
    ];
    foreach (['email_subject_template'] as $key) {
        $value = (string) ($source[$key] ?? $settings[$key]);
        $settings[$key] = strlen($value) <= 100_000 && trim($value) !== '' ? $value : $settings[$key];
    }
    foreach ($templates as $key => $value) {
        $value = (string) $value;
        $settings[$key] = strlen($value) <= 100_000 && trim($value) !== '' ? $value : $settings[$key];
    }
    foreach (['login_notification', 'password_breach_alert', 'request_rate_alert', 'force_admin_https', 'developer_mode'] as $key) {
        $settings[$key] = array_key_exists($key, $source)
            ? !empty($source[$key])
            : (bool) $settings[$key];
    }
    $window = (int) ($source['request_window_seconds'] ?? $settings['request_window_seconds']);
    $settings['request_window_seconds'] = in_array($window, [60, 300, 600, 1800], true) ? $window : $settings['request_window_seconds'];
    // API rate alerts and automatic IP bans intentionally share one rate policy.
    // The legacy blacklist field is kept as a persisted compatibility mirror.
    $threshold = (int) ($source['request_threshold'] ?? $source['ip_blacklist_threshold'] ?? $settings['request_threshold']);
    $settings['request_threshold'] = in_array($threshold, [50, 100, 300, 500, 1000], true) ? $threshold : $settings['request_threshold'];
    $settings['ip_blacklist_threshold'] = $settings['request_threshold'];
    $settings['access_log_auto_cleanup'] = array_key_exists('access_log_auto_cleanup', $source)
        ? !empty($source['access_log_auto_cleanup'])
        : (bool) $settings['access_log_auto_cleanup'];
    $retentionMonths = (int) ($source['access_log_retention_months'] ?? $settings['access_log_retention_months']);
    $settings['access_log_retention_months'] = in_array($retentionMonths, [1, 3, 6, 12, 24, 36, 60], true)
        ? $retentionMonths
        : $settings['access_log_retention_months'];
    $settings['upload_log_auto_cleanup'] = array_key_exists('upload_log_auto_cleanup', $source)
        ? !empty($source['upload_log_auto_cleanup'])
        : (bool) $settings['upload_log_auto_cleanup'];
    $uploadRetentionMonths = (int) ($source['upload_log_retention_months'] ?? $settings['upload_log_retention_months']);
    $settings['upload_log_retention_months'] = in_array($uploadRetentionMonths, [1, 3, 6, 12, 24, 36, 60], true)
        ? $uploadRetentionMonths
        : $settings['upload_log_retention_months'];
    $settings['login_log_auto_cleanup'] = array_key_exists('login_log_auto_cleanup', $source)
        ? !empty($source['login_log_auto_cleanup'])
        : (bool) $settings['login_log_auto_cleanup'];
    $loginRetentionMonths = (int) ($source['login_log_retention_months'] ?? $settings['login_log_retention_months']);
    $settings['login_log_retention_months'] = in_array($loginRetentionMonths, [1, 3, 6, 12, 24, 36, 60], true)
        ? $loginRetentionMonths
        : $settings['login_log_retention_months'];
    return $settings;
}

/** @return array<string,mixed> */
function site_settings(): array
{
    $raw = is_file(SITE_SETTINGS_FILE) ? json_decode((string) file_get_contents(SITE_SETTINGS_FILE), true) : [];
    $fallback = normalize_site_settings(is_array($raw) ? $raw : []);
    $stored = database_document('site-settings');
    if (is_array($stored)) return normalize_site_settings($stored);
    // First database-enabled request imports the existing JSON configuration.
    save_database_document('site-settings', $fallback);
    return $fallback;
}

/** @param array<string,mixed> $input @return array<string,mixed> */
function save_site_settings(array $input): array
{
    if (array_key_exists('admin_session_days', $input) && filter_var($input['admin_session_days'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]) === false) {
        throw new InvalidArgumentException('登录凭证有效期必须为 1～30 天的整数。');
    }
    initialize_storage();
    $settings = normalize_site_settings($input);
    if (runtime_state_database() !== null) {
        if (!save_database_document('site-settings', $settings)) throw new RuntimeException('无法保存站点设置到数据库。');
        return $settings;
    }
    $temporary = SITE_SETTINGS_FILE . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $encoded = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($encoded === false || file_put_contents($temporary, $encoded, LOCK_EX) === false || !rename($temporary, SITE_SETTINGS_FILE)) {
        @unlink($temporary);
        throw new RuntimeException('无法保存站点设置。');
    }
    return $settings;
}

/** @param array<string,mixed> $changes @return array<string,mixed> */
function update_site_settings(array $changes): array
{
    $settings = save_site_settings(array_replace(site_settings(), $changes));
    if (array_key_exists('admin_session_days', $changes)) refresh_admin_session_cookie();
    return $settings;
}

/**
 * Deletes only dated access-log files. Request counters, request summaries,
 * and compatible count archives deliberately remain untouched.
 */
function purge_expired_access_logs(bool $force = false): int
{
    $settings = site_settings();
    if (empty($settings['access_log_auto_cleanup'])) return 0;

    $months = (int) ($settings['access_log_retention_months'] ?? 12);
    if (!in_array($months, [1, 3, 6, 12, 24, 36, 60], true)) return 0;

    $logRoot = STORAGE_DIR . '/logs';
    ensure_directory($logRoot);
    $lock = @fopen($logRoot . '/.retention-cleanup.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return 0;
    }

    try {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $today = new DateTimeImmutable('today', $timezone);
        $statePath = $logRoot . '/.retention-cleanup.json';
        $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : [];
        $state = is_array($state) ? $state : [];
        if (!$force
            && ($state['day'] ?? '') === $today->format('Y-m-d')
            && (int) ($state['months'] ?? 0) === $months) {
            return 0;
        }

        $cutoff = $today->modify('-' . $months . ' months');
        $targets = [
            ['root' => $logRoot, 'extension' => 'jsonl'],
            ['root' => $logRoot . '/access', 'extension' => 'jsonl'],
            ['root' => STORAGE_DIR . '/logs/endpoints/auto', 'extension' => 'log'],
            ['root' => STORAGE_DIR . '/logs/endpoints/pc', 'extension' => 'log'],
            ['root' => STORAGE_DIR . '/logs/endpoints/mobile', 'extension' => 'log'],
        ];
        $deleted = 0;
        foreach ($targets as $target) {
            $root = $target['root'];
            if (!is_dir($root)) continue;
            $pattern = $root . '/*/*/*.' . $target['extension'];
            foreach (glob($pattern) ?: [] as $path) {
                $relative = str_replace('\\', '/', substr($path, strlen(rtrim($root, '\\/')) + 1));
                if (preg_match('~^(\\d{4})/(\\d{2})/(\\d{2})\\.' . $target['extension'] . '$~', $relative, $matches) !== 1) continue;
                try {
                    $day = new DateTimeImmutable($matches[1] . '-' . $matches[2] . '-' . $matches[3], $timezone);
                    if ($day < $cutoff && @unlink($path)) $deleted++;
                } catch (Throwable) {
                    // Leave malformed paths untouched rather than deleting an unexpected file.
                }
            }
        }
        $pdo = runtime_state_database();
        if ($pdo !== null) {
            $statement = $pdo->prepare('DELETE FROM picapi_request_logs WHERE log_day < :cutoff');
            $statement->execute(['cutoff' => $cutoff->format('Y-m-d')]);
            $deleted += $statement->rowCount();
        }

        $state = ['day' => $today->format('Y-m-d'), 'months' => $months, 'updated_at' => gmdate('c')];
        $temporary = $statePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
            @rename($temporary, $statePath);
        } else {
            @unlink($temporary);
        }
        return $deleted;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Deletes only dated upload-audit records. Original images and thumbnails are
 * deliberately outside this directory and are never touched by this policy.
 */
function purge_expired_upload_audit_logs(bool $force = false): int
{
    $settings = site_settings();
    if (empty($settings['upload_log_auto_cleanup'])) return 0;

    $months = (int) ($settings['upload_log_retention_months'] ?? 12);
    if (!in_array($months, [1, 3, 6, 12, 24, 36, 60], true)) return 0;

    $root = STORAGE_DIR . '/logs/upload';
    ensure_directory($root);
    $lockRoot = STORAGE_DIR . '/logs';
    $lock = @fopen($lockRoot . '/.upload-retention-cleanup.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return 0;
    }

    try {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $today = new DateTimeImmutable('today', $timezone);
        $statePath = $lockRoot . '/.upload-retention-cleanup.json';
        $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : [];
        $state = is_array($state) ? $state : [];
        if (!$force
            && ($state['day'] ?? '') === $today->format('Y-m-d')
            && (int) ($state['months'] ?? 0) === $months) {
            return 0;
        }

        $cutoff = $today->modify('-' . $months . ' months');
        $deleted = 0;
        foreach ([$root, STORAGE_DIR . '/upload-logs'] as $sourceRoot) {
            foreach (glob($sourceRoot . '/*/*/*.jsonl') ?: [] as $path) {
                $relative = str_replace('\\', '/', substr($path, strlen(rtrim($sourceRoot, '\\/')) + 1));
                if (preg_match('~^(\\d{4})/(\\d{2})/(\\d{2})\\.jsonl$~', $relative, $matches) !== 1) continue;
                try {
                    $day = new DateTimeImmutable($matches[1] . '-' . $matches[2] . '-' . $matches[3], $timezone);
                    if ($day < $cutoff && @unlink($path)) $deleted++;
                } catch (Throwable) {
                    // Leave malformed paths untouched rather than deleting an unexpected file.
                }
            }
        }
        $pdo = runtime_state_database();
        if ($pdo !== null) {
            $statement = $pdo->prepare('DELETE FROM picapi_upload_audits WHERE log_day < :cutoff');
            $statement->execute(['cutoff' => $cutoff->format('Y-m-d')]);
            $deleted += $statement->rowCount();
        }

        $state = ['day' => $today->format('Y-m-d'), 'months' => $months, 'updated_at' => gmdate('c')];
        $temporary = $statePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
            @rename($temporary, $statePath);
        } else {
            @unlink($temporary);
        }
        return $deleted;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Deletes expired administrator login records from both local files and the database. */
function purge_expired_login_logs(bool $force = false): int
{
    $settings = site_settings();
    if (empty($settings['login_log_auto_cleanup'])) return 0;

    $months = (int) ($settings['login_log_retention_months'] ?? 12);
    if (!in_array($months, [1, 3, 6, 12, 24, 36, 60], true)) return 0;

    $logRoot = STORAGE_DIR . '/logs';
    ensure_directory($logRoot . '/login');
    $lock = @fopen($logRoot . '/.login-retention-cleanup.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return 0;
    }

    try {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $today = new DateTimeImmutable('today', $timezone);
        $statePath = $logRoot . '/.login-retention-cleanup.json';
        $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : [];
        $state = is_array($state) ? $state : [];
        if (!$force && ($state['day'] ?? '') === $today->format('Y-m-d') && (int) ($state['months'] ?? 0) === $months) return 0;

        $cutoff = $today->modify('-' . $months . ' months');
        $deleted = 0;
        $root = $logRoot . '/login';
        foreach (glob($root . '/*/*/*.jsonl') ?: [] as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen(rtrim($root, '\\/')) + 1));
            if (preg_match('~^(\\d{4})/(\\d{2})/(\\d{2})\\.jsonl$~', $relative, $matches) !== 1) continue;
            try {
                $day = new DateTimeImmutable($matches[1] . '-' . $matches[2] . '-' . $matches[3], $timezone);
                if ($day < $cutoff && @unlink($path)) $deleted++;
            } catch (Throwable) {
                // Ignore malformed filenames rather than deleting unexpected files.
            }
        }

        $pdo = runtime_state_database();
        if ($pdo !== null) {
            $statement = $pdo->prepare('DELETE FROM picapi_login_logs WHERE logged_at < :cutoff');
            $statement->execute(['cutoff' => $cutoff->getTimestamp()]);
            $deleted += $statement->rowCount();
        }

        $state = ['day' => $today->format('Y-m-d'), 'months' => $months, 'updated_at' => gmdate('c')];
        $temporary = $statePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
            @rename($temporary, $statePath);
        } else {
            @unlink($temporary);
        }
        return $deleted;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** @param array<string,mixed> $upload */
function save_site_icon_upload(array $upload): void
{
    if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
        throw new RuntimeException('站点图标上传失败，请重新选择图片。');
    }
    if ((int) ($upload['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('站点图标不能超过 5 MB。');
    $temporary = (string) $upload['tmp_name'];
    $dimensions = @getimagesize($temporary);
    $mime = is_array($dimensions) ? (string) ($dimensions['mime'] ?? '') : '';
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) throw new RuntimeException('站点图标仅支持 JPEG、PNG、GIF 或 WebP 图片。');
    $width = (int) ($dimensions[0] ?? 0);
    $height = (int) ($dimensions[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 4096 || $height > 4096) throw new RuntimeException('站点图标尺寸无效或过大。');
    $extension = $extensions[$mime];
    $staged = STORAGE_DIR . '/' . SITE_ICON_BASENAME . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    if (!move_uploaded_file($temporary, $staged)) throw new RuntimeException('无法保存站点图标。');
    foreach (glob(STORAGE_DIR . '/' . SITE_ICON_BASENAME . '.*') ?: [] as $existing) @unlink($existing);
    if (!rename($staged, STORAGE_DIR . '/' . SITE_ICON_BASENAME . '.' . $extension)) {
        @unlink($staged);
        throw new RuntimeException('无法启用站点图标。');
    }
    sync_site_favicon_root();
}

/** @param array<string,mixed> $original @param array<string,mixed> $compressed */
function save_site_icon_upload_pair(array $original, array $compressed, bool $uploadedOnly = true): void
{
    $readUpload = static function (array $upload, string $label, int $maxBytes, array $allowedMimes) use ($uploadedOnly): array {
        $temporary = (string) ($upload['tmp_name'] ?? '');
        if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($uploadedOnly ? !is_uploaded_file($temporary) : !is_file($temporary))) {
            throw new RuntimeException($label . '上传失败，请重新选择图片。');
        }
        $uploadSize = (int) ($upload['size'] ?? 0);
        if ($uploadSize < 1 || $uploadSize > $maxBytes) {
            throw new RuntimeException($label . '大小超出限制。');
        }
        $dimensions = @getimagesize($temporary);
        $mime = is_array($dimensions) ? (string) ($dimensions['mime'] ?? '') : '';
        if (!in_array($mime, $allowedMimes, true)) throw new RuntimeException($label . '格式不受支持。');
        $width = (int) ($dimensions[0] ?? 0);
        $height = (int) ($dimensions[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > 8192 || $height > 8192) throw new RuntimeException($label . '尺寸无效或过大。');
        return [$mime, $width, $height];
    };

    [$originalMime] = $readUpload($original, '网站图标原图', 5 * 1024 * 1024, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
    [$compressedMime, $compressedWidth, $compressedHeight] = $readUpload($compressed, '网站图标压缩图', 1024 * 1024, ['image/png', 'image/webp']);
    if ($compressedWidth !== $compressedHeight || $compressedWidth > 512) throw new RuntimeException('压缩图必须是 512 像素以内的正方形图片。');

    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $originalTarget = STORAGE_DIR . '/' . SITE_ICON_BASENAME . '-original.' . $extensions[$originalMime];
    $compressedTarget = STORAGE_DIR . '/' . SITE_ICON_BASENAME . '.' . ($compressedMime === 'image/webp' ? 'webp' : 'png');
    $nonce = bin2hex(random_bytes(6));
    $originalStage = STORAGE_DIR . '/.' . SITE_ICON_BASENAME . '-original-' . $nonce . '.tmp';
    $compressedStage = STORAGE_DIR . '/.' . SITE_ICON_BASENAME . '-compressed-' . $nonce . '.tmp';
    $backups = [];
    $installed = [];

    try {
        $storeTemporary = static fn (string $source, string $destination): bool => $uploadedOnly ? move_uploaded_file($source, $destination) : copy($source, $destination);
        if (!$storeTemporary((string) $original['tmp_name'], $originalStage)) throw new RuntimeException('无法暂存网站图标原图。');
        if (!$storeTemporary((string) $compressed['tmp_name'], $compressedStage)) throw new RuntimeException('无法暂存网站图标压缩图。');
        $existing = [];
        foreach (glob(STORAGE_DIR . '/' . SITE_ICON_BASENAME . '-original.*') ?: [] as $path) {
            if (preg_match('/^site-icon-original\.(?:jpg|png|gif|webp)$/i', basename($path))) $existing[] = $path;
        }
        foreach (glob(STORAGE_DIR . '/' . SITE_ICON_BASENAME . '.*') ?: [] as $path) {
            if (preg_match('/^site-icon\.(?:jpg|png|gif|webp)$/i', basename($path))) $existing[] = $path;
        }
        foreach (array_unique($existing) as $path) {
            $backup = $path . '.replace-' . $nonce;
            if (!rename($path, $backup)) throw new RuntimeException('无法替换现有网站图标。');
            $backups[$path] = $backup;
        }
        if (!rename($originalStage, $originalTarget)) throw new RuntimeException('无法保存网站图标原图。');
        $installed[] = $originalTarget;
        if (!rename($compressedStage, $compressedTarget)) throw new RuntimeException('无法启用网站图标压缩图。');
        $installed[] = $compressedTarget;
        sync_site_favicon_root();
        foreach ($backups as $backup) @unlink($backup);
    } catch (Throwable $error) {
        @unlink($originalStage);
        @unlink($compressedStage);
        foreach ($installed as $path) @unlink($path);
        foreach ($backups as $path => $backup) {
            if (is_file($backup)) @rename($backup, $path);
        }
        throw $error;
    }
}

/** @return array<string,mixed> */
function smtp_settings(): array
{
    $installation = installation_config() ?? [];
    $fallback = is_array($installation['smtp'] ?? null) ? $installation['smtp'] : [];
    $stored = database_document('smtp-settings');
    if (is_array($stored) && trim((string) ($stored['host'] ?? '')) !== '') return $stored;
    if ($fallback !== [] && runtime_state_database() !== null) save_database_document('smtp-settings', $fallback);
    return $fallback;
}

/** @param array<string,mixed> $input */
function save_smtp_settings(array $input): void
{
    $installation = installation_config() ?? [];
    $smtp = smtp_settings();
    $host = trim((string) ($input['smtp_host'] ?? $smtp['host'] ?? ''));
    $port = (int) ($input['smtp_port'] ?? $smtp['port'] ?? 465);
    $username = trim((string) ($input['smtp_username'] ?? $smtp['username'] ?? ''));
    $password = (string) ($input['smtp_password'] ?? '');
    $encryption = (string) ($input['smtp_encryption'] ?? $smtp['encryption'] ?? 'ssl');
    if (!in_array($encryption, ['ssl', 'tls'], true)) $encryption = 'ssl';
    if ($host === '' || $port < 1 || $port > 65535 || $username === '') {
        throw new RuntimeException('请完整填写 SMTP 地址、端口和账号。');
    }
    if ($password === '') $password = (string) ($smtp['password'] ?? '');
    if ($password === '') throw new RuntimeException('请填写 SMTP 授权密码。');
    $updated = ['host' => $host, 'port' => (string) $port, 'username' => $username, 'password' => $password, 'encryption' => $encryption];
    if (runtime_state_database() !== null) {
        if (!save_database_document('smtp-settings', $updated)) throw new RuntimeException('无法保存 SMTP 配置到数据库。');
        return;
    }
    $installation['smtp'] = $updated;
    $path = STORAGE_DIR . '/installation.php';
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $contents = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($installation, true) . ";\n";
    if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('无法保存 SMTP 配置。');
    }
}

function site_icon_path(): ?string
{
    foreach (['webp', 'png', 'jpg', 'gif'] as $extension) {
        $path = STORAGE_DIR . '/' . SITE_ICON_BASENAME . '.' . $extension;
        if (is_file($path)) return $path;
    }
    return null;
}

/** Keep a physical favicon copy in the public web root for hosts that serve root assets directly. */
function sync_site_favicon_root(): void
{
    $source = site_icon_path() ?? PUBLIC_DIR . '/admin/assets/mark.svg';
    if (!is_file($source)) throw new RuntimeException('找不到当前网站图标，无法同步到站点根目录。');
    $target = PUBLIC_DIR . '/favicon.ico';
    $temporary = PUBLIC_DIR . '/.favicon-' . bin2hex(random_bytes(6)) . '.tmp';
    $backup = null;
    if (!copy($source, $temporary)) throw new RuntimeException('无法暂存站点根目录的网站图标。');
    try {
        if (is_file($target)) {
            $backup = PUBLIC_DIR . '/.favicon-' . bin2hex(random_bytes(6)) . '.bak';
            if (!rename($target, $backup)) throw new RuntimeException('无法备份站点根目录中的旧网站图标。');
        }
        if (!rename($temporary, $target)) throw new RuntimeException('无法更新站点根目录的网站图标。');
        if ($backup !== null) @unlink($backup);
    } catch (Throwable $error) {
        @unlink($temporary);
        if ($backup !== null && is_file($backup)) @rename($backup, $target);
        throw $error;
    }
}

function site_favicon_url(): string
{
    $custom = site_icon_path();
    $version = $custom ?? PUBLIC_DIR . '/admin/assets/mark.svg';
    $query = is_file($version) ? '?v=' . substr(hash_file('sha256', $version), 0, 16) . ($custom !== null ? '&custom=1' : '') : '';
    // Keep this URL on the origin that served the current page. In a reverse
    // proxy deployment PHP may see the upstream HTTP connection even when the
    // browser-facing page is HTTPS, which makes an absolute URL violate CSP.
    $base = application_base_url();
    $basePath = $base !== '' ? parse_url($base, PHP_URL_PATH) : null;
    $prefix = $base === ''
        ? '../'
        : (is_string($basePath) && rtrim($basePath, '/') !== '' ? rtrim($basePath, '/') . '/' : '/');
    return $prefix . 'favicon.ico' . $query;
}

function output_site_favicon(): bool
{
    $path = site_icon_path();
    if ($path === null) $path = PUBLIC_DIR . '/admin/assets/mark.svg';
    if (!is_file($path)) return false;
    $mime = [
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ][strtolower((string) pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . (string) filesize($path));
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') readfile($path);
    return true;
}

/** @param array<string,mixed> $settings */
function site_theme_css(array $settings): string
{
    $fontSize = ['extra_small' => '12px', 'small' => '14px', 'normal' => '16px', 'large' => '18px', 'extra_large' => '20px'][(string) $settings['font_size']] ?? '16px';
    $accent = (string) $settings['theme_color'];
    $background = (string) $settings['theme_background'];
    $surface = (string) $settings['theme_surface'];
    $text = (string) $settings['theme_text'];
    $muted = (string) $settings['theme_muted'];
    return 'html{font-size:' . $fontSize . ';--picapi-accent:' . $accent . ';--picapi-background:' . $background . ';--picapi-surface:' . $surface . ';--picapi-text:' . $text . ';--picapi-muted:' . $muted . ';--picapi-accent-soft:color-mix(in srgb,' . $accent . ' 16%,transparent);--picapi-surface-raised:color-mix(in srgb,var(--picapi-surface) 92%,var(--picapi-text));--picapi-surface-hover:color-mix(in srgb,var(--picapi-surface) 90%,var(--picapi-accent));--picapi-border:color-mix(in srgb,var(--picapi-text) 18%,var(--picapi-surface));color-scheme:dark}'
        . 'html[data-theme="light"]{color-scheme:light}html[data-theme="auto"]{color-scheme:light dark}'
        . 'body{background:var(--picapi-background)!important;color:var(--picapi-text)!important}'
        . '.bg-slate-950{background-color:var(--picapi-background)!important}.bg-slate-950\\/40{background-color:color-mix(in srgb,var(--picapi-background) 40%,var(--picapi-surface))!important}.bg-slate-950\\/50{background-color:color-mix(in srgb,var(--picapi-background) 50%,var(--picapi-surface))!important}.bg-slate-950\\/60{background-color:color-mix(in srgb,var(--picapi-background) 60%,var(--picapi-surface))!important}.bg-slate-950\\/70{background-color:color-mix(in srgb,var(--picapi-background) 70%,var(--picapi-surface))!important}.bg-slate-950\\/75{background-color:color-mix(in srgb,var(--picapi-background) 75%,var(--picapi-surface))!important}.bg-slate-950\\/80{background-color:color-mix(in srgb,var(--picapi-background) 80%,var(--picapi-surface))!important}.bg-slate-950\\/95{background-color:color-mix(in srgb,var(--picapi-background) 95%,var(--picapi-surface))!important}.bg-slate-900{background-color:var(--picapi-surface)!important}.bg-slate-900\\/50{background-color:color-mix(in srgb,var(--picapi-surface) 50%,var(--picapi-background))!important}.bg-slate-900\\/80{background-color:color-mix(in srgb,var(--picapi-surface) 80%,var(--picapi-background))!important}.bg-slate-900\\/95{background-color:color-mix(in srgb,var(--picapi-surface) 95%,var(--picapi-background))!important}.bg-slate-800{background-color:var(--picapi-surface-raised)!important}.bg-slate-800\\/30{background-color:color-mix(in srgb,var(--picapi-surface-raised) 30%,var(--picapi-surface))!important}.bg-slate-800\\/40{background-color:color-mix(in srgb,var(--picapi-surface-raised) 40%,var(--picapi-surface))!important}.hover\\:bg-slate-900:hover,.hover\\:bg-slate-800:hover,.hover\\:bg-slate-700:hover{background-color:var(--picapi-surface-hover)!important}.hover\\:bg-slate-800\\/30:hover,.hover\\:bg-slate-800\\/40:hover{background-color:color-mix(in srgb,var(--picapi-surface-hover) 40%,var(--picapi-surface))!important}'
        . '.border-slate-950,.border-slate-900,.border-slate-800,.border-slate-700{border-color:var(--picapi-border)!important}'
        . '.picapi-total-card{background:color-mix(in srgb,var(--picapi-surface) 94%,var(--picapi-accent))!important;border-color:color-mix(in srgb,var(--picapi-accent) 34%,var(--picapi-border))!important}.picapi-total-card:hover{background:color-mix(in srgb,var(--picapi-surface) 90%,var(--picapi-accent))!important}'
        . '.text-white,.text-slate-100,.text-slate-200,.text-slate-300{color:var(--picapi-text)!important}.text-slate-400,.text-slate-500,.text-slate-600{color:var(--picapi-muted)!important}'
        . 'input,select,textarea{background-color:var(--picapi-background)!important;color:var(--picapi-text)!important;border-color:var(--picapi-border)!important}'
        . '.custom-scrollbar::-webkit-scrollbar-track{background:var(--picapi-background)}.custom-scrollbar::-webkit-scrollbar-thumb{background:color-mix(in srgb,var(--picapi-muted) 55%,var(--picapi-surface))}.custom-scrollbar::-webkit-scrollbar-thumb:hover{background:var(--picapi-muted)}'
        . '.bg-indigo-600,.bg-indigo-500{background-color:var(--picapi-accent)!important}.hover\\:bg-indigo-700:hover,.hover\\:bg-indigo-500:hover{background-color:color-mix(in srgb,var(--picapi-accent) 84%,#000)!important}'
        . '.text-indigo-400,.text-indigo-300{color:color-mix(in srgb,var(--picapi-accent) 78%,var(--picapi-text))!important}.border-indigo-500,.border-indigo-500\\/50{border-color:var(--picapi-accent)!important}.focus\\:border-indigo-500:focus{border-color:var(--picapi-accent)!important}'
        . 'html[data-theme="light"] .text-cyan-100,html[data-theme="light"] .text-cyan-200,html[data-theme="light"] .text-cyan-300,html[data-theme="light"] .text-cyan-400,html[data-theme="light"] .text-indigo-200{color:color-mix(in srgb,var(--picapi-accent) 56%,var(--picapi-text))!important}'
        . '.notice,.picapi-theme-notice{border-color:color-mix(in srgb,var(--picapi-accent) 62%,var(--picapi-border))!important;background:color-mix(in srgb,var(--picapi-surface) 84%,var(--picapi-accent))!important;color:var(--picapi-text)!important;box-shadow:0 20px 38px color-mix(in srgb,var(--picapi-background) 76%,transparent)!important}.picapi-theme-notice .picapi-theme-notice-icon{color:var(--picapi-accent)!important}.picapi-theme-notice--error{border-color:color-mix(in srgb,#ef4444 58%,var(--picapi-accent))!important}.picapi-theme-notice--error .picapi-theme-notice-icon{color:color-mix(in srgb,#ef4444 72%,var(--picapi-accent))!important}';
}

function email_template_style_value(DOMElement $element, string $property): string
{
    $style = (string) $element->getAttribute('style');
    if (preg_match('/(?:^|;)\s*' . preg_quote($property, '/') . '\s*:\s*([^;]+)/i', $style, $matches) !== 1) return '';
    return trim((string) $matches[1]);
}

function email_template_set_style(DOMElement $element, string $property, string $value): void
{
    $style = (string) $element->getAttribute('style');
    $pattern = '/(^|;)\s*' . preg_quote($property, '/') . '\s*:\s*[^;]*(?=;|$)/i';
    $style = preg_replace($pattern, '$1', $style) ?? $style;
    $style = trim((string) preg_replace('/;{2,}/', ';', $style), " \t\r\n;");
    $element->setAttribute('style', ($style === '' ? '' : $style . ';') . $property . ':' . $value);
}

function email_template_html_load_options(): int
{
    $options = 0;
    // Some Windows PHP distributions expose DOMDocument without these optional
    // HTML parser constants. Build the flag set defensively so delivery repair
    // still runs instead of falling back to the malformed saved template.
    foreach (['LIBXML_HTML_NOERROR', 'LIBXML_HTML_NOWARNING'] as $constant) {
        if (defined($constant)) $options |= (int) constant($constant);
    }
    return $options;
}

function email_template_is_descendant(DOMNode $node, DOMNode $ancestor): bool
{
    for ($current = $node; $current !== null; $current = $current->parentNode) {
        if ($current->isSameNode($ancestor)) return true;
    }
    return false;
}

/**
 * Repairs the malformed legacy notification template where the outer layout
 * table was accidentally edited as the IP/time information card. Delivery is
 * normalized here as well as in the editor, so existing saved templates send
 * with readable colors immediately.
 */
function repair_notification_email_template_for_delivery(string $template): string
{
    if (!class_exists(DOMDocument::class) || !str_contains($template, '{{ip}}') || !str_contains($template, '{{time}}')) return $template;

    $previousLibxmlErrors = libxml_use_internal_errors(true);
    try {
        $document = new DOMDocument('1.0', 'UTF-8');
        // HTML templates do not necessarily have a meta charset declaration.
        // Prefixing the parser input preserves Chinese text while DOMDocument
        // normalizes the legacy information-card markup.
        if (@$document->loadHTML('<?xml encoding="UTF-8">' . $template, email_template_html_load_options()) === false) return $template;
        $xpath = new DOMXPath($document);
        $candidates = [];
        foreach ($xpath->query('//table') ?: [] as $table) {
            if (!$table instanceof DOMElement) continue;
            $text = preg_replace('/\s+/u', ' ', (string) $table->textContent) ?? '';
            if ((str_contains($text, '来源 IP') || str_contains($text, '{{ip}}')) && (str_contains($text, '发生时间') || str_contains($text, '{{time}}'))) {
                $candidates[] = $table;
            }
        }
        if ($candidates === []) return $template;

        $infoTable = null;
        $lowestNestedCount = PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            $nestedCount = 0;
            foreach ($candidates as $other) {
                if (!$candidate->isSameNode($other) && email_template_is_descendant($other, $candidate)) $nestedCount++;
            }
            if ($nestedCount < $lowestNestedCount) {
                $infoTable = $candidate;
                $lowestNestedCount = $nestedCount;
            }
        }
        if (!$infoTable instanceof DOMElement) return $template;

        $legacyContainer = null;
        foreach ($xpath->query('//table[@data-picapi-easy-info]') ?: [] as $table) {
            if ($table instanceof DOMElement && !$table->isSameNode($infoTable) && email_template_is_descendant($infoTable, $table)) {
                $legacyContainer = $table;
                break;
            }
        }
        if (!$legacyContainer instanceof DOMElement) return $template;

        $cardBackground = email_template_style_value($legacyContainer, 'background');
        if ($cardBackground === '') $cardBackground = email_template_style_value($legacyContainer, 'background-color');
        if ($cardBackground === '') $cardBackground = '#EAF6FF';
        $labelColor = '#0C4A6E';
        foreach ($xpath->query('//*[@data-picapi-easy-info-label]') ?: [] as $label) {
            if ($label instanceof DOMElement && !email_template_is_descendant($label, $infoTable)) {
                $labelColor = email_template_style_value($label, 'color') ?: $labelColor;
                break;
            }
        }
        $messageNode = $xpath->query('//*[@data-picapi-easy-message]')->item(0);
        $valueColor = $messageNode instanceof DOMElement ? (email_template_style_value($messageNode, 'color') ?: '#082F49') : '#082F49';
        $brandNode = $xpath->query('//*[@data-picapi-easy-brand]')->item(0);
        $headerTitleColor = $brandNode instanceof DOMElement ? (email_template_style_value($brandNode, 'color') ?: '#FFFFFF') : '#FFFFFF';

        $legacyContainer->removeAttribute('data-picapi-easy-info');
        // The outer table is only for layout. Its legacy card background creates
        // an unintended coloured canvas around the real email card.
        email_template_set_style($legacyContainer, 'background', 'transparent');
        email_template_set_style($legacyContainer, 'background-color', 'transparent');
        foreach ($xpath->query('//*[@data-picapi-easy-info-label or @data-picapi-easy-info-value]') ?: [] as $node) {
            if ($node instanceof DOMElement && !email_template_is_descendant($node, $infoTable)) {
                $node->removeAttribute('data-picapi-easy-info-label');
                $node->removeAttribute('data-picapi-easy-info-value');
            }
        }
        $infoTable->setAttribute('data-picapi-easy-info', '');
        email_template_set_style($infoTable, 'background', $cardBackground);
        email_template_set_style($infoTable, 'border-color', $labelColor);
        foreach ($xpath->query('.//td', $infoTable) ?: [] as $cell) {
            if (!$cell instanceof DOMElement) continue;
            $cellText = trim((string) $cell->textContent);
            if (str_contains($cellText, '{{ip}}') || str_contains($cellText, '{{time}}')) {
                $cell->setAttribute('data-picapi-easy-info-value', '');
                email_template_set_style($cell, 'color', $valueColor);
            } else {
                $cell->setAttribute('data-picapi-easy-info-label', '');
                email_template_set_style($cell, 'color', $labelColor);
            }
        }
        foreach ($xpath->query('//*[@data-picapi-easy-header]//h1') ?: [] as $title) {
            if ($title instanceof DOMElement) email_template_set_style($title, 'color', $headerTitleColor);
        }

        $repaired = $document->saveHTML();
        if ($repaired === false) return $template;
        return preg_replace('/<\?xml[^>]*>\s*/i', '', $repaired) ?? $repaired;
    } catch (Throwable) {
        return $template;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlErrors);
    }
}

/** @param array<string,string|int> $details */
function render_site_notification_html(string $title, string $message, array $details = [], string $templateKey = 'notification_email_html_template'): string
{
    $settings = site_settings();
    $timezone = new DateTimeZone('Asia/Shanghai');
    $tokens = [
        '{{site_name}}' => html((string) $settings['site_name']),
        '{{title}}' => html($title),
        '{{message}}' => nl2br(html($message)),
        '{{ip}}' => html((string) ($details['ip'] ?? '-')),
        '{{time}}' => html((string) ($details['time'] ?? (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s'))),
        '{{detail}}' => nl2br(html((string) ($details['detail'] ?? 'PicAPI 安全与服务提醒'))),
        '{{code}}' => html((string) ($details['code'] ?? '-')),
    ];
    $template = (string) ($settings[$templateKey] ?? $settings['notification_email_html_template']);
    if ($templateKey === 'notification_email_html_template') $template = repair_notification_email_template_for_delivery($template);
    return strtr($template, $tokens);
}

/** @param array<string,string|int> $details */
function send_site_notification(string $title, string $message, array $details = [], ?string $recipient = null, string $templateKey = 'notification_email_html_template', ?string &$failureReason = null): bool
{
    $failureReason = null;
    $settings = site_settings();
    $installation = installation_config() ?? [];
    $smtp = smtp_settings();
    $recipient ??= (string) ($settings['notification_email'] !== '' ? $settings['notification_email'] : ($installation['admin_email'] ?? ''));
    if (!smtp_is_configured($smtp)) {
        $failureReason = 'SMTP 服务尚未完成配置。';
        return false;
    }
    if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
        $failureReason = '提醒收件人邮箱无效或未配置。';
        return false;
    }
    $subject = strtr((string) $settings['email_subject_template'], ['{{site_name}}' => (string) $settings['site_name'], '{{title}}' => $title]);
    $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
    try {
        smtp_send_message($smtp, $recipient, $subject !== '' ? $subject : $title, render_site_notification_html($title, $message, $details, $templateKey), true);
        return true;
    } catch (Throwable $error) {
        $failureReason = $error->getMessage();
        return false;
    }
}

function send_password_reset_notification(string $recipient, string $code): bool
{
    return send_site_notification('找回密码验证码', '请使用下方验证码完成密码重置。', ['code' => $code, 'detail' => '验证码将在 15 分钟后失效。'], $recipient, 'password_reset_email_html_template');
}

function record_public_request_rate_alert(string $ip): void
{
    $settings = site_settings();
    if (empty($settings['request_rate_alert']) || filter_var($ip, FILTER_VALIDATE_IP) === false) return;
    $window = (int) $settings['request_window_seconds'];
    $threshold = (int) $settings['request_threshold'];
    $directory = STORAGE_DIR . '/security';
    ensure_directory($directory);
    $path = $directory . '/request-rate-alerts.json';
    $legacy = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    $legacy = is_array($legacy) ? $legacy : [];
    if (runtime_state_database() !== null) {
        [$shouldNotify, $count] = with_database_runtime_state('request-rate-alerts', $legacy, static function (array &$states) use ($ip, $window, $threshold): array {
            $now = time();
            foreach ($states as $key => $state) {
                $events = array_values(array_filter((array) ($state['events'] ?? []), static fn(mixed $event): bool => is_int($event) && $event >= $now - $window));
                if ($events === [] && (int) ($state['notified_at'] ?? 0) < $now - 86400) {
                    unset($states[$key]);
                    continue;
                }
                $states[$key]['events'] = $events;
            }
            $key = hash('sha256', $ip);
            $state = is_array($states[$key] ?? null) ? $states[$key] : ['ip' => $ip, 'events' => [], 'notified_at' => 0];
            $state['events'][] = $now;
            $count = count($state['events']);
            $shouldNotify = $count >= $threshold && $now - (int) ($state['notified_at'] ?? 0) >= $window;
            if ($shouldNotify) $state['notified_at'] = $now;
            $states[$key] = $state;
            return [$shouldNotify, $count];
        });
        if ($shouldNotify) {
            try {
                send_site_notification('API 请求频率提醒', '检测到单个 IP 在设定时间窗口内的请求次数超过阈值。', [
                    'ip' => $ip,
                    'detail' => $window . ' 秒内请求 ' . $count . ' 次，阈值为 ' . $threshold . ' 次。',
                ]);
            } catch (Throwable) {
                // Alert delivery must never interrupt the public image API.
            }
        }
        return;
    }
    $lock = fopen($path . '.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        return;
    }
    $shouldNotify = false;
    $count = 0;
    try {
        // Re-read under the lock so concurrent requests cannot overwrite each
        // other's counters from a stale pre-lock snapshot.
        $states = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $states = is_array($states) ? $states : [];
        $now = time();
        foreach ($states as $key => $state) {
            $events = array_values(array_filter((array) ($state['events'] ?? []), static fn(mixed $event): bool => is_int($event) && $event >= $now - $window));
            if ($events === [] && (int) ($state['notified_at'] ?? 0) < $now - 86400) {
                unset($states[$key]);
                continue;
            }
            $states[$key]['events'] = $events;
        }
        $key = hash('sha256', $ip);
        $state = is_array($states[$key] ?? null) ? $states[$key] : ['ip' => $ip, 'events' => [], 'notified_at' => 0];
        $state['events'][] = $now;
        $count = count($state['events']);
        if ($count >= $threshold && $now - (int) ($state['notified_at'] ?? 0) >= $window) {
            $state['notified_at'] = $now;
            $shouldNotify = true;
        }
        $states[$key] = $state;
        write_json_atomic($path, $states);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    if (!$shouldNotify) return;
    try {
        send_site_notification('API 请求频率提醒', '检测到单个 IP 在设定时间窗口内的请求次数超过阈值。', [
            'ip' => $ip,
            'detail' => $window . ' 秒内请求 ' . $count . ' 次，阈值为 ' . $threshold . ' 次。',
        ]);
    } catch (Throwable) {
        // Alert delivery must never interrupt the public image API.
    }
}

function ip_access_control_path(): string
{
    ensure_directory(dirname(IP_ACCESS_CONTROL_FILE));
    return IP_ACCESS_CONTROL_FILE;
}

function valid_access_control_ip(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

/** @param callable(array<string,mixed>&): mixed $callback */
function with_ip_access_control(callable $callback): mixed
{
    $path = ip_access_control_path();
    $legacy = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    $legacy = is_array($legacy) ? $legacy : [];
    if (runtime_state_database() !== null) {
        return with_database_runtime_state('ip-access-control', $legacy, $callback);
    }
    $lock = fopen($path . '.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('无法访问 IP 管控状态。');
    }
    try {
        // Re-read under the lock so concurrent requests cannot overwrite each
        // other's state from a stale pre-lock snapshot (which would bypass
        // request-rate auto-bans and login blacklists in the file fallback).
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $data = is_array($data) ? $data : [];
        $result = $callback($data);
        if (!write_json_atomic($path, $data)) {
            throw new RuntimeException('无法保存 IP 管控状态。');
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** @param array<string,mixed> $data */
function normalize_ip_access_control(array &$data, int $now, ?int $rateWindowSeconds = null): void
{
    $rateWindowSeconds ??= (int) site_settings()['request_window_seconds'];
    $rateWindowSeconds = in_array($rateWindowSeconds, [60, 300, 600, 1800], true) ? $rateWindowSeconds : 60;
    $data['whitelist'] = is_array($data['whitelist'] ?? null) ? $data['whitelist'] : [];
    $data['blacklist'] = is_array($data['blacklist'] ?? null) ? $data['blacklist'] : [];
    $data['login_blacklist'] = is_array($data['login_blacklist'] ?? null) ? $data['login_blacklist'] : [];
    $data['rate_events'] = is_array($data['rate_events'] ?? null) ? $data['rate_events'] : [];

    // Older installations mixed both scopes in one map. Preserve their
    // expiry and permanence while moving login bans to their own map.
    foreach ($data['blacklist'] as $ip => $entry) {
        if (is_array($entry) && in_array((string) ($entry['reason'] ?? ''), ['login', 'manual_login'], true)) {
            if (!isset($data['login_blacklist'][$ip])) $data['login_blacklist'][$ip] = $entry;
            unset($data['blacklist'][$ip]);
        }
    }

    foreach ($data['whitelist'] as $ip => $entry) {
        if (!is_string($ip) || !valid_access_control_ip($ip)) {
            unset($data['whitelist'][$ip]);
            continue;
        }
        $data['whitelist'][$ip] = ['added_at' => max(0, (int) (is_array($entry) ? ($entry['added_at'] ?? 0) : 0))];
    }
    foreach (['blacklist', 'login_blacklist'] as $list) {
        foreach ($data[$list] as $ip => $entry) {
            $until = is_array($entry) ? (int) ($entry['until'] ?? 0) : 0;
            $permanent = is_array($entry) && !empty($entry['permanent']);
            if (!is_string($ip) || !valid_access_control_ip($ip) || (!$permanent && $until <= $now)) {
                unset($data[$list][$ip]);
                continue;
            }
            $reason = (string) ($entry['reason'] ?? ($list === 'login_blacklist' ? 'manual_login' : 'auto'));
            if ($list === 'login_blacklist' && !in_array($reason, ['login', 'manual_login'], true)) $reason = 'manual_login';
            $data[$list][$ip] = [
                'until' => $permanent ? 0 : $until,
                'permanent' => $permanent,
                'reason' => $reason,
                'added_at' => max(0, (int) ($entry['added_at'] ?? 0)),
            ];
        }
    }
    foreach ($data['rate_events'] as $ip => $events) {
        if (!is_string($ip) || !valid_access_control_ip($ip)) {
            unset($data['rate_events'][$ip]);
            continue;
        }
        $events = array_values(array_filter((array) $events, static fn(mixed $event): bool => is_int($event) && $event > $now - $rateWindowSeconds));
        if ($events === []) {
            unset($data['rate_events'][$ip]);
        } else {
            $data['rate_events'][$ip] = $events;
        }
    }
}

/** @return array{whitelist:list<array{ip:string,added_at:int}>,blacklist:list<array{ip:string,until:int,permanent:bool,reason:string,added_at:int}>} */
function ip_access_control_lists(): array
{
    require_once __DIR__ . '/security_runtime.php';
    if (security_policy_active()) { return security_legacy_lists(); }
    return with_ip_access_control(static function (array &$data): array {
        normalize_ip_access_control($data, time());
        $whitelist = [];
        foreach ($data['whitelist'] as $ip => $entry) $whitelist[] = ['ip' => $ip, 'added_at' => (int) $entry['added_at']];
        $blacklist = [];
        foreach (['blacklist', 'login_blacklist'] as $list) {
            foreach ($data[$list] as $ip => $entry) $blacklist[] = ['ip' => $ip, 'until' => (int) $entry['until'], 'permanent' => !empty($entry['permanent']), 'reason' => (string) $entry['reason'], 'added_at' => (int) $entry['added_at']];
        }
        usort($whitelist, static fn(array $left, array $right): int => $left['ip'] <=> $right['ip']);
        usort($blacklist, static fn(array $left, array $right): int => ($left['permanent'] ? 0 : 1) <=> ($right['permanent'] ? 0 : 1) ?: $left['until'] <=> $right['until']);
        return ['whitelist' => $whitelist, 'blacklist' => $blacklist];
    });
}

function add_ip_to_whitelist(string $ip): void
{
    require_once __DIR__ . '/security_runtime.php';
    if (security_policy_active()) { security_legacy_add($ip, true); return; }
    $ip = trim($ip);
    if (!valid_access_control_ip($ip)) throw new RuntimeException('请输入有效的 IPv4 或 IPv6 地址。');
    with_ip_access_control(static function (array &$data) use ($ip): void {
        $now = time();
        normalize_ip_access_control($data, $now);
        $data['whitelist'][$ip] = ['added_at' => $now];
        unset($data['blacklist'][$ip], $data['login_blacklist'][$ip], $data['rate_events'][$ip]);
    });
}

function add_ip_to_blacklist(string $ip, int $duration = 1800, string $reason = 'manual', bool $permanent = false): void
{
    require_once __DIR__ . '/security_runtime.php';
    if (security_policy_active()) { security_legacy_add($ip, false, $duration, $reason, $permanent); return; }
    $ip = trim($ip);
    if (!valid_access_control_ip($ip)) throw new RuntimeException('请输入有效的 IPv4 或 IPv6 地址。');
    with_ip_access_control(static function (array &$data) use ($ip, $duration, $reason, $permanent): void {
        $now = time();
        normalize_ip_access_control($data, $now);
        if (isset($data['whitelist'][$ip])) throw new RuntimeException('该 IP 已在白名单中，不能加入黑名单。');
        $list = in_array($reason, ['login', 'manual_login'], true) ? 'login_blacklist' : 'blacklist';
        $data[$list][$ip] = ['until' => $permanent ? 0 : $now + max(60, $duration), 'permanent' => $permanent, 'reason' => $reason, 'added_at' => $now];
        if ($list === 'blacklist') unset($data['rate_events'][$ip]);
    });
}

/** @return list<string> */
function ip_access_control_list_keys(string $list, ?string $scope = null): array
{
    if (!in_array($list, ['whitelist', 'blacklist'], true) || ($scope !== null && !in_array($scope, ['request', 'login'], true))) {
        throw new RuntimeException('IP 管控项目无效。');
    }
    if ($list === 'whitelist') return ['whitelist'];
    return match ($scope) {
        'request' => ['blacklist'],
        'login' => ['login_blacklist'],
        default => ['blacklist', 'login_blacklist'],
    };
}

function remove_ip_from_access_list(string $ip, string $list, ?string $scope = null): void
{
    require_once __DIR__ . '/security_runtime.php';
    if (security_policy_active()) { security_legacy_remove([$ip], $list, $scope); return; }
    $keys = ip_access_control_list_keys($list, $scope);
    if (!valid_access_control_ip($ip)) throw new RuntimeException('IP 管控项目无效。');
    with_ip_access_control(static function (array &$data) use ($ip, $keys): void {
        normalize_ip_access_control($data, time());
        foreach ($keys as $key) unset($data[$key][$ip]);
    });
}

/** @param list<mixed> $ips */
function remove_ips_from_access_list(array $ips, string $list, ?string $scope = null): int
{
    require_once __DIR__ . '/security_runtime.php';
    if (security_policy_active()) { return security_legacy_remove($ips, $list, $scope); }
    $keys = ip_access_control_list_keys($list, $scope);
    $validIps = [];
    foreach ($ips as $ip) {
        $ip = is_string($ip) ? trim($ip) : '';
        if (valid_access_control_ip($ip)) $validIps[$ip] = true;
    }
    if ($validIps === []) throw new RuntimeException('请选择至少一个有效的 IP 地址。');

    return with_ip_access_control(static function (array &$data) use ($keys, $validIps): int {
        normalize_ip_access_control($data, time());
        $removed = 0;
        foreach (array_keys($validIps) as $ip) {
            $found = false;
            foreach ($keys as $key) {
                if (isset($data[$key][$ip])) {
                    unset($data[$key][$ip]);
                    $found = true;
                }
            }
            if ($found) $removed++;
        }
        return $removed;
    });
}

/** @return array{blocked:bool,until:int,permanent:bool,reason:string,whitelisted:bool,newly_blocked:bool,count:int} */
function public_ip_access_status(string $ip): array
{
    return scoped_ip_access_status($ip, 'request');
}

/** @return array{blocked:bool,until:int,permanent:bool,reason:string,whitelisted:bool,newly_blocked:bool,count:int} */
function login_ip_access_status(string $ip): array
{
    return scoped_ip_access_status($ip, 'login');
}

/** @return array{blocked:bool,until:int,permanent:bool,reason:string,whitelisted:bool,newly_blocked:bool,count:int} */
function scoped_ip_access_status(string $ip, string $scope): array
{
    require_once __DIR__ . '/security_runtime.php';
    if (security_policy_active()) return security_status($ip, $scope === 'request' ? 'api' : 'login');
    $list = ip_access_control_list_keys('blacklist', $scope)[0];
    $empty = ['blocked' => false, 'until' => 0, 'permanent' => false, 'reason' => '', 'whitelisted' => false, 'newly_blocked' => false, 'count' => 0];
    if (!valid_access_control_ip($ip)) return $empty;
    return with_ip_access_control(static function (array &$data) use ($ip, $list, $empty): array {
        $now = time();
        normalize_ip_access_control($data, $now);
        if (isset($data['whitelist'][$ip])) return array_replace($empty, ['whitelisted' => true]);
        $entry = $data[$list][$ip] ?? null;
        if (is_array($entry)) return array_replace($empty, ['blocked' => true, 'until' => (int) $entry['until'], 'permanent' => !empty($entry['permanent']), 'reason' => (string) $entry['reason']]);
        return $empty;
    });
}

/** @return array{blocked:bool,until:int,permanent:bool,reason:string,whitelisted:bool,newly_blocked:bool,count:int} */
function record_public_ip_request(string $ip): array
{
    require_once __DIR__ . '/security_runtime.php';
    if (security_policy_active()) return security_status($ip, 'api', true);
    $empty = ['blocked' => false, 'until' => 0, 'permanent' => false, 'reason' => '', 'whitelisted' => false, 'newly_blocked' => false, 'count' => 0];
    if (!valid_access_control_ip($ip)) return $empty;
    $settings = site_settings();
    $threshold = (int) $settings['request_threshold'];
    $window = (int) $settings['request_window_seconds'];
    return with_ip_access_control(static function (array &$data) use ($ip, $threshold, $window, $empty): array {
        $now = time();
        normalize_ip_access_control($data, $now, $window);
        if (isset($data['whitelist'][$ip])) return array_replace($empty, ['whitelisted' => true]);
        $existing = $data['blacklist'][$ip] ?? null;
        if (is_array($existing)) return array_replace($empty, ['blocked' => true, 'until' => (int) $existing['until'], 'permanent' => !empty($existing['permanent']), 'reason' => (string) $existing['reason']]);
        $events = (array) ($data['rate_events'][$ip] ?? []);
        $events[] = $now;
        $count = count($events);
        $data['rate_events'][$ip] = $events;
        if ($count < $threshold) return array_replace($empty, ['count' => $count]);
        $until = $now + 1800;
        $data['blacklist'][$ip] = ['until' => $until, 'permanent' => false, 'reason' => 'auto', 'added_at' => $now];
        unset($data['rate_events'][$ip]);
        return array_replace($empty, ['blocked' => true, 'until' => $until, 'reason' => 'auto', 'newly_blocked' => true, 'count' => $count]);
    });
}
