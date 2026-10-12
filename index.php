<?php
declare(strict_types=1);

/**
 * PicAPI root front controller.
 *
 * Root-directory deployments and `php -S ... index.php` send every request
 * through this file. Public implementations stay under public/; URLs remain
 * short and do not expose that directory in the address bar.
 *
 * Public routes:
 *   / and /api/v1/random  -> image API
 *   /pc/                  -> PC image API and public PC assets
 *   /m/                   -> mobile image API and public mobile assets
 *   /admin/               -> dashboard, setup, login, and admin APIs
 *   /docs/                -> published API documentation
 *   /robots.txt, /favicon.ico, and approved static assets
 *
 * Start the built-in server from this directory with:
 *   php -S 127.0.0.1:80 index.php
 * Omitting `index.php` disables this router in PHP's built-in server.
 */

$publicRoot = realpath(__DIR__ . '/public');
$requestPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

$notFound = static function (): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
};

// Keep the public URL map here so all supported application areas are visible
// at the project entry point. Each matching path is resolved only inside public/.
$publicRouteGroups = [
    'admin' => '管理后台、初始化和管理 API',
    'docs' => '公开 API 文档',
    'pc' => 'PC 图片接口与图片资源',
    'm' => '手机图片接口与图片资源',
];
$publicRootFiles = ['index.php', 'login.php', 'robots.txt', 'favicon.ico'];
$publicAliases = [
    '/' => '/index.php',
    '/favicon.ico' => '/index.php',
    '/robots.txt' => '/robots.php',
    '/sitemap.xml' => '/sitemap.php',
    '/api/v1/random' => '/index.php',
    '/api/v1/random/' => '/index.php',
];

$routeGroupPattern = implode('|', array_map(static fn(string $name): string => preg_quote($name, '~'), array_keys($publicRouteGroups)));
$isPublicGroupRoute = preg_match('~^/(?:' . $routeGroupPattern . ')(?:/|$)~', $requestPath) === 1;
$isPublicRootFile = in_array(ltrim($requestPath, '/'), $publicRootFiles, true);
$isAlias = array_key_exists($requestPath, $publicAliases);

if ($publicRoot === false
    || !str_starts_with($requestPath, '/')
    || str_contains($requestPath, '\\')
    || preg_match('~[\x00-\x1f\x7f]|(?:^|/)\.|//~', $requestPath) === 1
    || (!$isPublicGroupRoute && !$isPublicRootFile && !$isAlias)
    || preg_match('~/(?:storage|database|log)(?:/|$)|_view\.php$~i', $requestPath) === 1) {
    $notFound();
}

if ($isAlias) $requestPath = $publicAliases[$requestPath];
$targetPath = realpath($publicRoot . $requestPath);
if ($targetPath === false || !str_starts_with($targetPath, $publicRoot . DIRECTORY_SEPARATOR)) $notFound();

if (is_dir($targetPath)) {
    if (!str_ends_with($requestPath, '/')) {
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if (preg_match('~[\x00-\x20\x7f]~', $query) === 1) $query = '';
        $location = str_replace('%2F', '/', rawurlencode($requestPath)) . '/';
        if ($query !== '') $location .= '?' . $query;
        header('Location: ' . $location, true, 308);
        exit;
    }
    if (!is_file($targetPath . '/index.php')) $notFound();
    $targetPath = realpath($targetPath . '/index.php');
    $requestPath .= 'index.php';
}

if ($targetPath === false || !is_file($targetPath) || !str_starts_with($targetPath, $publicRoot . DIRECTORY_SEPARATOR)) $notFound();

if (strtolower(pathinfo($targetPath, PATHINFO_EXTENSION)) === 'php') {
    // Preserve controller-relative redirects and include behavior.
    $_SERVER['SCRIPT_NAME'] = $requestPath;
    $_SERVER['PHP_SELF'] = $requestPath;
    $_SERVER['SCRIPT_FILENAME'] = $targetPath;
    require $targetPath;
    return;
}

$contentTypes = [
    'css' => 'text/css; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'avif' => 'image/avif',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'txt' => 'text/plain; charset=utf-8',
];
$extension = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
if (!isset($contentTypes[$extension])) $notFound();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

header('Content-Type: ' . $contentTypes[$extension]);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($targetPath));
if ($method !== 'HEAD') readfile($targetPath);
