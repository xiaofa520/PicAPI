<?php
declare(strict_types=1);
require_once __DIR__ . '/../../src/dashboard_runtime.php';
initialize_storage();
require_admin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode(dashboard_statistics(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
