<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/update_runtime.php';
initialize_storage();
require_admin();
send_admin_security_headers();
header('Cache-Control: no-store, no-cache, must-revalidate');

if (($_GET['install'] ?? '') !== '1') redirect('index.php#settings?tab=other');
$csrf = csrf_token();
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive,nosnippet">
  <title>PicAPI 自动更新</title>
  <style>
    :root{color-scheme:dark;--bg:#111827;--panel:#1e293b;--line:#334155;--ink:#e8eef7;--muted:#9eacc1;--accent:#a78bfa;--danger:#f87171}*{box-sizing:border-box}body{min-height:100vh;margin:0;display:grid;place-items:center;padding:24px;background:var(--bg);color:var(--ink);font:15px/1.7 system-ui,-apple-system,"Segoe UI",sans-serif}.update-card{width:min(520px,100%);padding:32px;border:1px solid var(--line);border-radius:18px;background:var(--panel);box-shadow:0 24px 70px #0004}.update-mark{display:grid;place-items:center;width:48px;height:48px;margin-bottom:20px;border-radius:15px;background:color-mix(in srgb,var(--accent) 20%,transparent);color:var(--accent);font-size:24px}.update-card h1{margin:0;font-size:22px}.update-card p{margin:9px 0 0;color:var(--muted)}.update-progress{height:7px;margin:24px 0 12px;overflow:hidden;border-radius:99px;background:#ffffff16}.update-progress span{display:block;width:40%;height:100%;border-radius:inherit;background:var(--accent);animation:move 1.15s ease-in-out infinite alternate}.update-card[data-state=success] .update-progress span{width:100%;animation:none;background:#34d399}.update-card[data-state=error] .update-progress span{width:100%;animation:none;background:var(--danger)}.update-status{min-height:26px;font-size:13px}.update-actions{display:flex;gap:10px;margin-top:23px}.update-actions a{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 16px;border:1px solid var(--line);border-radius:9px;color:var(--ink);text-decoration:none}.update-actions a[hidden]{display:none}@keyframes move{from{transform:translateX(-50%)}to{transform:translateX(200%)}}@media(prefers-reduced-motion:reduce){.update-progress span{animation:none}}
  </style>
</head>
<body data-csrf="<?= html($csrf) ?>">
  <main class="update-card" id="update-card" data-state="running" aria-live="polite">
    <div class="update-mark" aria-hidden="true">↻</div>
    <h1>正在自动更新 PicAPI</h1>
    <p>更新包来自官方 GitHub 仓库。图片、站点设置和数据库保留，程序文件会先自动备份。</p>
    <div class="update-progress" aria-hidden="true"><span></span></div>
    <div class="update-status" id="update-status">正在校验版本并准备下载…</div>
    <div class="update-actions"><a id="return-link" href="index.php#settings?tab=other" hidden>返回后台</a></div>
  </main>
  <script src="assets/update.js?v=automatic-update-1" defer></script>
</body>
</html>
