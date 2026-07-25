<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/image_runtime.php';
require_once __DIR__ . '/../lib/settings_runtime.php';

initialize_storage();
$admin = require_admin();
send_admin_security_headers();
$requestedPage = $requestedPage ?? 'stats';
    if (!in_array($requestedPage, ['stats', 'upload', 'gallery', 'folders', 'logs', 'ip_control', 'settings'], true)) {
    $requestedPage = 'stats';
}
$images = list_images();
$categories = all_categories();
$categories = array_values(array_filter($categories, static fn(string $category): bool => $category !== 'default'));
array_unshift($categories, 'default');
$categoryDisplayNames = category_display_names();
$counters = request_counter_state();
$notice = flash('notice');
$noticeType = flash('notice_type') === 'error' ? 'error' : 'success';
$siteSettings = site_settings();
$siteSettings['notification_email_html_template'] = format_html_source_for_editor((string) $siteSettings['notification_email_html_template']);
$siteSettings['password_reset_email_html_template'] = format_html_source_for_editor((string) $siteSettings['password_reset_email_html_template']);
$ipAccessLists = ['whitelist' => [], 'blacklist' => []];
if ($requestedPage === 'ip_control') {
    try {
        $ipAccessLists = ip_access_control_lists();
    } catch (Throwable) {
        // The rest of the settings page remains available if this optional list cannot be read.
    }
}
$installation = installation_config() ?? [];
$smtpSettings = smtp_settings();
$adminAccount = admin_account_by_identity($admin) ?? ['username' => $admin, 'email' => (string) ($installation['admin_email'] ?? '')];
$adminAvatarUrl = admin_avatar_url();
$pendingEmailChange = is_array($_SESSION['admin_email_change'] ?? null) && (int) ($_SESSION['admin_email_change']['expires_at'] ?? 0) >= time()
    ? $_SESSION['admin_email_change']
    : null;
if ($pendingEmailChange === null) unset($_SESSION['admin_email_change']);
$menuOrderItems = [
    'dashboard.php' => ['label' => '数据看板', 'icon' => 'bar-chart-3'],
    'upload.php' => ['label' => '切片上传', 'icon' => 'upload-cloud'],
    'media.php' => ['label' => '图片资源管理', 'icon' => 'image'],
    'folders.php' => ['label' => '分类文件夹管理', 'icon' => 'folder-kanban'],
    'ip_control.php' => ['label' => 'IP 黑白名单管理', 'icon' => 'shield-ban'],
    'logs.php' => ['label' => '系统日志与流水', 'icon' => 'scroll-text'],
    'custom_api.php' => ['label' => 'API 自定义配置', 'icon' => 'sliders-horizontal'],
    'custom_api.php?view=docs' => ['label' => 'API 文档编辑', 'icon' => 'book-open-text'],
];

/** @return list<array{time:string,ip:string,image:string,status:int,method:string,type:string,bytes:int,device:string,channel:string}> */
function latest_access_logs(int $limit = 80): array
{
    $databaseRecords = database_access_log_records($limit);
    if ($databaseRecords !== []) return $databaseRecords;
    $files = glob(STORAGE_DIR . '/logs/*/*/*.jsonl') ?: [];
    rsort($files, SORT_STRING);
    $records = [];
    foreach ($files as $path) {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            continue;
        }
        $size = filesize($path);
        $read = min($size === false ? 0 : $size, 131072);
        if ($read > 0) {
            fseek($handle, -$read, SEEK_END);
            $lines = preg_split('/\R/', (string) fread($handle, $read)) ?: [];
            foreach (array_reverse($lines) as $line) {
                $record = json_decode($line, true);
                if (is_array($record) && isset($record['time'], $record['ip'], $record['image'])) {
                    $records[] = [
                        'time' => (string) $record['time'],
                        'ip' => (string) $record['ip'],
                        'image' => (string) $record['image'],
                        'status' => (int) ($record['status'] ?? 0),
                        'method' => (string) ($record['method'] ?? ''),
                        'type' => (string) ($record['type'] ?? ''),
                        'bytes' => (int) ($record['bytes'] ?? 0),
                        'device' => (string) ($record['device'] ?? 'root'),
                        // Old logs have no channel field; retain their former device-based grouping as a fallback.
                        'channel' => in_array($record['channel'] ?? null, ['auto', 'pc', 'mobile'], true)
                            ? (string) $record['channel']
                            : ((string) ($record['device'] ?? '') === 'mobile' ? 'mobile' : ((string) ($record['device'] ?? '') === 'pc' ? 'pc' : 'auto')),
                    ];
                    if (count($records) >= $limit) {
                        break 2;
                    }
                }
            }
        }
        fclose($handle);
    }
    return $records;
}

function image_category(array $image): string
{
    $folder = dirname($image['relative']);
    return $folder === '.' ? '默认分类' : $folder;
}

function format_html_source_for_editor(string $source): string
{
    $source = trim($source);
    if ($source === '' || str_contains($source, "\n")) return $source;

    $voidTags = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
    $expanded = preg_replace('/>\s*</', ">\n<", $source) ?? $source;
    $depth = 0;
    $lines = [];

    foreach (explode("\n", $expanded) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/^<\//', $line) === 1) $depth = max(0, $depth - 1);
        $lines[] = str_repeat('  ', $depth) . $line;

        if (preg_match('/^<([A-Za-z][\w:-]*)\b[^>]*>$/', $line, $matches) !== 1) continue;
        $tag = strtolower($matches[1]);
        if (in_array($tag, $voidTags, true) || str_ends_with($line, '/>')) continue;
        if (preg_match('/<\/' . preg_quote($matches[1], '/') . '\s*>$/i', $line) !== 1) $depth++;
    }

    return implode("\n", $lines);
}

$logs = latest_access_logs(500);
$availableLogDates = [];
if ($requestedPage === 'logs') {
    foreach (database_access_log_dates() as $day) $availableLogDates[$day] = true;
    foreach (glob(STORAGE_DIR . '/logs/*/*/*.jsonl') ?: [] as $logPath) {
        $handle = @fopen($logPath, 'rb');
        if ($handle === false) continue;
        while (($line = fgets($handle)) !== false) {
            $record = json_decode($line, true);
            if (!is_array($record) || !isset($record['time'])) continue;
            try {
                $availableLogDates[(new DateTimeImmutable((string) $record['time']))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d')] = true;
            } catch (Throwable) {
                continue;
            }
        }
        fclose($handle);
    }
}
$availableLogDates = array_keys($availableLogDates);
$timezone = new DateTimeZone('Asia/Shanghai');
$todayDate = new DateTimeImmutable('today', $timezone);
$dailyLabels = [];
$dailyTotals = [];
$dailyVisitors = [];
$dailyBytes = [];
$dailyPc = [];
$dailyMobile = [];
$dailyAuto = [];
for ($offset = 13; $offset >= 0; $offset--) {
    $date = $todayDate->sub(new DateInterval('P' . $offset . 'D'));
    $key = $date->format('Y-m-d');
    $dailyLabels[] = $date->format('m-d');
    $dailyTotals[$key] = 0;
    $dailyVisitors[$key] = [];
    $dailyBytes[$key] = 0;
    $dailyPc[$key] = 0;
    $dailyMobile[$key] = 0;
    $dailyAuto[$key] = 0;
}
foreach ($logs as $log) {
    try {
        $key = (new DateTimeImmutable($log['time']))->setTimezone($timezone)->format('Y-m-d');
    } catch (Throwable) {
        continue;
    }
    if (array_key_exists($key, $dailyTotals)) {
        $dailyTotals[$key]++;
        $dailyVisitors[$key][$log['ip']] = true;
        $dailyBytes[$key] += $log['bytes'];
        if ($log['channel'] === 'pc') {
            $dailyPc[$key]++;
        } elseif ($log['channel'] === 'mobile') {
            $dailyMobile[$key]++;
        } else {
            $dailyAuto[$key]++;
        }
    }
}
$todayRequests = $dailyTotals[$todayDate->format('Y-m-d')] ?? 0;
$yesterdayRequests = $dailyTotals[$todayDate->sub(new DateInterval('P1D'))->format('Y-m-d')] ?? 0;
$requestChange = $yesterdayRequests > 0 ? round((($todayRequests - $yesterdayRequests) / $yesterdayRequests) * 100) : 0;
$chartLabels = array_slice($dailyLabels, -7);
$chartRequests = array_slice(array_values($dailyTotals), -7);
$chartOutputBytes = array_slice(array_values($dailyBytes), -7);
$requestPeriodStats = [];
foreach (['today' => 1, 'yesterday' => 1, 'seven_days' => 7, 'fourteen_days' => 14] as $period => $days) {
    $keys = $period === 'yesterday' ? [array_keys($dailyTotals)[count($dailyTotals) - 2]] : array_slice(array_keys($dailyTotals), -$days);
    $pcRequests = 0;
    $mobileRequests = 0;
    $autoRequests = 0;
    foreach ($keys as $key) {
        $pcRequests += $dailyPc[$key];
        $mobileRequests += $dailyMobile[$key];
        $autoRequests += $dailyAuto[$key];
    }
    $requestPeriodStats[$period] = ['pc' => $pcRequests, 'mobile' => $mobileRequests, 'auto' => $autoRequests, 'total' => $pcRequests + $mobileRequests + $autoRequests];
}
$requestDailySeries = [];
foreach (array_keys($dailyTotals) as $day) {
    $requestDailySeries[] = ['day' => $day, 'pc' => $dailyPc[$day], 'mobile' => $dailyMobile[$day], 'auto' => $dailyAuto[$day]];
}
$pcCount = count(array_filter($images, static fn(array $image): bool => $image['device'] === 'pc'));
$mobileCount = count($images) - $pcCount;
$pcImageBytes = 0;
$mobileImageBytes = 0;
foreach ($images as $image) {
    if ($image['device'] === 'pc') {
        $pcImageBytes += (int) $image['size'];
    } else {
        $mobileImageBytes += (int) $image['size'];
    }
}
$totalImageBytes = $pcImageBytes + $mobileImageBytes;
$categoryCounts = array_fill_keys($categories, 0);
$defaultCategoryCount = 0;
$categoryTimes = [];
foreach ($images as $image) {
    $category = dirname($image['relative']);
    if ($category === '.' || $category === 'default') {
        $defaultCategoryCount++;
    } elseif (isset($categoryCounts[$category])) {
        $categoryCounts[$category]++;
    }
}
foreach ($categories as $category) {
    if ($category === 'default') {
        $categoryTimes[$category] = '内置根目录';
        continue;
    }
    $timestamp = @filemtime(category_path('pc', $category));
    $categoryTimes[$category] = $timestamp === false ? '—' : date('Y-m-d', $timestamp);
}
$categoryCounts['default'] = $defaultCategoryCount;
$selectedFolder = (string) ($_GET['folder'] ?? 'all');
if ($selectedFolder !== 'all' && !in_array($selectedFolder, $categories, true)) {
    $selectedFolder = 'all';
}
$galleryImages = array_values(array_filter($images, static function (array $image) use ($selectedFolder): bool {
    if ($selectedFolder === 'all') {
        return true;
    }
    $folder = dirname($image['relative']);
    return $selectedFolder === 'default' ? ($folder === '.' || $folder === 'default') : $folder === $selectedFolder;
}));
try {
    seed_upload_audit_records($images);
    $uploadAuditEntries = list_upload_audit_records();
} catch (Throwable) {
    $uploadAuditEntries = [];
}
$auditRecords = array_map(static function (array $record): array {
    try {
        $time = (new DateTimeImmutable((string) $record['time']))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        $time = '-';
    }
    return [
        'time' => $time,
        'name' => (string) $record['name'],
        'category' => (string) $record['category'],
        'dimensions' => (int) $record['width'] . ' × ' . (int) $record['height'],
        'size' => format_file_size((int) $record['size']),
    ];
}, $uploadAuditEntries);
$base = rtrim(application_base_url(), '/');
$rootApi = $base . '/';
$pcApi = $base . '/pc/';
$mobileApi = $base . '/m/';
?>
<!doctype html>
<html lang="zh-CN" data-theme="<?= html((string) $siteSettings['theme_mode']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= html((string) $siteSettings['site_name']) ?> 后台</title>
    <link rel="icon" href="<?= html(site_favicon_url()) ?>">
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" integrity="sha384-vsrfeLOOY6KuIYKDlmVH5UiBmgIdB1oEf7p01YgWHuqmOHfZr374+odEv96n9tNC" crossorigin="anonymous"></script>
    <script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.js" integrity="sha384-LtmWBcrD5iuFIR4sFphS8IiiaftkypL5dJzLKywbyd9ATLB7ZbPz3JYnI9nvXHkV" crossorigin="anonymous"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap');
        body { font-family: 'Inter', 'Helvetica Neue', sans-serif; overflow: hidden; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: var(--picapi-background); }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #475569; }
        aside .h-16 > div > span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        #gallery .overflow-x-auto.custom-scrollbar:has(table) { height: 520px; overflow: auto; scrollbar-gutter: stable; }
        #gallery .picapi-gallery-thumb { position: relative; display: grid; width: 2.5rem; height: 2.5rem; place-items: center; overflow: hidden; }
        #gallery .picapi-gallery-thumb img { position: relative; z-index: 1; opacity: 0; transition: opacity .18s ease; }
        #gallery .picapi-gallery-thumb.is-loaded img { opacity: 1; }
        #gallery .picapi-gallery-thumb-loader { position: absolute; z-index: 0; width: .9rem; height: .9rem; border: 2px solid rgb(100 116 139 / .4); border-top-color: #818cf8; border-radius: 9999px; animation: picapi-thumbnail-spin .72s linear infinite; }
        #gallery .picapi-gallery-thumb.is-loaded .picapi-gallery-thumb-loader,
        #gallery .picapi-gallery-thumb.is-error .picapi-gallery-thumb-loader { display: none; }
         #gallery .picapi-gallery-thumb.is-error { border-color: rgb(244 63 94 / .7); }
         #gallery tbody tr.picapi-gallery-previewed { background: color-mix(in srgb, var(--picapi-accent) 11%, var(--picapi-surface)); box-shadow: inset 3px 0 0 var(--picapi-accent); }
         @keyframes picapi-thumbnail-spin { to { transform: rotate(360deg); } }
         .picapi-preview-loader { width: 2.35rem; height: 2.35rem; border: 3px solid rgb(148 163 184 / .32); border-top-color: var(--picapi-accent); border-radius: 9999px; animation: picapi-preview-spin .72s linear infinite; box-shadow: 0 0 1.5rem color-mix(in srgb, var(--picapi-accent) 35%, transparent); }
         @keyframes picapi-preview-spin { to { transform: rotate(360deg); } }
        #logs > div:nth-of-type(3) .overflow-x-auto.custom-scrollbar,
        #logs > div:nth-of-type(4) .overflow-x-auto.custom-scrollbar { height: 340px; overflow: auto; scrollbar-gutter: stable; }
        [data-ip-list-table] { height: 360px; overflow: auto; scrollbar-gutter: stable; }
        #gallery .overflow-x-auto.custom-scrollbar thead th,
        #logs > div:nth-of-type(3) .overflow-x-auto.custom-scrollbar thead th,
        #logs > div:nth-of-type(4) .overflow-x-auto.custom-scrollbar thead th,
        [data-ip-list-table] thead th { position: sticky; top: 0; z-index: 2; background: var(--picapi-surface); }
        [data-export-calendar] { min-height: 13.25rem; grid-auto-rows: 2rem; align-content: start; }
        [data-theme-workbench] { background: linear-gradient(135deg, color-mix(in srgb, var(--picapi-surface) 92%, var(--picapi-accent)) 0%, var(--picapi-surface) 48%); }
        [data-theme-color-control] { background: color-mix(in srgb, var(--picapi-surface) 82%, var(--picapi-background)); }
        [data-theme-color-control] input[type="color"] { appearance: none; -webkit-appearance: none; }
        [data-theme-color-control] input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; }
        [data-theme-color-control] input[type="color"]::-webkit-color-swatch { border: 0; border-radius: 5px; }
        [data-theme-preview] { background: var(--picapi-background); color: var(--picapi-text); }
        [data-theme-preview-card] { background: var(--picapi-surface); border-color: var(--picapi-border); }
        [data-theme-preview-notice] { background: color-mix(in srgb, var(--picapi-surface) 82%, var(--picapi-accent)); border-color: color-mix(in srgb, var(--picapi-accent) 62%, var(--picapi-border)); color: var(--picapi-text); }
        [data-theme-preview-muted] { color: var(--picapi-muted); }
        [data-theme-preview-button] { background: var(--picapi-accent); color: #fff; }
        [data-template-code-editor] { position: relative; height: 450px; overflow: hidden; border: 1px solid #334155; border-radius: .5rem; background: #020617; box-shadow: inset 0 1px 0 rgb(148 163 184 / .08); }
        [data-template-code-editor] > pre,
        [data-template-code-editor] > textarea { box-sizing: border-box; width: 100%; height: 450px; margin: 0; padding: .875rem 1rem; font: 12px/1.7 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; tab-size: 2; white-space: pre; }
        [data-template-code-editor] > pre { position: absolute; z-index: 2; inset: 0; overflow: hidden; pointer-events: none; color: #cbd5e1; }
        [data-template-code-editor] > textarea { position: relative; z-index: 1; display: block; resize: none; overflow: auto; border: 0; outline: 0; background-color: transparent !important; color: transparent; caret-color: #f8fafc; -webkit-text-fill-color: transparent; }
        [data-template-code-editor] > textarea::selection { background: rgb(99 102 241 / .45); }
        [data-template-code-editor]:focus-within { border-color: #6366f1; box-shadow: 0 0 0 3px rgb(99 102 241 / .16), inset 0 1px 0 rgb(148 163 184 / .08); }
        .picapi-code-comment { color: #64748b; font-style: italic; }
        .picapi-code-doctype { color: #f472b6; }
        .picapi-code-tag { color: #7dd3fc; }
        .picapi-code-attribute { color: #c4b5fd; }
        .picapi-code-string { color: #fcd34d; }
        .picapi-code-token { color: #fb923c; }
        @media (prefers-reduced-motion: no-preference) {
            body.picapi-page-ready > div > aside { animation: picapi-sidebar-enter .18s ease-out both; }
            body.picapi-page-ready > div > main { animation: picapi-content-enter .22s ease-out both; }
            body.picapi-page-leaving > div > aside,
            body.picapi-page-leaving > div > main { pointer-events: none; opacity: 0; transform: translateY(6px); transition: opacity .14s ease, transform .14s ease; }
            @keyframes picapi-sidebar-enter { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
            @keyframes picapi-content-enter { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        }
        @media (max-width: 640px) {
            #gallery .overflow-x-auto.custom-scrollbar:has(table) { height: 420px; }
            #logs > div:nth-of-type(3) .overflow-x-auto.custom-scrollbar,
            #logs > div:nth-of-type(4) .overflow-x-auto.custom-scrollbar { height: 280px; }
        }
        .mobile-menu-toggle { display: none; }
        @media (max-width: 767px) {
            body > div > aside { flex: none; position: relative; width: 100%; }
            .mobile-menu-toggle { display: inline-flex; align-items: center; justify-content: center; width: 2.5rem; height: 2.5rem; border-radius: .5rem; color: #cbd5e1; }
            body:not(.mobile-menu-open) > div > aside nav,
            body:not(.mobile-menu-open) > div > aside > div:last-child { display: none; }
            body.mobile-menu-open > div > aside nav { display: block; max-height: calc(100vh - 8rem); overflow-y: auto; border-bottom: 1px solid #1e293b; }
            body.mobile-menu-open > div > aside > div:last-child { display: flex; }
        }
    </style>
    <style><?= site_theme_css($siteSettings) ?></style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<div class="h-screen w-screen flex flex-col md:flex-row overflow-hidden">
    <aside class="w-full md:w-64 md:min-w-[16rem] md:shrink-0 bg-slate-900 border-b md:border-b-0 md:border-r border-slate-800 flex flex-col justify-between z-10">
        <div>
            <div class="h-16 flex items-center px-6 border-b border-slate-800 justify-between"><div class="flex items-center gap-2.5 font-semibold text-white"><i data-lucide="layout-dashboard" class="w-5 h-5 text-indigo-400"></i><span><?= html((string) $siteSettings['site_name']) ?></span></div></div>
            <nav class="p-4 space-y-1">
                <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg <?= $requestedPage === 'stats' ? 'bg-slate-800 text-indigo-400' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200' ?> font-medium text-sm transition" href="dashboard.php"><i data-lucide="bar-chart-3" class="w-4 h-4"></i>数据看板</a>
                <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg <?= $requestedPage === 'upload' ? 'bg-slate-800 text-indigo-400' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200' ?> font-medium text-sm transition" href="upload.php"><i data-lucide="upload-cloud" class="w-4 h-4"></i>切片上传</a>
                <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg <?= $requestedPage === 'gallery' ? 'bg-slate-800 text-indigo-400' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200' ?> font-medium text-sm transition" href="media.php"><i data-lucide="image" class="w-4 h-4"></i>图片资源管理</a>
                 <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg <?= $requestedPage === 'folders' ? 'bg-slate-800 text-indigo-400' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200' ?> font-medium text-sm transition" href="folders.php"><i data-lucide="folder-kanban" class="w-4 h-4"></i>分类文件夹管理</a>
                 <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg <?= $requestedPage === 'ip_control' ? 'bg-slate-800 text-indigo-400' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200' ?> font-medium text-sm transition" href="ip_control.php"><i data-lucide="shield-ban" class="w-4 h-4"></i>IP 黑白名单管理</a>
                 <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg <?= $requestedPage === 'logs' ? 'bg-slate-800 text-indigo-400' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200' ?> font-medium text-sm transition" href="logs.php"><i data-lucide="scroll-text" class="w-4 h-4"></i>系统日志与流水</a>
                <a class="flex items-center gap-3 px-3 py-2.5 rounded-lg <?= $requestedPage === 'settings' ? 'bg-slate-800 text-indigo-400' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200' ?> font-medium text-sm transition" href="settings.php"><i data-lucide="settings-2" class="w-4 h-4"></i>系统设置</a>
            </nav>
        </div>
        <div class="p-4 border-t border-slate-800 flex items-center justify-between"><div class="flex items-center gap-2"><div data-admin-avatar class="w-8 h-8 overflow-hidden rounded-full bg-indigo-600 text-white flex items-center justify-center text-sm font-semibold"><?php if ($adminAvatarUrl !== null): ?><img src="<?= html($adminAvatarUrl) ?>" alt="管理员头像" class="h-full w-full object-cover"><?php else: ?><?= html(display_initial($admin)) ?><?php endif; ?></div><span class="text-sm font-medium text-slate-300"><?= html($admin) ?></span></div><a href="logout.php" class="text-slate-500 hover:text-rose-400 transition" title="退出登录"><i data-lucide="log-out" class="w-4 h-4"></i></a></div>
    </aside>
    <main class="flex-1 h-full overflow-y-auto p-4 md:p-8 custom-scrollbar bg-slate-950"><div class="lg:max-w-7xl mx-auto w-full space-y-8">
        <?php if ($notice !== null): ?><p class="notice"><?= html($notice) ?></p><?php endif; ?>

        <?php if ($requestedPage === 'stats'): ?><section id="stats" class="space-y-6"><div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 bg-slate-900 p-4 border border-slate-800 rounded-xl"><div><h2 class="text-xl font-semibold text-white">数据实时看板</h2><p class="text-xs text-slate-400 mt-0.5">自适应 API 响应分流系统</p></div><div class="grid grid-cols-1 sm:grid-cols-3 gap-2 text-xs"><div class="bg-slate-950 p-2 rounded border border-slate-800"><span class="font-semibold text-indigo-400 block mb-0.5">[PC端接口]</span><code class="text-slate-400"><?= html($pcApi) ?>?type=image</code></div><div class="bg-slate-950 p-2 rounded border border-slate-800"><span class="font-semibold text-emerald-400 block mb-0.5">[手机端接口]</span><code class="text-slate-400"><?= html($mobileApi) ?>?type=image</code></div><div class="bg-slate-950 p-2 rounded border border-slate-800"><span class="font-semibold text-amber-400 block mb-0.5">[自适应接口]</span><code class="text-slate-400"><?= html($rootApi) ?>?type=json</code></div></div></div><div class="grid grid-cols-1 sm:grid-cols-3 gap-4"><div class="bg-slate-900 p-5 rounded-xl border border-slate-800 shadow-sm"><p class="text-xs font-medium text-slate-400 uppercase tracking-wider">今日访问量</p><div class="flex items-baseline gap-2 mt-2"><span class="text-3xl font-semibold text-white"><?= $todayRequests ?></span><span class="text-xs font-medium <?= $requestChange >= 0 ? 'text-emerald-400 bg-emerald-500/10' : 'text-rose-400 bg-rose-500/10' ?> px-1.5 py-0.5 rounded flex items-center gap-0.5"><i data-lucide="trending-up" class="w-3 h-3"></i><?= $requestChange ?>%</span></div></div><div class="bg-slate-900 p-5 rounded-xl border border-slate-800 shadow-sm"><p class="text-xs font-medium text-slate-400 uppercase tracking-wider">昨日访问量</p><div class="flex items-baseline gap-2 mt-2"><span class="text-3xl font-semibold text-slate-300"><?= $yesterdayRequests ?></span></div></div><div class="bg-slate-900 p-5 rounded-xl border border-slate-800 shadow-sm bg-gradient-to-br from-indigo-950 to-slate-900"><p class="text-xs font-medium text-slate-400 uppercase tracking-wider">总访问量</p><div class="flex items-baseline gap-2 mt-2"><span class="text-3xl font-bold text-white"><?= (int) ($counters['total'] ?? 0) ?></span></div></div></div><div class="grid grid-cols-1 gap-6"><div class="bg-slate-900 p-5 rounded-xl border border-slate-800"><div class="flex items-center justify-between mb-4"><h3 class="text-sm font-semibold text-slate-200">每日请求频次（近 7 天）</h3></div><div class="h-64"><canvas id="requestsChart"></canvas></div></div><div class="bg-slate-900 p-5 rounded-xl border border-slate-800"><div class="flex items-center justify-between mb-4"><h3 class="text-sm font-semibold text-slate-200">终端图片资源分布</h3></div><div class="h-64"><canvas id="trafficChart"></canvas></div></div></div></section><?php endif; ?>

        <?php if ($requestedPage === 'upload'): ?><section id="upload" class="mt-8 bg-slate-900 p-6 rounded-xl border border-slate-800 space-y-6"><div><h2 class="text-lg font-semibold text-white">大图切片加速上传</h2><p class="text-sm text-slate-400">针对超大壁纸或无损图提供分片并行、秒传及队列控制。</p></div><form id="chunk-upload-form" method="post" action="action.php" enctype="multipart/form-data" class="space-y-6"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="upload"><div class="grid grid-cols-1 md:grid-cols-3 gap-6"><div class="space-y-4 text-xs"><div><label class="block text-slate-400 mb-1.5">目标分类文件夹</label><select name="category" class="w-full bg-slate-800 border border-slate-700 text-white rounded-lg px-3 py-2 focus:outline-none"><option value="">默认分类（根目录）</option><?php foreach ($categories as $category): ?><option value="<?= html($category) ?>"><?= html($category) ?></option><?php endforeach; ?></select></div><div><label class="block text-slate-400 mb-1.5">属性备注</label><input name="memo" type="text" placeholder="标签..." class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white focus:outline-none"></div><p class="text-slate-500 leading-relaxed">竖图自动进入移动端目录；横图和方图自动进入 PC 目录。</p></div><div class="md:col-span-2"><label for="fileInput" class="block border-2 border-dashed border-slate-700 hover:border-indigo-500/50 rounded-xl p-8 text-center cursor-pointer bg-slate-950 transition"><input name="image" type="file" id="fileInput" accept="image/jpeg,image/png,image/gif,image/webp" class="hidden" required><i data-lucide="cloud-upload" class="w-8 h-8 mx-auto mb-3 text-slate-500"></i><p class="text-sm font-medium text-slate-300">点击或将大图拖拽到此处</p><p class="text-xs text-slate-500 mt-1">JPEG、PNG、GIF、WebP · 最大 20 MB</p></label></div></div><div id="upload-progress-panel" class="hidden border border-slate-800 bg-slate-950 rounded-xl p-4 space-y-3"><div class="flex items-center justify-between text-xs"><span id="upload-filename" class="text-slate-300 truncate"></span><span id="upload-status-badge" class="bg-indigo-500/10 text-indigo-400 px-2 py-0.5 rounded-full">等待上传</span></div><div class="w-full bg-slate-800 rounded-full h-2 overflow-hidden"><div id="upload-progress-bar" class="bg-indigo-500 h-2" style="width:25%"></div></div></div><div class="flex justify-end"><button type="submit" class="flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium rounded-lg transition shadow-lg shadow-indigo-600/10"><i data-lucide="upload" class="w-4 h-4"></i>开始上传</button></div></form></section><?php endif; ?>

        <?php if ($requestedPage === 'gallery'): ?><section id="gallery" class="space-y-4"><div class="flex flex-col sm:flex-row gap-4 items-start sm:items-center justify-between bg-slate-900 p-4 rounded-xl border border-slate-800"><div class="flex flex-wrap gap-2"><a href="media.php" class="px-3 py-1.5 text-xs font-medium rounded-lg transition <?= $selectedFolder === 'all' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-slate-800 border border-slate-700 text-slate-300 hover:bg-slate-700' ?>">全部资源 (<?= count($images) ?>)</a><?php foreach ($categories as $category): ?><a href="media.php?folder=<?= rawurlencode($category) ?>" class="px-3 py-1.5 text-xs font-medium rounded-lg transition <?= $selectedFolder === $category ? 'bg-indigo-600 text-white shadow-sm' : 'bg-slate-800 border border-slate-700 text-slate-300 hover:bg-slate-700' ?>"><?= $category === 'default' ? '默认分类' : html($category) ?> (<?= $categoryCounts[$category] ?? 0 ?>)</a><?php endforeach; ?></div></div><div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-2xl"><div class="relative min-h-[48px] border-b border-slate-800 bg-slate-900/50"><div class="p-3 flex flex-col sm:flex-row sm:items-center justify-between gap-4 text-xs text-slate-400"><div class="flex items-center gap-4"><div>显示第 <span class="font-mono text-slate-300">1</span> 至 <span class="font-mono text-slate-300"><?= count($galleryImages) ?></span> 行，共 <span class="font-mono text-slate-300"><?= count($galleryImages) ?></span> 条资源</div><div class="flex items-center gap-1.5 border-l border-slate-800 pl-4"><span>每页</span><select class="bg-slate-800 border border-slate-700 text-white rounded px-1.5 py-0.5 focus:outline-none"><option selected>20</option></select><span>条</span></div></div><div class="flex items-center gap-1"><button disabled class="px-2 py-1 rounded bg-slate-800 border border-slate-700 text-slate-500 text-[11px] cursor-not-allowed">上一页</button><button class="px-2.5 py-1 rounded bg-indigo-600 text-white text-[11px] font-semibold">1</button><button disabled class="px-2 py-1 rounded bg-slate-800 border border-slate-700 text-slate-500 text-[11px] cursor-not-allowed">下一页</button></div></div></div><div class="overflow-x-auto custom-scrollbar"><table class="w-full text-left border-collapse text-xs"><thead><tr class="border-b border-slate-800 text-slate-400 bg-slate-950/40 font-medium"><th class="p-3.5 w-12 text-center"><input type="checkbox" disabled class="w-4 h-4 rounded border-slate-700 bg-slate-800 text-indigo-600 cursor-not-allowed"></th><th class="p-3.5 w-16">预览</th><th class="p-3.5 min-w-[280px]">相对路径/文件名</th><th class="p-3.5 w-24">资源库</th><th class="p-3.5 w-28">分辨率</th><th class="p-3.5 w-24">大小</th><th class="p-3.5 w-40">最后修改</th><th class="p-3.5 w-12 text-center">操作</th></tr></thead><tbody class="divide-y divide-slate-800/60 text-slate-300"><?php if ($galleryImages === []): ?><tr><td colspan="8" class="p-8 text-center text-slate-600">当前分类暂无图片资源。</td></tr><?php endif; ?><?php foreach ($galleryImages as $image): ?><tr class="hover:bg-slate-800/40 transition"><td class="p-3 text-center"><input type="checkbox" disabled class="w-4 h-4 rounded border-slate-700 bg-slate-800 text-indigo-600 cursor-not-allowed"></td><td class="p-3"><div class="picapi-gallery-thumb is-loading w-10 h-10 rounded border border-slate-700 bg-slate-950"><span class="picapi-gallery-thumb-loader" aria-hidden="true"></span><img data-preview-src="<?= html(image_url($image['device'], $image['relative'])) ?>" data-preview-revision="<?= (int) filemtime($image['path']) ?>" loading="lazy" decoding="async" class="w-full h-full object-cover" alt="" aria-busy="true"></div></td><td class="p-3 font-mono text-slate-200 truncate max-w-xs"><?= html($image['relative']) ?></td><td class="p-3"><span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold <?= $image['device'] === 'mobile' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20' ?>"><i data-lucide="<?= $image['device'] === 'mobile' ? 'smartphone' : 'monitor' ?>" class="w-3 h-3"></i><?= $image['device'] === 'mobile' ? '手机' : 'PC' ?></span></td><td class="p-3 text-slate-400"><?= $image['width'] ?> × <?= $image['height'] ?></td><td class="p-3 text-slate-400"><?= html(format_file_size($image['size'])) ?></td><td class="p-3 text-slate-500"><?= date('Y-m-d H:i', filemtime($image['path'])) ?></td><td class="p-3 text-center"><form method="post" action="action.php" onsubmit="return confirm('确认永久删除此图片？')"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="delete_image"><input type="hidden" name="device" value="<?= html($image['device']) ?>"><input type="hidden" name="relative" value="<?= html($image['relative']) ?>"><button class="text-slate-500 hover:text-rose-400 bg-transparent" type="submit" title="删除"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button></form></td></tr><?php endforeach; ?></tbody></table></div></div></section><?php endif; ?>

        <?php if ($requestedPage === 'folders'): ?><section id="folders" class="space-y-6"><div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-900 p-4 border border-slate-800 rounded-xl"><div><h2 class="text-lg font-semibold text-white">分类文件夹管理</h2><p class="text-xs text-slate-400 mt-0.5">配置核心图片存储分类，支持目录重命名及规则自定义</p></div><button type="button" onclick="openFolderModal('create')" class="flex items-center gap-2 px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium rounded-lg transition shadow-lg shadow-indigo-600/10"><i data-lucide="plus" class="w-4 h-4"></i>创建新分类</button></div><div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4"><?php if ($categories === []): ?><div class="sm:col-span-2 lg:col-span-3 bg-slate-900 border border-slate-800 rounded-xl p-8 text-center text-sm text-slate-500">暂无分类文件夹。点击右上角创建第一个分类。</div><?php endif; ?><?php foreach ($categories as $category): ?><div class="bg-slate-900 border border-slate-800 rounded-xl p-5 flex flex-col justify-between hover:border-slate-700 transition space-y-4"><div class="space-y-2"><div class="flex items-center justify-between"><div class="p-2 bg-slate-950 border border-slate-800 text-indigo-400 rounded-lg"><i data-lucide="folder" class="w-5 h-5"></i></div><span class="text-[11px] px-2 py-0.5 rounded font-mono bg-slate-950 text-slate-400 border border-slate-800"><?= html($category) ?></span></div><div><h4 class="text-sm font-semibold text-white tracking-tight"><?= html(category_display_name($category)) ?></h4><p class="text-xs text-slate-500 mt-1">创建时间: <?= html($categoryTimes[$category]) ?></p></div></div><div class="border-t border-slate-800/80 pt-3 flex items-center justify-between text-xs"><span class="text-slate-400 font-medium">包含资源数: <strong class="font-mono text-indigo-400"><?= $categoryCounts[$category] ?? 0 ?></strong> 条</span><div class="flex items-center gap-1.5"><button type="button" data-category="<?= html($category) ?>" onclick="openFolderModal('rename', this.dataset.category)" class="p-1 text-slate-400 hover:text-indigo-400 transition" title="重命名"><i data-lucide="edit-3" class="w-4 h-4"></i></button><?php if ($category !== 'default'): ?><form method="post" action="action.php" onsubmit="return confirm('确认删除空分类 <?= html($category) ?>？')"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="category" value="<?= html($category) ?>"><button class="p-1 text-slate-400 hover:text-rose-400 transition bg-transparent" type="submit" title="删除"><i data-lucide="trash-2" class="w-4 h-4"></i></button></form><?php endif; ?></div></div></div><?php endforeach; ?></div></section><div id="folder-modal" class="hidden fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4"><form method="post" action="action.php" id="folder-form" class="bg-slate-900 border border-slate-800 rounded-xl shadow-2xl w-full max-w-sm p-6 space-y-4"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" id="folder-action" value="create_category"><input type="hidden" name="old_category" id="old-folder-key"><div class="flex items-center justify-between"><h3 id="modal-title" class="font-semibold text-white text-sm">新建分类文件夹</h3><button type="button" onclick="closeFolderModal()" class="text-slate-400 hover:text-white"><i data-lucide="x" class="w-4 h-4"></i></button></div><div class="space-y-3 text-xs"><div><label class="block text-slate-400 mb-1">文件夹标识 (唯一)</label><input id="modal-folder-key" name="category" required pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,48}" placeholder="例如 anime 或 4k_wallpaper" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white focus:outline-none"></div><p class="text-slate-500">系统会自动创建 PC 和移动端目录，以及该分类的请求入口。</p></div><div class="flex justify-end gap-2 pt-2"><button type="button" onclick="closeFolderModal()" class="bg-slate-800 hover:bg-slate-700 text-slate-300 px-3 py-1.5 rounded-lg text-xs">取消</button><button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg text-xs">保存分类</button></div></form></div><?php endif; ?>

        <?php if ($requestedPage === 'logs'): ?><section id="logs" class="space-y-8"><div class="bg-slate-900 p-4 border border-slate-800 rounded-xl flex items-center justify-between"><div><h2 class="text-lg font-semibold text-white">系统审计与访问流水</h2><p class="text-xs text-slate-400 mt-0.5">监控 API 接口的每日历史吞吐率、文件改动审计及最新请求链路</p></div><a href="export_logs.php" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-medium rounded-lg transition flex items-center gap-1.5"><i data-lucide="download" class="w-3.5 h-3.5"></i>导出日志</a></div><div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden"><div class="p-4 border-b border-slate-800 bg-slate-900/50 flex items-center gap-2"><i data-lucide="activity" class="w-4 h-4 text-indigo-400"></i><h3 class="text-xs font-semibold text-slate-200">近 14 天吞吐指标</h3></div><div class="overflow-x-auto custom-scrollbar"><table class="w-full text-left border-collapse text-[11px] font-mono"><thead><tr class="border-b border-slate-800 text-slate-400 bg-slate-950/40 font-medium"><th class="p-3">日期</th><th class="p-3">总请求量</th><th class="p-3">独立访客 IP</th><th class="p-3">带宽占用</th><th class="p-3">核心接口 (ROOT)</th><th class="p-3">PC 接口</th><th class="p-3">手机接口 (MOBILE)</th></tr></thead><tbody class="divide-y divide-slate-800/60 text-slate-300"><?php foreach (array_reverse(array_keys($dailyTotals)) as $day): ?><tr class="hover:bg-slate-800/30 transition"><td class="p-3 text-slate-400 font-sans"><?= html($day) ?></td><td class="p-3 text-slate-200 font-bold"><?= $dailyTotals[$day] ?></td><td class="p-3"><?= count($dailyVisitors[$day]) ?></td><td class="p-3 text-indigo-400"><?= html(format_file_size($dailyBytes[$day])) ?></td><td class="p-3"><?= max(0, $dailyTotals[$day] - $dailyPc[$day] - $dailyMobile[$day]) ?></td><td class="p-3"><?= $dailyPc[$day] ?></td><td class="p-3"><?= $dailyMobile[$day] ?></td></tr><?php endforeach; ?></tbody></table></div></div><div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden"><div class="p-4 border-b border-slate-800 bg-slate-900/50 flex flex-col md:flex-row md:items-center justify-between gap-4"><div class="flex items-center gap-4"><div class="flex items-center gap-2"><i data-lucide="shield-check" class="w-4 h-4 text-emerald-400"></i><h3 class="text-xs font-semibold text-slate-200">上传审计记录</h3></div><span class="bg-slate-800 border border-slate-700 text-slate-300 text-[10px] px-2 py-1 rounded">已提交上传记录</span></div><div class="flex items-center gap-4 text-xs text-slate-400"><span>每页 <strong class="text-white">20</strong> 行</span><button type="button" class="px-2.5 py-1 rounded bg-indigo-600 text-white text-[11px] font-bold">1</button></div></div><div class="overflow-x-auto custom-scrollbar"><table class="w-full text-left border-collapse text-[11px]"><thead><tr class="border-b border-slate-800 text-slate-400 bg-slate-950/40 font-medium"><th class="p-3 w-40">上传时间</th><th class="p-3">源代码文件名与摘要名</th><th class="p-3 w-32">源目录</th><th class="p-3 w-32">几何分辨率</th><th class="p-3 w-28">文件大小</th></tr></thead><tbody class="divide-y divide-slate-800/60 text-slate-400 font-mono"><?php if ($images === []): ?><tr><td class="p-8 text-center text-slate-600 font-sans" colspan="5">无已提交的上传审计事务。</td></tr><?php endif; ?><?php foreach (array_slice($images, 0, 20) as $image): ?><tr class="hover:bg-slate-800/30 transition"><td class="p-3 text-slate-500 font-sans"><?= date('Y-m-d H:i:s', filemtime($image['path'])) ?></td><td class="p-3 text-slate-200"><?= html(basename($image['relative'])) ?></td><td class="p-3"><?= html(image_category($image)) ?></td><td class="p-3"><?= $image['width'] ?> × <?= $image['height'] ?></td><td class="p-3 text-indigo-400"><?= html(format_file_size($image['size'])) ?></td></tr><?php endforeach; ?></tbody></table></div></div><div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-xl"><div class="p-4 border-b border-slate-800 bg-slate-900/50 flex flex-col md:flex-row md:items-center justify-between gap-4"><div class="flex items-center gap-2"><i data-lucide="database" class="w-4 h-4 text-amber-500"></i><h3 class="text-xs font-semibold text-slate-200">最近访问流水</h3></div><div class="flex items-center gap-4 text-xs text-slate-400"><span>每页显示 <?= count($logs) ?> 条</span><button type="button" class="px-2.5 py-1 rounded bg-indigo-600 text-white text-[11px] font-bold">1</button></div></div><div class="overflow-x-auto custom-scrollbar"><table class="w-full text-left border-collapse text-[11px] font-mono"><thead><tr class="border-b border-slate-800 text-slate-400 bg-slate-950/40 font-medium"><th class="p-3 w-40">时间</th><th class="p-3 w-16 text-center">来源</th><th class="p-3 w-36">IP 物理地址</th><th class="p-3">静态资源路径</th><th class="p-3 w-16">方法</th><th class="p-3 w-16">类型</th><th class="p-3 w-24">吞吐</th></tr></thead><tbody class="divide-y divide-slate-800/60 text-slate-300"><?php if ($logs === []): ?><tr><td class="p-8 text-center text-slate-600 font-sans" colspan="7">暂无公开 API 访问日志。</td></tr><?php endif; ?><?php foreach ($logs as $log): ?><tr class="hover:bg-slate-800/30 transition"><td class="p-2.5 text-slate-500 font-sans"><?= html(str_replace('T', ' ', substr($log['time'], 0, 19))) ?></td><td class="p-2.5 text-center"><span class="<?= $log['device'] === 'mobile' ? 'bg-emerald-500/10 text-emerald-400' : ($log['device'] === 'pc' ? 'bg-indigo-500/10 text-indigo-400' : 'bg-slate-800 text-slate-400 border border-slate-700') ?> px-1 rounded text-[10px]"><?= $log['device'] === 'mobile' ? 'M' : ($log['device'] === 'pc' ? 'PC' : 'ROOT') ?></span></td><td class="p-2.5 text-slate-400"><?= html($log['ip']) ?></td><td class="p-2.5 text-slate-200 truncate max-w-xs"><?= html($log['image']) ?></td><td class="p-2.5 text-indigo-400 font-bold"><?= html($log['method']) ?></td><td class="p-2.5 <?= $log['type'] === 'json' ? 'text-amber-500' : 'text-slate-500' ?>"><?= html($log['type']) ?></td><td class="p-2.5 text-slate-400"><?= html(format_file_size($log['bytes'])) ?></td></tr><?php endforeach; ?></tbody></table></div></div></section><?php endif; ?>
        <?php if ($requestedPage === 'ip_control'): ?>
            <section id="ip-control" class="space-y-6">
                <div class="flex flex-col gap-4 border-b border-slate-800 pb-5 lg:flex-row lg:items-end lg:justify-between"><div><h2 class="text-xl font-semibold text-white">IP 黑白名单管理</h2><p class="mt-1 text-xs text-slate-400">按单个 IP 管控公共图片接口访问。自动封禁与 API 请求频率提醒使用同一时间窗口和阈值。</p></div><form method="post" action="ip_access_control.php" class="flex flex-wrap items-center gap-2"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="update_threshold"><label class="flex items-center gap-2 text-xs text-slate-400">时间窗口<select name="request_window_seconds" class="rounded-lg border border-slate-700 bg-slate-900 px-2.5 py-2 text-xs text-slate-200"><option value="60" <?= (int) $siteSettings['request_window_seconds'] === 60 ? 'selected' : '' ?>>1 分钟</option><option value="300" <?= (int) $siteSettings['request_window_seconds'] === 300 ? 'selected' : '' ?>>5 分钟</option><option value="600" <?= (int) $siteSettings['request_window_seconds'] === 600 ? 'selected' : '' ?>>10 分钟</option><option value="1800" <?= (int) $siteSettings['request_window_seconds'] === 1800 ? 'selected' : '' ?>>30 分钟</option></select></label><label class="flex items-center gap-2 text-xs text-slate-400">请求阈值<select name="request_threshold" class="rounded-lg border border-slate-700 bg-slate-900 px-2.5 py-2 text-xs text-slate-200"><option value="50" <?= (int) $siteSettings['request_threshold'] === 50 ? 'selected' : '' ?>>50 次</option><option value="100" <?= (int) $siteSettings['request_threshold'] === 100 ? 'selected' : '' ?>>100 次</option><option value="300" <?= (int) $siteSettings['request_threshold'] === 300 ? 'selected' : '' ?>>300 次</option><option value="500" <?= (int) $siteSettings['request_threshold'] === 500 ? 'selected' : '' ?>>500 次</option><option value="1000" <?= (int) $siteSettings['request_threshold'] === 1000 ? 'selected' : '' ?>>1000 次</option></select></label><button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-2 text-xs font-medium text-white hover:bg-indigo-700"><i data-lucide="save" class="h-3.5 w-3.5"></i>保存策略</button></form></div>
                <?php
                $loginBlacklist = array_values(array_filter($ipAccessLists['blacklist'], static fn(array $entry): bool => in_array((string) ($entry['reason'] ?? ''), ['login', 'manual_login'], true)));
                $requestBlacklist = array_values(array_filter($ipAccessLists['blacklist'], static fn(array $entry): bool => !in_array((string) ($entry['reason'] ?? ''), ['login', 'manual_login'], true)));
                ?>
                <section class="overflow-hidden rounded-xl border border-slate-800 bg-slate-900"><div class="flex flex-col gap-3 border-b border-slate-800 px-5 py-4 lg:flex-row lg:items-center lg:justify-between"><div><div class="flex items-center gap-2"><i data-lucide="gauge" class="h-4 w-4 text-amber-400"></i><h3 class="text-sm font-semibold text-white">频繁请求黑名单</h3></div><p class="mt-1 text-[11px] text-slate-500">由 API 请求频率策略自动封禁，手动封禁的 IP 也归入此列表。</p></div><form method="post" action="ip_access_control.php" class="flex flex-wrap items-center gap-2"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="add_blacklist"><input name="ip" required placeholder="IPv4 或 IPv6 地址" class="min-w-[13rem] flex-1 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-white outline-none focus:border-indigo-500"><input data-blacklist-duration name="duration_minutes" type="number" min="1" max="525600" value="30" class="w-24 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-white outline-none focus:border-indigo-500"><label class="flex items-center gap-1.5 text-xs text-slate-400"><input data-blacklist-permanent name="permanent" type="checkbox" value="1" class="h-4 w-4 rounded border-slate-600 bg-slate-800 text-indigo-600">永久封禁</label><button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-3 py-2 text-xs font-medium text-white hover:bg-amber-700"><i data-lucide="plus" class="h-3.5 w-3.5"></i>添加封禁</button></form></div><div class="overflow-x-auto custom-scrollbar"><table class="w-full min-w-[760px] text-left text-xs"><thead class="border-b border-slate-800 bg-slate-950/50 text-slate-400"><tr><th class="px-5 py-3 font-medium">IP 地址</th><th class="px-5 py-3 font-medium">来源</th><th class="px-5 py-3 font-medium">状态</th><th class="px-5 py-3 font-medium">解封时间</th><th class="w-24 px-5 py-3 text-right font-medium">操作</th></tr></thead><tbody class="divide-y divide-slate-800/70 text-slate-300"><?php if ($requestBlacklist === []): ?><tr><td colspan="5" class="px-5 py-10 text-center text-slate-500">当前没有频繁请求封禁记录。</td></tr><?php endif; ?><?php foreach ($requestBlacklist as $entry): ?><?php $reason = (string) $entry['reason']; $reasonLabel = $reason === 'auto' ? '请求过多' : '手动封禁'; $reasonClass = $reason === 'auto' ? 'border-amber-500/30 bg-amber-500/10 text-amber-400' : 'border-slate-700 bg-slate-800 text-slate-300'; ?><tr class="hover:bg-slate-800/30"><td class="px-5 py-3 font-mono text-slate-200"><?= html($entry['ip']) ?></td><td class="px-5 py-3"><span class="rounded border px-2 py-1 text-[10px] <?= $reasonClass ?>"><?= $reasonLabel ?></span></td><td class="px-5 py-3"><?= !empty($entry['permanent']) ? '永久封禁' : '临时封禁' ?></td><td class="px-5 py-3 text-slate-400"><?= !empty($entry['permanent']) ? '永久' : date('Y-m-d H:i:s', (int) $entry['until']) ?></td><td class="px-5 py-3 text-right"><form method="post" action="ip_access_control.php"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="remove_blacklist"><input type="hidden" name="ip" value="<?= html($entry['ip']) ?>"><button type="submit" class="text-slate-400 hover:text-emerald-400">解封</button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
                <section class="overflow-hidden rounded-xl border border-slate-800 bg-slate-900"><div class="flex items-start gap-3 border-b border-slate-800 px-5 py-4"><div class="mt-0.5 rounded-lg border border-rose-500/20 bg-rose-500/10 p-2 text-rose-400"><i data-lucide="shield-alert" class="h-4 w-4"></i></div><div><h3 class="text-sm font-semibold text-white">登录黑名单</h3><p class="mt-1 text-[11px] text-slate-500">同一 IP 的登录爆破锁定达到 5 小时上限后，再次失败时会自动永久加入此列表。</p></div></div><div class="overflow-x-auto custom-scrollbar"><table class="w-full min-w-[640px] text-left text-xs"><thead class="border-b border-slate-800 bg-slate-950/50 text-slate-400"><tr><th class="px-5 py-3 font-medium">IP 地址</th><th class="px-5 py-3 font-medium">封禁状态</th><th class="px-5 py-3 font-medium">加入时间</th><th class="w-24 px-5 py-3 text-right font-medium">操作</th></tr></thead><tbody class="divide-y divide-slate-800/70 text-slate-300"><?php if ($loginBlacklist === []): ?><tr><td colspan="4" class="px-5 py-10 text-center text-slate-500">当前没有登录爆破黑名单记录。</td></tr><?php endif; ?><?php foreach ($loginBlacklist as $entry): ?><tr class="hover:bg-slate-800/30"><td class="px-5 py-3 font-mono text-slate-200"><?= html($entry['ip']) ?></td><td class="px-5 py-3"><span class="rounded border border-rose-500/30 bg-rose-500/10 px-2 py-1 text-[10px] text-rose-400"><?= !empty($entry['permanent']) ? '永久封禁' : '临时封禁' ?></span></td><td class="px-5 py-3 text-slate-400"><?= (int) $entry['added_at'] > 0 ? date('Y-m-d H:i:s', (int) $entry['added_at']) : '-' ?></td><td class="px-5 py-3 text-right"><form method="post" action="ip_access_control.php"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="remove_blacklist"><input type="hidden" name="ip" value="<?= html($entry['ip']) ?>"><button type="submit" class="text-slate-400 hover:text-emerald-400">解封</button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
                <section class="overflow-hidden rounded-xl border border-slate-800 bg-slate-900"><div class="flex flex-col gap-3 border-b border-slate-800 px-5 py-4 lg:flex-row lg:items-center lg:justify-between"><div class="flex items-center gap-2"><i data-lucide="shield-check" class="h-4 w-4 text-emerald-400"></i><h3 class="text-sm font-semibold text-white">白名单</h3></div><form method="post" action="ip_access_control.php" class="flex items-center gap-2"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="add_whitelist"><input name="ip" required placeholder="IPv4 或 IPv6 地址" class="min-w-[13rem] flex-1 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-white outline-none focus:border-indigo-500"><button type="submit" class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-3 py-2 text-xs font-medium text-emerald-400 hover:bg-emerald-500/20"><i data-lucide="plus" class="h-3.5 w-3.5"></i>添加白名单</button></form></div><div class="overflow-x-auto custom-scrollbar"><table class="w-full min-w-[760px] text-left text-xs"><thead class="border-b border-slate-800 bg-slate-950/50 text-slate-400"><tr><th class="px-5 py-3 font-medium">IP 地址</th><th class="px-5 py-3 font-medium">加入时间</th><th class="px-5 py-3 font-medium">访问策略</th><th class="w-24 px-5 py-3 text-right font-medium">操作</th></tr></thead><tbody class="divide-y divide-slate-800/70 text-slate-300"><?php if ($ipAccessLists['whitelist'] === []): ?><tr><td colspan="4" class="px-5 py-10 text-center text-slate-500">当前没有白名单记录。</td></tr><?php endif; ?><?php foreach ($ipAccessLists['whitelist'] as $entry): ?><tr class="hover:bg-slate-800/30"><td class="px-5 py-3 font-mono text-slate-200"><?= html($entry['ip']) ?></td><td class="px-5 py-3 text-slate-400"><?= (int) $entry['added_at'] > 0 ? date('Y-m-d H:i:s', (int) $entry['added_at']) : '-' ?></td><td class="px-5 py-3"><span class="rounded border border-emerald-500/30 bg-emerald-500/10 px-2 py-1 text-[10px] text-emerald-400">跳过自动封禁</span></td><td class="px-5 py-3 text-right"><form method="post" action="ip_access_control.php"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="remove_whitelist"><input type="hidden" name="ip" value="<?= html($entry['ip']) ?>"><button type="submit" class="text-slate-400 hover:text-rose-400">移除</button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
            </section>
        <?php endif; ?>
        <?php if ($requestedPage === 'settings'): ?>
            <section id="settings" class="space-y-6">
                <div class="flex flex-col gap-2 border-b border-slate-800 pb-5 sm:flex-row sm:items-end sm:justify-between">
                    <div><h2 class="text-xl font-semibold text-white">系统设置</h2><p class="mt-1 text-xs text-slate-400">站点外观、邮件通知和安全策略会立即应用到后台与公开接口。</p></div>
                    <span class="inline-flex w-fit items-center gap-1.5 rounded-md border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-1 text-[11px] font-medium text-emerald-400"><i data-lucide="shield-check" class="h-3.5 w-3.5"></i>配置保存在项目目录</span>
                </div>
                <div class="space-y-6">
                    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1.16fr)_minmax(22rem,0.84fr)]">
                        <form data-settings-appearance-form method="post" action="settings_save.php" enctype="multipart/form-data" class="min-w-0">
                            <input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>">
                            <?php foreach (['dark', 'light'] as $paletteMode): ?><?php foreach (['color', 'background', 'surface', 'text', 'muted'] as $palettePart): ?><?php $paletteKey = $paletteMode . '_theme_' . $palettePart; ?><input type="hidden" name="<?= $paletteKey ?>" value="<?= html((string) $siteSettings[$paletteKey]) ?>"><?php endforeach; ?><?php endforeach; ?>
                        <section data-theme-workbench class="border border-slate-800 bg-slate-900 p-5 rounded-xl space-y-5">
                            <div class="flex flex-col gap-2 border-b border-slate-800 pb-4 sm:flex-row sm:items-center sm:justify-between"><div class="flex items-center gap-2"><i data-lucide="palette" class="h-4 w-4 text-indigo-400"></i><div><h3 class="text-sm font-semibold text-white">站点外观</h3><p class="mt-0.5 text-[11px] text-slate-500">颜色和字体更改后立即同步到全部后台页面。</p></div></div><span class="inline-flex w-fit items-center gap-1.5 rounded-md border border-indigo-500/30 bg-indigo-500/10 px-2 py-1 text-[10px] text-indigo-300"><i data-lucide="zap" class="h-3 w-3"></i>即时应用</span></div>
                            <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_14rem]">
                                <div class="block text-xs text-slate-400"><span>网站名称</span><div class="mt-1.5 flex gap-2"><input data-instant-setting name="site_name" maxlength="80" required value="<?= html((string) $siteSettings['site_name']) ?>" class="min-w-0 flex-1 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-500"><button type="button" data-site-name-reset class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-300 transition hover:bg-slate-700 hover:text-white" title="重置网站名称"><i data-lucide="rotate-ccw" class="h-3.5 w-3.5"></i>重置</button></div></div>
                                <label class="block text-xs text-slate-400">站点图标<input data-icon-upload name="site_icon" type="file" accept="image/png,image/jpeg,image/gif,image/webp" class="mt-1.5 block w-full text-xs text-slate-400 file:mr-2 file:rounded-md file:border-0 file:bg-slate-800 file:px-2 file:py-1.5 file:text-xs file:text-slate-200 hover:file:bg-slate-700"></label>
                            </div>
                            <div class="flex items-center gap-3 border-y border-slate-800 py-3 text-xs text-slate-400"><?php if (site_icon_path() !== null): ?><img src="<?= html(site_favicon_url()) ?>" alt="站点图标" class="h-8 w-8 rounded-md object-cover"><span>当前站点图标已启用，上传新图片将替换它。</span><?php else: ?><i data-lucide="image" class="h-4 w-4 text-slate-500"></i><span>尚未上传站点图标。支持 JPEG、PNG、GIF、WebP，最大 2 MB。</span><?php endif; ?></div>
                            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_13rem]">
                                <div class="space-y-2"><span class="block text-xs text-slate-400">主题模式</span><div class="grid grid-cols-3 gap-2"><?php foreach (['dark' => '深色', 'light' => '浅色', 'auto' => '自动'] as $mode => $label): ?><label data-theme-mode-label class="cursor-pointer rounded-lg border px-3 py-2 text-center text-xs transition <?= $siteSettings['theme_mode'] === $mode ? 'border-indigo-500 bg-indigo-500/10 text-indigo-200' : 'border-slate-700 bg-slate-950 text-slate-400 hover:border-slate-600' ?>"><input data-instant-setting class="sr-only" type="radio" name="theme_mode" value="<?= $mode ?>" <?= $siteSettings['theme_mode'] === $mode ? 'checked' : '' ?>><span><?= $label ?></span></label><?php endforeach; ?></div></div>
                                <label class="block text-xs text-slate-400">字体大小<select data-instant-setting name="font_size" class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-500"><option value="extra_small" <?= $siteSettings['font_size'] === 'extra_small' ? 'selected' : '' ?>>特小 · 12px</option><option value="small" <?= $siteSettings['font_size'] === 'small' ? 'selected' : '' ?>>较小 · 14px</option><option value="normal" <?= $siteSettings['font_size'] === 'normal' ? 'selected' : '' ?>>标准 · 16px</option><option value="large" <?= $siteSettings['font_size'] === 'large' ? 'selected' : '' ?>>较大 · 18px</option><option value="extra_large" <?= $siteSettings['font_size'] === 'extra_large' ? 'selected' : '' ?>>特大 · 20px</option></select></label>
                            </div>
                            <div class="border-t border-slate-800 pt-5">
                                <div class="mb-3 flex items-center justify-between gap-3"><div><h4 class="text-xs font-semibold text-slate-200">自定义主题调色板</h4><p class="mt-1 text-[11px] text-slate-500">分别控制强调色、页面、内容区和文字颜色。</p></div><span class="text-[10px] text-slate-500">点击色块即时保存</span></div>
                                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                                    <?php foreach (['theme_color' => '强调色', 'theme_background' => '页面背景', 'theme_surface' => '卡片背景', 'theme_text' => '主文字', 'theme_muted' => '弱文字'] as $key => $label): ?><label data-theme-color-control class="flex min-w-0 items-center gap-2 rounded-lg border border-slate-800 px-2.5 py-2 text-[11px] text-slate-400"><input data-instant-setting name="<?= $key ?>" type="color" value="<?= html((string) $siteSettings[$key]) ?>" title="<?= $label ?>" class="h-7 w-8 shrink-0 cursor-pointer rounded border-0 bg-transparent p-0"><span class="min-w-0"><span class="block truncate text-slate-300"><?= $label ?></span><code data-theme-color-value="<?= $key ?>" class="mt-0.5 block text-[10px] text-slate-500"><?= html((string) $siteSettings[$key]) ?></code></span></label><?php endforeach; ?>
                                </div>
                            </div>
                            <?php $darkThemePresets = [['靛蓝', '#6366F1', '#0F172A', '#111827', '#F8FAFC', '#94A3B8'], ['青蓝', '#0891B2', '#06202A', '#0B2A35', '#E6FFFB', '#9AC7D1'], ['紫罗兰', '#8B5CF6', '#18112B', '#211839', '#F7F2FF', '#B5A8D0'], ['玫红', '#DB2777', '#2A0C1D', '#381126', '#FFF1F7', '#D6A4BB'], ['琥珀', '#D97706', '#241707', '#32220E', '#FFF8E8', '#D8C098']]; $lightThemePresets = [['天空', '#2563EB', '#F7FAFF', '#FFFFFF', '#102A43', '#52677F'], ['翡翠', '#059669', '#F3FBF7', '#FFFFFF', '#12352A', '#5D776C'], ['石榴', '#DC2626', '#FFF8F7', '#FFFFFF', '#411A1A', '#7E5C5C'], ['橙金', '#EA580C', '#FFFAF5', '#FFFFFF', '#3B2616', '#806957'], ['洋红', '#C026D3', '#FFF8FF', '#FFFFFF', '#3A1D3D', '#78617A']]; ?>
                            <div class="grid gap-4 border-t border-slate-800 pt-5 lg:grid-cols-[minmax(0,1fr)_13.5rem]">
                                <div class="space-y-3"><div><span class="block text-[11px] font-medium text-slate-500">深色主题预设</span><div class="mt-1.5 grid grid-cols-5 gap-2"><?php foreach ($darkThemePresets as [$name, $color, $background, $surface, $text, $muted]): ?><button type="button" data-theme-preset data-mode="dark" data-color="<?= $color ?>" data-background="<?= $background ?>" data-surface="<?= $surface ?>" data-text="<?= $text ?>" data-muted="<?= $muted ?>" title="应用<?= $name ?>深色主题" class="group rounded-lg border border-slate-700 bg-slate-950 p-2 text-[10px] text-slate-400 hover:border-slate-500"><span class="mx-auto mb-1 flex h-4 w-6 overflow-hidden rounded-sm ring-1 ring-white/10"><i class="h-full w-1/3" style="background:<?= $background ?>"></i><i class="h-full w-1/3" style="background:<?= $surface ?>"></i><i class="h-full w-1/3" style="background:<?= $color ?>"></i></span><?= $name ?></button><?php endforeach; ?></div></div><div><span class="block text-[11px] font-medium text-slate-500">浅色主题预设</span><div class="mt-1.5 grid grid-cols-5 gap-2"><?php foreach ($lightThemePresets as [$name, $color, $background, $surface, $text, $muted]): ?><button type="button" data-theme-preset data-mode="light" data-color="<?= $color ?>" data-background="<?= $background ?>" data-surface="<?= $surface ?>" data-text="<?= $text ?>" data-muted="<?= $muted ?>" title="应用<?= $name ?>浅色主题" class="group rounded-lg border border-slate-700 bg-slate-950 p-2 text-[10px] text-slate-400 hover:border-slate-500"><span class="mx-auto mb-1 flex h-4 w-6 overflow-hidden rounded-sm ring-1 ring-white/10"><i class="h-full w-1/3" style="background:<?= $background ?>"></i><i class="h-full w-1/3" style="background:<?= $surface ?>"></i><i class="h-full w-1/3" style="background:<?= $color ?>"></i></span><?= $name ?></button><?php endforeach; ?></div></div></div>
                                <div data-theme-preview class="min-h-[10.75rem] rounded-lg border border-slate-800 p-3"><div data-theme-preview-card class="h-full rounded-md border p-3"><div class="flex items-center justify-between"><strong class="text-xs">界面预览</strong><span class="h-2.5 w-2.5 rounded-full" style="background:var(--picapi-accent)"></span></div><p class="mt-2 text-xs">主文字与表面层级</p><p data-theme-preview-muted class="mt-1 text-[11px]">辅助说明文字会使用弱文字颜色。</p><div class="mt-3 flex items-center gap-2"><span data-theme-preview-button class="rounded-md px-2.5 py-1.5 text-[10px] font-medium">主要操作</span><span class="text-[10px]" style="color:var(--picapi-accent)">接口状态正常</span></div><div data-theme-preview-notice class="mt-3 flex items-center gap-1.5 rounded-md border px-2 py-1.5 text-[10px]"><i data-lucide="bell" class="h-3 w-3" style="color:var(--picapi-accent)"></i>系统消息会跟随当前主题</div></div></div>
                            </div>
                        </section>
                        </form>
                        <div class="min-w-0 space-y-4">
                        <form method="post" action="settings_save.php">
                            <input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>">
                            <input type="hidden" name="settings_section" value="mail_service">
                        <section class="border border-slate-800 bg-slate-900 p-5 rounded-xl space-y-5">
                            <div class="flex flex-col gap-2 border-b border-slate-800 pb-4 sm:flex-row sm:items-center sm:justify-between"><div class="flex items-center gap-2"><i data-lucide="server-cog" class="h-4 w-4 text-amber-400"></i><div><h3 class="text-sm font-semibold text-white">邮件服务</h3><p class="mt-0.5 text-[11px] text-slate-500">配置 SMTP 连接与安全提醒收件人。</p></div></div><span class="inline-flex w-fit items-center gap-1.5 rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-1 text-[10px] text-amber-400"><i data-lucide="radio-tower" class="h-3 w-3"></i>SMTP</span></div>
                            <div class="grid gap-4 sm:grid-cols-2"><label class="block text-xs text-slate-400">SMTP 地址<input name="smtp_host" value="<?= html((string) ($smtpSettings['host'] ?? '')) ?>" placeholder="smtp.example.com" class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-500"></label><label class="block text-xs text-slate-400">端口<input name="smtp_port" type="number" min="1" max="65535" value="<?= html((string) ($smtpSettings['port'] ?? '')) ?>" placeholder="465" class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-500"></label><label class="block text-xs text-slate-400">SMTP 账号<input name="smtp_username" value="<?= html((string) ($smtpSettings['username'] ?? '')) ?>" autocomplete="username" class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-500"></label><label class="block text-xs text-slate-400">授权密码<input name="smtp_password" type="password" autocomplete="new-password" placeholder="留空则保持原密码" class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-500"></label><label class="block text-xs text-slate-400">加密方式<select name="smtp_encryption" class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-500"><option value="ssl" <?= ($smtpSettings['encryption'] ?? 'ssl') === 'ssl' ? 'selected' : '' ?>>SSL</option><option value="tls" <?= ($smtpSettings['encryption'] ?? '') === 'tls' ? 'selected' : '' ?>>STARTTLS</option></select></label><label class="block text-xs text-slate-400">提醒收件人<input name="notification_email" type="email" value="<?= html((string) ($siteSettings['notification_email'] !== '' ? $siteSettings['notification_email'] : ($installation['admin_email'] ?? ''))) ?>" placeholder="默认使用管理员邮箱" class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-500"></label></div>
                            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-800 pt-4"><span class="text-[11px] text-slate-500">测试邮件将使用已保存的 SMTP 参数发送。</span><div class="flex flex-wrap justify-end gap-2"><button type="submit" formaction="settings_test_email.php" formmethod="post" formnovalidate class="inline-flex items-center gap-2 rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-xs font-medium text-slate-200 transition hover:bg-slate-700"><i data-lucide="send" class="h-3.5 w-3.5"></i>发送测试邮件</button><button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-3 py-2 text-xs font-medium text-white transition hover:bg-indigo-700"><i data-lucide="save" class="h-3.5 w-3.5"></i>保存邮件服务</button></div></div>
                        </section>
                        </form>
                        <section class="border border-slate-800 bg-slate-900 p-5 rounded-xl space-y-5">
                            <div class="flex flex-col gap-2 border-b border-slate-800 pb-4 sm:flex-row sm:items-center sm:justify-between"><div class="flex items-center gap-2"><i data-lucide="user-round-cog" class="h-4 w-4 text-cyan-400"></i><div><h3 class="text-sm font-semibold text-white">用户管理</h3><p class="mt-0.5 text-[11px] text-slate-500">修改登录用户名或绑定邮箱。更换邮箱必须完成新地址验证。</p></div></div><span class="inline-flex w-fit items-center gap-1.5 rounded-md border border-cyan-500/30 bg-cyan-500/10 px-2 py-1 text-[10px] text-cyan-300"><i data-lucide="badge-check" class="h-3 w-3"></i>账户安全</span></div>
                            <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-800 bg-slate-950/70 px-3 py-2.5 text-xs"><div class="flex min-w-0 items-center gap-3"><div data-admin-avatar class="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-full bg-indigo-600 text-sm font-semibold text-white"><?php if ($adminAvatarUrl !== null): ?><img src="<?= html($adminAvatarUrl) ?>" alt="管理员头像" class="h-full w-full object-cover"><?php else: ?><?= html(display_initial((string) $adminAccount['username'])) ?><?php endif; ?></div><div class="min-w-0"><span class="text-slate-500">当前账户</span><div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-slate-200"><strong><?= html((string) $adminAccount['username']) ?></strong><span class="text-slate-600">•</span><span class="truncate"><?= html((string) $adminAccount['email']) ?></span></div></div></div><button type="button" data-avatar-open class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-700 bg-slate-800 px-2.5 py-2 text-xs font-medium text-slate-200 transition hover:border-cyan-400/50 hover:bg-slate-700 hover:text-white"><i data-lucide="image-up" class="h-3.5 w-3.5 text-cyan-300"></i>上传头像</button></div>
                            <form method="post" action="account_save.php" data-account-username="<?= html((string) $adminAccount['username']) ?>" class="space-y-4"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="save_profile"><div class="grid gap-4 sm:grid-cols-2"><label class="block text-xs text-slate-400">登录用户名<input name="username" maxlength="48" required value="<?= html((string) ($pendingEmailChange['new_username'] ?? $adminAccount['username'])) ?>" autocomplete="username" class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-cyan-400"></label><label class="block text-xs text-slate-400">绑定邮箱<input name="email" type="email" required value="<?= html((string) ($pendingEmailChange['new_email'] ?? $adminAccount['email'])) ?>" autocomplete="email" class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-cyan-400"></label></div><label class="block text-xs text-slate-400">当前密码<input name="current_password" type="password" required autocomplete="current-password" placeholder="确认身份后才能发送验证邮件" class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-cyan-400"></label><div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-800 pt-4"><span class="max-w-sm text-[11px] leading-relaxed text-slate-500">仅修改用户名会立即生效；填写新邮箱会发送 6 位验证码，新邮箱验证成功后才会更新资料。</span><button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-cyan-600 px-3 py-2 text-xs font-medium text-white transition hover:bg-cyan-500"><i data-lucide="mail-check" class="h-3.5 w-3.5"></i>发送验证 / 保存</button></div></form>
                            <?php if ($pendingEmailChange !== null): ?><form method="post" action="account_save.php" class="rounded-lg border border-cyan-500/25 bg-cyan-500/5 p-4"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="verify_email_change"><div class="flex items-start gap-2"><i data-lucide="mail-open-check" class="mt-0.5 h-4 w-4 shrink-0 text-cyan-300"></i><div><p class="text-xs font-medium text-cyan-100">请验证新邮箱</p><p class="mt-1 text-[11px] leading-relaxed text-cyan-100/60">验证码已发送至 <?= html((string) $pendingEmailChange['new_email']) ?>，15 分钟内有效。</p></div></div><div class="mt-3 flex flex-col gap-2 sm:flex-row"><input name="verification_code" inputmode="numeric" pattern="\d{6}" maxlength="6" required placeholder="输入 6 位验证码" class="min-w-0 flex-1 rounded-lg border border-cyan-400/30 bg-slate-950 px-3 py-2 text-sm tracking-[0.35em] text-white outline-none focus:border-cyan-300"><button type="submit" class="rounded-lg bg-cyan-600 px-3 py-2 text-xs font-medium text-white hover:bg-cyan-500">确认新邮箱</button><button type="submit" name="action" value="cancel_email_change" formnovalidate class="rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-300 hover:bg-slate-700">取消</button></div></form><?php endif; ?>
                            <form method="post" action="account_save.php" class="border-t border-slate-800 pt-5"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="change_password"><div class="flex items-center gap-2"><i data-lucide="key-round" class="h-4 w-4 text-amber-400"></i><div><h4 class="text-xs font-semibold text-slate-200">修改密码</h4><p class="mt-0.5 text-[11px] text-slate-500">更新后会立即注销其他设备上的登录状态。</p></div></div><div class="mt-4 grid gap-4 sm:grid-cols-2"><label class="block text-xs text-slate-400 sm:col-span-2">当前密码<input name="current_password" type="password" required autocomplete="current-password" class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-amber-400"></label><label class="block text-xs text-slate-400">新密码<input name="new_password" type="password" minlength="8" required autocomplete="new-password" placeholder="至少 8 位" class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-amber-400"></label><label class="block text-xs text-slate-400">确认新密码<input name="new_password_confirmation" type="password" minlength="8" required autocomplete="new-password" class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-amber-400"></label></div><div class="mt-4 flex justify-end"><button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-amber-500/35 bg-amber-500/10 px-3 py-2 text-xs font-medium text-amber-300 transition hover:bg-amber-500/20"><i data-lucide="shield-check" class="h-3.5 w-3.5"></i>更新密码</button></div></form>
                        </section>
                        </div>
                    </div>
                    <form method="post" action="settings_save.php">
                        <input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>">
                        <input type="hidden" name="settings_section" value="mail_template">
                    <section class="border border-slate-800 bg-slate-900 p-5 rounded-xl space-y-5">
                        <div class="flex flex-col gap-3 border-b border-slate-800 pb-4 lg:flex-row lg:items-center lg:justify-between"><div class="flex items-center gap-2"><i data-lucide="file-code-2" class="h-4 w-4 text-indigo-400"></i><div><h3 class="text-sm font-semibold text-white">邮件模板编辑</h3><p class="mt-0.5 text-[11px] text-slate-500">编辑通知和密码重置邮件的主题与 HTML 内容，右侧实时预览。</p></div></div><div data-template-tabs class="inline-flex w-fit rounded-lg border border-slate-700 bg-slate-950 p-1 text-[11px]"><button type="button" data-template-tab="notification" class="rounded-md bg-indigo-600 px-2.5 py-1 text-white">提醒模板</button><button type="button" data-template-tab="reset" class="rounded-md px-2.5 py-1 text-slate-400 hover:text-white">密码重置验证码</button></div></div>
                        <label class="block max-w-2xl text-xs text-slate-400">邮件主题模板<input name="email_subject_template" maxlength="180" value="<?= html((string) $siteSettings['email_subject_template']) ?>" class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-500"><span class="mt-1 block text-[11px] text-slate-500">可用变量：{{site_name}}、{{title}}</span></label>
                        <div class="flex flex-wrap items-center justify-between gap-3"><span class="text-xs text-slate-400">编辑方式</span><div data-template-editor-modes class="inline-flex rounded-lg border border-slate-700 bg-slate-950 p-1 text-[11px]"><button type="button" data-template-editor-mode="source" class="rounded-md bg-indigo-600 px-2.5 py-1 text-white">HTML 源代码</button><button type="button" data-template-editor-mode="simple" class="rounded-md px-2.5 py-1 text-slate-400 hover:text-white">简易修改</button></div></div>
                        <div class="grid gap-5 xl:grid-cols-2"><div class="min-w-0"><div data-template-editor-panel="source"><span class="block text-xs text-slate-400">HTML 源代码</span><div data-template-code-editor class="mt-1.5"><pre aria-hidden="true"><code data-template-code-highlight="notification"><?= html((string) $siteSettings['notification_email_html_template']) ?></code></pre><textarea data-template-source="notification" name="notification_email_html_template" rows="21" wrap="soft" spellcheck="false" aria-label="提醒模板 HTML 源代码"><?= html((string) $siteSettings['notification_email_html_template']) ?></textarea></div><div data-template-code-editor class="mt-1.5"><pre aria-hidden="true"><code data-template-code-highlight="reset"><?= html((string) $siteSettings['password_reset_email_html_template']) ?></code></pre><textarea data-template-source="reset" name="password_reset_email_html_template" rows="21" wrap="soft" spellcheck="false" aria-label="密码重置模板 HTML 源代码"><?= html((string) $siteSettings['password_reset_email_html_template']) ?></textarea></div></div><div data-template-editor-panel="simple" class="hidden space-y-5"><div class="grid gap-4 sm:grid-cols-2"><label class="block text-xs text-slate-400">邮件名称<input data-template-simple="brand" type="text" maxlength="80" class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-500"></label><label class="block text-xs text-slate-400">补充文字<input data-template-simple="note" type="text" maxlength="160" class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-500"></label><label class="block text-xs text-slate-400">容器宽度<input data-template-simple="width" type="number" min="320" max="900" step="10" class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-500"></label><label class="block text-xs text-slate-400">最小高度<input data-template-simple="height" type="number" min="0" max="1600" step="10" class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-500"></label></div><div class="border-t border-slate-800 pt-4"><p class="mb-3 text-xs font-medium text-slate-300">颜色</p><div class="grid gap-3 sm:grid-cols-2"><label class="flex items-center justify-between gap-3 text-xs text-slate-400">页面背景<input data-template-simple="bodyBackground" type="color" class="h-8 w-10 cursor-pointer rounded border-0 bg-transparent p-0"></label><label class="flex items-center justify-between gap-3 text-xs text-slate-400">卡片背景<input data-template-simple="surfaceBackground" type="color" class="h-8 w-10 cursor-pointer rounded border-0 bg-transparent p-0"></label><label class="flex items-center justify-between gap-3 text-xs text-slate-400">标题栏<input data-template-simple="headerBackground" type="color" class="h-8 w-10 cursor-pointer rounded border-0 bg-transparent p-0"></label><label class="flex items-center justify-between gap-3 text-xs text-slate-400">主文字<input data-template-simple="primaryText" type="color" class="h-8 w-10 cursor-pointer rounded border-0 bg-transparent p-0"></label><label class="flex items-center justify-between gap-3 text-xs text-slate-400 sm:col-span-2">辅助文字<input data-template-simple="mutedText" type="color" class="h-8 w-10 cursor-pointer rounded border-0 bg-transparent p-0"></label></div></div></div></div><div class="text-xs text-slate-400">实时预览<div class="mt-1.5 h-[450px] overflow-hidden rounded-lg border border-slate-700 bg-white"><iframe data-template-preview title="邮件模板预览" sandbox="" class="h-full w-full border-0 bg-white"></iframe></div></div></div>
                        <div class="flex flex-col gap-3 border-t border-slate-800 pt-4 sm:flex-row sm:items-center sm:justify-between"><span class="text-[11px] text-slate-500">可用变量：{{site_name}}、{{title}}、{{message}}、{{ip}}、{{time}}、{{detail}}、{{code}}</span><div class="flex flex-wrap gap-2"><button type="button" data-template-reset class="rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-300 hover:bg-slate-700">还原当前模板</button><button type="submit" class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-medium text-white hover:bg-indigo-700"><i data-lucide="save" class="mr-1 inline h-3.5 w-3.5"></i>保存邮件模板</button></div></div>
                    </section>
                    </form>
                    <section class="border border-slate-800 bg-slate-900 p-5 rounded-xl space-y-5">
                        <div class="flex items-center gap-2"><i data-lucide="bell-ring" class="h-4 w-4 text-rose-400"></i><h3 class="text-sm font-semibold text-white">提醒与安全策略</h3></div>
                        <div class="grid gap-5 lg:grid-cols-2"><div class="rounded-lg border border-slate-800 bg-slate-950 p-4"><label class="flex cursor-pointer items-start gap-3"><input name="password_breach_alert" type="checkbox" value="1" <?= !empty($siteSettings['password_breach_alert']) ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded border-slate-600 bg-slate-800 text-indigo-600"><span><strong class="block text-sm font-medium text-slate-200">密码爆破提醒</strong><span class="mt-1 block text-xs leading-relaxed text-slate-500">同一 IP 第 3 次密码错误时发送邮件，之后每增加 2 次错误再发送一次，并写入来源 IP。前三次后锁定 1 分钟；第四次起每次增加 10 分钟，最长锁定 5 小时。</span></span></label></div><div class="rounded-lg border border-slate-800 bg-slate-950 p-4 space-y-3"><label class="flex cursor-pointer items-start gap-3"><input name="request_rate_alert" type="checkbox" value="1" <?= !empty($siteSettings['request_rate_alert']) ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded border-slate-600 bg-slate-800 text-indigo-600"><span><strong class="block text-sm font-medium text-slate-200">API 请求频率提醒</strong><span class="mt-1 block text-xs leading-relaxed text-slate-500">单个 IP 在指定窗口超过阈值后发送邮件，同一窗口最多发送一次。</span></span></label><div class="grid grid-cols-2 gap-3 pl-7"><label class="block text-xs text-slate-500">时间窗口<select name="request_window_seconds" class="mt-1 w-full rounded-md border border-slate-700 bg-slate-900 px-2 py-1.5 text-xs text-slate-200"><option value="60" <?= (int) $siteSettings['request_window_seconds'] === 60 ? 'selected' : '' ?>>1 分钟</option><option value="300" <?= (int) $siteSettings['request_window_seconds'] === 300 ? 'selected' : '' ?>>5 分钟</option><option value="600" <?= (int) $siteSettings['request_window_seconds'] === 600 ? 'selected' : '' ?>>10 分钟</option><option value="1800" <?= (int) $siteSettings['request_window_seconds'] === 1800 ? 'selected' : '' ?>>30 分钟</option></select></label><label class="block text-xs text-slate-500">请求阈值<select name="request_threshold" class="mt-1 w-full rounded-md border border-slate-700 bg-slate-900 px-2 py-1.5 text-xs text-slate-200"><option value="50" <?= (int) $siteSettings['request_threshold'] === 50 ? 'selected' : '' ?>>50 次</option><option value="100" <?= (int) $siteSettings['request_threshold'] === 100 ? 'selected' : '' ?>>100 次</option><option value="300" <?= (int) $siteSettings['request_threshold'] === 300 ? 'selected' : '' ?>>300 次</option><option value="500" <?= (int) $siteSettings['request_threshold'] === 500 ? 'selected' : '' ?>>500 次</option><option value="1000" <?= (int) $siteSettings['request_threshold'] === 1000 ? 'selected' : '' ?>>1000 次</option></select></label></div></div></div>
                    </section>
                    <?php if (false): ?><section class="border border-slate-800 bg-slate-900 p-5 rounded-xl space-y-5">
                        <div class="flex flex-col gap-2 border-b border-slate-800 pb-4 sm:flex-row sm:items-center sm:justify-between"><div class="flex items-center gap-2"><i data-lucide="shield-ban" class="h-4 w-4 text-rose-400"></i><div><h3 class="text-sm font-semibold text-white">IP 访问管控</h3><p class="mt-0.5 text-[11px] text-slate-500">达到阈值时自动封禁 30 分钟；白名单不受自动封禁限制。</p></div></div><label class="flex w-fit items-center gap-2 text-xs text-slate-400">1 分钟阈值<select name="ip_blacklist_threshold" class="rounded-md border border-slate-700 bg-slate-950 px-2 py-1.5 text-xs text-slate-200"><option value="20" <?= (int) $siteSettings['ip_blacklist_threshold'] === 20 ? 'selected' : '' ?>>20 次</option><option value="50" <?= (int) $siteSettings['ip_blacklist_threshold'] === 50 ? 'selected' : '' ?>>50 次</option><option value="100" <?= (int) $siteSettings['ip_blacklist_threshold'] === 100 ? 'selected' : '' ?>>100 次</option><option value="200" <?= (int) $siteSettings['ip_blacklist_threshold'] === 200 ? 'selected' : '' ?>>200 次</option><option value="500" <?= (int) $siteSettings['ip_blacklist_threshold'] === 500 ? 'selected' : '' ?>>500 次</option><option value="1000" <?= (int) $siteSettings['ip_blacklist_threshold'] === 1000 ? 'selected' : '' ?>>1000 次</option></select></label></div>
                        <div class="grid gap-5 xl:grid-cols-2"><div class="min-w-0"><form method="post" action="ip_access_control.php"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="add_whitelist"><div class="flex gap-2"><input name="ip" required placeholder="IPv4 或 IPv6 地址" class="min-w-0 flex-1 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-white outline-none focus:border-indigo-500"><button type="submit" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-3 py-2 text-xs font-medium text-emerald-400 hover:bg-emerald-500/20"><i data-lucide="shield-check" class="h-3.5 w-3.5"></i>加入白名单</button></div></form><div class="mt-3 max-h-52 overflow-y-auto rounded-lg border border-slate-800 bg-slate-950/70 custom-scrollbar"><div class="border-b border-slate-800 px-3 py-2 text-[11px] font-medium text-slate-400">白名单 IP</div><?php if ($ipAccessLists['whitelist'] === []): ?><p class="px-3 py-4 text-xs text-slate-500">暂无白名单 IP。</p><?php endif; ?><?php foreach ($ipAccessLists['whitelist'] as $entry): ?><div class="flex items-center justify-between gap-3 border-b border-slate-800/70 px-3 py-2 last:border-b-0"><code class="min-w-0 truncate text-xs text-slate-200"><?= html($entry['ip']) ?></code><form method="post" action="ip_access_control.php"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="remove_whitelist"><input type="hidden" name="ip" value="<?= html($entry['ip']) ?>"><button type="submit" class="text-[11px] text-slate-500 hover:text-rose-400">移除</button></form></div><?php endforeach; ?></div></div><div class="min-w-0"><form method="post" action="ip_access_control.php"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="add_blacklist"><div class="flex gap-2"><input name="ip" required placeholder="IPv4 或 IPv6 地址" class="min-w-0 flex-1 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-white outline-none focus:border-indigo-500"><button type="submit" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-xs font-medium text-rose-400 hover:bg-rose-500/20"><i data-lucide="ban" class="h-3.5 w-3.5"></i>封禁 30 分钟</button></div></form><div class="mt-3 max-h-52 overflow-y-auto rounded-lg border border-slate-800 bg-slate-950/70 custom-scrollbar"><div class="border-b border-slate-800 px-3 py-2 text-[11px] font-medium text-slate-400">黑名单 IP</div><?php if ($ipAccessLists['blacklist'] === []): ?><p class="px-3 py-4 text-xs text-slate-500">暂无正在生效的封禁。</p><?php endif; ?><?php foreach ($ipAccessLists['blacklist'] as $entry): ?><div class="flex items-center justify-between gap-3 border-b border-slate-800/70 px-3 py-2 last:border-b-0"><div class="min-w-0"><code class="block truncate text-xs text-slate-200"><?= html($entry['ip']) ?></code><span class="mt-0.5 block text-[10px] text-slate-500"><?= $entry['reason'] === 'auto' ? '自动封禁' : '手动封禁' ?> · 解封 <?= date('Y-m-d H:i:s', (int) $entry['until']) ?></span></div><form method="post" action="ip_access_control.php"><input type="hidden" name="csrf" value="<?= html(csrf_token()) ?>"><input type="hidden" name="action" value="remove_blacklist"><input type="hidden" name="ip" value="<?= html($entry['ip']) ?>"><button type="submit" class="text-[11px] text-slate-500 hover:text-emerald-400">解封</button></form></div><?php endforeach; ?></div></div></div>
                    </section><?php endif; ?>
                    <section class="border border-slate-800 bg-slate-900 p-5 rounded-xl space-y-4">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"><div class="flex items-center gap-2"><i data-lucide="list-ordered" class="h-4 w-4 text-indigo-400"></i><div><h3 class="text-sm font-semibold text-white">侧边菜单排序</h3><p class="mt-1 text-xs text-slate-500">拖动列表中的项目调整顺序；系统设置始终固定在菜单最后。</p></div></div><button type="button" data-navigation-reset class="inline-flex w-fit items-center gap-1.5 rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-300 hover:bg-slate-700"><i data-lucide="rotate-ccw" class="h-3.5 w-3.5"></i>恢复默认顺序</button></div>
                        <div data-navigation-sort-list class="flex flex-col gap-2"><?php foreach ($menuOrderItems as $key => $item): ?><div draggable="true" data-navigation-key="<?= html($key) ?>" class="flex cursor-move items-center gap-3 rounded-lg border border-slate-800 bg-slate-950 px-3 py-2.5 text-xs text-slate-300 transition hover:border-slate-600"><i data-lucide="grip-vertical" class="h-4 w-4 shrink-0 text-slate-600"></i><i data-lucide="<?= html($item['icon']) ?>" class="h-4 w-4 shrink-0 text-indigo-400"></i><span><?= html($item['label']) ?></span></div><?php endforeach; ?></div>
                    </section>
                </div>
            </section>
        <?php endif; ?>
    </div></main>
</div>
<script>
const secureLogoutCsrf = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
document.addEventListener('click', event => {
    const link = event.target.closest('a[href="logout.php"]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    const form = document.createElement('form');
    form.method = 'post';
    form.action = 'logout.php';
    const token = document.createElement('input');
    token.type = 'hidden';
    token.name = 'csrf';
    token.value = secureLogoutCsrf;
    form.append(token);
    document.body.append(form);
    form.submit();
});
// Standalone gallery loader. It is kept separate from the broader admin code so
// a thumbnail request cannot be overwritten by another gallery loader.
(() => {
    window.picapiDirectGalleryPreview = true;
    const startGalleryPreviewLoader = () => {
        const scroller = document.querySelector('#gallery table')?.closest('.overflow-x-auto.custom-scrollbar');
        if (!scroller) return;
        const previews = Array.from(document.querySelectorAll('#gallery tbody img[data-preview-src]'));
        if (!previews.length) return;
        let frame = 0;
        const mark = (preview, state) => {
            const box = preview.closest('.picapi-gallery-thumb');
            box?.classList.remove('is-loading', 'is-loaded', 'is-error');
            box?.classList.add(`is-${state}`);
            preview.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');
        };
        const requestThumbnail = preview => {
            if (preview.dataset.previewRequested || preview.getAttribute('src')) return;
            const source = new URL(preview.dataset.previewSrc, window.location.href);
            if (window.location.protocol === 'https:' && source.protocol === 'http:' && source.host === window.location.host) source.protocol = 'https:';
            const matched = source.pathname.match(/\/(pc|m)\/img\/(.+)$/);
            if (!matched) {
                mark(preview, 'error');
                return;
            }
            const device = matched[1] === 'pc' ? 'pc' : 'mobile';
            const relative = decodeURIComponent(matched[2]);
            const thumbnail = new URL('thumbnail.php', window.location.href);
            thumbnail.searchParams.set('device', device);
            thumbnail.searchParams.set('relative', relative);
            if (preview.dataset.previewRevision) thumbnail.searchParams.set('v', preview.dataset.previewRevision);
            preview.dataset.previewRequested = '1';
            preview.dataset.fullImage = source.toString();
            preview.loading = 'lazy';
            mark(preview, 'loading');
            let timeout = 0;
            const stopTimeout = () => {
                if (timeout) window.clearTimeout(timeout);
                timeout = 0;
            };
            preview.addEventListener('load', () => {
                stopTimeout();
                mark(preview, 'loaded');
            }, { once: true });
            preview.addEventListener('error', () => {
                stopTimeout();
                if (!preview.dataset.previewFallback) {
                    preview.dataset.previewFallback = '1';
                    preview.src = source.toString();
                    return;
                }
                mark(preview, 'error');
            });
            // A proxy or extension can leave an image request pending without an
            // error event. Fall back to the original instead of leaving a spinner.
            timeout = window.setTimeout(() => {
                if (!preview.complete && !preview.dataset.previewFallback) {
                    preview.dataset.previewFallback = '1';
                    preview.src = source.toString();
                }
            }, 15000);
            preview.src = thumbnail.toString();
        };
        const loadVisible = () => {
            frame = 0;
            const viewport = scroller.getBoundingClientRect();
            previews.forEach(preview => {
                if (preview.closest('tr')?.hidden || preview.getAttribute('src')) return;
                const box = preview.closest('.picapi-gallery-thumb')?.getBoundingClientRect();
                if (box && box.bottom > viewport.top && box.top < viewport.bottom && box.right > viewport.left && box.left < viewport.right) requestThumbnail(preview);
            });
        };
        const schedule = () => { if (!frame) frame = requestAnimationFrame(loadVisible); };
        scroller.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('resize', schedule, { passive: true });
        window.addEventListener('load', schedule, { once: true });
        const rows = document.querySelector('#gallery tbody');
        if (rows && 'MutationObserver' in window) {
            new MutationObserver(schedule).observe(rows, { subtree: true, attributes: true, attributeFilter: ['hidden', 'style', 'class'] });
        }
        requestAnimationFrame(schedule);
        window.setTimeout(schedule, 80);
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startGalleryPreviewLoader, { once: true });
    else startGalleryPreviewLoader();
})();
</script>
<script>
const imageStorageStats = <?= json_encode([
    ['label' => 'PC 图片总占用', 'value' => format_file_size($pcImageBytes), 'detail' => $pcCount . ' 张 PC 图片', 'icon' => 'monitor', 'color' => 'text-indigo-400'],
    ['label' => '手机图片总占用', 'value' => format_file_size($mobileImageBytes), 'detail' => $mobileCount . ' 张手机图片', 'icon' => 'smartphone', 'color' => 'text-emerald-400'],
    ['label' => '全部图片总占用', 'value' => format_file_size($totalImageBytes), 'detail' => count($images) . ' 张全部原始图片', 'icon' => 'hard-drive', 'color' => 'text-amber-400'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const categoryDisplayNames = <?= json_encode($categoryDisplayNames, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const categoryAliases = <?= json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const statsPanel = document.getElementById('stats');
if (statsPanel) {
    const storagePanel = document.createElement('section');
    storagePanel.className = 'grid grid-cols-1 gap-4 md:grid-cols-3';
    storagePanel.innerHTML = imageStorageStats.map(stat => `<div class="rounded-xl border border-slate-800 bg-slate-900 p-5 shadow-sm"><div class="flex items-center justify-between"><p class="text-xs font-medium text-slate-400">${stat.label}</p><i data-lucide="${stat.icon}" class="h-4 w-4 ${stat.color}"></i></div><p class="mt-2 text-2xl font-semibold text-white">${stat.value}</p><p class="mt-1 text-xs text-slate-500">${stat.detail}</p></div>`).join('');
    const renderStoragePanel = stats => {
        storagePanel.innerHTML = stats.map(stat => `<div class="rounded-xl border border-slate-800 bg-slate-900 p-5 shadow-sm"><div class="flex items-center justify-between"><p class="text-xs font-medium text-slate-400">${stat.label}</p><i data-lucide="${stat.icon}" class="h-4 w-4 ${stat.color}"></i></div><p class="mt-2 text-2xl font-semibold text-white">${stat.value}</p><p class="mt-1 text-xs text-slate-500">${stat.detail}</p></div>`).join('');
        lucide.createIcons({ nodes: [storagePanel] });
    };
    const visitMetrics = Array.from(statsPanel.children).find(element => element.querySelector('.text-3xl'));
    (visitMetrics || statsPanel).after(storagePanel);

    let requestPeriods = <?= json_encode($requestPeriodStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const requestLabels = { today: '今天', yesterday: '昨日', seven_days: '近 7 天', fourteen_days: '近 14 天' };
    const requestPanel = document.createElement('section');
    requestPanel.className = 'rounded-xl border border-slate-800 bg-slate-900 p-5 shadow-sm';
    requestPanel.innerHTML = '<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h3 class="text-sm font-semibold text-white">接口请求统计</h3><p class="mt-1 text-xs text-slate-500">自适应、PC 与手机接口的真实访问次数</p></div><div class="flex flex-wrap gap-1.5" data-request-periods></div></div><div class="grid grid-cols-1 gap-3 sm:grid-cols-4" data-request-values></div>';
    storagePanel.after(requestPanel);
    const periodButtons = requestPanel.querySelector('[data-request-periods]');
    const periodValues = requestPanel.querySelector('[data-request-values]');
    const renderRequestPeriod = period => {
        const stats = requestPeriods[period];
        periodButtons.innerHTML = Object.entries(requestLabels).map(([key, label]) => `<button type="button" data-period="${key}" class="rounded-lg px-2.5 py-1 text-[11px] font-medium ${key === period ? 'bg-indigo-600 text-white' : 'border border-slate-700 bg-slate-800 text-slate-300 hover:bg-slate-700'}">${label}</button>`).join('');
        const cards = [['自适应请求', stats.auto, 'route', 'text-amber-400'], ['PC 请求', stats.pc, 'monitor', 'text-indigo-400'], ['手机请求', stats.mobile, 'smartphone', 'text-emerald-400'], ['全部请求', stats.total, 'bar-chart-3', 'text-slate-300']];
        periodValues.innerHTML = cards.map(([label, value, icon, color]) => `<div class="rounded-lg border border-slate-800 bg-slate-950 p-4"><div class="flex items-center justify-between"><span class="text-xs text-slate-400">${label}</span><i data-lucide="${icon}" class="h-4 w-4 ${color}"></i></div><p class="mt-2 text-2xl font-semibold text-white">${Number(value).toLocaleString('zh-CN')}</p></div>`).join('');
        periodButtons.querySelectorAll('button').forEach(button => button.addEventListener('click', () => renderRequestPeriod(button.dataset.period)));
        lucide.createIcons({ nodes: [requestPanel] });
    };
    renderRequestPeriod('today');

    let requestDailySeries = <?= json_encode($requestDailySeries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let requestTrendChart = null;
    const chartPeriods = { today: 1, yesterday: 1, seven_days: 7, fourteen_days: 14 };
    const requestTrendPeriodStorageKey = 'picapi.dashboard.requestTrendPeriod';
    const savedRequestTrendPeriod = (() => {
        try { return window.localStorage.getItem(requestTrendPeriodStorageKey); } catch (error) { return null; }
    })();
    let selectedRequestTrendPeriod = Object.prototype.hasOwnProperty.call(chartPeriods, savedRequestTrendPeriod) ? savedRequestTrendPeriod : 'seven_days';
    const renderRequestChart = period => {
        selectedRequestTrendPeriod = period;
        const days = chartPeriods[period];
        const series = period === 'yesterday' ? requestDailySeries.slice(-2, -1) : requestDailySeries.slice(-days);
        const total = series.reduce((sum, item) => sum + item.auto + item.pc + item.mobile, 0);
        requestPanel.innerHTML = '<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h3 class="text-sm font-semibold text-white">接口请求趋势</h3><p class="mt-1 text-xs text-slate-500">自适应、PC 与手机接口的真实访问请求</p></div><div class="flex flex-wrap gap-1.5" data-request-chart-periods></div></div><div class="mb-3 flex items-center gap-2 text-xs text-slate-400">当前周期总请求 <strong class="font-mono text-white">' + Number(total).toLocaleString('zh-CN') + '</strong> 次</div><div class="h-72"><canvas data-request-trend-chart></canvas></div>';
        const chartButtons = requestPanel.querySelector('[data-request-chart-periods]');
        chartButtons.innerHTML = Object.entries(requestLabels).map(([key, label]) => `<button type="button" data-period="${key}" class="rounded-lg px-2.5 py-1 text-[11px] font-medium ${key === period ? 'bg-indigo-600 text-white' : 'border border-slate-700 bg-slate-800 text-slate-300 hover:bg-slate-700'}">${label}</button>`).join('');
        chartButtons.querySelectorAll('button').forEach(button => button.addEventListener('click', () => {
            const nextPeriod = button.dataset.period;
            try { window.localStorage.setItem(requestTrendPeriodStorageKey, nextPeriod); } catch (error) {}
            renderRequestChart(nextPeriod);
        }));
        if (requestTrendChart) requestTrendChart.destroy();
        const context = requestPanel.querySelector('[data-request-trend-chart]').getContext('2d');
        requestTrendChart = new Chart(context, {
            type: 'line',
            data: { labels: series.map(item => item.day.slice(5)), datasets: [
                { label: '自适应', data: series.map(item => item.auto), borderColor: '#f59e0b', backgroundColor: 'rgba(245, 158, 11, .14)', pointBackgroundColor: '#f59e0b', fill: false, tension: .36, borderWidth: 2.5 },
                { label: 'PC', data: series.map(item => item.pc), borderColor: '#818cf8', backgroundColor: 'rgba(129, 140, 248, .14)', pointBackgroundColor: '#818cf8', fill: false, tension: .36, borderWidth: 2.5 },
                { label: '手机', data: series.map(item => item.mobile), borderColor: '#34d399', backgroundColor: 'rgba(52, 211, 153, .14)', pointBackgroundColor: '#34d399', fill: false, tension: .36, borderWidth: 2.5 },
            ] },
            options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, elements: { point: { radius: 3, hoverRadius: 5 } }, plugins: { legend: { labels: { color: '#cbd5e1', boxWidth: 10, usePointStyle: true } } }, scales: { x: { ticks: { color: '#94a3b8' }, grid: { display: false } }, y: { beginAtZero: true, ticks: { color: '#94a3b8', precision: 0 }, grid: { color: 'rgba(51,65,85,.55)' } } } }
        });
    };
    renderRequestChart(selectedRequestTrendPeriod);
    const visitValues = visitMetrics?.querySelectorAll('.text-3xl') ?? [];
    const visitChange = visitMetrics?.children[0]?.querySelector('span.text-xs.font-medium');
    const refreshDashboardData = async () => {
        if (document.hidden) return;
        try {
            const response = await fetch('dashboard_data.php', { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) return;
            const data = await response.json();
            if (!Array.isArray(data.requestDailySeries) || !data.requestPeriods) return;
            requestPeriods = data.requestPeriods;
            requestDailySeries = data.requestDailySeries;
            renderStoragePanel(Array.isArray(data.imageStorageStats) ? data.imageStorageStats : imageStorageStats);
            if (visitValues[0]) visitValues[0].textContent = Number(data.todayRequests || 0).toLocaleString('zh-CN');
            if (visitValues[1]) visitValues[1].textContent = Number(data.yesterdayRequests || 0).toLocaleString('zh-CN');
            if (visitValues[2]) visitValues[2].textContent = Number(data.totalRequests || 0).toLocaleString('zh-CN');
            if (visitChange) {
                const change = Number(data.requestChange || 0);
                visitChange.classList.toggle('text-emerald-400', change >= 0);
                visitChange.classList.toggle('bg-emerald-500/10', change >= 0);
                visitChange.classList.toggle('text-rose-400', change < 0);
                visitChange.classList.toggle('bg-rose-500/10', change < 0);
                const textNode = visitChange.lastChild;
                if (textNode && textNode.nodeType === Node.TEXT_NODE) textNode.textContent = `${change}%`;
            }
            renderRequestChart(selectedRequestTrendPeriod);
            if (typeof window.updatePicapiDashboardCharts === 'function' && Array.isArray(data.dashboardDailySeries)) window.updatePicapiDashboardCharts(data.dashboardDailySeries);
        } catch (error) {
            // Keep the last successful dashboard data visible when a transient request fails.
        }
    };
    window.setInterval(refreshDashboardData, 5000);
}
function closeFolderModal() { document.getElementById('folder-modal')?.classList.add('hidden'); }
function openFolderModal(mode, category = '') {
    const modal = document.getElementById('folder-modal');
    if (!modal) return;
    const key = document.getElementById('modal-folder-key');
    const action = document.getElementById('folder-action');
    const old = document.getElementById('old-folder-key');
    document.getElementById('modal-title').textContent = mode === 'rename' ? '修改分类文件夹' : '新建分类文件夹';
    action.value = mode === 'rename' ? 'rename_category' : 'create_category';
    key.name = 'category_name';
    key.removeAttribute('pattern');
    key.maxLength = 60;
    key.value = mode === 'rename' ? (categoryDisplayNames[category] || category) : '';
    const nameLabel = key.previousElementSibling;
    if (nameLabel) nameLabel.textContent = '分类文件夹名称';
    let aliasInput = document.getElementById('modal-request-alias');
    if (!aliasInput) {
        const field = document.createElement('div');
        field.innerHTML = '<label class="block text-slate-400 mb-1">请求名称（英文）</label><input id="modal-request-alias" name="request_alias" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,48}" placeholder="留空自动创建，例如 anime" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-white focus:outline-none">';
        key.parentElement.after(field);
        aliasInput = field.querySelector('input');
    }
    aliasInput.value = mode === 'rename' ? category : '';
    const isDefaultCategory = mode === 'rename' && category === 'default';
    aliasInput.readOnly = isDefaultCategory;
    aliasInput.classList.toggle('opacity-60', isDefaultCategory);
    aliasInput.title = isDefaultCategory ? '默认分类的请求名称固定为 default' : '';
    old.value = mode === 'rename' ? category : '';
    modal.classList.remove('hidden');
    key.focus();
}
const uploadInput = document.getElementById('fileInput');
const uploadCategorySelect = document.querySelector('#chunk-upload-form select[name="category"]');
const galleryCategoryTabs = document.querySelector('#gallery > div:first-child > div:first-child');
if (galleryCategoryTabs) {
    const allResourcesTab = galleryCategoryTabs.querySelector('a');
    galleryCategoryTabs.classList.remove('flex-wrap');
    galleryCategoryTabs.classList.add('flex-nowrap', 'items-start', 'self-start', 'h-auto', 'overflow-x-auto', 'custom-scrollbar', 'pb-1', 'max-w-full');
    galleryCategoryTabs.style.height = 'auto';
    galleryCategoryTabs.style.alignItems = 'flex-start';
    galleryCategoryTabs.querySelectorAll('a').forEach(tab => {
        tab.classList.add('shrink-0', 'self-start', 'h-auto');
        tab.style.height = 'auto';
        tab.style.alignSelf = 'flex-start';
    });
    if (allResourcesTab) allResourcesTab.classList.add('sticky', 'left-0', 'z-10');
}
if (uploadCategorySelect) {
    const uploadCategoryStorageKey = 'picapi.upload.selectedCategory';
    const defaultOption = uploadCategorySelect.querySelector('option[value=""]');
    if (defaultOption) {
        defaultOption.value = 'default';
        defaultOption.textContent = '默认分类 (default)';
    }
    uploadCategorySelect.querySelectorAll('option[value="default"]').forEach(option => {
        if (option !== defaultOption) option.remove();
    });
    uploadCategorySelect.querySelectorAll('option').forEach(option => {
        const alias = option.value;
        if (categoryDisplayNames[alias]) option.textContent = `${categoryDisplayNames[alias]} (${alias})`;
    });
    try {
        const savedCategory = window.localStorage.getItem(uploadCategoryStorageKey);
        if (savedCategory && Array.from(uploadCategorySelect.options).some(option => option.value === savedCategory)) {
            uploadCategorySelect.value = savedCategory;
        } else if (savedCategory) {
            window.localStorage.removeItem(uploadCategoryStorageKey);
        }
        uploadCategorySelect.addEventListener('change', () => window.localStorage.setItem(uploadCategoryStorageKey, uploadCategorySelect.value));
    } catch (_) {
        // The upload still works when browser storage is unavailable.
    }
}
if (uploadInput) {
    uploadInput.addEventListener('change', () => {
        const file = uploadInput.files?.[0];
        if (!file) return;
        document.getElementById('upload-filename').textContent = file.name;
        document.getElementById('upload-progress-panel').classList.remove('hidden');
    });
}
document.querySelectorAll('[data-category]').forEach(control => {
    const alias = control.dataset.category;
    const card = control.closest('div.bg-slate-900');
    const title = card?.querySelector('h4');
    if (title && categoryDisplayNames[alias]) title.textContent = categoryDisplayNames[alias];
    if (title && alias && alias !== 'default' && !card.querySelector('[data-auto-category-endpoint]')) {
        const endpoint = document.createElement('p');
        endpoint.dataset.autoCategoryEndpoint = '';
        endpoint.className = 'mt-1 text-[11px] font-mono text-indigo-400 break-all';
        endpoint.textContent = `${window.location.origin.replace(/\/$/, '')}/?type=${encodeURIComponent(alias)}`;
        title.after(endpoint);
    }
});
document.querySelectorAll('#gallery a[href*="folder="]').forEach(link => {
    const alias = new URL(link.href, window.location.href).searchParams.get('folder');
    if (!alias || !categoryDisplayNames[alias]) return;
    const count = link.textContent.match(/\s*\(\d+\)\s*$/)?.[0] || '';
    link.textContent = `${categoryDisplayNames[alias]}${count}`;
});
const chunkUploadForm = document.getElementById('chunk-upload-form');
const uploadDropZone = document.querySelector('label[for="fileInput"]');
if (uploadInput) {
    uploadInput.multiple = true;
    uploadInput.name = 'image[]';
    uploadInput.addEventListener('change', () => {
        const total = uploadInput.files?.length || 0;
        if (!total) return;
        document.getElementById('upload-filename').textContent = total === 1
            ? uploadInput.files[0].name
            : `已选择 ${total} 个图片文件`;
        document.getElementById('upload-progress-panel').classList.remove('hidden');
        document.getElementById('upload-progress-bar').style.width = '0%';
        document.getElementById('upload-status-badge').textContent = '等待上传';
    });
}
if (uploadDropZone && uploadInput) {
    const uploadHint = uploadDropZone.querySelector('p.text-xs');
    if (uploadHint) uploadHint.textContent = 'JPEG、PNG、GIF、WebP · 支持任意大小的图片';
    const pickerActions = document.createElement('div');
    pickerActions.className = 'mt-3 flex flex-wrap justify-center gap-2';
    const createPickerButton = (label, icon, folder) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'inline-flex items-center gap-1.5 rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-xs font-medium text-slate-200 transition hover:bg-slate-700 hover:text-white';
        button.innerHTML = `<i data-lucide="${icon}" class="w-3.5 h-3.5"></i>${label}`;
        button.addEventListener('click', event => {
            event.preventDefault();
            uploadInput.removeAttribute('webkitdirectory');
            uploadInput.removeAttribute('directory');
            if (folder) {
                uploadInput.setAttribute('webkitdirectory', '');
                uploadInput.setAttribute('directory', '');
            }
            uploadInput.click();
        });
        return button;
    };
    pickerActions.append(
        createPickerButton('多选文件上传', 'files', false),
        createPickerButton('选择文件夹上传', 'folder-open', true),
    );
    uploadDropZone.insertAdjacentElement('afterend', pickerActions);
    ['dragenter', 'dragover'].forEach(type => uploadDropZone.addEventListener(type, event => {
        event.preventDefault();
        uploadDropZone.classList.add('border-indigo-500', 'bg-indigo-500/5');
    }));
    ['dragleave', 'drop'].forEach(type => uploadDropZone.addEventListener(type, event => {
        event.preventDefault();
        uploadDropZone.classList.remove('border-indigo-500', 'bg-indigo-500/5');
    }));
    uploadDropZone.addEventListener('drop', event => {
        const dropped = Array.from(event.dataTransfer?.files || []).filter(file => !file.type || file.type.startsWith('image/'));
        if (!dropped.length) return;
        const transfer = new DataTransfer();
        dropped.forEach(file => transfer.items.add(file));
        uploadInput.files = transfer.files;
        uploadInput.dispatchEvent(new Event('change', { bubbles: true }));
    });
}
async function publishUploadThumbnail(file, metadata, csrf) {
    if (!metadata?.device || !metadata?.relative || !csrf || !window.createImageBitmap) return false;
    try {
        const bitmap = await createImageBitmap(file);
        const maxEdge = 480;
        const scale = Math.min(1, maxEdge / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        const context = canvas.getContext('2d', { alpha: false });
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();
        const thumbnail = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
        canvas.width = 1;
        canvas.height = 1;
        if (!thumbnail) return false;
        const data = new FormData();
        data.append('csrf', csrf);
        data.append('device', metadata.device);
        data.append('relative', metadata.relative);
        data.append('thumbnail', thumbnail, 'preview.png');
        const response = await fetch('upload_thumbnail.php', { method: 'POST', body: data, credentials: 'same-origin' });
        return response.ok;
    } catch (_) {
        // The original image was uploaded successfully; the list falls back to it.
        return false;
    }
}
function uploadChunkSize(fileSize) {
    const kib = 1024;
    const mib = 1024 * kib;
    if (fileSize <= 2 * mib) return Math.max(256 * kib, fileSize);
    if (fileSize <= 32 * mib) return 768 * kib;
    if (fileSize <= 256 * mib) return 1 * mib;
    if (fileSize <= 1024 * mib) return 1536 * kib;
    return 2 * mib;
}
let thumbnailGenerationQueue = Promise.resolve();
let uploadCancelRequested = false;
let activeUploadController = null;
let cancelUploadButton = null;
if (chunkUploadForm) {
    const uploadSubmitButton = chunkUploadForm.querySelector('button[type="submit"]');
    if (uploadSubmitButton?.parentElement) {
        cancelUploadButton = document.createElement('button');
        cancelUploadButton.type = 'button';
        cancelUploadButton.disabled = true;
        cancelUploadButton.className = 'mr-2 rounded-lg border border-slate-700 bg-slate-800 px-4 py-2 text-xs font-medium text-slate-500 transition disabled:cursor-not-allowed enabled:text-slate-200 enabled:hover:border-rose-500/60 enabled:hover:text-rose-300';
        cancelUploadButton.textContent = '取消上传';
        uploadSubmitButton.parentElement.prepend(cancelUploadButton);
        cancelUploadButton.addEventListener('click', () => {
            if (cancelUploadButton.disabled) return;
            uploadCancelRequested = true;
            activeUploadController?.abort();
            cancelUploadButton.disabled = true;
        });
    }
}
function queueMissingThumbnail(preview, metadata, csrf, thumbnailUrl) {
    thumbnailGenerationQueue = thumbnailGenerationQueue.then(async () => {
        const response = await fetch(preview.dataset.fullImage, { credentials: 'same-origin' });
        if (!response.ok) throw new Error('Unable to read original image.');
        const created = await publishUploadThumbnail(await response.blob(), metadata, csrf);
        if (created) {
            preview.src = `${thumbnailUrl}&v=${Date.now()}`;
            return;
        }
        preview.dataset.fallbackLoaded = '1';
        preview.src = preview.dataset.fullImage;
    }).catch(() => {
        preview.dataset.fallbackLoaded = '1';
        preview.src = preview.dataset.fullImage;
    });
}
if (chunkUploadForm && window.fetch && window.crypto?.getRandomValues) {
    // Capture phase replaces the legacy single-file handler below. It keeps the
    // same chunk protocol while sending every selected or dropped image.
    chunkUploadForm.addEventListener('submit', async event => {
        event.preventDefault();
        event.stopImmediatePropagation();
        uploadCancelRequested = false;
        const files = Array.from(uploadInput?.files || []).filter(file => {
            return file.type
                ? file.type.startsWith('image/')
                : /\.(jpe?g|png|gif|webp)$/i.test(file.name);
        });
        if (!files.length) {
            document.getElementById('upload-status-badge').textContent = '请选择至少一个支持的图片文件';
            return;
        }
        const invalid = files.find(file => file.size < 1);
        const badge = document.getElementById('upload-status-badge');
        const bar = document.getElementById('upload-progress-bar');
        const filename = document.getElementById('upload-filename');
        const button = chunkUploadForm.querySelector('button[type="submit"]');
        if (invalid) {
            badge.textContent = `“${invalid.name}” 不是有效的图片文件`;
            badge.className = 'bg-rose-500/10 text-rose-400 px-2 py-0.5 rounded-full';
            return;
        }

        const category = chunkUploadForm.querySelector('[name="category"]').value;
        const csrf = chunkUploadForm.querySelector('[name="csrf"]').value;
        button.disabled = true;
        if (cancelUploadButton) cancelUploadButton.disabled = false;
        bar.style.width = '0%';
        try {
            for (let fileIndex = 0; fileIndex < files.length; fileIndex += 1) {
                if (uploadCancelRequested) throw new DOMException('Upload cancelled.', 'AbortError');
                const file = files[fileIndex];
                const chunkSize = uploadChunkSize(file.size);
                const bytes = new Uint8Array(16);
                crypto.getRandomValues(bytes);
                const uploadId = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
                const total = Math.ceil(file.size / chunkSize);
                filename.textContent = file.name;
                let completedPayload = null;
                for (let index = 0; index < total; index += 1) {
                    if (uploadCancelRequested) throw new DOMException('Upload cancelled.', 'AbortError');
                    badge.textContent = `上传中 ${fileIndex + 1}/${files.length} · 第 ${index + 1}/${total} 块`;
                    const data = new FormData();
                    data.append('csrf', csrf);
                    data.append('upload_id', uploadId);
                    data.append('index', String(index));
                    data.append('total', String(total));
                    data.append('category', category);
                    data.append('chunk', file.slice(index * chunkSize, Math.min(file.size, (index + 1) * chunkSize)), file.name);
                    activeUploadController = new AbortController();
                    let response = await fetch('upload_chunk.php', { method: 'POST', body: data, credentials: 'same-origin', signal: activeUploadController.signal });
                    activeUploadController = null;
                    let body = await response.text();
                    let payload = {};
                    try { payload = JSON.parse(body); } catch (_) { /* The HTTP status below remains useful. */ }
                    while (response.status === 409 && payload.conflict) {
                        const resolution = await window.picapiResolveUploadConflict(payload.filename || file.name, payload.server_image_url || '', file);
                        if (!resolution) throw new DOMException('Upload cancelled.', 'AbortError');
                        data.set('conflict_action', resolution.action);
                        if (resolution.filename) data.set('renamed_filename', resolution.filename);
                        activeUploadController = new AbortController();
                        response = await fetch('upload_chunk.php', { method: 'POST', body: data, credentials: 'same-origin', signal: activeUploadController.signal });
                        activeUploadController = null;
                        body = await response.text();
                        try { payload = JSON.parse(body); } catch (_) { payload = {}; }
                    }
                    if (!response.ok || !payload.ok) throw new Error(payload.message || `上传失败（HTTP ${response.status}）`);
                    if (payload.complete) completedPayload = payload;
                    const complete = (fileIndex + ((index + 1) / total)) / files.length;
                    bar.style.width = `${Math.round(complete * 100)}%`;
                }
                if (uploadCancelRequested) throw new DOMException('Upload cancelled.', 'AbortError');
                // The gallery builds its own cached PNG from the saved original.
                // Avoid decoding a just-uploaded large file in the browser, which
                // would temporarily consume a large amount of client memory.
            }
            badge.textContent = `已完成 ${files.length} 个图片上传`;
            bar.style.width = '100%';
            window.setTimeout(() => window.location.assign('upload.php'), 450);
        } catch (error) {
            if (uploadCancelRequested || (error instanceof DOMException && error.name === 'AbortError')) {
                badge.textContent = '上传已取消。';
                badge.className = 'bg-amber-500/10 text-amber-400 px-2 py-0.5 rounded-full';
                button.disabled = false;
                return;
            }
            badge.textContent = error instanceof Error ? error.message : '上传失败';
            badge.className = 'bg-rose-500/10 text-rose-400 px-2 py-0.5 rounded-full';
            button.disabled = false;
        } finally {
            activeUploadController = null;
            if (cancelUploadButton) cancelUploadButton.disabled = true;
        }
    }, true);
}
if (chunkUploadForm && window.fetch && window.crypto?.getRandomValues) {
    chunkUploadForm.addEventListener('submit', async (event) => {
        const file = uploadInput?.files?.[0];
        if (!file) return;
        event.preventDefault();
        const button = chunkUploadForm.querySelector('button[type="submit"]');
        const badge = document.getElementById('upload-status-badge');
        const bar = document.getElementById('upload-progress-bar');
        const bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        const uploadId = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
        const chunkSize = uploadChunkSize(file.size);
        const total = Math.ceil(file.size / chunkSize);
        button.disabled = true;
        badge.textContent = '上传中...';
        try {
            for (let index = 0; index < total; index++) {
                const data = new FormData();
                data.append('csrf', chunkUploadForm.querySelector('[name="csrf"]').value);
                data.append('upload_id', uploadId);
                data.append('index', index);
                data.append('total', total);
                data.append('category', chunkUploadForm.querySelector('[name="category"]').value);
                data.append('chunk', file.slice(index * chunkSize, Math.min(file.size, (index + 1) * chunkSize)), file.name);
                const response = await fetch('upload_chunk.php', { method: 'POST', body: data, credentials: 'same-origin' });
                const payload = await response.json();
                if (!response.ok || !payload.ok) throw new Error(payload.message || '上传失败。');
                bar.style.width = `${payload.progress || Math.round(((index + 1) / total) * 100)}%`;
            }
            badge.textContent = '上传完成';
            window.location.assign('upload.php');
        } catch (error) {
            badge.textContent = error.message || '上传失败';
            badge.className = 'bg-rose-500/10 text-rose-400 px-2 py-0.5 rounded-full';
            button.disabled = false;
        }
    });
}
if (window.Chart) {
    const chartLabels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
    const chartRequests = <?= json_encode($chartRequests) ?>;
    const chartOutputBytes = <?= json_encode($chartOutputBytes) ?>;
    const requestCanvas = document.getElementById('requestsChart');
    const trafficCanvas = document.getElementById('trafficChart');
    const chartOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { color: '#64748b' }, grid: { color: '#1e293b' } }, y: { ticks: { color: '#64748b', precision: 0 }, grid: { color: '#1e293b' }, beginAtZero: true } } };
    if (requestCanvas) new Chart(requestCanvas, { type: 'line', data: { labels: chartLabels, datasets: [{ data: chartRequests, borderColor: '#818cf8', backgroundColor: 'rgba(129,140,248,.16)', fill: true, tension: .38, pointRadius: 3 }] }, options: chartOptions });
    if (trafficCanvas) {
        const trafficCard = trafficCanvas.closest('.bg-slate-900');
        const trafficTitle = trafficCard?.querySelector('h3');
        if (trafficTitle) trafficTitle.textContent = '总输出流量（近7天）';
        const formatTraffic = value => {
            if (value < 1024) return `${value} B`;
            if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
            return `${(value / (1024 * 1024)).toFixed(1)} MB`;
        };
        new Chart(trafficCanvas, {
            type: 'line',
            data: { labels: chartLabels, datasets: [{ data: chartOutputBytes, borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,.16)', fill: true, tension: .38, pointRadius: 3, pointHoverRadius: 4 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: context => formatTraffic(context.parsed.y) } } }, scales: { x: { ticks: { color: '#94a3b8' }, grid: { color: '#1e293b' } }, y: { ticks: { color: '#94a3b8', callback: value => formatTraffic(value) }, grid: { color: '#1e293b' }, beginAtZero: true } } },
        });
    }
}
if (window.Chart && document.getElementById('requestsChart')) {
    let dashboardDailySeries = <?= json_encode(array_map(static fn(string $day): array => ['day' => $day, 'requests' => $dailyTotals[$day], 'bytes' => $dailyBytes[$day]], array_keys($dailyTotals)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const dashboardPeriods = { today: 1, yesterday: 1, seven_days: 7, fourteen_days: 14 };
    const dashboardPeriodLabels = { today: '今天', yesterday: '昨日', seven_days: '近 7 天', fourteen_days: '近 14 天' };
    const trafficLabel = value => value < 1024 ? `${value} B` : value < 1048576 ? `${(value / 1024).toFixed(1)} KB` : `${(value / 1048576).toFixed(1)} MB`;
    const requestCanvas = document.getElementById('requestsChart');
    const trafficCanvas = document.getElementById('trafficChart');
    const requestCard = requestCanvas.closest('.bg-slate-900');
    const trafficCard = trafficCanvas?.closest('.bg-slate-900');
    let dashboardRequestPeriod = 'seven_days';
    let dashboardTrafficPeriod = 'seven_days';
    const addPeriodControls = (card, period, onSelect) => {
        const header = card.querySelector('h3')?.parentElement;
        if (!header) return;
        header.classList.add('flex-wrap');
        let controls = header.querySelector('[data-dashboard-periods]');
        if (!controls) { controls = document.createElement('div'); controls.className = 'flex flex-wrap gap-1'; controls.dataset.dashboardPeriods = ''; header.append(controls); }
        controls.innerHTML = Object.entries(dashboardPeriodLabels).map(([key, label]) => `<button type="button" data-period="${key}" class="rounded px-2 py-1 text-[10px] font-medium ${key === period ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-400 hover:text-white'}">${label}</button>`).join('');
        controls.querySelectorAll('button').forEach(button => button.addEventListener('click', () => onSelect(button.dataset.period)));
    };
    const selectSeries = period => period === 'yesterday' ? dashboardDailySeries.slice(-2, -1) : dashboardDailySeries.slice(-dashboardPeriods[period]);
    const renderDashboardCharts = period => {
        const series = selectSeries(period);
        const labels = series.map(item => item.day.slice(5));
        Chart.getChart(requestCanvas)?.destroy();
        new Chart(requestCanvas, { type: 'line', data: { labels, datasets: [{ data: series.map(item => item.requests), borderColor: '#818cf8', backgroundColor: 'rgba(129,140,248,.16)', fill: true, tension: .38, pointRadius: 3 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { color: '#64748b' }, grid: { color: '#1e293b' } }, y: { ticks: { color: '#64748b', precision: 0 }, grid: { color: '#1e293b' }, beginAtZero: true } } } });
        if (trafficCanvas) {
            Chart.getChart(trafficCanvas)?.destroy();
            new Chart(trafficCanvas, { type: 'line', data: { labels, datasets: [{ data: series.map(item => item.bytes), borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,.16)', fill: true, tension: .38, pointRadius: 3, pointHoverRadius: 4 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: context => trafficLabel(context.parsed.y) } } }, scales: { x: { ticks: { color: '#94a3b8' }, grid: { color: '#1e293b' } }, y: { ticks: { color: '#94a3b8', callback: value => trafficLabel(value) }, grid: { color: '#1e293b' }, beginAtZero: true } } } });
        }
        requestCard.querySelector('h3').textContent = `每日请求频次（${dashboardPeriodLabels[period]}）`;
        if (trafficCard) trafficCard.querySelector('h3').textContent = `总输出流量（${dashboardPeriodLabels[period]}）`;
        addPeriodControls(requestCard, period, renderDashboardCharts);
        if (trafficCard) addPeriodControls(trafficCard, period, renderDashboardCharts);
    };
    const renderRequestsChart = period => {
        dashboardRequestPeriod = period;
        const series = selectSeries(period);
        Chart.getChart(requestCanvas)?.destroy();
        new Chart(requestCanvas, { type: 'line', data: { labels: series.map(item => item.day.slice(5)), datasets: [{ data: series.map(item => item.requests), borderColor: '#818cf8', backgroundColor: 'rgba(129,140,248,.16)', fill: true, tension: .38, pointRadius: 3 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { color: '#64748b' }, grid: { color: '#1e293b' } }, y: { ticks: { color: '#64748b', precision: 0 }, grid: { color: '#1e293b' }, beginAtZero: true } } } });
        requestCard.querySelector('h3').textContent = `每日请求频次（${dashboardPeriodLabels[period]}）`;
        addPeriodControls(requestCard, period, renderRequestsChart);
    };
    const renderTrafficChart = period => {
        if (!trafficCanvas || !trafficCard) return;
        dashboardTrafficPeriod = period;
        const series = selectSeries(period);
        Chart.getChart(trafficCanvas)?.destroy();
        new Chart(trafficCanvas, { type: 'line', data: { labels: series.map(item => item.day.slice(5)), datasets: [{ data: series.map(item => item.bytes), borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,.16)', fill: true, tension: .38, pointRadius: 3, pointHoverRadius: 4 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: context => trafficLabel(context.parsed.y) } } }, scales: { x: { ticks: { color: '#94a3b8' }, grid: { color: '#1e293b' } }, y: { ticks: { color: '#94a3b8', callback: value => trafficLabel(value) }, grid: { color: '#1e293b' }, beginAtZero: true } } } });
        trafficCard.querySelector('h3').textContent = `总输出流量（${dashboardPeriodLabels[period]}）`;
        addPeriodControls(trafficCard, period, renderTrafficChart);
    };
    renderRequestsChart('seven_days');
    renderTrafficChart('seven_days');
    window.updatePicapiDashboardCharts = series => {
        dashboardDailySeries = series;
        renderRequestsChart(dashboardRequestPeriod);
        renderTrafficChart(dashboardTrafficPeriod);
    };
}
const currentApiBase = window.location.origin.replace(/\/$/, '');
const apiExamples = [`${currentApiBase}/pc/`, `${currentApiBase}/m/`, `${currentApiBase}/`];
document.querySelectorAll('#stats code').forEach((element, index) => {
    if (apiExamples[index]) element.textContent = apiExamples[index];
});
const PAGE_SIZES = [20, 40, 60, 80, 100];

function pagerPages(totalPages, currentPage) {
    if (totalPages <= 5) return Array.from({ length: totalPages }, (_, index) => index + 1);
    const pages = [1];
    const start = Math.max(2, currentPage - 1);
    const end = Math.min(totalPages - 1, currentPage + 1);
    if (start > 2) pages.push('…');
    for (let page = start; page <= end; page += 1) pages.push(page);
    if (end < totalPages - 1) pages.push('…');
    pages.push(totalPages);
    return pages;
}

function pagerButton(page, label, active = false, disabled = false, title = '') {
    const state = disabled ? ' disabled' : '';
    const accessibility = title ? ` title="${title}" aria-label="${title}"` : '';
    const kind = active
        ? 'bg-indigo-600 text-white font-semibold'
        : (disabled ? 'bg-slate-800 border border-slate-700 text-slate-500 cursor-not-allowed' : 'bg-slate-800 border border-slate-700 text-slate-300 hover:bg-slate-700');
    return `<button type="button" data-page="${page}"${state}${accessibility} class="px-2.5 py-1 rounded text-[11px] ${kind}">${label}</button>`;
}

function setupPager(rows, header, options) {
    if (!header) return;
    const params = new URLSearchParams(window.location.search);
    const requestedSize = Number(params.get(options.sizeKey));
    const perPage = PAGE_SIZES.includes(requestedSize) ? requestedSize : 20;
    const requestedPage = Number(params.get(options.pageKey));
    const total = rows.length;
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    const currentPage = Math.min(Math.max(Number.isInteger(requestedPage) && requestedPage > 0 ? requestedPage : 1, 1), totalPages);
    const first = total === 0 ? 0 : ((currentPage - 1) * perPage) + 1;
    const last = Math.min(currentPage * perPage, total);

    rows.forEach((row, index) => {
        row.hidden = index < first - 1 || index >= last;
    });

    const selectOptions = PAGE_SIZES.map(size => `<option value="${size}"${size === perPage ? ' selected' : ''}>${size}</option>`).join('');
    const pageButtons = pagerPages(totalPages, currentPage).map(page => {
        if (page === '…') return '<span class="px-1 text-slate-600 text-[11px]">…</span>';
        return pagerButton(page, page, page === currentPage);
    }).join('');
    if (options.preserveHeading) {
        const heading = header.querySelector('.flex.items-center.gap-2')?.outerHTML || '';
        header.innerHTML = `${heading}<div class="flex items-center flex-wrap gap-4 text-xs text-slate-400"><div class="flex items-center gap-1.5"><span>${options.perPageLabel || '每页显示'}</span><select aria-label="每页显示条数" class="bg-slate-800 border border-slate-700 text-white rounded px-1.5 py-0.5 focus:outline-none">${selectOptions}</select><span>${options.unit}</span></div><div class="flex items-center gap-1">${pagerButton(currentPage - 1, options.previousLabel || '上一页', false, currentPage === 1, options.previousTitle || '上一页')}${pageButtons}${pagerButton(currentPage + 1, options.nextLabel || '下一页', false, currentPage === totalPages, options.nextTitle || '下一页')}</div></div>`;
        header.className = options.headerClass;
        header.querySelector('select')?.addEventListener('change', event => {
            const url = new URL(window.location.href);
            url.searchParams.set(options.sizeKey, event.currentTarget.value);
            url.searchParams.set(options.pageKey, '1');
            window.history.replaceState(null, '', url);
            setupPager(rows, header, options);
        });
        header.querySelectorAll('button[data-page]:not([disabled])').forEach(button => {
            button.addEventListener('click', () => {
                const url = new URL(window.location.href);
                url.searchParams.set(options.sizeKey, String(perPage));
                url.searchParams.set(options.pageKey, button.dataset.page);
                window.history.replaceState(null, '', url);
                setupPager(rows, header, options);
            });
        });
        return;
    }
    header.innerHTML = `<div class="flex items-center gap-4"><div>显示第 <span class="font-mono text-slate-300">${first}</span> 至 <span class="font-mono text-slate-300">${last}</span> 行，共 <span class="font-mono text-slate-300">${total}</span> ${options.unit}</div><div class="flex items-center gap-1.5 border-l border-slate-800 pl-4"><span>每页</span><select aria-label="每页显示条数" class="bg-slate-800 border border-slate-700 text-white rounded px-1.5 py-0.5 focus:outline-none">${selectOptions}</select><span>条</span></div></div><div class="flex items-center gap-1">${pagerButton(currentPage - 1, options.previousLabel || '上一页', false, currentPage === 1, options.previousTitle || '上一页')}${pageButtons}${pagerButton(currentPage + 1, options.nextLabel || '下一页', false, currentPage === totalPages, options.nextTitle || '下一页')}</div>`;
    header.className = options.headerClass;

    header.querySelector('select')?.addEventListener('change', event => {
        const url = new URL(window.location.href);
        url.searchParams.set(options.sizeKey, event.currentTarget.value);
        url.searchParams.set(options.pageKey, '1');
        window.history.replaceState(null, '', url);
        setupPager(rows, header, options);
    });
    header.querySelectorAll('button[data-page]:not([disabled])').forEach(button => {
        button.addEventListener('click', () => {
            const url = new URL(window.location.href);
            url.searchParams.set(options.sizeKey, String(perPage));
            url.searchParams.set(options.pageKey, button.dataset.page);
            window.history.replaceState(null, '', url);
            setupPager(rows, header, options);
        });
    });
}

const galleryRows = Array.from(document.querySelectorAll('#gallery tbody tr')).filter(row => row.children.length > 1);
setupPager(galleryRows, document.querySelector('#gallery .relative > div'), {
    pageKey: 'gallery_page',
    sizeKey: 'gallery_per_page',
    unit: '条资源',
    headerClass: 'p-3 flex flex-col sm:flex-row sm:items-center justify-between gap-4 text-xs text-slate-400 w-full',
    previousLabel: '←',
    nextLabel: '→',
    previousTitle: '上一页',
    nextTitle: '下一页',
});

function openFullImagePreview(url, label, candidates = []) {
    const existing = document.getElementById('full-image-preview');
    if (existing) existing.remove();
    const overlay = document.createElement('div');
    overlay.id = 'full-image-preview';
    overlay.className = 'fixed inset-0 z-50 flex items-center justify-center bg-slate-950/95 p-4';
    overlay.innerHTML = '<div data-preview-stage class="flex h-full w-full items-center justify-center overflow-hidden select-none"><img data-preview-image draggable="false" class="max-h-full max-w-full object-contain shadow-2xl" alt=""></div><div class="absolute left-1/2 top-5 flex -translate-x-1/2 items-center gap-1 rounded-xl border border-slate-700 bg-slate-900/95 p-1 text-slate-200 shadow-xl"><button type="button" data-preview-zoom-out class="rounded-lg p-2 hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40" aria-label="缩小"><i data-lucide="minus" class="h-4 w-4"></i></button><span data-preview-zoom-label class="min-w-12 text-center text-xs font-mono text-slate-300">100%</span><button type="button" data-preview-zoom-in class="rounded-lg p-2 hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40" aria-label="放大"><i data-lucide="plus" class="h-4 w-4"></i></button><button type="button" data-preview-reset class="ml-1 rounded-lg border-l border-slate-700 p-2 text-slate-400 hover:bg-slate-800 hover:text-white" aria-label="重置缩放"><i data-lucide="rotate-ccw" class="h-4 w-4"></i></button></div><button type="button" data-preview-close class="absolute right-5 top-5 rounded-lg border border-slate-700 bg-slate-900 p-2 text-slate-300 hover:text-white" aria-label="关闭预览"><i data-lucide="x" class="h-5 w-5"></i></button>';
    const toolbar = overlay.querySelector('[data-preview-zoom-out]').parentElement;
    toolbar.classList.remove('top-5');
    toolbar.classList.add('bottom-5');
    overlay.querySelector('[data-preview-zoom-out]').setAttribute('title', '缩小图片');
    overlay.querySelector('[data-preview-zoom-in]').setAttribute('title', '放大图片');
    overlay.querySelector('[data-preview-reset]').setAttribute('title', '重置缩放和位置');
    overlay.querySelector('[data-preview-close]').setAttribute('title', '关闭预览');
    const addRotationButton = (direction, icon, label) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.previewRotate = direction;
        button.className = `rounded-lg p-2 hover:bg-slate-800${direction === 'right' ? ' border-r border-slate-700' : ''}`;
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
        button.innerHTML = `<i data-lucide="${icon}" class="h-4 w-4"></i>`;
        toolbar.insertBefore(button, toolbar.firstChild);
    };
    addRotationButton('right', 'rotate-cw', '向右旋转 90 度');
    addRotationButton('left', 'rotate-ccw', '向左旋转 90 度');
    const stage = overlay.querySelector('[data-preview-stage]');
    const image = overlay.querySelector('[data-preview-image]');
    stage.style.position = 'relative';
    image.style.transition = 'opacity .15s ease';
    const loadingIndicator = document.createElement('div');
    loadingIndicator.dataset.previewLoader = '';
    loadingIndicator.className = 'pointer-events-none absolute inset-0 grid place-items-center';
    loadingIndicator.innerHTML = '<span class="picapi-preview-loader" aria-label="图片加载中"></span>';
    stage.append(loadingIndicator);
    const zoomLabel = overlay.querySelector('[data-preview-zoom-label]');
    const zoomIn = overlay.querySelector('[data-preview-zoom-in]');
    const zoomOut = overlay.querySelector('[data-preview-zoom-out]');
    const previewItems = Array.isArray(candidates) ? candidates.filter(candidate => candidate?.dataset?.fullImage) : [];
    let previewIndex = previewItems.findIndex(candidate => candidate.dataset.fullImage === url);
    const navigation = document.createElement('div');
    navigation.className = 'pointer-events-none absolute inset-x-5 top-1/2 flex -translate-y-1/2 items-center justify-between';
    navigation.innerHTML = '<button type="button" data-preview-previous class="pointer-events-auto inline-flex items-center gap-1 rounded-lg border border-slate-700 bg-slate-900/95 px-3 py-2 text-xs font-medium text-slate-200 shadow-xl transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-35" aria-label="上一张图片"><i data-lucide="chevron-left" class="h-4 w-4"></i><span class="hidden sm:inline">上一张</span></button><span data-preview-position class="rounded-md border border-slate-700 bg-slate-900/95 px-2.5 py-1.5 text-xs font-mono text-slate-300 shadow-xl"></span><button type="button" data-preview-next class="pointer-events-auto inline-flex items-center gap-1 rounded-lg border border-slate-700 bg-slate-900/95 px-3 py-2 text-xs font-medium text-slate-200 shadow-xl transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-35" aria-label="下一张图片"><span class="hidden sm:inline">下一张</span><i data-lucide="chevron-right" class="h-4 w-4"></i></button>';
    const previousButton = navigation.querySelector('[data-preview-previous]');
    const nextButton = navigation.querySelector('[data-preview-next]');
    const positionLabel = navigation.querySelector('[data-preview-position]');
    previousButton.innerHTML = '<i data-lucide="chevron-left" class="h-5 w-5"></i>';
    nextButton.innerHTML = '<i data-lucide="chevron-right" class="h-5 w-5"></i>';
    previousButton.setAttribute('title', '上一张图片');
    nextButton.setAttribute('title', '下一张图片');
    positionLabel.className = 'ml-1 border-l border-slate-700 pl-3 pr-1 text-xs font-mono text-slate-300';
    if (previewItems.length > 1 && previewIndex >= 0) {
        overlay.append(navigation);
        toolbar.append(positionLabel);
    }
    let zoom = 1;
    let panX = 0;
    let panY = 0;
    let rotation = 0;
    let drag = null;
    const renderTransform = () => {
        image.style.transform = `translate(${panX}px, ${panY}px) scale(${zoom}) rotate(${rotation}deg)`;
        image.style.cursor = zoom > 1 ? (drag ? 'grabbing' : 'grab') : 'zoom-in';
        zoomLabel.textContent = `${Math.round(zoom * 100)}%`;
        zoomIn.disabled = zoom >= 8;
        zoomOut.disabled = zoom <= .1;
    };
    const resetTransform = () => {
        zoom = 1;
        panX = 0;
        panY = 0;
        rotation = 0;
        renderTransform();
    };
    const setPreviewLoading = loading => {
        if (loading) {
            loadingIndicator.innerHTML = '<span class="picapi-preview-loader" aria-label="图片加载中"></span>';
            loadingIndicator.style.display = 'grid';
        } else {
            loadingIndicator.style.display = 'none';
        }
        image.style.opacity = loading ? '0' : '1';
    };
    const showPreviewLoadError = () => {
        loadingIndicator.innerHTML = '<span class="rounded-lg border border-rose-400/40 bg-slate-900/95 px-3 py-2 text-xs text-rose-300">图片加载失败</span>';
        loadingIndicator.style.display = 'grid';
    };
    const loadPreviewImage = (source, alt) => {
        setPreviewLoading(true);
        image.alt = alt;
        image.src = source;
        if (!image.complete) return;
        if (image.naturalWidth > 0) {
            setPreviewLoading(false);
            resetTransform();
        } else {
            showPreviewLoadError();
        }
    };
    const changeZoom = (amount, anchor = null) => {
        const current = zoom;
        const next = Math.max(.1, Math.min(8, Number((zoom + amount).toFixed(2))));
        if (anchor && next !== current) {
            const stageBox = stage.getBoundingClientRect();
            const pointerX = anchor.clientX - (stageBox.left + stageBox.width / 2);
            const pointerY = anchor.clientY - (stageBox.top + stageBox.height / 2);
            const ratio = next / current;
            panX += (1 - ratio) * (pointerX - panX);
            panY += (1 - ratio) * (pointerY - panY);
        } else if (next === 1) {
            panX = 0;
            panY = 0;
        }
        zoom = next;
        renderTransform();
    };
    const rotateImage = amount => {
        rotation = (rotation + amount + 360) % 360;
        renderTransform();
    };
    const updatePreviewNavigation = () => {
        if (previewItems.length < 2 || previewIndex < 0) return;
        previousButton.disabled = previewIndex <= 0;
        nextButton.disabled = previewIndex >= previewItems.length - 1;
        positionLabel.textContent = `${previewIndex + 1} / ${previewItems.length}`;
    };
    const changePreview = direction => {
        const nextIndex = previewIndex + direction;
        if (nextIndex < 0 || nextIndex >= previewItems.length) return;
        previewIndex = nextIndex;
        const nextPreview = previewItems[previewIndex];
        loadPreviewImage(nextPreview.dataset.fullImage, nextPreview.dataset.previewLabel || nextPreview.alt || '');
        resetTransform();
        updatePreviewNavigation();
    };
    const onKeydown = event => {
        if (event.key === 'Escape') close();
        if (event.key === 'ArrowLeft') { event.preventDefault(); changePreview(-1); }
        if (event.key === 'ArrowRight') { event.preventDefault(); changePreview(1); }
        if (event.key === '+' || event.key === '=') changeZoom(.25);
        if (event.key === '-') changeZoom(-.25);
        if (event.key === '0') resetTransform();
    };
    const close = () => {
        document.removeEventListener('keydown', onKeydown);
        const viewedRow = previewItems[previewIndex]?.closest('tr');
        if (viewedRow) {
            document.querySelectorAll('#gallery tbody tr.picapi-gallery-previewed').forEach(row => row.classList.remove('picapi-gallery-previewed'));
            viewedRow.classList.add('picapi-gallery-previewed');
            const scroller = viewedRow.closest('.overflow-x-auto.custom-scrollbar');
            if (scroller) {
                const rowBox = viewedRow.getBoundingClientRect();
                const scrollerBox = scroller.getBoundingClientRect();
                const scrollTop = scroller.scrollTop + rowBox.top - scrollerBox.top - Math.max(0, (scroller.clientHeight - rowBox.height) / 2);
                scroller.scrollTo({ top: Math.max(0, scrollTop), behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
            }
        }
        overlay.remove();
    };
    overlay.querySelector('[data-preview-close]').addEventListener('click', () => {
        ignoreGalleryPreviewHighlightClear = true;
        close();
    });
    overlay.querySelector('[data-preview-zoom-in]').addEventListener('click', () => changeZoom(.25));
    overlay.querySelector('[data-preview-zoom-out]').addEventListener('click', () => changeZoom(-.25));
    overlay.querySelector('[data-preview-reset]').addEventListener('click', resetTransform);
    overlay.querySelector('[data-preview-rotate="left"]').addEventListener('click', () => rotateImage(-90));
    overlay.querySelector('[data-preview-rotate="right"]').addEventListener('click', () => rotateImage(90));
    previousButton?.addEventListener('click', () => changePreview(-1));
    nextButton?.addEventListener('click', () => changePreview(1));
    stage.addEventListener('wheel', event => {
        event.preventDefault();
        changeZoom(event.deltaY < 0 ? .25 : -.25, event);
    }, { passive: false });
    stage.addEventListener('dblclick', event => { zoom > 1 ? resetTransform() : changeZoom(1, event); });
    stage.addEventListener('pointerdown', event => {
        if (zoom <= 1) return;
        drag = { pointerId: event.pointerId, x: event.clientX, y: event.clientY, panX, panY };
        stage.setPointerCapture?.(event.pointerId);
        renderTransform();
    });
    stage.addEventListener('pointermove', event => {
        if (!drag || event.pointerId !== drag.pointerId) return;
        panX = drag.panX + event.clientX - drag.x;
        panY = drag.panY + event.clientY - drag.y;
        renderTransform();
    });
    const endDrag = event => {
        if (!drag || event.pointerId !== drag.pointerId) return;
        drag = null;
        renderTransform();
    };
    stage.addEventListener('pointerup', endDrag);
    stage.addEventListener('pointercancel', endDrag);
    image.addEventListener('load', () => {
        setPreviewLoading(false);
        resetTransform();
    });
    image.addEventListener('error', () => {
        showPreviewLoadError();
    });
    loadPreviewImage(url, label);
    document.body.append(overlay);
    lucide.createIcons({ nodes: [overlay] });
    renderTransform();
    updatePreviewNavigation();
    document.addEventListener('keydown', onKeydown);
}

const galleryPreviewScroller = document.querySelector('#gallery table')?.closest('.overflow-x-auto.custom-scrollbar');
const galleryPreviewCandidates = [];
let ignoreGalleryPreviewHighlightClear = false;
const visibleGalleryPreviewCandidates = () => galleryPreviewCandidates.filter(preview => !preview.closest('tr')?.hidden);
document.addEventListener('click', event => {
    if (ignoreGalleryPreviewHighlightClear) {
        ignoreGalleryPreviewHighlightClear = false;
        return;
    }
    const target = event.target;
    if (!(target instanceof Element) || target.closest('#gallery tbody tr, #gallery button, #gallery a, #gallery input, #gallery select, #gallery form')) return;
    document.querySelectorAll('#gallery tbody tr.picapi-gallery-previewed').forEach(row => row.classList.remove('picapi-gallery-previewed'));
});
document.querySelectorAll('#gallery tbody img[data-preview-src]').forEach(preview => {
    const source = new URL(preview.dataset.previewSrc, window.location.href);
    if (window.location.protocol === 'https:' && source.protocol === 'http:' && source.host === window.location.host) {
        source.protocol = 'https:';
    }
    const matched = source.pathname.match(/\/(pc|m)\/img\/(.+)$/);
    if (!matched) return;
    const device = matched[1] === 'pc' ? 'pc' : 'mobile';
    const relative = decodeURIComponent(matched[2]);
    const thumbnailUrl = `thumbnail.php?device=${encodeURIComponent(device)}&relative=${encodeURIComponent(relative)}`;
    preview.dataset.fullImage = source.toString();
    preview.dataset.previewLabel = relative;
    const thumbnailBox = preview.closest('.picapi-gallery-thumb');
    preview.galleryPreviewTarget = thumbnailBox || preview;
    const setThumbnailState = state => {
        thumbnailBox?.classList.remove('is-loading', 'is-loaded', 'is-error');
        thumbnailBox?.classList.add(`is-${state}`);
        preview.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');
    };
    if (!window.picapiDirectGalleryPreview) {
        preview.loadGalleryThumbnail = () => {
            if (preview.dataset.previewRequested) return;
            preview.dataset.previewRequested = '1';
            setThumbnailState('loading');
            preview.src = thumbnailUrl;
        };
    }
    preview.classList.add('cursor-zoom-in');
    if (!window.picapiDirectGalleryPreview) {
        preview.addEventListener('load', () => setThumbnailState('loaded'));
        preview.addEventListener('error', () => {
            if (!preview.dataset.thumbnailAttempted) {
                preview.dataset.thumbnailAttempted = '1';
                const csrf = preview.closest('tr')?.querySelector('input[name="csrf"]')?.value || '';
                queueMissingThumbnail(preview, { device, relative }, csrf, thumbnailUrl);
                return;
            }
            if (!preview.dataset.fallbackLoaded) {
                preview.dataset.fallbackLoaded = '1';
                preview.src = preview.dataset.fullImage;
                return;
            }
            setThumbnailState('error');
        });
    }
    preview.addEventListener('click', () => openFullImagePreview(preview.dataset.fullImage, relative, visibleGalleryPreviewCandidates()));
    preview.setAttribute('role', 'button');
    preview.tabIndex = 0;
    preview.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') openFullImagePreview(preview.dataset.fullImage, relative, visibleGalleryPreviewCandidates());
    });
    galleryPreviewCandidates.push(preview);
});

if (!window.picapiDirectGalleryPreview && galleryPreviewScroller && 'IntersectionObserver' in window) {
    const galleryThumbnailObserver = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) return;
            const preview = entry.target.__picapiPreview || entry.target;
            if (preview.closest('tr')?.hidden || preview.dataset.previewRequested) return;
            preview.loadGalleryThumbnail?.();
            galleryThumbnailObserver.unobserve(entry.target);
        });
    }, { root: galleryPreviewScroller, threshold: .01 });
    galleryPreviewCandidates.forEach(preview => {
        const target = preview.galleryPreviewTarget || preview;
        target.__picapiPreview = preview;
        galleryThumbnailObserver.observe(target);
    });
}

let galleryLazyLoadFrame = 0;
const loadVisibleGalleryThumbnails = () => {
    galleryLazyLoadFrame = 0;
    if (!galleryPreviewScroller) return;
    const viewport = galleryPreviewScroller.getBoundingClientRect();
    galleryPreviewCandidates.forEach(preview => {
        const row = preview.closest('tr');
        if (preview.dataset.previewRequested || preview.getAttribute('src') || row?.hidden) return;
        const box = preview.closest('.picapi-gallery-thumb')?.getBoundingClientRect();
        const isVisibleByBounds = Boolean(box && box.bottom > viewport.top && box.top < viewport.bottom && box.right > viewport.left && box.left < viewport.right);
        if (!isVisibleByBounds) return;
        preview.loadGalleryThumbnail?.();
    });
};
const scheduleVisibleGalleryThumbnails = () => {
    if (!galleryLazyLoadFrame) galleryLazyLoadFrame = requestAnimationFrame(loadVisibleGalleryThumbnails);
};
if (!window.picapiDirectGalleryPreview && galleryPreviewScroller) {
    galleryPreviewScroller.addEventListener('scroll', scheduleVisibleGalleryThumbnails, { passive: true });
    window.addEventListener('resize', scheduleVisibleGalleryThumbnails, { passive: true });
    const galleryRows = document.querySelector('#gallery tbody');
    if (galleryRows && 'MutationObserver' in window) {
        new MutationObserver(scheduleVisibleGalleryThumbnails).observe(galleryRows, { subtree: true, attributes: true, attributeFilter: ['hidden', 'style', 'class'] });
    }
    loadVisibleGalleryThumbnails();
    scheduleVisibleGalleryThumbnails();
}

const notificationHost = document.createElement('div');
notificationHost.className = 'fixed right-4 top-4 z-[90] flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-3';
document.body.append(notificationHost);
window.picapiToast = (message, type = 'success') => {
    const toast = document.createElement('div');
    toast.className = `picapi-theme-notice ${type === 'error' ? 'picapi-theme-notice--error' : ''} flex items-start gap-3 rounded-xl border px-4 py-3 text-sm shadow-2xl transition duration-300`;
    toast.innerHTML = `<i data-lucide="${type === 'error' ? 'circle-x' : 'circle-check'}" class="picapi-theme-notice-icon mt-0.5 h-4 w-4 shrink-0"></i><p class="flex-1 leading-5"></p><button type="button" class="text-current/70 hover:text-current"><i data-lucide="x" class="h-4 w-4"></i></button>`;
    toast.querySelector('p').textContent = message;
    toast.querySelector('button').addEventListener('click', () => toast.remove());
    notificationHost.append(toast);
    lucide.createIcons({ nodes: [toast] });
    window.setTimeout(() => { toast.classList.add('translate-x-8', 'opacity-0'); window.setTimeout(() => toast.remove(), 300); }, 4500);
};
window.picapiConfirm = (title, message, onConfirm) => {
    const dialog = document.createElement('div');
    dialog.className = 'fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/75 p-4 backdrop-blur-sm';
    dialog.innerHTML = '<div role="dialog" aria-modal="true" class="w-full max-w-sm rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-2xl"><div class="flex items-start gap-3"><div class="rounded-lg bg-rose-500/10 p-2 text-rose-400"><i data-lucide="triangle-alert" class="h-5 w-5"></i></div><div><h3 class="text-sm font-semibold text-white"></h3><p class="mt-1.5 text-sm leading-6 text-slate-400"></p></div></div><div class="mt-6 flex justify-end gap-3"><button type="button" data-cancel class="rounded-lg bg-slate-800 px-3 py-2 text-xs font-medium text-slate-300 hover:bg-slate-700">取消</button><button type="button" data-confirm class="rounded-lg bg-rose-600 px-3 py-2 text-xs font-medium text-white hover:bg-rose-500">确认删除</button></div></div>';
    dialog.querySelector('h3').textContent = title;
    dialog.querySelector('p').textContent = message;
    const close = () => dialog.remove();
    dialog.querySelector('[data-cancel]').addEventListener('click', close);
    dialog.addEventListener('click', event => { if (event.target === dialog) close(); });
    dialog.querySelector('[data-confirm]').addEventListener('click', () => { close(); onConfirm(); });
    document.body.append(dialog);
    lucide.createIcons({ nodes: [dialog] });
};
window.picapiResolveUploadConflict = (filename, serverImageUrl = '', uploadFile = null) => new Promise(resolve => {
    const dialog = document.createElement('div');
    dialog.className = 'fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/75 p-4 backdrop-blur-sm';
    dialog.innerHTML = '<div role="dialog" aria-modal="true" class="w-full max-w-md rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-2xl"><div class="flex items-start gap-3"><div class="rounded-lg bg-amber-500/10 p-2 text-amber-400"><i data-lucide="files" class="h-5 w-5"></i></div><div><h3 class="text-sm font-semibold text-white">发现同名图片</h3><p class="mt-1.5 text-sm leading-6 text-slate-400">目标分类中已存在 <strong class="font-mono text-slate-200"></strong>，请选择如何处理。</p></div></div><div class="mt-5 hidden" data-manual-field><label class="mb-1.5 block text-xs text-slate-400">新的文件名</label><input data-filename class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white outline-none focus:border-indigo-500" placeholder="例如 wallpaper-2.png"></div><div class="mt-6 flex flex-wrap justify-end gap-2"><button type="button" data-cancel class="rounded-lg bg-slate-800 px-3 py-2 text-xs font-medium text-slate-300 hover:bg-slate-700">取消上传</button><button type="button" data-skip class="rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-xs font-medium text-slate-200 hover:border-slate-500">保留服务器图片</button><button type="button" data-append class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-medium text-white hover:bg-indigo-500">全部保留（自动加 1）</button><button type="button" data-manual class="rounded-lg bg-amber-600 px-3 py-2 text-xs font-medium text-white hover:bg-amber-500">手动改名</button></div></div>';
    dialog.querySelector('strong').textContent = filename;
    const comparison = document.createElement('div');
    comparison.className = 'mt-5 grid grid-cols-2 gap-3';
    comparison.innerHTML = '<figure class="overflow-hidden rounded-xl border border-slate-700 bg-slate-950"><figcaption class="border-b border-slate-800 px-3 py-2 text-xs font-medium text-slate-300">服务器已有图片</figcaption><img data-server-image class="h-32 w-full object-cover" alt="服务器图片"></figure><figure class="overflow-hidden rounded-xl border border-indigo-500/50 bg-slate-950"><figcaption class="border-b border-slate-800 px-3 py-2 text-xs font-medium text-indigo-300">本次上传图片</figcaption><img data-upload-image class="h-32 w-full object-cover" alt="上传图片"></figure>';
    const manualField = dialog.querySelector('[data-manual-field]');
    manualField.before(comparison);
    const uploadUrl = uploadFile ? URL.createObjectURL(uploadFile) : '';
    comparison.querySelector('[data-server-image]').src = serverImageUrl || '';
    comparison.querySelector('[data-upload-image]').src = uploadUrl;
    const close = value => { if (uploadUrl) URL.revokeObjectURL(uploadUrl); dialog.remove(); resolve(value); };
    dialog.querySelector('[data-cancel]').addEventListener('click', () => close(null));
    dialog.querySelector('[data-skip]').addEventListener('click', () => close({ action: 'skip' }));
    dialog.querySelector('[data-append]').addEventListener('click', () => close({ action: 'append' }));
    const skipCurrent = document.createElement('button');
    skipCurrent.type = 'button';
    skipCurrent.className = 'rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-xs font-medium text-slate-300 hover:border-slate-500 hover:text-white';
    skipCurrent.textContent = '跳过本次图片';
    dialog.querySelector('[data-skip]').before(skipCurrent);
    skipCurrent.addEventListener('click', () => close({ action: 'skip' }));
    dialog.querySelector('[data-manual]').addEventListener('click', () => {
        const field = dialog.querySelector('[data-manual-field]');
        const input = dialog.querySelector('[data-filename]');
        if (field.classList.contains('hidden')) { field.classList.remove('hidden'); input.value = filename; input.focus(); return; }
        if (input.value.trim()) close({ action: 'rename', filename: input.value.trim() });
    });
    document.body.append(dialog);
    lucide.createIcons({ nodes: [dialog] });
});
window.picapiInputDialog = (title, label, initialValue) => new Promise(resolve => {
    const dialog = document.createElement('div');
    dialog.className = 'fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/75 p-4 backdrop-blur-sm';
    dialog.innerHTML = '<div role="dialog" aria-modal="true" class="w-full max-w-sm rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-2xl"><h3 class="text-sm font-semibold text-white"></h3><label class="mt-4 block text-xs text-slate-400"></label><input class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white outline-none focus:border-indigo-500"><div class="mt-6 flex justify-end gap-3"><button type="button" data-cancel class="rounded-lg bg-slate-800 px-3 py-2 text-xs text-slate-300">取消</button><button type="button" data-confirm class="rounded-lg bg-indigo-600 px-3 py-2 text-xs text-white">确认</button></div></div>';
    const input = dialog.querySelector('input');
    dialog.querySelector('h3').textContent = title;
    dialog.querySelector('label').textContent = label;
    input.value = initialValue;
    const close = value => { dialog.remove(); resolve(value); };
    dialog.querySelector('[data-cancel]').addEventListener('click', () => close(null));
    dialog.querySelector('[data-confirm]').addEventListener('click', () => close(input.value.trim() || null));
    input.addEventListener('keydown', event => { if (event.key === 'Enter') close(input.value.trim() || null); });
    document.body.append(dialog); input.focus(); input.select();
});
const picapiEscapeHtml = value => String(value).replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[character]);
window.picapiCategoryDialog = current => new Promise(resolve => {
    const dialog = document.createElement('div');
    dialog.className = 'fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/75 p-4 backdrop-blur-sm';
    const options = categoryAliases.map(alias => `<option value="${picapiEscapeHtml(alias)}">${picapiEscapeHtml(categoryDisplayNames[alias] || alias)} (${picapiEscapeHtml(alias)})</option>`).join('');
    dialog.innerHTML = `<div role="dialog" aria-modal="true" class="w-full max-w-sm rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-2xl"><h3 class="text-sm font-semibold text-white">移动图片</h3><label class="mt-4 block text-xs text-slate-400">目标分类</label><select class="mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-white outline-none focus:border-indigo-500">${options}</select><div class="mt-6 flex justify-end gap-3"><button type="button" data-cancel class="rounded-lg bg-slate-800 px-3 py-2 text-xs text-slate-300">取消</button><button type="button" data-confirm class="rounded-lg bg-indigo-600 px-3 py-2 text-xs text-white">移动</button></div></div>`;
    const select = dialog.querySelector('select'); select.value = current;
    const close = value => { dialog.remove(); resolve(value); };
    dialog.querySelector('[data-cancel]').addEventListener('click', () => close(null));
    dialog.querySelector('[data-confirm]').addEventListener('click', () => close(select.value));
    document.body.append(dialog);
});
document.querySelectorAll('.notice').forEach(notice => {
    window.picapiToast(notice.textContent.trim(), <?= json_encode($noticeType) ?>);
    notice.remove();
});
document.querySelectorAll('#gallery form[onsubmit], #folders form[onsubmit]').forEach(form => form.removeAttribute('onsubmit'));
document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('#gallery form, #folders form')) return;
    const action = form.querySelector('input[name="action"]')?.value;
    if (!['delete_image', 'delete_category'].includes(action)) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const message = action === 'delete_category' ? '确认删除这个空分类文件夹吗？' : '确认永久删除这张图片吗？';
    window.picapiConfirm('删除确认', message, () => HTMLFormElement.prototype.submit.call(form));
}, true);
function submitGalleryImageAction(row, action, fields = {}) {
    const device = row.querySelector('input[name="device"]')?.value;
    const relative = row.querySelector('input[name="relative"]')?.value;
    const csrf = row.querySelector('input[name="csrf"]')?.value;
    if (!device || !relative || !csrf) return;
    const form = document.createElement('form');
    form.method = 'post'; form.action = 'action.php';
    Object.entries({ csrf, action, device, relative, ...fields }).forEach(([name, value]) => {
        const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.append(input);
    });
    document.body.append(form); form.submit();
}
let galleryContextMenu = null;
function closeGalleryContextMenu() { galleryContextMenu?.remove(); galleryContextMenu = null; }
document.querySelectorAll('#gallery tbody tr').forEach(row => row.addEventListener('contextmenu', event => {
    const relative = row.querySelector('input[name="relative"]')?.value;
    if (!relative) return;
    event.preventDefault();
    closeGalleryContextMenu();
    const menu = document.createElement('div');
    menu.className = 'fixed z-[95] min-w-36 rounded-xl border border-slate-700 bg-slate-900 p-1.5 text-xs shadow-2xl';
    menu.style.left = `${Math.min(event.clientX, window.innerWidth - 170)}px`;
    menu.style.top = `${Math.min(event.clientY, window.innerHeight - 150)}px`;
    menu.innerHTML = '<button type="button" data-rename class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-slate-200 hover:bg-slate-800"><i data-lucide="pencil" class="h-3.5 w-3.5"></i>重命名</button><button type="button" data-move class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-slate-200 hover:bg-slate-800"><i data-lucide="folder-input" class="h-3.5 w-3.5"></i>移动到分类</button><div class="my-1 border-t border-slate-800"></div><button type="button" data-delete class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-rose-300 hover:bg-rose-500/10"><i data-lucide="trash-2" class="h-3.5 w-3.5"></i>删除图片</button>';
    document.body.append(menu); galleryContextMenu = menu; lucide.createIcons({ nodes: [menu] });
    const currentCategory = relative.includes('/') ? relative.split('/')[0] : 'default';
    menu.querySelector('[data-rename]').addEventListener('click', async () => {
        closeGalleryContextMenu();
        const filename = await window.picapiInputDialog('重命名图片', '新文件名（扩展名不可更改）', relative.split('/').pop());
        if (filename) submitGalleryImageAction(row, 'rename_image', { filename });
    });
    menu.querySelector('[data-move]').addEventListener('click', async () => {
        closeGalleryContextMenu();
        const category = await window.picapiCategoryDialog(currentCategory);
        if (category && category !== currentCategory) submitGalleryImageAction(row, 'move_image', { category });
    });
    menu.querySelector('[data-delete]').addEventListener('click', () => {
        closeGalleryContextMenu();
        window.picapiConfirm('删除图片', '确认永久删除这张图片吗？', () => submitGalleryImageAction(row, 'delete_image'));
    });
}));
document.addEventListener('click', closeGalleryContextMenu);
window.addEventListener('scroll', closeGalleryContextMenu, true);

const gallerySelectionRows = Array.from(document.querySelectorAll('#gallery tbody tr')).filter(row => row.children.length > 1);
const gallerySelectAll = document.querySelector('#gallery thead input[type="checkbox"]');
const galleryTopBar = document.querySelector('#gallery .relative');
if (gallerySelectAll && galleryTopBar && gallerySelectionRows.length) {
    gallerySelectAll.disabled = false;
    gallerySelectAll.classList.remove('cursor-not-allowed');
    gallerySelectAll.classList.add('cursor-pointer');
    const batchBar = document.createElement('div');
    batchBar.className = 'hidden absolute inset-0 z-10 rounded-t-xl border-2 border-amber-700/60 bg-slate-900 px-6 py-3 shadow-sm flex items-center justify-between text-xs';
    batchBar.innerHTML = '<div class="flex items-center gap-2 text-amber-500 font-medium"><i data-lucide="alert-circle" class="w-4 h-4"></i><span>已选中 <strong class="font-bold font-mono text-amber-400 text-sm">0</strong> 项资源</span></div><div class="flex items-center gap-3"><button type="button" data-bulk-delete class="bg-rose-600 hover:bg-rose-700 text-white px-3 py-1.5 rounded-md font-medium transition">批量删除</button><button type="button" data-clear-selection class="text-slate-400 hover:text-slate-200 px-1 py-1">取消</button></div>';
    galleryTopBar.append(batchBar);
    const rowCheckboxes = gallerySelectionRows.map(row => row.querySelector('td:first-child input[type="checkbox"]')).filter(Boolean);
    rowCheckboxes.forEach(checkbox => {
        checkbox.disabled = false;
        checkbox.classList.remove('cursor-not-allowed');
        checkbox.classList.add('cursor-pointer');
    });
    const visibleCheckboxes = () => gallerySelectionRows
        .filter(row => !row.hidden)
        .map(row => row.querySelector('td:first-child input[type="checkbox"]'))
        .filter(Boolean);
    const updateBatchBar = () => {
        const selected = rowCheckboxes.filter(checkbox => checkbox.checked);
        batchBar.classList.toggle('hidden', selected.length === 0);
        batchBar.querySelector('strong').textContent = String(selected.length);
        const visible = visibleCheckboxes();
        gallerySelectAll.checked = visible.length > 0 && visible.every(checkbox => checkbox.checked);
        gallerySelectAll.indeterminate = !gallerySelectAll.checked && visible.some(checkbox => checkbox.checked);
    };
    gallerySelectAll.addEventListener('change', () => {
        visibleCheckboxes().forEach(checkbox => { checkbox.checked = gallerySelectAll.checked; });
        updateBatchBar();
    });
    rowCheckboxes.forEach(checkbox => checkbox.addEventListener('change', updateBatchBar));
    batchBar.querySelector('[data-clear-selection]').addEventListener('click', () => {
        rowCheckboxes.forEach(checkbox => { checkbox.checked = false; });
        updateBatchBar();
    });
    batchBar.querySelector('[data-bulk-delete]').addEventListener('click', () => {
        const selectedRows = gallerySelectionRows.filter(row => row.querySelector('td:first-child input[type="checkbox"]')?.checked);
        if (!selectedRows.length) return;
        window.picapiConfirm('批量删除图片', `确认永久删除选中的 ${selectedRows.length} 张图片吗？`, () => {
            const form = document.createElement('form');
            form.method = 'post';
            form.action = 'action.php';
            const csrf = selectedRows[0].querySelector('input[name="csrf"]')?.value || '';
            [['csrf', csrf], ['action', 'bulk_delete']].forEach(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = name; input.value = value; form.append(input);
            });
            selectedRows.forEach(row => {
                const device = row.querySelector('input[name="device"]')?.value;
                const relative = row.querySelector('input[name="relative"]')?.value;
                if (!device || !relative) return;
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = 'items[]'; input.value = `${device}|${relative}`; form.append(input);
            });
            document.body.append(form);
            form.submit();
        });
    });
    updateBatchBar();
}

const auditRecords = <?= json_encode($auditRecords, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const auditTitle = Array.from(document.querySelectorAll('#logs h3')).find(element => element.textContent.trim() === '上传审计记录');
const auditHeader = auditTitle?.closest('.p-4');
const auditBody = auditHeader?.parentElement?.querySelector('tbody');
if (auditHeader && auditBody) {
    auditBody.replaceChildren();
    if (!auditRecords.length) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 5;
        cell.className = 'p-8 text-center text-slate-600 font-sans';
        cell.textContent = '无已提交的上传审计事务。';
        row.append(cell);
        auditBody.append(row);
    } else {
        auditRecords.forEach(record => {
            const row = document.createElement('tr');
            row.className = 'hover:bg-slate-800/30 transition';
            const cells = [
                ['p-3 text-slate-500 font-sans', record.time],
                ['p-3 text-slate-200', record.name],
                ['p-3', record.category],
                ['p-3', record.dimensions],
                ['p-3 text-indigo-400', record.size],
            ];
            cells.forEach(([className, value]) => {
                const cell = document.createElement('td');
                cell.className = className;
                cell.textContent = value;
                row.append(cell);
            });
            auditBody.append(row);
        });
    }
    const auditRows = Array.from(auditBody.querySelectorAll('tr')).filter(row => row.children.length > 1);
    setupPager(auditRows, auditHeader, {
        pageKey: 'audit_page',
        sizeKey: 'audit_per_page',
        unit: '行',
        perPageLabel: '每页',
        preserveHeading: true,
        headerClass: 'p-4 border-b border-slate-800 bg-slate-900/50 flex flex-col md:flex-row md:items-center justify-between gap-4',
    });
}

const accessLogRows = Array.from(document.querySelectorAll('#logs .shadow-xl tbody tr')).filter(row => row.children.length > 1);
setupPager(accessLogRows, document.querySelector('#logs .shadow-xl > div:first-child'), {
    pageKey: 'logs_page',
    sizeKey: 'logs_per_page',
    unit: '条',
    preserveHeading: true,
    headerClass: 'p-4 border-b border-slate-800 bg-slate-900/50 flex flex-col md:flex-row md:items-center justify-between gap-4 text-xs text-slate-400',
});
const exportLogLink = document.querySelector('#logs a[href="export_logs.php"]');
if (exportLogLink) {
    const availableExportLogDates = new Set(<?= json_encode($availableLogDates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
    const exportDialog = document.createElement('div');
    exportDialog.className = 'fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/75 p-4 backdrop-blur-sm';
    exportDialog.innerHTML = `<div role="dialog" aria-modal="true" aria-labelledby="export-logs-title" class="max-h-[calc(100vh-2rem)] w-full max-w-3xl overflow-y-auto rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl custom-scrollbar"><div class="flex items-start justify-between border-b border-slate-800 p-5"><div><h3 id="export-logs-title" class="text-base font-semibold text-white">导出系统日志</h3><p class="mt-1 text-xs text-slate-400">选择导出内容与日期范围，亮色日期表示当天有访问日志。</p></div><button type="button" data-export-close class="rounded-lg p-1 text-slate-400 hover:bg-slate-800 hover:text-white" aria-label="关闭"><i data-lucide="x" class="h-5 w-5"></i></button></div><div class="grid gap-6 p-5 md:grid-cols-[1.2fr_.8fr]"><div class="space-y-5"><div><label class="mb-1.5 block text-xs font-medium text-slate-300">导出内容</label><select data-export-section class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-indigo-500"><option value="access">最近访问流水</option><option value="uploads">上传审计记录</option><option value="metrics">每日吞吐指标</option></select></div><div><div class="mb-1.5 flex items-center justify-between"><label class="text-xs font-medium text-slate-300">日期范围</label><button type="button" data-export-today class="text-xs font-medium text-indigo-400 hover:text-indigo-300">导出当天</button></div><div class="grid grid-cols-2 gap-3"><div><label class="mb-1 block text-[11px] text-slate-500">开始日期</label><input data-export-start type="date" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-500"></div><div><label class="mb-1 block text-[11px] text-slate-500">结束日期</label><input data-export-end type="date" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-indigo-500"></div></div><p class="mt-1.5 text-[11px] text-slate-500">留空表示导出全部日期；点击日历可连续选择开始与结束日期。</p></div><div><p class="mb-2 text-xs font-medium text-slate-300">导出格式</p><div class="grid grid-cols-2 gap-3"><label class="cursor-pointer rounded-lg border border-indigo-500/50 bg-indigo-500/10 p-3 text-sm text-indigo-100"><input class="mr-2 accent-indigo-500" type="radio" name="export-format" value="csv" checked>CSV 表格</label><label class="cursor-pointer rounded-lg border border-slate-700 bg-slate-950 p-3 text-sm text-slate-300"><input class="mr-2 accent-indigo-500" type="radio" name="export-format" value="txt">TXT 文本</label></div></div></div><div class="rounded-xl border border-slate-800 bg-slate-950 p-4"><div class="mb-3 flex items-center justify-between"><button type="button" data-calendar-prev class="rounded p-1 text-slate-400 hover:bg-slate-800 hover:text-white" aria-label="上个月"><i data-lucide="chevron-left" class="h-4 w-4"></i></button><strong data-calendar-title class="text-sm text-white"></strong><button type="button" data-calendar-next class="rounded p-1 text-slate-400 hover:bg-slate-800 hover:text-white" aria-label="下个月"><i data-lucide="chevron-right" class="h-4 w-4"></i></button></div><div class="grid grid-cols-7 gap-1 text-center text-[10px] text-slate-500"><span>日</span><span>一</span><span>二</span><span>三</span><span>四</span><span>五</span><span>六</span></div><div data-export-calendar class="mt-2 grid grid-cols-7 gap-1"></div><div class="mt-3 flex items-center gap-3 text-[10px]"><span class="flex items-center gap-1 text-slate-400"><i class="h-2 w-2 rounded-full bg-slate-300"></i>有日志</span><span class="flex items-center gap-1 text-slate-600"><i class="h-2 w-2 rounded-full bg-slate-700"></i>无日志</span></div></div></div><div class="flex justify-end gap-3 border-t border-slate-800 p-5"><button type="button" data-export-close class="rounded-lg bg-slate-800 px-3 py-2 text-xs font-medium text-slate-300 hover:bg-slate-700">取消</button><button type="button" data-export-submit class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-xs font-medium text-white hover:bg-indigo-500"><i data-lucide="download" class="h-4 w-4"></i>开始导出</button></div></div>`;
    document.body.append(exportDialog);
    const startInput = exportDialog.querySelector('[data-export-start]');
    const endInput = exportDialog.querySelector('[data-export-end]');
    const calendar = exportDialog.querySelector('[data-export-calendar]');
    const calendarTitle = exportDialog.querySelector('[data-calendar-title]');
    const exportSection = exportDialog.querySelector('[data-export-section]');
    const allContentOption = document.createElement('option');
    allContentOption.value = 'all';
    allContentOption.textContent = '导出所有内容';
    exportSection.prepend(allContentOption);
    const localDay = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    const now = new Date();
    let calendarYear = now.getFullYear();
    let calendarMonth = now.getMonth();
    const renderCalendar = () => {
        calendar.replaceChildren();
        calendarTitle.textContent = `${calendarYear} 年 ${calendarMonth + 1} 月`;
        const first = new Date(calendarYear, calendarMonth, 1).getDay();
        const days = new Date(calendarYear, calendarMonth + 1, 0).getDate();
        for (let blank = 0; blank < first; blank += 1) calendar.append(document.createElement('span'));
        for (let day = 1; day <= days; day += 1) {
            const date = `${calendarYear}-${String(calendarMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const button = document.createElement('button');
            const selected = date === startInput.value || date === endInput.value || (startInput.value && endInput.value && date > startInput.value && date < endInput.value);
            button.type = 'button'; button.textContent = String(day); button.dataset.date = date;
            button.className = `h-8 rounded-md text-xs transition ${selected ? 'bg-indigo-600 text-white' : (availableExportLogDates.has(date) ? 'bg-slate-800 text-slate-200 hover:bg-slate-700' : 'bg-slate-900 text-slate-600 hover:bg-slate-800')}`;
            button.title = availableExportLogDates.has(date) ? '当天有日志' : '当天无日志';
            button.addEventListener('click', () => {
                if (!startInput.value || (startInput.value && endInput.value)) { startInput.value = date; endInput.value = ''; }
                else if (date < startInput.value) { endInput.value = startInput.value; startInput.value = date; }
                else endInput.value = date;
                renderCalendar();
            });
            calendar.append(button);
        }
    };
    const openExportDialog = () => {
        const currentDate = new Date();
        calendarYear = currentDate.getFullYear();
        calendarMonth = currentDate.getMonth();
        exportDialog.classList.remove('hidden');
        exportDialog.classList.add('flex');
        renderCalendar();
        window.lucide?.createIcons({ nodes: [exportDialog] });
    };
    const closeExportDialog = () => { exportDialog.classList.add('hidden'); exportDialog.classList.remove('flex'); };
    exportLogLink.addEventListener('click', event => { event.preventDefault(); openExportDialog(); });
    exportDialog.querySelectorAll('[data-export-close]').forEach(button => button.addEventListener('click', closeExportDialog));
    exportDialog.addEventListener('click', event => { if (event.target === exportDialog) closeExportDialog(); });
    exportDialog.querySelector('[data-calendar-prev]').addEventListener('click', () => { if (--calendarMonth < 0) { calendarMonth = 11; calendarYear -= 1; } renderCalendar(); });
    exportDialog.querySelector('[data-calendar-next]').addEventListener('click', () => { if (++calendarMonth > 11) { calendarMonth = 0; calendarYear += 1; } renderCalendar(); });
    exportDialog.querySelector('[data-export-today]').addEventListener('click', () => { const today = localDay(new Date()); startInput.value = today; endInput.value = today; calendarYear = now.getFullYear(); calendarMonth = now.getMonth(); renderCalendar(); });
    [startInput, endInput].forEach(input => input.addEventListener('change', renderCalendar));
    exportDialog.querySelector('[data-export-submit]').addEventListener('click', () => {
        if (startInput.value && endInput.value && startInput.value > endInput.value) { const value = startInput.value; startInput.value = endInput.value; endInput.value = value; }
        const params = new URLSearchParams({ section: exportDialog.querySelector('[data-export-section]').value, format: exportDialog.querySelector('input[name="export-format"]:checked').value });
        if (startInput.value) params.set('start_date', startInput.value);
        if (endInput.value) params.set('end_date', endInput.value);
        window.location.assign(`export_logs.php?${params.toString()}`);
        closeExportDialog();
    });
}
const sidebar = document.querySelector('aside');
const sidebarHeader = sidebar?.querySelector('.h-16');
const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
if (!reduceMotion) requestAnimationFrame(() => document.body.classList.add('picapi-page-ready'));
let pageNavigationInProgress = false;
document.addEventListener('click', event => {
    const link = event.target.closest('aside nav a[href]');
    if (!link) return;
    if (pageNavigationInProgress || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target === '_blank' || link.hasAttribute('download')) return;
    const destination = new URL(link.href, window.location.href);
    if (destination.origin !== window.location.origin || (destination.pathname === window.location.pathname && destination.search === window.location.search)) return;
    if (reduceMotion) return;
    event.preventDefault();
    pageNavigationInProgress = true;
    document.body.classList.add('picapi-page-leaving');
    window.setTimeout(() => { window.location.assign(destination.href); }, 145);
});
if (sidebar && sidebarHeader) {
    const menuToggle = document.createElement('button');
    menuToggle.type = 'button';
    menuToggle.className = 'mobile-menu-toggle';
    menuToggle.setAttribute('aria-label', '展开导航菜单');
    menuToggle.setAttribute('aria-expanded', 'false');
    menuToggle.innerHTML = '<i data-lucide="menu" class="h-5 w-5"></i>';
    menuToggle.addEventListener('click', event => {
        event.stopPropagation();
        const isOpen = document.body.classList.toggle('mobile-menu-open');
        menuToggle.setAttribute('aria-expanded', String(isOpen));
    });
    document.addEventListener('click', event => {
        if (window.innerWidth < 768 && document.body.classList.contains('mobile-menu-open') && !sidebar.contains(event.target)) {
            document.body.classList.remove('mobile-menu-open');
            menuToggle.setAttribute('aria-expanded', 'false');
        }
    });
    sidebarHeader.append(menuToggle);
}
const adminNavigation = document.querySelector('aside nav');
if (adminNavigation && !adminNavigation.querySelector('[href="custom_api.php"]')) {
    const settingsNavigationLink = adminNavigation.querySelector('[href="settings.php"]');
    const customApiLink = document.createElement('a');
    customApiLink.href = 'custom_api.php';
    customApiLink.className = 'flex items-center gap-3 px-3 py-2.5 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-slate-200 font-medium text-sm transition';
    customApiLink.innerHTML = '<i data-lucide="sliders-horizontal" class="w-4 h-4"></i>API 自定义配置';
    adminNavigation.insertBefore(customApiLink, settingsNavigationLink);
    const docsLink = document.createElement('a');
    docsLink.href = 'custom_api.php?view=docs';
    docsLink.className = 'flex items-center gap-3 px-3 py-2.5 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-slate-200 font-medium text-sm transition';
    docsLink.innerHTML = '<i data-lucide="book-open-text" class="w-4 h-4"></i>API 文档编辑';
    adminNavigation.insertBefore(docsLink, settingsNavigationLink);
}
const navigationOrderStorageKey = 'picapi-admin-navigation-order';
const defaultAdminNavigationOrder = ['dashboard.php', 'upload.php', 'media.php', 'folders.php', 'ip_control.php', 'logs.php', 'custom_api.php', 'custom_api.php?view=docs'];
const applyAdminNavigationOrder = () => {
    if (!adminNavigation) return;
    const fixedSettingsLink = adminNavigation.querySelector('[href="settings.php"]');
    const navigationItems = Array.from(adminNavigation.querySelectorAll(':scope > a, :scope > button'));
    const navigationKey = item => item.dataset.navKey || item.getAttribute('href') || item.dataset.apiView || item.textContent.trim();
    const movableItems = navigationItems.filter(item => item !== fixedSettingsLink);
    const applyOrder = order => {
        const map = new Map(movableItems.map(item => [navigationKey(item), item]));
        order.forEach(key => { const item = map.get(key); if (item) adminNavigation.insertBefore(item, fixedSettingsLink); });
    };
    applyOrder(defaultAdminNavigationOrder);
    try {
        const savedOrder = JSON.parse(localStorage.getItem(navigationOrderStorageKey) || '[]');
        if (Array.isArray(savedOrder)) applyOrder(savedOrder);
    } catch (_) {}
    if (fixedSettingsLink) adminNavigation.append(fixedSettingsLink);
};
applyAdminNavigationOrder();
const settingsPanel = document.querySelector('#settings');
const settingsForm = settingsPanel?.querySelector('[data-settings-appearance-form]');
if (settingsForm && settingsPanel) {
    const settingsScope = settingsPanel;
    const csrf = settingsForm.querySelector('[name="csrf"]')?.value || '';
    const settingsToast = document.createElement('div');
    settingsToast.className = 'pointer-events-none fixed right-4 top-4 z-[110] hidden max-w-sm rounded-xl border px-4 py-3 text-sm shadow-2xl';
    document.body.append(settingsToast);
    let settingsToastTimer;
    const showSettingsToast = (ok, message) => {
        clearTimeout(settingsToastTimer);
        settingsToast.textContent = message;
        settingsToast.className = `picapi-theme-notice ${ok ? '' : 'picapi-theme-notice--error'} pointer-events-none fixed right-4 top-4 z-[110] max-w-sm rounded-xl border px-4 py-3 text-sm shadow-2xl`;
        settingsToastTimer = window.setTimeout(() => settingsToast.classList.add('hidden'), 2600);
    };
    const navigationSortList = settingsScope.querySelector('[data-navigation-sort-list]');
    if (navigationSortList) {
        const defaultNavigationOrder = Array.from(navigationSortList.querySelectorAll('[data-navigation-key]')).map(item => item.dataset.navigationKey);
        const sortItems = () => Array.from(navigationSortList.querySelectorAll('[data-navigation-key]'));
        const renderNavigationSortOrder = order => {
            const map = new Map(sortItems().map(item => [item.dataset.navigationKey, item]));
            order.forEach(key => { const item = map.get(key); if (item) navigationSortList.append(item); });
        };
        try {
            const savedOrder = JSON.parse(localStorage.getItem(navigationOrderStorageKey) || '[]');
            if (Array.isArray(savedOrder)) renderNavigationSortOrder(savedOrder);
        } catch (_) {}
        const saveNavigationOrder = () => {
            try { localStorage.setItem(navigationOrderStorageKey, JSON.stringify(sortItems().map(item => item.dataset.navigationKey))); } catch (_) {}
            applyAdminNavigationOrder();
        };
        let draggedSortItem = null;
        sortItems().forEach(item => {
            item.addEventListener('dragstart', event => { draggedSortItem = item; item.classList.add('opacity-50'); event.dataTransfer.effectAllowed = 'move'; });
            item.addEventListener('dragend', () => { draggedSortItem = null; item.classList.remove('opacity-50'); });
        });
        navigationSortList.addEventListener('dragover', event => {
            if (!draggedSortItem) return;
            event.preventDefault();
            const target = event.target.closest('[data-navigation-key]');
            if (!target || target.parentElement !== navigationSortList || target === draggedSortItem) return;
            const bounds = target.getBoundingClientRect();
            navigationSortList.insertBefore(draggedSortItem, event.clientY < bounds.top + bounds.height / 2 ? target : target.nextSibling);
        });
        navigationSortList.addEventListener('drop', () => { saveNavigationOrder(); showSettingsToast(true, '菜单顺序已更新。'); });
        settingsScope.querySelector('[data-navigation-reset]')?.addEventListener('click', () => {
            try { localStorage.removeItem(navigationOrderStorageKey); } catch (_) {}
            renderNavigationSortOrder(defaultNavigationOrder);
            applyAdminNavigationOrder();
            showSettingsToast(true, '已恢复默认菜单顺序。');
        });
    }
    const valueOf = name => {
        const inputs = Array.from(settingsScope.querySelectorAll(`[name="${name}"]`));
        return inputs.find(input => input.type !== 'radio' || input.checked) || inputs[0] || null;
    };
    const saveInstant = async names => {
        const data = new FormData();
        data.append('csrf', csrf);
        names.forEach(name => {
            const input = valueOf(name);
            if (!input) return;
            data.append(name, input.type === 'checkbox' ? (input.checked ? '1' : '') : input.value);
        });
        try {
            const response = await fetch('settings_preferences_save.php', { method: 'POST', body: data, credentials: 'same-origin' });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || '设置保存失败。');
            showSettingsToast(true, payload.message || '设置已即时应用。');
        } catch (error) {
            showSettingsToast(false, error.message || '设置保存失败。');
        }
    };
    const accessLogCleanupConfig = <?= json_encode([
        'enabled' => !empty($siteSettings['access_log_auto_cleanup']),
        'months' => (int) $siteSettings['access_log_retention_months'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const accessLogCleanupPanel = document.createElement('section');
    accessLogCleanupPanel.className = 'rounded-lg border border-slate-800 bg-slate-950 p-4 space-y-3';
    const accessLogPeriods = [[1, '1 个月'], [3, '3 个月'], [6, '6 个月'], [12, '1 年'], [24, '2 年'], [36, '3 年'], [60, '5 年']];
    accessLogCleanupPanel.innerHTML = `<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><label class="flex cursor-pointer items-start gap-3"><input name="access_log_auto_cleanup" type="checkbox" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-600 bg-slate-800 text-indigo-600"><span><strong class="block text-sm font-medium text-slate-200">访问日志自动清理</strong><span class="mt-1 block text-xs leading-relaxed text-slate-500">仅清理到期的访问流水，不清空今日、昨日、累计访问次数或接口计数文件。</span></span></label><label class="block shrink-0 text-xs text-slate-500">保留时间<select name="access_log_retention_months" class="mt-1 w-full rounded-md border border-slate-700 bg-slate-900 px-2 py-1.5 text-xs text-slate-200">${accessLogPeriods.map(([value, label]) => `<option value="${value}">${label}</option>`).join('')}</select></label></div>`;
    const cleanupAnchor = valueOf('request_rate_alert')?.closest('div[class*="grid"]');
    if (cleanupAnchor) cleanupAnchor.after(accessLogCleanupPanel);
    else settingsForm.append(accessLogCleanupPanel);
    const accessLogCleanupEnabled = accessLogCleanupPanel.querySelector('[name="access_log_auto_cleanup"]');
    const accessLogRetentionMonths = accessLogCleanupPanel.querySelector('[name="access_log_retention_months"]');
    if (accessLogCleanupEnabled && accessLogRetentionMonths) {
        accessLogCleanupEnabled.checked = Boolean(accessLogCleanupConfig.enabled);
        accessLogRetentionMonths.value = String(accessLogCleanupConfig.months || 12);
        const syncAccessLogCleanup = () => {
            accessLogRetentionMonths.disabled = !accessLogCleanupEnabled.checked;
            accessLogCleanupPanel.classList.toggle('opacity-60', !accessLogCleanupEnabled.checked);
        };
        accessLogCleanupEnabled.addEventListener('change', () => {
            syncAccessLogCleanup();
            saveInstant(['access_log_auto_cleanup', 'access_log_retention_months']);
        });
        accessLogRetentionMonths.addEventListener('change', () => saveInstant(['access_log_auto_cleanup', 'access_log_retention_months']));
        syncAccessLogCleanup();
    }
    const uploadLogCleanupConfig = <?= json_encode([
        'enabled' => !empty($siteSettings['upload_log_auto_cleanup']),
        'months' => (int) $siteSettings['upload_log_retention_months'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const uploadLogCleanupPanel = document.createElement('section');
    uploadLogCleanupPanel.className = 'rounded-lg border border-slate-800 bg-slate-950 p-4 space-y-3';
    uploadLogCleanupPanel.innerHTML = `<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><label class="flex cursor-pointer items-start gap-3"><input name="upload_log_auto_cleanup" type="checkbox" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-600 bg-slate-800 text-indigo-600"><span><strong class="block text-sm font-medium text-slate-200">上传审计自动清理</strong><span class="mt-1 block text-xs leading-relaxed text-slate-500">仅清理到期的上传审计记录，原图、缩略图和图片分类不会受到影响。</span></span></label><label class="block shrink-0 text-xs text-slate-500">保留时间<select name="upload_log_retention_months" class="mt-1 w-full rounded-md border border-slate-700 bg-slate-900 px-2 py-1.5 text-xs text-slate-200">${accessLogPeriods.map(([value, label]) => `<option value="${value}">${label}</option>`).join('')}</select></label></div>`;
    accessLogCleanupPanel.after(uploadLogCleanupPanel);
    const uploadLogCleanupEnabled = uploadLogCleanupPanel.querySelector('[name="upload_log_auto_cleanup"]');
    const uploadLogRetentionMonths = uploadLogCleanupPanel.querySelector('[name="upload_log_retention_months"]');
    if (uploadLogCleanupEnabled && uploadLogRetentionMonths) {
        uploadLogCleanupEnabled.checked = Boolean(uploadLogCleanupConfig.enabled);
        uploadLogRetentionMonths.value = String(uploadLogCleanupConfig.months || 12);
        const syncUploadLogCleanup = () => {
            uploadLogRetentionMonths.disabled = !uploadLogCleanupEnabled.checked;
            uploadLogCleanupPanel.classList.toggle('opacity-60', !uploadLogCleanupEnabled.checked);
        };
        uploadLogCleanupEnabled.addEventListener('change', () => {
            syncUploadLogCleanup();
            saveInstant(['upload_log_auto_cleanup', 'upload_log_retention_months']);
        });
        uploadLogRetentionMonths.addEventListener('change', () => saveInstant(['upload_log_auto_cleanup', 'upload_log_retention_months']));
        syncUploadLogCleanup();
    }
    const applyAppearance = () => {
        const mode = valueOf('theme_mode')?.value || 'dark';
        const palette = {
            theme_color: valueOf('theme_color')?.value || '#6366F1',
            theme_background: valueOf('theme_background')?.value || '#0F172A',
            theme_surface: valueOf('theme_surface')?.value || '#111827',
            theme_text: valueOf('theme_text')?.value || '#F8FAFC',
            theme_muted: valueOf('theme_muted')?.value || '#94A3B8',
        };
        const font = valueOf('font_size')?.value || 'normal';
        const fontSizes = { extra_small: '12px', small: '14px', normal: '16px', large: '18px', extra_large: '20px' };
        document.documentElement.dataset.theme = mode;
        document.documentElement.style.setProperty('--picapi-accent', palette.theme_color);
        document.documentElement.style.setProperty('--picapi-background', palette.theme_background);
        document.documentElement.style.setProperty('--picapi-surface', palette.theme_surface);
        document.documentElement.style.setProperty('--picapi-text', palette.theme_text);
        document.documentElement.style.setProperty('--picapi-muted', palette.theme_muted);
        document.documentElement.style.setProperty('--picapi-accent-soft', `color-mix(in srgb, ${palette.theme_color} 16%, transparent)`);
        document.documentElement.style.setProperty('--picapi-surface-raised', `color-mix(in srgb, ${palette.theme_surface} 92%, ${palette.theme_text})`);
        document.documentElement.style.setProperty('--picapi-surface-hover', `color-mix(in srgb, ${palette.theme_surface} 90%, ${palette.theme_color})`);
        document.documentElement.style.setProperty('--picapi-border', `color-mix(in srgb, ${palette.theme_text} 18%, ${palette.theme_surface})`);
        document.documentElement.style.fontSize = fontSizes[font] || '16px';
        Object.entries(palette).forEach(([name, color]) => {
            const colorValue = settingsScope.querySelector(`[data-theme-color-value="${name}"]`);
            if (colorValue) colorValue.textContent = color.toUpperCase();
        });
        settingsScope.querySelectorAll('[data-theme-mode-label]').forEach(label => {
            const selected = label.querySelector('input')?.checked;
            label.classList.toggle('border-indigo-500', selected);
            label.classList.toggle('bg-indigo-500/10', selected);
            label.classList.toggle('text-indigo-200', selected);
            label.classList.toggle('border-slate-700', !selected);
            label.classList.toggle('bg-slate-950', !selected);
            label.classList.toggle('text-slate-400', !selected);
        });
        const name = valueOf('site_name')?.value.trim();
        if (name) {
            const sidebarName = document.querySelector('aside .h-16 > div > span');
            if (sidebarName) sidebarName.textContent = name;
            document.title = `${name} 后台`;
        }
    };
    const modeDefaultPalettes = {
        dark: { theme_color: '#6366F1', theme_background: '#0F172A', theme_surface: '#111827', theme_text: '#F8FAFC', theme_muted: '#94A3B8' },
        light: { theme_color: '#2563EB', theme_background: '#F7FAFF', theme_surface: '#FFFFFF', theme_text: '#102A43', theme_muted: '#52677F' },
    };
    const themePaletteParts = ['color', 'background', 'surface', 'text', 'muted'];
    const activeThemeSettingNames = themePaletteParts.map(part => `theme_${part}`);
    const rememberedThemeSettingNames = ['dark', 'light'].flatMap(mode => themePaletteParts.map(part => `${mode}_theme_${part}`));
    const resolvedThemeMode = mode => mode === 'auto' && window.matchMedia?.('(prefers-color-scheme: light)').matches ? 'light' : (mode === 'light' ? 'light' : 'dark');
    let activePaletteMode = resolvedThemeMode(valueOf('theme_mode')?.value || 'dark');
    const rememberPalette = mode => {
        themePaletteParts.forEach(part => {
            const activeInput = valueOf(`theme_${part}`);
            const rememberedInput = valueOf(`${mode}_theme_${part}`);
            if (activeInput && rememberedInput) rememberedInput.value = activeInput.value;
        });
    };
    const restorePalette = mode => {
        themePaletteParts.forEach(part => {
            const activeInput = valueOf(`theme_${part}`);
            const rememberedInput = valueOf(`${mode}_theme_${part}`);
            const fallback = modeDefaultPalettes[mode][`theme_${part}`];
            if (activeInput) activeInput.value = rememberedInput?.value || fallback;
        });
    };
    const applyModePalette = mode => {
        rememberPalette(activePaletteMode);
        activePaletteMode = resolvedThemeMode(mode);
        restorePalette(activePaletteMode);
    };
    let deferredSave;
    settingsScope.querySelector('[data-site-name-reset]')?.addEventListener('click', () => {
        const siteNameInput = valueOf('site_name');
        if (!siteNameInput) return;
        clearTimeout(deferredSave);
        siteNameInput.value = 'PicAPI';
        applyAppearance();
        saveInstant(['site_name']);
    });
    settingsForm.querySelectorAll('[data-instant-setting]').forEach(input => {
        const names = [input.name];
        const isPaletteInput = activeThemeSettingNames.includes(input.name);
        const save = () => {
            if (isPaletteInput) rememberPalette(activePaletteMode);
            applyAppearance();
            saveInstant(isPaletteInput ? [...activeThemeSettingNames, ...rememberedThemeSettingNames] : names);
        };
        input.addEventListener('change', () => {
            if (input.name === 'theme_mode') {
                applyModePalette(input.value);
                applyAppearance();
                saveInstant(['theme_mode', ...activeThemeSettingNames, ...rememberedThemeSettingNames]);
                return;
            }
            save();
        });
        if ([...activeThemeSettingNames, 'site_name'].includes(input.name)) {
            input.addEventListener('input', () => {
                if (isPaletteInput) rememberPalette(activePaletteMode);
                applyAppearance();
                clearTimeout(deferredSave);
                deferredSave = window.setTimeout(() => saveInstant(isPaletteInput ? [...activeThemeSettingNames, ...rememberedThemeSettingNames] : names), 350);
            });
        }
    });
    const securityGrid = valueOf('password_breach_alert')?.closest('div[class*="grid"]');
    if (securityGrid && !securityGrid.querySelector('[name="login_notification"]')) {
        securityGrid.classList.remove('lg:grid-cols-2');
        securityGrid.classList.add('xl:grid-cols-3');
        const loginNotificationPanel = document.createElement('div');
        loginNotificationPanel.className = 'rounded-lg border border-slate-800 bg-slate-950 p-4';
        loginNotificationPanel.innerHTML = `<label class="flex cursor-pointer items-start gap-3"><input name="login_notification" type="checkbox" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-600 bg-slate-800 text-indigo-600"><span><strong class="block text-sm font-medium text-slate-200">管理员登录提醒</strong><span class="mt-1 block text-xs leading-relaxed text-slate-500">登录成功后发送邮件，包含来源 IP、登录时间、IP 归属地和浏览器/设备信息。仅会查询有效公网 IP；查询不可用时会如实显示未知位置。</span></span></label>`;
        const loginNotificationInput = loginNotificationPanel.querySelector('[name="login_notification"]');
        if (loginNotificationInput) loginNotificationInput.checked = <?= !empty($siteSettings['login_notification']) ? 'true' : 'false' ?>;
        securityGrid.prepend(loginNotificationPanel);
    }
    ['login_notification', 'password_breach_alert', 'request_rate_alert', 'request_window_seconds', 'request_threshold'].forEach(name => {
        const input = valueOf(name);
        input?.addEventListener('change', () => saveInstant([name]));
    });
    const extraThemePresets = {
        dark: [
            ['深海', '#38BDF8', '#07111F', '#0C1C2C', '#E6F5FF', '#9DB7C9'],
            ['松石', '#14B8A6', '#061A18', '#0B2926', '#E6FFFB', '#9DC7C0'],
            ['赤霞', '#F43F5E', '#220B13', '#32101C', '#FFF1F4', '#D4A9B4'],
            ['森林', '#22C55E', '#0A1710', '#10251A', '#ECFDF3', '#A5C6AF'],
            ['铁灰', '#F59E0B', '#171717', '#262626', '#FAFAFA', '#B8B8B8'],
            ['极夜', '#818CF8', '#080B1B', '#11162D', '#EEF2FF', '#AAB4D4'],
            ['铜绿', '#2DD4BF', '#071816', '#102521', '#E8FFFA', '#9FC7BF'],
            ['莓果', '#E11D48', '#240812', '#35101C', '#FFF1F4', '#D9A6B2'],
            ['月岩', '#A3A3A3', '#151515', '#242424', '#FAFAFA', '#BDBDBD'],
            ['暖棕', '#FB923C', '#21130B', '#322016', '#FFF7ED', '#D7B59B'],
        ],
        light: [
            ['湖蓝', '#0284C7', '#F1FAFE', '#FFFFFF', '#082F49', '#55778C'],
            ['极光', '#0F9DB2', '#F1FDFF', '#FFFFFF', '#083344', '#557A80'],
            ['金盏', '#B7791F', '#FFFCF3', '#FFFFFF', '#3D2A0D', '#806C42'],
            ['葡萄', '#7C3AED', '#FAF7FF', '#FFFFFF', '#2E1065', '#75628A'],
            ['石墨', '#475569', '#F8FAFC', '#FFFFFF', '#172033', '#687386'],
            ['冰川', '#4F46E5', '#F5F7FF', '#FFFFFF', '#18214D', '#66709A'],
            ['薄荷', '#0D9488', '#F1FCF9', '#FFFFFF', '#103A35', '#5C7B74'],
            ['珊瑚', '#E85D4A', '#FFF7F5', '#FFFFFF', '#4A1E19', '#8B6661'],
            ['沙丘', '#A16207', '#FFFCF5', '#FFFFFF', '#3A2A12', '#7D6B4D'],
            ['靛青', '#4338CA', '#F6F5FF', '#FFFFFF', '#201A53', '#6B6792'],
        ],
    };
    Object.entries(extraThemePresets).forEach(([mode, presets]) => {
        const grid = settingsScope.querySelector(`[data-theme-preset][data-mode="${mode}"]`)?.parentElement;
        if (!grid || grid.dataset.extraThemePresets === 'true') return;
        grid.dataset.extraThemePresets = 'true';
        presets.forEach(([name, color, background, surface, text, muted]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.themePreset = '';
            button.dataset.mode = mode;
            button.dataset.color = color;
            button.dataset.background = background;
            button.dataset.surface = surface;
            button.dataset.text = text;
            button.dataset.muted = muted;
            button.title = `应用${name}${mode === 'dark' ? '深色' : '浅色'}主题`;
            button.className = 'group rounded-lg border border-slate-700 bg-slate-950 p-2 text-[10px] text-slate-400 hover:border-slate-500';
            button.innerHTML = `<span class="mx-auto mb-1 flex h-4 w-6 overflow-hidden rounded-sm ring-1 ring-white/10"><i class="h-full w-1/3" style="background:${background}"></i><i class="h-full w-1/3" style="background:${surface}"></i><i class="h-full w-1/3" style="background:${color}"></i></span>${name}`;
            grid.append(button);
        });
    });
    settingsScope.querySelectorAll('[data-theme-preset]').forEach(button => button.addEventListener('click', () => {
        rememberPalette(activePaletteMode);
        const palette = {
            theme_color: button.dataset.color,
            theme_background: button.dataset.background,
            theme_surface: button.dataset.surface,
            theme_text: button.dataset.text,
            theme_muted: button.dataset.muted,
        };
        Object.entries(palette).forEach(([name, color]) => {
            const input = valueOf(name);
            if (input && color) input.value = color;
        });
        const matching = settingsScope.querySelector(`[name="theme_mode"][value="${button.dataset.mode || 'dark'}"]`);
        if (matching) matching.checked = true;
        activePaletteMode = button.dataset.mode === 'light' ? 'light' : 'dark';
        rememberPalette(activePaletteMode);
        applyAppearance();
        saveInstant(['theme_mode', ...activeThemeSettingNames, ...rememberedThemeSettingNames]);
    }));
    const iconInput = settingsForm.querySelector('[data-icon-upload]');
    iconInput?.addEventListener('change', async () => {
        if (!iconInput.files?.length) return;
        const data = new FormData();
        data.append('csrf', csrf);
        data.append('site_icon', iconInput.files[0]);
        try {
            const response = await fetch('settings_preferences_save.php', { method: 'POST', body: data, credentials: 'same-origin' });
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || '图标上传失败。');
            document.querySelectorAll('img[alt="站点图标"]').forEach(image => { image.src = `../favicon.ico?v=${Date.now()}`; });
            showSettingsToast(true, '站点图标已更新。');
        } catch (error) {
            showSettingsToast(false, error.message || '图标上传失败。');
        }
    });
    const templateDefaults = <?= json_encode(['notification' => default_notification_email_html_template(), 'reset' => default_password_reset_email_html_template()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const templatePreview = settingsScope.querySelector('[data-template-preview]');
    const templateSources = Object.fromEntries(Array.from(settingsScope.querySelectorAll('[data-template-source]')).map(source => [source.dataset.templateSource, source]));
    Object.values(templateSources).forEach(source => { source.wrap = 'off'; });
    const templateCodeEditors = Object.fromEntries(Array.from(settingsScope.querySelectorAll('[data-template-code-editor]')).map(editor => [editor.querySelector('[data-template-source]')?.dataset.templateSource, editor]));
    const templateEditorPanels = Array.from(settingsScope.querySelectorAll('[data-template-editor-panel]'));
    const templateEditorButtons = Array.from(settingsScope.querySelectorAll('[data-template-editor-mode]'));
    const simpleTemplateInputs = Object.fromEntries(Array.from(settingsScope.querySelectorAll('[data-template-simple]')).map(input => [input.dataset.templateSimple, input]));
    let activeTemplate = 'notification';
    let activeTemplateEditorMode = 'source';
    const escapePreview = value => String(value).replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]));
    const highlightTemplateText = value => escapePreview(value).replace(/(\{\{[\w.-]+\}\})/g, '<span class="picapi-code-token">$1</span>');
    const formatTemplateHtml = value => {
        const source = String(value).trim();
        if (source === '') return '';
        const voidTags = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
        let depth = 0;
        return source.replace(/>\s*</g, '>\n<').split('\n').map(line => {
            const trimmed = line.trim();
            if (/^<\//.test(trimmed)) depth = Math.max(0, depth - 1);
            const formatted = `${'  '.repeat(depth)}${trimmed}`;
            const opening = trimmed.match(/^<([A-Za-z][\w:-]*)\b[^>]*>$/);
            if (opening && !voidTags.has(opening[1].toLowerCase()) && !/\/>$/.test(trimmed) && !new RegExp(`</${opening[1]}\\s*>$`, 'i').test(trimmed)) depth += 1;
            return formatted;
        }).join('\n');
    };
    const highlightTemplateTag = tag => {
        if (/^<!--[\s\S]*-->$/.test(tag)) return `<span class="picapi-code-comment">${escapePreview(tag)}</span>`;
        if (/^<!doctype\b/i.test(tag)) return `<span class="picapi-code-doctype">${escapePreview(tag)}</span>`;
        const closingTag = tag.match(/^<\s*\/\s*([A-Za-z][\w:-]*)\s*>$/);
        if (closingTag) return `&lt;/<span class="picapi-code-tag">${escapePreview(closingTag[1])}</span>&gt;`;
        const openingTag = tag.match(/^<\s*([A-Za-z][\w:-]*)([\s\S]*?)(\/?)>$/);
        if (!openingTag) return escapePreview(tag);
        const [, tagName, rawAttributes, trailingSlash] = openingTag;
        const attributePattern = /(\s+)([\w:-]+)(?:\s*(=)\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;
        let attributes = '';
        let lastIndex = 0;
        let match;
        while ((match = attributePattern.exec(rawAttributes)) !== null) {
            attributes += escapePreview(rawAttributes.slice(lastIndex, match.index));
            attributes += escapePreview(match[1]);
            attributes += `<span class="picapi-code-attribute">${escapePreview(match[2])}</span>`;
            if (match[3]) {
                const rawValue = match[4] !== undefined ? `"${match[4]}"` : (match[5] !== undefined ? `'${match[5]}'` : match[6]);
                attributes += `${escapePreview(match[3])}<span class="picapi-code-string">${highlightTemplateText(rawValue)}</span>`;
            }
            lastIndex = attributePattern.lastIndex;
        }
        attributes += escapePreview(rawAttributes.slice(lastIndex));
        return `&lt;<span class="picapi-code-tag">${escapePreview(tagName)}</span>${attributes}${trailingSlash ? '/' : ''}&gt;`;
    };
    const highlightTemplateHtml = source => String(source).split(/(<!--[\s\S]*?-->|<!doctype[^>]*>|<\/?[A-Za-z][^>]*>)/gi).map(part => /^<!--[\s\S]*-->$|^<!doctype\b|^<\/?[A-Za-z]/i.test(part) ? highlightTemplateTag(part) : highlightTemplateText(part)).join('');
    const syncTemplateHighlightScroll = source => {
        const layer = source.closest('[data-template-code-editor]')?.querySelector('pre');
        if (!layer) return;
        layer.scrollTop = source.scrollTop;
        layer.scrollLeft = source.scrollLeft;
    };
    const renderTemplateHighlight = source => {
        const code = source.closest('[data-template-code-editor]')?.querySelector('[data-template-code-highlight]');
        if (!code) return;
        try {
            code.innerHTML = highlightTemplateHtml(source.value);
        } catch (_) {
            code.textContent = source.value;
        }
        syncTemplateHighlightScroll(source);
    };
    const renderTemplatePreview = () => {
        const source = templateSources[activeTemplate];
        if (!source || !templatePreview) return;
        const tokens = { '{{site_name}}': escapePreview(valueOf('site_name')?.value || 'PicAPI'), '{{title}}': activeTemplate === 'reset' ? '找回密码验证码' : '安全提醒', '{{message}}': activeTemplate === 'reset' ? '请使用以下验证码完成密码重置。' : '检测到一条新的系统提醒。', '{{ip}}': '203.0.113.10', '{{time}}': '2026-07-20 12:00:00', '{{detail}}': '这是一封预览邮件，不会发送给任何人。', '{{code}}': '123456' };
        let previewHtml = source.value;
        Object.entries(tokens).forEach(([token, value]) => { previewHtml = previewHtml.split(token).join(value); });
        templatePreview.srcdoc = previewHtml;
    };
    const templateDocument = source => new DOMParser().parseFromString(source.value, 'text/html');
    const simpleTemplateElement = (documentNode, marker, fallback) => documentNode.querySelector(`[data-picapi-easy-${marker}]`) || fallback(documentNode);
    const readTemplateStyle = (element, property, fallback) => {
        const value = element?.style.getPropertyValue(property).trim() || '';
        return /^#[0-9a-f]{6}$/i.test(value) ? value.toUpperCase() : fallback;
    };
    const readTemplateDimension = (element, property, fallback) => {
        const value = parseInt(element?.style.getPropertyValue(property) || '', 10);
        return Number.isFinite(value) ? value : fallback;
    };
    const currentTemplateParts = source => {
        const documentNode = templateDocument(source);
        const body = simpleTemplateElement(documentNode, 'body', node => node.body);
        const shell = simpleTemplateElement(documentNode, 'shell', node => node.querySelector('table[style*="max-width"]') || node.querySelector('table'));
        const header = simpleTemplateElement(documentNode, 'header', node => Array.from(node.querySelectorAll('td')).find(cell => cell.style.getPropertyValue('background')) || null);
        const brand = simpleTemplateElement(documentNode, 'brand', node => node.querySelector('strong'));
        const message = simpleTemplateElement(documentNode, 'message', node => Array.from(node.querySelectorAll('p')).find(paragraph => paragraph.textContent.includes('{{message}}')) || node.querySelector('p'));
        const detail = simpleTemplateElement(documentNode, 'detail', node => Array.from(node.querySelectorAll('p')).find(paragraph => paragraph.textContent.includes('{{detail}}')) || null);
        const note = documentNode.querySelector('[data-picapi-easy-note]');
        return { documentNode, body, shell, header, brand, message, detail, note };
    };
    const syncSimpleTemplateEditor = () => {
        const source = templateSources[activeTemplate];
        if (!source) return;
        const { body, shell, header, brand, message, detail, note } = currentTemplateParts(source);
        const siteName = valueOf('site_name')?.value || 'PicAPI';
        const values = {
            brand: (brand?.textContent || siteName).trim().replace('{{site_name}}', siteName),
            note: (note?.textContent || '').trim(),
            width: readTemplateDimension(shell, 'max-width', 620),
            height: readTemplateDimension(shell, 'min-height', 360),
            bodyBackground: readTemplateStyle(body, 'background', '#0F172A'),
            surfaceBackground: readTemplateStyle(shell, 'background', '#111827'),
            headerBackground: readTemplateStyle(header, 'background', '#312E81'),
            primaryText: readTemplateStyle(message, 'color', '#D7E0EF'),
            mutedText: readTemplateStyle(detail, 'color', '#94A3B8'),
        };
        Object.entries(values).forEach(([name, value]) => { if (simpleTemplateInputs[name]) simpleTemplateInputs[name].value = String(value); });
    };
    const applySimpleTemplateEditor = () => {
        const source = templateSources[activeTemplate];
        if (!source) return;
        const { documentNode, body, shell, header, brand, message, detail, note } = currentTemplateParts(source);
        const inputValue = (name, fallback = '') => String(simpleTemplateInputs[name]?.value ?? fallback).trim();
        const colorValue = (name, fallback) => /^#[0-9a-f]{6}$/i.test(inputValue(name, fallback)) ? inputValue(name, fallback).toUpperCase() : fallback;
        const numberValue = (name, fallback, minimum, maximum) => {
            const parsed = parseInt(inputValue(name, String(fallback)), 10);
            return Math.max(minimum, Math.min(maximum, Number.isFinite(parsed) ? parsed : fallback));
        };
        const bodyBackground = colorValue('bodyBackground', '#0F172A');
        const surfaceBackground = colorValue('surfaceBackground', '#111827');
        const headerBackground = colorValue('headerBackground', '#312E81');
        const primaryText = colorValue('primaryText', '#D7E0EF');
        const mutedText = colorValue('mutedText', '#94A3B8');
        if (body) { body.setAttribute('data-picapi-easy-body', ''); body.style.setProperty('background', bodyBackground); body.style.setProperty('color', primaryText); }
        if (shell) { shell.setAttribute('data-picapi-easy-shell', ''); shell.style.setProperty('background', surfaceBackground); shell.style.setProperty('max-width', `${numberValue('width', 620, 320, 900)}px`); shell.style.setProperty('min-height', `${numberValue('height', 360, 0, 1600)}px`); }
        if (header) { header.setAttribute('data-picapi-easy-header', ''); header.style.setProperty('background', headerBackground); }
        if (brand) { brand.setAttribute('data-picapi-easy-brand', ''); brand.textContent = inputValue('brand', valueOf('site_name')?.value || 'PicAPI'); }
        if (message) { message.setAttribute('data-picapi-easy-message', ''); message.style.setProperty('color', primaryText); }
        documentNode.querySelectorAll('h1').forEach(title => title.style.setProperty('color', primaryText));
        if (detail) { detail.setAttribute('data-picapi-easy-detail', ''); detail.style.setProperty('color', mutedText); }
        const noteText = inputValue('note');
        const existingNote = note || null;
        if (noteText !== '') {
            const noteElement = existingNote || documentNode.createElement('p');
            noteElement.setAttribute('data-picapi-easy-note', '');
            noteElement.textContent = noteText;
            noteElement.style.cssText = `margin:16px 0 0;color:${mutedText};font-size:12px;line-height:1.6`;
            if (!existingNote && message?.parentNode) message.parentNode.insertBefore(noteElement, detail || message.nextSibling);
        } else if (existingNote) {
            existingNote.remove();
        }
        source.value = formatTemplateHtml(`<!doctype html>\n${documentNode.documentElement.outerHTML}`);
        renderTemplateHighlight(source);
        renderTemplatePreview();
    };
    const selectTemplateEditorMode = mode => {
        activeTemplateEditorMode = mode === 'simple' ? 'simple' : 'source';
        templateEditorPanels.forEach(panel => panel.classList.toggle('hidden', panel.dataset.templateEditorPanel !== activeTemplateEditorMode));
        templateEditorButtons.forEach(button => {
            const selected = button.dataset.templateEditorMode === activeTemplateEditorMode;
            button.classList.toggle('bg-indigo-600', selected);
            button.classList.toggle('text-white', selected);
            button.classList.toggle('text-slate-400', !selected);
        });
        if (activeTemplateEditorMode === 'simple') syncSimpleTemplateEditor();
    };
    const selectTemplate = key => {
        activeTemplate = key;
        Object.entries(templateSources).forEach(([name]) => templateCodeEditors[name]?.classList.toggle('hidden', name !== key));
        settingsScope.querySelectorAll('[data-template-tab]').forEach(button => {
            const selected = button.dataset.templateTab === key;
            button.classList.toggle('bg-indigo-600', selected);
            button.classList.toggle('text-white', selected);
            button.classList.toggle('text-slate-400', !selected);
        });
        renderTemplatePreview();
        if (activeTemplateEditorMode === 'simple') syncSimpleTemplateEditor();
    };
    settingsScope.querySelectorAll('[data-template-tab]').forEach(button => button.addEventListener('click', () => selectTemplate(button.dataset.templateTab)));
    templateEditorButtons.forEach(button => button.addEventListener('click', () => selectTemplateEditorMode(button.dataset.templateEditorMode)));
    Object.values(templateSources).forEach(source => {
        if (!source.value.includes('\n')) source.value = formatTemplateHtml(source.value);
        renderTemplateHighlight(source);
        source.addEventListener('input', () => { renderTemplateHighlight(source); renderTemplatePreview(); });
        source.addEventListener('scroll', () => syncTemplateHighlightScroll(source));
        source.addEventListener('keydown', event => {
            if (event.key !== 'Tab') return;
            event.preventDefault();
            source.setRangeText('  ', source.selectionStart, source.selectionEnd, 'end');
            source.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });
    Object.values(simpleTemplateInputs).forEach(input => input.addEventListener('input', applySimpleTemplateEditor));
    settingsScope.querySelector('[data-template-reset]')?.addEventListener('click', () => {
        if (!templateSources[activeTemplate]) return;
        templateSources[activeTemplate].value = formatTemplateHtml(templateDefaults[activeTemplate]);
        renderTemplateHighlight(templateSources[activeTemplate]);
        renderTemplatePreview();
        if (activeTemplateEditorMode === 'simple') syncSimpleTemplateEditor();
        showSettingsToast(true, '已还原当前模板，点击“保存邮件模板”后生效。');
    });
    selectTemplate(activeTemplate);
}
lucide.createIcons();
const ipControlPanel = document.querySelector('#ip-control');
if (ipControlPanel) {
    const durationPresets = [['30', '30 分钟'], ['60', '1 小时'], ['360', '6 小时'], ['1440', '1 天'], ['10080', '7 天'], ['43200', '30 天'], ['525600', '365 天']];
    const ensureBlacklistScope = (form, scope) => {
        let field = form.querySelector('[name="blacklist_scope"]');
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = 'blacklist_scope';
            form.append(field);
        }
        field.value = scope;
    };
    const replaceDurationWithPreset = field => {
        if (!field || field.closest('[data-blacklist-duration-control]')) return field;
        const control = document.createElement('div');
        control.dataset.blacklistDurationControl = '';
        control.className = 'inline-flex h-9 shrink-0 overflow-hidden rounded-lg border border-slate-700 bg-slate-950 transition focus-within:border-indigo-500';
        const input = document.createElement('input');
        input.type = 'number';
        input.name = 'duration_minutes';
        input.min = '1';
        input.max = '525600';
        input.value = String(field.value || '30');
        input.dataset.blacklistDuration = '';
        input.className = 'w-24 min-w-0 border-0 bg-transparent px-2 text-xs text-white outline-none';
        input.setAttribute('aria-label', '封禁时长（分钟）');
        const unit = document.createElement('span');
        unit.className = 'flex items-center border-l border-slate-800 px-2 text-[11px] text-slate-500';
        unit.textContent = '分钟';
        const preset = document.createElement('select');
        preset.dataset.blacklistDurationPreset = '';
        preset.className = 'max-w-[6rem] border-l border-slate-800 bg-slate-800 px-2 text-[11px] text-slate-300 outline-none hover:bg-slate-700';
        preset.setAttribute('aria-label', '预设封禁时长');
        preset.innerHTML = '<option value="">预设时间</option>' + durationPresets.map(([value, label]) => `<option value="${value}">${label}</option>`).join('');
        preset.addEventListener('change', () => {
            if (preset.value === '') return;
            input.value = preset.value;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
        control.append(input, unit, preset);
        field.replaceWith(control);
        return input;
    };
    const bindBlacklistDuration = form => {
        const permanent = form.querySelector('[data-blacklist-permanent]');
        const duration = replaceDurationWithPreset(form.querySelector('[data-blacklist-duration]'));
        if (!permanent || !duration || duration.dataset.blacklistBound === 'true') return;
        const control = duration.closest('[data-blacklist-duration-control]');
        const preset = control?.querySelector('[data-blacklist-duration-preset]');
        const update = () => {
            duration.disabled = permanent.checked;
            if (preset) preset.disabled = permanent.checked;
            control?.classList.toggle('opacity-50', permanent.checked);
        };
        duration.dataset.blacklistBound = 'true';
        permanent.addEventListener('change', update);
        update();
    };
    const requestBlacklistForm = Array.from(ipControlPanel.querySelectorAll('form')).find(form => form.querySelector('[name="action"][value="add_blacklist"]'));
    if (requestBlacklistForm) {
        ensureBlacklistScope(requestBlacklistForm, 'request');
        bindBlacklistDuration(requestBlacklistForm);
    }
    const loginBlacklistHeading = Array.from(ipControlPanel.querySelectorAll('h3')).find(heading => heading.textContent.trim() === '登录黑名单');
    const loginBlacklistHeader = loginBlacklistHeading?.parentElement?.parentElement;
    if (loginBlacklistHeader && !loginBlacklistHeader.querySelector('[data-login-blacklist-form]')) {
        const csrf = ipControlPanel.querySelector('[name="csrf"]')?.value || '';
        const form = document.createElement('form');
        form.method = 'post';
        form.action = 'ip_access_control.php';
        form.dataset.loginBlacklistForm = '';
        form.className = 'flex w-full flex-wrap items-center gap-2 lg:ml-auto lg:w-auto';
        form.innerHTML = `<input type="hidden" name="csrf" value="${csrf}"><input type="hidden" name="action" value="add_blacklist"><input type="hidden" name="blacklist_scope" value="login"><input name="ip" required placeholder="IPv4 或 IPv6 地址" class="min-w-[13rem] flex-1 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-white outline-none focus:border-rose-500"><input data-blacklist-duration name="duration_minutes" type="number" min="1" max="525600" value="30" aria-label="封禁时长（分钟）" class="w-28 rounded-lg border border-slate-700 bg-slate-950 px-2.5 py-2 text-xs text-white outline-none focus:border-rose-500"><label class="flex items-center gap-1.5 text-xs text-slate-400"><input data-blacklist-permanent name="permanent" type="checkbox" value="1" class="h-4 w-4 rounded border-slate-600 bg-slate-800 text-rose-600">永久封禁</label><button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-rose-600 px-3 py-2 text-xs font-medium text-white hover:bg-rose-700">添加登录封禁</button>`;
        loginBlacklistHeader.classList.remove('items-start');
        loginBlacklistHeader.classList.add('flex-wrap', 'items-center');
        loginBlacklistHeader.append(form);
        bindBlacklistDuration(form);
    }
    const renderIpListPager = table => {
        const rows = Array.from(table.body.querySelectorAll('tr')).filter(row => row.children.length > 1);
        setupPager(rows, table.pagerHeader, {
            pageKey: table.pageKey,
            sizeKey: table.sizeKey,
            unit: '\u6761',
            perPageLabel: '\u6bcf\u9875',
            headerClass: 'p-3 flex flex-col gap-3 border-t border-slate-800 bg-slate-900/50 sm:flex-row sm:items-center sm:justify-between text-xs text-slate-400',
        });
        delete table.pagerHeader.dataset.batchActive;
    };
    const setupIpListPager = (title, listKey, listType, pageKey, sizeKey) => {
        const heading = Array.from(ipControlPanel.querySelectorAll('h3')).find(element => element.textContent.trim() === title);
        const section = heading?.closest('section');
        const body = section?.querySelector('tbody');
        const tableArea = body?.closest('.overflow-x-auto');
        if (!section || !body || !tableArea) return;
        tableArea.dataset.ipListTable = '';
        const pagerHeader = document.createElement('div');
        pagerHeader.dataset.ipListPager = listKey;
        pagerHeader.className = 'p-3 flex flex-col gap-3 border-t border-slate-800 bg-slate-900/50 sm:flex-row sm:items-center sm:justify-between text-xs text-slate-400';
        tableArea.after(pagerHeader);
        const rows = Array.from(body.querySelectorAll('tr')).filter(row => row.children.length > 1);
        setupPager(rows, pagerHeader, {
            pageKey,
            sizeKey,
            unit: '行',
            perPageLabel: '每页',
            headerClass: 'p-3 flex flex-col gap-3 border-t border-slate-800 bg-slate-900/50 sm:flex-row sm:items-center sm:justify-between text-xs text-slate-400',
        });
        return { title, listKey, listType, pageKey, sizeKey, section, body, tableArea, pagerHeader, selected: new Set(), signature: '' };
    };
    const ipListTables = [
        setupIpListPager('频繁请求黑名单', 'request_blacklist', 'blacklist', 'request_blacklist_page', 'request_blacklist_per_page'),
        setupIpListPager('登录黑名单', 'login_blacklist', 'blacklist', 'login_blacklist_page', 'login_blacklist_per_page'),
        setupIpListPager('白名单', 'whitelist', 'whitelist', 'whitelist_page', 'whitelist_per_page'),
    ].filter(Boolean);
    const ipListNotice = document.createElement('div');
    ipListNotice.className = 'pointer-events-none fixed right-4 top-4 z-[110] hidden max-w-sm rounded-xl border px-4 py-3 text-sm shadow-2xl';
    document.body.append(ipListNotice);
    let ipListNoticeTimer;
    const showIpListNotice = (ok, message) => {
        clearTimeout(ipListNoticeTimer);
        ipListNotice.textContent = message;
        ipListNotice.className = `pointer-events-none fixed right-4 top-4 z-[110] max-w-sm rounded-xl border px-4 py-3 text-sm shadow-2xl ${ok ? 'border-emerald-500/30 bg-emerald-950 text-emerald-200' : 'border-rose-500/30 bg-rose-950 text-rose-200'}`;
        ipListNoticeTimer = window.setTimeout(() => ipListNotice.classList.add('hidden'), 2600);
    };
    const csrfForIpLists = ipControlPanel.querySelector('[name="csrf"]')?.value || '';
    const ipListByKey = new Map(ipListTables.map(table => [table.listKey, table]));
    const formatIpListTime = timestamp => {
        const seconds = Number(timestamp || 0);
        if (!Number.isFinite(seconds) || seconds <= 0) return '-';
        return new Date(seconds * 1000).toLocaleString('zh-CN', { hour12: false });
    };
    const createIpTableCell = (text, className = '') => {
        const cell = document.createElement('td');
        cell.className = className;
        cell.textContent = text;
        return cell;
    };
    const ensureIpSelectHeader = table => {
        const headerRow = table.section.querySelector('thead tr');
        if (!headerRow) return null;
        let checkbox = headerRow.querySelector('[data-ip-select-all]');
        if (!checkbox) {
            const header = document.createElement('th');
            header.className = 'w-12 px-5 py-3 text-center font-medium';
            checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.dataset.ipSelectAll = table.listKey;
            checkbox.className = 'h-4 w-4 rounded border-slate-600 bg-slate-800 text-indigo-600';
            checkbox.setAttribute('aria-label', '全选当前页');
            header.append(checkbox);
            headerRow.prepend(header);
            checkbox.addEventListener('change', () => {
                const visibleRows = Array.from(table.body.querySelectorAll('tr[data-ip]')).filter(row => !row.hidden);
                visibleRows.forEach(row => {
                    const ip = row.dataset.ip || '';
                    if (checkbox.checked) table.selected.add(ip);
                    else table.selected.delete(ip);
                });
                refreshIpSelectionUi(table);
            });
        }
        return checkbox;
    };
    const refreshIpSelectionUi = table => {
        const validIps = new Set(Array.from(table.body.querySelectorAll('tr[data-ip]')).map(row => row.dataset.ip));
        Array.from(table.selected).forEach(ip => { if (!validIps.has(ip)) table.selected.delete(ip); });
        table.body.querySelectorAll('[data-ip-select-row]').forEach(input => { input.checked = table.selected.has(input.value); });
        const selectAll = table.section.querySelector(`[data-ip-select-all="${table.listKey}"]`);
        if (selectAll) {
            const visibleRows = Array.from(table.body.querySelectorAll('tr[data-ip]')).filter(row => !row.hidden);
            const selectedVisible = visibleRows.filter(row => table.selected.has(row.dataset.ip || '')).length;
            selectAll.checked = visibleRows.length > 0 && selectedVisible === visibleRows.length;
            selectAll.indeterminate = selectedVisible > 0 && selectedVisible < visibleRows.length;
        }
        const batchBar = table.pagerHeader;
        if (table.selected.size === 0) {
            if (batchBar.dataset.batchActive === 'true') renderIpListPager(table);
            return;
        }
        const actionLabel = table.listType === 'blacklist' ? '批量解封' : '批量移除';
        const actionClass = table.listType === 'blacklist' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-rose-600 hover:bg-rose-700';
        batchBar.dataset.batchActive = 'true';
        batchBar.className = 'flex items-center justify-between gap-3 border-t border-slate-800 bg-slate-950/70 px-4 py-2.5 text-xs';
        batchBar.innerHTML = `<span class="text-slate-300">已选择 <strong class="font-mono text-white">${table.selected.size}</strong> 项</span><div class="flex items-center gap-2"><button type="button" data-ip-batch-action class="rounded-md ${actionClass} px-3 py-1.5 font-medium text-white">${actionLabel}</button><button type="button" data-ip-batch-clear class="rounded-md border border-slate-700 bg-slate-800 px-3 py-1.5 text-slate-300 hover:bg-slate-700">取消选择</button></div>`;
        batchBar.querySelector('[data-ip-batch-action]')?.addEventListener('click', () => submitIpListAction(table, Array.from(table.selected)));
        batchBar.querySelector('[data-ip-batch-clear]')?.addEventListener('click', () => { table.selected.clear(); refreshIpSelectionUi(table); });
    };
    const renderIpListRows = (table, records) => {
        const scrollTop = table.tableArea.scrollTop;
        const scrollLeft = table.tableArea.scrollLeft;
        ensureIpSelectHeader(table);
        table.body.replaceChildren();
        if (records.length === 0) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = table.listType === 'blacklist' && table.listKey !== 'login_blacklist' ? 6 : 5;
            cell.className = 'px-5 py-10 text-center text-slate-500';
            cell.textContent = table.listKey === 'request_blacklist' ? '当前没有频繁请求封禁记录。' : (table.listKey === 'login_blacklist' ? '当前没有登录爆破黑名单记录。' : '当前没有白名单记录。');
            row.append(cell);
            table.body.append(row);
        }
        records.forEach(entry => {
            const row = document.createElement('tr');
            row.dataset.ip = String(entry.ip || '');
            row.className = 'hover:bg-slate-800/30';
            const selectCell = document.createElement('td');
            selectCell.className = 'w-12 px-5 py-3 text-center';
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = row.dataset.ip;
            checkbox.dataset.ipSelectRow = table.listKey;
            checkbox.className = 'h-4 w-4 rounded border-slate-600 bg-slate-800 text-indigo-600';
            checkbox.checked = table.selected.has(row.dataset.ip);
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) table.selected.add(checkbox.value);
                else table.selected.delete(checkbox.value);
                refreshIpSelectionUi(table);
            });
            selectCell.append(checkbox);
            row.append(selectCell, createIpTableCell(row.dataset.ip, 'px-5 py-3 font-mono text-slate-200'));
            if (table.listKey === 'request_blacklist') {
                const automated = entry.reason === 'auto';
                const label = document.createElement('span');
                label.className = `rounded border px-2 py-1 text-[10px] ${automated ? 'border-amber-500/30 bg-amber-500/10 text-amber-400' : 'border-slate-700 bg-slate-800 text-slate-300'}`;
                label.textContent = automated ? '请求过多' : '手动封禁';
                const reasonCell = document.createElement('td');
                reasonCell.className = 'px-5 py-3';
                reasonCell.append(label);
                row.append(reasonCell, createIpTableCell(entry.permanent ? '永久封禁' : '临时封禁', 'px-5 py-3'), createIpTableCell(entry.permanent ? '永久' : formatIpListTime(entry.until), 'px-5 py-3 text-slate-400'));
            } else if (table.listKey === 'login_blacklist') {
                const label = document.createElement('span');
                label.className = 'rounded border border-rose-500/30 bg-rose-500/10 px-2 py-1 text-[10px] text-rose-400';
                label.textContent = entry.permanent ? '永久封禁' : '临时封禁';
                const statusCell = document.createElement('td');
                statusCell.className = 'px-5 py-3';
                statusCell.append(label);
                row.append(statusCell, createIpTableCell(formatIpListTime(entry.added_at), 'px-5 py-3 text-slate-400'));
            } else {
                const policy = document.createElement('span');
                policy.className = 'rounded border border-emerald-500/30 bg-emerald-500/10 px-2 py-1 text-[10px] text-emerald-400';
                policy.textContent = '跳过自动封禁';
                const policyCell = document.createElement('td');
                policyCell.className = 'px-5 py-3';
                policyCell.append(policy);
                row.append(createIpTableCell(formatIpListTime(entry.added_at), 'px-5 py-3 text-slate-400'), policyCell);
            }
            const actionCell = document.createElement('td');
            actionCell.className = 'w-24 px-5 py-3 text-right';
            const action = document.createElement('button');
            action.type = 'button';
            action.className = table.listType === 'blacklist' ? 'text-slate-400 hover:text-emerald-400' : 'text-slate-400 hover:text-rose-400';
            action.textContent = table.listType === 'blacklist' ? '解封' : '移除';
            action.addEventListener('click', () => submitIpListAction(table, [row.dataset.ip]));
            actionCell.append(action);
            row.append(actionCell);
            table.body.append(row);
        });
        const rows = Array.from(table.body.querySelectorAll('tr')).filter(row => row.children.length > 1);
        setupPager(rows, table.pagerHeader, {
            pageKey: table.pageKey,
            sizeKey: table.sizeKey,
            unit: '行',
            perPageLabel: '每页',
            headerClass: 'p-3 flex flex-col gap-3 border-t border-slate-800 bg-slate-900/50 sm:flex-row sm:items-center sm:justify-between text-xs text-slate-400',
        });
        requestAnimationFrame(() => {
            table.tableArea.scrollTop = scrollTop;
            table.tableArea.scrollLeft = scrollLeft;
            refreshIpSelectionUi(table);
        });
    };
    let ipListRefreshInFlight = false;
    const refreshIpLists = async force => {
        if (ipListRefreshInFlight) return;
        ipListRefreshInFlight = true;
        try {
            const response = await fetch('ip_access_lists.php', { credentials: 'same-origin', cache: 'no-store' });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || '无法刷新 IP 管控列表。');
            ipListTables.forEach(table => {
                const records = Array.isArray(result[table.listKey]) ? result[table.listKey] : [];
                const signature = JSON.stringify(records);
                if (!force && signature === table.signature) return;
                table.signature = signature;
                renderIpListRows(table, records);
            });
        } catch (error) {
            if (force) showIpListNotice(false, error.message || '无法刷新 IP 管控列表。');
        } finally {
            ipListRefreshInFlight = false;
        }
    };
    const submitIpListAction = async (table, ips) => {
        const uniqueIps = [...new Set(ips.filter(Boolean))];
        if (uniqueIps.length === 0) return;
        const body = new FormData();
        body.append('csrf', csrfForIpLists);
        body.append('action', table.listType === 'blacklist' ? 'batch_remove_blacklist' : 'batch_remove_whitelist');
        uniqueIps.forEach(ip => body.append('ips[]', ip));
        try {
            const response = await fetch('ip_access_control.php', { method: 'POST', headers: { Accept: 'application/json' }, body, credentials: 'same-origin' });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || '批量操作失败。');
            table.selected.clear();
            showIpListNotice(true, result.message || '操作已完成。');
            table.signature = '';
            await refreshIpLists(false);
        } catch (error) {
            showIpListNotice(false, error.message || '批量操作失败。');
        }
    };
    const bindAsyncIpAddForm = (form, listKey) => {
        if (!form || form.dataset.ipAsyncBound === 'true') return;
        form.dataset.ipAsyncBound = 'true';
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const table = ipListByKey.get(listKey);
            if (!table) return;
            try {
                const response = await fetch('ip_access_control.php', { method: 'POST', headers: { Accept: 'application/json' }, body: new FormData(form), credentials: 'same-origin' });
                const result = await response.json().catch(() => ({ ok: false, message: '操作失败。' }));
                if (!response.ok || !result.ok) {
                    showIpListNotice(false, result.message || '操作失败。');
                    return;
                }
                const ipField = form.querySelector('[name="ip"]');
                if (ipField) ipField.value = '';
                showIpListNotice(true, result.message || '操作已完成。');
                table.signature = '';
                await refreshIpLists(false);
            } catch (error) {
                showIpListNotice(false, error.message || '操作失败。');
            }
        });
    };
    bindAsyncIpAddForm(requestBlacklistForm, 'request_blacklist');
    bindAsyncIpAddForm(loginBlacklistHeader?.querySelector('[data-login-blacklist-form]'), 'login_blacklist');
    bindAsyncIpAddForm(Array.from(ipControlPanel.querySelectorAll('form')).find(form => form.querySelector('[name="action"][value="add_whitelist"]')), 'whitelist');
    ipListTables.forEach(table => {
        table.pagerHeader.addEventListener('click', () => window.setTimeout(() => refreshIpSelectionUi(table), 0));
        table.pagerHeader.addEventListener('change', () => window.setTimeout(() => refreshIpSelectionUi(table), 0));
    });
    refreshIpLists(true);
    window.setInterval(() => refreshIpLists(false), 5000);
}
const adminAvatar = document.querySelector('aside > div:last-child [data-admin-avatar]');
const adminName = adminAvatar?.nextElementSibling?.textContent?.trim();
if (adminAvatar && adminName && !adminAvatar.querySelector('img')) adminAvatar.textContent = Array.from(adminName)[0].toUpperCase();
(() => {
    const form = document.querySelector('form[action="account_save.php"] [name="action"][value="change_password"]')?.closest('form');
    const password = form?.querySelector('[name="new_password"]');
    const confirmation = form?.querySelector('[name="new_password_confirmation"]');
    if (!form || !password || !confirmation || form.querySelector('[data-account-password-feedback]')) return;

    const feedback = document.createElement('div');
    feedback.dataset.accountPasswordFeedback = 'true';
    feedback.className = 'rounded-lg border border-slate-800 bg-slate-950/70 p-3 sm:col-span-2';
    feedback.innerHTML = '<div class="flex items-center justify-between gap-3 text-[11px]"><span class="text-slate-400">密码强度</span><span data-account-password-label class="text-slate-500">至少 8 位</span></div><div class="mt-2 grid grid-cols-4 gap-1.5" aria-hidden="true"><i data-account-password-bar class="h-1.5 rounded-full bg-slate-800 transition-colors"></i><i data-account-password-bar class="h-1.5 rounded-full bg-slate-800 transition-colors"></i><i data-account-password-bar class="h-1.5 rounded-full bg-slate-800 transition-colors"></i><i data-account-password-bar class="h-1.5 rounded-full bg-slate-800 transition-colors"></i></div><p data-account-password-match class="mt-2 text-[11px] text-slate-500">使用字母、数字和符号的组合可提升密码强度。</p>';
    confirmation.closest('label')?.parentElement?.insertAdjacentElement('afterend', feedback);

    const label = feedback.querySelector('[data-account-password-label]');
    const match = feedback.querySelector('[data-account-password-match]');
    const bars = Array.from(feedback.querySelectorAll('[data-account-password-bar]'));
    const levels = [
        { label: '密码较弱', color: 'bg-rose-500', text: 'text-rose-400' },
        { label: '密码一般', color: 'bg-amber-400', text: 'text-amber-300' },
        { label: '密码良好', color: 'bg-cyan-400', text: 'text-cyan-300' },
        { label: '密码很强', color: 'bg-emerald-400', text: 'text-emerald-300' },
    ];
    const render = () => {
        const value = password.value;
        let score = 0;
        if (value.length >= 8) score++;
        if (value.length >= 12) score++;
        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
        if (/\d/.test(value) && /[^A-Za-z\d]/.test(value)) score++;
        const visibleScore = value === '' ? 0 : Math.max(1, score);
        const level = value === '' ? null : levels[visibleScore - 1];
        label.textContent = level?.label ?? '至少 8 位';
        label.className = level?.text ?? 'text-slate-500';
        bars.forEach((bar, index) => { bar.className = `h-1.5 rounded-full transition-colors ${level && index < visibleScore ? level.color : 'bg-slate-800'}`; });
        const matches = confirmation.value !== '' && value === confirmation.value;
        const mismatch = confirmation.value !== '' && !matches;
        confirmation.setCustomValidity(mismatch ? '两次输入的新密码不一致。' : '');
        match.textContent = mismatch ? '两次输入的新密码不一致。' : (matches ? '两次输入的新密码一致。' : '使用字母、数字和符号的组合可提升密码强度。');
        match.className = `mt-2 text-[11px] ${mismatch ? 'text-rose-400' : (matches ? 'text-emerald-300' : 'text-slate-500')}`;
    };
    password.addEventListener('input', render);
    confirmation.addEventListener('input', render);
    render();
})();
(() => {
    const profileForm = document.querySelector('form[action="account_save.php"] [name="action"][value="save_profile"]')?.closest('form');
    const passwordForm = document.querySelector('form[action="account_save.php"] [name="action"][value="change_password"]')?.closest('form');
    const verificationForm = document.querySelector('form[action="account_save.php"] [name="action"][value="verify_email_change"]')?.closest('form');
    if (!profileForm || !passwordForm || document.querySelector('[data-account-password-modal]')) return;

    profileForm.querySelector('[name="current_password"]')?.closest('label')?.remove();
    const profileEmail = profileForm.querySelector('[name="email"]');
    const accountEmail = profileForm.previousElementSibling?.querySelector('span:last-child')?.textContent?.trim() || profileEmail?.defaultValue || '';
    profileEmail?.closest('label')?.remove();
    if (profileEmail) {
        profileEmail.type = 'hidden';
        profileEmail.value = accountEmail;
        profileEmail.removeAttribute('required');
        profileForm.append(profileEmail);
    }
    profileForm.querySelector('.grid')?.classList.remove('sm:grid-cols-2');
    const profileHint = profileForm.querySelector('.max-w-sm');
    if (profileHint) profileHint.textContent = '修改用户名后直接保存；邮箱绑定请使用下方按钮。';
    const profileSubmit = profileForm.querySelector('button[type="submit"]');
    if (profileSubmit) profileSubmit.textContent = '保存资料';

    const createModal = (kind, title, description) => {
        const modal = document.createElement('div');
        modal.dataset.accountModal = kind;
        // Tailwind's .flex utility can override the browser's [hidden] rule,
        // so visibility must be controlled by its own .hidden utility class.
        modal.className = 'fixed inset-0 z-[90] hidden items-center justify-center p-4';
        modal.style.display = 'none';
        modal.innerHTML = `<div data-modal-backdrop class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div><section role="dialog" aria-modal="true" aria-labelledby="${kind}-modal-title" class="relative w-full max-w-md rounded-2xl border border-slate-700 bg-slate-900 p-5 shadow-2xl shadow-black/50"><div class="flex items-start justify-between gap-4 border-b border-slate-800 pb-4"><div><h3 id="${kind}-modal-title" class="text-base font-semibold text-white">${title}</h3><p class="mt-1 text-xs leading-relaxed text-slate-400">${description}</p></div><button type="button" data-modal-close aria-label="关闭" class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-800 hover:text-white">×</button></div><div data-modal-content class="pt-5"></div></section>`;
        document.body.append(modal);
        return modal;
    };
    const bindModal = (modal, trigger, focusTarget) => {
        let previousOverflow = '';
        const isOpen = () => !modal.classList.contains('hidden');
        const close = () => {
            if (!isOpen()) return;
            modal.classList.add('hidden');
            modal.style.display = 'none';
            document.body.style.overflow = previousOverflow;
            trigger.focus();
        };
        const open = () => {
            if (isOpen()) return;
            previousOverflow = document.body.style.overflow;
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            window.setTimeout(() => focusTarget()?.focus(), 0);
        };
        trigger.addEventListener('click', open);
        modal.querySelector('[data-modal-close]')?.addEventListener('click', close);
        modal.querySelector('[data-modal-backdrop]')?.addEventListener('click', close);
        document.addEventListener('keydown', event => { if (event.key === 'Escape' && isOpen()) close(); });
        return { open };
    };

    const emailTrigger = document.createElement('button');
    emailTrigger.type = 'button';
    emailTrigger.className = 'inline-flex items-center gap-2 rounded-lg border border-cyan-500/35 bg-cyan-500/10 px-3 py-2 text-xs font-medium text-cyan-200 transition hover:bg-cyan-500/20 hover:text-white';
    emailTrigger.innerHTML = '<i data-lucide="mail-check" class="h-3.5 w-3.5 text-cyan-300"></i>更改邮箱绑定';
    profileForm.insertAdjacentElement('afterend', emailTrigger);

    const emailModal = createModal('account-email', '更改邮箱绑定', '验证码会发送到新的邮箱地址，验证通过后才会完成绑定。');
    const emailContent = emailModal.querySelector('[data-modal-content]');
    const emailForm = document.createElement('form');
    emailForm.method = 'post';
    emailForm.action = 'account_save.php';
    emailForm.className = 'space-y-4';
    const csrf = profileForm.querySelector('[name="csrf"]')?.cloneNode(true);
    const username = document.createElement('input');
    username.type = 'hidden';
    username.name = 'username';
    username.value = profileForm.dataset.accountUsername || profileForm.querySelector('[name="username"]')?.defaultValue || '';
    const action = document.createElement('input');
    action.type = 'hidden';
    action.name = 'action';
    action.value = 'save_profile';
    const newEmailLabel = document.createElement('label');
    newEmailLabel.className = 'block text-xs text-slate-400';
    newEmailLabel.textContent = '新绑定邮箱';
    const newEmail = document.createElement('input');
    newEmail.name = 'email';
    newEmail.type = 'email';
    newEmail.required = true;
    newEmail.autocomplete = 'email';
    newEmail.placeholder = 'name@example.com';
    newEmail.className = 'mt-1.5 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-cyan-400';
    newEmailLabel.append(newEmail);
    const emailSubmit = document.createElement('button');
    emailSubmit.type = 'submit';
    emailSubmit.className = 'inline-flex w-full items-center justify-center rounded-lg bg-cyan-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-cyan-500';
    emailSubmit.textContent = '发送验证码';
    if (csrf) emailForm.append(csrf);
    emailForm.append(username, action, newEmailLabel, emailSubmit);
    emailContent?.append(verificationForm || emailForm);
    bindModal(emailModal, emailTrigger, () => emailModal.querySelector('input:not([type="hidden"])'));

    passwordForm.querySelector(':scope > div:first-of-type')?.remove();
    passwordForm.className = 'space-y-4';
    const passwordSubmit = passwordForm.querySelector('button[type="submit"]');
    if (passwordSubmit) {
        passwordSubmit.className = 'inline-flex items-center justify-center rounded-lg bg-amber-500 px-4 py-2.5 text-sm font-medium text-slate-950 transition hover:bg-amber-400';
        passwordSubmit.textContent = '确认修改';
    }

    const passwordTrigger = document.createElement('button');
    passwordTrigger.type = 'button';
    passwordTrigger.className = 'inline-flex items-center gap-2 rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-xs font-medium text-slate-200 transition hover:border-amber-400/50 hover:bg-slate-700 hover:text-white';
    passwordTrigger.innerHTML = '<i data-lucide="key-round" class="h-3.5 w-3.5 text-amber-300"></i>修改密码';
    passwordForm.insertAdjacentElement('beforebegin', passwordTrigger);

    const modal = createModal('account-password', '修改密码', '请先确认当前密码，再设置新的登录密码。');
    modal.dataset.accountPasswordModal = 'true';
    modal.querySelector('[data-modal-content]')?.append(passwordForm);
    bindModal(modal, passwordTrigger, () => passwordForm.querySelector('[name="current_password"]'));
    window.lucide?.createIcons?.({ attrs: { 'stroke-width': 1.8 } });
})();
(() => {
    const openButton = document.querySelector('[data-avatar-open]');
    const csrf = document.querySelector('form[action="account_save.php"] [name="csrf"]')?.value;
    if (!openButton || !csrf || document.querySelector('[data-avatar-modal]')) return;

    const modal = document.createElement('div');
    modal.dataset.avatarModal = 'true';
    modal.className = 'fixed inset-0 z-[95] hidden items-center justify-center p-4';
    modal.style.display = 'none';
    modal.innerHTML = '<div data-avatar-backdrop class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div><section role="dialog" aria-modal="true" aria-labelledby="avatar-modal-title" class="relative flex max-h-[calc(100vh-2rem)] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl shadow-black/50"><div class="flex items-start justify-between gap-4 border-b border-slate-800 px-5 py-4"><div><h3 id="avatar-modal-title" class="text-base font-semibold text-white">上传头像</h3><p class="mt-1 text-xs leading-relaxed text-slate-400">选择图片后可拖动调整位置，并使用缩放滑杆完成方形裁剪。</p></div><button type="button" data-avatar-close aria-label="关闭" class="grid h-8 w-8 shrink-0 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-800 hover:text-white">×</button></div><div class="custom-scrollbar space-y-4 overflow-y-auto px-5 py-5"><input data-avatar-file type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden"><button type="button" data-avatar-select class="inline-flex items-center gap-2 rounded-lg border border-cyan-500/35 bg-cyan-500/10 px-3 py-2 text-xs font-medium text-cyan-200 transition hover:bg-cyan-500/20 hover:text-white"><i data-lucide="image-plus" class="h-3.5 w-3.5"></i>选择图片</button><div class="mx-auto grid w-64 overflow-hidden rounded-xl border border-slate-700 bg-slate-950 shadow-inner"><canvas data-avatar-canvas width="512" height="512" class="h-64 w-64 touch-none cursor-grab select-none"></canvas></div><p data-avatar-empty class="text-center text-xs leading-relaxed text-slate-500">请选择 JPEG、PNG、WebP 或 GIF 图片。</p><div><div class="mb-2 flex items-center justify-between text-xs"><span class="text-slate-400">缩放</span><span data-avatar-zoom-value class="text-slate-500">100%</span></div><input data-avatar-zoom type="range" min="1" max="3" step="0.01" value="1" disabled class="w-full accent-cyan-500"></div><p data-avatar-notice aria-live="polite" class="min-h-5 text-xs text-slate-500"></p></div><div class="flex justify-end gap-2 border-t border-slate-800 px-5 py-4"><button type="button" data-avatar-cancel class="rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-xs text-slate-300 transition hover:bg-slate-700">取消</button><button type="button" data-avatar-save disabled class="inline-flex items-center gap-1.5 rounded-lg bg-cyan-600 px-3 py-2 text-xs font-medium text-white transition enabled:hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-50"><i data-lucide="crop" class="h-3.5 w-3.5"></i>裁剪并保存</button></div></section>';
    document.body.append(modal);

    const input = modal.querySelector('[data-avatar-file]');
    const canvas = modal.querySelector('[data-avatar-canvas]');
    const context = canvas?.getContext('2d');
    const selectButton = modal.querySelector('[data-avatar-select]');
    const saveButton = modal.querySelector('[data-avatar-save]');
    const zoomInput = modal.querySelector('[data-avatar-zoom]');
    const zoomValue = modal.querySelector('[data-avatar-zoom-value]');
    const emptyHint = modal.querySelector('[data-avatar-empty]');
    const notice = modal.querySelector('[data-avatar-notice]');
    if (!input || !canvas || !context || !selectButton || !saveButton || !zoomInput || !zoomValue || !emptyHint || !notice) return;

    const cropSize = canvas.width;
    let source = null;
    let sourceUrl = '';
    let baseScale = 1;
    let scale = 1;
    let offset = { x: 0, y: 0 };
    let drag = null;
    let previousOverflow = '';
    const setNotice = (message, error = false) => {
        notice.textContent = message;
        notice.className = `min-h-5 text-xs ${error ? 'text-rose-400' : 'text-slate-500'}`;
    };
    const constrain = () => {
        if (!source) return;
        const width = source.naturalWidth * scale;
        const height = source.naturalHeight * scale;
        offset.x = Math.min(0, Math.max(cropSize - width, offset.x));
        offset.y = Math.min(0, Math.max(cropSize - height, offset.y));
    };
    const draw = () => {
        context.clearRect(0, 0, cropSize, cropSize);
        context.fillStyle = '#020617';
        context.fillRect(0, 0, cropSize, cropSize);
        if (!source) return;
        context.drawImage(source, offset.x, offset.y, source.naturalWidth * scale, source.naturalHeight * scale);
        context.strokeStyle = 'rgba(255,255,255,.28)';
        context.lineWidth = 1;
        for (let line = 1; line < 3; line++) {
            const point = (cropSize / 3) * line;
            context.beginPath(); context.moveTo(point, 0); context.lineTo(point, cropSize); context.stroke();
            context.beginPath(); context.moveTo(0, point); context.lineTo(cropSize, point); context.stroke();
        }
    };
    const reset = () => {
        if (sourceUrl) URL.revokeObjectURL(sourceUrl);
        source = null;
        sourceUrl = '';
        drag = null;
        input.value = '';
        zoomInput.value = '1';
        zoomInput.disabled = true;
        zoomValue.textContent = '100%';
        saveButton.disabled = true;
        emptyHint.hidden = false;
        setNotice('');
        draw();
    };
    const close = () => {
        if (modal.classList.contains('hidden')) return;
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = previousOverflow;
        reset();
        openButton.focus();
    };
    const open = () => {
        if (!modal.classList.contains('hidden')) return;
        previousOverflow = document.body.style.overflow;
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        window.setTimeout(() => selectButton.focus(), 0);
    };
    const pointerPoint = event => {
        const rect = canvas.getBoundingClientRect();
        return { x: (event.clientX - rect.left) * cropSize / rect.width, y: (event.clientY - rect.top) * cropSize / rect.height };
    };
    const renderAvatar = url => {
        document.querySelectorAll('[data-admin-avatar]').forEach(node => {
            node.replaceChildren();
            const image = document.createElement('img');
            image.src = `${url}${url.includes('?') ? '&' : '?'}_=${Date.now()}`;
            image.alt = '管理员头像';
            image.className = 'h-full w-full object-cover';
            node.append(image);
        });
    };

    openButton.addEventListener('click', open);
    modal.querySelector('[data-avatar-close]')?.addEventListener('click', close);
    modal.querySelector('[data-avatar-cancel]')?.addEventListener('click', close);
    modal.querySelector('[data-avatar-backdrop]')?.addEventListener('click', close);
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && !modal.classList.contains('hidden')) close(); });
    selectButton.addEventListener('click', () => input.click());
    input.addEventListener('change', () => {
        const file = input.files?.[0];
        if (!file) return;
        if (!file.type.startsWith('image/') || file.size > 20 * 1024 * 1024) {
            setNotice('请选择不超过 20 MB 的图片文件。', true);
            input.value = '';
            return;
        }
        if (sourceUrl) URL.revokeObjectURL(sourceUrl);
        sourceUrl = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => {
            if (image.naturalWidth < 96 || image.naturalHeight < 96 || image.naturalWidth * image.naturalHeight > 24_000_000) {
                setNotice('图片尺寸应至少为 96 像素，且总像素不能超过 2400 万。', true);
                URL.revokeObjectURL(sourceUrl); sourceUrl = ''; input.value = '';
                return;
            }
            source = image;
            baseScale = Math.max(cropSize / image.naturalWidth, cropSize / image.naturalHeight);
            scale = baseScale;
            offset = { x: (cropSize - image.naturalWidth * scale) / 2, y: (cropSize - image.naturalHeight * scale) / 2 };
            zoomInput.value = '1';
            zoomInput.disabled = false;
            zoomValue.textContent = '100%';
            saveButton.disabled = false;
            emptyHint.hidden = true;
            setNotice('拖动图片可调整裁剪位置。');
            draw();
        };
        image.onerror = () => { setNotice('无法读取这张图片，请换一张再试。', true); URL.revokeObjectURL(sourceUrl); sourceUrl = ''; input.value = ''; };
        image.src = sourceUrl;
    });
    zoomInput.addEventListener('input', () => {
        if (!source) return;
        const center = { x: (cropSize / 2 - offset.x) / scale, y: (cropSize / 2 - offset.y) / scale };
        scale = baseScale * Number(zoomInput.value);
        offset = { x: cropSize / 2 - center.x * scale, y: cropSize / 2 - center.y * scale };
        constrain();
        zoomValue.textContent = `${Math.round(Number(zoomInput.value) * 100)}%`;
        draw();
    });
    canvas.addEventListener('pointerdown', event => {
        if (!source) return;
        drag = { point: pointerPoint(event), offset: { ...offset } };
        canvas.setPointerCapture(event.pointerId);
        canvas.classList.replace('cursor-grab', 'cursor-grabbing');
    });
    canvas.addEventListener('pointermove', event => {
        if (!drag || !source) return;
        const point = pointerPoint(event);
        offset = { x: drag.offset.x + point.x - drag.point.x, y: drag.offset.y + point.y - drag.point.y };
        constrain();
        draw();
    });
    const stopDrag = event => {
        if (!drag) return;
        drag = null;
        if (canvas.hasPointerCapture(event.pointerId)) canvas.releasePointerCapture(event.pointerId);
        canvas.classList.replace('cursor-grabbing', 'cursor-grab');
    };
    canvas.addEventListener('pointerup', stopDrag);
    canvas.addEventListener('pointercancel', stopDrag);
    saveButton.addEventListener('click', () => {
        if (!source || saveButton.disabled) return;
        saveButton.disabled = true;
        setNotice('正在保存裁剪后的头像…');
        canvas.toBlob(async blob => {
            if (!blob) { saveButton.disabled = false; setNotice('头像裁剪失败，请重试。', true); return; }
            const body = new FormData();
            body.append('csrf', csrf);
            body.append('avatar', blob, 'avatar.webp');
            try {
                const response = await fetch('avatar_upload.php', { method: 'POST', headers: { Accept: 'application/json' }, body, credentials: 'same-origin' });
                const result = await response.json().catch(() => ({ ok: false, message: '头像保存失败，请重试。' }));
                if (!response.ok || !result.ok || !result.url) throw new Error(result.message || '头像保存失败，请重试。');
                renderAvatar(result.url);
                close();
            } catch (error) {
                saveButton.disabled = false;
                setNotice(error.message || '头像保存失败，请重试。', true);
            }
        }, 'image/webp', 0.9);
    });
    window.lucide?.createIcons?.({ attrs: { 'stroke-width': 1.8 } });
})();
</script>
</body>
</html>
