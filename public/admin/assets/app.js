'use strict';

// Shared workbench rendering and interactions; production.js supplies server operations.
const icons = {
  logIn:'<path d="M15 4h5v16h-5M3 12h12m-4-4 4 4-4 4"/>',
  eye:'<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
  eyeOff:'<path d="m3 3 18 18M10.6 5.1 12 5c6.5 0 10 7 10 7a20 20 0 0 1-3 3.8M6.3 6.3A22 22 0 0 0 2 12s3.5 7 10 7a12 12 0 0 0 5.7-1.7M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
  logOut:'<path d="M9 4H4v16h5M10 12h11m-4-4 4 4-4 4"/>',
  status:'<circle cx="12" cy="12" r="6" fill="currentColor" stroke="none"/>',
  filterClear:'<path d="M20 3H4l6 7v9l4 2V10l6-7Z"/><path d="m18 14 4 4m0-4-4 4"/>',
  save:'<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h12l4 4v12a2 2 0 0 1-2 2Z"/><path d="M7 3v6h10V3M7 21v-8h10v8"/>',
  palette:'<circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 3a9 9 0 1 0 0 18h1.5a2.5 2.5 0 0 0 0-5H13a2 2 0 0 1 0-4h4a4 4 0 0 0 4-4c0-3-4-5-9-5Z"/>',
  overview:'<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
  image:'<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
  upload:'<path d="M12 16V4m-5 5 5-5 5 5M4 16v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4"/>',
  folder:'<path d="M3 7V5a2 2 0 0 1 2-2h4l3 3h7a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/>',
  code:'<path d="m8 6-6 6 6 6m8-12 6 6-6 6M14 3l-4 18"/>',
  book:'<path d="M12 5v16M12 5C9 2 4 3 2 4v16c3-1 7-1 10 1 3-2 7-2 10-1V4c-2-1-7-2-10 1z"/>',
  activity:'<path d="M2 12h5l3-9 4 18 3-9h5"/>',
  shield:'<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3z"/><path d="m8 12 3 3 5-6"/>',
  settings:'<path d="M19.11,9.05 L21.85,10.26 L21.85,13.74 L19.11,14.95 L19.11,14.95 L20.19,17.74 L17.74,20.19 L14.95,19.11 L14.95,19.11 L13.74,21.85 L10.26,21.85 L9.05,19.11 L9.05,19.11 L6.26,20.19 L3.81,17.74 L4.89,14.95 L4.89,14.95 L2.15,13.74 L2.15,10.26 L4.89,9.05 L4.89,9.05 L3.81,6.26 L6.26,3.81 L9.05,4.89 L9.05,4.89 L10.26,2.15 L13.74,2.15 L14.95,4.89 L14.95,4.89 L17.74,3.81 L20.19,6.26 L19.11,9.05 Z"/><circle cx="12" cy="12" r="3.2"/>',
  database:'<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0"/>',
  file:'<path d="M14 3H5v18h14V8zM14 3v5h5M8 13h8m-8 4h6"/>',
  sliders:'<path d="M4 6h4m4 0h8M4 12h10m4 0h2M4 18h2m4 0h10"/><circle cx="10" cy="6" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="8" cy="18" r="2"/>',
  chevrons:'<path d="m9 8 3-3 3 3m-6 8 3 3 3-3"/>',
  chevron:'<path d="m9 5 7 7-7 7"/>',
  arrow:'<path d="M4 12h16m-6-6 6 6-6 6"/>',
  arrowUp:'<path d="m7 14 5-5 5 5M12 9v11M4 4h16"/>',
  trend:'<path d="m3 17 6-6 4 4 8-10m-6 0h6v6"/>',
  download:'<path d="M12 3v12m-5-5 5 5 5-5M4 17v4h16v-4"/>',
  copy:'<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V3H3v13h5"/>',
  moon:'<path d="M20.8 13A9 9 0 0 1 11 3.2 9 9 0 1 0 20.8 13z"/>',
  themeAuto:'<path d="m5 20 7-16 7 16M8 14h8"/>',
  sun:'<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/>',
  bell:'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
  menu:'<path d="M3 6h18M3 12h18M3 18h18"/>',
  search:'<circle cx="10.5" cy="10.5" r="7"/><path d="m16 16 5 5"/>',
  calendar:'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 17h.01M12 17h.01"/>',
  close:'<path d="m6 6 12 12M6 18 18 6"/>',
  check:'<path d="m5 12 4 4L19 6"/>',
  plus:'<path d="M12 5v14M5 12h14"/>',
  monitor:'<rect x="3" y="3" width="18" height="13" rx="2"/><path d="M8 21h8m-4-5v5"/>',
  phone:'<rect x="6" y="2" width="12" height="20" rx="3"/><path d="M11 18h2"/>',
  globe:'<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/>',
  more:'<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
  list:'<path d="M9 5h12M9 12h12M9 19h12M3 5h.01M3 12h.01M3 19h.01"/>',
  clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  bolt:'<path d="m13 2-9 12h7l-1 8 10-12h-7l0-8z"/>',
  mail:'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
  lock:'<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V6a4 4 0 0 1 8 0v4m-4 5v2"/>',
  user:'<circle cx="12" cy="7" r="4"/><path d="M4 21v-3a8 8 0 0 1 16 0v3"/>',
  info:'<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10h.01"/>',
  external:'<path d="M14 3h7v7m0-7L10 14M10 3H3v18h18v-7"/>',
  trash:'<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/>',
  filter:'<path d="M4 6h16M7 12h10M10 18h4"/>',
  refresh:'<path d="M20 12a8 8 0 1 1-2.34-5.66L20 9"/><path d="M20 4v5h-5"/>',
  cloud:'<path d="M7 18H6a5 5 0 0 1-1-10 7 7 0 0 1 13-1 5 5 0 0 1 0 11h-1m-5 3V11m-3 3 3-3 3 3"/>',
  hardDrive:'<path d="m4 4-2 10v5h20v-5L20 4H4zm-2 10h20M6 17h.01M10 17h.01"/>'
};
const icon = name => `<svg class="icon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">${icons[name] || icons.image}</svg>`;
const escapeHTML = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const button = (text, action, symbol, primary = false, extra = '') => `<button class="button${primary ? ' primary' : ''}" data-action="${action}" ${extra}>${symbol ? icon(symbol) : ''}${text}</button>`;
const pageInfo = {
  overview:['数据总览','图片服务的访问与存储统计'],
  gallery:['图片资源','按设备、分类和文件名查找图片'],
  upload:['上传图片','支持多文件、文件夹和拖放上传'],
  categories:['分类管理','管理分类名称和请求别名'],
  api:['API 配置','管理图片来源、返回方式与 JSON 模板'],
  docs:['接口文档','请求参数、调用示例与响应状态'],
  logs:['访问日志','查看来源、响应状态与请求耗时'],
  'login-logs':['登录日志','查看后台登录记录与失败原因'],
  security:['访问控制','分别管理图片接口与后台登录规则'],
  settings:['系统设置','站点、邮件与账号配置'],
  setup:['初始化设置','数据库、管理员与邮件服务'],
  login:['登录','登录 PicAPI 图片工作台']
};
let images = [];
let categories = [];
const state = {page:'overview',device:'all',category:'all',search:'',sort:'new',layout:'grid',selected:new Set(),galleryPage:1,pageSize:12,categoryVisibleCount:12,actionIds:[],period:7,apiTab:'behavior',apiDevice:'auto',settingsTab:'general',securityTab:'api',logFilter:'all',codeLang:'curl',uploaded:[],theme:'light',switches:{}};
const main = document.querySelector('#main');
const modal = document.querySelector('#modal');
const toastElement = document.querySelector('#toast');
let toastTimer, toastHideTimer, modalReturnFocus;
const localFileURLs = [];
const compactNavigation = window.matchMedia('(max-width: 900px)');
let navigationCollapsed = false;

function syncNavigation() {
  const sidebar=document.querySelector('.sidebar'),toggle=document.querySelector('#menu-toggle');
  const shown=!['setup','login'].includes(state.page)&&(compactNavigation.matches?sidebar.classList.contains('open'):!navigationCollapsed);
  sidebar.inert=!shown;
  toggle.setAttribute('aria-expanded',String(shown));
  toggle.setAttribute('aria-label',shown?'收起导航':'展开导航');
  document.body.classList.toggle('nav-collapsed',navigationCollapsed);
}
compactNavigation.addEventListener('change',()=>closeNav());

function paintIcons(root = document) {
  root.querySelectorAll('[data-icon]').forEach(el => { el.innerHTML = icon(el.dataset.icon); });
}
function placeToast() {
  // Keep status announcements in the active dialog and paint above its backdrop.
  const host = modal.open ? modal : document.body;
  if (toastElement.parentElement !== host) host.append(toastElement);
  if (toastElement.showPopover && !toastElement.matches(':popover-open')) toastElement.showPopover();
}
function toast(message) {
  clearTimeout(toastTimer);
  clearTimeout(toastHideTimer);
  placeToast();
  toastElement.textContent = message;
  toastElement.getBoundingClientRect();
  toastElement.classList.add('visible');
  toastTimer = setTimeout(() => {
    toastElement.classList.remove('visible');
    toastHideTimer = setTimeout(() => {
      if (toastElement.hidePopover && toastElement.matches(':popover-open')) toastElement.hidePopover();
      toastElement.textContent = '';
    }, 220);
  }, 4300);
}
function head(page, action = '') {
  const info = pageInfo[page];
  const heading=page==='gallery'?galleryHeading()+'<span class="heading-count">'+filteredImages().length+'</span>':info[0];
  return `<div class="page-head"><div><h1>${heading}</h1><p>${info[1]}</p></div><div class="actions">${action}</div></div>`;
}
function openModal(title, body, footer = '', className = '') {
  modalReturnFocus = document.activeElement;
  if (modal.contains(toastElement)) document.body.append(toastElement);
  modal.className = className;
  modal.innerHTML = `<div class="modal-head"><h2 id="modal-title">${title}</h2><button class="icon-button" data-action="close-modal" aria-label="关闭弹窗">${icon('close')}</button></div>${body}${footer ? `<div class="modal-footer">${footer}</div>` : ''}`;
  modal.showModal();
  if (toastElement.classList.contains('visible')) placeToast();
}
function closeModal() { modal.close(); }
modal.addEventListener('close', () => {
  if (toastElement.classList.contains('visible')) placeToast();
  else document.body.append(toastElement);
  if (modalReturnFocus?.isConnected) modalReturnFocus.focus(); else main.focus({preventScroll:true});
});
modal.addEventListener('click', e => { if(e.target === modal) { const r=modal.getBoundingClientRect(); if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom) closeModal(); } });
function closeNav() {
  const sidebar=document.querySelector('.sidebar');
  const returnFocus=sidebar.classList.contains('open')&&sidebar.contains(document.activeElement);
  document.querySelector('.sidebar').classList.remove('open');
  document.querySelector('#nav-scrim').classList.remove('visible');
  syncNavigation();
  document.body.style.overflow='';
  if(returnFocus)document.querySelector('#menu-toggle').focus({preventScroll:true});
}
function navigate() {
  const [page,query=''] = location.hash.slice(1).split('?');
  state.page = Object.hasOwn(pageInfo,page) ? page : 'overview';
  if(state.page==='gallery')restoreGalleryURL(query);
  if(state.page==='security'){const scope=new URLSearchParams(query).get('scope');if(['api','login'].includes(scope))state.securityTab=scope;}
  if(state.page==='settings'){const tab=new URLSearchParams(query).get('tab');state.settingsTab=['general','appearance','login','mail','templates','account','runtime','other'].includes(tab)?tab:'general';}
  if(state.page==='login')loginStep=new URLSearchParams(query).get('mode')==='reset'?'request':'login';
  document.querySelectorAll('[data-page]').forEach(a => { a.classList.toggle('active', a.dataset.page===state.page); if(a.dataset.page===state.page) a.setAttribute('aria-current','page'); else a.removeAttribute('aria-current'); });
  document.querySelector('#breadcrumb-page').textContent = pageInfo[state.page][0];
  document.title = `${pageInfo[state.page][0]} · PicAPI 图片工作台`;
  render();
  closeNav();
  window.scrollTo({top:0,behavior:'instant'});
}
function updateWorkspace() {
  updateSidebarProfile();
  const total=document.querySelector('#sidebar-total'),storage=document.querySelector('#sidebar-storage'),folders=document.querySelector('#sidebar-categories');
  if(total)total.textContent=images.length;
  if(storage){
    const size=formatLibraryStorage(images.reduce((sum,image)=>sum+image.size,0));
    storage.textContent=size.value+' '+size.unit;
    const summary=storage.closest('.sidebar-storage-summary');
    summary.title='本地图片总占用：'+storage.textContent;
    summary.setAttribute('aria-label',summary.title);
  }
  if(folders)folders.innerHTML=categories.map(c=>`<button class="folder-shortcut ${state.page==='gallery'&&state.category===c.name?'active':''}" data-category-gallery="${escapeHTML(c.name)}" aria-label="${escapeHTML(c.name)}" title="${escapeHTML(c.name)} · ${images.filter(x=>x.category===c.name).length} 张"><span class="folder-marker" aria-hidden="true">${icon('folder')}</span><span>${escapeHTML(c.name)}</span><small>${images.filter(x=>x.category===c.name).length}</small></button>`).join('');
}
function render() {
  closeImageMenu(false);
  const focused = document.activeElement;
  const key = ['device','layout','period','apiTab','apiDesignPane','apiDesignChannel','settingsTab','securityTab','logFilter','codeLang','appearanceMode','paletteMode','appearancePreset','mailKind','mailEditor','mailPreset','preferenceAction'].find(k => focused?.dataset[k] !== undefined);
  const focusValue = key ? focused.dataset[key] : null;
  document.body.classList.toggle('setup-mode',state.page==='setup');
  document.body.classList.toggle('login-mode',state.page==='login');
  document.body.dataset.page=state.page;
  updateWorkspace();
  const views = {overview, gallery, upload, categories:categoryPage, api, docs, logs, 'login-logs':loginLogsPage, security:securityPage, settings, setup:setupPage, login:loginPage};
  main.innerHTML = views[state.page]();
  paintIcons(main);
  if(state.page==='gallery')updateSelection();
  mountStatsCharts();
  syncGalleryURL();
  if(state.page==='security')history.replaceState(null,'','#security?scope='+state.securityTab);
  if(state.page==='settings'){
    history.replaceState(null,'','#settings?tab='+state.settingsTab);
    const revealSettingsTab=()=>{const nav=main.querySelector('.local-nav'),active=nav?.querySelector('.active');if(!nav||!active||nav.scrollWidth<=nav.clientWidth)return;const left=active.offsetLeft,right=left+active.offsetWidth;if(left<nav.scrollLeft)nav.scrollLeft=left;else if(right>nav.scrollLeft+nav.clientWidth)nav.scrollLeft=right-nav.clientWidth;};
    revealSettingsTab();requestAnimationFrame(revealSettingsTab);
  }
  if(key)main.querySelector(`[data-${key.replace(/[A-Z]/g,c=>'-'+c.toLowerCase())}="${CSS.escape(focusValue)}"]`)?.focus({preventScroll:true});
  animateRenderedView();
  mountMailCodeEditor();
  mountAPIEditor();
  mountDocsPreview();
  bindImageLoadState(main);
  if(state.page==='categories')queueCategoryLoadCheck();
}
function categoryPage() {
  state.categoryVisibleCount=Math.min(Math.max(12,state.categoryVisibleCount||12),Math.max(12,categories.length));
  const visible=categories.slice(0,state.categoryVisibleCount);
  return `${head('categories',button('新建分类','new-category','plus',true))}<div id="category-viewport" class="category-viewport" role="region" aria-label="分类内容，可滚动浏览" tabindex="0"><div id="category-grid" class="category-grid">${visible.map((category,index)=>categoryCard(category,index)).join('')}<button class="category-add" data-action="new-category">${icon('plus')}<strong>创建新的图片分类</strong><small>让你的图库更有条理</small></button></div><div id="category-load-footer" class="category-load-footer">${categoryLoadFooter()}</div></div>`;
}
function categoryCard(category,index) {
  const members=images.filter(image=>image.category===category.name),covers=members.slice(0,3);
  const pc=members.filter(image=>image.device==='pc').length;
  const cover=covers.length?`<img src="${escapeHTML(covers[0].src)}" alt="${escapeHTML(category.name)}分类封面" width="400" height="220" loading="lazy" decoding="async" data-thumbnail-device="${escapeHTML(covers[0].device)}" data-thumbnail-relative="${escapeHTML(covers[0].relative)}" ${covers[0].width>360||covers[0].height>360||covers[0].bytes>100000?'class="thumbnail-needs-check"':''}>${covers.length>1?`<div>${[covers[1],covers[2]||covers[1]].map(image=>`<img src="${escapeHTML(image.src)}" alt="" width="180" height="110" loading="lazy" decoding="async" data-thumbnail-device="${escapeHTML(image.device)}" data-thumbnail-relative="${escapeHTML(image.relative)}" ${image.width>360||image.height>360||image.bytes>100000?'class="thumbnail-needs-check"':''}>`).join('')}</div>`:''}`:`<span class="category-empty-cover">${icon('folder')}<span>暂无图片</span></span>`;
  return `<article class="panel category-card"><div class="category-cover ${covers.length<=1?'is-single':''}">${cover}</div><div class="category-content"><div class="category-title"><h2 title="${escapeHTML(category.name)}">${escapeHTML(category.name)}</h2><button class="icon-button" data-category-menu="${index}" aria-label="${escapeHTML(category.name)} 的分类操作" aria-haspopup="menu" aria-expanded="false" aria-controls="image-menu">${icon('more')}</button></div><p class="mono">/${escapeHTML(category.alias)}/</p><div class="category-stat"><span><strong>${members.length}</strong>张图片</span><span>·</span><span>PC ${pc} / 手机 ${members.length-pc}</span></div><div class="category-bottom"><span class="muted">分类图片接口</span><button class="text-button" data-category-gallery="${escapeHTML(category.name)}">查看图片 ${icon('arrow')}</button></div></div></article>`;
}
const categoryBatchSize=12;
let categoryLoadFrame=0;
function categoryLoadFooter(){const loaded=Math.min(state.categoryVisibleCount||categoryBatchSize,categories.length);return `<span role="status" aria-live="polite">已显示 ${loaded} / ${categories.length} 个分类</span>`;}
function appendCategoryBatch(){
  const grid=document.querySelector('#category-grid');if(!grid)return;
  const start=Math.min(state.categoryVisibleCount||categoryBatchSize,categories.length),next=categories.slice(start,start+categoryBatchSize);
  if(!next.length)return;
  const add=grid.querySelector('.category-add');add?.insertAdjacentHTML('beforebegin',next.map((category,index)=>categoryCard(category,start+index)).join(''));
  bindImageLoadState(grid);
  state.categoryVisibleCount=start+next.length;
  const footer=document.querySelector('#category-load-footer');if(footer)footer.innerHTML=categoryLoadFooter();
  if(grid.parentElement.scrollHeight<=grid.parentElement.clientHeight+80)queueCategoryLoadCheck();
}
function queueCategoryLoadCheck(){
  if(categoryLoadFrame||!document.querySelector('#category-viewport'))return;
  categoryLoadFrame=requestAnimationFrame(()=>{categoryLoadFrame=0;const viewport=document.querySelector('#category-viewport');if(!viewport||state.categoryVisibleCount>=categories.length)return;if(viewport.scrollTop+viewport.clientHeight>=viewport.scrollHeight-180)appendCategoryBatch();});
}
document.addEventListener('scroll',event=>{if(event.target?.id==='category-viewport')queueCategoryLoadCheck();},{capture:true,passive:true});
function switchRow(title, description, id, on = true) {
  on=state.switches[id]??on;
  return `<div class="setting-row"><div><h3 id="${id}-label">${title}</h3><p>${description}</p></div><button class="switch" role="switch" aria-checked="${on}" aria-labelledby="${id}-label" data-switch="${id}"></button></div>`;
}
async function copy(text) {
  try { await navigator.clipboard.writeText(text); toast('已复制到剪贴板'); }
  catch { const field=document.createElement('textarea');field.value=text;field.style.cssText='position:fixed;opacity:0;pointer-events:none';(modal.open?modal:document.body).append(field);field.select();const ok=document.execCommand('copy');field.remove();toast(ok?'已复制到剪贴板':'当前浏览器无法自动复制，请手动复制'); }
}
function downloadCSV(filename, rows) {
  const csv='\uFEFF'+rows.map(r=>r.map(v=>'"'+(/^[=+@\-\t\r]/.test(String(v))?"'"+v:String(v)).replaceAll('"','""')+'"').join(',')).join('\r\n');
  const url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));
  const a=document.createElement('a');a.href=url;a.download=filename;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
  toast('清单已导出');
}
function categoryModal(index = null) {
  const c=index===null?{name:'',alias:''}:categories[index];
  if(!c)return;
  openModal(index===null?'新建分类':'编辑分类',`<form id="category-form" class="modal-body"><div class="field"><label for="category-name">分类名称</label><input id="category-name" name="name" placeholder="例如：城市与日常" value="${escapeHTML(c.name)}" required maxlength="24"></div><div class="field"><label for="category-alias">请求别名</label><input id="category-alias" name="alias" placeholder="例如：city" value="${escapeHTML(c.alias)}" pattern="[A-Za-z0-9_\\-]+" maxlength="40" aria-describedby="category-alias-hint"><small id="category-alias-hint">${index===null?'可留空，由后台自动分配':'可留空以保留当前别名'}；填写时使用英文字母、数字、下划线或连字符。</small></div><div class="callout">${index===null?'新分类将同时提供 PC 与手机图片入口。':'更新后分类名称与请求别名立即生效。'}</div><input type="hidden" name="index" value="${index===null?'':index}"></form>`,button('取消','close-modal','')+'<button type="submit" form="category-form" class="button primary">'+icon('check')+'确认分类</button>');
}
let pendingCategoryDeletion=null;
function deleteCategoryModal(index) {
  const category=categories[index];if(!category||category.default)return;
  const count=images.filter(image=>image.category===category.name).length;
  openModal('删除分类',`<form id="delete-category-form" class="modal-body"><p class="modal-message">将删除分类 <strong>${escapeHTML(category.name)}</strong> 及其中的 <strong>${count} 张图片</strong>。</p><div class="category-delete-warning">${icon('trash')}<span>分类文件夹和内部图片会一起删除，请确认后继续。</span></div><div class="field"><label for="delete-category-name">输入分类名称以确认</label><input id="delete-category-name" name="confirmation" autocomplete="off" required placeholder="${escapeHTML(category.name)}"></div><p class="category-demo-note">此操作无法撤销，请确认分类名称。</p></form>`,button('取消','close-modal','')+'<button type="submit" form="delete-category-form" id="confirm-category-delete" class="button danger" disabled>'+icon('trash')+'删除分类及图片</button>');
  pendingCategoryDeletion=category;
}
modal.addEventListener('close',()=>{pendingCategoryDeletion=null;});
function renderCategoryChange(message) {
  const scrollTop=document.querySelector('#category-viewport')?.scrollTop||0;
  closeModal();render();
  document.querySelector('#category-viewport')?.scrollTo({top:scrollTop,behavior:'instant'});
  toast(message);
}
document.addEventListener('input',event=>{
  if(event.target.id==='delete-category-name')document.querySelector('#confirm-category-delete').disabled=event.target.value.trim()!==pendingCategoryDeletion?.name;
});
function changeTheme() {
  const modes=['light','dark','auto'];
  preferences.mode=modes[(modes.indexOf(preferences.mode)+1)%modes.length];
  preferences.editMode=preferences.mode==='auto'?(systemAppearance.matches?'dark':'light'):preferences.mode;
  applyAppearance();
  if(state.page==='settings')render();
}
const actions = {
  'goto-docs':()=>location.hash='docs','goto-gallery':()=>location.hash='gallery','goto-upload':()=>location.hash='upload','goto-api':()=>location.hash='api',
  'close-modal':closeModal,'close-nav':closeNav,'theme':changeTheme,
  'gallery-reset':()=>{state.device='all';state.category='all';state.search='';state.sort='new';resetLibraryFilter();render();},
  'select-all-matching':()=>{filteredImages().forEach(x=>state.selected.add(x.id));updateSelection();},
  'clear-selection':()=>{state.selected.clear();refreshGallery();},
  'new-category':()=>categoryModal(),
  'pick-files':()=>document.querySelector('#file-input').click(),
  'pick-folder':()=>document.querySelector('#folder-input').click(),
  'move-selected':()=>openMove([...state.selected]), 'delete-selected':()=>openDelete([...state.selected])
};
document.addEventListener('click', e=>{
  const el=e.target.closest('button,a');if(!el)return;
  if(el.matches('.sidebar a')&&el.hash===location.hash){closeNav();return;}
  if(el.dataset.action&&actions[el.dataset.action]){actions[el.dataset.action]();return;}
  if(el.dataset.copy){copy(el.dataset.copy);return;}
  if(el.dataset.preview){preview(Number(el.dataset.preview));return;}
  if(el.dataset.device){state.device=el.dataset.device;resetLibraryFilter();render();return;}
  if(el.dataset.layout){state.layout=el.dataset.layout;render();updateSelection();return;}
  if(el.dataset.period){state.period=Number(el.dataset.period);render();return;}
  if(el.dataset.settingsTab){state.settingsTab=el.dataset.settingsTab;render();return;}
  if(el.dataset.securityTab){state.securityTab=el.dataset.securityTab;render();return;}
  if(el.dataset.logFilter){state.logFilter=el.dataset.logFilter;render();return;}
  if(el.dataset.codeLang){state.codeLang=el.dataset.codeLang;render();return;}
  if(el.dataset.switch){const on=el.getAttribute('aria-checked')!=='true';state.switches[el.dataset.switch]=on;el.setAttribute('aria-checked',String(on));return;}
  if(el.dataset.editCategory!==undefined){categoryModal(Number(el.dataset.editCategory));return;}
  if(el.dataset.categoryGallery){state.category=el.dataset.categoryGallery;state.device='all';state.search='';resetLibraryFilter();if(state.page==='gallery')render();else location.hash='gallery?category='+encodeURIComponent(state.category);return;}
  if(el.dataset.removeUpload!==undefined){const removed=state.uploaded.splice(Number(el.dataset.removeUpload),1)[0];if(removed?.uploading)removed.abortController?.abort();if(removed?.url)URL.revokeObjectURL(removed.url);if(state.page==='upload'&&typeof renderUploadQueue==='function')renderUploadQueue();else render();return;}
  if(el.dataset.action==='copy-preview'){const item=images.find(x=>x.id===Number(el.dataset.id));if(item)copy(imageURL(item));}
});
document.addEventListener('input',e=>{if(e.target.id==='gallery-search'){state.search=e.target.value;resetLibraryFilter();refreshGallery();}});
document.addEventListener('change',e=>{
  const el=e.target;
  if(el.dataset.select){const id=Number(el.dataset.select);if(el.checked)state.selected.add(id);else state.selected.delete(id);updateSelection();return;}
  if(el.id==='gallery-category'){state.category=el.value;resetLibraryFilter();refreshGallery();}
  if(el.id==='gallery-sort'){state.sort=el.value;state.galleryPage=1;refreshGallery(true);}
  if(el.id==='file-input'||el.id==='folder-input'){const files=Array.from(el.files||[]);el.value='';addFiles(files);}
});
document.addEventListener('input',e=>{if(e.target.matches('input'))e.target.setCustomValidity('');});
document.addEventListener('dragover',e=>{if(e.target.closest('#dropzone')){e.preventDefault();document.querySelector('#dropzone').classList.add('drag-over');}});
document.addEventListener('dragleave',e=>{if(e.target.closest('#dropzone'))document.querySelector('#dropzone')?.classList.remove('drag-over');});
document.addEventListener('drop',e=>{if(e.target.closest('#dropzone')){e.preventDefault();addFiles(e.dataTransfer.files);}});
document.querySelector('.skip-link').addEventListener('click',e=>{e.preventDefault();main.focus();main.scrollIntoView();});
document.querySelector('#menu-toggle').addEventListener('click',()=>{
  if(!compactNavigation.matches){navigationCollapsed=!navigationCollapsed;syncNavigation();return;}
  const open=document.querySelector('.sidebar').classList.toggle('open');
  document.querySelector('#nav-scrim').classList.toggle('visible',open);
  document.body.style.overflow=open?'hidden':'';
  syncNavigation();
  if(open)document.querySelector('.sidebar .active')?.focus();
});
document.querySelector('#nav-scrim').addEventListener('click',closeNav);
document.querySelector('#theme-toggle').addEventListener('click',()=>changeTheme());
document.addEventListener('keydown',e=>{
  if(e.key==='Escape')closeNav();
  if(e.key==='Tab'&&document.querySelector('.sidebar').classList.contains('open')){
    const nodes=Array.from(document.querySelectorAll('.sidebar button,.sidebar a')).filter(x=>x.getBoundingClientRect().width>0);
    const first=nodes[0],last=nodes.at(-1);
    if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}
    else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}
  }
});
window.addEventListener('hashchange',()=>{if(modal.open)closeModal();navigate();});
window.addEventListener('beforeunload',()=>localFileURLs.forEach(url=>URL.revokeObjectURL(url)));
