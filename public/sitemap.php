<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/settings_runtime.php';

$settings = site_settings();
$base = rtrim((string) (($settings['site_url'] ?? '') ?: application_base_url()), '/');
if ($base === '' || filter_var($base, FILTER_VALIDATE_URL) === false) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Set a valid public site URL in the admin settings to publish the sitemap.';
    exit;
}

$locations = [$base . '/docs/'];
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($locations as $location) {
    echo '  <url><loc>' . htmlspecialchars($location, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc></url>\n";
}
echo "</urlset>\n";
