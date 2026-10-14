<?php
declare(strict_types=1);
require_once __DIR__ . '/app.php';

function login_log_database(): PDO
{
    $pdo = runtime_state_database();
    if ($pdo === null) throw new RuntimeException('登录日志数据库不可用。');
    $sql = 'CREATE TABLE IF NOT EXISTS picapi_login_logs (record_id VARCHAR(64) PRIMARY KEY, logged_at BIGINT NOT NULL, account VARCHAR(200) NOT NULL, ip VARCHAR(64) NOT NULL, result VARCHAR(20) NOT NULL, device VARCHAR(500) NOT NULL, reason VARCHAR(500) NOT NULL)';
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlsrv') $sql = "IF OBJECT_ID('picapi_login_logs', 'U') IS NULL CREATE TABLE picapi_login_logs (record_id NVARCHAR(64) PRIMARY KEY, logged_at BIGINT NOT NULL, account NVARCHAR(200) NOT NULL, ip NVARCHAR(64) NOT NULL, result NVARCHAR(20) NOT NULL, device NVARCHAR(500) NOT NULL, reason NVARCHAR(500) NOT NULL)";
    $pdo->exec($sql);
    ensure_database_index($pdo, 'picapi_login_logs', 'logged_at', 'picapi_login_logs_time_idx');
    return $pdo;
}

function record_login_log(string $account, string $result, string $reason): void
{
    $short = static fn(string $value, int $length): string => function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    $record = [
        'record_id' => bin2hex(random_bytes(16)),
        'logged_at' => time(),
        'account' => $short(trim(str_replace(["\r", "\n", "\0"], ' ', $account)), 120),
        'ip' => request_client_ip(),
        'result' => in_array($result, ['success', 'failure'], true) ? $result : 'failure',
        'device' => $short(trim(str_replace(["\r", "\n", "\0"], ' ', request_client_device_label())), 500),
        'reason' => $short(trim(str_replace(["\r", "\n", "\0"], ' ', $reason)), 240),
    ];
    // The local copy is written first so a database outage cannot erase an audit event.
    try {
        $date = (new DateTimeImmutable('@' . $record['logged_at']))->setTimezone(new DateTimeZone('Asia/Shanghai'));
        $directory = STORAGE_DIR . '/logs/login/' . $date->format('Y/m');
        ensure_directory($directory);
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        @file_put_contents($directory . '/' . $date->format('d') . '.jsonl', $json . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable) {
        error_log('PicAPI login audit local copy could not be persisted.');
    }
    try {
        $pdo = login_log_database();
        $statement = $pdo->prepare('INSERT INTO picapi_login_logs (record_id, logged_at, account, ip, result, device, reason) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$record['record_id'], $record['logged_at'], $record['account'], $record['ip'], $record['result'], $record['device'], $record['reason']]);
    } catch (Throwable) {
        error_log('PicAPI login audit could not be persisted.');
    }
    try { purge_expired_login_logs(); } catch (Throwable) { /* Cleanup must not affect authentication. */ }
}

function login_log_records(int $limit = 5000, ?string $startDate = null, ?string $endDate = null): array
{
    $limit = max(1, min(5000, $limit));
    $records = [];
    try {
        $pdo = login_log_database();
        $timezone = new DateTimeZone('Asia/Shanghai');
        $conditions = [];
        $parameters = [];
        if ($startDate !== null) { $conditions[] = 'logged_at >= :start_ts'; $parameters['start_ts'] = (new DateTimeImmutable($startDate . ' 00:00:00', $timezone))->getTimestamp(); }
        if ($endDate !== null) { $conditions[] = 'logged_at <= :end_ts'; $parameters['end_ts'] = (new DateTimeImmutable($endDate . ' 23:59:59', $timezone))->getTimestamp(); }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlsrv'
            ? "SELECT TOP {$limit} * FROM picapi_login_logs{$where} ORDER BY logged_at DESC, record_id DESC"
            : "SELECT * FROM picapi_login_logs{$where} ORDER BY logged_at DESC, record_id DESC LIMIT {$limit}";
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) $records[(string) $row['record_id']] = $row;
    } catch (Throwable) {
        // Keep showing the durable local audit copy while the database is unavailable.
    }
    $root = STORAGE_DIR . '/logs/login';
    if (count($records) < $limit) {
        $filesByDay = [];
        foreach (glob($root . '/*/*/*.jsonl') ?: [] as $path) {
            if (preg_match('~(\\d{4})/(\\d{2})/(\\d{2})\\.jsonl$~', str_replace('\\', '/', $path), $matches) !== 1) continue;
            $day = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
            if (($startDate !== null && $day < $startDate) || ($endDate !== null && $day > $endDate)) continue;
            $filesByDay[$day][] = $path;
        }
        krsort($filesByDay, SORT_STRING);
        foreach ($filesByDay as $dayFiles) {
            foreach ($dayFiles as $path) {
                $handle = @fopen($path, 'rb');
                if ($handle === false) continue;
                while (($line = fgets($handle)) !== false) {
                    $row = json_decode($line, true);
                    if (!is_array($row) || empty($row['record_id'])) continue;
                    $records[(string) $row['record_id']] ??= $row;
                }
                fclose($handle);
            }
            if (count($records) >= $limit) break;
        }
    }
    uasort($records, static fn(array $a, array $b): int => ((int) ($b['logged_at'] ?? 0) <=> (int) ($a['logged_at'] ?? 0)) ?: strcmp((string) ($b['record_id'] ?? ''), (string) ($a['record_id'] ?? '')));
    $records = array_slice($records, 0, $limit, true);
    $rows = [];
    foreach ($records as $row) {
        $client = login_log_split_device_browser((string) ($row['device'] ?? ''));
        $rows[] = ['id' => count($rows) + 1, 'time' => (int) ($row['logged_at'] ?? 0) * 1000, 'account' => (string) ($row['account'] ?? ''), 'ip' => (string) ($row['ip'] ?? ''), 'result' => (string) ($row['result'] ?? 'failure'), 'device' => $client['device'], 'browser' => $client['browser'], 'reason' => (string) ($row['reason'] ?? ''), 'source' => '服务器认证记录'];
    }
    return $rows;
}

/** @return array{device:string,browser:string} */
function login_log_split_device_browser(string $label): array
{
    $parts = preg_split('/\s*·\s*/u', trim($label), 2);
    if (is_array($parts) && count($parts) === 2) {
        return [
            'device' => trim($parts[1]) !== '' ? trim($parts[1]) : '未知系统',
            'browser' => trim($parts[0]) !== '' ? trim($parts[0]) : '未知浏览器',
        ];
    }
    if (trim($label) === '' || trim($label) === '未知设备') return ['device' => '未知系统', 'browser' => '未知浏览器'];
    return ['device' => trim($label), 'browser' => '未知浏览器'];
}

/** @return list<string> */
function login_log_dates(): array
{
    $days = [];
    try {
        $pdo = login_log_database();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $dateExpression = match ($driver) {
            'sqlite' => "date(logged_at, 'unixepoch', '+8 hours')",
            'mysql' => "DATE(DATE_ADD('1970-01-01 08:00:00', INTERVAL logged_at SECOND))",
            'sqlsrv' => "CONVERT(char(10), DATEADD(second, CONVERT(int, (logged_at % 86400) + 28800), DATEADD(day, CONVERT(int, logged_at / 86400), '19700101')), 23)",
            default => null,
        };
        if ($dateExpression !== null) {
            $statement = $pdo->query("SELECT DISTINCT {$dateExpression} AS log_day FROM picapi_login_logs ORDER BY log_day DESC");
            while (($day = $statement->fetchColumn()) !== false) {
                $day = (string) $day;
                if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $day) === 1) $days[$day] = true;
            }
        } else {
            $timezone = new DateTimeZone('Asia/Shanghai');
            $statement = $pdo->query('SELECT logged_at FROM picapi_login_logs');
            while (($timestamp = $statement->fetchColumn()) !== false) {
                $days[(new DateTimeImmutable('@' . max(0, (int) $timestamp)))->setTimezone($timezone)->format('Y-m-d')] = true;
            }
        }
    } catch (Throwable) {
        // Local daily files still expose the available days if the database is unavailable.
    }
    foreach (glob(STORAGE_DIR . '/logs/login/*/*/*.jsonl') ?: [] as $path) {
        if (preg_match('~(\\d{4})/(\\d{2})/(\\d{2})\\.jsonl$~', str_replace('\\', '/', $path), $matches) === 1) $days[$matches[1] . '-' . $matches[2] . '-' . $matches[3]] = true;
    }
    $result = array_keys($days);
    rsort($result, SORT_STRING);
    return $result;
}
