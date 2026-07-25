<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/image_runtime.php';
require_once dirname(__DIR__) . '/lib/api_docs_runtime.php';

// The public document refreshes only these statistics through the same page,
// so its layout and copy/open controls never need a full-page reload.
if ((string) ($_GET['stats'] ?? '') === '1') {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        http_response_code(405);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    try {
        initialize_storage();
        $statistics = [
            'auto' => endpoint_request_statistics('auto'),
            'pc' => endpoint_request_statistics('pc'),
            'mobile' => endpoint_request_statistics('mobile'),
        ];
        $total = ['today' => 0, 'yesterday' => 0, 'total' => 0];
        foreach ($statistics as $item) {
            foreach ($total as $field => $value) $total[$field] += max(0, (int) ($item[$field] ?? 0));
        }
        if ($method !== 'HEAD') {
            echo json_encode(['ok' => true, 'refreshed_at' => gmdate('c'), 'total' => $total, 'channels' => $statistics], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    } catch (Throwable) {
        http_response_code(503);
        if ($method !== 'HEAD') echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

initialize_storage();
$base = rtrim(application_base_url(), '/');
$channels = [
    ['key' => 'auto', 'name' => '自适应接口', 'path' => '/', 'stats' => endpoint_request_statistics('auto')],
    ['key' => 'pc', 'name' => 'PC 接口', 'path' => '/pc/', 'stats' => endpoint_request_statistics('pc')],
    ['key' => 'mobile', 'name' => '手机接口', 'path' => '/m/', 'stats' => endpoint_request_statistics('mobile')],
];
$categories = all_categories();
$docConfig = api_docs_configuration();
$docThemeCss = api_docs_theme_css($docConfig);
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <title>PicAPI 使用文档</title>
    <style>
        :root { color-scheme: dark; --page: #080d18; --panel: #101827; --line: #263247; --muted: #9eabc0; --text: #f5f7fb; --accent: #7c9cff; }
        * { box-sizing: border-box; }
        body { margin: 0; min-width: 320px; background: var(--page); color: var(--text); font: 15px/1.55 "Noto Sans SC", "Microsoft YaHei", sans-serif; }
        main { width: min(980px, calc(100% - 32px)); margin: 0 auto; padding: 52px 0 64px; }
        header { display: flex; align-items: end; gap: 10px; padding-bottom: 26px; border-bottom: 1px solid var(--line); }
        header > :first-child { margin-right: auto; }
        h1 { margin: 0; font-size: 28px; letter-spacing: 0; }
        .lead, .hint { margin: 7px 0 0; color: var(--muted); font-size: 13px; }
        .home { color: var(--accent); font-size: 13px; text-decoration: none; white-space: nowrap; }
        .home:hover { color: #b8c7ff; }
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 26px 0 34px; }
        .stat { padding: 16px; border: 1px solid var(--line); background: var(--panel); border-radius: 7px; }
        .stat span { display: block; color: var(--muted); font-size: 12px; }
        .stat strong { display: block; margin-top: 3px; font-size: 25px; font-weight: 600; font-variant-numeric: tabular-nums; }
        .section-title { margin: 0 0 12px; font-size: 16px; }
        .endpoints { display: grid; gap: 12px; }
        .endpoint { border: 1px solid var(--line); border-radius: 7px; overflow: hidden; background: var(--panel); }
        .endpoint-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--line); }
        .endpoint h2 { margin: 0; font-size: 16px; font-weight: 600; }
        .endpoint-stats { color: var(--muted); font-size: 12px; white-space: nowrap; }
        .route { display: grid; grid-template-columns: 68px minmax(0, 1fr) auto; align-items: center; gap: 10px; padding: 10px 16px; border-bottom: 1px solid var(--line); }
        .route:last-child { border-bottom: 0; }
        .category-title { margin: 34px 0 5px; font-size: 16px; }
        .category-list { display: grid; gap: 12px; margin-top: 12px; }
        .category-alias { color: var(--muted); font-size: 12px; }
        .route-label { color: var(--muted); font-size: 12px; }
        code { min-width: 0; overflow: hidden; color: var(--text); font: 12px/1.5 Consolas, "Cascadia Mono", monospace; text-overflow: ellipsis; white-space: nowrap; }
        .actions { display: flex; gap: 6px; }
        button, .open { appearance: none; min-height: 29px; padding: 4px 9px; border: 1px solid var(--line); border-radius: 5px; background: #172236; color: #d6deed; font: 12px/1 "Noto Sans SC", "Microsoft YaHei", sans-serif; text-decoration: none; cursor: pointer; }
        button:hover, .open:hover { border-color: #5574c8; color: #fff; }
        footer { margin-top: 28px; color: var(--muted); font-size: 12px; }
        #notice { position: fixed; right: 16px; bottom: 16px; padding: 9px 12px; border: 1px solid #4261aa; border-radius: 6px; background: #14203a; color: #e2e9ff; font-size: 13px; opacity: 0; transform: translateY(8px); pointer-events: none; transition: opacity .16s ease, transform .16s ease; }
        #notice.show { opacity: 1; transform: translateY(0); }
        @media (max-width: 640px) { main { width: min(100% - 24px, 980px); padding-top: 28px; } header { align-items: start; flex-direction: column; gap: 12px; } header > :first-child { margin-right: 0; } .stats { gap: 8px; } .stat { padding: 12px; } .stat strong { font-size: 21px; } .endpoint-head { align-items: start; flex-direction: column; } .route { grid-template-columns: 52px minmax(0, 1fr); padding: 10px 12px; } .actions { grid-column: 2; } }
    </style>
    <style><?= $docThemeCss ?></style>
    <script>
        (() => {
            const key = 'picapi-doc-theme-mode';
            try {
                const mode = localStorage.getItem(key);
                const selected = ['dark', 'light', 'auto'].includes(mode) ? mode : 'auto';
                document.documentElement.dataset.docTheme = selected === 'auto'
                    ? (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark')
                    : selected;
            } catch (_) {}
        })();
    </script>
    <?php if ($docConfig['font_family'] !== ''): ?><style>body { font-family: <?= html($docConfig['font_family']) ?> !important; }</style><?php endif; ?>
    <?php if ($docConfig['custom_css'] !== ''): ?><style><?= $docConfig['custom_css'] ?></style><?php endif; ?>
    <?= $docConfig['head_html'] ?>
</head>
<body>
<main>
    <header>
        <div><h1>PicAPI 使用文档</h1><p class="lead">复制链接即可使用，默认直接返回图片。</p></div>
        <a class="home" href="<?= htmlspecialchars($base . '/', ENT_QUOTES, 'UTF-8') ?>">打开自适应接口</a>
        <div class="doc-theme-switch" role="group" aria-label="文档显示模式">
            <button type="button" data-doc-theme-mode="dark">深色</button>
            <button type="button" data-doc-theme-mode="light">浅色</button>
            <button type="button" data-doc-theme-mode="auto">自动</button>
        </div>
    </header>

    <section class="stats" aria-label="访问统计">
        <div class="stat"><span>今日访问</span><strong data-doc-total-stat="today" aria-live="polite"><?= $channels[0]['stats']['today'] + $channels[1]['stats']['today'] + $channels[2]['stats']['today'] ?></strong></div>
        <div class="stat"><span>昨日访问</span><strong data-doc-total-stat="yesterday" aria-live="polite"><?= $channels[0]['stats']['yesterday'] + $channels[1]['stats']['yesterday'] + $channels[2]['stats']['yesterday'] ?></strong></div>
        <div class="stat"><span>总访问</span><strong data-doc-total-stat="total" aria-live="polite"><?= $channels[0]['stats']['total'] + $channels[1]['stats']['total'] + $channels[2]['stats']['total'] ?></strong></div>
    </section>

    <h2 class="section-title">接口链接</h2>
    <section class="endpoints">
        <?php foreach ($channels as $channel): $url = $base . $channel['path']; ?>
        <article class="endpoint">
            <div class="endpoint-head"><h2><?= htmlspecialchars($channel['name'], ENT_QUOTES, 'UTF-8') ?></h2><span class="endpoint-stats" data-doc-channel-stats="<?= $channel['key'] ?>">今日 <b data-doc-channel-stat="today"><?= $channel['stats']['today'] ?></b> · 昨日 <b data-doc-channel-stat="yesterday"><?= $channel['stats']['yesterday'] ?></b> · 总计 <b data-doc-channel-stat="total"><?= $channel['stats']['total'] ?></b></span></div>
            <?php foreach (['直接图片' => '', 'JSON 数据' => '?type=json', '图片链接' => '?type=url'] as $label => $suffix): $route = $url . $suffix; ?>
            <div class="route"><span class="route-label"><?= $label ?></span><code title="<?= htmlspecialchars($route, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($route, ENT_QUOTES, 'UTF-8') ?></code><span class="actions"><button type="button" data-copy="<?= htmlspecialchars($route, ENT_QUOTES, 'UTF-8') ?>">复制</button><a class="open" href="<?= htmlspecialchars($route, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">打开</a></span></div>
            <?php endforeach; ?>
        </article>
        <?php endforeach; ?>
    </section>

    <h2 class="category-title">分类请求</h2>
    <p class="hint">选择分类后默认直接返回该分类图片。</p>
    <section class="category-list">
        <?php foreach ($categories as $category): $categoryName = category_display_name($category); ?>
        <article class="endpoint">
            <div class="endpoint-head"><h2><?= htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') ?></h2><span class="category-alias">请求名：<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></span></div>
            <?php foreach ($channels as $channel): $route = $base . $channel['path'] . '?type=' . rawurlencode($category); ?>
            <div class="route"><span class="route-label"><?= htmlspecialchars($channel['name'], ENT_QUOTES, 'UTF-8') ?></span><code title="<?= htmlspecialchars($route, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($route, ENT_QUOTES, 'UTF-8') ?></code><span class="actions"><button type="button" data-copy="<?= htmlspecialchars($route, ENT_QUOTES, 'UTF-8') ?>">复制</button><a class="open" href="<?= htmlspecialchars($route, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">打开</a></span></div>
            <?php endforeach; ?>
        </article>
        <?php endforeach; ?>
    </section>
    <footer>JSON：<code>?category=分类请求名&amp;type=json</code>　链接：<code>?category=分类请求名&amp;type=url</code></footer>
    <?= $docConfig['footer_html'] ?>
</main>
<div id="notice" role="status" aria-live="polite">已复制</div>
<script>
(() => {
    const themeStorageKey = 'picapi-doc-theme-mode';
    const systemTheme = window.matchMedia('(prefers-color-scheme: light)');
    const validThemeMode = value => ['dark', 'light', 'auto'].includes(value) ? value : 'auto';
    let documentThemeMode = 'auto';
    try { documentThemeMode = validThemeMode(localStorage.getItem(themeStorageKey)); } catch (_) {}
    const applyDocumentTheme = mode => {
        documentThemeMode = validThemeMode(mode);
        const resolved = documentThemeMode === 'auto'
            ? (systemTheme.matches ? 'light' : 'dark')
            : documentThemeMode;
        document.documentElement.dataset.docTheme = resolved;
        document.querySelectorAll('[data-doc-theme-mode]').forEach(button => {
            const selected = button.dataset.docThemeMode === documentThemeMode;
            button.classList.toggle('is-active', selected);
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
    };
    document.querySelectorAll('[data-doc-theme-mode]').forEach(button => button.addEventListener('click', () => {
        const mode = validThemeMode(button.dataset.docThemeMode);
        try { localStorage.setItem(themeStorageKey, mode); } catch (_) {}
        applyDocumentTheme(mode);
    }));
    systemTheme.addEventListener('change', () => { if (documentThemeMode === 'auto') applyDocumentTheme('auto'); });
    applyDocumentTheme(documentThemeMode);

    const notice = document.getElementById('notice');
    let timer;
    document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
        const value = button.dataset.copy || '';
        try { await navigator.clipboard.writeText(value); }
        catch (_) { const input = document.createElement('textarea'); input.value = value; document.body.append(input); input.select(); document.execCommand('copy'); input.remove(); }
        notice.textContent = '已复制链接'; notice.classList.add('show'); clearTimeout(timer); timer = setTimeout(() => notice.classList.remove('show'), 1600);
    }));

    const statisticsUrl = new URL(window.location.href);
    statisticsUrl.searchParams.set('stats', '1');
    const numberFormatter = new Intl.NumberFormat('zh-CN');
    const setStatistic = (element, value) => {
        const number = Number(value);
        if (element && Number.isFinite(number)) element.textContent = numberFormatter.format(Math.max(0, number));
    };
    const refreshStatistics = async () => {
        try {
            const response = await fetch(statisticsUrl.toString(), {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin',
            });
            const payload = await response.json();
            if (!response.ok || payload?.ok !== true) return;
            ['today', 'yesterday', 'total'].forEach(field => {
                setStatistic(document.querySelector(`[data-doc-total-stat="${field}"]`), payload.total?.[field]);
            });
            document.querySelectorAll('[data-doc-channel-stats]').forEach(container => {
                const channel = container.dataset.docChannelStats;
                const statistics = payload.channels?.[channel];
                if (!statistics) return;
                container.querySelectorAll('[data-doc-channel-stat]').forEach(element => {
                    setStatistic(element, statistics[element.dataset.docChannelStat]);
                });
            });
        } catch (_) {
            // Keep the most recently rendered values when the optional update
            // request is briefly unavailable.
        }
    };
    // Public documentation statistics refresh independently every five
    // seconds; no full document reload or page-position reset is required.
    window.setInterval(refreshStatistics, 5000);
    window.setTimeout(refreshStatistics, 5000);
})();
</script>
<?php if ($docConfig['custom_js'] !== ''): ?><script><?= $docConfig['custom_js'] ?></script><?php endif; ?>
</body>
</html>
