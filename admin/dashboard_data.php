<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/image_runtime.php';

initialize_storage();
require_admin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$timezone = new DateTimeZone('Asia/Shanghai');
$today = new DateTimeImmutable('today', $timezone);
$daily = [];
for ($offset = 13; $offset >= 0; $offset--) {
    $day = $today->sub(new DateInterval('P' . $offset . 'D'));
    $key = $day->format('Y-m-d');
    $daily[$key] = ['day' => $key, 'requests' => 0, 'bytes' => 0, 'pc' => 0, 'mobile' => 0, 'auto' => 0, 'visitors' => []];
}

$auditDays = array_keys($daily);
$auditDays[] = $today->sub(new DateInterval('P14D'))->format('Y-m-d');
$databaseDaily = database_daily_statistics(array_unique($auditDays));
foreach (array_unique($auditDays) as $day) {
    if (($databaseDaily[$day]['requests'] ?? 0) > 0 && isset($daily[$day])) {
        $daily[$day]['requests'] = (int) $databaseDaily[$day]['requests'];
        $daily[$day]['bytes'] = (int) $databaseDaily[$day]['bytes'];
        $daily[$day]['pc'] = (int) $databaseDaily[$day]['pc'];
        $daily[$day]['mobile'] = (int) $databaseDaily[$day]['mobile'];
        $daily[$day]['auto'] = (int) $databaseDaily[$day]['auto'];
        continue;
    }
    $file = STORAGE_DIR . '/logs/' . str_replace('-', '/', $day) . '.jsonl';
    if (!is_file($file)) continue;
    $handle = fopen($file, 'rb');
    if ($handle === false) continue;
    while (($line = fgets($handle)) !== false) {
        $record = json_decode($line, true);
        if (!is_array($record)) continue;
        try {
            $recordDay = (new DateTimeImmutable((string) ($record['time'] ?? 'now')))->setTimezone($timezone)->format('Y-m-d');
        } catch (Throwable) {
            continue;
        }
        if (!isset($daily[$recordDay])) continue;
        $channel = in_array($record['channel'] ?? null, ['auto', 'pc', 'mobile'], true)
            ? (string) $record['channel']
            : ((string) ($record['device'] ?? '') === 'mobile' ? 'mobile' : ((string) ($record['device'] ?? '') === 'pc' ? 'pc' : 'auto'));
        $daily[$recordDay]['requests']++;
        $daily[$recordDay]['bytes'] += max(0, (int) ($record['bytes'] ?? 0));
        $daily[$recordDay][$channel]++;
        $daily[$recordDay]['visitors'][(string) ($record['ip'] ?? '')] = true;
    }
    fclose($handle);
}

$periods = [];
foreach (['today' => 1, 'yesterday' => 1, 'seven_days' => 7, 'fourteen_days' => 14] as $name => $days) {
    $series = $name === 'yesterday' ? [array_values($daily)[12]] : array_slice(array_values($daily), -$days);
    $periods[$name] = ['pc' => 0, 'mobile' => 0, 'auto' => 0, 'total' => 0];
    foreach ($series as $item) {
        $periods[$name]['pc'] += $item['pc'];
        $periods[$name]['mobile'] += $item['mobile'];
        $periods[$name]['auto'] += $item['auto'];
        $periods[$name]['total'] += $item['requests'];
    }
}

$images = list_images();
$pcBytes = 0;
$mobileBytes = 0;
$pcCount = 0;
foreach ($images as $image) {
    if ($image['device'] === 'pc') {
        $pcCount++;
        $pcBytes += (int) $image['size'];
    } else {
        $mobileBytes += (int) $image['size'];
    }
}
$formatBytes = static function (int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
};
$counters = request_counter_state();
$values = array_values($daily);
$todayRequests = $values[13]['requests'];
$yesterdayRequests = $values[12]['requests'];
$change = $yesterdayRequests > 0 ? round((($todayRequests - $yesterdayRequests) / $yesterdayRequests) * 100) : 0;

echo json_encode([
    'todayRequests' => $todayRequests,
    'yesterdayRequests' => $yesterdayRequests,
    'totalRequests' => max(0, (int) ($counters['total'] ?? 0)),
    'requestChange' => $change,
    'requestPeriods' => $periods,
    'requestDailySeries' => array_map(static fn(array $item): array => ['day' => $item['day'], 'pc' => $item['pc'], 'mobile' => $item['mobile'], 'auto' => $item['auto']], $values),
    'dashboardDailySeries' => array_map(static fn(array $item): array => ['day' => $item['day'], 'requests' => $item['requests'], 'bytes' => $item['bytes']], $values),
    'imageStorageStats' => [
        ['label' => 'PC 图片总占用', 'value' => $formatBytes($pcBytes), 'detail' => $pcCount . ' 张 PC 图片', 'icon' => 'monitor', 'color' => 'text-indigo-400'],
        ['label' => '手机图片总占用', 'value' => $formatBytes($mobileBytes), 'detail' => (count($images) - $pcCount) . ' 张手机图片', 'icon' => 'smartphone', 'color' => 'text-emerald-400'],
        ['label' => '全部图片总占用', 'value' => $formatBytes($pcBytes + $mobileBytes), 'detail' => count($images) . ' 张全部原始图片', 'icon' => 'hard-drive', 'color' => 'text-amber-400'],
    ],
    'updatedAt' => gmdate('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
