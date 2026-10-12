<?php
declare(strict_types=1);
require_once __DIR__ . '/../../src/settings_runtime.php';
initialize_storage();
require_admin();
send_admin_security_headers();
header('Cache-Control: no-store');
$workbenchRoute = ['stats'=>'overview','gallery'=>'gallery','folders'=>'categories','upload'=>'upload','logs'=>'logs','ip_control'=>'security','settings'=>'settings','api'=>'api','docs'=>'docs','login-logs'=>'login-logs'][$requestedPage ?? 'stats'] ?? 'overview';
require __DIR__ . '/../../templates/admin/workbench_view.php';
