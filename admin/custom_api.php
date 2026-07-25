<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/api_config_runtime.php';
require_once __DIR__ . '/../lib/api_docs_runtime.php';
require_once __DIR__ . '/../lib/settings_runtime.php';

initialize_storage();
$admin = require_admin();
send_admin_security_headers();
$template = embedded_custom_api_template();

$siteSettings = site_settings();
$siteName = html((string) $siteSettings['site_name']);
$template = preg_replace('/<html\b([^>]*)>/i', '<html$1 data-theme="' . html((string) $siteSettings['theme_mode']) . '">', $template, 1) ?? $template;
$template = str_replace('<div id="main-dashboard" class="hidden h-screen', '<div id="main-dashboard" class="h-screen', $template);
$initial = html(display_initial($admin));
$adminName = html($admin);
$adminAvatarUrl = admin_avatar_url();
$adminAvatarMarkup = $adminAvatarUrl !== null
    ? '<img src="' . html($adminAvatarUrl) . '" alt="管理员头像" class="h-full w-full object-cover">'
    : $initial;
$ipControlLink = '<a data-nav-key="ip_control.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-slate-200 font-medium text-sm transition" href="ip_control.php"><i data-lucide="shield-ban" class="w-4 h-4"></i>IP 黑白名单管理</a>';
$settingsLink = '<a data-nav-key="settings.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-slate-200 font-medium text-sm transition" href="settings.php"><i data-lucide="settings-2" class="w-4 h-4"></i>系统设置</a>';
$sidebar = <<<HTML
<aside class="w-full md:w-64 md:min-w-[16rem] md:shrink-0 bg-slate-900 border-b md:border-b-0 md:border-r border-slate-800 flex flex-col justify-between z-10"><div><div class="h-16 flex items-center px-6 border-b border-slate-800 justify-between"><div class="flex min-w-0 items-center gap-2.5 font-semibold text-white"><i data-lucide="layout-dashboard" class="w-5 h-5 shrink-0 text-indigo-400"></i><span class="truncate">{$siteName}</span></div></div><nav class="p-4 space-y-1"><a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-slate-200 font-medium text-sm transition" href="dashboard.php"><i data-lucide="bar-chart-3" class="w-4 h-4"></i>数据看板</a><a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-slate-200 font-medium text-sm transition" href="upload.php"><i data-lucide="upload-cloud" class="w-4 h-4"></i>切片上传</a><a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-slate-200 font-medium text-sm transition" href="media.php"><i data-lucide="image" class="w-4 h-4"></i>图片资源管理</a><a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-slate-200 font-medium text-sm transition" href="folders.php"><i data-lucide="folder-kanban" class="w-4 h-4"></i>分类文件夹管理</a><a class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-slate-200 font-medium text-sm transition" href="logs.php"><i data-lucide="scroll-text" class="w-4 h-4"></i>系统日志与流水</a><button type="button" data-api-view="config" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg bg-slate-800 text-indigo-400 font-medium text-sm transition"><i data-lucide="sliders-horizontal" class="w-4 h-4"></i>API 自定义配置</button><button type="button" data-api-view="docs" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-slate-400 hover:bg-slate-800 hover:text-slate-200 font-medium text-sm transition"><i data-lucide="book-open-text" class="w-4 h-4"></i>API 文档编辑</button></nav></div><div class="p-4 border-t border-slate-800 flex items-center justify-between"><div class="flex items-center gap-2"><div class="w-8 h-8 rounded-full bg-indigo-600 text-white flex items-center justify-center text-sm font-semibold">{$initial}</div><span class="text-sm font-medium text-slate-300">{$adminName}</span></div><a href="logout.php" class="text-slate-500 hover:text-rose-400 transition" title="退出登录"><i data-lucide="log-out" class="w-4 h-4"></i></a></div></aside>
HTML;
$sidebar = str_replace(
    '<div class="w-8 h-8 rounded-full bg-indigo-600 text-white flex items-center justify-center text-sm font-semibold">' . $initial . '</div>',
    '<div data-admin-avatar class="w-8 h-8 overflow-hidden rounded-full bg-indigo-600 text-white flex items-center justify-center text-sm font-semibold">' . $adminAvatarMarkup . '</div>',
    $sidebar
);
$sidebar = str_replace('href="logout.php"', 'href="logout.php" data-logout-csrf="' . html(csrf_token()) . '"', $sidebar);
$sidebar = str_replace('data-api-view="config"', 'data-api-view="config" data-nav-key="custom_api.php"', $sidebar);
$sidebar = str_replace('data-api-view="docs"', 'data-api-view="docs" data-nav-key="custom_api.php?view=docs"', $sidebar);
$sidebar = str_replace('</nav></div><div class="p-4 border-t', $settingsLink . '</nav></div><div class="p-4 border-t', $sidebar);
$sidebar = preg_replace('#(<a[^>]*href="folders\.php"[^>]*>.*?</a>)(<a[^>]*href="logs\.php"[^>]*>.*?</a>)#s', '$1' . $ipControlLink . '$2', $sidebar, 1) ?? $sidebar;
$template = preg_replace('/<aside\b[^>]*>.*?<\/aside>/s', $sidebar, $template, 1) ?? $template;
$customApiSwitchCss = <<<'CSS'
#main-dashboard input[id^="api-"][type="checkbox"] + div {
  position: relative;
  width: 2rem;
  height: 1rem;
  border-radius: 9999px;
  background-color: #1e293b !important;
  transition: background-color .18s ease;
}
#main-dashboard input[id^="api-"][type="checkbox"] + div::after {
  content: '';
  position: absolute;
  top: 2px;
  left: 2px;
  width: .75rem;
  height: .75rem;
  border-width: 1px;
  border-radius: 9999px;
  background-color: #94a3b8 !important;
  border-color: #cbd5e1 !important;
  transition: transform .18s ease, background-color .18s ease;
}
#main-dashboard input[id^="api-pc-"][type="checkbox"]:checked + div { background-color: #4f46e5 !important; }
#main-dashboard input[id^="api-mobile-"][type="checkbox"]:checked + div { background-color: #059669 !important; }
#main-dashboard input[id^="api-auto-"][type="checkbox"]:checked + div { background-color: #d97706 !important; }
#main-dashboard input[id^="api-"][type="checkbox"]:checked + div::after {
  transform: translateX(100%);
  background-color: #ffffff !important;
  border-color: #ffffff !important;
}
#main-dashboard input[id^="api-"][type="checkbox"]:focus-visible + div {
  outline: 2px solid var(--picapi-accent, #6366f1);
  outline-offset: 2px;
}
#main-dashboard .picapi-category-field {
  position: relative;
  margin-top: .75rem;
}
#main-dashboard .picapi-category-field label {
  display: block;
  margin-bottom: .375rem;
  color: #94a3b8;
  font-size: .75rem;
  font-weight: 500;
}
#main-dashboard .picapi-category-field input {
  width: 100%;
  border: 1px solid #1e293b;
  border-radius: .5rem;
  background: #020617;
  padding: .5rem .75rem;
  color: #f8fafc;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: .875rem;
  outline: none;
}
#main-dashboard .picapi-category-field input::placeholder { color: #64748b; }
#main-dashboard .picapi-category-field input:focus {
  border-color: #6366f1;
  box-shadow: 0 0 0 1px #6366f1;
}
#main-dashboard .picapi-category-combobox { position: relative; }
#main-dashboard .picapi-category-combobox input { padding-right: 2.75rem; }
#main-dashboard .picapi-category-trigger {
  position: absolute;
  top: 0;
  right: 0;
  display: inline-flex;
  width: 2.5rem;
  height: 100%;
  align-items: center;
  justify-content: center;
  border: 0;
  border-radius: 0 .5rem .5rem 0;
  color: #94a3b8;
  background: transparent;
  cursor: pointer;
}
#main-dashboard .picapi-category-trigger:hover { color: #e2e8f0; }
#main-dashboard .picapi-category-trigger svg { transition: transform .16s ease; }
#main-dashboard .picapi-category-combobox.is-open .picapi-category-trigger svg { transform: rotate(180deg); }
#main-dashboard .picapi-category-menu {
  position: absolute;
  top: calc(100% + .375rem);
  right: 0;
  left: 0;
  z-index: 60;
  display: none;
  box-sizing: border-box;
  width: 100%;
  max-height: 12rem;
  overflow-y: auto;
  border: 1px solid #334155;
  border-radius: .5rem;
  padding: .25rem;
  background: #1e293b;
  box-shadow: 0 16px 30px rgb(2 6 23 / .4);
}
#main-dashboard .picapi-category-combobox.is-open .picapi-category-menu { display: block; }
#main-dashboard .picapi-category-option {
  display: block;
  width: 100%;
  border: 0;
  border-radius: .375rem;
  padding: .5rem .625rem;
  color: #f8fafc;
  background: transparent;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: .8125rem;
  line-height: 1.25rem;
  text-align: left;
  cursor: pointer;
}
#main-dashboard .picapi-category-option:hover,
#main-dashboard .picapi-category-option:focus-visible { background: #334155; outline: none; }
#main-dashboard .picapi-category-empty { padding: .5rem .625rem; color: #94a3b8; font-size: .75rem; }
#api-pc-json,
#api-mobile-json,
#api-auto-json {
  box-sizing: border-box;
  height: 220px !important;
  min-height: 220px !important;
  max-height: 220px !important;
  resize: none;
  overflow-y: auto;
}
#main-dashboard .picapi-channel-save {
  display: flex;
  width: 100%;
  align-items: center;
  justify-content: center;
  gap: .375rem;
  margin-top: 1rem;
  border: 0;
  border-radius: .5rem;
  background: #4f46e5;
  padding: .625rem .875rem;
  color: #fff;
  font-size: .75rem;
  font-weight: 500;
  line-height: 1.25rem;
  box-shadow: 0 10px 15px -3px rgb(79 70 229 / .18);
  cursor: pointer;
  transition: background-color .18s ease, transform .18s ease, opacity .18s ease;
}
#main-dashboard .picapi-channel-save:hover { background: #4338ca; }
#main-dashboard .picapi-channel-save:focus-visible { outline: 2px solid #818cf8; outline-offset: 2px; }
#main-dashboard .picapi-channel-save:disabled { cursor: wait; opacity: .6; }
.picapi-api-notice {
  position: fixed;
  top: 1rem;
  right: 1rem;
  z-index: 100;
  display: none;
  max-width: 24rem;
  border: 1px solid #334155;
  border-radius: .75rem;
  padding: .75rem 1rem;
  color: #e2e8f0;
  background: #0f172a;
  font-size: .875rem;
  box-shadow: 0 25px 50px -12px rgb(0 0 0 / .5);
}
.picapi-api-notice.is-visible { display: block; }
.picapi-api-notice.is-success { border-color: rgb(16 185 129 / .35); color: #a7f3d0; background: #022c22; }
.picapi-api-notice.is-error { border-color: rgb(244 63 94 / .35); color: #fecdd3; background: #4c0519; }
@media (prefers-reduced-motion: no-preference) {
  body.picapi-page-ready #main-dashboard > aside { animation: picapi-api-sidebar-enter .18s ease-out both; }
  body.picapi-page-ready #main-dashboard > main { animation: picapi-api-content-enter .22s ease-out both; }
  body.picapi-page-leaving #main-dashboard > aside,
  body.picapi-page-leaving #main-dashboard > main { pointer-events: none; opacity: 0; transform: translateY(6px); transition: opacity .14s ease, transform .14s ease; }
  .picapi-api-view-enter { animation: picapi-api-content-enter .18s ease-out both; }
  @keyframes picapi-api-sidebar-enter { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
  @keyframes picapi-api-content-enter { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
}
CSS;
$template = str_replace('</head>', '<link rel="icon" href="' . html(site_favicon_url()) . '"><script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.js" integrity="sha384-LtmWBcrD5iuFIR4sFphS8IiiaftkypL5dJzLKywbyd9ATLB7ZbPz3JYnI9nvXHkV" crossorigin="anonymous"></script><style>' . site_theme_css($siteSettings) . $customApiSwitchCss . '#login-page{display:none!important}</style></head>', $template);
$menuSortScript = <<<'JS'
<script>
(() => {
  const nav = document.querySelector('aside nav');
  if (!nav) return;
  const fixed = nav.querySelector('[href="settings.php"]');
  const items = () => Array.from(nav.querySelectorAll(':scope > a, :scope > button'));
  const key = item => item.dataset.navKey || item.getAttribute('href') || item.textContent.trim();
  const movable = () => items().filter(item => item !== fixed);
  const defaultOrder = ['dashboard.php', 'upload.php', 'media.php', 'folders.php', 'ip_control.php', 'logs.php', 'custom_api.php', 'custom_api.php?view=docs'];
  const applyOrder = order => {
    const map = new Map(movable().map(item => [key(item), item]));
    order.forEach(itemKey => { const item = map.get(itemKey); if (item) nav.insertBefore(item, fixed); });
  };
  applyOrder(defaultOrder);
  try {
    const order = JSON.parse(localStorage.getItem('picapi-admin-navigation-order') || '[]');
    if (Array.isArray(order)) applyOrder(order);
  } catch (_) {}
  if (fixed) nav.append(fixed);
  const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  if (!reduceMotion) requestAnimationFrame(() => document.body.classList.add('picapi-page-ready'));
  let navigationInProgress = false;
  nav.querySelectorAll('a[href]').forEach(link => link.addEventListener('click', event => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (link.dataset.logoutCsrf) {
      event.preventDefault();
      const form = document.createElement('form');
      form.method = 'post';
      form.action = 'logout.php';
      const token = document.createElement('input');
      token.type = 'hidden';
      token.name = 'csrf';
      token.value = link.dataset.logoutCsrf;
      form.append(token);
      document.body.append(form);
      form.submit();
      return;
    }
    if (navigationInProgress || link.target === '_blank' || link.hasAttribute('download')) return;
    const destination = new URL(link.href, window.location.href);
    if (destination.origin !== window.location.origin || (destination.pathname === window.location.pathname && destination.search === window.location.search) || reduceMotion) return;
    event.preventDefault();
    navigationInProgress = true;
    document.body.classList.add('picapi-page-leaving');
    window.setTimeout(() => { window.location.assign(destination.href); }, 145);
  }));
})();
</script>
JS;
$template = str_replace('</body>', $menuSortScript . '</body>', $template);

$configJson = json_encode(api_configuration(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$docsJson = json_encode(api_docs_configuration(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$categoriesJson = json_encode(all_categories(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$endpointsJson = json_encode([
    'pc' => rtrim(application_base_url(), '/') . '/pc/',
    'mobile' => rtrim(application_base_url(), '/') . '/m/',
    'auto' => rtrim(application_base_url(), '/') . '/',
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$docsUrlJson = json_encode(rtrim(application_base_url(), '/') . '/docs/', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$csrfJson = json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$initialViewJson = json_encode((string) ($_GET['view'] ?? '') === 'docs' ? 'docs' : 'config');

$scriptTemplate = <<<'JS'
<script>
(() => {
const savedConfig = __CONFIG__;
const savedDocsConfig = __DOCS_CONFIG__;
const categoryAliases = __CATEGORIES__;
const publicEndpoints = __ENDPOINTS__;
const publicDocsUrl = __DOCS_URL__;
const csrf = __CSRF__;
const initialView = __INITIAL_VIEW__;

const notice = document.createElement('div');
notice.className = 'picapi-api-notice';
document.body.append(notice);
let noticeTimer;
const showNotice = (ok, message) => {
  clearTimeout(noticeTimer);
  notice.textContent = message;
  notice.className = 'picapi-api-notice is-visible ' + (ok ? 'is-success' : 'is-error');
  noticeTimer = setTimeout(() => notice.classList.remove('is-visible'), 3600);
};

const configView = document.querySelector('main > div');
/* Third-party storage UI removed.
const remoteStorageView = document.createElement('section');
remoteStorageView.className = 'mt-6 rounded-xl border border-slate-800 bg-slate-900 p-6 space-y-5';
remoteStorageView.innerHTML = `
  <div class="flex flex-col gap-4 border-b border-slate-800 pb-4 sm:flex-row sm:items-start sm:justify-between"><div class="flex items-start gap-3"><div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-cyan-500/10 text-cyan-400"><i data-lucide="cloud" class="h-5 w-5"></i></div><div><h2 class="text-base font-semibold text-white">&#31532;&#19977;&#26041;&#23384;&#20648;&#31354;&#38388;</h2><p class="mt-1 text-xs leading-5 text-slate-400">&#25903;&#25345;&#33258;&#24314; NAS / MinIO&#12289;&#23545;&#35937;&#23384;&#20648;&#21644;&#26377;&#20844;&#32593;&#35775;&#38382;&#22320;&#22336;&#30340;&#21378;&#21830;&#23384;&#20648;&#26381;&#21153;&#12290;&#22270;&#29255;&#20445;&#25345;&#22312;&#21407;&#23384;&#20648;&#31354;&#38388;&#65292;&#19981;&#22797;&#21046;&#21040;&#26412;&#26426;&#12290;</p></div></div><label class="block shrink-0 text-xs text-slate-400">&#21462;&#22270;&#26041;&#24335;<select data-remote-mode class="mt-1.5 block w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-cyan-500"><option value="append">&#19982;&#26412;&#22320;&#22270;&#29255;&#21512;&#24182;&#25277;&#21462;</option><option value="only">&#20165;&#20174;&#31532;&#19977;&#26041;&#23384;&#20648;&#25277;&#21462;</option></select></label></div>
  <div data-remote-source-list class="space-y-4"></div>
  <div class="flex flex-col gap-3 border-t border-slate-800 pt-4 sm:flex-row sm:items-center sm:justify-between"><button type="button" data-remote-source-add class="inline-flex w-fit items-center gap-1.5 rounded-lg border border-cyan-500/40 bg-cyan-500/10 px-3 py-2 text-xs font-medium text-cyan-300 hover:bg-cyan-500/20"><i data-lucide="plus" class="h-3.5 w-3.5"></i>&#28155;&#21152;&#23384;&#20648;&#26469;&#28304;</button><button type="button" data-remote-storage-save class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-cyan-600 px-4 py-2 text-xs font-medium text-white shadow-lg shadow-cyan-600/20 hover:bg-cyan-700"><i data-lucide="save" class="h-3.5 w-3.5"></i>&#20445;&#23384;&#31532;&#19977;&#26041;&#23384;&#20648;&#37197;&#32622;</button></div>
  <details class="rounded-lg border border-slate-800 bg-slate-950/50 p-4 text-xs text-slate-400"><summary class="cursor-pointer font-medium text-slate-300">JSON &#22270;&#29255;&#28165;&#21333;&#26684;&#24335;&#35828;&#26126;</summary><p class="mt-3 leading-5">&#23558; JSON &#25991;&#20214;&#25918;&#21040;&#23384;&#20648;&#31354;&#38388;&#24182;&#22635;&#20837;&#20854;&#20844;&#32593;&#22320;&#22336;&#12290;&#25903;&#25345; <code class="text-cyan-300">{&quot;pc&quot;:[&quot;https://.../a.jpg&quot;],&quot;mobile&quot;:[&quot;https://.../b.jpg&quot;]}</code>&#65292;&#25110;&#32773;&#20351;&#29992; <code class="text-cyan-300">images</code> &#25968;&#32452;&#20026;&#27599;&#24352;&#22270;&#22635;&#20837; URL&#12289;&#35774;&#22791;&#21644;&#20998;&#31867;&#12290;&#30452;&#25509;&#22270;&#29255;&#35831;&#27714;&#20250; 302 &#21040;&#21407;&#23384;&#20648;&#22320;&#22336;&#65292;&#19981;&#21344;&#29992;&#26412;&#31449;&#22270;&#29255;&#27969;&#37327;&#12290;</p><pre class="mt-3 overflow-x-auto rounded-md border border-slate-800 bg-slate-950 p-3 text-[11px] leading-5 text-slate-300">{
  &quot;images&quot;: [
    {&quot;url&quot;:&quot;https://storage.example.com/pc/a.jpg&quot;,&quot;device&quot;:&quot;pc&quot;,&quot;category&quot;:&quot;default&quot;,&quot;width&quot;:1920,&quot;height&quot;:1080}
  ]
}</pre></details>`;
configView?.append(remoteStorageView);

const escapeRemoteHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]));
let remoteStorageState = {
  mode: savedRemoteStorage?.mode === 'only' ? 'only' : 'append',
  sources: Array.isArray(savedRemoteStorage?.sources) ? savedRemoteStorage.sources : [],
};
const remoteSourceList = remoteStorageView.querySelector('[data-remote-source-list]');
const remoteMode = remoteStorageView.querySelector('[data-remote-mode]');
const readRemoteStorage = () => ({
  mode: remoteMode.value === 'only' ? 'only' : 'append',
  sources: Array.from(remoteSourceList.querySelectorAll('[data-remote-source]')).map(source => ({
    id: source.dataset.remoteSourceId || '',
    name: source.querySelector('[data-remote-name]')?.value.trim() || '',
    enabled: Boolean(source.querySelector('[data-remote-enabled]')?.checked),
    manifest_url: source.querySelector('[data-remote-manifest]')?.value.trim() || '',
    pc_urls: (source.querySelector('[data-remote-pc-urls]')?.value || '').split(/\r?\n/).map(value => value.trim()).filter(Boolean),
    mobile_urls: (source.querySelector('[data-remote-mobile-urls]')?.value || '').split(/\r?\n/).map(value => value.trim()).filter(Boolean),
    cache_seconds: Number(source.querySelector('[data-remote-cache]')?.value || 300),
  })),
});
const remoteSourceMarkup = source => {
  const name = escapeRemoteHtml(source.name || '');
  const manifest = escapeRemoteHtml(source.manifest_url || '');
  const pcUrls = escapeRemoteHtml(Array.isArray(source.pc_urls) ? source.pc_urls.join('\n') : source.pc_urls || '');
  const mobileUrls = escapeRemoteHtml(Array.isArray(source.mobile_urls) ? source.mobile_urls.join('\n') : source.mobile_urls || '');
  const cacheSeconds = Math.max(30, Number(source.cache_seconds) || 300);
  return `<article data-remote-source data-remote-source-id="${escapeRemoteHtml(source.id || '')}" class="rounded-xl border border-slate-800 bg-slate-950/40 p-4 space-y-4"><div class="flex flex-col gap-3 sm:flex-row sm:items-center"><label class="min-w-0 flex-1 text-xs text-slate-400">&#26469;&#28304;&#21517;&#31216;<input data-remote-name maxlength="60" value="${name}" placeholder="&#20363;&#22914;&#65306;NAS &#22270;&#24202; / OSS &#22270;&#24202;" class="mt-1.5 w-full rounded-lg border border-slate-800 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-cyan-500"></label><label class="flex shrink-0 items-center gap-2 pt-4 text-xs text-slate-300"><input data-remote-enabled type="checkbox" ${source.enabled !== false ? 'checked' : ''} class="h-4 w-4 rounded border-slate-600 bg-slate-900 text-cyan-500">&#21551;&#29992;</label><button type="button" data-remote-source-delete class="mt-4 inline-flex shrink-0 items-center justify-center rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-300 hover:bg-rose-500/20">&#21024;&#38500;</button></div><div class="grid gap-4 lg:grid-cols-2"><label class="block text-xs text-slate-400">JSON &#22270;&#29255;&#28165;&#21333;&#22320;&#22336;&#65288;&#21487;&#36873;&#65289;<input data-remote-manifest type="url" value="${manifest}" placeholder="https://storage.example.com/picapi-images.json" class="mt-1.5 w-full rounded-lg border border-slate-800 bg-slate-950 px-3 py-2 font-mono text-xs text-white outline-none focus:border-cyan-500"></label><label class="block text-xs text-slate-400">&#28165;&#21333;&#32531;&#23384;&#31186;&#25968;<input data-remote-cache type="number" min="30" max="86400" value="${cacheSeconds}" class="mt-1.5 w-full rounded-lg border border-slate-800 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-cyan-500"></label></div><div class="grid gap-4 lg:grid-cols-2"><label class="block text-xs text-slate-400">PC &#22270;&#29255;&#30452;&#25509;&#22320;&#22336;&#65288;&#27599;&#34892;&#19968;&#20010;&#65292;&#21487;&#36873;&#65289;<textarea data-remote-pc-urls rows="5" placeholder="https://storage.example.com/pc/a.jpg" class="mt-1.5 w-full resize-y rounded-lg border border-slate-800 bg-slate-950 p-3 font-mono text-xs leading-5 text-white outline-none focus:border-cyan-500">${pcUrls}</textarea></label><label class="block text-xs text-slate-400">&#25163;&#26426;&#22270;&#29255;&#30452;&#25509;&#22320;&#22336;&#65288;&#27599;&#34892;&#19968;&#20010;&#65292;&#21487;&#36873;&#65289;<textarea data-remote-mobile-urls rows="5" placeholder="https://storage.example.com/mobile/a.jpg" class="mt-1.5 w-full resize-y rounded-lg border border-slate-800 bg-slate-950 p-3 font-mono text-xs leading-5 text-white outline-none focus:border-cyan-500">${mobileUrls}</textarea></label></div></article>`;
};
const renderRemoteSources = () => {
  remoteMode.value = remoteStorageState.mode === 'only' ? 'only' : 'append';
  if (!remoteStorageState.sources.length) {
    remoteSourceList.innerHTML = '<div class="rounded-lg border border-dashed border-slate-700 px-4 py-7 text-center text-xs text-slate-500">&#20851;&#38381;&#26102;&#19981;&#24433;&#21709;&#26412;&#22320;&#22270;&#29255;&#24211;&#12290;&#28857;&#20987;&#8220;&#28155;&#21152;&#23384;&#20648;&#26469;&#28304;&#8221;&#21518;&#21487;&#37197;&#32622;&#33258;&#24314;&#25110;&#21378;&#21830;&#23384;&#20648;&#12290;</div>';
    return;
  }
  remoteSourceList.innerHTML = remoteStorageState.sources.map(remoteSourceMarkup).join('');
  remoteSourceList.querySelectorAll('[data-remote-source-delete]').forEach((button, index) => button.addEventListener('click', () => {
    const current = readRemoteStorage();
    current.sources.splice(index, 1);
    remoteStorageState = current;
    renderRemoteSources();
  }));
};
remoteStorageView.querySelector('[data-remote-source-add]').addEventListener('click', () => {
  remoteStorageState = readRemoteStorage();
  remoteStorageState.sources.push({ id: `source-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 7)}`, name: '', enabled: true, manifest_url: '', pc_urls: [], mobile_urls: [], cache_seconds: 300 });
  renderRemoteSources();
});
remoteStorageView.querySelector('[data-remote-storage-save]').addEventListener('click', async event => {
  const button = event.currentTarget;
  const configuration = readRemoteStorage();
  button.disabled = true;
  try {
    const body = new FormData();
    body.append('csrf', csrf);
    body.append('config', JSON.stringify(configuration));
    const response = await fetch('remote_storage_save.php', { method: 'POST', body, credentials: 'same-origin' });
    const result = await response.json();
    if (result.ok) remoteStorageState = configuration;
    showNotice(Boolean(result.ok), result.message || (result.ok ? 'Third-party storage saved.' : 'Third-party storage could not be saved.'));
  } catch (_) {
    showNotice(false, 'Third-party storage could not be saved.');
  } finally { button.disabled = false; }
});
renderRemoteSources();
*/

const docsView = document.createElement('section');
docsView.className = 'hidden lg:max-w-7xl mx-auto w-full space-y-6';
docsView.innerHTML = `
  <div class="bg-slate-900 p-6 rounded-xl border border-slate-800"><div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><div class="flex items-center gap-3"><div class="w-10 h-10 rounded-lg bg-indigo-500/10 text-indigo-400 flex items-center justify-center"><i data-lucide="book-open-text" class="w-5 h-5"></i></div><div><h2 class="text-lg font-semibold text-white">API 文档编辑</h2><p class="text-sm text-slate-400">自定义公开 API 文档的字体、样式和交互脚本。</p></div></div><a data-doc-public target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-xs font-medium text-slate-200 hover:border-indigo-500 hover:text-white"><i data-lucide="external-link" class="w-3.5 h-3.5"></i>打开公开文档</a></div></div>
  <div class="bg-slate-900 p-6 rounded-xl border border-slate-800 space-y-5"><div><h3 class="text-sm font-bold text-white">基础外观</h3><p class="mt-1 text-xs text-slate-500">留空时使用文档原有字体与布局。</p></div><div><label class="block text-xs font-medium text-slate-300 mb-1.5">字体族</label><input data-doc-font maxlength="200" placeholder="例如: Noto Serif SC, serif" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500"></div><div><label class="block text-xs font-medium text-slate-300 mb-1.5">自定义 CSS</label><textarea data-doc-css rows="9" spellcheck="false" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-emerald-400 font-mono text-xs leading-relaxed focus:outline-none focus:ring-1 focus:ring-indigo-500" placeholder="/* 仅作用于公开 API 文档 */"></textarea></div></div>
  <div class="bg-slate-900 p-6 rounded-xl border border-slate-800 space-y-5"><div><h3 class="text-sm font-bold text-white">扩展内容</h3><p class="mt-1 text-xs text-slate-500">HTML 和脚本会原样加入公开 API 文档，只粘贴你信任的代码。</p></div><div class="grid grid-cols-1 lg:grid-cols-2 gap-5"><div><label class="block text-xs font-medium text-slate-300 mb-1.5">页头 HTML</label><textarea data-doc-head rows="9" spellcheck="false" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-slate-200 font-mono text-xs leading-relaxed focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea></div><div><label class="block text-xs font-medium text-slate-300 mb-1.5">页尾 HTML</label><textarea data-doc-footer rows="9" spellcheck="false" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-slate-200 font-mono text-xs leading-relaxed focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea></div></div><div><label class="block text-xs font-medium text-slate-300 mb-1.5">自定义 JavaScript</label><textarea data-doc-js rows="9" spellcheck="false" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-amber-300 font-mono text-xs leading-relaxed focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea></div><div class="flex flex-wrap justify-end gap-2"><button type="button" data-doc-reset class="px-3 py-2 rounded-lg border border-slate-700 bg-slate-800 text-xs font-medium text-slate-300 hover:bg-slate-700">清空编辑器</button><button type="button" data-doc-save class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-xs font-medium text-white shadow-lg shadow-indigo-600/20">保存并应用</button></div></div>`;
docsView.innerHTML = `
  <div class="bg-slate-900 p-6 rounded-xl border border-slate-800"><div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><div class="flex items-center gap-3"><div class="w-10 h-10 rounded-lg bg-indigo-500/10 text-indigo-400 flex items-center justify-center"><i data-lucide="book-open-text" class="w-5 h-5"></i></div><div><h2 class="text-lg font-semibold text-white">API 文档外观与内容</h2><p class="text-sm text-slate-400">先完成常用设置；CSS 与 JavaScript 仅在需要深度定制时使用。</p></div></div><a data-doc-public target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-xs font-medium text-slate-200 hover:border-indigo-500 hover:text-white"><i data-lucide="external-link" class="w-3.5 h-3.5"></i>预览公开文档</a></div></div>
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6"><div class="lg:col-span-2 space-y-6"><div class="bg-slate-900 p-6 rounded-xl border border-slate-800 space-y-5"><div class="border-b border-slate-800 pb-3"><h3 class="text-sm font-bold text-white">① 常用外观设置</h3><p class="mt-1 text-xs text-slate-500">选择字体即可立即改变公开文档的阅读体验。</p></div><div><label class="block text-xs font-medium text-slate-300 mb-2">字体预设</label><div class="grid grid-cols-2 gap-2 sm:grid-cols-4"><button type="button" data-doc-font-preset="" class="doc-font-choice rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-slate-300 hover:border-indigo-500">默认</button><button type="button" data-doc-font-preset="Noto Sans SC, sans-serif" class="doc-font-choice rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-slate-300 hover:border-indigo-500">思源黑体</button><button type="button" data-doc-font-preset="Noto Serif SC, serif" class="doc-font-choice rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-slate-300 hover:border-indigo-500">思源宋体</button><button type="button" data-doc-font-preset="Microsoft YaHei, sans-serif" class="doc-font-choice rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-xs text-slate-300 hover:border-indigo-500">微软雅黑</button></div></div><div><label class="block text-xs font-medium text-slate-300 mb-1.5">自定义字体族</label><input data-doc-font maxlength="200" placeholder="不填则保持默认，例如 Noto Serif SC, serif" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500"><p class="mt-1.5 text-[11px] text-slate-500">选择预设会自动填入；也可以直接输入自己的字体名称。</p></div></div>
    <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden"><details open><summary class="cursor-pointer list-none p-5 flex items-center justify-between"><div><h3 class="text-sm font-bold text-white">② 高级外观（可选）</h3><p class="mt-1 text-xs text-slate-500">用 CSS 修改颜色、间距、卡片样式等。</p></div><i data-lucide="chevron-down" class="w-4 h-4 text-slate-500"></i></summary><div class="border-t border-slate-800 p-5"><textarea data-doc-css rows="10" spellcheck="false" placeholder="/* 示例：修改强调色 */&#10;:root { --accent: #38bdf8; }" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-emerald-400 font-mono text-xs leading-relaxed focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea></div></details></div>
    <div class="bg-slate-900 rounded-xl border border-slate-800 overflow-hidden"><details><summary class="cursor-pointer list-none p-5 flex items-center justify-between"><div><h3 class="text-sm font-bold text-white">③ 内容与脚本（可选）</h3><p class="mt-1 text-xs text-slate-500">添加页头、页尾内容或自定义交互。</p></div><i data-lucide="chevron-down" class="w-4 h-4 text-slate-500"></i></summary><div class="border-t border-slate-800 p-5 space-y-5"><div class="grid grid-cols-1 gap-5 md:grid-cols-2"><div><label class="block text-xs font-medium text-slate-300 mb-1.5">页头 HTML</label><textarea data-doc-head rows="8" spellcheck="false" placeholder="例如：统计代码或 meta 标签" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-slate-200 font-mono text-xs leading-relaxed focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea></div><div><label class="block text-xs font-medium text-slate-300 mb-1.5">页尾 HTML</label><textarea data-doc-footer rows="8" spellcheck="false" placeholder="例如：版权说明或额外提示" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-slate-200 font-mono text-xs leading-relaxed focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea></div></div><div><label class="block text-xs font-medium text-slate-300 mb-1.5">自定义 JavaScript</label><textarea data-doc-js rows="8" spellcheck="false" placeholder="// 只会在公开 API 文档页面执行" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-amber-300 font-mono text-xs leading-relaxed focus:outline-none focus:ring-1 focus:ring-indigo-500"></textarea></div></div></details></div>
    <div class="flex flex-wrap justify-end gap-2"><button type="button" data-doc-reset class="px-3 py-2 rounded-lg border border-slate-700 bg-slate-800 text-xs font-medium text-slate-300 hover:bg-slate-700">清空所有设置</button><button type="button" data-doc-save class="px-5 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-xs font-medium text-white shadow-lg shadow-indigo-600/20">保存并应用到文档</button></div></div>
    <aside class="space-y-5"><div class="bg-slate-900 p-5 rounded-xl border border-slate-800"><div class="flex items-center gap-2"><i data-lucide="list-checks" class="w-4 h-4 text-indigo-400"></i><h3 class="text-sm font-semibold text-white">三步完成修改</h3></div><ol class="mt-4 space-y-3 text-xs text-slate-400"><li class="flex gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-indigo-500/10 text-indigo-400">1</span><span>选择字体或填写自定义字体。</span></li><li class="flex gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-indigo-500/10 text-indigo-400">2</span><span>需要更多样式时展开高级外观。</span></li><li class="flex gap-2"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-indigo-500/10 text-indigo-400">3</span><span>保存后点击预览公开文档。</span></li></ol></div><div class="bg-indigo-950/30 p-5 rounded-xl border border-indigo-500/20"><div class="flex items-center gap-2"><i data-lucide="shield-alert" class="w-4 h-4 text-indigo-300"></i><h3 class="text-sm font-semibold text-indigo-100">代码使用提醒</h3></div><p class="mt-2 text-xs leading-5 text-indigo-200/70">CSS、HTML 和 JavaScript 会直接应用到公开文档。请只粘贴你信任的代码。</p></div></aside></div>`;
configView?.after(docsView);
docsView.querySelector('[data-doc-public]').href = publicDocsUrl;

/* Legacy field map (kept as a comment because of old template encoding):
const docFields = {
  font_family: docsView.querySelector('[data-doc-font]'), custom_css: docsView.querySelector('[data-doc-css]'), custom_js: docsView.querySelector('[data-doc-js']), head_html: docsView.querySelector('[data-doc-head]'), footer_html: docsView.querySelector('[data-doc-footer]')
}; */
const docFields = {
  font_family: docsView.querySelector('[data-doc-font]'),
  custom_css: docsView.querySelector('[data-doc-css]'),
  custom_js: docsView.querySelector('[data-doc-js]'),
  head_html: docsView.querySelector('[data-doc-head]'),
  footer_html: docsView.querySelector('[data-doc-footer]')
};
const docThemePresets = {
  dark: [
    ['night', '深靛蓝', '#7C9CFF', '#080D18', '#101827', '#F5F7FB', '#9EABC0', '#263247'],
    ['green', '深绿', '#34D399', '#061B17', '#0D2822', '#E9FFF6', '#9BCFBD', '#245C4D'],
    ['ocean', '深海蓝', '#38BDF8', '#061521', '#0C2232', '#E7F7FF', '#9CBFCC', '#24516A'],
    ['amber', '深琥珀', '#FBBF24', '#1C1507', '#2C220D', '#FFF8E7', '#D2C093', '#5C491A'],
  ],
  light: [
    ['sky', '天空蓝', '#2563EB', '#F6F9FF', '#FFFFFF', '#102A43', '#59718B', '#D7E3F2'],
    ['green', '浅绿', '#059669', '#F1FCF7', '#FFFFFF', '#123A2E', '#5C7D70', '#CBEADD'],
    ['violet', '浅紫', '#7C3AED', '#FAF7FF', '#FFFFFF', '#2E1065', '#75628A', '#E3D8F7'],
    ['amber', '浅琥珀', '#B7791F', '#FFFCF3', '#FFFFFF', '#3D2A0D', '#806C42', '#F0E1B9'],
  ],
};
const docThemeEditor = document.createElement('section');
docThemeEditor.className = 'rounded-xl border border-slate-800 bg-slate-900 p-5 space-y-4';
docThemeEditor.innerHTML = '<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><h3 class="text-sm font-bold text-white">文档主题</h3><p class="mt-1 text-xs text-slate-500">主题只应用于公开 API 文档，不影响后台系统主题。</p></div><div class="inline-flex rounded-lg border border-slate-700 bg-slate-950 p-1"><button type="button" data-doc-theme-mode="dark" class="rounded-md px-3 py-1.5 text-xs font-medium">深色</button><button type="button" data-doc-theme-mode="light" class="rounded-md px-3 py-1.5 text-xs font-medium">浅色</button></div></div><input data-doc-theme-field="theme_mode" type="hidden"><input data-doc-theme-field="theme_color" type="hidden"><input data-doc-theme-field="theme_background" type="hidden"><input data-doc-theme-field="theme_surface" type="hidden"><input data-doc-theme-field="theme_text" type="hidden"><input data-doc-theme-field="theme_muted" type="hidden"><input data-doc-theme-field="theme_line" type="hidden"><div data-doc-theme-preview class="rounded-lg border p-4"><span class="block text-sm font-semibold">PicAPI 使用文档</span><span class="mt-1 block text-xs opacity-70">主题预览</span></div><div data-doc-theme-presets class="grid grid-cols-2 gap-2 sm:grid-cols-4"></div>';
docFields.font_family.closest('.bg-slate-900')?.after(docThemeEditor);
const docThemeFields = Object.fromEntries(Array.from(docThemeEditor.querySelectorAll('[data-doc-theme-field]')).map(field => [field.dataset.docThemeField, field]));
Object.assign(docFields, docThemeFields);
const docThemePreview = docThemeEditor.querySelector('[data-doc-theme-preview]');
const docThemePresetGrid = docThemeEditor.querySelector('[data-doc-theme-presets]');
const refreshDocTheme = () => {
  const mode = docThemeFields.theme_mode.value === 'light' ? 'light' : 'dark';
  docThemePreview.style.background = docThemeFields.theme_background.value;
  docThemePreview.style.color = docThemeFields.theme_text.value;
  docThemePreview.style.borderColor = docThemeFields.theme_line.value;
  docThemeEditor.querySelectorAll('[data-doc-theme-mode]').forEach(button => {
    const selected = button.dataset.docThemeMode === mode;
    button.className = 'rounded-md px-3 py-1.5 text-xs font-medium ' + (selected ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:text-slate-200');
  });
  docThemePresetGrid.replaceChildren();
  docThemePresets[mode].forEach(([key, label, color, background, surface, text, muted, line]) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.docThemePreset = `${mode}:${key}`;
    const selected = docThemeFields.theme_color.value.toUpperCase() === color && docThemeFields.theme_background.value.toUpperCase() === background;
    button.className = 'rounded-lg border p-2 text-left text-[11px] transition ' + (selected ? 'border-indigo-500 ring-1 ring-indigo-500/40' : 'border-slate-700 bg-slate-950 hover:border-slate-500');
    button.innerHTML = `<span class="mb-1.5 flex h-4 overflow-hidden rounded-sm border" style="border-color:${line}"><i class="h-full w-1/3" style="background:${background}"></i><i class="h-full w-1/3" style="background:${surface}"></i><i class="h-full w-1/3" style="background:${color}"></i></span><span class="text-slate-300">${label}</span>`;
    button.addEventListener('click', () => setDocThemePreset(mode, key));
    docThemePresetGrid.append(button);
  });
};
const setDocThemePreset = (mode, key) => {
  const preset = docThemePresets[mode]?.find(item => item[0] === key) || docThemePresets[mode][0];
  const [, , color, background, surface, text, muted, line] = preset;
  docThemeFields.theme_mode.value = mode;
  docThemeFields.theme_color.value = color;
  docThemeFields.theme_background.value = background;
  docThemeFields.theme_surface.value = surface;
  docThemeFields.theme_text.value = text;
  docThemeFields.theme_muted.value = muted;
  docThemeFields.theme_line.value = line;
  refreshDocTheme();
};
Object.entries(docFields).forEach(([key, field]) => { field.value = savedDocsConfig[key] || ''; });
if (!docThemeFields.theme_mode.value || !docThemeFields.theme_color.value) setDocThemePreset('dark', 'night');
else refreshDocTheme();
docThemeEditor.querySelectorAll('[data-doc-theme-mode]').forEach(button => button.addEventListener('click', () => { const mode = button.dataset.docThemeMode; setDocThemePreset(mode, mode === 'light' ? 'sky' : 'night'); }));
docsView.querySelectorAll('[data-doc-font-preset]').forEach(button => button.addEventListener('click', () => { docFields.font_family.value = button.dataset.docFontPreset || ''; docsView.querySelectorAll('[data-doc-font-preset]').forEach(item => item.classList.remove('border-indigo-500', 'text-indigo-300')); button.classList.add('border-indigo-500', 'text-indigo-300'); }));
docsView.querySelector('[data-doc-reset]').addEventListener('click', () => { Object.entries(docFields).forEach(([key, field]) => { if (!key.startsWith('theme_')) field.value = ''; }); setDocThemePreset('dark', 'night'); });
docsView.querySelector('[data-doc-save]').addEventListener('click', async event => {
  const button = event.currentTarget;
  const body = new FormData();
  body.append('csrf', csrf);
  Object.entries(docFields).forEach(([key, field]) => body.append(key, field.value));
  button.disabled = true;
  try {
    const response = await fetch('api_docs_save.php', { method: 'POST', body });
    const result = await response.json(); showNotice(Boolean(result.ok), result.message || (result.ok ? 'Document settings saved.' : 'Document settings failed.')); /* legacy localized message:
    showNotice(Boolean(result.ok), result.message || (result.ok ? '文档设置已保存。' : '文档保存失败。'));
  } catch (_) { showNotice(false, '文档保存失败：无法连接保存接口。'); }
  */ } catch (_) { showNotice(false, 'Document settings could not be saved.'); } finally { button.disabled = false; }
});

  // The public page owns its display mode. The editor only configures the
  // independent dark and light palettes used by that page.
  docThemeEditor.remove();
  ['theme_mode', 'theme_color', 'theme_background', 'theme_surface', 'theme_text', 'theme_muted', 'theme_line'].forEach(key => delete docFields[key]);
  const documentPalettePresets = {
    dark: [
      ['night', '\u6df1\u9776\u84dd', '#7C9CFF', '#080D18', '#101827', '#F5F7FB', '#9EABC0', '#263247'],
      ['green', '\u6df1\u7eff', '#34D399', '#061B17', '#0D2822', '#E9FFF6', '#9BCFBD', '#245C4D'],
      ['ocean', '\u6df1\u6d77\u84dd', '#38BDF8', '#061521', '#0C2232', '#E7F7FF', '#9CBFCC', '#24516A'],
      ['amber', '\u6df1\u7425\u73c0', '#FBBF24', '#1C1507', '#2C220D', '#FFF8E7', '#D2C093', '#5C491A'],
      ['violet', '\u6df1\u7d2b\u7f57\u5170', '#A78BFA', '#130D25', '#21183A', '#F7F2FF', '#B9ADD3', '#4B3A70'],
      ['rose', '\u6df1\u73ab\u7470', '#FB7185', '#240B12', '#35101B', '#FFF1F4', '#D7A9B2', '#6A2D3B'],
      ['teal', '\u6df1\u677e\u77f3', '#2DD4BF', '#061917', '#0D2925', '#E8FFFA', '#A0C9C0', '#285D55'],
      ['graphite', '\u77f3\u58a8\u9ed1', '#A3A3A3', '#121212', '#202020', '#FAFAFA', '#B8B8B8', '#3D3D3D'],
      ['copper', '\u6df1\u94dc\u68d5', '#FB923C', '#21130B', '#332016', '#FFF7ED', '#D5B49B', '#704120'],
      ['ruby', '\u6df1\u7ea2\u5b9d\u77f3', '#E11D48', '#250810', '#39101C', '#FFF1F3', '#D8A7B1', '#72203A'],
    ],
    light: [
      ['sky', '\u5929\u7a7a\u84dd', '#2563EB', '#F6F9FF', '#FFFFFF', '#102A43', '#59718B', '#D7E3F2'],
      ['green', '\u6d45\u7eff', '#059669', '#F1FCF7', '#FFFFFF', '#123A2E', '#5C7D70', '#CBEADD'],
      ['violet', '\u6d45\u7d2b', '#7C3AED', '#FAF7FF', '#FFFFFF', '#2E1065', '#75628A', '#E3D8F7'],
      ['amber', '\u6d45\u7425\u73c0', '#B7791F', '#FFFCF3', '#FFFFFF', '#3D2A0D', '#806C42', '#F0E1B9'],
      ['coral', '\u73ca\u745a\u6a59', '#E85D4A', '#FFF7F5', '#FFFFFF', '#4A1E19', '#8B6661', '#F2D3CE'],
      ['rose', '\u8537\u8587\u73ab\u7470', '#DB2777', '#FFF7FB', '#FFFFFF', '#4A1230', '#8D6176', '#F3D4E3'],
      ['teal', '\u6e05\u6da7\u7eff', '#0D9488', '#F1FCF9', '#FFFFFF', '#103A35', '#5C7B74', '#CDEBE4'],
      ['slate', '\u6696\u77f3\u58a8', '#475569', '#F8FAFC', '#FFFFFF', '#172033', '#687386', '#D8E0EA'],
      ['indigo', '\u975b\u9752\u84dd', '#4338CA', '#F6F5FF', '#FFFFFF', '#201A53', '#6B6792', '#DCD8F6'],
      ['orange', '\u67d4\u548c\u6a59', '#EA580C', '#FFFAF5', '#FFFFFF', '#3B2616', '#806957', '#F3DBC3'],
    ],
  };
  const documentPaletteEditor = document.createElement('section');
  documentPaletteEditor.className = 'rounded-xl border border-slate-800 bg-slate-900 p-5 space-y-5';
  documentPaletteEditor.innerHTML = `
    <div><h3 class="text-sm font-bold text-white">&#25991;&#26723;&#20027;&#39064;&#39044;&#35774;</h3><p class="mt-1 text-xs text-slate-500">&#20998;&#21035;&#37197;&#32622;&#20844;&#24320; API &#25991;&#26723;&#30340;&#28145;&#33394;&#19982;&#27973;&#33394;&#22806;&#35266;&#12290;&#35775;&#38382;&#32773;&#22312;&#25991;&#26723;&#39029;&#38754;&#33258;&#34892;&#36873;&#25321;&#28145;&#33394;&#12289;&#27973;&#33394;&#25110;&#33258;&#21160;&#27169;&#24335;&#12290;</p></div>
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
      <section class="rounded-lg border border-slate-800 bg-slate-950/50 p-4"><div class="mb-3 flex items-center justify-between"><h4 class="text-xs font-semibold text-slate-200">&#28145;&#33394;&#20027;&#39064;&#39044;&#35774;</h4><span class="text-[11px] text-slate-500">Dark</span></div><div data-doc-palette-preview="dark" class="mb-3 rounded-md border p-3 text-xs"><strong class="block text-sm">PicAPI &#20351;&#29992;&#25991;&#26723;</strong><span class="mt-1 block opacity-70">&#28145;&#33394;&#39044;&#35272;</span></div><div data-doc-palette-grid="dark" class="grid grid-cols-2 gap-2"></div></section>
      <section class="rounded-lg border border-slate-800 bg-slate-950/50 p-4"><div class="mb-3 flex items-center justify-between"><h4 class="text-xs font-semibold text-slate-200">&#27973;&#33394;&#20027;&#39064;&#39044;&#35774;</h4><span class="text-[11px] text-slate-500">Light</span></div><div data-doc-palette-preview="light" class="mb-3 rounded-md border p-3 text-xs"><strong class="block text-sm">PicAPI &#20351;&#29992;&#25991;&#26723;</strong><span class="mt-1 block opacity-70">&#27973;&#33394;&#39044;&#35272;</span></div><div data-doc-palette-grid="light" class="grid grid-cols-2 gap-2"></div></section>
    </div>
    ${['dark', 'light'].map(mode => ['color', 'background', 'surface', 'text', 'muted', 'line'].map(part => `<input type="hidden" data-doc-palette-field="${mode}_theme_${part}">`).join('')).join('')}`;
  docFields.font_family.closest('.bg-slate-900')?.after(documentPaletteEditor);
  const documentPaletteFields = Object.fromEntries(Array.from(documentPaletteEditor.querySelectorAll('[data-doc-palette-field]')).map(field => [field.dataset.docPaletteField, field]));
  Object.assign(docFields, documentPaletteFields);
  Object.entries(documentPaletteFields).forEach(([key, field]) => { field.value = savedDocsConfig[key] || ''; });
  const updateDocumentPalettePreview = mode => {
    const preview = documentPaletteEditor.querySelector(`[data-doc-palette-preview="${mode}"]`);
    if (!preview) return;
    preview.style.background = documentPaletteFields[`${mode}_theme_background`].value;
    preview.style.color = documentPaletteFields[`${mode}_theme_text`].value;
    preview.style.borderColor = documentPaletteFields[`${mode}_theme_line`].value;
  };
  let documentPaletteSaveTimer;
  const saveDocumentPalette = () => {
    clearTimeout(documentPaletteSaveTimer);
    documentPaletteSaveTimer = window.setTimeout(async () => {
      const body = new FormData();
      body.append('csrf', csrf);
      Object.entries(docFields).forEach(([key, field]) => body.append(key, field.value));
      try {
        const response = await fetch('api_docs_save.php', { method: 'POST', body });
        const result = await response.json();
        showNotice(Boolean(result.ok), result.message || (result.ok ? '文档主题已即时应用。' : '文档主题保存失败。'));
      } catch (_) {
        showNotice(false, '文档主题保存失败。');
      }
    }, 120);
  };
  const renderDocumentPalette = mode => {
    const grid = documentPaletteEditor.querySelector(`[data-doc-palette-grid="${mode}"]`);
    if (!grid) return;
    grid.replaceChildren();
    const selectedColor = documentPaletteFields[`${mode}_theme_color`].value.toUpperCase();
    const selectedBackground = documentPaletteFields[`${mode}_theme_background`].value.toUpperCase();
    documentPalettePresets[mode].forEach(([key, label, color, background, surface, text, muted, line]) => {
      const button = document.createElement('button');
      button.type = 'button';
      const selected = selectedColor === color && selectedBackground === background;
      button.className = 'rounded-lg border p-2 text-left text-[11px] transition ' + (selected ? 'border-indigo-500 ring-1 ring-indigo-500/40' : 'border-slate-700 bg-slate-950 hover:border-slate-500');
      button.innerHTML = `<span class="mb-1.5 flex h-4 overflow-hidden rounded-sm border" style="border-color:${line}"><i class="h-full w-1/3" style="background:${background}"></i><i class="h-full w-1/3" style="background:${surface}"></i><i class="h-full w-1/3" style="background:${color}"></i></span><span class="text-slate-300">${label}</span>`;
      button.addEventListener('click', () => setDocumentPalettePreset(mode, key, true));
      grid.append(button);
    });
    updateDocumentPalettePreview(mode);
  };
  const setDocumentPalettePreset = (mode, key, persist = false) => {
    const preset = documentPalettePresets[mode].find(item => item[0] === key) || documentPalettePresets[mode][0];
    const [, , color, background, surface, text, muted, line] = preset;
    documentPaletteFields[`${mode}_theme_color`].value = color;
    documentPaletteFields[`${mode}_theme_background`].value = background;
    documentPaletteFields[`${mode}_theme_surface`].value = surface;
    documentPaletteFields[`${mode}_theme_text`].value = text;
    documentPaletteFields[`${mode}_theme_muted`].value = muted;
    documentPaletteFields[`${mode}_theme_line`].value = line;
    renderDocumentPalette(mode);
    if (persist) saveDocumentPalette();
  };
  for (const mode of ['dark', 'light']) {
    if (!documentPaletteFields[`${mode}_theme_color`].value) setDocumentPalettePreset(mode, mode === 'dark' ? 'night' : 'sky');
    else renderDocumentPalette(mode);
  }
  docsView.querySelector('[data-doc-reset]').addEventListener('click', () => {
    setDocumentPalettePreset('dark', 'night');
    setDocumentPalettePreset('light', 'sky');
  });

  const viewButtons = document.querySelectorAll('[data-api-view]');
const setView = view => {
  const showDocs = view === 'docs';
  configView?.classList.toggle('hidden', showDocs);
  docsView.classList.toggle('hidden', !showDocs);
  viewButtons.forEach(button => {
    const active = button.dataset.apiView === view;
    button.className = 'w-full flex items-center gap-3 px-3 py-2.5 rounded-lg font-medium text-sm transition ' + (active ? 'bg-slate-800 text-indigo-400' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200');
  });
  const activeView = showDocs ? docsView : configView;
  if (!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
    activeView?.classList.remove('picapi-api-view-enter');
    requestAnimationFrame(() => activeView?.classList.add('picapi-api-view-enter'));
  }
  if (showDocs) window.lucide?.createIcons({ nodes: [docsView] });
};
viewButtons.forEach(button => button.addEventListener('click', () => setView(button.dataset.apiView)));
setView(initialView);

const endpointFor = (type, category) => publicEndpoints[type] + (category ? `?type=${encodeURIComponent(category)}` : '');
for (const type of ['pc', 'mobile', 'auto']) {
  const item = savedConfig[type] || {};
  const direct = document.getElementById(`api-${type}-direct`);
  const url = document.getElementById(`api-${type}-out-url`);
  const json = document.getElementById(`api-${type}-out-json`);
  const alias = document.getElementById(`api-${type}-alias`);
  const strategy = document.getElementById(`api-${type}-strategy`);
  const template = document.getElementById(`api-${type}-json`);
  if (direct) direct.checked = Boolean(item.direct);
  if (url) url.checked = Boolean(item.url);
  if (json) json.checked = Boolean(item.json);
  if (strategy) strategy.value = item.strategy || 'random';
  if (template) template.value = item.template || '';
  if (alias) {
    alias.readOnly = true;
    alias.value = endpointFor(type, item.category || '');
    const field = document.createElement('div');
    field.className = 'picapi-category-field';
    field.innerHTML = `<label class="block text-slate-400 mb-1.5 font-medium">&#20998;&#31867;&#35831;&#27714;&#21517;&#31216;&#65288;&#21487;&#36873;&#65289;</label><div class="picapi-category-combobox"><input id="api-${type}-category" role="combobox" aria-autocomplete="list" aria-expanded="false" autocomplete="off" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-white font-mono focus:outline-none focus:ring-1 focus:ring-indigo-500" placeholder="&#20363;&#22914; category-1"><button type="button" class="picapi-category-trigger" aria-label="&#26174;&#31034;&#20998;&#31867;&#21015;&#34920;"><i data-lucide="chevron-down" class="w-4 h-4"></i></button><div class="picapi-category-menu" role="listbox"></div></div>`;
    alias.parentElement.append(field);
    const category = field.querySelector('input');
    const combobox = field.querySelector('.picapi-category-combobox');
    const menu = field.querySelector('.picapi-category-menu');
    const trigger = field.querySelector('.picapi-category-trigger');
    category.value = item.category || '';
    const closeMenu = () => {
      combobox.classList.remove('is-open');
      category.setAttribute('aria-expanded', 'false');
    };
    const chooseCategory = value => {
      category.value = value;
      alias.value = endpointFor(type, value.trim().toLowerCase());
      closeMenu();
    };
    const renderOptions = filterValue => {
      const filter = String(filterValue || '').trim().toLowerCase();
      const matches = categoryAliases.filter(value => String(value).toLowerCase().includes(filter));
      menu.replaceChildren();
      if (!matches.length) {
        const empty = document.createElement('p');
        empty.className = 'picapi-category-empty';
        empty.textContent = '\u6ca1\u6709\u5339\u914d\u7684\u5206\u7c7b\uff0c\u53ef\u4ee5\u76f4\u63a5\u8f93\u5165\u8bf7\u6c42\u540d\u79f0\u3002';
        menu.append(empty);
        return;
      }
      matches.forEach(value => {
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'picapi-category-option';
        option.setAttribute('role', 'option');
        option.textContent = value;
        option.addEventListener('click', () => chooseCategory(value));
        menu.append(option);
      });
    };
    const openMenu = () => {
      renderOptions(category.value);
      combobox.classList.add('is-open');
      category.setAttribute('aria-expanded', 'true');
    };
    category.addEventListener('input', () => {
      alias.value = endpointFor(type, category.value.trim().toLowerCase());
      openMenu();
    });
    category.addEventListener('focus', openMenu);
    category.addEventListener('keydown', event => {
      if (event.key === 'Escape') closeMenu();
      if (event.key === 'ArrowDown' && !combobox.classList.contains('is-open')) {
        event.preventDefault();
        openMenu();
      }
    });
    trigger.addEventListener('click', () => {
      if (combobox.classList.contains('is-open')) closeMenu();
      else {
        category.focus();
        openMenu();
      }
    });
    document.addEventListener('pointerdown', event => {
      if (!field.contains(event.target)) closeMenu();
    });
  }
}
const readChannel = type => ({
  direct: Boolean(document.getElementById(`api-${type}-direct`)?.checked), url: Boolean(document.getElementById(`api-${type}-out-url`)?.checked), json: Boolean(document.getElementById(`api-${type}-out-json`)?.checked), alias: document.getElementById(`api-${type}-alias`)?.value || '', category: document.getElementById(`api-${type}-category`)?.value.trim().toLowerCase() || '', strategy: document.getElementById(`api-${type}-strategy`)?.value || 'random', template: document.getElementById(`api-${type}-json`)?.value || ''
});
/* Legacy template save routine is replaced below.
  const body = new FormData(); body.append('csrf', csrf); body.append('channel', type); body.append('config', JSON.stringify(readChannel(type)));
  button.disabled = true;
  try { const response = await fetch('api_config_save.php', { method: 'POST', body }); const result = await response.json(); showNotice(Boolean(result.ok), result.message || (result.ok ? '接口配置已保存。' : '接口保存失败。')); }
  catch (_) { showNotice(false, '接口保存失败：无法连接保存接口。'); }
  finally { button.disabled = false; }
};
*/
const saveChannel = async (type, button) => {
  const body = new FormData();
  body.append('csrf', csrf);
  body.append('channel', type);
  body.append('config', JSON.stringify(readChannel(type)));
  button.disabled = true;
  try {
    const response = await fetch('api_config_save.php', { method: 'POST', body });
    const result = await response.json();
    showNotice(Boolean(result.ok), result.message || (result.ok ? 'API configuration saved.' : 'API configuration failed.'));
  } catch (_) { showNotice(false, 'API configuration could not be saved.'); }
  finally { button.disabled = false; }
};
const oldSave = document.querySelector('button[onclick="saveAllApiSettings()"]');
oldSave?.remove();
for (const type of ['pc', 'mobile', 'auto']) {
  const card = document.getElementById(`api-${type}-direct`)?.closest('.bg-slate-900.p-6.rounded-xl.border.border-slate-800.space-y-4');
  if (!card) continue;
  card.dataset.apiChannel = type;
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'picapi-channel-save';
  button.textContent = `保存${type === 'pc' ? ' PC' : type === 'mobile' ? ' 手机' : ' 自适应'}接口配置`;
  button.addEventListener('click', () => saveChannel(type, button));
  card.append(button);
}
window.saveAllApiSettings = () => {};
window.lucide?.createIcons();
})();
</script>
JS;
$script = strtr($scriptTemplate, [
    '__CONFIG__' => $configJson,
    '__DOCS_CONFIG__' => $docsJson,
    '__CATEGORIES__' => $categoriesJson,
    '__ENDPOINTS__' => $endpointsJson,
    '__DOCS_URL__' => $docsUrlJson,
    '__CSRF__' => $csrfJson,
    '__INITIAL_VIEW__' => $initialViewJson,
]);
$template = str_replace('</body>', $script . '</body>', $template);
echo $template;

function embedded_custom_api_template(): string
{
    return <<<'PICAPI_TEMPLATE'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>随机图片API - API 自定义配置面板</title>
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        slate: {
                            950: '#020617',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght=300;400;500;600&display=swap');
        body { font-family: 'Inter', 'Helvetica Neue', sans-serif; overflow: hidden; } /* 禁止全局外层滚动 */
        
        /* 自定义美化内置滚动条 */
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #0f172a; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #475569; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">

    <div id="login-page" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950 flex-col px-4">
        <div class="bg-slate-900 border border-slate-800 p-8 rounded-2xl shadow-2xl w-full max-w-md">
            <div class="text-center mb-8">
                <div class="inline-flex p-3 bg-slate-800 text-indigo-400 rounded-xl mb-3">
                    <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="4" y1="21" y2="14"/><line x1="4" x2="4" y1="10" y2="3"/><line x1="12" x2="12" y1="21" y2="12"/><line x1="12" x2="12" y1="8" y2="3"/><line x1="20" x2="20" y1="21" y2="16"/><line x1="20" x2="20" y1="12" y2="3"/><line x1="2" x2="6" y1="14" y2="14"/><line x1="10" x2="14" y1="8" y2="8"/><line x1="18" x2="22" y1="16" y2="16"/></svg>
                </div>
                <h1 class="text-2xl font-semibold tracking-tight text-white">PicAPI Admin</h1>
                <p class="text-sm text-slate-400 mt-1">随机图片服务 · 自定义配置控制台</p>
            </div>
            <form id="login-form" onsubmit="handleLogin(event)" class="space-y-5">
                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-1.5">管理员账号</label>
                    <input type="text" required value="admin" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-sm text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-500 transition">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-400 mb-1.5">密码</label>
                    <input type="password" required value="123456" class="w-full bg-slate-800 border border-slate-700 rounded-lg px-4 py-2.5 text-sm text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-500 transition">
                </div>
                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium py-2.5 rounded-lg transition shadow-lg shadow-indigo-600/20">
                    进入控制台
                </button>
            </form>
        </div>
    </div>

    <div id="main-dashboard" class="hidden h-screen w-screen flex flex-col md:flex-row overflow-hidden">
        
        <aside class="w-full md:w-64 md:min-w-[16rem] md:shrink-0 bg-slate-900 border-b md:border-b-0 md:border-r border-slate-800 flex flex-col justify-between z-10">
            <div>
                <div class="h-16 flex items-center px-6 border-b border-slate-800 justify-between">
                    <div class="flex items-center gap-2.5 font-semibold text-white">
                        <svg class="w-5 h-5 text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="4" y1="21" y2="14"/><line x1="4" x2="4" y1="10" y2="3"/><line x1="12" x2="12" y1="21" y2="12"/><line x1="12" x2="12" y1="8" y2="3"/><line x1="20" x2="20" y1="21" y2="16"/><line x1="20" x2="20" y1="12" y2="3"/><line x1="2" x2="6" y1="14" y2="14"/><line x1="10" x2="14" y1="8" y2="8"/><line x1="18" x2="22" y1="16" y2="16"/></svg>
                        <span>PicAPI Panel</span>
                    </div>
                </div>
                <nav class="p-4 space-y-1">
                    <a href="#" class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-slate-800 text-indigo-400 font-medium text-sm transition">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="4" y1="21" y2="14"/><line x1="4" x2="4" y1="10" y2="3"/><line x1="12" x2="12" y1="21" y2="12"/><line x1="12" x2="12" y1="8" y2="3"/><line x1="20" x2="20" y1="21" y2="16"/><line x1="20" x2="20" y1="12" y2="3"/><line x1="2" x2="6" y1="14" y2="14"/><line x1="10" x2="14" y1="8" y2="8"/><line x1="18" x2="22" y1="16" y2="16"/></svg>
                        API自定义配置
                    </a>
                </nav>
            </div>
            <div class="p-4 border-t border-slate-800 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-full bg-indigo-600 text-white flex items-center justify-center text-sm font-semibold">A</div>
                    <span class="text-sm font-medium text-slate-300">Admin</span>
                </div>
                <button onclick="handleLogout()" class="text-slate-500 hover:text-rose-400 transition" title="退出登录">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                </button>
            </div>
        </aside>

        <main class="flex-1 h-full overflow-y-auto p-4 md:p-8 space-y-8 custom-scrollbar bg-slate-950">
            <div class="lg:max-w-7xl mx-auto w-full space-y-6">
                
                <div class="bg-slate-900 p-6 rounded-xl border border-slate-800 space-y-2">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-indigo-500/10 text-indigo-400 flex items-center justify-center">
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="4" y1="21" y2="14"/><line x1="4" x2="4" y1="10" y2="3"/><line x1="12" x2="12" y1="21" y2="12"/><line x1="12" x2="12" y1="8" y2="3"/><line x1="20" x2="20" y1="21" y2="16"/><line x1="20" x2="20" y1="12" y2="3"/><line x1="2" x2="6" y1="14" y2="14"/><line x1="10" x2="14" y1="8" y2="8"/><line x1="18" x2="22" y1="16" y2="16"/></svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-white">API 输出权限与格式控制台</h2>
                            <p class="text-sm text-slate-400">独立控制“图片直链输出”、“URL明文数据”以及“自定义JSON数据”三大通道的访问权限。</p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 space-y-6">
                        
                        <div class="bg-slate-900 p-6 rounded-xl border border-slate-800 space-y-4">
                            <div class="border-b border-slate-800 pb-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <div class="w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-400 flex items-center justify-center">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/></svg>
                                    </div>
                                    <div>
                                        <h3 class="text-sm font-bold text-white">PC 端核心接口配置</h3>
                                        <span class="text-[10px] text-slate-500 font-mono">/api/v1/random?device=pc</span>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-3 bg-slate-950/60 rounded-xl border border-slate-800/80">
                                <div class="flex items-center justify-between bg-slate-900/80 px-3 py-2.5 rounded-lg border border-slate-800">
                                    <span class="text-xs text-slate-300 font-medium">直接输出图片</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="api-pc-direct" checked onchange="updateApiConfig()" class="sr-only peer">
                                        <div class="w-8 h-4 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-slate-400 after:border-slate-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-indigo-600"></div>
                                    </label>
                                </div>
                                <div class="flex items-center justify-between bg-slate-900/80 px-3 py-2.5 rounded-lg border border-slate-800">
                                    <span class="text-xs text-slate-300 font-medium">URL数据输出</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="api-pc-out-url" checked onchange="updateApiConfig()" class="sr-only peer">
                                        <div class="w-8 h-4 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-slate-400 after:border-slate-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-indigo-600"></div>
                                    </label>
                                </div>
                                <div class="flex items-center justify-between bg-slate-900/80 px-3 py-2.5 rounded-lg border border-slate-800">
                                    <span class="text-xs text-slate-300 font-medium">JSON数据输出</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="api-pc-out-json" checked onchange="updateApiConfig()" class="sr-only peer">
                                        <div class="w-8 h-4 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-slate-400 after:border-slate-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-indigo-600"></div>
                                    </label>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs pt-1">
                                <div>
                                    <label class="block text-slate-400 mb-1.5 font-medium">接口访问别名 (Alias)</label>
                                    <input type="text" id="api-pc-alias" value="/api/v1/random?device=pc" oninput="updateApiConfig()" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-white font-mono focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-slate-400 mb-1.5 font-medium">资源输出检索方式</label>
                                    <select id="api-pc-strategy" onchange="updateApiConfig()" class="w-full bg-slate-950 border border-slate-800 text-slate-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                        <option value="random">随机输出 (不重样洗牌法)</option>
                                        <option value="sequence">按顺序输出 (数据库物理轮播)</option>
                                    </select>
                                </div>
                                <div class="sm:col-span-2">
                                    <div class="flex items-center justify-between mb-1.5">
                                        <label class="block text-slate-400 font-medium">自定义 JSON 输出模板 data</label>
                                        <button onclick="resetJsonTemplate('pc')" class="text-[10px] text-indigo-400 hover:underline">重置默认</button>
                                    </div>
                                    <textarea id="api-pc-json" rows="4" oninput="updateApiConfig()" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-emerald-400 font-mono text-[11px] leading-relaxed focus:outline-none focus:ring-1 focus:ring-indigo-500">{
  "status": 200,
  "device": "pc",
  "url": "{{img_url}}",
  "width": "{{width}}",
  "height": "{{height}}",
  "format": "{{format}}"
}</textarea>
                                </div>
                            </div>
                        </div>

                        <div class="bg-slate-900 p-6 rounded-xl border border-slate-800 space-y-4">
                            <div class="border-b border-slate-800 pb-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <div class="w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-400 flex items-center justify-center">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><line x1="12" x2="12" y1="18" y2="18.01"/></svg>
                                    </div>
                                    <div>
                                        <h3 class="text-sm font-bold text-white">手机端核心接口配置</h3>
                                        <span class="text-[10px] text-slate-500 font-mono">/api/v1/random?device=mobile</span>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-3 bg-slate-950/60 rounded-xl border border-slate-800/80">
                                <div class="flex items-center justify-between bg-slate-900/80 px-3 py-2.5 rounded-lg border border-slate-800">
                                    <span class="text-xs text-slate-300 font-medium">直接输出图片</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="api-mobile-direct" checked onchange="updateApiConfig()" class="sr-only peer">
                                        <div class="w-8 h-4 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-slate-400 after:border-slate-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-emerald-600"></div>
                                    </label>
                                </div>
                                <div class="flex items-center justify-between bg-slate-900/80 px-3 py-2.5 rounded-lg border border-slate-800">
                                    <span class="text-xs text-slate-300 font-medium">URL数据输出</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="api-mobile-out-url" checked onchange="updateApiConfig()" class="sr-only peer">
                                        <div class="w-8 h-4 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-slate-400 after:border-slate-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-emerald-600"></div>
                                    </label>
                                </div>
                                <div class="flex items-center justify-between bg-slate-900/80 px-3 py-2.5 rounded-lg border border-slate-800">
                                    <span class="text-xs text-slate-300 font-medium">JSON数据输出</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="api-mobile-out-json" checked onchange="updateApiConfig()" class="sr-only peer">
                                        <div class="w-8 h-4 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-slate-400 after:border-slate-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-emerald-600"></div>
                                    </label>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs pt-1">
                                <div>
                                    <label class="block text-slate-400 mb-1.5 font-medium">接口访问别名 (Alias)</label>
                                    <input type="text" id="api-mobile-alias" value="/api/v1/random?device=mobile" oninput="updateApiConfig()" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-white font-mono focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                </div>
                                <div>
                                    <label class="block text-slate-400 mb-1.5 font-medium">资源输出检索方式</label>
                                    <select id="api-mobile-strategy" onchange="updateApiConfig()" class="w-full bg-slate-950 border border-slate-800 text-slate-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                                        <option value="random">随机输出 (不重样洗牌法)</option>
                                        <option value="sequence">按顺序输出 (数据库物理轮播)</option>
                                    </select>
                                </div>
                                <div class="sm:col-span-2">
                                    <div class="flex items-center justify-between mb-1.5">
                                        <label class="block text-slate-400 font-medium">自定义 JSON 输出模板数据</label>
                                        <button onclick="resetJsonTemplate('mobile')" class="text-[10px] text-emerald-400 hover:underline">重置默认</button>
                                    </div>
                                    <textarea id="api-mobile-json" rows="4" oninput="updateApiConfig()" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-emerald-400 font-mono text-[11px] leading-relaxed focus:outline-none focus:ring-1 focus:ring-emerald-500">{
  "status": 200,
  "device": "mobile",
  "url": "{{img_url}}",
  "width": "{{width}}",
  "height": "{{height}}",
  "format": "{{format}}"
}</textarea>
                                </div>
                            </div>
                        </div>

                        <div class="bg-slate-900 p-6 rounded-xl border border-slate-800 space-y-4">
                            <div class="border-b border-slate-800 pb-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <div class="w-8 h-8 rounded-lg bg-amber-500/10 text-amber-400 flex items-center justify-center">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" x2="12" y1="17" y2="17"/></svg>
                                    </div>
                                    <div>
                                        <h3 class="text-sm font-bold text-white">自适应终端接口配置</h3>
                                        <span class="text-[10px] text-slate-500 font-mono">/api/v1/random?device=auto</span>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-3 bg-slate-950/60 rounded-xl border border-slate-800/80">
                                <div class="flex items-center justify-between bg-slate-900/80 px-3 py-2.5 rounded-lg border border-slate-800">
                                    <span class="text-xs text-slate-300 font-medium">直接输出图片</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="api-auto-direct" checked onchange="updateApiConfig()" class="sr-only peer">
                                        <div class="w-8 h-4 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-slate-400 after:border-slate-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-amber-600"></div>
                                    </label>
                                </div>
                                <div class="flex items-center justify-between bg-slate-900/80 px-3 py-2.5 rounded-lg border border-slate-800">
                                    <span class="text-xs text-slate-300 font-medium">URL数据输出</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="api-auto-out-url" checked onchange="updateApiConfig()" class="sr-only peer">
                                        <div class="w-8 h-4 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-slate-400 after:border-slate-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-amber-600"></div>
                                    </label>
                                </div>
                                <div class="flex items-center justify-between bg-slate-900/80 px-3 py-2.5 rounded-lg border border-slate-800">
                                    <span class="text-xs text-slate-300 font-medium">JSON数据输出</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="api-auto-out-json" checked onchange="updateApiConfig()" class="sr-only peer">
                                        <div class="w-8 h-4 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-slate-400 after:border-slate-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-amber-600"></div>
                                    </label>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs pt-1">
                                <div>
                                    <label class="block text-slate-400 mb-1.5 font-medium">接口访问别名 (Alias)</label>
                                    <input type="text" id="api-auto-alias" value="/api/v1/random?device=auto" oninput="updateApiConfig()" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-white font-mono focus:outline-none focus:ring-1 focus:ring-amber-500">
                                </div>
                                <div>
                                    <label class="block text-slate-400 mb-1.5 font-medium">资源输出检索方式</label>
                                    <select id="api-auto-strategy" onchange="updateApiConfig()" class="w-full bg-slate-950 border border-slate-800 text-slate-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-amber-500">
                                        <option value="random" selected>随机输出 (不重样洗牌法)</option>
                                        <option value="sequence">按顺序输出 (数据库物理轮播)</option>
                                    </select>
                                </div>
                                <div class="sm:col-span-2">
                                    <div class="flex items-center justify-between mb-1.5">
                                        <label class="block text-slate-400 font-medium">自定义 JSON 输出模板数据</label>
                                        <button onclick="resetJsonTemplate('auto')" class="text-[10px] text-amber-400 hover:underline">重置默认</button>
                                    </div>
                                    <textarea id="api-auto-json" rows="4" oninput="updateApiConfig()" class="w-full bg-slate-950 border border-slate-800 rounded-lg p-3 text-emerald-400 font-mono text-[11px] leading-relaxed focus:outline-none focus:ring-1 focus:ring-amber-500">{
  "status": 200,
  "device": "auto_detect",
  "client_ua": "{{user_agent}}",
  "url": "{{img_url}}",
  "width": "{{width}}",
  "height": "{{height}}",
  "format": "{{format}}"
}</textarea>
                                </div>
                            </div>
                        </div>

                    </div>

                    <div class="space-y-6 font-sans">
                        <div class="bg-slate-900 p-6 rounded-xl border border-slate-800 space-y-4">
                            <h3 class="text-sm font-semibold text-white flex items-center gap-2">
                                <svg class="w-4 h-4 text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                接口响应实时模拟器
                            </h3>
                            <p class="text-[11px] text-slate-400">切换下方请求格式，实时调试在不同开关控制下的 API 返回结果。</p>
                            
                            <div class="space-y-3">
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-[10px] text-slate-400 mb-1">测试通道</label>
                                        <select id="simulator-channel" onchange="runApiSimulator()" class="w-full bg-slate-950 border border-slate-800 text-xs text-slate-300 rounded-lg px-2 py-1.5 focus:outline-none">
                                            <option value="pc">PC 核心端 (pc)</option>
                                            <option value="mobile">手机端 (mobile)</option>
                                            <option value="auto">自适应端 (auto)</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] text-slate-400 mb-1">请求格式 (&format=)</label>
                                        <select id="simulator-format" onchange="runApiSimulator()" class="w-full bg-slate-950 border border-slate-800 text-xs text-slate-300 rounded-lg px-2 py-1.5 focus:outline-none">
                                            <option value="redirect">redirect (直接图片)</option>
                                            <option value="url">url (纯文本路径)</option>
                                            <option value="json">json (JSON数据)</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="bg-slate-950 rounded-lg p-3 border border-slate-800 space-y-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[10px] font-semibold text-slate-400">Status Code: <span id="simulator-status-badge" class="text-emerald-400 font-mono">200 OK</span></span>
                                        <span id="simulator-output-type" class="text-[9px] px-1.5 py-0.5 rounded bg-indigo-500/10 text-indigo-400 font-mono">TEXT</span>
                                    </div>
                                    <pre id="simulator-output-box" class="text-[11px] font-mono text-indigo-300 overflow-x-auto whitespace-pre-wrap max-h-64 custom-scrollbar">{}</pre>
                                </div>
                            </div>
                        </div>

                        <div class="bg-indigo-950/30 border border-indigo-500/20 rounded-xl p-6 space-y-3">
                            <h3 class="text-xs font-semibold text-white flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="16" y2="12"/><line x1="12" x2="12" y1="8" y2="8"/></svg>
                                开关逻辑说明
                            </h3>
                            <ul class="space-y-2 text-[11px] text-slate-400 leading-relaxed">
                                <li>• <strong class="text-white">直接输出图片：</strong>控制直接在浏览器输入 API 链接或放置在 <code class="text-emerald-400">&lt;img&gt;</code> 标签时，是否进行 302 图像重定向。</li>
                                <li>• <strong class="text-white">URL数据输出：</strong>控制请求格式为纯文本时（例如携带参数 <code class="text-emerald-400">format=url</code>），是否允许返回图片直链地址。</li>
                                <li>• <strong class="text-white">JSON数据输出：</strong>控制请求格式为 JSON 时，是否允许返回包含宽高度及元信息的 JSON 字符串。</li>
                            </ul>
                        </div>

                        <button onclick="saveAllApiSettings()" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium py-3 rounded-lg transition shadow-lg shadow-indigo-600/20 flex items-center justify-center gap-1.5">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            部署应用新规则配置
                        </button>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <script>
        // 默认配置模板数据备份
        const defaultJsonTemplates = {
            pc: '{\n  "status": 200,\n  "device": "pc",\n  "url": "{{img_url}}",\n  "width": "{{width}}",\n  "height": "{{height}}",\n  "format": "{{format}}"\n}',
            mobile: '{\n  "status": 200,\n  "device": "mobile",\n  "url": "{{img_url}}",\n  "width": "{{width}}",\n  "height": "{{height}}",\n  "format": "{{format}}"\n}',
            auto: '{\n  "status": 200,\n  "device": "auto_detect",\n  "client_ua": "{{user_agent}}",\n  "url": "{{img_url}}",\n  "width": "{{width}}",\n  "height": "{{height}}",\n  "format": "{{format}}"\n}'
        };

        // 三路开关全局状态模型
        let apiConfigurations = {
            pc: { direct: true, outUrl: true, outJson: true, alias: '/api/v1/random?device=pc', strategy: 'random', json: '' },
            mobile: { direct: true, outUrl: true, outJson: true, alias: '/api/v1/random?device=mobile', strategy: 'random', json: '' },
            auto: { direct: true, outUrl: true, outJson: true, alias: '/api/v1/random?device=auto', strategy: 'random', json: '' }
        };

        // 页面启动入口
        window.addEventListener('DOMContentLoaded', () => {
            updateApiConfig();
        });

        // 登录控制逻辑
        function handleLogin(event) {
            event.preventDefault();
            document.getElementById('login-page').classList.add('hidden');
            document.getElementById('main-dashboard').classList.remove('hidden');
        }

        // 登出逻辑
        function handleLogout() {
            document.getElementById('main-dashboard').classList.add('hidden');
            document.getElementById('login-page').classList.remove('hidden');
        }

        // 同步配置状态并刷新模拟器
        function updateApiConfig() {
            ['pc', 'mobile', 'auto'].forEach(type => {
                apiConfigurations[type].direct = document.getElementById(`api-${type}-direct`).checked;
                apiConfigurations[type].outUrl = document.getElementById(`api-${type}-out-url`).checked;
                apiConfigurations[type].outJson = document.getElementById(`api-${type}-out-json`).checked;
                apiConfigurations[type].alias = document.getElementById(`api-${type}-alias`).value;
                apiConfigurations[type].strategy = document.getElementById(`api-${type}-strategy`).value;
                apiConfigurations[type].json = document.getElementById(`api-${type}-json`).value;
            });

            runApiSimulator();
        }

        // 重置为默认 JSON 模板
        function resetJsonTemplate(type) {
            if (defaultJsonTemplates[type]) {
                document.getElementById(`api-${type}-json`).value = defaultJsonTemplates[type];
                updateApiConfig();
            }
        }

        // 模拟接口响应请求（完美模拟三路开关拦截）
        function runApiSimulator() {
            const currentChannel = document.getElementById('simulator-channel').value;
            const requestedFormat = document.getElementById('simulator-format').value;
            const config = apiConfigurations[currentChannel];
            
            const outputBox = document.getElementById('simulator-output-box');
            const typeBadge = document.getElementById('simulator-output-type');
            const statusBadge = document.getElementById('simulator-status-badge');

            // 模拟抓取到的随机图片
            let mockImgUrl = currentChannel === 'mobile' 
                ? "https://i.xiaofa520.top/mobile/custom_bg.png" 
                : "https://i.xiaofa520.top/pc/custom_wallpaper.png";

            // 根据不同的请求格式，判断对应的开关是否允许输出
            if (requestedFormat === 'redirect') {
                // 1. 直接输出图片 (302 Redirect) 校验
                if (config.direct) {
                    statusBadge.innerText = "302 Found";
                    statusBadge.className = "text-amber-400 font-mono";
                    typeBadge.innerText = "REDIRECT (图像直链重定向)";
                    typeBadge.className = "text-[9px] px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-400 font-mono";
                    outputBox.innerText = `HTTP/1.1 302 Found\nLocation: ${mockImgUrl}\nCache-Control: no-cache\n\n[终端访问此 API 将直接重定向渲染该图片]`;
                } else {
                    statusBadge.innerText = "403 Forbidden";
                    statusBadge.className = "text-rose-500 font-mono";
                    typeBadge.innerText = "ACCESS DENIED";
                    typeBadge.className = "text-[9px] px-1.5 py-0.5 rounded bg-rose-500/10 text-rose-400 font-mono";
                    outputBox.innerText = `HTTP/1.1 403 Forbidden\nContent-Type: text/plain; charset=utf-8\n\nError: Direct image redirect format has been disabled by administrator.`;
                }
            } 
            else if (requestedFormat === 'url') {
                // 2. URL 纯文本输出校验
                if (config.outUrl) {
                    statusBadge.innerText = "200 OK";
                    statusBadge.className = "text-emerald-400 font-mono";
                    typeBadge.innerText = "TEXT / PLAIN";
                    typeBadge.className = "text-[9px] px-1.5 py-0.5 rounded bg-indigo-500/10 text-indigo-400 font-mono";
                    outputBox.innerText = mockImgUrl;
                } else {
                    statusBadge.innerText = "403 Forbidden";
                    statusBadge.className = "text-rose-500 font-mono";
                    typeBadge.innerText = "ACCESS DENIED";
                    typeBadge.className = "text-[9px] px-1.5 py-0.5 rounded bg-rose-500/10 text-rose-400 font-mono";
                    outputBox.innerText = `HTTP/1.1 403 Forbidden\nContent-Type: text/plain; charset=utf-8\n\nError: Plain text URL format output has been disabled.`;
                }
            } 
            else if (requestedFormat === 'json') {
                // 3. JSON 数据输出校验
                if (config.outJson) {
                    statusBadge.innerText = "200 OK";
                    statusBadge.className = "text-emerald-400 font-mono";
                    typeBadge.innerText = "APPLICATION / JSON";
                    typeBadge.className = "text-[9px] px-1.5 py-0.5 rounded bg-emerald-500/10 text-emerald-400 font-mono";
                    
                    let baseJson = config.json;
                    let randomWidth = currentChannel === 'mobile' ? 1080 : 1920;
                    let randomHeight = currentChannel === 'mobile' ? 2400 : 1080;

                    let parsed = baseJson
                        .replace(/\{\{img_url\}\}/g, mockImgUrl)
                        .replace(/\{\{width\}\}/g, randomWidth)
                        .replace(/\{\.height\}\}/g, randomHeight) // 兼容正则
                        .replace(/\{\{height\}\}/g, randomHeight)
                        .replace(/\{\{format\}\}/g, "png")
                        .replace(/\{\{user_agent\}\}/g, navigator.userAgent);

                    try {
                        const parsedObj = JSON.parse(parsed);
                        outputBox.innerText = JSON.stringify(parsedObj, null, 2);
                    } catch(e) {
                        outputBox.innerHTML = `<span class="text-rose-400">⚠️ JSON 语法校验失败，请检查文本域:\n${e.message}</span>`;
                    }
                } else {
                    statusBadge.innerText = "403 Forbidden";
                    statusBadge.className = "text-rose-500 font-mono";
                    typeBadge.innerText = "ACCESS DENIED";
                    typeBadge.className = "text-[9px] px-1.5 py-0.5 rounded bg-rose-500/10 text-rose-400 font-mono";
                    outputBox.innerText = `{\n  "status": 403,\n  "error": "JSON data format output has been disabled."\n}`;
                }
            }
        }

        // 保存部署通知
        function saveAllApiSettings() {
            /* 此演示提示已由 PHP 后端的实际保存逻辑接管。
            alert('⚙️ 全新三路独立开关配置（直接图片/URL/JSON）已统一部署成功！\n数据已被原子化写入后端配置文件中。');
        }
            */ }
    </script>
</body>
</html>
PICAPI_TEMPLATE;
}
