<?php

require_once __DIR__ . '/image_runtime.php';

function public_api_default_category_name(): string
{
    return 'default';
}

function public_api_category_file(string $projectRoot): string
{
    return rtrim($projectRoot, '/\\') . '/admin/data/categories.json';
}

function public_api_endpoint_settings_file(string $projectRoot): string
{
    return rtrim($projectRoot, '/\\') . '/admin/data/api_settings.json';
}

function public_api_endpoint_state_file(string $projectRoot): string
{
    return rtrim($projectRoot, '/\\') . '/admin/data/endpoint_state.json';
}

function public_api_validate_category_name(string $name): bool
{
    return preg_match('/^[A-Za-z0-9_-]+$/', $name) === 1;
}

function public_api_default_settings(): array
{
    return [
        'shared' => [
            'type_param' => 'type',
            'type_value' => 'json',
            'category_param' => 'category',
            'missing_category_behavior' => 'legacy',
        ],
        'endpoints' => [
            'root' => ['mode' => 'random', 'template' => public_api_default_template()],
            'm' => ['mode' => 'random', 'template' => public_api_default_template()],
            'pc' => ['mode' => 'random', 'template' => public_api_default_template()],
        ],
    ];
}

function public_api_default_template(): string
{
    return <<<'JSON'
{
  "url": {{url}},
  "size": {{size}},
  "width": {{width}},
  "height": {{height}},
  "category": {{category}}
}
JSON;
}

function public_api_read_settings(string $projectRoot): array
{
    $defaults = public_api_default_settings();
    $path = public_api_endpoint_settings_file($projectRoot);
    if (!is_file($path)) {
        return $defaults;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    $shared = is_array($decoded['shared'] ?? null) ? $decoded['shared'] : [];
    $endpoints = is_array($decoded['endpoints'] ?? null) ? $decoded['endpoints'] : [];

    $typeParam = trim((string) ($shared['type_param'] ?? $shared['type_key'] ?? 'type'));
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $typeParam)) {
        $typeParam = $defaults['shared']['type_param'];
    }

    $typeValue = trim((string) ($shared['type_value'] ?? $shared['type'] ?? 'json'));
    if ($typeValue === '') {
        $typeValue = $defaults['shared']['type_value'];
    }

    $categoryParam = trim((string) ($shared['category_param'] ?? $defaults['shared']['category_param']));
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $categoryParam)) {
        $categoryParam = $defaults['shared']['category_param'];
    }

    $settings = [
        'shared' => [
            'type_param' => $typeParam,
            'type_value' => $typeValue,
            'category_param' => $categoryParam,
            'missing_category_behavior' => 'legacy',
        ],
        'endpoints' => $defaults['endpoints'],
    ];

    foreach (['root', 'm', 'pc'] as $endpointKey) {
        $mode = (string) ($endpoints[$endpointKey]['mode'] ?? $defaults['endpoints'][$endpointKey]['mode']);
        $settings['endpoints'][$endpointKey]['mode'] = in_array($mode, ['random', 'sequential'], true)
            ? $mode
            : $defaults['endpoints'][$endpointKey]['mode'];
        $template = trim((string) ($endpoints[$endpointKey]['template'] ?? $defaults['endpoints'][$endpointKey]['template']));
        $settings['endpoints'][$endpointKey]['template'] = $template !== ''
            ? $template
            : $defaults['endpoints'][$endpointKey]['template'];
    }

    return $settings;
}

function public_api_read_categories(string $projectRoot): array
{
    $categories = [public_api_default_category_name()];
    $path = public_api_category_file($projectRoot);
    if (is_file($path)) {
        $raw = file_get_contents($path);
        $decoded = json_decode($raw ?: '[]', true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $name = trim((string) ($item['name'] ?? ''));
                if ($name !== '' && public_api_validate_category_name($name)) {
                    $categories[] = $name;
                }
            }
        }
    }

    foreach (['pc/img', 'm/img'] as $relativeRoot) {
        $absoluteRoot = rtrim($projectRoot, '/\\') . '/' . $relativeRoot;
        if (!is_dir($absoluteRoot)) {
            continue;
        }

        foreach (scandir($absoluteRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!public_api_validate_category_name($entry)) {
                continue;
            }

            if (is_dir($absoluteRoot . '/' . $entry)) {
                $categories[] = $entry;
            }
        }
    }

    $categories = array_values(array_unique($categories));
    usort($categories, static function (string $left, string $right): int {
        $default = public_api_default_category_name();
        if ($left === $default && $right !== $default) {
            return -1;
        }
        if ($right === $default && $left !== $default) {
            return 1;
        }

        return strcasecmp($left, $right);
    });

    return $categories;
}

function public_api_requested_category(array $settings): ?string
{
    $configuredParam = (string) ($settings['shared']['category_param'] ?? 'category');
    $raw = trim((string) ($_GET[$configuredParam] ?? ''));
    if ($raw === '' && $configuredParam !== 'category') {
        $raw = trim((string) ($_GET['category'] ?? ''));
    }

    return $raw === '' ? null : $raw;
}

function public_api_requested_type(array $settings): string
{
    $queryKey = (string) ($settings['shared']['type_param'] ?? 'type');
    $value = trim((string) ($_GET[$queryKey] ?? $_GET['type'] ?? 'image'));
    return $value === '' ? 'image' : $value;
}

function public_api_is_json_request(string $requestedType, array $settings): bool
{
    return in_array($requestedType, [
        (string) ($settings['shared']['type_value'] ?? 'json'),
        'json',
    ], true);
}

function public_api_json_error(int $status, string $message, array $extra = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(
        array_merge(['ok' => false, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function public_api_append_images_from_directory(
    string $absoluteRoot,
    string $publicPrefix,
    string $libraryKey,
    string $category,
    array &$catalog,
    bool $rootFilesOnly = false
): void {
    if (!is_dir($absoluteRoot)) {
        return;
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if ($rootFilesOnly) {
        foreach (scandir($absoluteRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $absoluteRoot . '/' . $entry;
            if (!is_file($fullPath)) {
                continue;
            }

            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (!in_array($extension, $allowedExtensions, true)) {
                continue;
            }

            $catalog[] = [
                'library' => $libraryKey,
                'category' => $category,
                'fs_path' => $fullPath,
                'relative_path' => $entry,
                'public_path' => trim($publicPrefix, '/') . '/' . $entry,
                'basename' => basename($entry),
            ];
        }

        return;
    }

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

        $catalog[] = [
            'library' => $libraryKey,
            'category' => $category,
            'fs_path' => $fileInfo->getPathname(),
            'relative_path' => $relativePath,
            'public_path' => trim($publicPrefix, '/') . '/' . $relativePath,
            'basename' => $fileInfo->getBasename(),
        ];
    }
}

function public_api_load_category_catalog(string $projectRoot, string $endpointKey, string $category): array
{
    $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
    $catalog = [];
    $definitions = match ($endpointKey) {
        'm' => [
            ['root' => $projectRoot . '/m/img', 'prefix' => 'm/img', 'library' => 'm'],
        ],
        'pc' => [
            ['root' => $projectRoot . '/pc/img', 'prefix' => 'pc/img', 'library' => 'pc'],
        ],
        default => [
            ['root' => $projectRoot . '/pc/img', 'prefix' => 'pc/img', 'library' => 'pc'],
            ['root' => $projectRoot . '/m/img', 'prefix' => 'm/img', 'library' => 'm'],
        ],
    };

    foreach ($definitions as $definition) {
        $root = $definition['root'];
        $prefix = $definition['prefix'];
        $libraryKey = $definition['library'];

        if ($category === public_api_default_category_name()) {
            public_api_append_images_from_directory($root, $prefix, $libraryKey, $category, $catalog, true);
            public_api_append_images_from_directory($root . '/' . $category, $prefix . '/' . $category, $libraryKey, $category, $catalog);
            continue;
        }

        public_api_append_images_from_directory($root . '/' . $category, $prefix . '/' . $category, $libraryKey, $category, $catalog);
    }

    usort($catalog, static function (array $left, array $right): int {
        $pathCompare = strcmp((string) ($left['public_path'] ?? ''), (string) ($right['public_path'] ?? ''));
        if ($pathCompare !== 0) {
            return $pathCompare;
        }

        return strcmp((string) ($left['library'] ?? ''), (string) ($right['library'] ?? ''));
    });

    return $catalog;
}

function public_api_pick_sequential(array $catalog, string $projectRoot, string $stateKey): array
{
    $stateFile = public_api_endpoint_state_file($projectRoot);
    image_api_ensure_directory(dirname($stateFile));
    $handle = fopen($stateFile, 'c+');
    if ($handle === false) {
        error_log('[ImageAPI] public_api_pick_sequential: fopen failed for stateKey=' . $stateKey);
        return $catalog[0];
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            error_log('[ImageAPI] public_api_pick_sequential: flock failed for stateKey=' . $stateKey);
            return $catalog[0];
        }

        $raw = stream_get_contents($handle);
        $state = json_decode($raw ?: '{}', true);
        if (!is_array($state)) {
            $state = [];
        }

        $cursor = max(0, (int) ($state[$stateKey] ?? 0));
        $index = $cursor % count($catalog);
        $state[$stateKey] = $index + 1;

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($handle);

        return $catalog[$index];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function public_api_pick_category_image(string $projectRoot, string $endpointKey, string $category, array $catalog, array $settings): array
{
    $mode = (string) ($settings['endpoints'][$endpointKey]['mode'] ?? 'random');
    if ($mode === 'sequential') {
        return public_api_pick_sequential($catalog, $projectRoot, $endpointKey . ':' . $category);
    }

    return $catalog[array_rand($catalog)];
}

function public_api_base_url_for_path(string $basePath): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $scheme = $isHttps ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
    $normalizedBasePath = trim($basePath, '/');

    return $scheme . '://' . $host . ($normalizedBasePath === '' ? '' : '/' . $normalizedBasePath);
}

function public_api_payload(array $selectedImage, array $imageInfo, string $baseUrl): array
{
    return [
        'endpoint' => '',
        'name' => '',
        'mode' => '',
        'file_name' => (string) ($selectedImage['basename'] ?? ''),
        'url' => rtrim($baseUrl, '/') . '/' . image_api_encode_public_path((string) $selectedImage['public_path']),
        'size' => image_api_format_file_size((int) ($imageInfo['size'] ?? 0)),
        'width' => (int) ($imageInfo['width'] ?? 0),
        'height' => (int) ($imageInfo['height'] ?? 0),
        'category' => (string) ($selectedImage['category'] ?? public_api_default_category_name()),
        'library' => (string) ($selectedImage['library'] ?? ''),
        'path' => (string) ($selectedImage['public_path'] ?? ''),
        'preview' => '',
        'timestamp' => date('c'),
    ];
}

function public_api_render_template(string $template, array $payload): string
{
    $rendered = $template;
    foreach ($payload as $key => $value) {
        $rendered = str_replace('{{' . $key . '}}', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rendered);
    }

    return $rendered;
}

function public_api_output_json(array $selectedImage, array $imageInfo, string $baseUrl, array $settings, string $endpointKey): void
{
    $payload = public_api_payload($selectedImage, $imageInfo, $baseUrl);
    $payload['endpoint'] = $endpointKey;
    $payload['name'] = match ($endpointKey) {
        'm' => '手机接口',
        'pc' => 'PC 接口',
        default => '自适应接口',
    };
    $payload['mode'] = (string) ($settings['endpoints'][$endpointKey]['mode'] ?? 'random');
    $payload['library'] = $endpointKey === 'root' ? 'adaptive' : (string) ($selectedImage['library'] ?? $endpointKey);

    $template = trim((string) ($settings['endpoints'][$endpointKey]['template'] ?? public_api_default_template()));
    if ($template === '') {
        $template = public_api_default_template();
    }

    $rendered = public_api_render_template($template, $payload);
    header('Content-Type: application/json; charset=utf-8');
    try {
        json_decode($rendered, true, 512, JSON_THROW_ON_ERROR);
        echo $rendered;
    } catch (Throwable) {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

function public_api_emit_selected_image(
    array $selectedImage,
    string $baseUrl,
    string $logFolder,
    string $requestMethod,
    string $requestedType,
    array $settings,
    string $endpointKey
): never {
    $imageInfo = image_api_get_image_info((string) $selectedImage['fs_path']);

    if (public_api_is_json_request($requestedType, $settings)) {
        public_api_output_json($selectedImage, $imageInfo, $baseUrl, $settings, $endpointKey);
        image_api_write_log($logFolder, (string) $selectedImage['public_path'], 200, $requestMethod, 'json');
        exit;
    }

    if ($requestedType === 'url') {
        header('Content-Type: text/plain; charset=utf-8');
        echo rtrim($baseUrl, '/') . '/' . image_api_encode_public_path((string) $selectedImage['public_path']);
        image_api_write_log($logFolder, (string) $selectedImage['public_path'], 200, $requestMethod, 'url');
        exit;
    }

    header('Expires: Tue, 01 Jan 2000 00:00:00 GMT');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Content-Type: ' . image_api_detect_mime_type((string) $imageInfo['path']));
    header('Content-Length: ' . (int) $imageInfo['size']);
    readfile((string) $imageInfo['path']);
    image_api_write_log($logFolder, (string) $selectedImage['public_path'], 200, $requestMethod, 'image');
    exit;
}
