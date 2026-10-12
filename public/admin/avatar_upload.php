<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/image_runtime.php';
require_once __DIR__ . '/../../src/settings_runtime.php';

initialize_storage();
require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') abort_request(405, 'Method not allowed.');
verify_csrf();

header('Content-Type: application/json; charset=utf-8');
try {
    save_admin_avatar_upload(is_array($_FILES['avatar'] ?? null) ? $_FILES['avatar'] : []);
    echo json_encode(['ok' => true, 'url' => admin_avatar_url()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_SLASHES);
}
