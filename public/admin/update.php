<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/update_runtime.php';
initialize_storage();
require_admin();
send_admin_security_headers();
header('Cache-Control: no-store, no-cache, must-revalidate');

if (($_GET['install'] ?? '') !== '1') redirect('index.php#settings?tab=other');
$csrf = csrf_token();
$settings = site_settings();
$themeMode = in_array($settings['theme_mode'] ?? 'light', ['light', 'dark', 'auto'], true) ? $settings['theme_mode'] : 'light';
$siteName = (string) ($settings['site_name'] ?? 'PicAPI');
?>
<!doctype html>
<html lang="zh-CN" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <meta name="robots" content="noindex,nofollow,noarchive,nosnippet">
  <title><?= html($siteName) ?> · 自动更新</title>
  <link rel="icon" href="<?= html(site_favicon_url()) ?>">
  <script>
    (() => {
      let mode = <?= json_encode($themeMode, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      try {
        const saved = localStorage.getItem('picapi-production-theme');
        if (['light', 'dark', 'auto'].includes(saved)) mode = saved;
      } catch {}
      const media = window.matchMedia('(prefers-color-scheme: dark)');
      const applyTheme = () => {
        document.documentElement.dataset.theme = mode === 'auto' ? (media.matches ? 'dark' : 'light') : mode;
      };
      applyTheme();
      media.addEventListener?.('change', applyTheme);
    })();
  </script>
  <style>
    :root {
      color-scheme: light;
      --update-light-accent: <?= html((string) ($settings['light_theme_color'] ?? '#1974d2')) ?>;
      --update-light-bg: <?= html((string) ($settings['light_theme_background'] ?? '#f5f7fb')) ?>;
      --update-light-surface: <?= html((string) ($settings['light_theme_surface'] ?? '#ffffff')) ?>;
      --update-light-ink: <?= html((string) ($settings['light_theme_text'] ?? '#2c3b50')) ?>;
      --update-light-muted: <?= html((string) ($settings['light_theme_muted'] ?? '#728198')) ?>;
      --update-dark-accent: <?= html((string) ($settings['dark_theme_color'] ?? '#80bfff')) ?>;
      --update-dark-bg: <?= html((string) ($settings['dark_theme_background'] ?? '#111924')) ?>;
      --update-dark-surface: <?= html((string) ($settings['dark_theme_surface'] ?? '#1a2535')) ?>;
      --update-dark-ink: <?= html((string) ($settings['dark_theme_text'] ?? '#edf2fa')) ?>;
      --update-dark-muted: <?= html((string) ($settings['dark_theme_muted'] ?? '#b1bdd0')) ?>;
      --font: "Segoe UI", "Microsoft YaHei", "PingFang SC", sans-serif;
      --topbar-height: 66px;
    }
    :root[data-theme="light"] {
      color-scheme: light;
      --accent: var(--update-light-accent);
      --bg: var(--update-light-bg);
      --surface: var(--update-light-surface);
      --ink: var(--update-light-ink);
      --muted: var(--update-light-muted);
      --line: color-mix(in srgb, var(--ink) 14%, var(--surface));
      --surface-alt: color-mix(in srgb, var(--bg) 90%, var(--ink));
      --accent-soft: color-mix(in srgb, var(--accent) 12%, var(--surface));
      --shadow: 0 12px 40px color-mix(in srgb, var(--ink) 8%, transparent);
    }
    :root[data-theme="dark"] {
      color-scheme: dark;
      --accent: var(--update-dark-accent);
      --bg: var(--update-dark-bg);
      --surface: var(--update-dark-surface);
      --ink: var(--update-dark-ink);
      --muted: var(--update-dark-muted);
      --line: color-mix(in srgb, var(--ink) 15%, var(--surface));
      --surface-alt: color-mix(in srgb, var(--bg) 88%, var(--ink));
      --accent-soft: color-mix(in srgb, var(--accent) 15%, var(--surface));
      --shadow: 0 20px 60px #0003;
    }
    * { box-sizing: border-box; }
    html { min-height: 100%; background: var(--bg); }
    body {
      min-height: 100vh;
      margin: 0;
      background: var(--bg);
      color: var(--ink);
      font: 14px/1.65 var(--font);
      -webkit-font-smoothing: antialiased;
    }
    a { color: inherit; }
    a:focus-visible { outline: 3px solid var(--accent); outline-offset: 3px; }
    .update-topbar {
      position: sticky;
      top: 0;
      z-index: 2;
      display: flex;
      align-items: center;
      justify-content: space-between;
      min-height: var(--topbar-height);
      padding: 0 clamp(18px, 3vw, 38px);
      border-bottom: 1px solid var(--line);
      background: color-mix(in srgb, var(--surface) 94%, transparent);
      backdrop-filter: blur(18px);
    }
    .update-brand, .update-topbar-meta, .update-breadcrumb { display: flex; align-items: center; }
    .update-brand { gap: 11px; min-width: 0; font-size: 15px; font-weight: 700; letter-spacing: -.25px; }
    .update-brand span { overflow: hidden; max-width: min(32vw, 260px); text-overflow: ellipsis; white-space: nowrap; }
    .update-brand img { width: 29px; height: 29px; border-radius: 8px; object-fit: cover; }
    .update-breadcrumb {
      position: absolute;
      left: 50%;
      gap: 13px;
      transform: translateX(-50%);
      color: var(--muted);
      font-size: 12px;
      white-space: nowrap;
    }
    .update-breadcrumb strong { color: var(--ink); font-weight: 550; }
    .update-topbar-meta { gap: 9px; color: var(--muted); font-size: 11px; }
    .update-live-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--accent); box-shadow: 0 0 0 4px var(--accent-soft); }
    .update-topbar-version { padding-left: 13px; border-left: 1px solid var(--line); font-variant-numeric: tabular-nums; }
    .update-main {
      display: grid;
      min-height: calc(100dvh - var(--topbar-height));
      place-items: center;
      padding: clamp(30px, 7vh, 76px) 24px;
    }
    .update-content { width: min(100%, 680px); animation: rise-in .48s cubic-bezier(.2,.75,.25,1) both; }
    .update-card {
      overflow: hidden;
      border: 1px solid var(--line);
      border-radius: 13px;
      background: var(--surface);
      box-shadow: var(--shadow);
    }
    .update-card-head { display: flex; align-items: flex-start; gap: 17px; padding: 31px 34px 26px; }
    .update-mark {
      display: grid;
      flex: 0 0 46px;
      width: 46px;
      height: 46px;
      place-items: center;
      border: 1px solid color-mix(in srgb, var(--accent) 28%, var(--line));
      border-radius: 12px;
      background: var(--accent-soft);
      color: var(--accent);
    }
    .update-mark svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 1.8; animation: turn 2.4s linear infinite; }
    .update-eyebrow { margin: 0 0 5px; color: var(--accent); font-size: 10px; font-weight: 700; letter-spacing: 1.05px; }
    .update-card h1 { margin: 0; font-size: clamp(20px, 3vw, 24px); font-weight: 650; letter-spacing: -.55px; line-height: 1.35; }
    .update-card-head p { margin: 8px 0 0; color: var(--muted); font-size: 12px; }
    .update-progress-wrap { padding: 0 34px 25px; }
    .update-progress { height: 5px; overflow: hidden; border-radius: 99px; background: var(--surface-alt); }
    .update-progress span { display: block; width: 34%; height: 100%; border-radius: inherit; background: var(--accent); animation: progress 1.3s cubic-bezier(.6,0,.4,1) infinite alternate; }
    .update-card[data-state="success"] .update-progress span { width: 100%; background: #36b987; animation: none; }
    .update-card[data-state="error"] .update-progress span { width: 100%; background: #d45d6f; animation: none; }
    .update-card[data-state="success"] .update-mark { color: #36b987; background: color-mix(in srgb, #36b987 12%, var(--surface)); border-color: color-mix(in srgb, #36b987 28%, var(--line)); }
    .update-card[data-state="error"] .update-mark { color: #d45d6f; background: color-mix(in srgb, #d45d6f 12%, var(--surface)); border-color: color-mix(in srgb, #d45d6f 28%, var(--line)); }
    .update-card[data-state="success"] .update-mark svg, .update-card[data-state="error"] .update-mark svg { animation: none; }
    .update-card-body { padding: 20px 34px 24px; border-top: 1px solid var(--line); }
    .update-status-label { display: flex; align-items: center; gap: 9px; margin-bottom: 6px; color: var(--muted); font-size: 10px; font-weight: 650; letter-spacing: .7px; text-transform: uppercase; }
    .update-status-label::before { width: 5px; height: 5px; border-radius: 50%; background: var(--accent); content: ""; }
    .update-status { min-height: 26px; font-size: 13px; font-weight: 550; overflow-wrap: anywhere; }
    .update-card[data-state="error"] .update-status-label::before { background: #d45d6f; }
    .update-actions { display: flex; gap: 10px; margin-top: 19px; }
    .update-actions a { display: inline-flex; align-items: center; justify-content: center; min-height: 39px; padding: 0 15px; border: 1px solid var(--line); border-radius: 7px; color: var(--ink); font-size: 12px; font-weight: 600; text-decoration: none; transition: background .16s, border-color .16s; }
    .update-actions a:hover { border-color: var(--accent); background: var(--accent-soft); }
    .update-actions a[hidden] { display: none; }
    .update-note { margin: 16px 4px 0; color: var(--muted); font-size: 11px; text-align: center; }
    @keyframes progress { from { transform: translateX(-75%); } to { transform: translateX(205%); } }
    @keyframes turn { to { transform: rotate(360deg); } }
    @keyframes rise-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    @media (max-width: 640px) {
      :root { --topbar-height: 58px; }
      .update-topbar { padding: 0 17px; }
      .update-breadcrumb { position: static; margin-left: 15px; transform: none; }
      .update-breadcrumb span, .update-topbar-version { display: none; }
      .update-topbar-meta { font-size: 10px; }
      .update-brand span { max-width: 27vw; }
      .update-main { min-height: calc(100svh - var(--topbar-height)); padding: 24px 16px 32px; }
      .update-card-head { gap: 13px; padding: 24px 21px 21px; }
      .update-mark { flex-basis: 40px; width: 40px; height: 40px; }
      .update-progress-wrap { padding: 0 21px 21px; }
      .update-card-body { padding: 18px 21px 21px; }
    }
    @media (max-width: 390px) {
      .update-breadcrumb { display: none; }
      .update-brand span { max-width: 43vw; }
    }
    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after { scroll-behavior: auto !important; animation-duration: .01ms !important; animation-iteration-count: 1 !important; }
    }
  </style>
</head>
<body data-csrf="<?= html($csrf) ?>">
  <header class="update-topbar" aria-label="顶部导航">
    <div class="update-brand"><img src="<?= html(site_favicon_url()) ?>" width="29" height="29" alt=""><span><?= html($siteName) ?></span></div>
    <div class="update-breadcrumb"><span>系统设置</span><span aria-hidden="true">/</span><strong>在线更新</strong></div>
    <div class="update-topbar-meta"><span class="update-live-dot" aria-hidden="true"></span><span>管理后台</span><span class="update-topbar-version">v<?= html(APP_VERSION) ?></span></div>
  </header>
  <main class="update-main">
    <div class="update-content">
      <section class="update-card" id="update-card" data-state="running" aria-live="polite" aria-busy="true">
        <div class="update-card-head">
          <div class="update-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 7v5h-5"></path><path d="M4.8 9a7.5 7.5 0 0 1 12.8-2L20 12M4 17v-5h5"></path><path d="M19.2 15a7.5 7.5 0 0 1-12.8 2L4 12"></path></svg></div>
          <div>
            <div class="update-eyebrow">系统维护 · ONLINE UPDATE</div>
            <h1>正在自动更新 <?= html($siteName) ?></h1>
            <p>更新包来自官方 GitHub 仓库。程序文件会先备份，图片、设置和数据库会保留。</p>
          </div>
        </div>
        <div class="update-progress-wrap"><div class="update-progress" aria-hidden="true"><span></span></div></div>
        <div class="update-card-body">
          <div class="update-status-label">更新状态</div>
          <div class="update-status" id="update-status">正在校验版本并准备下载…</div>
          <div class="update-actions"><a id="return-link" href="index.php#settings?tab=other" hidden>返回后台</a></div>
        </div>
      </section>
      <p class="update-note">请保持此页面开启，直到更新结果显示完成。</p>
    </div>
  </main>
  <script src="assets/update.js?v=automatic-update-2" defer></script>
</body>
</html>
