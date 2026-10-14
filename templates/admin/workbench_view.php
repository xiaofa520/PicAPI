<?php if (!isset($workbenchRoute)) { http_response_code(404); exit; } $workbenchSiteSettings = site_settings(); ?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <meta name="description" content="<?= html(site_settings()['site_description']) ?>">
  <title><?= html($workbenchSiteSettings['site_name']) ?> · 桌面素材台</title>
  <link rel="icon" href="<?= html(site_favicon_url()) ?>">
  <link rel="stylesheet" href="assets/styles.css?v=modal-overlay-blur-1"><link rel="stylesheet" href="assets/studio.css?v=upload-conflict-checkbox-align-2"><link rel="stylesheet" href="assets/api-design.css?v=2">
 <script src="assets/workbench.js?v=thumbnail-cache-2" defer></script><link rel="stylesheet" href="assets/html-editor.css?v=1"><link rel="stylesheet" href="assets/docs-workspace.css?v=modal-overlay-blur-1"><script src="assets/html-editor.js?v=1" defer></script><link rel="stylesheet" href="assets/access-log.css?v=dropdown-motion-1"><script src="assets/preferences.js?v=site-seo-fields-1" defer></script><script src="assets/account.js?v=avatar-cropper-5" defer></script><script src="assets/motion.js?v=production-1" defer></script><script src="assets/sidebar.js?v=production-1" defer></script><script src="assets/security.js?v=config-ui-1" defer></script><script src="assets/login-logs.js?v=split-login-system-browser-1" defer></script><link rel="stylesheet" href="assets/site-icon.css?v=site-icon-crop-layout-5"><script src="assets/site-icon.js?v=site-icon-root-sync-1" defer></script><script src="assets/session-settings.js?v=autosave-1" defer></script><script src="assets/app.js?v=upload-queue-scroll-3" defer></script><script src="assets/docs-markdown.js?v=domain-token-1" defer></script><script src="assets/configuration.js?v=docs-backend-palette-1" defer></script><script src="assets/docs-editor.js?v=demo-promoted-1" defer></script><script src="assets/api-design.js?v=json-variable-help-1" defer></script><script src="assets/production.js?v=update-button-fix-1" defer></script></head>
<body data-app-version="<?= html(APP_VERSION) ?>">
<script type="application/json" id="workbench-context"><?= json_encode(['csrf'=>csrf_token(),'route'=>$workbenchRoute], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <a class="skip-link" href="#main">跳到主要内容</a>
  <div class="nav-scrim" id="nav-scrim"></div>
  <header class="topbar">

    <button class="icon-button mobile-menu" id="menu-toggle" aria-label="收起导航" aria-expanded="true" aria-controls="primary-navigation"><span data-icon="menu"></span></button>
    <div class="breadcrumb"><span data-site-name><?= html($workbenchSiteSettings['site_name']) ?></span><span>/</span><strong id="breadcrumb-page">总览</strong></div>
    <div class="topbar-tools"><span class="demo-badge"><span class="tiny-dot" data-icon="status"></span>管理后台</span><button class="icon-button" id="theme-toggle" aria-label="当前浅色，切换到深色" title="当前：浅色 · 点击切换到深色"><span data-icon="sun"></span></button></div>
  </header>
  <aside class="sidebar" id="primary-navigation" aria-label="主要导航">
    <div class="workspace-nav">
      <div class="workspace-heading"><a class="workspace-brand" href="#overview" aria-label="<?= html($workbenchSiteSettings['site_name']) ?> 总览"><img id="workspace-site-icon" src="<?= html(site_favicon_url()) ?>" width="28" height="28" alt=""><span data-site-name><?= html($workbenchSiteSettings['site_name']) ?></span></a><button class="icon-button nav-close" data-action="close-nav" aria-label="关闭导航"><span data-icon="close"></span></button></div>
      <div class="workspace-scroll">
        <nav id="navigation">
          <a href="#overview" data-page="overview"><span data-icon="overview"></span><span>数据总览</span></a>
          <a href="#gallery" data-page="gallery"><span data-icon="image"></span><span>全部图片</span><small id="sidebar-total">—</small></a>
          <a href="#upload" data-page="upload"><span data-icon="upload"></span><span>上传图片</span></a>
          <a href="#categories" data-page="categories"><span data-icon="folder"></span><span>分类管理</span></a>
          <div class="nav-label">接口与服务</div>
          <a href="#api" data-page="api"><span data-icon="code"></span><span>API 配置</span></a>
          <a href="#docs" data-page="docs"><span data-icon="book"></span><span>接口文档</span></a>
          <a href="#logs" data-page="logs"><span data-icon="activity"></span><span>访问日志</span></a>
          <a href="#login-logs" data-page="login-logs"><span data-icon="logIn"></span><span>登录日志</span></a>
          <a href="#security" data-page="security"><span data-icon="shield"></span><span>访问控制</span></a>
        </nav>
        <section class="sidebar-folders" aria-label="图片分类"><div class="nav-label"><span>图片分类</span><button class="icon-button" data-action="new-category" aria-label="新建分类" title="新建分类"><span data-icon="plus"></span></button></div><div id="sidebar-categories"></div></section>
      </div>
      <div class="sidebar-bottom">
        <div class="sidebar-storage-summary" tabindex="0" role="group" title="所有分类图片的总占用空间"><span><span data-icon="hardDrive"></span><span class="storage-label">本地图片占用</span></span><strong id="sidebar-storage">—</strong></div>
        <a href="#settings" class="settings-link" data-page="settings"><span data-icon="settings"></span><span class="nav-item-label">系统设置</span></a>
        <div class="sidebar-account"><a id="sidebar-profile" class="sidebar-profile" href="#settings?tab=account" aria-label="Administrator，查看个人资料"></a><a class="icon-button login-preview-link" href="#" data-action="logout" aria-label="退出登录" title="退出登录"><span data-icon="logOut"></span></a></div>
      </div>
    </div>
  </aside>
  <div id="sidebar-resizer" class="sidebar-resizer" role="separator" tabindex="0" aria-label="调整导航栏宽度" aria-orientation="vertical" aria-controls="primary-navigation" aria-valuemin="72" aria-valuemax="320" aria-valuenow="224" title="拖动调整宽度；双击恢复默认。也可用左右方向键调整。"></div>
  <div id="sidebar-tooltip" class="sidebar-tooltip" role="tooltip" hidden></div>
  <div id="chart-tooltip" class="chart-tooltip" role="tooltip" hidden></div>
  <div class="app-shell"><main id="main" tabindex="-1"></main></div>
  <dialog id="modal" aria-labelledby="modal-title"></dialog>
  <div id="toast" class="toast" popover="manual" role="status" aria-live="polite" aria-atomic="true"></div>
</body></html>
