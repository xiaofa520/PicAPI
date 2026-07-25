<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/smtp_runtime.php';

const SITE_SETTINGS_FILE = STORAGE_DIR . '/site-settings.json';
const SITE_ICON_BASENAME = 'site-icon';
const IP_ACCESS_CONTROL_FILE = STORAGE_DIR . '/security/ip-access-control.json';

function default_notification_email_html_template(): string
{
    return '<!doctype html><html lang="zh-CN"><body data-picapi-easy-body style="margin:0;padding:32px 16px;background:#0B1020;color:#E6EDF7;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table data-picapi-easy-shell role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;min-height:360px;background:#111827;border:1px solid #334155;border-radius:16px;overflow:hidden;box-shadow:0 20px 48px rgba(2,6,23,.42)"><tr><td data-picapi-easy-header style="padding:26px 30px;background:#312E81;border-bottom:3px solid #6366F1"><strong data-picapi-easy-brand style="color:#C7D2FE;font-size:18px">{{site_name}}</strong><h1 style="margin:18px 0 0;color:#FFFFFF;font-size:22px">{{title}}</h1></td></tr><tr><td style="padding:30px"><p data-picapi-easy-message style="margin:0 0 20px;color:#D7E0EF;line-height:1.75">{{message}}</p><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #334155;border-radius:10px;background:#0F172A"><tr><td style="padding:12px;color:#93C5FD;font-size:13px">来源 IP</td><td style="padding:12px;color:#F8FAFC;font:13px ui-monospace,SFMono-Regular,Menlo,monospace;text-align:right">{{ip}}</td></tr><tr><td style="padding:12px;color:#93C5FD;font-size:13px">发生时间</td><td style="padding:12px;color:#F8FAFC;font-size:13px;text-align:right">{{time}}</td></tr></table><p data-picapi-easy-detail style="margin:20px 0 0;color:#94A3B8;font-size:12px">{{detail}}</p></td></tr></table></td></tr></table></body></html>';
}

function default_password_reset_email_html_template(): string
{
    return '<!doctype html><html lang="zh-CN"><body data-picapi-easy-body style="margin:0;padding:32px 16px;background:#071A1A;color:#E6FFFB;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table data-picapi-easy-shell role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;min-height:360px;background:#102322;border:1px solid #285C59;border-radius:16px;overflow:hidden;box-shadow:0 20px 48px rgba(2,44,34,.42)"><tr><td data-picapi-easy-header style="padding:26px 30px;background:#0F766E;border-bottom:3px solid #2DD4BF"><strong data-picapi-easy-brand style="color:#99F6E4;font-size:18px">{{site_name}}</strong><h1 style="margin:18px 0 0;color:#ECFDF5;font-size:22px">{{title}}</h1></td></tr><tr><td style="padding:30px"><p data-picapi-easy-message style="margin:0 0 20px;color:#D1FAE5;line-height:1.75">{{message}}</p><div style="margin:20px 0;padding:18px;border:1px solid #38BDF8;border-radius:10px;background:#0C4A6E;color:#E0F2FE;text-align:center;font-size:30px;font-weight:700;letter-spacing:10px">{{code}}</div><p data-picapi-easy-detail style="margin:20px 0 0;color:#99B9B3;font-size:12px">{{detail}}</p></td></tr></table></td></tr></table></body></html>';
}

/** @return array<string,mixed> */
function default_site_settings(): array
{
    return [
        'site_name' => 'PicAPI',
        'font_size' => 'normal',
        'theme_mode' => 'dark',
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
        'light_theme_color' => '#2563EB',
        'light_theme_background' => '#F7FAFF',
        'light_theme_surface' => '#FFFFFF',
        'light_theme_text' => '#102A43',
        'light_theme_muted' => '#52677F',
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
    ];
}

/** @param array<string,mixed> $source @return array<string,mixed> */
function normalize_site_settings(array $source): array
{
    $settings = default_site_settings();
    $siteName = trim((string) ($source['site_name'] ?? $settings['site_name']));
    $siteNameLength = function_exists('mb_strlen') ? mb_strlen($siteName, 'UTF-8') : strlen($siteName);
    $settings['site_name'] = $siteName !== '' && $siteNameLength <= 80 ? $siteName : $settings['site_name'];

    $fontSize = (string) ($source['font_size'] ?? $settings['font_size']);
    $settings['font_size'] = in_array($fontSize, ['extra_small', 'small', 'normal', 'large', 'extra_large'], true) ? $fontSize : $settings['font_size'];
    $themeMode = (string) ($source['theme_mode'] ?? $settings['theme_mode']);
    $settings['theme_mode'] = in_array($themeMode, ['dark', 'light', 'auto'], true) ? $themeMode : $settings['theme_mode'];

    foreach (['theme_color', 'theme_background', 'theme_surface', 'theme_text', 'theme_muted'] as $key) {
        $color = strtoupper(trim((string) ($source[$key] ?? $settings[$key])));
        $settings[$key] = preg_match('/^#[0-9A-F]{6}$/', $color) === 1 ? $color : $settings[$key];
    }
    foreach (['dark', 'light'] as $mode) {
        foreach (['color', 'background', 'surface', 'text', 'muted'] as $part) {
            $key = $mode . '_theme_' . $part;
            $activeKey = 'theme_' . $part;
            $fallback = $mode === $settings['theme_mode'] ? $settings[$activeKey] : $settings[$key];
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
    foreach (['login_notification', 'password_breach_alert', 'request_rate_alert'] as $key) {
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
    return save_site_settings(array_replace(site_settings(), $changes));
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
            ['root' => APP_ROOT . '/log', 'extension' => 'log'],
            ['root' => APP_ROOT . '/pc/log', 'extension' => 'log'],
            ['root' => APP_ROOT . '/m/log', 'extension' => 'log'],
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

    $root = STORAGE_DIR . '/upload-logs';
    ensure_directory($root);
    $lock = @fopen($root . '/.retention-cleanup.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return 0;
    }

    try {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $today = new DateTimeImmutable('today', $timezone);
        $statePath = $root . '/.retention-cleanup.json';
        $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : [];
        $state = is_array($state) ? $state : [];
        if (!$force
            && ($state['day'] ?? '') === $today->format('Y-m-d')
            && (int) ($state['months'] ?? 0) === $months) {
            return 0;
        }

        $cutoff = $today->modify('-' . $months . ' months');
        $deleted = 0;
        foreach (glob($root . '/*/*/*.jsonl') ?: [] as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen(rtrim($root, '\\/')) + 1));
            if (preg_match('~^(\\d{4})/(\\d{2})/(\\d{2})\\.jsonl$~', $relative, $matches) !== 1) continue;
            try {
                $day = new DateTimeImmutable($matches[1] . '-' . $matches[2] . '-' . $matches[3], $timezone);
                if ($day < $cutoff && @unlink($path)) $deleted++;
            } catch (Throwable) {
                // Leave malformed paths untouched rather than deleting an unexpected file.
            }
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
    if ((int) ($upload['size'] ?? 0) > 2 * 1024 * 1024) throw new RuntimeException('站点图标不能超过 2 MB。');
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
    foreach (['png', 'jpg', 'gif', 'webp'] as $extension) {
        $path = STORAGE_DIR . '/' . SITE_ICON_BASENAME . '.' . $extension;
        if (is_file($path)) return $path;
    }
    return null;
}

function site_favicon_url(): string
{
    $version = site_icon_path();
    $query = $version !== null ? '?v=' . (string) filemtime($version) : '';
    $base = rtrim(application_base_url(), '/');
    return ($base !== '' ? $base : '..') . '/favicon.ico' . $query;
}

function output_site_favicon(): bool
{
    $path = site_icon_path();
    if ($path === null) return false;
    $mime = [
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
        . '.text-white,.text-slate-100,.text-slate-200,.text-slate-300{color:var(--picapi-text)!important}.text-slate-400,.text-slate-500,.text-slate-600{color:var(--picapi-muted)!important}'
        . 'input,select,textarea{background-color:var(--picapi-background)!important;color:var(--picapi-text)!important;border-color:var(--picapi-border)!important}'
        . '.custom-scrollbar::-webkit-scrollbar-track{background:var(--picapi-background)}.custom-scrollbar::-webkit-scrollbar-thumb{background:color-mix(in srgb,var(--picapi-muted) 55%,var(--picapi-surface))}.custom-scrollbar::-webkit-scrollbar-thumb:hover{background:var(--picapi-muted)}'
        . '.bg-indigo-600,.bg-indigo-500{background-color:var(--picapi-accent)!important}.hover\\:bg-indigo-700:hover,.hover\\:bg-indigo-500:hover{background-color:color-mix(in srgb,var(--picapi-accent) 84%,#000)!important}'
        . '.text-indigo-400,.text-indigo-300{color:color-mix(in srgb,var(--picapi-accent) 78%,var(--picapi-text))!important}.border-indigo-500,.border-indigo-500\\/50{border-color:var(--picapi-accent)!important}.focus\\:border-indigo-500:focus{border-color:var(--picapi-accent)!important}'
        . 'html[data-theme="light"] .text-cyan-100,html[data-theme="light"] .text-cyan-200,html[data-theme="light"] .text-cyan-300,html[data-theme="light"] .text-cyan-400,html[data-theme="light"] .text-indigo-200{color:color-mix(in srgb,var(--picapi-accent) 56%,var(--picapi-text))!important}'
        . '.notice,.picapi-theme-notice{border-color:color-mix(in srgb,var(--picapi-accent) 62%,var(--picapi-border))!important;background:color-mix(in srgb,var(--picapi-surface) 84%,var(--picapi-accent))!important;color:var(--picapi-text)!important;box-shadow:0 20px 38px color-mix(in srgb,var(--picapi-background) 76%,transparent)!important}.picapi-theme-notice .picapi-theme-notice-icon{color:var(--picapi-accent)!important}.picapi-theme-notice--error{border-color:color-mix(in srgb,#ef4444 58%,var(--picapi-accent))!important}.picapi-theme-notice--error .picapi-theme-notice-icon{color:color-mix(in srgb,#ef4444 72%,var(--picapi-accent))!important}';
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
    return strtr($template, $tokens);
}

/** @param array<string,string|int> $details */
function send_site_notification(string $title, string $message, array $details = [], ?string $recipient = null, string $templateKey = 'notification_email_html_template'): bool
{
    $settings = site_settings();
    $installation = installation_config() ?? [];
    $smtp = smtp_settings();
    $recipient ??= (string) ($settings['notification_email'] !== '' ? $settings['notification_email'] : ($installation['admin_email'] ?? ''));
    if (!smtp_is_configured($smtp) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) return false;
    $subject = strtr((string) $settings['email_subject_template'], ['{{site_name}}' => (string) $settings['site_name'], '{{title}}' => $title]);
    $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
    smtp_send_message($smtp, $recipient, $subject !== '' ? $subject : $title, render_site_notification_html($title, $message, $details, $templateKey), true);
    return true;
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
        $states = $legacy;
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
        file_put_contents($path, json_encode($states, JSON_UNESCAPED_SLASHES), LOCK_EX);
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
        $data = $legacy;
        $result = $callback($data);
        if (file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
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
    $data['rate_events'] = is_array($data['rate_events'] ?? null) ? $data['rate_events'] : [];

    foreach ($data['whitelist'] as $ip => $entry) {
        if (!is_string($ip) || !valid_access_control_ip($ip)) {
            unset($data['whitelist'][$ip]);
            continue;
        }
        $data['whitelist'][$ip] = ['added_at' => max(0, (int) (is_array($entry) ? ($entry['added_at'] ?? 0) : 0))];
    }
    foreach ($data['blacklist'] as $ip => $entry) {
        $until = is_array($entry) ? (int) ($entry['until'] ?? 0) : 0;
        $permanent = is_array($entry) && !empty($entry['permanent']);
        if (!is_string($ip) || !valid_access_control_ip($ip) || (!$permanent && $until <= $now)) {
            unset($data['blacklist'][$ip]);
            continue;
        }
        $data['blacklist'][$ip] = [
            'until' => $permanent ? 0 : $until,
            'permanent' => $permanent,
            'reason' => (string) ($entry['reason'] ?? 'auto'),
            'added_at' => max(0, (int) ($entry['added_at'] ?? 0)),
        ];
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
    return with_ip_access_control(static function (array &$data): array {
        normalize_ip_access_control($data, time());
        $whitelist = [];
        foreach ($data['whitelist'] as $ip => $entry) $whitelist[] = ['ip' => $ip, 'added_at' => (int) $entry['added_at']];
        $blacklist = [];
        foreach ($data['blacklist'] as $ip => $entry) $blacklist[] = ['ip' => $ip, 'until' => (int) $entry['until'], 'permanent' => !empty($entry['permanent']), 'reason' => (string) $entry['reason'], 'added_at' => (int) $entry['added_at']];
        usort($whitelist, static fn(array $left, array $right): int => $left['ip'] <=> $right['ip']);
        usort($blacklist, static fn(array $left, array $right): int => ($left['permanent'] ? 0 : 1) <=> ($right['permanent'] ? 0 : 1) ?: $left['until'] <=> $right['until']);
        return ['whitelist' => $whitelist, 'blacklist' => $blacklist];
    });
}

function add_ip_to_whitelist(string $ip): void
{
    $ip = trim($ip);
    if (!valid_access_control_ip($ip)) throw new RuntimeException('请输入有效的 IPv4 或 IPv6 地址。');
    with_ip_access_control(static function (array &$data) use ($ip): void {
        $now = time();
        normalize_ip_access_control($data, $now);
        $data['whitelist'][$ip] = ['added_at' => $now];
        unset($data['blacklist'][$ip], $data['rate_events'][$ip]);
    });
}

function add_ip_to_blacklist(string $ip, int $duration = 1800, string $reason = 'manual', bool $permanent = false): void
{
    $ip = trim($ip);
    if (!valid_access_control_ip($ip)) throw new RuntimeException('请输入有效的 IPv4 或 IPv6 地址。');
    with_ip_access_control(static function (array &$data) use ($ip, $duration, $reason, $permanent): void {
        $now = time();
        normalize_ip_access_control($data, $now);
        if (isset($data['whitelist'][$ip])) throw new RuntimeException('该 IP 已在白名单中，不能加入黑名单。');
        $data['blacklist'][$ip] = ['until' => $permanent ? 0 : $now + max(60, $duration), 'permanent' => $permanent, 'reason' => $reason, 'added_at' => $now];
        unset($data['rate_events'][$ip]);
    });
}

function remove_ip_from_access_list(string $ip, string $list): void
{
    if (!in_array($list, ['whitelist', 'blacklist'], true) || !valid_access_control_ip($ip)) {
        throw new RuntimeException('IP 管控项目无效。');
    }
    with_ip_access_control(static function (array &$data) use ($ip, $list): void {
        normalize_ip_access_control($data, time());
        unset($data[$list][$ip]);
    });
}

/** @param list<mixed> $ips */
function remove_ips_from_access_list(array $ips, string $list): int
{
    if (!in_array($list, ['whitelist', 'blacklist'], true)) {
        throw new RuntimeException('IP 管控项目无效。');
    }
    $validIps = [];
    foreach ($ips as $ip) {
        $ip = is_string($ip) ? trim($ip) : '';
        if (valid_access_control_ip($ip)) $validIps[$ip] = true;
    }
    if ($validIps === []) throw new RuntimeException('请选择至少一个有效的 IP 地址。');

    return with_ip_access_control(static function (array &$data) use ($list, $validIps): int {
        normalize_ip_access_control($data, time());
        $removed = 0;
        foreach (array_keys($validIps) as $ip) {
            if (!isset($data[$list][$ip])) continue;
            unset($data[$list][$ip]);
            $removed++;
        }
        return $removed;
    });
}

/** @return array{blocked:bool,until:int,permanent:bool,reason:string,whitelisted:bool,newly_blocked:bool,count:int} */
function public_ip_access_status(string $ip): array
{
    $empty = ['blocked' => false, 'until' => 0, 'permanent' => false, 'reason' => '', 'whitelisted' => false, 'newly_blocked' => false, 'count' => 0];
    if (!valid_access_control_ip($ip)) return $empty;
    return with_ip_access_control(static function (array &$data) use ($ip, $empty): array {
        $now = time();
        normalize_ip_access_control($data, $now);
        if (isset($data['whitelist'][$ip])) return array_replace($empty, ['whitelisted' => true]);
        $entry = $data['blacklist'][$ip] ?? null;
        if (is_array($entry)) return array_replace($empty, ['blocked' => true, 'until' => (int) $entry['until'], 'permanent' => !empty($entry['permanent']), 'reason' => (string) $entry['reason']]);
        return $empty;
    });
}

/** @return array{blocked:bool,until:int,permanent:bool,reason:string,whitelisted:bool,newly_blocked:bool,count:int} */
function record_public_ip_request(string $ip): array
{
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
