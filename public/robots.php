<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/settings_runtime.php';

$settings = site_settings();
$base = rtrim((string) (($settings['site_url'] ?? '') ?: application_base_url()), '/');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo "User-agent: *\n";
echo "Disallow: /admin/\n";
echo "Disallow: /database/\n";
echo "Disallow: /storage/\n";
echo "Disallow: /log/\n";
echo "Disallow: /m/log/\n";
echo "Disallow: /pc/log/\n";
echo "\nAllow: /docs/\n";
if ($base !== '') echo 'Sitemap: ' . $base . "/sitemap.xml\n";
