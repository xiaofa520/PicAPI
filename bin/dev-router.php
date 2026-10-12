<?php
declare(strict_types=1);
// Local preview: php -S 127.0.0.1:8768 -t public bin/dev-router.php
// The built-in server ignores .htaccess, so protect private files explicitly.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$root = realpath(dirname(__DIR__) . '/public');
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if (str_contains($path, '\\') || str_contains($path, "\0") || preg_match('~(?:^|/)\.{1,2}(?:/|$)|(?:^|/)\.~', $path)) { http_response_code(404); exit; }
if ($path === '/admin') {
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: /admin/' . ($query === '' ? '' : '?' . $query), true, 308);
    exit;
}
if ($path === '/favicon.ico' || $path === '/api/v1/random' || $path === '/api/v1/random/') { require $root . '/index.php'; return true; }
if ($path === '/setup.txt'
    || !preg_match('~^/(?:$|index\.php$|favicon\.ico$|(?:admin|docs|pc|m)/)~', $path)
    || preg_match('~/(?:log|database|storage)/|_view\.php$|\.(?:json|jsonl|sqlite|sqlite3|db|log|bak|lock|tmp)$~i', $path)) { http_response_code(404); exit; }
$file = realpath($root . $path);
if ($file === false || ($file !== $root && !str_starts_with(strtolower($file), strtolower($root . DIRECTORY_SEPARATOR)))) { http_response_code(404); exit; }
if (is_dir($file) && !is_file($file . '/index.php')) { http_response_code(404); exit; }
return false;
