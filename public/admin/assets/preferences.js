'use strict';

// Editable settings drafts; production.js persists changes through authenticated endpoints.
const paletteKeys = ['accent','background','surface','text','muted'];
const paletteLabels = ['强调色','页面背景','内容背景','主文字','辅助文字'];
const appearancePresets = {
  light:[
    ['澄白','#1974d2','#f5f7fb','#ffffff','#2c3b50','#728198'],['天空','#2563eb','#f7faff','#ffffff','#102a43','#52677f'],['翡翠','#059669','#f3fbf7','#ffffff','#12352a','#5d776c'],['石榴','#dc2626','#fff8f7','#ffffff','#411a1a','#7e5c5c'],['橙金','#ea580c','#fffaf5','#ffffff','#3b2616','#806957'],['洋红','#c026d3','#fff8ff','#ffffff','#3a1d3d','#78617a'],
    ['海盐','#0284c7','#f0f9ff','#ffffff','#0c3045','#547386'],['青柠','#65a30d','#f7fbea','#ffffff','#293b16','#71805c'],['珊瑚','#e11d48','#fff5f5','#ffffff','#401d27','#80626a'],['薰衣草','#7c3aed','#f8f5ff','#ffffff','#30224b','#73668c'],['湖绿','#0f766e','#f0fdfa','#ffffff','#153b37','#5c7c77'],['焦糖','#b45309','#fff8ed','#ffffff','#3d2b1c','#78654f'],
    ['樱花','#db2777','#fff5fa','#ffffff','#401d32','#806279'],['冰川','#0891b2','#f0fbff','#ffffff','#143743','#5c7780'],['森林','#15803d','#f3faf4','#ffffff','#1d3927','#647b69'],['皇家蓝','#4f46e5','#f5f6ff','#ffffff','#25264a','#686b8b'],['石墨','#475569','#f5f7fa','#ffffff','#293241','#667085'],['杏桃','#c2410c','#fff7f1','#ffffff','#42291f','#80695d']
  ],
  dark:[
    ['澄白深色','#80bfff','#171e28','#202a37','#e8eef6','#a0aec0'],['靛蓝','#818cf8','#0f172a','#182235','#f8fafc','#a7b4c8'],['青蓝','#22d3ee','#06202a','#0b2a35','#e6fffb','#9ac7d1'],['紫罗兰','#a78bfa','#18112b','#211839','#f7f2ff','#b5a8d0'],['玫红','#f472b6','#2a0c1d','#381126','#fff1f7','#d6a4bb'],['琥珀','#f59e0b','#241707','#32220e','#fff8e8','#d8c098'],
    ['海盐','#38bdf8','#0b1d2a','#122b3a','#e0f2fe','#9ab9cc'],['青柠','#a3e635','#17210d','#243318','#f3fce0','#b2c698'],['珊瑚','#fb7185','#29121a','#3a1b25','#fff1f2','#d5a6ae'],['薰衣草','#c4b5fd','#1d1630','#2a2040','#f5f3ff','#b9aed0'],['湖绿','#2dd4bf','#09201e','#12312d','#e6fffb','#9bc9c2'],['焦糖','#fbbf24','#24190d','#38270f','#fff7ed','#d1bb91'],
    ['樱花','#f472b6','#25111e','#381b2e','#fff1f7','#d6a4bb'],['冰川','#67e8f9','#0b2028','#12313a','#ecfeff','#9dc4cc'],['森林','#4ade80','#102116','#1b3322','#ecfdf3','#a2c2a9'],['皇家蓝','#a5b4fc','#15172c','#222543','#eef2ff','#adb2d2'],['石墨','#cbd5e1','#171c25','#242b36','#f1f5f9','#a7b0bd'],['杏桃','#fb923c','#27170f','#392319','#fff7ed','#d2b29b']
  ]
};
const toPalette = preset => Object.fromEntries(paletteKeys.map((key,i)=>[key,preset[i+1]]));
const preferences = {
  site:{name:'PicAPI',url:'',description:'PicAPI 是自托管随机图片服务，支持按设备与分类提供图片、URL 和 JSON 接口，并提供图库管理、访问统计与访问控制。',seoTitle:'PicAPI 随机图片 API｜自托管图片服务',seoDescription:'自托管随机图片 API，支持 PC 与手机图片、分类调用、图片 URL 和 JSON 响应，并提供图库管理、访问控制及调用统计。',seoKeywords:'随机图片 API,图片接口,图片 API,图片管理,自托管图片服务,图片随机接口,PicAPI'},
  mode:'light',editMode:'light',customAppearance:false,
  palettes:{light:toPalette(appearancePresets.light[0]),dark:toPalette(appearancePresets.dark[0])},
  mailKind:'notification',mailEditor:'visual',subject:'[{{site_name}}] {{title}}'
};
const systemAppearance = window.matchMedia('(prefers-color-scheme: dark)');
const emailPresets = {
  light:{
    clear:{name:'清爽蓝',background:'#f2f5fa',surface:'#ffffff',header:'#1974d2',brand:'#ffffff',text:'#2c3b50',muted:'#728198',codeBackground:'#eaf3ff',codeText:'#155a9f'},
    mint:{name:'薄荷绿',background:'#f2f8f5',surface:'#ffffff',header:'#087f68',brand:'#ffffff',text:'#183d32',muted:'#617b71',codeBackground:'#e4f5ed',codeText:'#086a53'},
    violet:{name:'暮光紫',background:'#f6f3fb',surface:'#ffffff',header:'#7352ae',brand:'#ffffff',text:'#362849',muted:'#796c89',codeBackground:'#efe7fb',codeText:'#634199'},
    ocean:{name:'晴空海盐',background:'#edf7fb',surface:'#ffffff',header:'#087eaa',brand:'#ffffff',text:'#173747',muted:'#627f8e',codeBackground:'#e2f4fb',codeText:'#09658b'},
    rose:{name:'玫瑰粉',background:'#fff3f6',surface:'#ffffff',header:'#c73768',brand:'#ffffff',text:'#432336',muted:'#826574',codeBackground:'#fde4ec',codeText:'#a92755'},
    amber:{name:'暖阳琥珀',background:'#fff8eb',surface:'#ffffff',header:'#ad6412',brand:'#ffffff',text:'#45311d',muted:'#806c54',codeBackground:'#ffefcc',codeText:'#8a4e08'},
    forest:{name:'清新森林',background:'#f2f8f1',surface:'#ffffff',header:'#368046',brand:'#ffffff',text:'#26392a',muted:'#687a6a',codeBackground:'#e5f2e1',codeText:'#2e6d3c'},
    slate:{name:'石墨灰',background:'#f1f4f8',surface:'#ffffff',header:'#475569',brand:'#ffffff',text:'#263241',muted:'#6c7786',codeBackground:'#e8edf3',codeText:'#3c4c61'},
    coral:{name:'珊瑚橘',background:'#fff4ef',surface:'#ffffff',header:'#d4533e',brand:'#ffffff',text:'#432c25',muted:'#80675e',codeBackground:'#ffebe2',codeText:'#b13e2b'},
    lavender:{name:'柔和薰衣草',background:'#f7f4ff',surface:'#ffffff',header:'#6850a4',brand:'#ffffff',text:'#33294b',muted:'#756c89',codeBackground:'#efeaff',codeText:'#57408f'}
  },
  dark:{
    deep:{name:'深邃蓝',background:'#0b1020',surface:'#111827',header:'#312e81',brand:'#e0e7ff',text:'#e6edf7',muted:'#94a3b8',codeBackground:'#1e3a5f',codeText:'#e0f2fe'},
    mint:{name:'薄荷夜',background:'#0b1918',surface:'#122321',header:'#126b5c',brand:'#d9fff3',text:'#e3f2ed',muted:'#9dbab1',codeBackground:'#193b34',codeText:'#a9f4da'},
    violet:{name:'暮光紫',background:'#171125',surface:'#211832',header:'#60418e',brand:'#f1e8ff',text:'#f0eafa',muted:'#b6a9c8',codeBackground:'#34234c',codeText:'#ddc5ff'},
    rose:{name:'玫瑰夜',background:'#211019',surface:'#301724',header:'#9f315c',brand:'#ffe5ef',text:'#f7eaf0',muted:'#c3a1b0',codeBackground:'#492236',codeText:'#ffc0d8'},
    amber:{name:'琥珀夜',background:'#21190c',surface:'#302411',header:'#8d5b0c',brand:'#fff1cc',text:'#f6eddb',muted:'#c5b28d',codeBackground:'#493713',codeText:'#ffdf83'},
    aqua:{name:'青焰',background:'#071b22',surface:'#0e2932',header:'#087f8c',brand:'#d9ffff',text:'#e0f6f7',muted:'#9bbec4',codeBackground:'#17414b',codeText:'#a6f2f3'},
    forest:{name:'森林夜',background:'#101b13',surface:'#19291d',header:'#347442',brand:'#e5ffe8',text:'#e6f3e8',muted:'#a9c0ad',codeBackground:'#24452b',codeText:'#baf2c2'},
    clay:{name:'赤陶夜',background:'#21140f',surface:'#302019',header:'#a64c2d',brand:'#ffeadf',text:'#f6e9e2',muted:'#c3a99b',codeBackground:'#4b2c20',codeText:'#ffc2a2'},
    graphite:{name:'石墨夜',background:'#15191f',surface:'#20262e',header:'#4b5b6e',brand:'#f1f5f9',text:'#e8edf3',muted:'#a6b0bc',codeBackground:'#303a47',codeText:'#d6e3f2'},
    glacier:{name:'冰川夜',background:'#0c1b24',surface:'#142a35',header:'#28768c',brand:'#e2fbff',text:'#e7f4f7',muted:'#9ebdc5',codeBackground:'#21414e',codeText:'#b8eff8'}
  }
};
function defaultMailTemplate(kind){return {...emailPresets.light.clear,brandName:'{{site_name}}',note:kind==='reset'?'验证码将在 15 分钟后失效。如非本人操作，请忽略此邮件。':'此邮件由系统自动发送，请勿直接回复。',width:620,height:360,html:null};}
const mailTemplates = {notification:defaultMailTemplate('notification'),reset:defaultMailTemplate('reset')};
function currentMailTemplate(){return mailTemplates[preferences.mailKind];}
function selectedAppearance(){return preferences.palettes[preferences.editMode];}
function readableOn(hex){const rgb=hex.match(/[0-9a-f]{2}/gi).map(n=>parseInt(n,16)/255).map(n=>n<=.04045?n/12.92:((n+.055)/1.055)**2.4);return rgb[0]*.2126+rgb[1]*.7152+rgb[2]*.0722>.179?'#102030':'#ffffff';}
function applyAppearance(){
  state.theme=preferences.mode==='auto'?(systemAppearance.matches?'dark':'light'):preferences.mode;
  const root=document.documentElement;root.dataset.theme=state.theme;
  const modes={light:['sun','浅色','深色'],dark:['moon','深色','自动（跟随系统）'],auto:['themeAuto','自动（跟随系统）','浅色']};
  const [symbol,label,next]=modes[preferences.mode];
  const toggle=document.querySelector('#theme-toggle');
  toggle.innerHTML=icon(symbol);toggle.dataset.mode=preferences.mode;
  toggle.setAttribute('aria-label',`当前${label}，切换到${next}`);
  toggle.title=`当前：${label} · 点击切换到${next}`;
  if(!preferences.customAppearance)return;
  const p=preferences.palettes[state.theme];root.dataset.customPalette='true';
  const vars={'--accent':p.accent,'--bg':p.background,'--surface':p.surface,'--ink':p.text,'--muted':p.muted,'--accent-hover':`color-mix(in srgb, ${p.accent} 86%, ${p.text})`,'--accent-soft':`color-mix(in srgb, ${p.accent} 12%, ${p.surface})`,'--surface-alt':`color-mix(in srgb, ${p.background} 90%, ${p.text})`,'--line':`color-mix(in srgb, ${p.text} 15%, ${p.surface})`,'--sidebar':`color-mix(in srgb, ${p.background} 95%, ${p.accent})`,'--sidebar-ink':p.muted,'--on-accent':readableOn(p.accent)};
  Object.entries(vars).forEach(([key,value])=>root.style.setProperty(key,value));
}
systemAppearance.addEventListener('change',()=>{if(preferences.mode==='auto'){applyAppearance();preferences.editMode=state.theme;if(state.page==='settings')render();}});
function settingsSaveRow(label,action){return `<div class="settings-inline-save">${button(label,action,'save',true)}</div>`;}
function generalSettingsMarkup(){const s=preferences.site;return `<div class="settings-section"><h2>基本信息</h2><p>设置站点名称、公开地址与介绍。</p><div class="form-grid">${siteIconSettingsMarkup()}<div class="field"><label for="site-name">站点名称</label><input id="site-name" data-site-field="name" value="${escapeHTML(s.name)}" required maxlength="80"></div><div class="field"><label for="site-url">站点地址</label><input id="site-url" data-site-field="url" value="${escapeHTML(s.url)}" type="url" required></div><div class="field full"><label for="site-description">站点描述</label><textarea id="site-description" data-site-field="description" rows="3" maxlength="300">${escapeHTML(s.description)}</textarea></div><div class="field full preference-heading seo-settings-heading"><h3>SEO 配置</h3><small>仅用于公开接口文档页面。</small></div><div class="field full"><label for="site-seo-title">页面标题</label><input id="site-seo-title" data-site-field="seoTitle" value="${escapeHTML(s.seoTitle)}" maxlength="200" placeholder="留空时使用站点名称"></div><div class="field full"><label for="site-seo-description">搜索摘要</label><textarea id="site-seo-description" data-site-field="seoDescription" rows="2" maxlength="500" placeholder="留空时使用站点描述">${escapeHTML(s.seoDescription)}</textarea></div><div class="field full"><label for="site-seo-keywords">关键词</label><input id="site-seo-keywords" data-site-field="seoKeywords" value="${escapeHTML(s.seoKeywords)}" maxlength="500" placeholder="用逗号分隔关键词"></div></div>${settingsSaveRow('保存站点设置','save-settings')}</div>`;}
function appearanceSettings(){
  const p=selectedAppearance(),mode=preferences.editMode;
  return `<div class="settings-section"><h2>外观主题</h2><p>选择显示模式，并分别设置浅色与深色配色。</p><div class="preference-heading"><h3>显示模式</h3></div><div class="appearance-modes">${[['light','浅色','sun'],['dark','深色','moon'],['auto','跟随系统','monitor']].map(([key,label,symbol])=>`<button class="button ${preferences.mode===key?'is-selected':''}" data-appearance-mode="${key}" aria-pressed="${preferences.mode===key}">${icon(symbol)}${label}</button>`).join('')}</div><div class="preference-heading"><h3>主题配色</h3><div class="segmented" role="group" aria-label="编辑主题配色">${[['light','浅色配色'],['dark','深色配色']].map(([key,label])=>`<button type="button" data-palette-mode="${key}" class="${mode===key?'active':''}" aria-pressed="${mode===key}">${label}</button>`).join('')}</div></div><div class="palette-presets">${appearancePresets[mode].map((preset,i)=>`<button data-appearance-preset="${i}" aria-label="应用${preset[0]}配色" aria-pressed="${paletteKeys.every((k,j)=>p[k]===preset[j+1])}"><span class="palette-strip" aria-hidden="true"><i style="background:${preset[2]}"></i><i style="background:${preset[3]}"></i><i style="background:${preset[1]}"></i></span>${preset[0]}</button>`).join('')}</div><div class="preference-heading"><h3>自定义颜色</h3><button class="text-button" data-preference-action="reset-palette">恢复默认配色</button></div><div class="palette-controls">${paletteKeys.map((key,i)=>`<label class="color-control"><input type="color" data-palette-color="${key}" aria-label="${paletteLabels[i]}" value="${p[key]}"><span>${paletteLabels[i]}<code data-color-value="${key}">${p[key].toUpperCase()}</code></span></label>`).join('')}</div><div id="appearance-preview" class="appearance-preview" style="--preview-bg:${p.background};--preview-surface:${p.surface};--preview-ink:${p.text};--preview-muted:${p.muted};--preview-accent:${p.accent};--preview-on:${readableOn(p.accent)}"><div><strong>界面预览</strong><p>图片资源与文件信息</p><small>辅助说明跟随你的配色</small><span>主要操作</span></div></div>${settingsSaveRow('保存外观设置','save-appearance')}</div>`;
}
function generatedMailHTML(t,kind){
  return `<div style="padding:24px 12px;background:${t.background};font-family:Arial,'Microsoft YaHei',sans-serif;color:${t.text}">
  <div style="max-width:${t.width}px;min-height:${t.height}px;margin:auto;background:${t.surface};border-radius:10px;overflow:hidden">
    <div style="padding:24px;background:${t.header};color:${t.brand}"><strong>${escapeHTML(t.brandName)}</strong><h1 style="font-size:22px;margin:16px 0 0">{{title}}</h1></div>
    <div style="padding:24px"><p style="line-height:1.8;margin:0 0 20px">{{message}}</p>
      ${kind==='reset'?`<div style="padding:18px;background:${t.codeBackground};color:${t.codeText};text-align:center;font-size:30px;letter-spacing:8px;font-weight:bold;border-radius:6px">{{code}}</div>`:`<div style="padding:16px;background:${t.codeBackground};color:${t.codeText};line-height:2;border-radius:6px">来源 IP：{{ip}}<br>发生时间：{{time}}</div>`}
      <p style="margin-top:24px;color:${t.muted};font-size:12px;line-height:1.8">{{detail}}</p>
      <p style="color:${t.muted};font-size:12px;line-height:1.8">${escapeHTML(t.note)}</p>
    </div>
  </div>
</div>`;
}
function mailTokens(){return {site_name:preferences.site.name,title:preferences.mailKind==='reset'?'找回密码验证码':'新的管理员登录',message:preferences.mailKind==='reset'?'请使用下方验证码完成密码重置。':'你的管理员账号刚刚登录了 PicAPI。请核对本次登录信息。',ip:'192.0.2.10',time:'2026/10/04 14:30',detail:preferences.mailKind==='reset'?'请勿向他人提供验证码。':'如果这不是你的操作，请及时检查账号安全。',code:'286419'};}
function replaceMailTokens(source,html=false){const tokens=mailTokens();return source.replace(/\{\{(\w+)\}\}/g,(token,key)=>key in tokens?(html?escapeHTML(tokens[key]):tokens[key]):token);}
function mailPreviewDocument(){const t=currentMailTemplate();return `<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:; form-action 'none'; base-uri 'none'"><style>html,body{margin:0;padding:0}*{box-sizing:border-box}img{max-width:100%}body{overflow-wrap:anywhere}</style></head><body>${replaceMailTokens(t.html??generatedMailHTML(t,preferences.mailKind),true)}</body></html>`;}
function mailTemplateSettings(){
  const t=currentMailTemplate(),kind=preferences.mailKind;
  const mode=state.theme==='dark'?'dark':'light',presets=emailPresets[mode];
  const colors=[['background','页面背景'],['surface','内容背景'],['header','标题背景'],['brand','标题文字'],['text','主文字'],['muted','辅助文字'],['codeBackground',kind==='reset'?'验证码背景':'信息背景'],['codeText',kind==='reset'?'验证码文字':'信息文字']];
  return `<div class="settings-section mail-template-section"><h2>邮件模板</h2><p>分别设置提醒与验证码邮件，修改后可直接预览。</p><div class="template-kind-tabs segmented" aria-label="邮件类型">${[['notification','提醒邮件'],['reset','密码重置验证码']].map(([key,label])=>`<button data-mail-kind="${key}" class="${kind===key?'active':''}" aria-pressed="${key===kind}">${label}</button>`).join('')}</div><div class="field mail-subject-field"><label for="mail-subject">邮件主题</label><input id="mail-subject" value="${escapeHTML(preferences.subject)}" maxlength="180"><small>可用变量：{{site_name}}、{{title}}</small></div><div class="template-editor-layout"><div class="template-controls"><div class="segmented template-editor-tabs" aria-label="模板编辑方式">${[['visual','可视化编辑'],['html','HTML 自定义']].map(([key,label])=>`<button data-mail-editor="${key}" class="${preferences.mailEditor===key?'active':''}" aria-pressed="${key===preferences.mailEditor}">${label}</button>`).join('')}</div>${preferences.mailEditor==='html'?mailCodeEditorMarkup(t.html??generatedMailHTML(t,kind)):`<div class="mail-visual-fields"><div class="field"><label for="mail-brand">邮件名称</label><input id="mail-brand" data-mail-field="brandName" value="${escapeHTML(t.brandName)}" maxlength="80"></div><div class="field"><label for="mail-note">补充文字</label><textarea id="mail-note" data-mail-field="note" rows="2" maxlength="160">${escapeHTML(t.note)}</textarea></div><div class="form-grid"><div class="field"><label for="mail-width">容器宽度（px）</label><input id="mail-width" data-mail-field="width" type="number" value="${t.width}" min="320" max="900" step="10"></div><div class="field"><label for="mail-height">最小高度（px）</label><input id="mail-height" data-mail-field="height" type="number" value="${t.height}" min="0" max="1600" step="10"></div></div><div class="preference-heading email-palette-heading"><h3>邮件配色</h3><span class="muted">随后台${mode==='dark'?'深色':'浅色'}模式切换</span></div><div class="email-presets" aria-label="${mode==='dark'?'深色':'浅色'}邮件配色">${Object.entries(presets).map(([key,p])=>`<button data-mail-preset="${mode}:${key}" title="应用${p.name}邮件配色" aria-pressed="${colors.every(([k])=>t[k]===p[k])}"><i style="background:${p.header}" aria-hidden="true"></i>${p.name}</button>`).join('')}</div><div class="palette-controls mail-color-controls">${colors.map(([key,label])=>`<label class="color-control"><input type="color" data-mail-field="${key}" aria-label="邮件${label}" value="${t[key]}"><span>${label}<code>${t[key].toUpperCase()}</code></span></label>`).join('')}</div></div>`}</div><div class="mail-preview-panel"><div class="mail-preview-heading"><h3>实时预览</h3><span>示例邮件</span></div><div class="mail-preview-subject"><span>主题</span><strong id="mail-subject-preview">${escapeHTML(replaceMailTokens(preferences.subject))}</strong></div><iframe id="mail-template-preview" title="邮件模板实时预览" sandbox="" referrerpolicy="no-referrer" srcdoc="${escapeHTML(mailPreviewDocument())}"></iframe></div></div><details class="mail-variable-help"><summary>${icon('chevron')}可用模板变量</summary><p>{{site_name}} 站点名称 · {{title}} 标题 · {{message}} 正文 · {{ip}} 来源 IP · {{time}} 时间 · {{detail}} 详情 · {{code}} 验证码</p></details><div class="template-save-actions"><button class="text-button" data-preference-action="reset-mail">还原当前模板</button>${button('保存邮件模板','save-mail-template','save',true)}</div></div>`;
}
function updateMailPreview(){const frame=document.querySelector('#mail-template-preview');if(frame)frame.srcdoc=mailPreviewDocument();const subject=document.querySelector('#mail-subject-preview');if(subject)subject.textContent=replaceMailTokens(preferences.subject);}
function repaintPreferencePanel(){render();}
document.addEventListener('click',e=>{
  const el=e.target.closest('button');if(!el)return;
  if(el.dataset.appearanceMode){preferences.mode=el.dataset.appearanceMode;applyAppearance();preferences.editMode=state.theme;repaintPreferencePanel();void perform(()=>persistAppearanceSettings('显示模式已自动保存'),el);}
  if(el.dataset.paletteMode){preferences.editMode=el.dataset.paletteMode;repaintPreferencePanel();}
  if(el.dataset.appearancePreset!==undefined){preferences.palettes[preferences.editMode]=toPalette(appearancePresets[preferences.editMode][Number(el.dataset.appearancePreset)]);preferences.customAppearance=true;applyAppearance();repaintPreferencePanel();void perform(()=>persistAppearanceSettings('主题配色已自动保存'),el);}
  if(el.dataset.mailKind){preferences.mailKind=el.dataset.mailKind;repaintPreferencePanel();}
  if(el.dataset.mailEditor){preferences.mailEditor=el.dataset.mailEditor;repaintPreferencePanel();}
  if(el.dataset.mailPreset){const [mode,key]=el.dataset.mailPreset.split(':');const preset=emailPresets[mode]?.[key];if(preset){Object.assign(currentMailTemplate(),preset,{html:null});repaintPreferencePanel();}}
  if(el.dataset.preferenceAction==='reset-palette'){preferences.palettes[preferences.editMode]=toPalette(appearancePresets[preferences.editMode][0]);preferences.customAppearance=true;applyAppearance();repaintPreferencePanel();void perform(()=>persistAppearanceSettings('当前配色已恢复并保存'),el);}
  if(el.dataset.preferenceAction==='reset-mail'){mailTemplates[preferences.mailKind]=defaultMailTemplate(preferences.mailKind);repaintPreferencePanel();toast('当前邮件模板已还原');}
});
document.addEventListener('input',e=>{
  const el=e.target;
  if(el.dataset.siteField){preferences.site[el.dataset.siteField]=el.value;if(el.dataset.siteField==='name')applyWorkspaceSiteName(el.value);}
  if(el.dataset.paletteColor){const p=selectedAppearance();p[el.dataset.paletteColor]=el.value;preferences.customAppearance=true;applyAppearance();document.querySelector(`[data-color-value="${el.dataset.paletteColor}"]`).textContent=el.value.toUpperCase();document.querySelectorAll('[data-appearance-preset]').forEach(b=>b.setAttribute('aria-pressed','false'));const preview=document.querySelector('#appearance-preview');Object.entries({'bg':p.background,'surface':p.surface,'ink':p.text,'muted':p.muted,'accent':p.accent,'on':readableOn(p.accent)}).forEach(([k,v])=>preview.style.setProperty('--preview-'+k,v));}
  if(el.id==='mail-subject'){preferences.subject=el.value;updateMailPreview();}
  if(el.id==='mail-html'){currentMailTemplate().html=el.value;updateMailPreview();}
  if(el.dataset.mailField){if(el.type==='number'&&!el.validity.valid)return;currentMailTemplate()[el.dataset.mailField]=el.type==='number'?Number(el.value):el.value;currentMailTemplate().html=null;if(el.type==='color'){el.nextElementSibling.querySelector('code').textContent=el.value.toUpperCase();document.querySelectorAll('[data-mail-preset]').forEach(b=>b.setAttribute('aria-pressed','false'));}updateMailPreview();}
});
document.addEventListener('change',e=>{const el=e.target;if(el.dataset.paletteColor)void perform(()=>persistAppearanceSettings('自定义配色已自动保存'),el);});
