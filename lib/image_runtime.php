<?php

function image_api_ensure_directory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException(sprintf('Directory "%s" was not created', $path));
    }
}

function image_api_get_request_ip(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        $candidate = trim($parts[0]);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
}

function image_api_write_log(
    string $logFolder,
    string $imagePath,
    int $statusCode,
    string $requestMethod,
    string $type
): void {
    $logDir = rtrim($logFolder, '/\\') . DIRECTORY_SEPARATOR . date('Y/m/');
    image_api_ensure_directory($logDir);

    $logLine = sprintf(
        "[%s] IP: %s | image: %s | status: %d | method: %s | type: %s%s",
        date('Y-m-d H:i:s'),
        image_api_get_request_ip(),
        $imagePath,
        $statusCode,
        $requestMethod,
        $type,
        PHP_EOL
    );

    file_put_contents($logDir . date('d') . '.log', $logLine, FILE_APPEND | LOCK_EX);
}

function image_api_read_counter_value(string $path): int
{
    if (!is_file($path) || !is_readable($path)) {
        return 0;
    }

    $value = trim((string) file_get_contents($path));
    return ctype_digit($value) ? (int) $value : 0;
}

function image_api_get_last_history_count(array $lines): int
{
    if (empty($lines)) {
        return 0;
    }

    $lastLine = end($lines);
    if (preg_match('/(\d+)\s*$/', (string) $lastLine, $matches) === 1) {
        return (int) $matches[1];
    }

    return 0;
}

function image_api_update_call_count(
    string $logFolder,
    string $statLogFile,
    string $countLockFile,
    int $maxHistoryLines = 2000
): void {
    image_api_ensure_directory($logFolder);

    $historyLog = rtrim($logFolder, '/\\') . DIRECTORY_SEPARATOR . 'count_history.log';
    $lockHandle = fopen($countLockFile, 'c+');
    if ($lockHandle === false) {
        return;
    }

    try {
        if (!flock($lockHandle, LOCK_EX)) {
            return;
        }

        $lines = is_readable($historyLog)
            ? file($historyLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            : [];

        $fileCount = image_api_read_counter_value($statLogFile);
        $backupCount = image_api_get_last_history_count($lines);
        $newCount = max($fileCount, $backupCount) + 1;

        file_put_contents($statLogFile, (string) $newCount, LOCK_EX);

        $lines[] = sprintf('[%s] count: %d', date('Y-m-d H:i:s'), $newCount);
        if (count($lines) > $maxHistoryLines) {
            $lines = array_slice($lines, -$maxHistoryLines);
        }
        file_put_contents($historyLog, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);

        $backupDir = rtrim($logFolder, '/\\') . DIRECTORY_SEPARATOR . date('Y/m/');
        image_api_ensure_directory($backupDir);
        file_put_contents($backupDir . 'count_' . date('Y-m-d') . '.bak', (string) $newCount, LOCK_EX);
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function image_api_get_image_info(string $imagePath): array
{
    if (!is_readable($imagePath)) {
        throw new RuntimeException(sprintf('Image file is not readable: %s', $imagePath));
    }

    $size = filesize($imagePath);
    $imageSize = @getimagesize($imagePath);

    return [
        'path' => $imagePath,
        'size' => $size === false ? 0 : $size,
        'width' => $imageSize[0] ?? 0,
        'height' => $imageSize[1] ?? 0,
    ];
}

function image_api_format_file_size(int $size): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float) $size;
    $index = 0;

    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }

    return round($value, 2) . ' ' . $units[$index];
}

function image_api_detect_mime_type(string $imagePath): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mimeType = finfo_file($finfo, $imagePath);
            finfo_close($finfo);
            if (is_string($mimeType) && $mimeType !== '') {
                return $mimeType;
            }
        }
    }

    $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
    $fallbackMap = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    return $fallbackMap[$extension] ?? 'application/octet-stream';
}

function image_api_encode_public_path(string $path): string
{
    $segments = explode('/', str_replace('\\', '/', $path));
    return implode('/', array_map('rawurlencode', $segments));
}

function image_api_is_favicon_request(): bool
{
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    if ($requestUri === '') {
        return false;
    }

    $path = parse_url($requestUri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return false;
    }

    return strtolower(basename($path)) === 'favicon.ico';
}

function image_api_handle_favicon_request(): void
{
    if (!image_api_is_favicon_request()) {
        return;
    }

    http_response_code(204);
    header('Content-Type: image/x-icon');
    header('Cache-Control: public, max-age=86400');
    exit;
}

function image_api_find_images(string $absoluteRoot, string $publicPrefix): array
{
    if (!is_dir($absoluteRoot)) {
        return [];
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $catalog = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $extension = strtolower($fileInfo->getExtension());
        if (!in_array($extension, $allowedExtensions, true)) {
            continue;
        }

        $relativePath = substr($fileInfo->getPathname(), strlen($absoluteRoot));
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $publicPath = trim(str_replace('\\', '/', $publicPrefix), '/');
        if ($relativePath !== '') {
            $publicPath .= '/' . $relativePath;
        }

        $catalog[] = [
            'fs_path' => $fileInfo->getPathname(),
            'public_path' => $publicPath,
            'basename' => $fileInfo->getBasename(),
        ];
    }

    return $catalog;
}

function image_api_pick_random_image(array $catalog): ?array
{
    if (empty($catalog)) {
        return null;
    }

    return $catalog[array_rand($catalog)];
}

function image_api_normalize_relative_path(string $path): string
{
    return trim(str_replace('\\', '/', $path), '/');
}
