<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/image_runtime.php';

initialize_storage();
require_admin();

$path = admin_avatar_path();
if ($path === null) abort_request(404, 'Avatar not found.');

$extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
$mime = match ($extension) {
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    default => '',
};
if ($mime === '') abort_request(404, 'Avatar not found.');

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) (filesize($path) ?: 0));
header('Cache-Control: private, no-store');
readfile($path);
