<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/image_runtime.php';

initialize_storage();
require_admin();

function export_date_in_range(string $date, ?string $startDate, ?string $endDate): bool
{
    return ($startDate === null || $date >= $startDate) && ($endDate === null || $date <= $endDate);
}

function exported_record_date(array $record): ?string
{
    try {
        return (new DateTimeImmutable((string) ($record['time'] ?? 'now')))
            ->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
    } catch (Throwable) {
        return null;
    }
}

/** @return Generator<int,array<string,mixed>> */
function exported_access_records(?string $startDate, ?string $endDate): Generator
{
    $files = glob(STORAGE_DIR . '/logs/*/*/*.jsonl') ?: [];
    sort($files, SORT_STRING);
    foreach ($files as $file) {
        $handle = fopen($file, 'rb');
        if ($handle === false) continue;
        while (($line = fgets($handle)) !== false) {
            $record = json_decode($line, true);
            if (!is_array($record) || !isset($record['time'])) continue;
            $date = exported_record_date($record);
            if ($date === null || !export_date_in_range($date, $startDate, $endDate)) continue;
            yield $record;
        }
        fclose($handle);
    }
}

/** @return list<array<string,string|int>> */
function exported_upload_rows(?string $startDate, ?string $endDate): array
{
    try {
        seed_upload_audit_records(list_images());
        $records = list_upload_audit_records();
    } catch (Throwable) {
        $records = [];
    }
    $rows = [];
    foreach ($records as $record) {
        $date = exported_record_date($record);
        if ($date === null || !export_date_in_range($date, $startDate, $endDate)) continue;
        $time = (new DateTimeImmutable($record['time']))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
        $rows[] = [
            '上传时间' => $time,
            '文件名' => $record['name'],
            '分类' => $record['category'],
            '图片库' => $record['device'] === 'mobile' ? '手机' : 'PC',
            '分辨率' => $record['width'] . ' × ' . $record['height'],
            '大小(字节)' => $record['size'],
        ];
    }
    return $rows;
}

/** @return list<array<string,string|int>> */
function exported_rows(string $section, ?string $startDate, ?string $endDate): array
{
    if ($section === 'uploads') {
        return exported_upload_rows($startDate, $endDate);
        $rows = [];
        foreach (list_images() as $image) {
            $timestamp = filemtime($image['path']) ?: 0;
            $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
            if (!export_date_in_range($date, $startDate, $endDate)) continue;
            $folder = dirname($image['relative']);
            $rows[] = [
                '上传时间' => (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s'),
                '文件名' => basename($image['relative']),
                '分类' => $folder === '.' ? '默认分类' : $folder,
                '图片库' => $image['device'] === 'mobile' ? '手机' : 'PC',
                '分辨率' => $image['width'] . ' × ' . $image['height'],
                '大小(字节)' => (int) $image['size'],
            ];
        }
        return $rows;
    }

    $daily = [];
    $records = [];
    foreach (exported_access_records($startDate, $endDate) as $record) {
        $channel = in_array($record['channel'] ?? null, ['auto', 'pc', 'mobile'], true)
            ? (string) $record['channel']
            : ((string) ($record['device'] ?? '') === 'mobile' ? 'mobile' : ((string) ($record['device'] ?? '') === 'pc' ? 'pc' : 'auto'));
        if ($section === 'access') {
            $records[] = [
                '时间' => (new DateTimeImmutable((string) ($record['time'] ?? 'now')))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s'),
                '接口入口' => $channel === 'auto' ? '自适应' : ($channel === 'mobile' ? '手机' : 'PC'),
                '返回图片库' => (string) ($record['device'] ?? ''),
                'IP' => (string) ($record['ip'] ?? ''),
                '资源路径' => (string) ($record['image'] ?? ''),
                '请求方法' => (string) ($record['method'] ?? ''),
                '输出类型' => (string) ($record['type'] ?? ''),
                '状态码' => (int) ($record['status'] ?? 0),
                '输出字节' => (int) ($record['bytes'] ?? 0),
            ];
            continue;
        }

        $day = exported_record_date($record);
        if ($day === null) continue;
        $daily[$day] ??= ['日期' => $day, '总请求量' => 0, '自适应接口' => 0, 'PC接口' => 0, '手机接口' => 0, '总输出字节' => 0, '独立IP数' => []];
        $daily[$day]['总请求量']++;
        $daily[$day]['总输出字节'] += (int) ($record['bytes'] ?? 0);
        $daily[$day]['独立IP数'][(string) ($record['ip'] ?? '')] = true;
        $daily[$day][$channel === 'auto' ? '自适应接口' : ($channel === 'mobile' ? '手机接口' : 'PC接口')]++;
    }
    if ($section === 'metrics') {
        $rows = [];
        foreach ($daily as $row) {
            $row['独立IP数'] = count($row['独立IP数']);
            $rows[] = $row;
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['日期'], (string) $b['日期']));
        return $rows;
    }
    return $records;
}

/** @return list<array<string,string|int>> */
function exported_all_rows(?string $startDate, ?string $endDate): array
{
    $labels = [
        'access' => '访问流水',
        'uploads' => '上传审计',
        'metrics' => '每日指标',
    ];
    $rows = [];
    foreach ($labels as $section => $label) {
        foreach (exported_rows($section, $startDate, $endDate) as $row) {
            $rows[] = ['记录类别' => $label] + $row;
        }
    }
    if ($rows === []) {
        return [];
    }

    $columns = [];
    foreach ($rows as $row) {
        foreach (array_keys($row) as $column) {
            $columns[$column] = true;
        }
    }
    $blankRow = array_fill_keys(array_keys($columns), '');
    return array_map(static fn(array $row): array => array_replace($blankRow, $row), $rows);
}

$section = (string) ($_GET['section'] ?? '');
$format = (string) ($_GET['format'] ?? '');
$legacyDate = (string) ($_GET['date'] ?? '');
$startValue = (string) ($_GET['start_date'] ?? $legacyDate);
$endValue = (string) ($_GET['end_date'] ?? $legacyDate);
$startDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startValue) ? $startValue : null;
$endDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $endValue) ? $endValue : null;
$validSections = ['access', 'uploads', 'metrics', 'all'];
$validFormats = ['csv', 'txt'];

if (!in_array($section, $validSections, true) || !in_array($format, $validFormats, true)) {
    abort_request(422, 'Missing or invalid export options.');
}
if (($startDate !== null && $endDate !== null && $startDate > $endDate) || ($startValue !== '' && $startDate === null) || ($endValue !== '' && $endDate === null)) {
    abort_request(422, 'Invalid export date range.');
}

$rows = $section === 'all'
    ? exported_all_rows($startDate, $endDate)
    : exported_rows($section, $startDate, $endDate);
$label = ['access' => 'access', 'uploads' => 'uploads', 'metrics' => 'metrics', 'all' => 'all-content'][$section];
$rangeLabel = $startDate === null && $endDate === null ? 'all' : (($startDate ?? 'start') . '_to_' . ($endDate ?? 'end'));
$filename = 'picapi-' . $label . '-' . $rangeLabel . '-' . gmdate('Ymd-His');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    if ($rows !== []) {
        fputcsv($output, array_keys($rows[0]));
        foreach ($rows as $row) fputcsv($output, array_values($row));
    }
    fclose($output);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '.txt"');
if ($rows === []) {
    echo "没有符合条件的数据。\n";
    exit;
}
echo implode("\t", array_keys($rows[0])) . "\n";
foreach ($rows as $row) echo implode("\t", array_map(static fn(mixed $value): string => str_replace(["\t", "\r", "\n"], ' ', (string) $value), array_values($row))) . "\n";
