'use strict';
try{sessionStorage.removeItem('picapi-setup-draft-v1');}catch{}

// Server-backed workbench. Rendering modules contain no server credentials.
const context=JSON.parse(document.querySelector('#workbench-context').textContent);
let serverData=null,csrf=context.csrf,uploadBusy=false,pendingUploadConflictResolver=null,uploadThumbnailQueue=Promise.resolve();
let developerDiagnostics=[];
const PICAPI_REPOSITORY_URL='https://github.com/xiaofa520/PicAPI';
const PICAPI_VERSION=document.body.dataset.appVersion||'1.0.0';
const setupState={step:1};
const serverLogs={rows:[],uploads:[],accessDates:[],uploadDates:[],kind:'access',search:'',page:1,size:20,startDate:'',endDate:''};
const logCalendarDraft={startDate:'',endDate:'',year:0,month:0};
let logCalendarOpen=false;
const loadedViews=new Set();
const liveChannel=(()=>{try{return new BroadcastChannel('picapi-admin-live-v1')}catch{return null}})();
let liveSyncTimer=0,liveSyncBusy=false,liveMutationTimer=0,silentSyncRender=false;
const baseRender=render;
const baseProfileSettings=profileSettingsMarkup;
const baseGeneralSettings=generalSettingsMarkup;
const baseChangeTheme=changeTheme;
let pendingAvatar=null;

function adminRequestURL(path){const page=new URL(location.href),target=new URL(path,page);if(target.host.toLowerCase()===page.host.toLowerCase())target.protocol=page.protocol;return target.href;}
async function requestJSON(url,body=null,options={}){
  const response=await fetch(adminRequestURL(url),{method:body?'POST':'GET',credentials:'same-origin',headers:{Accept:'application/json'},body,signal:options.signal});
  if(response.redirected&&/login\.php/.test(response.url)){location.href='login.php';throw new Error('登录已过期');}
  const type=response.headers.get('content-type')||'';
  if(!type.includes('application/json'))throw new Error(response.status===403?'验证已失效，请刷新后重试':'服务器未返回有效数据');
  const data=await response.json();if(!response.ok||data.ok===false){const error=new Error(data.message||'操作失败');error.status=response.status;error.data=data;throw error;}
  if(data.csrf)csrf=data.csrf;
  if(body)announceLiveMutation();
  return data;
}
function formBody(values={}){const form=new FormData();form.set('csrf',csrf);Object.entries(values).forEach(([key,value])=>{if(Array.isArray(value))value.forEach(item=>form.append(key+'[]',item));else form.set(key,value);});return form;}
function apiRequest(operation,payload){return requestJSON('workbench_api.php?op='+encodeURIComponent(operation),payload===undefined?null:formBody({payload:JSON.stringify(payload)}));}
function appearanceSettingsPayload(){const input={theme_mode:preferences.mode};for(const mode of ['light','dark'])for(const key of paletteKeys)input[mode+'_theme_'+(key==='accent'?'color':key)]=preferences.palettes[mode][key];return input;}
async function persistAppearanceSettings(message='外观设置已自动保存'){const input=appearanceSettingsPayload();await apiRequest('settings',input);Object.assign(serverData.settings,input);if(message)toast(message);}
function announceLiveMutation(type='changed',delay=350){
  if(liveMutationTimer)return;
  liveMutationTimer=setTimeout(()=>{liveMutationTimer=0;const message={type,at:Date.now()};try{liveChannel?.postMessage(message);localStorage.setItem('picapi-admin-live-event',JSON.stringify(message));}catch{}},delay);
}
function syncPageForCurrentView(){return ['overview','gallery','categories','upload','security','logs','login-logs','api','docs','docs-public','settings'].includes(state.page)?state.page:null;}
function currentSettingsSnapshot(){return {settings:serverData.settings,smtp:serverData.smtp,profile:serverData.profile,emailPending:serverData.emailPending,favicon:serverData.favicon};}
function livePayloadKey(view,data=null){
  if(['gallery','categories','upload'].includes(view))return JSON.stringify(data?[data.images,data.categories]:[images,categories]);
  if(view==='overview')return JSON.stringify(data?.statistics??serverData.statistics);
  if(view==='security')return JSON.stringify(data?[data.policies,data.entries.map(entry=>({...entry,created:entry.created*1000,expires:entry.expires===null?null:entry.expires*1000}))]:[securityPolicies,securityEntries]);
  if(view==='logs')return JSON.stringify(data?[data.rows,data.uploads,data.accessDates,data.uploadDates,data.accessLimit,data.startDate,data.endDate]:[serverLogs.rows,serverLogs.uploads,serverLogs.accessDates,serverLogs.uploadDates,serverLogs.accessLimit,serverLogs.startDate,serverLogs.endDate]);
  if(view==='login-logs')return JSON.stringify(data?[data.rows,data.dates,data.startDate,data.endDate]:[loginLogRows,loginLogDates,loginLogState.startDate,loginLogState.endDate]);
  if(view==='api')return JSON.stringify(data?.api??serverData.api);
  if(view==='docs'||view==='docs-public')return JSON.stringify(data?[data.docs,data.docsRequestStatistics,data.docsVisitStatistics]:[serverData.docs,serverData.docsRequestStatistics,serverData.docsVisitStatistics]);
  if(view==='settings')return JSON.stringify(data?{settings:data.settings,smtp:data.smtp,profile:data.profile,emailPending:data.emailPending,favicon:data.favicon}:{settings:serverData.settings,smtp:serverData.smtp,profile:serverData.profile,emailPending:serverData.emailPending,favicon:serverData.favicon});
  return JSON.stringify(data);
}
function applyLivePageData(view,data){
  if(['gallery','categories','upload'].includes(view)){
    const selectedKeys=images.filter(item=>state.selected.has(item.id)).map(item=>item.key);
    images=data.images;categories=data.categories;serverData.images=images;serverData.categories=categories;
    state.selected=new Set(images.filter(item=>selectedKeys.includes(item.key)).map(item=>item.id));
    if(!categories.some(category=>category.name===state.category))state.category='all';
    state.galleryPage=Math.min(state.galleryPage,pageCount());
  }else if(view==='overview'){
    serverData.statistics=data.statistics;setStatistics(data.statistics);
  }else if(view==='security'){
    Object.assign(securityPolicies,data.policies);Object.assign(securityDrafts,structuredClone(data.policies));
    securityEntries=data.entries.map(entry=>({...entry,created:entry.created*1000,expires:entry.expires===null?null:entry.expires*1000}));
  }else if(view==='logs')Object.assign(serverLogs,data);
  else if(view==='login-logs'){loginLogRows=data.rows;loginLogDates=data.dates||[];}
  else if(view==='api')serverData.api=data.api;
  else if(view==='docs'||view==='docs-public'){
    serverData.docs=data.docs;serverData.docsRequestStatistics=data.docsRequestStatistics;serverData.docsVisitStatistics=data.docsVisitStatistics;
    if(typeof docsSavedMarkdown!=='undefined')docsSavedMarkdown=data.docs.markdown;
    if(typeof docsDraftMarkdown!=='undefined')docsDraftMarkdown=data.docs.markdown;
  }else if(view==='settings'){
    Object.assign(serverData,data);importSettings({...serverData,policies:serverData.policies,entries:serverData.entries});
    applySiteIcon(data.favicon);applyAppearance();
  }
}
function hasUnsavedFormChanges(){
  return [...main.querySelectorAll('form')].some(form=>[...form.elements].some(control=>{
    if(control.disabled||control.type==='file')return false;
    if(control.type==='checkbox'||control.type==='radio')return control.checked!==control.defaultChecked;
    if(control.tagName==='SELECT')return [...control.options].some(option=>option.selected!==option.defaultSelected);
    return 'defaultValue' in control&&control.value!==control.defaultValue;
  }));
}
async function syncCurrentView(){
  const view=syncPageForCurrentView();if(!serverData||!view||liveSyncBusy||document.hidden)return;
  if(modal.open||hasUnsavedFormChanges()||(state.page==='docs'&&typeof docsEditView!=='undefined'&&docsEditView==='source'))return;
  const focused=document.activeElement;
  if(focused&&main.contains(focused)&&focused.matches('input,textarea,select,[contenteditable="true"]'))return;
  liveSyncBusy=true;
  try{
    const before=livePayloadKey(view);
    const query=new URLSearchParams({op:'sync',view});
    if(view==='logs'){if(serverLogs.startDate)query.set('start_date',serverLogs.startDate);if(serverLogs.endDate)query.set('end_date',serverLogs.endDate);}
    if(view==='login-logs'){if(loginLogState.startDate)query.set('start_date',loginLogState.startDate);if(loginLogState.endDate)query.set('end_date',loginLogState.endDate);}
    const data=await requestJSON('workbench_api.php?'+query.toString());
    if(before!==livePayloadKey(view,data)){applyLivePageData(view,data);renderLiveUpdate();}
  }catch{}
  finally{liveSyncBusy=false;}
}
const baseAnimateRenderedView=animateRenderedView;
animateRenderedView=function(){if(!silentSyncRender)baseAnimateRenderedView();};
function renderLiveUpdate(){
  const scrolls=[...main.querySelectorAll('[id]')].filter(element=>element.scrollHeight>element.clientHeight+1).map(element=>[element.id,element.scrollTop,element.scrollLeft]);
  const mainTop=main.scrollTop,mainLeft=main.scrollLeft;
  silentSyncRender=true;try{render();}finally{silentSyncRender=false;}
  main.scrollTop=mainTop;main.scrollLeft=mainLeft;
  for(const [id,top,left] of scrolls){const element=document.getElementById(id);if(element){element.scrollTop=top;element.scrollLeft=left;}}
}
function scheduleLiveSync(delay=250){if(liveSyncTimer)clearTimeout(liveSyncTimer);liveSyncTimer=setTimeout(()=>{liveSyncTimer=0;syncCurrentView();},delay);}
liveChannel?.addEventListener('message',event=>{if(event.data?.type==='changed'||event.data?.type==='login-attempt')scheduleLiveSync(80);});
window.addEventListener('storage',event=>{if(event.key==='picapi-admin-live-event')scheduleLiveSync(80);});
document.addEventListener('visibilitychange',()=>{if(!document.hidden)scheduleLiveSync(0);});
document.addEventListener('focusout',()=>scheduleLiveSync(300));
async function perform(operation,control=null){
  if(control?.disabled)return;
  if(control){control.disabled=true;control.setAttribute('aria-busy','true');}
  try{return await operation();}catch(error){toast(error.message);return null;}finally{if(control?.isConnected){control.disabled=false;control.removeAttribute('aria-busy');}}
}
function currentCategory(name){return categories.find(category=>category.name===name);}
function chosenKeys(){return images.filter(image=>state.actionIds.includes(image.id)).map(image=>image.key);}
async function refreshLibrary(){const data=await apiRequest('library');images=data.images;categories=data.categories;state.selected.clear();state.actionIds=[];if(!categories.some(category=>category.name===state.category))state.category='all';state.galleryPage=Math.min(state.galleryPage,pageCount());}
function setStatistics(data){
  const endpoints=data.requestDailySeries;
  dailyStats=data.dashboardDailySeries.map((day,index)=>[day.day.slice(5).replace('-','/'),day.requests,day.bytes/1073741824,endpoints[index].auto,endpoints[index].pc,endpoints[index].mobile]);
  requestsBeforeWindow=Math.max(0,data.totalRequests-dailyStats.reduce((sum,day)=>sum+day[1],0));
}
function applyWorkspaceSiteName(value){
  const name=String(value??'').trim()||'PicAPI';
  document.querySelectorAll('[data-site-name]').forEach(node=>node.textContent=name);
  const brand=document.querySelector('.workspace-brand');if(brand)brand.setAttribute('aria-label',`${name} 总览`);
  document.title=`${name} · 桌面素材台`;
}
function importSettings(data){
  const s=data.settings;
  applySiteIcon(data.favicon||'assets/mark.svg');
  loginSessionSettings.days=Number(s.admin_session_days)||7;
  loginSessionSettings.save=async days=>{
    await apiRequest('settings',{admin_session_days:days});
    serverData.settings.admin_session_days=days;
  };
  preferences.site={name:s.site_name,url:s.site_url||data.baseURL,description:s.site_description,seoTitle:s.seo_title||'',seoDescription:s.seo_description||'',seoKeywords:s.seo_keywords||''};
  applyWorkspaceSiteName(preferences.site.name);
  preferences.mode=s.theme_mode;preferences.customAppearance=true;
  for(const mode of ['light','dark'])preferences.palettes[mode]={accent:s[mode+'_theme_color'],background:s[mode+'_theme_background'],surface:s[mode+'_theme_surface'],text:s[mode+'_theme_text'],muted:s[mode+'_theme_muted']};
  preferences.editMode=preferences.mode==='auto'?(systemAppearance.matches?'dark':'light'):preferences.mode;
  preferences.subject=s.email_subject_template;
  mailTemplates.notification.html=s.notification_email_html_template;mailTemplates.reset.html=s.password_reset_email_html_template;
  Object.assign(profile,data.profile);Object.assign(profileDraft,data.profile);
  Object.assign(securityPolicies,data.policies);Object.assign(securityDrafts,structuredClone(data.policies));
  securityEntries=data.entries.map(entry=>({...entry,created:entry.created*1000,expires:entry.expires===null?null:entry.expires*1000}));
}
async function loadSecurity(){const data=await apiRequest('security');Object.assign(securityPolicies,data.policies);Object.assign(securityDrafts,structuredClone(data.policies));securityEntries=data.entries.map(entry=>({...entry,created:entry.created*1000,expires:entry.expires===null?null:entry.expires*1000}));}
async function loadLogs(page){
  if(page==='login-logs'){
    const query=new URLSearchParams({op:'login-logs'});
    if(loginLogState.startDate)query.set('start_date',loginLogState.startDate);if(loginLogState.endDate)query.set('end_date',loginLogState.endDate);
    const data=await requestJSON('workbench_api.php?'+query.toString());loginLogRows=data.rows;loginLogDates=data.dates||[];
  }
  else {
    const query=new URLSearchParams({op:'logs'});
    if(serverLogs.startDate)query.set('start_date',serverLogs.startDate);if(serverLogs.endDate)query.set('end_date',serverLogs.endDate);
    Object.assign(serverLogs,await requestJSON('workbench_api.php?'+query.toString()));
  }
  loadedViews.add(page);if(state.page===page)render();
}
render=function(){
  if(!serverData)return;
  if(state.page==='setup'){location.href='setup.php';return;}
  if(state.page==='login'){location.href='login.php';return;}
  baseRender();
  if(['logs','login-logs'].includes(state.page)&&!loadedViews.has(state.page)){
    const page=state.page;loadedViews.add(page);perform(()=>loadLogs(page));
  }
};
changeTheme=function(){baseChangeTheme();try{localStorage.setItem('picapi-production-theme',preferences.mode);}catch{}void perform(()=>persistAppearanceSettings('显示模式已自动保存'),document.querySelector('#theme-toggle'));};
function setupPage(){return '';}
function loginPage(){return '';}

function field(name,label,value='',type='text',extra=''){return `<div class="field"><label for="prod-${name}">${label}</label><input id="prod-${name}" name="${name}" type="${type}" value="${escapeHTML(value??'')}" ${extra}></div>`;}
function checkbox(name,label,checked,description=''){return `<div class="setting-row"><div><label for="setting-${name}">${label}</label>${description?`<p>${description}</p>`:''}</div><input id="setting-${name}" class="settings-toggle" role="switch" name="${name}" type="checkbox" aria-label="${label}" ${checked?'checked':''}></div>`;}
function preview(id){
  const item=images.find(image=>image.id===id);if(!item)return;
  openModal('图片详情',`<div class="preview-content"><div class="preview-image"><img src="${escapeHTML(item.url)}" alt="${escapeHTML(item.original)}"></div><div class="preview-details"><h3>${escapeHTML(item.original)}</h3>${[['尺寸',item.width+' × '+item.height],['文件大小',formatLibraryStorage(item.size).value+' '+formatLibraryStorage(item.size).unit],['所属图库',item.device==='pc'?'PC':'手机'],['分类',item.category],['文件日期',item.date]].map(([label,value])=>`<div class="detail-row"><span>${label}</span><strong>${escapeHTML(value)}</strong></div>`).join('')}${button('复制图片地址','copy-preview','copy',true,`data-id="${id}"`)}</div></div>`,'','preview-dialog');
}

function upload(){
  return `${head('upload',button('查看图库','goto-gallery','image'))}<section class="panel"><div class="panel-body"><div class="dropzone" id="dropzone"><span class="upload-icon">${icon('cloud')}</span><h2>将图片拖到这里</h2><p>JPG、PNG、GIF、WebP · 每张最大 50 MB</p><div class="actions">${button('选择图片','pick-files','plus',true)}${button('选择文件夹','pick-folder','folder')}</div><input id="file-input" class="file-input" type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple><input id="folder-input" class="file-input" type="file" accept="image/*" multiple webkitdirectory></div><div class="upload-options"><div class="field"><label for="upload-category">上传到分类</label><select id="upload-category">${categories.map(c=>`<option value="${escapeHTML(c.storageAlias)}" ${state.uploadCategory===c.storageAlias?'selected':''}>${escapeHTML(c.name)}</option>`).join('')}</select></div><div class="field"><label for="upload-device">设备类型</label><select id="upload-device"><option value="auto">根据图片方向自动识别</option><option value="pc" ${state.uploadDevice==='pc'?'selected':''}>PC</option><option value="mobile" ${state.uploadDevice==='mobile'?'selected':''}>手机</option></select></div></div><div class="section-title"><h3>上传队列 ${state.uploaded.length}</h3>${button('清空队列','clear-upload','close',false,uploadBusy?'disabled':'')}</div><div class="upload-list">${state.uploaded.map((file,index)=>`<div class="upload-row ${file.uploading?'is-uploading':''}"><img src="${escapeHTML(file.url)}" alt="待上传图片"><div><strong>${escapeHTML(file.resultName||file.name)}</strong><small>${(file.size/1048576).toFixed(1)} MB</small></div><span class="chip ${file.done?'success':''}">${file.skipped?'已跳过':file.done?'已上传':file.error?escapeHTML(file.error):file.progress?file.progress+'%':'等待上传'}</span><button class="icon-button" data-remove-upload="${index}" aria-label="移除 ${escapeHTML(file.name)}" ${file.waitingConflict?'disabled':''}>${icon('close')}</button></div>`).join('')||'<p class="muted">选择图片后开始上传。</p>'}</div><div class="upload-footer"><span>分片上传 · 同名图片会先确认处理方式</span>${button('开始上传','start-upload','upload',true,uploadBusy||!state.uploaded.some(file=>!file.done)?'disabled':'')}</div></div></section>`;
}
async function addFiles(fileList){
  const files=Array.from(fileList||[]);if(!files.length)return;
  if(pendingUploadConflictResolver){const resolve=pendingUploadConflictResolver;pendingUploadConflictResolver=null;closeModal();resolve(null);}
  for(const entry of state.uploaded){if(entry.uploading)entry.abortController?.abort();if(entry.url)URL.revokeObjectURL(entry.url);}
  state.uploaded=[];
  let skipped=0;
  for(const file of files){
    if(!['image/jpeg','image/png','image/gif','image/webp'].includes(file.type)||file.size>50*1048576||!file.size){skipped++;continue;}
    const url=URL.createObjectURL(file);localFileURLs.push(url);state.uploaded.push({file,url,name:file.name,size:file.size,done:false,progress:0});
  }
  if(skipped)toast(`已跳过 ${skipped} 个不支持或超过 50 MB 的文件`);
  render();
}
function canonicalUploadName(file){
  const name=String(file.name||'').split(/[\\/]/).pop(),stem=name.replace(/\.[^.]*$/,'')||name;
  const extension={'image/jpeg':'jpg','image/png':'png','image/gif':'gif','image/webp':'webp'}[file.type]||'jpg';
  return `${stem}.${extension}`;
}
async function uploadTargetDevice(file,selection){
  if(selection==='pc'||selection==='mobile')return selection;
  try{const bitmap=await createImageBitmap(file);const device=bitmap.height>bitmap.width?'mobile':'pc';bitmap.close();return device;}catch{return 'pc';}
}
function duplicateChoice(entry,existing){
  return new Promise(resolve=>{
    pendingUploadConflictResolver=resolve;
    const existingName=existing.original||existing.filename||entry.name;
    const existingUrl=existing.url||existing.server_image_url||existing.src||'';
    const body=`<div class="modal-body upload-duplicate-modal"><p class="modal-message">目标分类中已存在同名图片。请选择处理方式：</p><div class="upload-duplicate-compare"><figure><img src="${escapeHTML(existingUrl)}" alt="已存在的图片"><figcaption><strong>图库中的图片</strong><span>${escapeHTML(existingName)}</span></figcaption></figure><figure><img src="${escapeHTML(entry.url)}" alt="准备上传的图片"><figcaption><strong>本次选择</strong><span>${escapeHTML(entry.name)}</span></figcaption></figure></div><label class="upload-duplicate-batch"><input type="checkbox" data-upload-conflict-batch><span>本次上传中，同名图片都按此方式处理（仅本次上传有效）</span></label></div>`;
    const footer=`<button type="button" class="button" data-upload-conflict-choice="skip">跳过</button><button type="button" class="button" data-upload-conflict-choice="replace">替换原图</button><button type="button" class="button primary" data-upload-conflict-choice="append">两张都保留</button>`;
    openModal('发现同名图片',body,footer,'upload-duplicate-dialog');
  });
}
async function preflightUploadConflicts(entries,category,device,batch){
  await refreshLibrary();
  for(const entry of entries){
    const targetDevice=await uploadTargetDevice(entry.file,device),filename=canonicalUploadName(entry.file).toLocaleLowerCase();
    const existing=images.find(image=>image.device===targetDevice&&image.categoryAlias===category&&String(image.original||'').toLocaleLowerCase()===filename);
    if(!existing){entry.conflictAction='prompt';continue;}
    let choice=batch.action;
    if(!choice){const selected=await duplicateChoice(entry,existing);if(!selected)return false;choice=selected.choice;if(selected.applyToBatch)batch.action=choice;}
    entry.conflictAction=choice;entry.targetDevice=targetDevice;
    if(choice==='skip'){entry.done=true;entry.skipped=true;}
  }
  return true;
}
function renderUploadQueue(followActive=false){
  const before=document.querySelector('.upload-list'),scrollTop=before?.scrollTop||0;
  render();
  const list=document.querySelector('.upload-list');if(!list)return;
  list.scrollTop=scrollTop;
  if(!followActive)return;
  requestAnimationFrame(()=>{
    const currentList=document.querySelector('.upload-list'),row=currentList?.querySelector('.upload-row.is-uploading');if(!currentList||!row)return;
    const listRect=currentList.getBoundingClientRect(),rowRect=row.getBoundingClientRect(),padding=12;
    let target=currentList.scrollTop;
    if(rowRect.top<listRect.top+padding)target-=listRect.top+padding-rowRect.top;
    else if(rowRect.bottom>listRect.bottom-padding)target+=rowRect.bottom-listRect.bottom+padding;
    if(target!==currentList.scrollTop)currentList.scrollTop=Math.max(0,target);
  });
}
async function saveUploadThumbnail(file,device,relative){
  const image=await createImageBitmap(file),scale=Math.min(1,320/Math.max(image.width,image.height)),canvas=document.createElement('canvas');
  canvas.width=Math.max(1,Math.round(image.width*scale));canvas.height=Math.max(1,Math.round(image.height*scale));canvas.getContext('2d').drawImage(image,0,0,canvas.width,canvas.height);image.close();
  const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/webp',0.68));if(!blob||blob.type!=='image/webp')throw new Error('无法生成 WebP 预览图');
  const data=formBody({device,relative});data.set('thumbnail',blob,'thumbnail.webp');await requestJSON('upload_thumbnail.php',data);
}
function queueUploadThumbnail(file,device,relative){uploadThumbnailQueue=uploadThumbnailQueue.then(()=>saveUploadThumbnail(file,device,relative)).catch(error=>console.warn('上传图片预览图将在图库打开时生成。',error));}
function uploadChunkSize(fileSize){
  const maxChunk=1900*1024,minChunk=1024*1024,targetChunks=6;
  if(fileSize<=maxChunk)return Math.max(1,fileSize);
  return Math.min(maxChunk,Math.max(minChunk,Math.ceil(fileSize/targetChunks)));
}
async function uploadQueue(){
  if(uploadBusy)return;uploadBusy=true;
  const category=document.querySelector('#upload-category').value,device=document.querySelector('#upload-device').value;
  const batch={action:null};
  try{
    const pending=state.uploaded.filter(file=>!file.done);
    if(!await preflightUploadConflicts(pending,category,device,batch)){toast('已取消本次上传');return;}
    for(const entry of pending.filter(file=>!file.done)){
      if(!state.uploaded.includes(entry))continue;
      entry.error='';entry.uploading=true;if(state.page==='upload')renderUploadQueue(true);const id=Array.from(crypto.getRandomValues(new Uint8Array(16)),byte=>byte.toString(16).padStart(2,'0')).join('');entry.uploadId=id;entry.abortController=new AbortController();
      const size=uploadChunkSize(entry.size),total=Math.ceil(entry.size/size);let index=0,conflictAction=entry.conflictAction||'prompt';
      try{while(index<total){
        const data=formBody({upload_id:id,index,total,category,device:entry.targetDevice||device,conflict_action:conflictAction});data.append('chunk',entry.file.slice(index*size,(index+1)*size),entry.name);
        let response;
        try{response=await requestJSON('upload_chunk.php',data,{signal:entry.abortController.signal});}catch(error){
          if(error.status===409&&error.data?.conflict){
            entry.waitingConflict=true;if(state.page==='upload')renderUploadQueue(false);let choice=batch.action;
            if(!choice){const selected=await duplicateChoice(entry,{filename:error.data.filename,url:error.data.server_image_url});if(selected){choice=selected.choice;if(selected.applyToBatch)batch.action=choice;}}
            entry.waitingConflict=false;if(state.page==='upload')renderUploadQueue(false);
            if(!choice)throw new Error('已取消同名图片处理');conflictAction=choice;continue;
          }
          throw error;
        }
        entry.progress=response.progress;entry.done=response.complete===true;entry.skipped=response.skipped===true;entry.resultName=response.relative?.split('/').pop()||entry.resultName;
        if(entry.done&&!entry.skipped&&response.relative)queueUploadThumbnail(entry.file,response.device||entry.targetDevice||device,response.relative);
        if(state.page==='upload')renderUploadQueue(false);index++;
      }}catch(error){if(error.name==='AbortError'){try{await requestJSON('upload_cancel.php',formBody({upload_id:id}));}catch{}}else entry.error=error.message;}finally{entry.uploading=false;entry.abortController=null;if(state.page==='upload')renderUploadQueue(false);}
    }
    await refreshLibrary();const remaining=state.uploaded.filter(file=>!file.done).length,kept=state.uploaded.filter(file=>file.skipped).length;toast(remaining?`部分图片未上传（${remaining} 张），请查看队列原因`:kept?`上传已完成，${kept} 张已跳过`:'图片已上传');
  }finally{uploadBusy=false;if(state.page==='upload')renderUploadQueue(false);else render();}
}

function profileSettings(){return baseProfileSettings()+(serverData.emailPending?`<form id="verify-email-form" class="settings-section"><h2>验证新邮箱</h2>${field('verification_code','邮箱验证码','','text','required pattern="[0-9]{6}" autocomplete="one-time-code"')}<div class="settings-inline-save"><button type="button" class="text-button" data-production-action="cancel-email">取消更换</button><button class="button primary" type="submit">确认更换</button></div></form>`:'');}
function generalSettings(){return baseGeneralSettings();}
function mailSettings(){const s=serverData.settings,smtp=serverData.smtp;return `<form id="smtp-form" class="settings-section"><h2>邮件服务</h2><p>保存 SMTP 后可发送测试邮件。</p><div class="form-grid">${field('smtp_host','SMTP 服务器',smtp.host,'text','required')}${field('smtp_port','端口',smtp.port||465,'number','required min="1" max="65535"')}${field('smtp_username','发信账号',smtp.username,'text','required autocomplete="username"')}${field('smtp_password','授权码','','password','placeholder="留空保留已保存密码" autocomplete="new-password"')}<div class="field"><label for="smtp-encryption">连接加密</label><select id="smtp-encryption" name="smtp_encryption">${[['ssl','SSL / TLS'],['tls','STARTTLS']].map(([key,label])=>`<option value="${key}" ${smtp.encryption===key?'selected':''}>${label}</option>`).join('')}</select></div>${field('notification_email','通知邮箱',s.notification_email,'email')}</div><div class="settings-inline-save"><button type="button" class="button" data-action="test-mail">${icon('mail')}发送测试邮件</button><button class="button primary" type="submit">${icon('save')}保存邮件服务</button></div></form><section id="notification-settings" class="settings-section"><h2>通知与安全</h2>${checkbox('login_notification','登录通知',s.login_notification,'管理员登录后发送邮件通知。')}${checkbox('password_breach_alert','登录失败告警',s.password_breach_alert,'连续登录失败时发送安全提醒。')}${checkbox('request_rate_alert','异常请求告警',s.request_rate_alert,'接口出现异常访问时发送提醒。')} ${checkbox('daily_summary','每日访问摘要',s.daily_summary,'汇总每日接口请求和图片分发情况。')}<p class="notification-note">每日摘要需要配置服务器定时任务，详见部署文档。</p></section>`;}
function runtimeSettings(){
  const s=serverData.settings;
  const tips={
    GD:{id:'gd',html:'<strong>用途</strong><br>生成图库缩略图，让图片列表加载更快。没有 GD 时会尝试 PHP Imagick 或 ImageMagick 命令行；都不可用时，工作台会按需在浏览器压缩可见图片并缓存为缩略图。<br><strong>安装</strong><br>宝塔：在对应 PHP 版本的设置中安装 GD 扩展并重启 PHP。Debian/Ubuntu：安装 <code>php-gd</code> 后重启对应 PHP-FPM。Windows：在当前 PHP 的 <code>php.ini</code> 启用 <code>extension=gd</code>，再重启 PHP。也可安装 ImageMagick，并把命令加入 PATH。'},
    HTTPS:{id:'https',html:'<strong>用途</strong><br>加密浏览器与网站之间的传输，保护后台登录和会话。<br><strong>配置</strong><br>在宝塔或 Web 服务器中申请并启用 TLS/SSL 证书，确认可以通过 HTTPS 打开站点后，再开启“强制 HTTPS”。若使用反向代理，还需正确传递 HTTPS 状态并配置可信代理。'},
    '存储目录':{id:'storage',html:'<strong>用途</strong><br>保存数据库、安装配置、会话、日志和缓存；后台需要在此目录创建和更新文件。<br><strong>配置</strong><br>将 <code>storage/</code> 的所有者设为实际运行 PHP-FPM 的系统用户，并授予该用户读写权限。Debian/Ubuntu 常见用户为 <code>www-data</code>，宝塔请使用站点 PHP 进程的运行用户。Windows 可给 IIS/PHP 运行账号授予修改权限。不要使用 777 权限。'}
  };
  const rows=Object.entries(serverData.environment).map(([label,value])=>{
    const tip=tips[label];
    const info=tip?`<span class="runtime-help" tabindex="0" aria-describedby="runtime-help-${tip.id}">${icon('info')}<span class="runtime-tooltip" id="runtime-help-${tip.id}" role="tooltip">${tip.html}</span></span>`:icon('info');
    return `<div class="status-row">${info}<span>${escapeHTML(label)}</span><strong>${escapeHTML(value)}</strong></div>`;
  }).join('');
  const logKinds=[['access','访问日志'],['upload','上传审计'],['login','登录日志']];
  const logRetention=logKinds.map(([kind,label])=>`<div class="log-retention-group">${checkbox(kind+'_log_auto_cleanup','自动清理'+label,s[kind+'_log_auto_cleanup'])}<div class="field log-retention-period"><label>${label}保留周期<select name="${kind}_log_retention_months">${[1,3,6,12,24,36,60].map(month=>`<option value="${month}" ${s[kind+'_log_retention_months']===month?'selected':''}>${month} 个月</option>`).join('')}</select></label></div></div>`).join('');
  return `<div class="settings-section"><h2>运行环境</h2>${rows}</div><div id="retention-settings" class="settings-section log-retention-settings"><h2>日志保留</h2>${logRetention}<p class="log-retention-note">开启后同时清理数据库记录和本地日志文件。</p></div>`;
}
function otherSettings(){
  const enabled=Boolean(serverData.settings.developer_mode),httpsEnabled=Boolean(serverData.settings.force_admin_https);
  const checks=developerDiagnostics.map(check=>`<div class="developer-check-row is-${escapeHTML(check.status)}"><strong>${escapeHTML(check.name)}</strong><span>${escapeHTML(check.detail)}</span></div>`).join('');
  const developerSettings=`<div class="settings-section developer-mode-settings"><h2>其他设置</h2><div class="other-settings-divider other-settings-divider-top" role="separator"></div>${checkbox('developer_mode','开发者模式',enabled,'用于运行检查、维护图库和恢复初始状态。')}${enabled?`<div class="developer-tools-grid"><article class="developer-tool"><h3>环境自检</h3><button class="button" type="button" data-action="developer-diagnostics">运行检查</button>${checks?`<div class="developer-check-list" aria-live="polite">${checks}</div>`:''}</article><article class="developer-tool"><h3>刷新图库索引</h3><p>重扫图片并更新缓存。</p><button class="button" type="button" data-action="developer-rebuild-index">重建索引</button></article><article class="developer-tool developer-tool-danger"><h3>恢复初始状态</h3><p>删除所有站点数据并重新初始化。</p><button class="button danger" type="button" data-action="developer-cleanup-open">${icon('trash')}恢复初始状态</button></article></div>`:''}<div class="other-settings-divider" role="separator"></div>${checkbox('force_admin_https','强制 HTTPS 访问后台',httpsEnabled,'启用前请确认站点已配置 HTTPS。')}</div>`;
  const updateSettings=`<div class="settings-section"><div class="online-update-settings"><div><h3>在线更新</h3><p>当前版本 <strong>v${escapeHTML(PICAPI_VERSION)}</strong> · 发现新版本后可自动安装。</p><a href="${PICAPI_REPOSITORY_URL}" target="_blank" rel="noopener noreferrer">PicAPI 开源仓库 ↗</a><small id="update-check-status" role="status" aria-live="polite">检查后会先询问，再自动备份和安装。</small></div><button class="button" type="button" data-action="check-updates">检查更新</button></div></div>`;
  return developerSettings+loginSessionSettingsMarkup()+updateSettings;
}async function checkPicApiUpdates(triggerButton){
  const status=document.querySelector('#update-check-status');
  await perform(async()=>{
    if(status)status.textContent='正在检查 GitHub 版本…';
    try{
      const response=await apiRequest('update-check',{}),info=response.result;
      if(!info?.available){if(status)status.textContent=`当前已是最新版本（v${escapeHTML(info?.current||PICAPI_VERSION)}）。`;return;}
      if(status)status.textContent=`发现新版本 ${info.latest}。`;
      const notes=String(info.notes||'').trim();
      const body=`<div class="modal-body update-confirm-body"><p>当前版本 <strong>v${escapeHTML(info.current)}</strong>，可更新到 <strong>v${escapeHTML(info.latest)}</strong>。</p><p>确认后将进入更新页面，程序会自动备份并安装，图片、配置和日志会保留。</p>${notes?`<details><summary>查看更新说明</summary><pre>${escapeHTML(notes)}</pre></details>`:''}</div>`;
      openModal('发现新版本',body,button('稍后','close-modal','')+button('安装并自动更新','confirm-picapi-update','download',true));
    }catch(error){if(status)status.textContent=error.message;throw error;}
  },triggerButton);
}
async function saveLogRetentionSetting(control){
  if(control.disabled)return;
  const name=control.name,previous=serverData.settings[name];
  const value=control.type==='checkbox'?control.checked:Number(control.value);
  await perform(async()=>{
    try{
      await apiRequest('settings',{[name]:value});
      serverData.settings[name]=value;
      toast('日志保留策略已自动保存');
    }catch(error){
      if(control.type==='checkbox')control.checked=Boolean(previous);else control.value=String(previous);
      throw error;
    }
  },control);
}
async function saveDeveloperModeSetting(control){
  if(control.disabled)return;
  const previous=Boolean(serverData.settings.developer_mode),value=control.checked;
  await perform(async()=>{
    try{
      await apiRequest('settings',{developer_mode:value});
      serverData.settings.developer_mode=value;
      render();
      toast(value?'开发者模式已开启':'开发者模式已关闭');
    }catch(error){control.checked=previous;throw error;}
  },control);
}
async function saveForceHttpsSetting(control){
  if(control.disabled)return;
  const previous=Boolean(serverData.settings.force_admin_https),value=control.checked;
  await perform(async()=>{
    try{
      await apiRequest('settings',{force_admin_https:value});
      serverData.settings.force_admin_https=value;
      toast('HTTPS 访问设置已自动保存');
    }catch(error){control.checked=previous;throw error;}
  },control);
}
async function saveNotificationSetting(control){
  if(control.disabled)return;
  const name=control.name,previous=Boolean(serverData.settings[name]),value=control.checked;
  await perform(async()=>{
    try{
      await apiRequest('settings',{[name]:value});
      serverData.settings[name]=value;
      toast('通知策略已自动保存');
    }catch(error){control.checked=previous;throw error;}
  },control);
}
function loginAppearanceSettings(){
  const s=serverData.settings,wallpaper=s.login_background_image||'',type=wallpaper?'image':'color';
  const mode=state.theme==='dark'?'dark':'light',palette=preferences.palettes[mode];
  const previewImage=wallpaper?`url("${new URL('login_wallpaper.php',location.href).href}?v=${encodeURIComponent(wallpaper)}")`:'';
  const light=s.login_background_light_color||'#F5F7FB',dark=s.login_background_dark_color||'#0F172A';
  return `<form id="login-appearance-form" class="settings-section login-appearance-settings"><h2>登录页面主题</h2><p>页面配色沿用后台外观主题，可单独设置登录背景颜色或壁纸。</p><input type="hidden" name="login_background_type" value="${type}"><div class="login-appearance-layout"><div class="login-preview-column"><div class="login-theme-preview mode-${mode}" data-login-theme-preview data-background-type="${type}" style="--login-preview-light:${escapeHTML(light)};--login-preview-dark:${escapeHTML(dark)};--login-preview-opacity:${Number(s.login_background_opacity??55)/100};--login-preview-blur:${Number(s.login_background_blur??0)}px;--login-preview-surface:${palette.surface};--login-preview-ink:${palette.text};--login-preview-muted:${palette.muted};--login-preview-line:color-mix(in srgb,${palette.text} 16%,${palette.surface});--login-preview-accent:${palette.accent};${previewImage?`--login-preview-image:${escapeHTML(previewImage)};`:''}"><div class="login-preview-card"><span class="login-preview-mark">P</span><strong>${escapeHTML(s.site_name||preferences.site.name||'PicAPI')}</strong><span class="login-preview-field"></span><span class="login-preview-field"></span><span class="login-preview-button">登录</span></div><span class="login-preview-caption">${mode==='dark'?'深色':'浅色'}模式预览</span></div><p class="login-preview-note">预览使用当前后台配色；登录页背景按所选模式分别保存。</p></div><div class="login-appearance-controls"><div class="login-colors"><h3>自定义背景颜色</h3><div class="form-grid"><label class="color-control"><input type="color" name="login_background_light_color" value="${escapeHTML(light)}"><span>浅色模式</span></label><label class="color-control"><input type="color" name="login_background_dark_color" value="${escapeHTML(dark)}"><span>深色模式</span></label></div></div><div class="login-wallpaper-controls"><div class="login-wallpaper-heading"><h3>背景壁纸</h3><div class="actions"><button class="button" type="button" data-login-wallpaper-upload>上传壁纸</button><button class="button" type="button" data-login-wallpaper-library>从图库选择</button><button class="text-button" type="button" data-login-wallpaper-clear ${wallpaper?'':'hidden'}>移除</button></div></div><input class="file-input" type="file" id="login-wallpaper-file" accept="image/jpeg,image/png,image/webp,image/gif"><label class="field login-opacity-field"><span>壁纸透明度 <strong data-login-opacity-value>${Number(s.login_background_opacity??55)}%</strong></span><input type="range" min="0" max="100" step="1" name="login_background_opacity" value="${Number(s.login_background_opacity??55)}" ${wallpaper?'':'disabled'}></label><label class="field login-blur-field"><span>毛玻璃模糊 <strong data-login-blur-value>${Number(s.login_background_blur??0)} px</strong></span><input type="range" min="0" max="50" step="1" name="login_background_blur" value="${Number(s.login_background_blur??0)}" ${wallpaper?'':'disabled'}></label><small>选择壁纸后会自动启用；移除壁纸后自动使用纯色背景。</small></div><div class="settings-inline-save"><button class="button primary" type="submit">保存登录页设置</button></div></div></div></form>`;
}
function settings(){
  const tabs=[['general','站点信息','globe'],['appearance','外观主题','palette'],['login','登录页面','lock'],['mail','邮件通知','mail'],['templates','邮件模板','file'],['account','个人资料','user'],['runtime','运行环境','database'],['other','其他设置','settings']];
  const content={general:generalSettings,appearance:appearanceSettings,login:loginAppearanceSettings,mail:mailSettings,templates:mailTemplateSettings,account:profileSettings,runtime:runtimeSettings,other:otherSettings}[state.settingsTab]();
  return `${head('settings')}<div class="settings-layout"><nav class="local-nav" aria-label="设置分类">${tabs.map(([key,label,symbol])=>`<button data-settings-tab="${key}" class="${state.settingsTab===key?'active':''}">${icon(symbol)}${label}</button>`).join('')}</nav><section class="panel">${content}</section></div>`;
}

function compressLoginWallpaper(source){
  return new Promise((resolve,reject)=>{
    const image=new Image();image.onload=()=>{try{let scale=Math.min(1,1920/Math.max(image.naturalWidth,image.naturalHeight));const canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(image.naturalWidth*scale));canvas.height=Math.max(1,Math.round(image.naturalHeight*scale));const context=canvas.getContext('2d',{alpha:false});context.fillStyle='#ffffff';context.fillRect(0,0,canvas.width,canvas.height);context.drawImage(image,0,0,canvas.width,canvas.height);let attempt=0,resizes=0;const resizeAndRetry=()=>{if(resizes>=3)return false;resizes++;canvas.width=Math.max(1,Math.round(canvas.width*.8));canvas.height=Math.max(1,Math.round(canvas.height*.8));context.fillStyle='#ffffff';context.fillRect(0,0,canvas.width,canvas.height);context.drawImage(image,0,0,canvas.width,canvas.height);attempt=0;encode();return true;};const encode=()=>{const quality=[0.78,0.62,0.48][Math.min(attempt,2)];canvas.toBlob(blob=>{if(!blob)return reject(new Error('浏览器无法压缩这张图片'));if(blob.type!=='image/webp'){canvas.toBlob(jpeg=>{if(!jpeg)return reject(new Error('浏览器无法压缩这张图片'));if(jpeg.size>3*1048576){if(attempt<2){attempt++;encode();return;}if(resizeAndRetry())return;reject(new Error('图片压缩后仍超过 3 MB，请换一张图片'));return;}resolve(new File([jpeg],'login-wallpaper.jpg',{type:'image/jpeg'}));},'image/jpeg',quality);return;}if(blob.size>3*1048576){if(attempt<2){attempt++;encode();return;}if(resizeAndRetry())return;reject(new Error('图片压缩后仍超过 3 MB，请换一张图片'));return;}resolve(new File([blob],'login-wallpaper.webp',{type:'image/webp'}));},'image/webp',quality);};encode(); }catch(error){reject(error);}finally{if(source.startsWith('blob:'))URL.revokeObjectURL(source);}};image.onerror=()=>{if(source.startsWith('blob:'))URL.revokeObjectURL(source);reject(new Error('无法读取这张图片'));};image.src=source;
  });
}
async function saveLoginWallpaper(source){
  const file=await compressLoginWallpaper(source);const body=formBody();body.set('wallpaper',file,file.name);
  const result=await requestJSON('login_wallpaper_save.php',body);serverData.settings=result.settings;state.settingsTab='login';render();toast(`壁纸已压缩并保存（${(file.size/1048576).toFixed(2)} MB）`);
}
const clientThumbnailJobs=new Map();
function processClientThumbnail(image){
  if(!image.matches('img[data-thumbnail-device][data-thumbnail-relative]')||!image.classList.contains('thumbnail-needs-check'))return;
  const device=image.dataset.thumbnailDevice,relative=image.dataset.thumbnailRelative,key=device+'|'+relative;
  if(!['pc','mobile'].includes(device)||!relative)return;
  const record=images.find(item=>item.device===device&&item.relative===relative);
  const thumbnailVersion=new URL(image.src,location.href).searchParams.get('v'),hasCachedVersion=Boolean(thumbnailVersion&&thumbnailVersion!=='preview-webp-1');
  if(image.naturalWidth>1&&image.naturalHeight>1&&image.naturalWidth<=320&&image.naturalHeight<=320&&(hasCachedVersion||Number(record?.width||0)>320||Number(record?.height||0)>320||Number(record?.bytes||0)<=100000)){image.classList.remove('thumbnail-needs-check');return;}
  if(!clientThumbnailJobs.has(key)){
    const task=(async()=>{let original=image;if(image.naturalWidth<=1&&image.naturalHeight<=1){if(!record)throw new Error('找不到原始图片记录');original=await new Promise((resolve,reject)=>{const source=new Image();source.onload=()=>resolve(source);source.onerror=()=>reject(new Error('原图加载失败'));source.src=record.url;});}const canvas=document.createElement('canvas');const scale=Math.min(1,320/Math.max(original.naturalWidth,original.naturalHeight));canvas.width=Math.max(1,Math.round(original.naturalWidth*scale));canvas.height=Math.max(1,Math.round(original.naturalHeight*scale));const context=canvas.getContext('2d');context.drawImage(original,0,0,canvas.width,canvas.height);const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/webp',0.68));if(!blob||blob.type!=='image/webp')throw new Error('无法生成 WebP 预览图，请更新浏览器后重试');const body=formBody({device,relative});body.set('thumbnail',blob,'thumbnail.webp');await requestJSON('upload_thumbnail.php',body);for(const candidate of document.querySelectorAll('img[data-thumbnail-device][data-thumbnail-relative]'))if(candidate.dataset.thumbnailDevice===device&&candidate.dataset.thumbnailRelative===relative){candidate.classList.add('thumbnail-needs-check');candidate.src=`thumbnail.php?device=${encodeURIComponent(device)}&relative=${encodeURIComponent(relative)}&v=${Date.now()}`;}
    })().finally(()=>clientThumbnailJobs.delete(key));clientThumbnailJobs.set(key,task);
  }
  image.classList.add('thumbnail-needs-check');
  clientThumbnailJobs.get(key).catch(()=>image.classList.remove('thumbnail-needs-check'));
}
function openLoginWallpaperLibrary(){
  const cards=images.map(item=>`<button type="button" class="login-wallpaper-choice" data-login-wallpaper-id="${escapeHTML(item.id)}"><img loading="lazy" src="${escapeHTML(item.src||item.url)}" alt="" data-thumbnail-device="${escapeHTML(item.device)}" data-thumbnail-relative="${escapeHTML(item.relative)}" ${item.width>320||item.height>320||item.bytes>100000?'class="thumbnail-needs-check"':''}><span>${escapeHTML(item.original)}</span><small>${escapeHTML(item.width+' × '+item.height)}</small></button>`).join('');
  openModal('从图库选择壁纸',`<div class="modal-body"><div class="login-wallpaper-library">${cards||'<p class="muted login-wallpaper-empty">图库中还没有图片。</p>'}</div></div>`,'','login-wallpaper-dialog');
}

function isSuccessfulAccessStatus(value){const status=Number(value);return Number.isFinite(status)&&status>=200&&status<300;}
function accessLogStatusBadge(value){const status=Number(value);let tone='unknown';if(Number.isFinite(status)){if(status>=200&&status<300)tone='success';else if(status>=300&&status<400)tone='redirect';else if(status===429)tone='limited';else if(status>=400&&status<500)tone='client-error';else if(status>=500&&status<600)tone='server-error';}const code=Number.isFinite(status)?String(status):'—';return `<span class="chip log-status log-status-${tone}" aria-label="HTTP 状态 ${code}">${code}</span>`;}
function logRecordDay(row){const raw=String(row.time||row.logged_at||'');if(/^\d{4}-\d{2}-\d{2}/.test(raw))return raw.slice(0,10);const date=new Date(raw);return Number.isNaN(date.getTime())?'':new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Shanghai',year:'numeric',month:'2-digit',day:'2-digit'}).format(date);}
function filteredServerLogs(){const source=serverLogs.kind==='upload'?serverLogs.uploads:serverLogs.rows;const q=serverLogs.search.toLowerCase();return source.filter(row=>{const day=logRecordDay(row);return(!serverLogs.startDate||day>=serverLogs.startDate)&&(!serverLogs.endDate||day<=serverLogs.endDate)&&(!q||JSON.stringify(row).toLowerCase().includes(q))&&(serverLogs.kind==='upload'||state.logFilter==='all'||(state.logFilter==='success'?isSuccessfulAccessStatus(row.status):!isSuccessfulAccessStatus(row.status)));});}
function calendarDayKey(date){return date.toISOString().slice(0,10);}
function renderLogCalendar(){
  const first=new Date(Date.UTC(logCalendarDraft.year,logCalendarDraft.month,1));
  const offset=(first.getUTCDay()+6)%7;
  const gridStart=new Date(Date.UTC(logCalendarDraft.year,logCalendarDraft.month,1-offset));
  const monthLabel=new Intl.DateTimeFormat('zh-CN',{year:'numeric',month:'long',timeZone:'UTC'}).format(first);
  const logDays=new Set(serverLogs.kind==='upload'?serverLogs.uploadDates:serverLogs.accessDates);
  const cells=Array.from({length:42},(_,index)=>{
    const date=new Date(gridStart);date.setUTCDate(gridStart.getUTCDate()+index);
    const key=calendarDayKey(date),inMonth=date.getUTCMonth()===logCalendarDraft.month,hasLogs=logDays.has(key);
    const isStart=key===logCalendarDraft.startDate,isEnd=key===logCalendarDraft.endDate;
    const inRange=Boolean(logCalendarDraft.startDate&&logCalendarDraft.endDate&&key>logCalendarDraft.startDate&&key<logCalendarDraft.endDate);
    const classes=['log-calendar-day',hasLogs?'has-logs':'no-logs',inMonth?'':'outside-month',inRange?'in-range':'',isStart?'range-start':'',isEnd?'range-end':''].filter(Boolean).join(' ');
    return `<button type="button" class="${classes}" data-log-calendar-date="${key}" aria-pressed="${isStart||isEnd}" aria-label="${key}，${hasLogs?'有日志':'无记录'}"><span>${date.getUTCDate()}</span>${hasLogs?'<i aria-hidden="true"></i>':''}</button>`;
  }).join('');
  const prompt=logCalendarDraft.startDate&&logCalendarDraft.endDate?`${logCalendarDraft.startDate} 至 ${logCalendarDraft.endDate}`:logCalendarDraft.startDate?`开始日期：${logCalendarDraft.startDate}，请选择结束日期`:'先选择开始日期，再选择结束日期';
  return `<div class="log-calendar"><div class="log-calendar-head"><button type="button" class="icon-button" data-action="log-calendar-prev" aria-label="上个月">${icon('chevron')}</button><strong>${monthLabel}</strong><button type="button" class="icon-button log-calendar-next" data-action="log-calendar-next" aria-label="下个月">${icon('chevron')}</button></div><div class="log-calendar-legend"><span><i class="has-logs"></i>有日志</span><span><i class="no-logs"></i>无记录</span></div><div class="log-calendar-grid" role="grid" aria-label="${monthLabel}">${['一','二','三','四','五','六','日'].map(day=>`<span class="log-calendar-weekday" role="columnheader">${day}</span>`).join('')}${cells}</div><p class="log-calendar-prompt" aria-live="polite">${escapeHTML(prompt)}</p></div>`;
}
function openLogCalendar(){
  logCalendarOpen=!logCalendarOpen;
  if(!logCalendarOpen){render();return;}
  logCalendarDraft.startDate=serverLogs.startDate;logCalendarDraft.endDate=serverLogs.endDate;
  const base=serverLogs.startDate||new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Shanghai',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date()),[year,month]=base.split('-').map(Number);
  logCalendarDraft.year=year;logCalendarDraft.month=month-1;
  render();
}
function shiftLogCalendarMonth(delta){const date=new Date(Date.UTC(logCalendarDraft.year,logCalendarDraft.month+delta,1));logCalendarDraft.year=date.getUTCFullYear();logCalendarDraft.month=date.getUTCMonth();const calendar=document.querySelector('#log-calendar-content');if(calendar)calendar.innerHTML=renderLogCalendar();}
function selectLogCalendarDate(date){
  if(!logCalendarDraft.startDate||logCalendarDraft.endDate){logCalendarDraft.startDate=date;logCalendarDraft.endDate='';}
  else if(date<logCalendarDraft.startDate){logCalendarDraft.startDate=date;logCalendarDraft.endDate='';}
  else{
    logCalendarDraft.endDate=date;serverLogs.startDate=logCalendarDraft.startDate;serverLogs.endDate=logCalendarDraft.endDate;serverLogs.page=1;
    closeLogCalendar();void perform(async()=>{await loadLogs('logs');toast('日志日期范围已更新');});return;
  }
  const calendar=document.querySelector('#log-calendar-content');if(calendar)calendar.innerHTML=renderLogCalendar();
}
function logs(){
  const rows=filteredServerLogs(),pages=Math.max(1,Math.ceil(rows.length/serverLogs.size));serverLogs.page=Math.min(serverLogs.page,pages);
  const upload=serverLogs.kind==='upload';
  const sectionName=upload?'上传日志':'访问日志';
  const capText=upload?'最多读取最近 5000 条上传记录':'最多读取最近 5000 条访问记录';
  const rangeLabel=serverLogs.startDate&&serverLogs.endDate?`${serverLogs.startDate} 至 ${serverLogs.endDate}`:serverLogs.startDate||serverLogs.endDate||'全部日期';
  return `${head('logs',button('导出'+sectionName,'export-logs','download',true))}<div class="filterbar"><div class="tabs"><button data-production-log-kind="access" class="${!upload?'active':''}">访问日志</button><button data-production-log-kind="upload" class="${upload?'active':''}">上传审计</button></div><div class="actions"><label class="search">${icon('search')}<input id="server-log-search" placeholder="搜索 IP、路径或文件名" value="${escapeHTML(serverLogs.search)}"></label>${button('刷新','refresh-logs','refresh')}</div></div><div class="log-date-toolbar"><div class="log-calendar-anchor"><button class="button" data-action="open-log-calendar" aria-expanded="${logCalendarOpen}">${icon('calendar')}选择日期范围</button><div id="log-calendar-popover" class="log-calendar-popover" ${logCalendarOpen?'':'hidden'}>${logCalendarOpen?`<div id="log-calendar-content">${renderLogCalendar()}</div><div class="log-calendar-actions">${button('清除日期','clear-log-range','close',false,serverLogs.startDate||serverLogs.endDate?'':'disabled')}${button('关闭','close-log-calendar','')}</div>`:''}</div></div><strong class="log-date-current">${escapeHTML(rangeLabel)}</strong><span class="log-date-note">${capText}</span></div><section class="panel"><div class="production-log-scroll"><table><thead><tr><th>时间</th><th>${upload?'文件':'IP'}</th><th>${upload?'分类':'路径'}</th><th>设备</th><th>${upload?'大小':'状态'}</th><th>详情</th></tr></thead><tbody>${rows.slice((serverLogs.page-1)*serverLogs.size,serverLogs.page*serverLogs.size).map((row,index)=>`<tr><td>${escapeHTML(row.time||row.logged_at||'')}</td><td>${escapeHTML(upload?(row.name||row.file_name||row.filename||''):row.ip)}</td><td class="production-wrap">${escapeHTML(upload?(row.category||row.category_alias||''):(row.image||''))}</td><td>${escapeHTML(row.device||'')}</td><td>${upload?escapeHTML(String(row.size??row.file_size??0)+' B'):accessLogStatusBadge(row.status)}</td><td><button class="text-button" data-production-log-detail="${(serverLogs.page-1)*serverLogs.size+index}">详情</button></td></tr>`).join('')||'<tr class="log-empty-row"><td colspan="6"><span role="status">暂无符合条件的记录</span></td></tr>'}</tbody></table></div><div class="security-list-footer"><span>共 ${rows.length} 条 · ${capText}</span><label>每页<select id="server-log-size">${[10,20,50].map(n=>`<option value="${n}" ${serverLogs.size===n?'selected':''}>${n} 条</option>`).join('')}</select></label><nav class="security-list-pagination" aria-label="访问日志分页"><button class="icon-button" data-production-log-page="-1" aria-label="上一页" ${serverLogs.page===1?'disabled':''}>${icon('chevron')}</button><span>${serverLogs.page} / ${pages}</span><button class="icon-button" data-production-log-page="1" aria-label="下一页" ${serverLogs.page===pages?'disabled':''}>${icon('chevron')}</button></nav></div></section>`;
}

function openLogExportDialog(){
  const upload=serverLogs.kind==='upload';
  const section=upload?'uploads':'access';
  const baseName=upload?'上传日志':'访问日志';
  const start=serverLogs.startDate;
  const end=serverLogs.endDate;
  if(start&&end&&start>end){toast('开始日期不能晚于结束日期');return;}
  const range=start||end?`${start||'不限'} 至 ${end||'不限'}`:'全部日期';
  openModal('导出'+baseName,`<div class="modal-body log-export-body"><p class="modal-message">导出范围：<strong>${escapeHTML(range)}</strong>。导出文件名为“${baseName}”，格式由你选择。</p><fieldset class="log-export-formats"><legend>导出格式</legend><label><input type="radio" name="log-export-format" value="csv" checked><span><strong>CSV</strong><small>适合 Excel 表格查看</small></span></label><label><input type="radio" name="log-export-format" value="json"><span><strong>JSON</strong><small>适合程序读取</small></span></label><label><input type="radio" name="log-export-format" value="txt"><span><strong>TXT</strong><small>纯文本表格</small></span></label></fieldset><p class="log-export-filename">文件名：<strong>${baseName}.csv / .json / .txt</strong></p></div>`,button('取消','close-modal','')+`<button class="button primary" data-action="confirm-logs-export" data-export-section="${section}" data-export-start="${escapeHTML(start)}" data-export-end="${escapeHTML(end)}">${icon('download')}导出</button>`);
}

function exportSelectedLogs(control){
  const format=document.querySelector('input[name="log-export-format"]:checked')?.value||'csv';
  const section=control?.dataset.exportSection|| (serverLogs.kind==='upload'?'uploads':'access');
  const url=new URL('export_logs.php',location.href);
  url.searchParams.set('section',section);url.searchParams.set('format',format);
  if(control?.dataset.exportStart)url.searchParams.set('start_date',control.dataset.exportStart);
  if(control?.dataset.exportEnd)url.searchParams.set('end_date',control.dataset.exportEnd);
  closeModal();location.assign(url.href);
}

function clearLogDateRange(){
  logCalendarOpen=false;
  serverLogs.startDate='';serverLogs.endDate='';serverLogs.page=1;
  logCalendarDraft.startDate='';logCalendarDraft.endDate='';
  render();
  return perform(async()=>{await loadLogs('logs');toast('已显示全部日期日志');});
}
function closeLogCalendar(){logCalendarOpen=false;render();}

function openDeveloperCleanupWarning(){
  openModal('恢复初始状态？',`<div class="modal-body developer-cleanup-modal"><div class="developer-cleanup-warning"><strong>此操作无法撤销</strong><p>将永久删除管理员账号、站点设置、接口配置、分类、访问策略、数据库记录、上传图片和日志。</p><p>完成后会退出后台并返回初始化页面，需要重新配置数据库和管理员账号。</p></div><p class="modal-message">请确认当前环境中的数据都可以清空。</p></div>`,button('取消','close-modal','')+`<button class="button danger" data-action="developer-cleanup-confirm">${icon('arrow')}继续</button>`,'developer-cleanup-dialog');
}
function openDeveloperCleanupFinal(){
  openModal('最后确认：恢复初始状态',`<form id="developer-cleanup-form" class="modal-body developer-cleanup-modal"><p class="modal-message">所有站点数据会被永久删除，操作后需要重新初始化。</p><div class="field"><label for="developer-cleanup-confirmation">请输入 <strong>恢复初始状态</strong> 以确认</label><input id="developer-cleanup-confirmation" name="confirmation" type="text" autocomplete="off" required placeholder="恢复初始状态"></div></form>`,button('取消','close-modal','')+`<button class="button danger" type="submit" form="developer-cleanup-form" data-action="developer-cleanup-submit" disabled>${icon('trash')}恢复初始状态</button>`,'developer-cleanup-dialog');
}

Object.assign(actions,{
  'confirm-picapi-update':()=>{closeModal();location.href='update.php?install=1';},
  'confirm-delete':()=>perform(async()=>{const data=await requestJSON('action.php',formBody({action:'bulk_delete',items:chosenKeys()}));closeModal();await refreshLibrary();render();toast(data.message);},document.querySelector('[data-action="confirm-delete"]')),
  'start-upload':()=>perform(uploadQueue),
  'clear-upload':()=>{if(uploadBusy)return;state.uploaded.forEach(file=>URL.revokeObjectURL(file.url));state.uploaded=[];render();},
  'save-settings':()=>perform(async()=>{for(const input of main.querySelectorAll('[data-site-field]'))if(!input.reportValidity())return;const body=formBody({settings_section:'site',site_name:preferences.site.name,site_url:preferences.site.url,site_description:preferences.site.description,seo_title:preferences.site.seoTitle,seo_description:preferences.site.seoDescription,seo_keywords:preferences.site.seoKeywords});await requestJSON('settings_save.php',body);applyWorkspaceSiteName(preferences.site.name);toast('站点设置已保存');}),
  'save-appearance':()=>perform(()=>persistAppearanceSettings('外观设置已保存')),
  'save-mail-template':()=>perform(async()=>{await apiRequest('settings',{email_subject_template:preferences.subject,notification_email_html_template:mailTemplates.notification.html??generatedMailHTML(mailTemplates.notification,'notification'),password_reset_email_html_template:mailTemplates.reset.html??generatedMailHTML(mailTemplates.reset,'reset')});toast('邮件模板已保存');}),
  'test-mail':()=>perform(async()=>{await apiRequest('test-mail',{});toast('测试邮件已发送');}),
  'refresh-logs':()=>perform(async()=>{await loadLogs('logs');toast('访问日志已刷新');}),
  'export-logs':openLogExportDialog,
  'open-log-calendar':openLogCalendar,
  'log-calendar-prev':()=>shiftLogCalendarMonth(-1),
  'log-calendar-next':()=>shiftLogCalendarMonth(1),
  'clear-log-range':clearLogDateRange,
  'close-log-calendar':closeLogCalendar,
  'confirm-logs-export':()=>exportSelectedLogs(document.querySelector('[data-action="confirm-logs-export"]')),
  'developer-cleanup-open':openDeveloperCleanupWarning,
  'developer-cleanup-confirm':openDeveloperCleanupFinal,
  'check-updates':()=>checkPicApiUpdates(document.querySelector('[data-action="check-updates"]')),
  'developer-diagnostics':()=>perform(async()=>{const response=await apiRequest('developer-diagnostics',{});developerDiagnostics=response.result||[];render();},document.querySelector('[data-action="developer-diagnostics"]')),
  'developer-rebuild-index':()=>perform(async()=>{const response=await apiRequest('developer-rebuild-index',{});await refreshLibrary();render();toast(`图库索引已重建：${Number(response.result?.images||0)} 张图片`);},document.querySelector('[data-action="developer-rebuild-index"]')),
  logout:()=>{announceLiveMutation('changed',0);const form=document.createElement('form');form.method='POST';form.action=adminRequestURL('logout.php');const input=document.createElement('input');input.name='csrf';input.value=csrf;form.append(input);document.body.append(form);form.submit();}
});

document.addEventListener('click',event=>{
  const target=event.target.closest('button,a');if(!target)return;
  if(target.dataset.uploadConflictChoice){event.preventDefault();event.stopImmediatePropagation();const choice=target.dataset.uploadConflictChoice,applyToBatch=Boolean(document.querySelector('[data-upload-conflict-batch]')?.checked),resolve=pendingUploadConflictResolver;pendingUploadConflictResolver=null;closeModal();resolve?.({choice,applyToBatch});return;}
  if(target.dataset.logCalendarDate){event.preventDefault();event.stopImmediatePropagation();selectLogCalendarDate(target.dataset.logCalendarDate);return;}
  if(target.matches('[data-login-wallpaper-upload]')){document.querySelector('#login-wallpaper-file')?.click();return;}
  if(target.matches('[data-login-wallpaper-library]')){openLoginWallpaperLibrary();return;}
  if(target.matches('[data-login-wallpaper-clear]')){perform(async()=>{const result=await requestJSON('login_wallpaper_save.php',formBody({action:'clear'}));serverData.settings=result.settings;render();toast('已恢复纯色背景');},target);return;}
  if(target.dataset.loginWallpaperId){const item=images.find(image=>String(image.id)===target.dataset.loginWallpaperId);if(item){closeModal();perform(()=>saveLoginWallpaper(item.url),target);}return;}
  if(['save-appearance','save-mail-template'].includes(target.dataset.action)){event.preventDefault();event.stopImmediatePropagation();actions[target.dataset.action]();return;}
  if(target.dataset.securityRemove){event.preventDefault();event.stopImmediatePropagation();const entry=securityEntries.find(item=>item.id===target.dataset.securityRemove);if(entry)perform(async()=>{await apiRequest('security-remove',entry);await loadSecurity();refreshSecurityLists(entry.kind);toast('名单已更新');},target);return;}
  if(target.hasAttribute('data-login-log-refresh')){event.preventDefault();event.stopImmediatePropagation();perform(async()=>{await loadLogs('login-logs');toast('登录日志已刷新');},target);return;}
  if(target.dataset.accountAction==='pick-avatar'){document.querySelector('#avatar-file').click();return;}
  if(target.dataset.accountAction==='send-password-code'){event.preventDefault();event.stopImmediatePropagation();perform(async()=>{const result=await requestJSON('account_save.php',formBody({action:'request_password_change_code'}));toast(result.message);},target);return;}
  if(target.dataset.accountAction==='remove-avatar'){perform(async()=>{await apiRequest('remove-avatar',{});pendingAvatar=null;if(profileDraft.avatar!==profile.avatar)releaseAvatar(profileDraft.avatar);profile.avatar='';profileDraft.avatar='';updateAvatarEditor();updateSidebarProfile();toast('头像已移除');},target);return;}
  if(target.dataset.productionLogKind){serverLogs.kind=target.dataset.productionLogKind;serverLogs.page=1;render();}
  if(target.dataset.productionLogPage&&!target.disabled){serverLogs.page+=Number(target.dataset.productionLogPage);render();}
  if(target.dataset.productionLogDetail!==undefined){const row=filteredServerLogs()[Number(target.dataset.productionLogDetail)];openModal('日志详情',`<div class="modal-body"><pre class="production-wrap">${escapeHTML(JSON.stringify(row,null,2))}</pre></div>`,button('关闭','close-modal',''));}
  if(target.dataset.productionAction==='cancel-email')perform(async()=>{await requestJSON('account_save.php',formBody({action:'cancel_email_change'}));serverData.emailPending=false;render();toast('已取消更换');},target);
},true);

document.querySelector('#modal')?.addEventListener('close',()=>{if(!pendingUploadConflictResolver)return;const resolve=pendingUploadConflictResolver;pendingUploadConflictResolver=null;resolve(null);});

document.addEventListener('click',event=>{
  if(!logCalendarOpen||!(event.target instanceof Element)||event.target.closest('.log-calendar-anchor'))return;
  logCalendarOpen=false;
  const popover=document.querySelector('#log-calendar-popover');if(popover)popover.hidden=true;
  document.querySelector('[data-action="open-log-calendar"]')?.setAttribute('aria-expanded','false');
},true);
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&logCalendarOpen)closeLogCalendar();});

document.addEventListener('input',event=>{const el=event.target;if(el.dataset.profileField)profileDraft[el.dataset.profileField]=el.value;});
document.addEventListener('load',event=>{if(event.target instanceof HTMLImageElement)processClientThumbnail(event.target);},true);
document.addEventListener('error',event=>{if(event.target instanceof HTMLImageElement)event.target.classList.remove('thumbnail-needs-check');},true);
document.addEventListener('input',event=>{
  if(event.target.id==='developer-cleanup-confirmation'){
    const submit=modal.querySelector('[data-action="developer-cleanup-submit"]');
    if(submit)submit.disabled=event.target.value!=='恢复初始状态';
  }
  const el=event.target;if(!el.closest('#login-appearance-form'))return;
  const preview=document.querySelector('[data-login-theme-preview]');if(!preview)return;
  if(el.name==='login_background_light_color')preview.style.setProperty('--login-preview-light',el.value);
  if(el.name==='login_background_dark_color')preview.style.setProperty('--login-preview-dark',el.value);
  if(el.name==='login_background_opacity'){preview.style.setProperty('--login-preview-opacity',String(Number(el.value)/100));const label=preview.closest('form')?.querySelector('[data-login-opacity-value]');if(label)label.textContent=el.value+'%';}
  if(el.name==='login_background_blur'){preview.style.setProperty('--login-preview-blur',String(Number(el.value))+'px');const label=preview.closest('form')?.querySelector('[data-login-blur-value]');if(label)label.textContent=el.value+' px';}
});
document.addEventListener('change',event=>{
  const el=event.target;
  if(el.closest('#notification-settings')&&el.type==='checkbox'){void saveNotificationSetting(el);return;}
  if(el.id==='setting-developer_mode'){void saveDeveloperModeSetting(el);return;}
  if(el.id==='setting-force_admin_https'){void saveForceHttpsSetting(el);return;}
  if(el.closest('#retention-settings')&&(el.type==='checkbox'||el.tagName==='SELECT')){void saveLogRetentionSetting(el);return;}
  if(el.id==='login-wallpaper-file'&&el.files?.[0]){const file=el.files[0];if(!file.type.startsWith('image/')){toast('请选择图片文件');return;}const url=URL.createObjectURL(file);perform(()=>saveLoginWallpaper(url),el);el.value='';}
  if(el.id==='avatar-file'){const file=el.files[0],previousAvatar=pendingAvatar;el.value='';chooseAvatar(file).then(cropped=>{pendingAvatar=cropped||previousAvatar;});}
  if(el.id==='upload-category')state.uploadCategory=el.value;
  if(el.id==='upload-device')state.uploadDevice=el.value;
  if(el.id==='server-log-search'){serverLogs.search=el.value;serverLogs.page=1;render();}
  if(el.id==='server-log-size'){serverLogs.size=Number(el.value);serverLogs.page=1;render();}
});

document.addEventListener('submit',event=>{
  if(['login-session-form','api-design-form'].includes(event.target.id))return;
  event.preventDefault();event.stopImmediatePropagation();
  const form=event.target;if(!form.reportValidity())return;const data=Object.fromEntries(new FormData(form)),control=event.submitter;
  perform(async()=>{
    if(form.id==='developer-cleanup-form'){
      await apiRequest('developer-cleanup',{confirmation:data.confirmation});
      closeModal();
      const setupUrl=new URL('setup.php',location.href);setupUrl.hash='';location.replace(setupUrl.href);
    }else if(form.id==='category-form'){
      const category=data.index===''?null:categories[Number(data.index)];
      if(category?.default)await apiRequest('default-category',{name:data.name,alias:data.alias});
      else await requestJSON('action.php',formBody({action:category?'rename_category':'create_category',old_category:category?.storageAlias||'',category_name:data.name,request_alias:data.alias}));
      closeModal();await refreshLibrary();render();toast('分类已保存');
    }else if(form.id==='delete-category-form'){
      await apiRequest('delete-category',{category:pendingCategoryDeletion.storageAlias,confirmation:data.confirmation});closeModal();state.category='all';await refreshLibrary();render();toast('分类及其图片已删除');
    }else if(form.id==='move-form'){
      await apiRequest('move',{items:chosenKeys(),category:currentCategory(data.category)?.storageAlias});closeModal();await refreshLibrary();render();toast('图片已移动');
    }else if(form.id==='security-policy-form'){
      const scope=form.dataset.scope;await apiRequest('security-policy',{scope,...securityDrafts[scope]});await loadSecurity();toast('访问策略已保存并生效');
    }else if(form.id==='security-entry-form'){
      const kind=form.dataset.kind,scope=form.dataset.scope;await apiRequest('security-add',{...data,kind,scope});closeModal();await loadSecurity();const paging=securityListPages[scope][kind];paging.page=Math.ceil(securityEntries.filter(entry=>entry.kind===kind&&entry.scope===scope).length/paging.size);refreshSecurityLists(kind,true);toast('已添加名单记录');
    }else if(form.id==='profile-form'){
      if(pendingAvatar){const body=formBody();body.set('avatar',pendingAvatar);const avatar=await requestJSON('avatar_upload.php',body);pendingAvatar=null;if(profileDraft.avatar!==profile.avatar)releaseAvatar(profileDraft.avatar);profile.avatar=avatar.url;profileDraft.avatar=avatar.url;}
      const result=await requestJSON('account_save.php',formBody({...data,action:'save_profile'}));serverData.emailPending=result.emailPending;const fresh=await apiRequest('bootstrap');Object.assign(profile,fresh.profile);Object.assign(profileDraft,fresh.profile);render();toast(result.message);
    }else if(form.id==='profile-password-form'){
      const result=await requestJSON('account_save.php',formBody({action:'change_password',verification_code:document.querySelector('#password-verification-code').value,new_password:document.querySelector('#new-password').value,new_password_confirmation:document.querySelector('#confirm-password').value}));form.reset();toast(result.message);
    }else if(form.id==='verify-email-form'){
      const result=await requestJSON('account_save.php',formBody({...data,action:'verify_email_change'}));serverData.emailPending=result.emailPending;const fresh=await apiRequest('bootstrap');Object.assign(profile,fresh.profile);Object.assign(profileDraft,fresh.profile);render();toast(result.message);
    }else if(form.id==='smtp-form'){
      await apiRequest('smtp',data);await apiRequest('settings',{notification_email:data.notification_email});serverData.smtp=(await apiRequest('bootstrap')).smtp;serverData.settings.notification_email=data.notification_email;render();toast('邮件服务已保存');
    }else if(form.id==='login-appearance-form'){
      if(data.login_background_type==='image'&&!serverData.settings.login_background_image){toast('请先上传壁纸或从图库选择。');return;}data.login_background_opacity=data.login_background_opacity!==undefined?Number(data.login_background_opacity):Number(serverData.settings.login_background_opacity??55);if(data.login_background_blur!==undefined)data.login_background_blur=Math.max(0,Math.min(50,Number(data.login_background_blur)||0));await apiRequest('settings',data);Object.assign(serverData.settings,data);render();toast('登录页面设置已保存');
    }else if(form.id==='docs-config-form'){const config={...docsDraft(),...data};Object.assign(docsDraft(),config);await apiRequest('docs',config);Object.assign(serverData.docs,config);clearDocsLocalDraft();state.docsView='preview';docsEditView='preview';render();toast('公开文档已更新');
    }
  },control);
},true);

async function bootWorkbench(){
  main.innerHTML='<div class="panel panel-body" role="status">正在载入工作台…</div>';
  try{
    serverData=await apiRequest('bootstrap');csrf=serverData.csrf;images=serverData.images;categories=serverData.categories;importSettings(serverData);setStatistics(serverData.statistics);
    let savedMode=null;try{savedMode=localStorage.getItem('picapi-production-theme');}catch{}if(['light','dark','auto'].includes(savedMode))preferences.mode=savedMode;
    if(!location.hash)history.replaceState(null,'','#'+context.route);
    initSidebarResize();paintIcons();applyAppearance();navigate();
    setInterval(()=>{if(!document.hidden)scheduleLiveSync(0);},15000);
    scheduleLiveSync(500);
  }catch(error){main.innerHTML='<div class="panel panel-body"><h1>工作台暂时无法载入</h1><p>'+escapeHTML(error.message)+'</p><button class="button" id="retry-workbench">重新载入</button></div>';document.querySelector('#retry-workbench').onclick=()=>location.reload();}
}
bootWorkbench();
