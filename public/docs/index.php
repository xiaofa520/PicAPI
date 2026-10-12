<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/media_management.php';
require_once dirname(__DIR__, 2) . '/src/api_docs_runtime.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

initialize_storage();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$isStatisticsRequest = (string) ($_GET['stats'] ?? '') === '1';
if ($isStatisticsRequest) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    try {
        $requestStats = api_docs_request_statistics();
        $visits = api_docs_visit_statistics(false);
        if ($method !== 'HEAD') {
            echo json_encode(['ok' => true, 'refreshed_at' => gmdate('c'), 'total' => $requestStats['total'], 'channels' => $requestStats['channels'], 'docVisits' => $visits], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    } catch (Throwable) {
        http_response_code(503);
        if ($method !== 'HEAD') echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$preview = array_key_exists('preview', $_GET);
$docVisits = api_docs_visit_statistics($method === 'GET' && !$preview);
$requestStats = api_docs_request_statistics();
$settings = site_settings();
$siteName = trim((string) ($settings['site_name'] ?? '')) ?: 'PicAPI';
$seoTitle = trim((string) ($settings['seo_title'] ?? '')) ?: $siteName . ' · 接口文档';
$seoDescription = trim((string) ($settings['seo_description'] ?? '')) ?: trim((string) ($settings['site_description'] ?? ''));
$seoKeywords = trim((string) ($settings['seo_keywords'] ?? ''));
$base = rtrim((string) ($settings['site_url'] ?: application_base_url()), '/') . '/';
$docConfig = api_docs_configuration();
$palettes = [];
foreach (['light', 'dark'] as $mode) {
    $surface = $settings[$mode . '_theme_surface'];
    $text = $settings[$mode . '_theme_text'];
    $palettes[$mode] = [
        'color' => $settings[$mode . '_theme_color'],
        'background' => $settings[$mode . '_theme_background'],
        'surface' => $surface,
        'text' => $text,
        'muted' => $settings[$mode . '_theme_muted'],
        'line' => 'color-mix(in srgb, ' . $surface . ' 82%, ' . $text . ')',
    ];
}
$frameConfiguration = [
    'markdown' => $docConfig['markdown'],
    'baseURL' => $base,
    'fontFamily' => $docConfig['font_family'],
    'customCSS' => $docConfig['custom_css'],
    'customJS' => $docConfig['custom_js'],
    'headHTML' => $docConfig['head_html'],
    'footerHTML' => $docConfig['footer_html'],
    'palettes' => $palettes,
    'stats' => $requestStats,
    'visits' => $docVisits,
];
$docThemeCss = api_docs_theme_css($settings);
$frameJSON = json_encode($frameConfiguration, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$siteNameJSON = json_encode($siteName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
?>
<!doctype html>
<html lang="zh-CN" data-doc-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <link rel="icon" href="<?= html(site_favicon_url()) ?>">
    <title><?= html($seoTitle) ?></title>
    <?php if ($seoDescription !== ''): ?><meta name="description" content="<?= html($seoDescription) ?>"><meta property="og:description" content="<?= html($seoDescription) ?>"><?php endif; ?>
    <?php if ($seoKeywords !== ''): ?><meta name="keywords" content="<?= html($seoKeywords) ?>"><?php endif; ?>
    <meta property="og:title" content="<?= html($seoTitle) ?>">
    <script src="<?= html($base . 'admin/assets/docs-markdown.js?v=domain-token-1') ?>"></script>
    <style>
        :root{color-scheme:light;--page:#fff;--panel:#fff;--line:#dbe4ed;--muted:#65758b;--text:#26384d;--accent:#2854da}
        *{box-sizing:border-box}html,body{width:100%;height:100%;min-height:0;margin:0}body{display:flex;flex-direction:column;overflow:hidden;background:var(--page);color:var(--text);font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}
        .docs-public-screen{display:flex;flex-direction:column;width:100%;height:100dvh;min-height:360px;background:var(--page)}
        .docs-public-head{display:flex;align-items:center;gap:16px;min-height:50px;flex:0 0 50px;padding:0 clamp(14px,3vw,34px);border-bottom:1px solid var(--line);background:var(--panel);color:var(--text);font-size:11px}
        .docs-public-head strong{font-weight:650;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.docs-public-head>span{margin-left:auto;color:var(--muted);font-size:10px;white-space:nowrap;font-variant-numeric:tabular-nums}
        .docs-public-back{min-height:30px;padding:5px 9px;border:1px solid var(--line);border-radius:5px;background:var(--panel);color:var(--muted);font:inherit;font-size:10px;text-decoration:none;white-space:nowrap}.docs-public-back:hover{border-color:var(--accent);color:var(--accent)}
        .docs-public-screen>iframe{display:block;flex:1;width:100%;min-width:0;min-height:0;border:0;background:var(--page)}
        #notice{position:fixed;right:16px;top:64px;padding:8px 12px;border:1px solid var(--line);border-radius:6px;background:var(--panel);color:var(--text);font-size:12px;opacity:0;pointer-events:none;transition:opacity .16s ease}#notice.show{opacity:1}
        <?= $docThemeCss ?>
        @media(max-width:620px){.docs-public-head{gap:8px;min-height:54px;flex-basis:54px;padding-inline:12px}.docs-public-head>span{font-size:9px}.docs-public-back{min-height:29px;padding:4px 7px;font-size:10px}.docs-public-screen{min-height:320px}}
    </style>
    <script>(()=>{let mode='light';try{const saved=localStorage.getItem('picapi-production-theme');if(['dark','light'].includes(saved))mode=saved;else if(saved==='auto')mode=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}catch{}document.documentElement.dataset.docTheme=mode;})();</script>
</head>
<body>
<main class="docs-public-screen">
    <header class="docs-public-head"><strong id="site-name"></strong><span aria-live="polite">今日访问 <b data-doc-visit="today"><?= number_format($docVisits['today']) ?></b> · 昨日 <b data-doc-visit="yesterday"><?= number_format($docVisits['yesterday']) ?></b></span><a class="docs-public-back" href="<?= html($base . 'admin/') ?>">返回后台</a></header>
    <iframe id="published-document" title="公开接口文档" sandbox="allow-scripts allow-popups"></iframe>
</main>
<div id="notice" role="status" aria-live="polite">已复制</div>
<script>
(()=>{
    const siteName=<?= $siteNameJSON ?>,frameOptions=<?= $frameJSON ?>,frame=document.querySelector('#published-document'),notice=document.querySelector('#notice');
    document.querySelector('#site-name').textContent=siteName;
    if(!window.PicAPIDocsMarkdown){document.querySelector('.doc-content').textContent='文档渲染器载入失败，请刷新页面。';return}
    const formatNumber=value=>new Intl.NumberFormat('zh-CN').format(Math.max(0,Number(value)||0));
    let noticeTimer;const theme=document.documentElement.dataset.docTheme||'light';
    frame.srcdoc=window.PicAPIDocsMarkdown.frame({...frameOptions,theme});
    frame.addEventListener('load',()=>frame.contentWindow?.postMessage({type:'picapi:docs-theme',theme},'*'));
    const updateCounters=payload=>{
        document.querySelectorAll('[data-doc-visit]').forEach(node=>{node.textContent=formatNumber(payload.docVisits?.[node.dataset.docVisit])});
        frame.contentWindow?.postMessage({type:'picapi:docs',action:'stats',stats:{channels:payload.channels||{},total:payload.total||{}},visits:payload.docVisits||{}},'*');
    };
    const statsURL=new URL(window.location.href);statsURL.searchParams.set('stats','1');
    const refresh=async()=>{try{const response=await fetch(statsURL,{headers:{Accept:'application/json'},cache:'no-store',credentials:'same-origin'});const payload=await response.json();if(response.ok&&payload.ok===true)updateCounters(payload)}catch{}};
    window.addEventListener('message',event=>{if(event.source!==frame.contentWindow||event.data?.type!=='picapi:docs'||event.data.action!=='refresh')return;refresh()});
    document.addEventListener('click',async event=>{const button=event.target.closest('[data-copy]');if(!button)return;try{await navigator.clipboard.writeText(button.dataset.copy||'')}catch{const input=document.createElement('textarea');input.value=button.dataset.copy||'';document.body.append(input);input.select();document.execCommand('copy');input.remove()}notice.classList.add('show');clearTimeout(noticeTimer);noticeTimer=setTimeout(()=>notice.classList.remove('show'),1400)});
    window.setTimeout(refresh,2000);window.setInterval(refresh,10000);
})();
</script>
</body>
</html>
