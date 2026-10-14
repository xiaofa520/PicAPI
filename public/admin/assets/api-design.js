'use strict';

const apiDesignChannels={auto:{name:'自适应',path:'/',icon:'monitor'},pc:{name:'PC 图片',path:'/pc/',icon:'monitor'},mobile:{name:'手机图片',path:'/m/',icon:'phone'}};
const apiProbe={output:'json',width:390,category:'',result:null};

function apiDesignDirty(){
  const channel=state.apiDevice,draft=apiDraft(channel),saved=serverData.api[channel];
  return ['direct','url','json','category','strategy','template'].some(key=>draft[key]!==saved[key])
    || Number(apiBreakpoint)!==Number(serverData.settings.adaptive_breakpoint);
}
function apiDesignAddress(){
  const baseURL=new URL(serverData.baseURL||location.origin,location.href);
  if(baseURL.host.toLowerCase()===location.host.toLowerCase())baseURL.protocol=location.protocol;
  const base=baseURL.href.replace(/\/?$/,'/');
  const url=new URL(apiDesignChannels[state.apiDevice].path.replace(/^\//,''),base);
  if(apiProbe.output!=='image')url.searchParams.set('type',apiProbe.output);
  if(state.apiDevice==='auto')url.searchParams.set('screen_width',String(apiProbe.width));
  if(apiProbe.category)url.searchParams.set('category',apiProbe.category);
  return url.href;
}
function apiDesignOptions(value,emptyLabel,storage=false){
  return `<option value="">${emptyLabel}</option>`+categories.map(category=>{
    const option=storage?category.storageAlias:category.alias;
    return `<option value="${escapeHTML(option)}" ${option===value?'selected':''}>${escapeHTML(category.name)}</option>`;
  }).join('');
}
function apiDesignSwitch(key,title,description){
  const checked=apiDraft(state.apiDevice)[key];
  return `<label class="api-design-switch"><span><strong>${title}</strong><small>${description}</small></span><input class="settings-toggle" role="switch" type="checkbox" name="${key}" aria-label="${title}" ${checked?'checked':''}></label>`;
}
function apiDesignBehavior(){
  const config=apiDraft(state.apiDevice);
  return `<section class="api-design-section"><h3>返回方式</h3>${apiDesignSwitch('direct','直接图片','用于网页背景和壁纸')}${apiDesignSwitch('url','图片地址','返回纯文本 URL')}${apiDesignSwitch('json','JSON 数据','按自定义模板返回图片信息')}</section>
    <section class="api-design-section"><h3>图片来源</h3><div class="api-design-fields"><div class="field"><label for="api-design-category">默认分类</label><select name="category" id="api-design-category">${apiDesignOptions(config.category,'全部分类',true)}</select></div><div class="field"><label for="api-design-strategy">抽图策略</label><select name="strategy" id="api-design-strategy"><option value="random" ${config.strategy==='random'?'selected':''}>随机抽取</option><option value="sequence" ${config.strategy==='sequence'?'selected':''}>顺序轮换</option></select></div></div>${state.apiDevice==='auto'?`<div class="field api-design-breakpoint"><label for="api-design-breakpoint">手机屏幕宽度阈值 <span>px</span></label><input id="api-design-breakpoint" name="breakpoint" type="number" min="1" max="3840" step="1" required value="${escapeHTML(String(apiBreakpoint))}"><small>屏幕宽度不超过该值时，使用手机图库。</small></div>`:''}</section>`;
}
function apiDesignTemplate(){
  const variables={img_url:'图片完整访问地址',width:'图片宽度，单位为像素',height:'图片高度，单位为像素',format:'图片文件格式，例如 jpg、png、webp',device:'本次返回图片所属设备：pc 或 mobile',user_agent:'发起请求的客户端 User-Agent 信息'};
  return `<section class="api-design-section"><div class="api-design-section-title"><h3>JSON 返回模板</h3><button type="button" class="text-button" data-api-design-reset>恢复默认</button></div><div class="html-code-shell api-design-editor"><div class="html-code-toolbar"><span>JSON</span><span>2 空格缩进</span></div><textarea id="api-design-template" name="template" spellcheck="false" aria-label="JSON 返回模板">${escapeHTML(apiDraft(state.apiDevice).template)}</textarea></div><div class="api-design-variables" aria-label="插入模板变量">${Object.entries(variables).map(([key,description])=>`<button type="button" data-api-design-token="${key}" title="${escapeHTML(description)}；点击插入 {{${key}}}" aria-label="${escapeHTML(`插入 {{${key}}}：${description}`)}">{{${key}}}</button>`).join('')}</div><p class="api-design-hint">鼠标悬停变量可查看说明；字符串变量需放在双引号内。</p><p id="api-design-error" class="api-design-error" role="alert"></p></section>`;
}
function apiDesignResult(){
  const result=apiProbe.result;
  if(!result)return `<div class="api-design-empty">${icon('code')}<strong>等待测试</strong><span>选择返回方式，查看接口响应</span></div>`;
  return `<div class="api-design-result-head"><span class="${result.status===200?'ok':'failed'}">${result.status} ${result.status===200?'OK':'ERROR'}</span><span>${escapeHTML(result.mime)}</span>${result.image?'':`<button type="button" class="icon-button" data-api-design-copy-response aria-label="复制响应内容" title="复制响应内容">${icon('copy')}</button>`}</div>${result.image?`<div class="api-design-image"><img src="${escapeHTML(result.image)}" alt="接口返回图片"><span>${escapeHTML(result.name)}</span></div>`:`<pre tabindex="0">${escapeHTML(result.body)}</pre>`}`;
}
function api(){
  const channel=state.apiDevice,info=apiDesignChannels[channel],pane=state.apiTab;
  const tabs=[['behavior','接口行为'],['template','JSON 模板']];
  const editor=`<section class="api-design-config panel"><header class="api-design-panel-head"><div><h2>${info.name}接口</h2><code>${info.path}</code></div><nav class="api-design-tabs" aria-label="配置内容">${tabs.map(([key,label])=>`<button type="button" data-api-design-pane="${key}" aria-pressed="${pane===key}">${label}</button>`).join('')}</nav></header><form id="api-design-form" novalidate>${pane==='template'?apiDesignTemplate():apiDesignBehavior()}</form></section>`;
  return `${head('api',button('接口文档','goto-docs','book'))}<div class="api-design"><div class="api-design-top"><nav class="api-design-channels" aria-label="选择配置接口">${Object.entries(apiDesignChannels).map(([key,item])=>`<button type="button" data-api-design-channel="${key}" aria-pressed="${key===channel}">${icon(item.icon)}<span>${item.name}</span></button>`).join('')}</nav><div class="api-design-save"><span id="api-design-save-status" role="status">${apiDesignDirty()?'未保存':''}</span><button type="button" class="button primary" data-api-design-save>${icon('save')}保存配置</button></div></div><div class="api-design-workspace">${editor}<aside class="api-design-probe panel" aria-label="接口测试"><header class="api-design-panel-head"><div><h2>接口测试</h2></div></header><div class="api-design-probe-body"><div class="api-design-request"><span>GET</span><code id="api-design-url">${escapeHTML(apiDesignAddress())}</code><button type="button" class="icon-button" data-api-design-copy-url aria-label="复制请求地址" title="复制请求地址">${icon('copy')}</button></div><div class="api-design-probe-options"><div class="field"><label for="api-design-output">返回方式</label><select id="api-design-output">${[['json','JSON 数据'],['url','图片地址'],['image','直接图片']].map(([key,label])=>`<option value="${key}" ${apiProbe.output===key?'selected':''}>${label}</option>`).join('')}</select></div>${channel==='auto'?`<div class="field"><label for="api-design-width">屏幕宽度（px）</label><input id="api-design-width" type="number" min="1" max="10000" step="1" value="${escapeHTML(String(apiProbe.width))}" required></div>`:''}<div class="field api-design-probe-category"><label for="api-design-test-category">测试分类</label><select id="api-design-test-category">${apiDesignOptions(apiProbe.category,'跟随接口配置')}</select></div></div><button type="button" class="button api-design-send" data-api-design-test>${icon('bolt')}发送测试请求</button><p class="api-design-hint">测试使用已保存的配置。</p><div id="api-design-result" class="api-design-result" aria-live="polite">${apiDesignResult()}</div></div></aside></div></div>`;
}
function apiDesignStatus(){const status=document.querySelector('#api-design-save-status');if(status)status.textContent=apiDesignDirty()?'未保存':'';}
function apiDesignDefaultTemplate(channel){
  const device=channel==='auto'?'{{device}}':channel;
  return `{\n  "url": "{{img_url}}",\n  "width": {{width}},\n  "height": {{height}},\n  "device": "${device}"\n}`;
}
function apiDesignTemplateValid(template){
  const replacements={img_url:'https://example.test/image.png',width:'1920',height:'1080',format:'png',device:'pc',user_agent:'PicAPI'};
  const sample=template.replace(/{{(img_url|width|height|format|device|user_agent)}}/g,(_,key)=>replacements[key]);
  try{JSON.parse(sample);return true;}catch{return false;}
}
async function saveAPIDesign(){
  const form=document.querySelector('#api-design-form');if(form&&!form.reportValidity())return;
  const channel=state.apiDevice,config={...apiDraft(channel)},breakpoint=Number(apiBreakpoint);
  if(!Number.isInteger(breakpoint)||breakpoint<1||breakpoint>3840){state.apiDevice='auto';state.apiTab='behavior';render();document.querySelector('#api-design-breakpoint').reportValidity();return;}
  if(!config.template.trim()||!apiDesignTemplateValid(config.template)){state.apiTab='template';render();document.querySelector('#api-design-error').textContent='JSON 模板无效，请检查双引号、逗号及变量格式。';return;}
  await apiRequest('api',{channel,config});
  serverData.api[channel]={...config};
  if(breakpoint!==Number(serverData.settings.adaptive_breakpoint)){
    await apiRequest('settings',{adaptive_breakpoint:breakpoint});
    serverData.settings.adaptive_breakpoint=breakpoint;
  }
  apiDesignStatus();document.querySelector('#api-design-save-status').textContent='已保存';toast('接口配置已保存');
}
function clearAPIProbe(){
  if(apiProbe.result?.image)URL.revokeObjectURL(apiProbe.result.image);
  apiProbe.result=null;
  const result=document.querySelector('#api-design-result');if(result)result.innerHTML=apiDesignResult();
  const address=document.querySelector('#api-design-url');if(address)address.textContent=apiDesignAddress();
}
async function testAPIDesign(){
  const width=document.querySelector('#api-design-width');if(width&&!width.reportValidity())return;
  clearAPIProbe();
  const url=apiDesignAddress(),response=await fetch(url,{credentials:'omit',cache:'no-store'});
  const mime=response.headers.get('content-type')||'application/octet-stream';
  let body='',image=null;
  if(mime.startsWith('image/'))image=URL.createObjectURL(await response.blob());
  else{body=await response.text();if(mime.includes('json')){try{body=JSON.stringify(JSON.parse(body),null,2);}catch{}}}
  apiProbe.result={status:response.status,mime,body,image,name:url};
  const result=document.querySelector('#api-design-result');if(result)result.innerHTML=apiDesignResult();
}
async function mountAPIEditor(){
  const field=document.querySelector('#api-design-template');if(!field||field.dataset.mounted)return;
  field.dataset.mounted='true';
  try{
    await loadHTMLEditor();if(!field.isConnected)return;
    let escapeTab=false;
    const editor=CodeMirror.fromTextArea(field,{mode:{name:'javascript',json:true},theme:'picapi',lineNumbers:true,lineWrapping:true,indentUnit:2,tabSize:2,screenReaderLabel:'JSON 返回模板',extraKeys:{Esc:()=>{escapeTab=true;},Tab:cm=>{if(escapeTab){escapeTab=false;leaveMailCodeEditor(cm);return;}cm.replaceSelection('  ','end','+input');},'Shift-Tab':cm=>{if(escapeTab){escapeTab=false;leaveMailCodeEditor(cm,true);return;}cm.indentSelection('subtract');}}});
    field._apiEditor=editor;
    editor.addOverlay({token(stream){if(stream.match(/\{\{[^}]*\}\}/))return 'template-variable';while(stream.next()!=null&&!stream.match(/\{\{/,false)){}return null;}});
    editor.on('change',()=>{editor.save();field.dispatchEvent(new Event('input',{bubbles:true}));});
    editor.refresh();
  }catch{field.dataset.mounted='';}
}
document.addEventListener('input',event=>{
  const el=event.target;
  if(el.closest('#api-design-form')&&el.name){if(el.name==='breakpoint')apiBreakpoint=el.value;else apiDraft(state.apiDevice)[el.name]=el.type==='checkbox'?el.checked:el.value;apiDesignStatus();}
  if(el.id==='api-design-width'){apiProbe.width=el.value;clearAPIProbe();}
});
document.addEventListener('change',event=>{
  const el=event.target;
  if(el.id==='api-design-output')apiProbe.output=el.value;
  else if(el.id==='api-design-test-category')apiProbe.category=el.value;
  else return;
  clearAPIProbe();
});
document.addEventListener('click',event=>{
  const button=event.target.closest('button');if(!button)return;
  if(button.dataset.apiDesignChannel){state.apiDevice=button.dataset.apiDesignChannel;clearAPIProbe();render();}
  if(button.dataset.apiDesignPane){state.apiTab=button.dataset.apiDesignPane;render();}
  if(button.hasAttribute('data-api-design-save'))perform(saveAPIDesign,button);
  if(button.hasAttribute('data-api-design-test'))perform(testAPIDesign,button);
  if(button.hasAttribute('data-api-design-copy-url'))copy(apiDesignAddress());
  if(button.hasAttribute('data-api-design-copy-response')&&apiProbe.result)copy(apiProbe.result.body);
  if(button.hasAttribute('data-api-design-reset')){apiDraft(state.apiDevice).template=apiDesignDefaultTemplate(state.apiDevice);render();}
  if(button.dataset.apiDesignToken){const field=document.querySelector('#api-design-template'),token='{{'+button.dataset.apiDesignToken+'}}';if(field._apiEditor){field._apiEditor.replaceSelection(token);field._apiEditor.focus();}else{field.setRangeText(token,field.selectionStart,field.selectionEnd,'end');field.dispatchEvent(new Event('input',{bubbles:true}));field.focus();}}
});
document.addEventListener('submit',event=>{if(event.target.id==='api-design-form'){event.preventDefault();perform(saveAPIDesign);}});
