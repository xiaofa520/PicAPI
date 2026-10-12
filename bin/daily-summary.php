<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/dashboard_runtime.php';
initialize_storage();
$settings = site_settings();
if (!$settings['daily_summary']) { echo "Daily summary is disabled.\n"; exit; }
$date = (new DateTimeImmutable('yesterday', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
$lock = fopen(STORAGE_DIR . '/daily-summary.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "Summary job is already running.\n"); exit(1); }
try {
    if ((database_document('daily-summary-delivery')['date'] ?? '') === $date) { echo "Summary already sent.\n"; exit; }
    $stats = dashboard_statistics(); $day = $stats['dashboardDailySeries'][28]; $channels = $stats['requestDailySeries'][28];
    $detail = '请求次数：' . $day['requests'] . '；流量：' . round($day['bytes']/1048576,2) . ' MB；自适应：' . $channels['auto'] . '；PC：' . $channels['pc'] . '；手机：' . $channels['mobile'];
    if (!send_site_notification('每日访问摘要 · ' . $date, '昨日图片接口访问情况', ['detail'=>$detail])) throw new RuntimeException('Unable to send daily summary.');
    if (!save_database_document('daily-summary-delivery', ['date'=>$date,'sent_at'=>gmdate('c')])) throw new RuntimeException('Unable to record delivery.');
    echo "Daily summary sent.\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
finally { flock($lock, LOCK_UN); fclose($lock); }
