"use strict";
const apiDrafts={};
let apiBreakpoint=null,docsEditDraft=null,docsEditView='source';
const docsDraftStoragePrefix='picapi-docs-draft-v1:';
let docsDraftSaveTimer=0,docsDraftRecoveryChecked=false;
function apiDraft(channel){if(apiBreakpoint===null)apiBreakpoint=serverData.settings.adaptive_breakpoint;return apiDrafts[channel]??(apiDrafts[channel]={...serverData.api[channel]});}
function docsDraft(){return docsEditDraft??(docsEditDraft={...serverData.docs});}
function docsRequestStats(){return serverData.docsRequestStatistics||{channels:{},total:{}};}
function docsVisitStats(){return serverData.docsVisitStatistics||{today:0,yesterday:0,total:0};}
function docsPaletteConfig(){return Object.fromEntries(['light','dark'].map(mode=>{const palette=preferences.palettes[mode];return[mode,{color:palette.accent,background:palette.background,surface:palette.surface,text:palette.text,muted:palette.muted,line:`color-mix(in srgb,${palette.surface} 82%,${palette.text})`}] }))}
function docsFrameOptions(){const d=docsDraft(),baseURL=serverData.settings.site_url||serverData.baseURL;return{markdown:d.markdown,baseURL:baseURL.replace(/\/?$/,'/'),fontFamily:d.font_family,customCSS:d.custom_css,customJS:d.custom_js,headHTML:d.head_html,footerHTML:d.footer_html,palettes:docsPaletteConfig(),stats:docsRequestStats(),visits:docsVisitStats(),theme:document.documentElement.dataset.theme==='dark'?'dark':'light'};}
function docsDraftStorageKey(){const identity=serverData?.profile?.email||serverData?.profile?.name||'admin';return docsDraftStoragePrefix+encodeURIComponent(String(identity).toLowerCase());}
function clearDocsLocalDraft(){if(docsDraftSaveTimer){clearTimeout(docsDraftSaveTimer);docsDraftSaveTimer=0;}try{localStorage.removeItem(docsDraftStorageKey());}catch{}const status=document.querySelector('.docs-save-state');if(status)status.textContent='已保存';}
function saveDocsLocalDraft(){
  if(!serverData||!docsEditDraft)return;
  const markdown=String(docsEditDraft.markdown||'');if(markdown===String(serverData.docs.markdown||'')){clearDocsLocalDraft();return;}
  try{localStorage.setItem(docsDraftStorageKey(),JSON.stringify({version:1,markdown,updatedAt:Date.now()}));const status=document.querySelector('.docs-save-state');if(status)status.textContent='本地暂存';}catch{const status=document.querySelector('.docs-save-state');if(status)status.textContent='本地暂存失败';}
}
function scheduleDocsLocalDraftSave(){if(docsDraftSaveTimer)clearTimeout(docsDraftSaveTimer);const status=document.querySelector('.docs-save-state');if(status)status.textContent='正在暂存…';docsDraftSaveTimer=setTimeout(()=>{docsDraftSaveTimer=0;saveDocsLocalDraft();},300);}
function readDocsLocalDraft(){try{const saved=JSON.parse(localStorage.getItem(docsDraftStorageKey())||'null');return saved&&saved.version===1&&typeof saved.markdown==='string'?saved:null;}catch{return null;}}
function offerDocsLocalDraftRecovery(){
  if(docsDraftRecoveryChecked||state.page!=='docs'||!serverData)return;docsDraftRecoveryChecked=true;
  const saved=readDocsLocalDraft();if(!saved)return;if(saved.markdown===String(serverData.docs.markdown||'')){clearDocsLocalDraft();return;}
  const time=Number(saved.updatedAt)>0?new Date(saved.updatedAt).toLocaleString('zh-CN'):'未知时间';
  openModal('发现未发布的文档草稿',`<div class="modal-body"><p>浏览器在${escapeHTML(time)}暂存了一份未发布的接口文档，要恢复吗？</p><p class="muted">恢复后会载入本地草稿；选择放弃会删除这份暂存内容。</p></div>`,button('放弃草稿','discard-docs-local-draft','close')+button('恢复草稿','restore-docs-local-draft','refresh',true),'docs-recovery-dialog');
}
window.addEventListener('pagehide',saveDocsLocalDraft);
document.addEventListener('visibilitychange',()=>{if(document.hidden)saveDocsLocalDraft();});
function mountDocsEditorPreview(){const frame=main.querySelector('#docs-editor-preview');if(frame&&window.PicAPIDocsMarkdown)frame.srcdoc=window.PicAPIDocsMarkdown.frame(docsFrameOptions());}
function mountDocsPreview(){if(state.page==='docs')mountDocsEditorPreview();}
function docsSetMarkdown(value){
  const source=document.querySelector('#docs-markdown');docsDraft().markdown=String(value||'');if(source?._docsEditor)source._docsEditor.setValue(docsDraft().markdown);else if(source)source.value=docsDraft().markdown;
  const count=document.querySelector('[data-docs-char-count]');if(count)count.textContent=docsDraft().markdown.length.toLocaleString('zh-CN');
  saveDocsLocalDraft();
  mountDocsEditorPreview();
}
function openDocsPlayerModal(){
  openModal('插入网易云音乐播放器','<div class="docs-player-form"><label for="docs-player-song">歌曲链接或歌曲 ID</label><input id="docs-player-song" type="text" inputmode="url" placeholder="31649312 或 music.163.com/song?id=31649312"><small>将生成网易云音乐外链播放器的 HTML iframe，并插入到当前光标位置。</small></div>',button('取消','close-modal','')+'<button type="button" class="button primary" data-docs-player-add>插入播放器</button>','docs-player-dialog');
}
function insertDocsPlayer(){
  const raw=document.querySelector('#docs-player-song')?.value.trim()||'';const found=raw.match(/(?:id=)?(\d{4,})/);if(!found){toast('请输入有效的网易云音乐歌曲 ID 或链接');return}
  const id=found[1],snippet=`\n<iframe frameborder="no" border="0" marginwidth="0" marginheight="0" width="330" height="86" src="https://music.163.com/outchain/player?type=2&id=${id}&auto=0&height=66"></iframe>\n`;
  closeModal();const source=document.querySelector('#docs-markdown');if(!source)return;if(source._docsEditor){source._docsEditor.replaceSelection(snippet,'end','+input');source._docsEditor.focus();}else{source.setRangeText(snippet,source.selectionStart,source.selectionEnd,'end');source.dispatchEvent(new Event('input',{bubbles:true}));source.focus();}
}

document.addEventListener('input',event=>{
  const el=event.target;if(!el.name)return;
  const form=el.closest('form');
  if(form?.id==='docs-config-form'){docsDraft()[el.name]=el.value;if(el.name==='markdown'){const count=document.querySelector('.docs-char-count,[data-docs-char-count]');if(count)count.textContent=el.value.length.toLocaleString('zh-CN');scheduleDocsLocalDraftSave();mountDocsEditorPreview();}}
});
document.addEventListener('change',event=>{
  const el=event.target;
  if(el.id==='docs-markdown-file'){
    const file=el.files?.[0];el.value='';if(!file)return;
    if(!/\.(?:md|markdown)$/i.test(file.name)||file.size>200000){toast(file.size>200000?'Markdown 文件不能超过 200 KB':'只支持上传 .md 或 .markdown 文档');return}
    file.text().then(docsSetMarkdown).then(()=>toast('Markdown 已载入，原文档已在草稿中替换')).catch(()=>toast('Markdown 文件读取失败'));
  }
});
document.addEventListener('click',event=>{
  if(!event.target.closest('.docs-tools-menu,.docs-tools>summary'))document.querySelectorAll('.docs-tools[open]').forEach(panel=>{panel.open=false;});
  const el=event.target.closest('button,a');if(!el)return;
  if(el.hasAttribute('data-docs-reference-close')){el.closest('.docs-tools').open=false;return;}
  if(el.dataset.docsEditView){docsEditView=el.dataset.docsEditView;state.docsView=docsEditView;render();}
  if(el.dataset.docsFormat)docsWorkspaceInsert(el.dataset.docsFormat);
  if(el.hasAttribute('data-docs-player-add'))insertDocsPlayer();
  if(el.dataset.docsCopy!==undefined)copy(el.dataset.docsCopy);
});
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!modal.open)document.querySelectorAll('.docs-tools[open]').forEach(panel=>{panel.open=false;});});

window.addEventListener('message',async event=>{
  const currentFrames=[...main.querySelectorAll('#docs-editor-preview')];
  if(!currentFrames.some(frame=>frame.contentWindow===event.source)||event.data?.type!=='picapi:docs'||event.data.action!=='refresh')return;
  try{
    const response=await fetch('../docs/?stats=1',{headers:{Accept:'application/json'},cache:'no-store',credentials:'same-origin'});
    const payload=await response.json();if(!response.ok||payload.ok!==true)throw new Error('无法读取统计信息');
    serverData.docsRequestStatistics={channels:payload.channels,total:payload.total};serverData.docsVisitStatistics=payload.docVisits;
    event.source.postMessage({type:'picapi:docs',action:'stats',stats:serverData.docsRequestStatistics,visits:serverData.docsVisitStatistics},'*');
  }catch(error){event.source.postMessage({type:'picapi:docs',action:'error',message:error.message},'*');}
});

// Promote the Demo documentation workspace into production. The Markdown
// remains server-backed here; the editor layout and interactions match Demo.
function docsWorkspaceReference(){
  const css='<style>\n.docs-example { padding: 16px; border: 1px solid var(--doc-line); border-radius: 8px; background: var(--doc-soft); color: var(--doc-ink); }\n</style>';
  const js='<script>\nconst today = PicAPIDocs.getTotalCount("today");\nconst pcToday = PicAPIDocs.getChannelCount("pc", "today");\nconsole.log({ today, pcToday });\n</script>';
  return `<details class="docs-tools"><summary>${icon('sliders')}参考</summary><div class="docs-tools-menu"><div class="docs-tools-menu-head"><strong>参考</strong><button class="icon-button docs-tools-close" type="button" data-docs-reference-close aria-label="关闭参考面板">${icon('close')}</button></div><section class="docs-reference-section"><h3>站点地址</h3><p>自动使用系统设置中的站点地址，含安装子目录。</p><div class="docs-token-list"><div><span>站点地址：</span><code>{{site.url}}</code></div></div></section><section class="docs-reference-section"><h3>统计占位符</h3><p>写入文档后会自动替换成对应请求数。</p><div class="docs-token-list"><div><span>自适应今日请求：</span><code>{{stats.auto.today}}</code></div><div><span>PC 今日请求：</span><code>{{stats.pc.today}}</code></div><div><span>手机今日请求：</span><code>{{stats.mobile.today}}</code></div><div><span>全部接口今日请求：</span><code>{{stats.total.today}}</code></div></div><small>设备：auto、pc、mobile、total；周期：today、yesterday、total。省略周期时默认 total。</small></section><section class="docs-reference-section"><h3>公开文档访问</h3><p>以下数据统计的是公开文档页访问量，与图片接口请求数分开。</p><div class="docs-token-list docs-visit-tokens"><div><span>今日访问量：</span><code>{{stats.docs.today}}</code></div><div><span>昨日访问量：</span><code>{{stats.docs.yesterday}}</code></div><div><span>累计访问量：</span><code>{{stats.docs.total}}</code></div></div></section><section class="docs-reference-section"><h3>内容支持</h3><p>Markdown 标题、强调、链接、图片、列表、引用、表格、分隔线和代码块；也支持 HTML5、CSS <code>&lt;style&gt;</code> 与 JavaScript <code>&lt;script&gt;</code>，在隔离预览中运行。</p></section><section class="docs-reference-section docs-sample-section"><h3>样式与脚本样板</h3><div class="docs-tools-samples"><div><span>CSS 样板</span><button type="button" data-copy="${escapeHTML(css)}">复制</button><pre>${escapeHTML(css)}</pre></div><div><span>JS 样板</span><button type="button" data-copy="${escapeHTML(js)}">复制</button><pre>${escapeHTML(js)}</pre></div></div></section></div></details>`;
}
function docsWorkspaceToolbar(){
  const format=(kind,label,title)=>`<button type="button" class="docs-format-button" data-docs-format="${kind}" title="${title}" aria-label="${title}">${label}</button>`;
  return `<div class="docs-utility-bar"><label class="docs-file-button">${icon('upload')}上传 Markdown<input id="docs-markdown-file" type="file" accept=".md,.markdown,text/markdown" hidden></label><div class="docs-format-tools" role="toolbar" aria-label="Markdown 格式工具">${format('h1','H1','一级标题')}${format('h2','H2','二级标题')}${format('h3','H3','三级标题')}<span class="docs-tool-divider"></span>${format('bold','B','加粗')}${format('italic','I','斜体')}${format('link','链','插入链接')}${format('unordered-list','• 列表','无序列表')}${format('ordered-list','1. 列表','有序列表')}${format('quote','❞ 引用','引用')}${format('inline-code','&lt;/&gt;','行内代码')}${format('code-block','代码块','插入代码块')}${format('table','表格','插入表格')}${format('rule','分隔线','插入分隔线')}</div><button class="docs-format-button docs-player-button" type="button" data-action="insert-doc-player">网易云音乐</button>${docsWorkspaceReference()}<button class="docs-restore-button" type="button" data-action="restore-docs">恢复初始文档</button><button class="docs-download-button" type="button" data-action="export-docs">下载 Markdown</button></div>`;
}
function docsWorkspaceMarkup(){
  const view=state.docsView||'source',d=docsDraft(),formatView=(key,label,glyph)=>`<button type="button" class="docs-mode-button${view===key?' is-active':''}" data-docs-edit-view="${key}" aria-pressed="${view===key}">${icon(glyph)}${label}</button>`;
  const heading=head('docs',view==='source'?button('打开公开页面','open-docs-public','external')+button('保存并发布','save-docs','save',true):button('打开公开页面','open-docs-public','external')+button('编辑源码','docs-view-source','code',true));
  const source=`<form id="docs-config-form" class="docs-writing-stage" autocomplete="off"><div class="docs-source-canvas"><textarea id="docs-markdown" name="markdown" spellcheck="false" aria-label="Markdown 文档源码">${escapeHTML(d.markdown||'')}</textarea></div><footer class="docs-source-footer"><span data-docs-cursor>第 1 行 · 第 1 列</span><span><b class="docs-char-count">${String(d.markdown||'').length.toLocaleString('zh-CN')}</b> 字 · <span class="docs-save-state">${d.markdown===serverData.docs.markdown?'已保存':'未保存'}</span></span></footer></form>`;
  const preview='<section class="docs-reader-stage docs-preview-stage"><iframe id="docs-editor-preview" class="docs-public-frame" title="Markdown 预览" scrolling="yes" sandbox="allow-scripts allow-popups"></iframe></section>';
  return `<div class="docs-room docs-room--${view}">${heading}<nav class="docs-mode-switch" aria-label="文档视图"><div class="docs-mode-tabs">${formatView('source','源码','code')}${formatView('preview','预览','eye')}</div></nav>${view==='source'?docsWorkspaceToolbar():''}${view==='source'?source:preview}</div>`;
}
function docs(){const markup=docsWorkspaceMarkup();queueMicrotask(offerDocsLocalDraftRecovery);return markup;}
mountDocsPreview=function(){
  if(state.page!=='docs')return;
  const frame=main.querySelector('#docs-editor-preview');
  if(frame&&window.PicAPIDocsMarkdown)frame.srcdoc=window.PicAPIDocsMarkdown.frame(docsFrameOptions());
  if(state.docsView!=='preview'&&typeof mountDocsCodeEditor==='function')mountDocsCodeEditor();
};
function docsWorkspaceInsert(kind){
  const source=document.querySelector('#docs-markdown');if(!source)return;
  const editor=source._docsEditor,selection=editor?editor.getSelection():source.value.slice(source.selectionStart,source.selectionEnd);
  const formats={h1:{prefix:'# ',fallback:'一级标题',block:true},h2:{prefix:'## ',fallback:'二级标题',block:true},h3:{prefix:'### ',fallback:'三级标题',block:true},bold:{before:'**',after:'**',fallback:'粗体文字'},italic:{before:'*',after:'*',fallback:'斜体文字'},link:{before:'[',after:'](https://example.com)',fallback:'链接文字'},'unordered-list':{prefix:'- ',fallback:'列表项',block:true},'ordered-list':{prefix:'1. ',fallback:'列表项',block:true},quote:{prefix:'> ',fallback:'引用内容',block:true},'inline-code':{before:'`',after:'`',fallback:'code'},'code-block':{before:'```javascript\n',after:'\n```',fallback:'const count = PicAPIDocs.getTotalCount("today");'},table:{value:'| 列 1 | 列 2 |\n| --- | --- |\n| 内容 | 内容 |'},rule:{value:'\n---\n'}};
  const item=formats[kind];if(!item)return;const text=selection||item.fallback,value=item.value??(item.block?text.split('\n').map(line=>item.prefix+line).join('\n'):item.before+text+item.after);
  if(editor){editor.replaceSelection(value,'end','+input');editor.focus();}else{source.setRangeText(value,source.selectionStart,source.selectionEnd,'end');source.dispatchEvent(new Event('input',{bubbles:true}));source.focus();}
}
function docsWorkspaceDownload(){const blob=new Blob([docsDraft().markdown||''],{type:'text/markdown;charset=utf-8'}),url=URL.createObjectURL(blob),link=document.createElement('a');link.href=url;link.download='picapi-api-docs.md';document.body.append(link);link.click();link.remove();URL.revokeObjectURL(url);}
actions['open-docs-public']=()=>window.open('../docs/','_blank','noopener');
actions['save-docs']=()=>document.querySelector('#docs-config-form')?.requestSubmit();
actions['docs-view-source']=()=>{docsEditView='source';state.docsView='source';render();};
actions['insert-doc-player']=openDocsPlayerModal;
actions['restore-docs']=()=>openModal('恢复初始文档','<div class="modal-body"><p>将使用保留的初始 Markdown 备份替换当前草稿。</p></div>',button('取消','close-modal','')+button('恢复初始文档','restore-docs-confirm','refresh',true));
actions['restore-docs-confirm']=()=>{docsSetMarkdown(serverData.docsDefaultMarkdown||'');closeModal();toast('已恢复初始文档备份');};
actions['restore-docs-local-draft']=()=>{const saved=readDocsLocalDraft();if(!saved){closeModal();toast('本地草稿已不存在');return;}docsEditDraft={...serverData.docs,markdown:saved.markdown};docsEditView='source';state.docsView='source';closeModal();render();toast('已恢复本地暂存的接口文档');};
actions['discard-docs-local-draft']=()=>{clearDocsLocalDraft();closeModal();toast('已放弃本地草稿');};
actions['export-docs']=docsWorkspaceDownload;
