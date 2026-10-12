<?php
declare(strict_types=1);

require_once __DIR__ . '/image_runtime.php';

function api_config_path(): string
{
    return STORAGE_DIR . '/api_config.json';
}

/** @return array<string,array<string,mixed>> */
function default_api_configuration(): array
{
    return [
        'pc' => ['direct' => true, 'url' => true, 'json' => true, 'alias' => '/pc/', 'category' => '', 'strategy' => 'random', 'template' => "{\n  \"url\": \"{{img_url}}\",\n  \"width\": \"{{width}}\",\n  \"height\": \"{{height}}\",\n  \"device\": \"pc\"\n}"],
        'mobile' => ['direct' => true, 'url' => true, 'json' => true, 'alias' => '/m/', 'category' => '', 'strategy' => 'random', 'template' => "{\n  \"url\": \"{{img_url}}\",\n  \"width\": \"{{width}}\",\n  \"height\": \"{{height}}\",\n  \"device\": \"mobile\"\n}"],
        'auto' => ['direct' => true, 'url' => true, 'json' => true, 'alias' => '/', 'category' => '', 'strategy' => 'random', 'template' => "{\n  \"url\": \"{{img_url}}\",\n  \"width\": \"{{width}}\",\n  \"height\": \"{{height}}\",\n  \"device\": \"{{device}}\"\n}"],
    ];
}

/** @return array<string,array<string,mixed>> */
function api_configuration(): array
{
    initialize_storage();
    $defaults = default_api_configuration();
    $raw = is_file(api_config_path()) ? json_decode((string) file_get_contents(api_config_path()), true) : null;
    $stored = database_document('api-configuration');
    if (is_array($stored)) $raw = $stored;
    elseif (is_array($raw)) save_database_document('api-configuration', $raw);
    elseif (runtime_state_database() !== null) {
        $raw = $defaults;
        save_database_document('api-configuration', $raw);
    }
    if (!is_array($raw)) return $defaults;
    foreach ($defaults as $channel => $default) {
        if (!is_array($raw[$channel] ?? null)) continue;
        $defaults[$channel] = array_merge($default, $raw[$channel]);
    }
    return $defaults;
}

function api_template_is_valid(string $template): bool
{
    $sample = strtr($template, ['{{img_url}}' => 'https://example.test/image.png', '{{width}}' => '1920', '{{height}}' => '1080', '{{format}}' => 'png', '{{device}}' => 'pc', '{{user_agent}}' => 'PicAPI']);
    json_decode($sample, true);
    return json_last_error() === JSON_ERROR_NONE;
}

/** @return array<string,array<string,mixed>> */
function normalize_api_configuration(array $input): array
{
    $result = default_api_configuration();
    foreach (array_keys($result) as $channel) {
        $source = is_array($input[$channel] ?? null) ? $input[$channel] : [];
        $template = trim((string) ($source['template'] ?? ''));
        if ($template === '' || !api_template_is_valid($template)) throw new InvalidArgumentException("{$channel} 的 JSON 模板无效，请检查 JSON 语法和占位符。");
        $strategy = (string) ($source['strategy'] ?? 'random');
        if (!in_array($strategy, ['random', 'sequence'], true)) throw new InvalidArgumentException('不支持的图片输出策略。');
        $category = strtolower(trim((string) ($source['category'] ?? '')));
        if ($category !== '' && (!valid_category($category) || !in_array($category, all_categories(), true))) {
            throw new InvalidArgumentException('分类请求名称不存在，请先创建该分类文件夹。');
        }
        $result[$channel] = ['direct' => !empty($source['direct']), 'url' => !empty($source['url']), 'json' => !empty($source['json']), 'alias' => $result[$channel]['alias'], 'category' => $category, 'strategy' => $strategy, 'template' => $template];
    }
    return $result;
}

function save_api_configuration(array $input): void
{
    $config = normalize_api_configuration($input);
    if (runtime_state_database() !== null) {
        if (!save_database_document('api-configuration', $config)) throw new RuntimeException('无法保存 API 自定义配置到数据库。');
        return;
    }
    $temporary = api_config_path() . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json) || file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, api_config_path())) {
        @unlink($temporary);
        throw new RuntimeException('无法保存 API 自定义配置。');
    }
}

function save_api_configuration_channel(string $channel, array $input): void
{
    if (!in_array($channel, ['pc', 'mobile', 'auto'], true)) throw new InvalidArgumentException('不支持的 API 通道。');
    $configuration = api_configuration();
    $configuration[$channel] = $input;
    save_api_configuration($configuration);
}

/** @return array{path:string,relative:string,device:string,mime:string,size:int,width:int,height:int}|null */
function configured_api_image(string $device, ?string $category, string $strategy, string $channel): ?array
{
    if ($strategy !== 'sequence') return random_image($device, $category);
    $images = list_images($device === 'auto' ? null : $device, $category);
    if ($images === []) return null;
    // Deterministic category/folder order: first folder and first file through
    // the final folder and final file, instead of modification-time order.
    usort($images, static fn(array $left, array $right): int => strnatcasecmp($left['relative'], $right['relative']));
    $path = STORAGE_DIR . '/api_sequence.json';
    $legacy = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    $legacy = is_array($legacy) ? $legacy : [];
    $sequenceKey = $channel . ':' . $device . ':' . ($category ?? '_all');
    if (runtime_state_database() !== null) {
        return with_database_runtime_state('api-sequence', $legacy, static function (array &$state) use ($sequenceKey, $images): array {
            $index = (int) ($state[$sequenceKey] ?? 0);
            $state[$sequenceKey] = $index + 1;
            return $images[$index % count($images)];
        });
    }
    $handle = fopen($path, 'c+');
    if ($handle === false) throw new RuntimeException('无法读取顺序输出状态。');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('无法锁定顺序输出状态。');
        $state = $legacy;
        $index = (int) ($state[$sequenceKey] ?? 0);
        $state[$sequenceKey] = $index + 1;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($state, JSON_UNESCAPED_UNICODE));
        fflush($handle);
        return $images[$index % count($images)];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** @return array<mixed>|string|int|float|bool|null */
function api_template_output(string $template, array $image): mixed
{
    $escaped = static fn(string $value): string => substr((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1);
    $rendered = strtr($template, [
        '{{img_url}}' => $escaped(image_url($image['device'], $image['relative'])), '{{width}}' => (string) $image['width'], '{{height}}' => (string) $image['height'],
        '{{format}}' => $escaped(pathinfo($image['relative'], PATHINFO_EXTENSION)), '{{device}}' => $escaped($image['device']), '{{user_agent}}' => $escaped((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
    ]);
    return json_decode($rendered, true, 512, JSON_THROW_ON_ERROR);
}
