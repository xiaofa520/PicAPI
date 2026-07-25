<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/settings_runtime.php';

const ALLOWED_IMAGE_MIME_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];
const IMAGE_INDEX_VERSION = 2;
const IMAGE_INDEX_TTL_SECONDS = 30;

function detect_image_mime(string $path): ?string
{
    $dimensions = @getimagesize($path);
    $mime = is_array($dimensions) ? ($dimensions['mime'] ?? null) : null;
    if (is_string($mime) && isset(ALLOWED_IMAGE_MIME_TYPES[$mime])) {
        return $mime;
    }

    // Some lightweight PHP installations do not load ext-fileinfo. Images can
    // still be safely identified through getimagesize(), so only use finfo when
    // it is actually available.
    if (!class_exists('finfo')) {
        return null;
    }
    try {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        return is_string($detected) && isset(ALLOWED_IMAGE_MIME_TYPES[$detected]) ? $detected : null;
    } catch (Throwable) {
        return null;
    }
}

/** @param array<int,mixed> $dimensions */
function assert_safe_image_dimensions(array $dimensions): void
{
    $width = (int) ($dimensions[0] ?? 0);
    $height = (int) ($dimensions[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 20000 || $height > 20000 || $width * $height > MAX_IMAGE_PIXELS) {
        throw new RuntimeException('Image dimensions exceed the allowed limit.', 413);
    }
}

function cleanup_expired_chunk_uploads(): void
{
    $root = STORAGE_DIR . '/chunks';
    if (!is_dir($root)) {
        return;
    }
    $now = time();
    foreach (new DirectoryIterator($root) as $entry) {
        if ($entry->isDot() || !$entry->isDir() || $entry->isLink() || !preg_match('/^[a-f0-9]{32}$/', $entry->getFilename())) {
            continue;
        }
        if ($entry->getMTime() > $now - CHUNK_UPLOAD_TTL_SECONDS) {
            continue;
        }
        $directory = $entry->getPathname();
        $lockPath = $directory . DIRECTORY_SEPARATOR . '.lock';
        $lock = @fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            continue;
        }
        try {
            foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
                if ($file->isFile() && !$file->isLink()) {
                    @unlink($file->getPathname());
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        @unlink($lockPath);
        @rmdir($directory);
    }
}

function thumbnail_path(string $device, string $relativePath): string
{
    if (!in_array($device, ['pc', 'mobile'], true)) {
        throw new InvalidArgumentException('Invalid thumbnail device.');
    }
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        throw new InvalidArgumentException('Invalid thumbnail path.');
    }
    return THUMBNAIL_DIR . DIRECTORY_SEPARATOR . $device . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) . '.png';
}

function original_upload_filename(string $originalName, string $fallbackExtension): string
{
    $filename = basename(str_replace('\\', '/', trim($originalName)));
    if ($filename === '' || $filename === '.' || $filename === '..' || preg_match('/[<>:"\\/|?*\x00-\x1F]/u', $filename) || rtrim($filename, '. ') !== $filename) {
        throw new RuntimeException('Invalid original filename.', 422);
    }
    $fallbackExtension = strtolower(ltrim($fallbackExtension, '.'));
    if (!in_array($fallbackExtension, array_values(ALLOWED_IMAGE_MIME_TYPES), true)) {
        throw new RuntimeException('Invalid image extension.', 422);
    }

    // Never retain a browser-supplied extension. A valid image named
    // `payload.php` is still a PHP file to a web server, and could be executed
    // if it is placed in a public image directory. Canonicalize every stored
    // name to the extension derived from the verified image MIME type.
    $stem = pathinfo($filename, PATHINFO_FILENAME);
    if ($stem === '' || $stem === '.' || $stem === '..') {
        throw new RuntimeException('Invalid original filename.', 422);
    }
    return $stem . '.' . $fallbackExtension;
}

function unique_upload_filename(string $directory, string $filename): string
{
    if (!is_file($directory . DIRECTORY_SEPARATOR . $filename)) return $filename;
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $stem = pathinfo($filename, PATHINFO_FILENAME);
    for ($number = 1; $number <= 9999; $number++) {
        $candidate = $stem . '-' . $number . ($extension === '' ? '' : '.' . $extension);
        if (!is_file($directory . DIRECTORY_SEPARATOR . $candidate)) return $candidate;
    }
    throw new RuntimeException('无法生成不重复的图片文件名。');
}

/** @return array{path:string,relative:string,device:string,mime:string,size:int,width:int,height:int}|null */
function inspect_image_file(string $path, string $root, string $device): ?array
{
    $realPath = realpath($path);
    $realRoot = realpath($root);
    if ($realPath === false || $realRoot === false || !str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR)) {
        return null;
    }
    $dimensions = @getimagesize($realPath);
    if ($dimensions === false) {
        return null;
    }
    // getimagesize already reads the image header. Reuse the MIME type it
    // returned instead of parsing every image header a second time while the
    // persistent index is refreshed.
    $mime = is_string($dimensions['mime'] ?? null) && isset(ALLOWED_IMAGE_MIME_TYPES[$dimensions['mime']])
        ? $dimensions['mime']
        : detect_image_mime($realPath);
    if ($mime === null) return null;
    return [
        'path' => $realPath,
        'relative' => str_replace(DIRECTORY_SEPARATOR, '/', substr($realPath, strlen($realRoot) + 1)),
        'device' => $device,
        'mime' => $mime,
        'size' => (int) filesize($realPath),
        'width' => (int) $dimensions[0],
        'height' => (int) $dimensions[1],
    ];
}

function has_supported_image_extension(string $filename): bool
{
    return in_array(strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

function image_index_path(): string
{
    return STORAGE_DIR . '/image-index.json';
}

/**
 * Marks the file-based image index stale after a managed image operation.
 * SSH changes are also detected by the short cache lifetime.
 */
function invalidate_image_index(): void
{
    @unlink(image_index_path());
}

/** @return array{version:int,generated_at:int,images:list<array<string,mixed>>}|null */
function read_image_index(): ?array
{
    $path = image_index_path();
    if (!is_file($path)) return null;
    $decoded = json_decode((string) @file_get_contents($path), true);
    if (!is_array($decoded)
        || (int) ($decoded['version'] ?? 0) !== IMAGE_INDEX_VERSION
        || !is_int($decoded['generated_at'] ?? null)
        || !is_array($decoded['images'] ?? null)) {
        return null;
    }
    return $decoded;
}

/** @param array{version:int,generated_at:int,images:list<array<string,mixed>>} $index */
function write_image_index(array $index): void
{
    $path = image_index_path();
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $encoded = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || file_put_contents($temporary, $encoded, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
    }
}

/** @return list<array<string,mixed>> */
function scan_image_index(): array
{
    $images = [];
    foreach ([['root' => IMAGE_PC_DIR, 'device' => 'pc'], ['root' => IMAGE_MOBILE_DIR, 'device' => 'mobile']] as $source) {
        $root = (string) $source['root'];
        $device = (string) $source['device'];
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) continue;
                // Category folders also contain their generated index.php
                // endpoint. Ignore it (and unrelated SSH files) before any
                // image-header parsing is attempted.
                if (!has_supported_image_extension($file->getFilename())) continue;
                $image = inspect_image_file($file->getPathname(), $root, $device);
                if ($image === null) continue;
                $images[] = [
                    'relative' => $image['relative'], 'device' => $image['device'], 'mime' => $image['mime'],
                    'size' => $image['size'], 'width' => $image['width'], 'height' => $image['height'],
                    'modified_at' => max(0, (int) @filemtime($image['path'])),
                ];
            }
        } catch (UnexpectedValueException) {
            // An unreadable directory must not make the public image API fail.
        }
    }
    usort($images, static fn(array $left, array $right): int => (int) $right['modified_at'] <=> (int) $left['modified_at']);
    return $images;
}

/** @return array{version:int,generated_at:int,images:list<array<string,mixed>>} */
function image_index(): array
{
    static $loaded = null;
    if (is_array($loaded)) return $loaded;

    $stored = read_image_index();
    if ($stored !== null && (int) $stored['generated_at'] >= time() - IMAGE_INDEX_TTL_SECONDS) {
        return $loaded = $stored;
    }

    $lock = @fopen(STORAGE_DIR . '/image-index.lock', 'c+');
    if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
        try {
            // Another PHP worker may have refreshed the index while this request
            // waited for the lock.
            $stored = read_image_index();
            if ($stored !== null && (int) $stored['generated_at'] >= time() - IMAGE_INDEX_TTL_SECONDS) {
                return $loaded = $stored;
            }
            $loaded = ['version' => IMAGE_INDEX_VERSION, 'generated_at' => time(), 'images' => scan_image_index()];
            write_image_index($loaded);
            return $loaded;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    if (is_resource($lock)) fclose($lock);
    // Do not queue every public request behind a potentially long scan of a
    // large image library. A previous index remains perfectly usable while a
    // different PHP worker refreshes it; only a cold start has to scan here.
    if ($stored !== null) return $loaded = $stored;
    // Preserve API availability if a temporary filesystem lock cannot be made
    // before the initial index has ever been written.
    return $loaded = ['version' => IMAGE_INDEX_VERSION, 'generated_at' => time(), 'images' => scan_image_index()];
}

/** @return list<array{path:string,relative:string,device:string,mime:string,size:int,width:int,height:int}> */
function list_images(?string $device = null, ?string $category = null): array
{
    initialize_storage();
    if (!in_array($device, [null, 'pc', 'mobile'], true)) {
        throw new InvalidArgumentException('Invalid image device.');
    }
    if ($category !== null && !valid_category($category)) {
        throw new InvalidArgumentException('Invalid category identifier.');
    }

    $images = [];
    foreach (image_index()['images'] as $item) {
        if (!is_array($item) || !in_array($item['device'] ?? null, ['pc', 'mobile'], true)) continue;
        $itemDevice = (string) $item['device'];
        $relative = ltrim(str_replace('\\', '/', (string) ($item['relative'] ?? '')), '/');
        if ($relative === '' || str_contains($relative, '..') || ($device !== null && $itemDevice !== $device)) continue;
        if ($category !== null && !str_starts_with($relative, $category . '/')) continue;

        $root = $itemDevice === 'mobile' ? IMAGE_MOBILE_DIR : IMAGE_PC_DIR;
        $path = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        $realRoot = realpath($root);
        if ($path === false || $realRoot === false || !str_starts_with($path, $realRoot . DIRECTORY_SEPARATOR) || !is_file($path)) continue;
        $images[] = [
            'path' => $path, 'relative' => $relative, 'device' => $itemDevice,
            'mime' => (string) ($item['mime'] ?? 'application/octet-stream'),
            'size' => max(0, (int) ($item['size'] ?? 0)), 'width' => max(0, (int) ($item['width'] ?? 0)),
            'height' => max(0, (int) ($item['height'] ?? 0)),
        ];
    }
    return $images;
}

/** @return array{path:string,relative:string,device:string,mime:string,size:int,width:int,height:int}|null */
function random_image(string $device, ?string $category = null): ?array
{
    $images = list_images($device === 'auto' ? null : $device, $category);
    if ($images === []) {
        return null;
    }
    return $images[random_int(0, count($images) - 1)];
}

function visitor_ip(): string
{
    return request_client_ip();
}

/** @param array{time:string,ip:string,image:string,status:int,method:string,type:string,bytes:int,device:string,channel:string} $record */
function store_database_access_log(array $record): void
{
    $pdo = runtime_state_database();
    if ($pdo === null) return;
    $timezone = new DateTimeZone('Asia/Shanghai');
    $day = (new DateTimeImmutable($record['time']))->setTimezone($timezone)->format('Y-m-d');
    $statement = $pdo->prepare('INSERT INTO picapi_request_logs (record_id, logged_at, log_day, ip_address, image_path, status_code, request_method, output_type, output_bytes, device, channel) VALUES (:id, :time, :day, :ip, :image, :status, :method, :type, :bytes, :device, :channel)');
    $statement->execute([
        'id' => bin2hex(random_bytes(16)), 'time' => $record['time'], 'day' => $day, 'ip' => $record['ip'], 'image' => $record['image'],
        'status' => $record['status'], 'method' => $record['method'], 'type' => $record['type'], 'bytes' => $record['bytes'],
        'device' => $record['device'], 'channel' => $record['channel'],
    ]);
    increment_database_daily_statistics($pdo, $day, $record['channel'], $record['bytes']);
}

function increment_database_daily_statistics(PDO $pdo, string $day, string $channel, int $bytes): void
{
    $channel = in_array($channel, ['auto', 'pc', 'mobile'], true) ? $channel : 'auto';
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $statement = $pdo->prepare('INSERT INTO picapi_daily_statistics (stat_day, channel, request_count, output_bytes) VALUES (:day, :channel, 1, :bytes) ON DUPLICATE KEY UPDATE request_count = request_count + 1, output_bytes = output_bytes + VALUES(output_bytes)');
        $statement->execute(['day' => $day, 'channel' => $channel, 'bytes' => max(0, $bytes)]);
        return;
    }
    if (in_array($driver, ['sqlite', 'pgsql'], true)) {
        $statement = $pdo->prepare('INSERT INTO picapi_daily_statistics (stat_day, channel, request_count, output_bytes) VALUES (:day, :channel, 1, :bytes) ON CONFLICT (stat_day, channel) DO UPDATE SET request_count = picapi_daily_statistics.request_count + 1, output_bytes = picapi_daily_statistics.output_bytes + EXCLUDED.output_bytes');
        $statement->execute(['day' => $day, 'channel' => $channel, 'bytes' => max(0, $bytes)]);
        return;
    }
    $pdo->beginTransaction();
    try {
        $select = $pdo->prepare('SELECT request_count FROM picapi_daily_statistics WHERE stat_day = :day AND channel = :channel');
        $select->execute(['day' => $day, 'channel' => $channel]);
        if ($select->fetchColumn() === false) {
            $insert = $pdo->prepare('INSERT INTO picapi_daily_statistics (stat_day, channel, request_count, output_bytes) VALUES (:day, :channel, 1, :bytes)');
            $insert->execute(['day' => $day, 'channel' => $channel, 'bytes' => max(0, $bytes)]);
        } else {
            $update = $pdo->prepare('UPDATE picapi_daily_statistics SET request_count = request_count + 1, output_bytes = output_bytes + :bytes WHERE stat_day = :day AND channel = :channel');
            $update->execute(['day' => $day, 'channel' => $channel, 'bytes' => max(0, $bytes)]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** @return list<array{time:string,ip:string,image:string,status:int,method:string,type:string,bytes:int,device:string,channel:string}> */
function database_access_log_records(int $limit = 500): array
{
    try {
        $pdo = runtime_state_database();
        if ($pdo === null) return [];
        $limit = max(1, min(5000, $limit));
        $statement = $pdo->query('SELECT logged_at, ip_address, image_path, status_code, request_method, output_type, output_bytes, device, channel FROM picapi_request_logs ORDER BY logged_at DESC');
        $records = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false && count($records) < $limit) {
            $records[] = ['time' => (string) $row['logged_at'], 'ip' => (string) $row['ip_address'], 'image' => (string) $row['image_path'], 'status' => (int) $row['status_code'], 'method' => (string) $row['request_method'], 'type' => (string) $row['output_type'], 'bytes' => (int) $row['output_bytes'], 'device' => (string) $row['device'], 'channel' => (string) $row['channel']];
        }
        return $records;
    } catch (Throwable) {
        return [];
    }
}

/** @return list<string> */
function database_access_log_dates(): array
{
    try {
        $pdo = runtime_state_database();
        if ($pdo === null) return [];
        $statement = $pdo->query('SELECT DISTINCT log_day FROM picapi_request_logs ORDER BY log_day DESC');
        $days = [];
        while (($day = $statement->fetchColumn()) !== false) {
            $day = (string) $day;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1) $days[] = $day;
        }
        return $days;
    } catch (Throwable) {
        return [];
    }
}

/** @return array<string,array{requests:int,bytes:int,auto:int,pc:int,mobile:int}> */
function database_daily_statistics(array $days): array
{
    $result = [];
    foreach ($days as $day) $result[$day] = ['requests' => 0, 'bytes' => 0, 'auto' => 0, 'pc' => 0, 'mobile' => 0];
    if ($result === []) return $result;
    try {
        $pdo = runtime_state_database();
        if ($pdo === null) return $result;
        $statement = $pdo->query('SELECT stat_day, channel, request_count, output_bytes FROM picapi_daily_statistics');
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $day = (string) ($row['stat_day'] ?? '');
            $channel = (string) ($row['channel'] ?? '');
            if (!isset($result[$day]) || !in_array($channel, ['auto', 'pc', 'mobile'], true)) continue;
            $count = max(0, (int) ($row['request_count'] ?? 0));
            $result[$day]['requests'] += $count;
            $result[$day]['bytes'] += max(0, (int) ($row['output_bytes'] ?? 0));
            $result[$day][$channel] += $count;
        }
    } catch (Throwable) {
        // File logs remain the compatibility fallback for dashboards and exports.
    }
    return $result;
}

function write_access_log(string $imagePath, int $status, string $outputType, int $bytes, string $device, string $channel): void
{
    $time = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
    try {
        // Retention only removes dated access records; counters are stored separately.
        purge_expired_access_logs();
    } catch (Throwable) {
        // A maintenance failure must not prevent image delivery or access logging.
    }
    $directory = STORAGE_DIR . '/logs/' . $time->format('Y/m');
    ensure_directory($directory);
    $record = [
        'time' => $time->format(DATE_ATOM),
        'ip' => visitor_ip(),
        'image' => $imagePath,
        'status' => $status,
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'type' => $outputType,
        'bytes' => $bytes,
        'device' => $device,
        // The endpoint that was requested. It differs from the delivered image pool for auto requests.
        'channel' => in_array($channel, ['auto', 'pc', 'mobile'], true) ? $channel : 'auto',
    ];
    try {
        store_database_access_log($record);
    } catch (Throwable) {
        // The local JSONL log remains the portable fallback when database storage is unavailable.
    }
    $written = file_put_contents(
        $directory . '/' . $time->format('d') . '.jsonl',
        json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
    if ($written === false) {
        throw new RuntimeException('Unable to write access log.');
    }
    write_compatible_endpoint_log($record);
    record_public_request_rate_alert($record['ip']);
}

function upload_audit_log_root(): string
{
    return STORAGE_DIR . '/upload-logs';
}

/** @param array{time:string,name:string,relative:string,category:string,device:string,width:int,height:int,size:int,source:string} $record */
function store_database_upload_audit(array $record): void
{
    $pdo = runtime_state_database();
    if ($pdo === null) return;
    $day = (new DateTimeImmutable($record['time']))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
    $statement = $pdo->prepare('INSERT INTO picapi_upload_audits (record_id, logged_at, log_day, file_name, relative_path, category_alias, device, width, height, file_size, source) VALUES (:id, :time, :day, :name, :relative, :category, :device, :width, :height, :size, :source)');
    $statement->execute([
        'id' => bin2hex(random_bytes(16)), 'time' => $record['time'], 'day' => $day, 'name' => $record['name'], 'relative' => $record['relative'],
        'category' => $record['category'], 'device' => $record['device'], 'width' => $record['width'], 'height' => $record['height'],
        'size' => $record['size'], 'source' => $record['source'],
    ]);
}

/** @return list<array{time:string,name:string,relative:string,category:string,device:string,width:int,height:int,size:int,source:string}> */
function database_upload_audit_records(): array
{
    try {
        $pdo = runtime_state_database();
        if ($pdo === null) return [];
        $statement = $pdo->query('SELECT logged_at, file_name, relative_path, category_alias, device, width, height, file_size, source FROM picapi_upload_audits ORDER BY logged_at DESC');
        $records = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $records[] = ['time' => (string) $row['logged_at'], 'name' => (string) $row['file_name'], 'relative' => (string) $row['relative_path'], 'category' => (string) $row['category_alias'], 'device' => (string) $row['device'] === 'mobile' ? 'mobile' : 'pc', 'width' => max(0, (int) $row['width']), 'height' => max(0, (int) $row['height']), 'size' => max(0, (int) $row['file_size']), 'source' => (string) $row['source']];
        }
        return $records;
    } catch (Throwable) {
        return [];
    }
}

function write_upload_audit_record(string $device, string $relativePath, int $width, int $height, int $size, string $source = 'upload', ?int $timestamp = null): void
{
    if (!in_array($device, ['pc', 'mobile'], true)) {
        throw new InvalidArgumentException('Invalid upload audit device.');
    }
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        throw new InvalidArgumentException('Invalid upload audit path.');
    }

    $root = upload_audit_log_root();
    ensure_directory($root);
    $time = $timestamp === null
        ? new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'))
        : (new DateTimeImmutable('@' . max(0, $timestamp)))->setTimezone(new DateTimeZone('Asia/Shanghai'));
    $folder = dirname($relativePath);
    $record = [
        'time' => $time->format(DATE_ATOM),
        'name' => basename($relativePath),
        'relative' => $relativePath,
        'category' => $folder === '.' || $folder === '' ? 'default' : $folder,
        'device' => $device,
        'width' => max(0, $width),
        'height' => max(0, $height),
        'size' => max(0, $size),
        'source' => $source,
    ];
    try {
        store_database_upload_audit($record);
    } catch (Throwable) {
        // The local audit file remains a portable fallback if database storage fails.
    }
    $directory = $root . '/' . $time->format('Y/m');
    ensure_directory($directory);
    if (file_put_contents($directory . '/' . $time->format('d') . '.jsonl', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Unable to write upload audit log.');
    }
    try {
        purge_expired_upload_audit_logs();
    } catch (Throwable) {
        // Upload success must not depend on optional log maintenance.
    }
}

function record_upload_audit_safely(string $device, string $relativePath, int $width, int $height, int $size, string $source = 'upload', ?int $timestamp = null): void
{
    try {
        write_upload_audit_record($device, $relativePath, $width, $height, $size, $source, $timestamp);
    } catch (Throwable) {
        // Image storage remains successful even when the optional audit log is unavailable.
    }
}

/** @param list<array{path:string,relative:string,device:string,mime:string,size:int,width:int,height:int}> $images */
function seed_upload_audit_records(array $images): void
{
    $root = upload_audit_log_root();
    ensure_directory($root);
    $marker = $root . '/.seeded.json';
    if (is_file($marker)) return;

    $lock = @fopen($root . '/.seed.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return;
    }
    try {
        if (is_file($marker)) return;
        // New installations can receive uploads before the audit page is opened.
        // Do not create migration duplicates when structured audit records exist.
        if ((glob($root . '/*/*/*.jsonl') ?: []) === []) {
            $batches = [];
            $timezone = new DateTimeZone('Asia/Shanghai');
            foreach ($images as $image) {
                $timestamp = @filemtime($image['path']);
                if ($timestamp === false) continue;
                $time = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
                $relative = ltrim(str_replace('\\', '/', $image['relative']), '/');
                $folder = dirname($relative);
                $record = [
                    'time' => $time->format(DATE_ATOM),
                    'name' => basename($relative),
                    'relative' => $relative,
                    'category' => $folder === '.' || $folder === '' ? 'default' : $folder,
                    'device' => $image['device'],
                    'width' => (int) $image['width'],
                    'height' => (int) $image['height'],
                    'size' => (int) $image['size'],
                    'source' => 'migration',
                ];
                $path = $root . '/' . $time->format('Y/m/d') . '.jsonl';
                $batches[$path][] = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
            foreach ($batches as $path => $records) {
                ensure_directory(dirname($path));
                if (file_put_contents($path, implode(PHP_EOL, $records) . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
                    throw new RuntimeException('Unable to seed upload audit log.');
                }
            }
        }
        file_put_contents($marker, json_encode(['seeded_at' => gmdate('c')], JSON_UNESCAPED_SLASHES), LOCK_EX);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    try {
        purge_expired_upload_audit_logs();
    } catch (Throwable) {
        // A migration remains useful even if cleanup cannot run immediately.
    }
}

/** @return list<array{time:string,name:string,relative:string,category:string,device:string,width:int,height:int,size:int,source:string}> */
function list_upload_audit_records(): array
{
    $records = database_upload_audit_records();
    $dedupe = [];
    foreach ($records as $record) $dedupe[$record['time'] . '|' . $record['device'] . '|' . $record['relative']] = true;
    $files = glob(upload_audit_log_root() . '/*/*/*.jsonl') ?: [];
    rsort($files, SORT_STRING);
    foreach ($files as $path) {
        $handle = @fopen($path, 'rb');
        if ($handle === false) continue;
        while (($line = fgets($handle)) !== false) {
            $record = json_decode($line, true);
            if (!is_array($record) || !is_string($record['time'] ?? null) || !is_string($record['name'] ?? null)) continue;
            $normalized = [
                'time' => $record['time'],
                'name' => $record['name'],
                'relative' => is_string($record['relative'] ?? null) ? $record['relative'] : $record['name'],
                'category' => is_string($record['category'] ?? null) ? $record['category'] : 'default',
                'device' => ($record['device'] ?? null) === 'mobile' ? 'mobile' : 'pc',
                'width' => max(0, (int) ($record['width'] ?? 0)),
                'height' => max(0, (int) ($record['height'] ?? 0)),
                'size' => max(0, (int) ($record['size'] ?? 0)),
                'source' => is_string($record['source'] ?? null) ? $record['source'] : 'upload',
            ];
            $key = $normalized['time'] . '|' . $normalized['device'] . '|' . $normalized['relative'];
            if (!isset($dedupe[$key])) {
                $records[] = $normalized;
                $dedupe[$key] = true;
            }
        }
        fclose($handle);
    }
    usort($records, static fn(array $left, array $right): int => strcmp($right['time'], $left['time']));
    return $records;
}

/** @param array{ip:string,image:string,status:int,method:string,type:string,channel:string} $record */
function write_compatible_endpoint_log(array $record): void
{
    $time = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
    $directory = endpoint_log_directory((string) $record['channel']) . '/' . $time->format('Y/m');
    ensure_directory($directory);
    $entry = sprintf(
        "[%s] IP: %s | Image: %s | Status: %d | Method: %s | Type: %s" . PHP_EOL,
        $time->format('Y-m-d H:i:s'),
        $record['ip'],
        $record['image'],
        $record['status'],
        $record['method'],
        $record['type'],
    );
    if (file_put_contents($directory . '/' . $time->format('d') . '.log', $entry, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Unable to write compatible endpoint log.');
    }
}

/** @return array{total:int,devices:array{auto:int,pc:int,mobile:int},days:array<string,array{total:int,auto:int,pc:int,mobile:int}>,updated_at:string} */
function normalized_request_counter_state(array $state): array
{
    $devices = is_array($state['devices'] ?? null) ? $state['devices'] : [];
    $normalizedDevices = [];
    foreach (['auto', 'pc', 'mobile'] as $channel) {
        $normalizedDevices[$channel] = max(0, (int) ($devices[$channel] ?? 0));
    }

    $normalizedDays = [];
    $days = is_array($state['days'] ?? null) ? $state['days'] : [];
    foreach ($days as $day => $counts) {
        if (!is_string($day) || !is_array($counts)) continue;
        $normalizedDays[$day] = [
            'total' => max(0, (int) ($counts['total'] ?? 0)),
            'auto' => max(0, (int) ($counts['auto'] ?? 0)),
            'pc' => max(0, (int) ($counts['pc'] ?? 0)),
            'mobile' => max(0, (int) ($counts['mobile'] ?? 0)),
        ];
    }

    return [
        'total' => max(max(0, (int) ($state['total'] ?? 0)), array_sum($normalizedDevices)),
        'devices' => $normalizedDevices,
        'days' => $normalizedDays,
        'updated_at' => is_string($state['updated_at'] ?? null) ? $state['updated_at'] : '',
    ];
}

/** @return array{total:int,devices:array{auto:int,pc:int,mobile:int},days:array<string,array{total:int,auto:int,pc:int,mobile:int}>,updated_at:string} */
function request_counter_compatibility_state(): array
{
    $path = STORAGE_DIR . '/counters.json';
    $stored = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    return normalized_request_counter_state(is_array($stored) ? $stored : []);
}

/** @param array{total:int,devices:array{auto:int,pc:int,mobile:int},days:array<string,array{total:int,auto:int,pc:int,mobile:int}>,updated_at:string} $state */
function persist_request_counter_compatibility_state(array $state): void
{
    $path = STORAGE_DIR . '/counters.json';
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to persist call counter.');
    }
}

/** @return array{total:int,devices:array{auto:int,pc:int,mobile:int},days:array<string,array{total:int,auto:int,pc:int,mobile:int}>,updated_at:string} */
function request_counter_state(): array
{
    $fallback = request_counter_compatibility_state();
    try {
        $stored = with_database_runtime_state('request-counters', $fallback, static function (array &$state): array {
            $state = normalized_request_counter_state($state);
            return $state;
        });
        if (is_array($stored)) return normalized_request_counter_state($stored);
    } catch (Throwable) {
        // The JSON snapshot remains available before setup and as a database fallback.
    }
    return $fallback;
}

function increment_call_count(string $device): int
{
    if (!in_array($device, ['auto', 'pc', 'mobile'], true)) {
        throw new InvalidArgumentException('Invalid request counter channel.');
    }
    initialize_storage();
    $lock = fopen(STORAGE_DIR . '/counter.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Unable to lock call counter.');
    }
    try {
        $fallback = request_counter_compatibility_state();
        $increment = static function (array &$state) use ($device): array {
            $state = normalized_request_counter_state($state);
            $previousDeviceCount = $state['devices'][$device];
            $state['total']++;
            $state['devices'][$device] = $previousDeviceCount + 1;
            $day = endpoint_request_day();
            $state['days'][$day] = $state['days'][$day] ?? ['total' => 0, 'auto' => 0, 'pc' => 0, 'mobile' => 0];
            $state['days'][$day]['total']++;
            $state['days'][$day][$device]++;
            $state['updated_at'] = gmdate('c');
            return ['state' => $state, 'previous_device_count' => $previousDeviceCount];
        };
        try {
            $result = with_database_runtime_state('request-counters', $fallback, $increment);
        } catch (Throwable) {
            $result = null;
        }
        if (!is_array($result) || !is_array($result['state'] ?? null)) {
            $state = $fallback;
            $result = $increment($state);
        }
        $stored = normalized_request_counter_state($result['state']);
        $previousDeviceCount = max(0, (int) ($result['previous_device_count'] ?? 0));
        persist_request_counter_compatibility_state($stored);

        if (file_put_contents(STORAGE_DIR . '/logs/' . endpoint_request_day() . '.count', (string) $stored['total'], LOCK_EX) === false) {
            throw new RuntimeException('Unable to write daily counter.');
        }
        if (file_put_contents(STORAGE_DIR . '/logs/' . endpoint_request_day() . '.' . $device . '.count', (string) $stored['devices'][$device], LOCK_EX) === false) {
            throw new RuntimeException('Unable to write endpoint daily counter.');
        }
        // Categories never create independent counters. Each request is merged
        // into exactly one public entry point: auto, pc, or mobile.
        increment_endpoint_request_counter($device, $previousDeviceCount);
        return $stored['total'];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function endpoint_log_directory(string $channel): string
{
    return match ($channel) {
        'pc' => APP_ROOT . '/pc/log',
        'mobile' => APP_ROOT . '/m/log',
        default => APP_ROOT . '/log',
    };
}

function endpoint_request_day(int $offset = 0): string
{
    $day = new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai'));
    return $offset === 0 ? $day->format('Y-m-d') : $day->modify($offset . ' day')->format('Y-m-d');
}

/** @return array{today:int,yesterday:int,total:int,updated_at:string} */
function endpoint_request_statistics(string $channel): array
{
    static $loaded = [];
    if (isset($loaded[$channel])) return $loaded[$channel];
    $path = endpoint_log_directory($channel) . '/requests.json';
    if (!is_file($path)) {
        $historical = endpoint_historical_request_statistics($channel);
        $stored = ['total' => $historical['total'], 'days' => $historical['days'], 'updated_at' => gmdate('c')];
        $lock = @fopen(STORAGE_DIR . '/counter.lock', 'c+');
        if ($lock !== false && flock($lock, LOCK_EX)) {
            try {
                if (!is_file($path)) {
                    ensure_directory(dirname($path));
                    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
                    if (file_put_contents($temporary, json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !rename($temporary, $path)) {
                        @unlink($temporary);
                    }
                }
                if (is_file($path)) {
                    $persisted = json_decode((string) file_get_contents($path), true);
                    if (is_array($persisted)) $stored = $persisted;
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } elseif (is_resource($lock)) {
            fclose($lock);
        }
    } else {
        $stored = json_decode((string) file_get_contents($path), true);
    }
    $stored = is_array($stored) ? $stored : [];
    $days = is_array($stored['days'] ?? null) ? $stored['days'] : [];
    $today = endpoint_request_day();
    $yesterday = endpoint_request_day(-1);

    return $loaded[$channel] = [
        'today' => max(0, (int) ($days[$today] ?? 0)),
        'yesterday' => max(0, (int) ($days[$yesterday] ?? 0)),
        'total' => max(0, (int) ($stored['total'] ?? 0)),
        'updated_at' => (string) ($stored['updated_at'] ?? ''),
    ];
}

/** @return array{today:int,yesterday:int,total:int,updated_at:string,days:array<string,int>} */
function endpoint_historical_request_statistics(string $channel, ?int $counterTotal = null): array
{
    $days = [];
    $logTotal = 0;
    foreach (glob(STORAGE_DIR . '/logs/*/*/*.jsonl') ?: [] as $path) {
        $handle = @fopen($path, 'rb');
        if ($handle === false) continue;
        while (($line = fgets($handle)) !== false) {
            $record = json_decode($line, true);
            if (!is_array($record)) continue;
            $recordChannel = in_array($record['channel'] ?? null, ['auto', 'pc', 'mobile'], true)
                ? (string) $record['channel']
                : ((string) ($record['device'] ?? '') === 'mobile' ? 'mobile' : ((string) ($record['device'] ?? '') === 'pc' ? 'pc' : 'auto'));
            if ($recordChannel !== $channel) continue;
            $logTotal++;
            try {
                $day = (new DateTimeImmutable((string) ($record['time'] ?? 'now')))
                    ->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
                $days[$day] = max(0, (int) ($days[$day] ?? 0)) + 1;
            } catch (Throwable) {
                // Ignore malformed legacy timestamps while retaining their total count.
            }
        }
        fclose($handle);
    }
    if ($counterTotal === null) {
        $counters = is_file(STORAGE_DIR . '/counters.json') ? json_decode((string) file_get_contents(STORAGE_DIR . '/counters.json'), true) : [];
        $counterTotal = is_array($counters) && is_array($counters['devices'] ?? null)
            ? max(0, (int) ($counters['devices'][$channel] ?? 0))
            : 0;
    }
    $today = endpoint_request_day();
    $yesterday = endpoint_request_day(-1);
    return [
        'today' => max(0, (int) ($days[$today] ?? 0)),
        'yesterday' => max(0, (int) ($days[$yesterday] ?? 0)),
        'total' => max($logTotal, $counterTotal),
        'updated_at' => '',
        'days' => $days,
    ];
}

function increment_endpoint_request_counter(string $channel, ?int $previousCounterTotal = null): void
{
    $directory = endpoint_log_directory($channel);
    ensure_directory($directory);
    $path = $directory . '/requests.json';
    $stored = is_file($path)
        ? json_decode((string) file_get_contents($path), true)
        : endpoint_historical_request_statistics($channel, $previousCounterTotal);
    $stored = is_array($stored) ? $stored : [];
    $stored['total'] = max(0, (int) ($stored['total'] ?? 0)) + 1;
    $stored['days'] = is_array($stored['days'] ?? null) ? $stored['days'] : [];
    $today = endpoint_request_day();
    $stored['days'][$today] = max(0, (int) ($stored['days'][$today] ?? 0)) + 1;
    $stored['updated_at'] = gmdate('c');

    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to persist endpoint counter.');
    }
    // Keep a local, human-readable counter beside the exact public endpoint:
    // /log for adaptive, /pc/log for PC, and /m/log for mobile.
    write_compatible_count_files($directory, (int) $stored['total']);
}

function write_compatible_count_files(string $directory, int $count): void
{
    ensure_directory($directory);
    if (file_put_contents($directory . '/count.log', (string) max(0, $count), LOCK_EX) === false) {
        throw new RuntimeException('Unable to write compatible call count.');
    }
    $time = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
    $archiveDirectory = $directory . '/' . $time->format('Y/m');
    ensure_directory($archiveDirectory);
    $backup = $archiveDirectory . '/count_' . $time->format('Y-m-d') . '.bak';
    if (file_put_contents($backup, (string) max(0, $count), LOCK_EX) === false) {
        throw new RuntimeException('Unable to write compatible count backup.');
    }
    $history = $directory . '/count_history.log';
    $entry = '[' . $time->format('Y-m-d H:i:s') . '] Count: ' . max(0, $count) . PHP_EOL;
    if (file_put_contents($history, $entry, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Unable to write compatible count history.');
    }
    if (($size = @filesize($history)) !== false && $size > 262144) {
        $lines = file($history, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if (count($lines) > 2000 && file_put_contents($history, implode(PHP_EOL, array_slice($lines, -2000)) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to trim compatible count history.');
        }
    }
}

function format_file_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $value = (float) $bytes;
    foreach ($units as $index => $unit) {
        if ($value < 1024 || $index === count($units) - 1) {
            return round($value, 2) . ' ' . $unit;
        }
        $value /= 1024;
    }
    return $bytes . ' B';
}
