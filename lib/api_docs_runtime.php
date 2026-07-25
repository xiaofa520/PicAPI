<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

const API_DOCS_CONFIGURATION_PATH = STORAGE_DIR . '/api-docs.json';

/** @return array<string,string> */
function default_api_docs_configuration(): array
{
    return [
        'font_family' => '',
        'custom_css' => '',
        'custom_js' => '',
        'head_html' => '',
        'footer_html' => '',
        // Kept for existing configuration files. Visitors choose the document
        // colour mode on the public page, independently of the admin theme.
        'theme_mode' => 'dark',
        'dark_theme_color' => '#7C9CFF',
        'dark_theme_background' => '#080D18',
        'dark_theme_surface' => '#101827',
        'dark_theme_text' => '#F5F7FB',
        'dark_theme_muted' => '#9EABC0',
        'dark_theme_line' => '#263247',
        'light_theme_color' => '#2563EB',
        'light_theme_background' => '#F6F9FF',
        'light_theme_surface' => '#FFFFFF',
        'light_theme_text' => '#102A43',
        'light_theme_muted' => '#59718B',
        'light_theme_line' => '#D7E3F2',
    ];
}

/** @return array<string,string> */
function api_docs_configuration(): array
{
    $defaults = default_api_docs_configuration();
    $stored = is_file(API_DOCS_CONFIGURATION_PATH) ? json_decode((string) file_get_contents(API_DOCS_CONFIGURATION_PATH), true) : [];
    $databaseStored = database_document('api-docs-configuration');
    if (is_array($databaseStored)) $stored = $databaseStored;
    elseif (is_array($stored)) save_database_document('api-docs-configuration', $stored);
    if (!is_array($stored)) return $defaults;
    foreach ($defaults as $key => $value) {
        if (isset($stored[$key]) && is_string($stored[$key])) $defaults[$key] = $stored[$key];
    }
    $legacyMode = ($stored['theme_mode'] ?? 'dark') === 'light' ? 'light' : 'dark';
    foreach (['color', 'background', 'surface', 'text', 'muted', 'line'] as $part) {
        $legacyKey = 'theme_' . $part;
        $paletteKey = $legacyMode . '_theme_' . $part;
        if (!isset($stored[$paletteKey]) && isset($stored[$legacyKey]) && is_string($stored[$legacyKey])) {
            $defaults[$paletteKey] = $stored[$legacyKey];
        }
    }
    return $defaults;
}

/** @param array<string,mixed> $input */
function save_api_docs_configuration(array $input): void
{
    $config = default_api_docs_configuration();
    foreach ($config as $key => $default) {
        $value = $input[$key] ?? $default;
        if (!is_string($value) || strlen($value) > 200000) {
            throw new InvalidArgumentException('文档自定义内容格式不正确或长度超出限制。');
        }
        $config[$key] = $value;
    }
    if (strlen($config['font_family']) > 200 || preg_match('/[<>{};]/', $config['font_family'])) {
        throw new InvalidArgumentException('字体名称格式不正确。');
    }
    $config['theme_mode'] = in_array($config['theme_mode'], ['dark', 'light', 'auto'], true) ? $config['theme_mode'] : 'dark';
    foreach (['dark', 'light'] as $mode) {
        foreach (['color', 'background', 'surface', 'text', 'muted', 'line'] as $part) {
        $key = $mode . '_theme_' . $part;
        $color = strtoupper(trim($config[$key]));
        if (preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) {
            throw new InvalidArgumentException('文档主题颜色格式不正确。');
        }
        $config[$key] = $color;
    }
    }
    if (runtime_state_database() !== null) {
        if (!save_database_document('api-docs-configuration', $config)) throw new RuntimeException('无法保存 API 文档配置到数据库。');
        return;
    }
    $temporary = API_DOCS_CONFIGURATION_PATH . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, API_DOCS_CONFIGURATION_PATH)) {
        @unlink($temporary);
        throw new RuntimeException('无法保存 API 文档配置。');
    }
}

/** @param array<string,string> $config */
function api_docs_theme_css(array $config): string
{
    $defaults = default_api_docs_configuration();
    $paletteValue = static function (string $key) use ($config, $defaults): string {
        $value = strtoupper(trim((string) ($config[$key] ?? '')));
        return preg_match('/^#[0-9A-F]{6}$/', $value) === 1 ? $value : $defaults[$key];
    };
    $paletteCss = static function (string $mode) use ($paletteValue): string {
        return '--page:' . $paletteValue($mode . '_theme_background')
            . ';--panel:' . $paletteValue($mode . '_theme_surface')
            . ';--line:' . $paletteValue($mode . '_theme_line')
            . ';--muted:' . $paletteValue($mode . '_theme_muted')
            . ';--text:' . $paletteValue($mode . '_theme_text')
            . ';--accent:' . $paletteValue($mode . '_theme_color');
    };
    return ':root{color-scheme:dark;' . $paletteCss('dark') . '}'
        . 'html[data-doc-theme="light"]{color-scheme:light;' . $paletteCss('light') . '}'
        . 'button,.open{background:color-mix(in srgb,var(--panel) 84%,var(--accent));color:var(--text)}button:hover,.open:hover{border-color:var(--accent);color:var(--text)}'
        . '#notice{border-color:color-mix(in srgb,var(--accent) 65%,var(--line));background:color-mix(in srgb,var(--panel) 84%,var(--accent));color:var(--text)}'
        . '.doc-theme-switch{display:inline-flex;padding:3px;border:1px solid var(--line);border-radius:6px;background:var(--panel);gap:2px}.doc-theme-switch button{min-height:28px;padding:4px 8px;border-color:transparent;background:transparent;color:var(--muted)}.doc-theme-switch button:hover{border-color:transparent;color:var(--text)}.doc-theme-switch button.is-active{border-color:color-mix(in srgb,var(--accent) 65%,var(--line));background:color-mix(in srgb,var(--panel) 70%,var(--accent));color:var(--text)}';
}
