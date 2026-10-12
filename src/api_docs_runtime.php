<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

const API_DOCS_CONFIGURATION_PATH = STORAGE_DIR . '/api-docs.json';
const API_DOCS_VISITS_PATH = STORAGE_DIR . '/logs/api-doc-visits.json';

function default_api_docs_markdown(): string
{
    return <<<'MARKDOWN'
# PicAPI 图片接口

用一个 HTTP 地址，把图库中的图片接入网页或应用。文档中的 `{{site.url}}` 会自动替换为你在系统设置中填写的站点地址。

> **最快开始：** 把图片地址放进网页的 `<img src="...">`，就能直接显示一张图片。

## 选择图片接口

| 场景 | 地址 | 适合用途 |
| --- | --- | --- |
| 自动适配 | `{{site.url}}/` | 按访问设备返回合适的图片 |
| 电脑图片 | `{{site.url}}/pc/` | 固定使用 PC 图片 |
| 手机图片 | `{{site.url}}/m/` | 固定使用手机图片 |

### 直接显示图片

将地址作为图片的 `src`。每次加载都会向接口请求图片：

```html
<img src="{{site.url}}/pc/" alt="随机图片">
```

如果要让页面根据访客设备自动选择图片，把地址改成根路径 `{{site.url}}/`；只服务手机页面时使用 `/m/`。

自动适配接口今日请求：`{{stats.auto.today}}` 次。

### 固定使用 PC 或手机图片

PC 页面使用 `/pc/`，手机页面使用 `/m/`。例如手机页面可以这样嵌入：

```html
<img src="{{site.url}}/m/" alt="手机图片">
```

手机接口今日请求：`{{stats.mobile.today}}` 次。

## 获取图片信息

接口默认直接返回图片。需要在代码中读取图片地址、尺寸等信息时，在地址后加 `?type=json`：

```bash
curl "{{site.url}}/pc/?type=json"
```

浏览器 JavaScript 调用示例：

```javascript
const response = await fetch('{{site.url}}/pc/?type=json');
if (!response.ok) throw new Error(`HTTP ${response.status}`);

const image = await response.json();
console.log(image.url, image.width, image.height);
```

PC 接口今日请求：`{{stats.pc.today}}` 次。

## 选择返回类型

在接口地址后添加 `type` 参数。需要直接展示时使用默认格式；需要程序处理时选择 JSON 或图片直链。

| 写法 | 返回内容 | 常见用途 |
| --- | --- | --- |
| `/pc/` | 图片文件 | 网页直接显示 |
| `/pc/?type=json` | 图片信息 JSON | 读取地址、宽高等字段 |
| `/pc/?type=url` | 图片直链 | 保存或传给其他组件 |

## 按分类筛选

在请求中加入 `category`，即可只从指定分类取图。分类别名可在后台“分类管理”查看：

```text
{{site.url}}/?category=default&type=json
```

把 `default` 换成目标分类的别名；也可以与设备路径组合，例如 `/m/?category=mobile`。

## 全站请求量

上面的数字分别对应自适应、PC 和手机接口。需要展示整体用量时，可在文档任意位置插入：今日总请求 `{{stats.total.today}}` 次，累计请求 `{{stats.total}}` 次。

## 公开文档访问量

下面的统计只计算这份公开文档的访问，与图片接口请求量分开：

- 今日访问：`{{stats.docs.today}}`
- 昨日访问：`{{stats.docs.yesterday}}`
- 累计访问：`{{stats.docs.total}}`

将文档保存并发布后，公开页面会展示你写入的内容和统计值。
MARKDOWN;
}

/** @return array{today:int,yesterday:int,total:int,date:string} */
function api_docs_visit_statistics(bool $increment = false): array
{
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
    $yesterday = (new DateTimeImmutable($today, new DateTimeZone('Asia/Shanghai')))->modify('-1 day')->format('Y-m-d');
    $directory = dirname(API_DOCS_VISITS_PATH);
    ensure_directory($directory);
    $lock = @fopen(API_DOCS_VISITS_PATH . '.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        return ['today' => 0, 'yesterday' => 0, 'total' => 0, 'date' => $today];
    }
    try {
        $saved = is_file(API_DOCS_VISITS_PATH) ? json_decode((string) file_get_contents(API_DOCS_VISITS_PATH), true) : null;
        $saved = is_array($saved) ? $saved : [];
        $savedDate = (string) ($saved['date'] ?? '');
        $todayCount = $savedDate === $today ? max(0, (int) ($saved['today'] ?? 0)) : 0;
        $yesterdayCount = $savedDate === $today
            ? max(0, (int) ($saved['yesterday'] ?? 0))
            : ($savedDate === $yesterday ? max(0, (int) ($saved['today'] ?? 0)) : 0);
        $total = max(0, (int) ($saved['total'] ?? 0));
        if ($increment) {
            $todayCount++;
            $total++;
            if (!write_json_atomic(API_DOCS_VISITS_PATH, ['today' => $todayCount, 'yesterday' => $yesterdayCount, 'total' => $total, 'date' => $today])) {
                throw new RuntimeException('无法保存公开文档访问统计。');
            }
        }
        return ['today' => $todayCount, 'yesterday' => $yesterdayCount, 'total' => $total, 'date' => $today];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** @return array{channels:array<string,array<string,int|string>>,total:array{today:int,yesterday:int,total:int}} */
function api_docs_request_statistics(): array
{
    $channels = [
        'auto' => endpoint_request_statistics('auto'),
        'pc' => endpoint_request_statistics('pc'),
        'mobile' => endpoint_request_statistics('mobile'),
    ];
    $total = ['today' => 0, 'yesterday' => 0, 'total' => 0];
    foreach ($channels as $statistics) {
        foreach ($total as $period => $value) $total[$period] += max(0, (int) ($statistics[$period] ?? 0));
    }
    return ['channels' => $channels, 'total' => $total];
}

/** @return array<string,string> */
function default_api_docs_configuration(): array
{
    return [
        'title' => 'PicAPI 使用文档',
        'introduction' => '复制链接即可使用，默认直接返回图片。',
        'markdown' => default_api_docs_markdown(),
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
    if (trim($config['markdown']) === '') throw new InvalidArgumentException('接口文档内容不能为空。');
    if (strlen($config['font_family']) > 200 || !api_docs_font_family_is_valid($config['font_family'])) {
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

function api_docs_font_family_is_valid(string $value): bool
{
    return $value === '' || preg_match('/^[\p{L}\p{N}\s"\x27,._-]+$/u', $value) === 1;
}

/** @param array<string,mixed> $settings */
function api_docs_theme_css(array $settings): string
{
    $defaults = default_site_settings();
    $paletteValue = static function (string $mode, string $part) use ($settings, $defaults): string {
        $key = $mode . '_theme_' . $part;
        $value = strtoupper(trim((string) ($settings[$key] ?? '')));
        return preg_match('/^#[0-9A-F]{6}$/', $value) === 1 ? $value : (string) $defaults[$key];
    };
    $paletteCss = static function (string $mode) use ($paletteValue): string {
        $surface = $paletteValue($mode, 'surface');
        $text = $paletteValue($mode, 'text');
        return '--page:' . $paletteValue($mode, 'background')
            . ';--panel:' . $surface
            . ';--line:color-mix(in srgb,' . $surface . ' 82%,' . $text . ')'
            . ';--muted:' . $paletteValue($mode, 'muted')
            . ';--text:' . $text
            . ';--accent:' . $paletteValue($mode, 'color');
    };
    return ':root{color-scheme:dark;' . $paletteCss('dark') . '}'
        . 'html[data-doc-theme="light"]{color-scheme:light;' . $paletteCss('light') . '}'
        . 'button,.open{background:color-mix(in srgb,var(--panel) 84%,var(--accent));color:var(--text)}button:hover,.open:hover{border-color:var(--accent);color:var(--text)}'
        . '#notice{border-color:color-mix(in srgb,var(--accent) 65%,var(--line));background:color-mix(in srgb,var(--panel) 84%,var(--accent));color:var(--text)}'
        . '.doc-theme-switch{display:inline-flex;padding:3px;border:1px solid var(--line);border-radius:6px;background:var(--panel);gap:2px}.doc-theme-switch button{min-height:28px;padding:4px 8px;border-color:transparent;background:transparent;color:var(--muted)}.doc-theme-switch button:hover{border-color:transparent;color:var(--text)}.doc-theme-switch button.is-active{border-color:color-mix(in srgb,var(--accent) 65%,var(--line));background:color-mix(in srgb,var(--panel) 70%,var(--accent));color:var(--text)}';
}
