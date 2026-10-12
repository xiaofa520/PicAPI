'use strict';

// Access-policy editor. Server enforcement and persistence live in security_runtime.php.
const securityPolicies={
  api:{enabled:true,stages:[{limit:120,duration:10,unit:'minutes'},{limit:120,duration:30,unit:'minutes'},{limit:100,duration:2,unit:'hours'},{limit:60,duration:1,unit:'days'}]},
  login:{enabled:true,stages:[{limit:5,duration:5,unit:'minutes'},{limit:5,duration:30,unit:'minutes'},{limit:5,duration:2,unit:'hours'},{limit:5,duration:1,unit:'days'}]}
};
const securityDrafts=structuredClone(securityPolicies);
const securityPreviewCount={api:0,login:0};
const securityListPages={
  api:{allow:{page:1,size:10},block:{page:1,size:10}},
  login:{allow:{page:1,size:10},block:{page:1,size:10}}
};
let securityEntries=[];
const securityUnitLabels={minutes:'分钟',hours:'小时',days:'天',forever:'永久'};
const securityUnitMillis={minutes:60000,hours:3600000,days:86400000};
const securityTimeFormatter=new Intl.DateTimeFormat('zh-CN',{timeZone:'Asia/Shanghai',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'});
const securityTime=value=>securityTimeFormatter.format(new Date(value));
const securityScopeLabel=scope=>scope==='api'?'API 访问':'后台登录';
const securityDuration=stage=>stage.unit==='forever'?'永久':`${stage.duration} ${securityUnitLabels[stage.unit]}`;
function securityStageForIncident(scope,incident){return securityPolicies[scope].stages[Math.min(Math.max(incident-1,0),3)];}
function securityUnitOptions(value,permanent=false){return Object.entries(securityUnitLabels).filter(([key])=>permanent||key!=='forever').map(([key,label])=>`<option value="${key}" ${key===value?'selected':''}>${label}</option>`).join('');}

function securityPolicyPanel(scope){
  const policy=securityDrafts[scope],login=scope==='login';
  return `<section class="panel security-policy"><form id="security-policy-form" data-scope="${scope}">
    <div class="security-policy-heading"><div><h2>${login?'连续登录失败保护':'API 请求频率保护'}</h2><p>${login?'按同一来源 IP 累计连续失败次数，成功登录清零。':'按同一来源 IP 统计每分钟请求，适用于自适应、PC 和手机图片接口。'}</p></div><label class="security-enable"><input type="checkbox" class="settings-toggle" role="switch" data-policy-enabled ${policy.enabled?'checked':''}>启用自动保护</label></div>
    <fieldset class="security-stage-fields" ${policy.enabled?'':'disabled'}><legend class="visually-hidden">四级封禁规则</legend>
      <div class="security-stage-labels" aria-hidden="true"><span>执行顺序</span><span>${login?'连续失败次数':'每分钟请求上限'}</span><span>封禁时长</span></div>
      ${policy.stages.map((stage,index)=>`<div class="security-stage-row"><strong><span>${index+1}</span>第 ${index+1} 级</strong><div class="security-limit"><span class="security-mobile-label" aria-hidden="true">${login?'连续失败次数':'每分钟请求上限'}</span><label class="visually-hidden" for="security-limit-${index}">第${index+1}级${login?'连续失败次数':'每分钟请求上限'}</label><input id="security-limit-${index}" type="number" min="1" max="${login?1000:1000000}" step="1" value="${stage.limit}" data-security-stage="${index}" data-security-field="limit" required><span>${login?'次失败':'次 / 分钟'}</span></div><div class="security-duration"><span class="security-mobile-label" aria-hidden="true">封禁时长</span><label class="visually-hidden" for="security-duration-${index}">第${index+1}级封禁时长</label><input id="security-duration-${index}" type="number" min="1" max="10000" step="1" value="${stage.duration}" data-security-stage="${index}" data-security-field="duration" ${stage.unit==='forever'?'disabled':''} required><label class="visually-hidden" for="security-unit-${index}">第${index+1}级封禁单位</label><select id="security-unit-${index}" data-security-stage="${index}" data-security-field="unit">${securityUnitOptions(stage.unit,index===3)}</select></div></div>`).join('')}
    </fieldset>
    <div class="security-policy-footer"><p>首次触发执行第 1 级；解封后重新计数，再次触发逐级递进，第 4 级后沿用第 4 级。${login?'成功登录只清零失败次数，不降低已累计的级别。':'达到上限仍可访问，超过上限时封禁。'}</p><button type="submit" class="button primary">${icon('save')}保存策略</button></div>
    <details class="security-rule-preview"><summary>${icon('chevron')}查看执行顺序</summary><div><p id="security-preview-result" role="status">保存策略后可模拟触发，预览每一级的封禁结果。</p><div class="actions"><button type="button" class="button" data-security-preview="next">模拟下一次触发</button><button type="button" class="text-button" data-security-preview="reset">重新开始</button></div><small>每次模拟按已解封计算；不会加入黑名单。</small></div></details>
  </form></section>`;
}
function securityListPanel(kind,scope){
  const allow=kind==='allow',label=allow?'白名单':'黑名单',rows=securityEntries.filter(entry=>entry.kind===kind&&entry.scope===scope);
  const paging=securityListPages[scope][kind],pages=Math.max(1,Math.ceil(rows.length/paging.size));
  paging.page=Math.max(1,Math.min(paging.page,pages));
  const start=(paging.page-1)*paging.size,visibleRows=rows.slice(start,start+paging.size);
  const footer=`<div class="security-list-footer"><span role="status" aria-live="polite">共 ${rows.length} 条 · ${rows.length?start+1:0}–${Math.min(start+paging.size,rows.length)}</span><label>每页<select data-security-page-size="${kind}" aria-label="${label}每页条数">${[10,20,50].map(size=>`<option value="${size}" ${paging.size===size?'selected':''}>${size} 条</option>`).join('')}</select></label><nav class="security-list-pagination" aria-label="${label}分页"><button class="icon-button" data-security-page="${kind}" data-page-step="-1" aria-label="上一页${label}" title="上一页" ${paging.page===1?'disabled':''}>${icon('chevron')}</button><span>${paging.page} / ${pages}</span><button class="icon-button" data-security-page="${kind}" data-page-step="1" aria-label="下一页${label}" title="下一页" ${paging.page===pages?'disabled':''}>${icon('chevron')}</button></nav></div>`;
  return `<section class="panel security-list-panel" data-security-list="${kind}"><div class="security-list-heading"><div><h2>${label}<small>${rows.length} 条</small></h2><p>${allow?'可信 IP 跳过当前策略的自动封禁。':'被限制的 IP、封禁原因与解封时间。'}</p></div><button class="button" data-security-add="${kind}">${icon('plus')}添加${label}</button></div>
    <div class="security-list-scroll" role="region" aria-label="${label}表格，可滚动浏览" tabindex="0"><table><thead><tr><th scope="col">IP 地址</th><th scope="col">${allow?'备注':'封禁原因'}</th><th scope="col">${allow?'添加时间':'封禁时间'}</th>${allow?'':'<th scope="col">解封时间</th>'}<th scope="col">操作</th></tr></thead><tbody>${visibleRows.map(entry=>`<tr><td class="mono">${escapeHTML(entry.ip)}</td><td class="security-reason">${escapeHTML(entry.reason||'—')}</td><td class="mono">${securityTime(entry.created)}</td>${allow?'':`<td class="mono">${entry.expires===null?'<span class="chip danger">永久</span>':securityTime(entry.expires)}</td>`}<td><button class="text-button" data-security-remove="${escapeHTML(entry.id)}" aria-label="${allow?'移除':'解除限制'} ${escapeHTML(entry.ip)}">${allow?'移除':'解除限制'}</button></td></tr>`).join('')||`<tr class="security-list-empty"><td colspan="${allow?4:5}">暂无${label}记录</td></tr>`}</tbody></table></div>${footer}</section>`;
}
function securityPage(){
  const scope=state.securityTab;
  return `${head('security')}<div class="security-tabs tabs" aria-label="访问策略范围">${[['api','API 访问策略'],['login','后台登录策略']].map(([key,label])=>`<button data-security-tab="${key}" class="${scope===key?'active':''}" aria-pressed="${scope===key}">${label}</button>`).join('')}</div><div class="security-workspace"><div class="security-layout">${securityPolicyPanel(scope)}<div class="security-lists">${securityListPanel('allow',scope)}${securityListPanel('block',scope)}</div></div></div>`;
}
function refreshSecurityLists(onlyKind=null,resetScroll=false){
  for(const kind of onlyKind?[onlyKind]:['allow','block']){
    const panel=document.querySelector(`[data-security-list="${kind}"]`);if(!panel)continue;
    const scroll=panel.querySelector('.security-list-scroll'),top=resetScroll?0:scroll.scrollTop,left=scroll.scrollLeft;
    panel.outerHTML=securityListPanel(kind,state.securityTab);
    document.querySelector(`[data-security-list="${kind}"] .security-list-scroll`).scrollTo({top,left,behavior:'instant'});
  }
}
function securityEntryModal(kind){
  const allow=kind==='allow',label=allow?'白名单':'黑名单';
  openModal('添加'+label,`<form id="security-entry-form" class="modal-body" data-kind="${kind}" data-scope="${state.securityTab}"><p class="modal-message">生效范围：${securityScopeLabel(state.securityTab)}</p><div class="field"><label for="security-entry-ip">IP 地址</label><input id="security-entry-ip" name="ip" placeholder="例如：192.0.2.20" required maxlength="64" autocomplete="off" aria-describedby="security-ip-hint"><small id="security-ip-hint">填写 IPv4 或 IPv6 地址，当前范围的黑白名单不能重复。</small></div><div class="field"><label for="security-entry-reason">${allow?'备注':'封禁原因'}</label><input id="security-entry-reason" name="reason" maxlength="60" ${allow?'':'required'} placeholder="${allow?'例如：固定办公网络':'例如：异常访问'}"></div>${allow?'':`<div class="field"><label for="security-entry-duration">封禁时长</label><div class="security-duration"><input id="security-entry-duration" name="duration" type="number" min="1" max="10000" value="30" required><select id="security-entry-unit" name="unit" aria-label="封禁时长单位">${securityUnitOptions('minutes',true)}</select></div></div>`}</form>`,button('取消','close-modal','')+`<button type="submit" form="security-entry-form" class="button primary">${icon('plus')}添加${label}</button>`);
}
document.addEventListener('click',event=>{
  const target=event.target.closest('button');if(!target)return;
  if(target.dataset.securityPage){
    if(target.disabled)return;
    const kind=target.dataset.securityPage,paging=securityListPages[state.securityTab][kind],step=Number(target.dataset.pageStep);
    paging.page+=step;refreshSecurityLists(kind,true);
    const panel=document.querySelector(`[data-security-list="${kind}"]`),control=panel.querySelector(`[data-page-step="${step}"]`);
    (control.disabled?panel.querySelector('.security-list-scroll'):control).focus({preventScroll:true});return;
  }
  if(target.dataset.securityAdd){securityEntryModal(target.dataset.securityAdd);return;}
  if(target.dataset.securityPreview){
    const scope=state.securityTab,result=document.querySelector('#security-preview-result');
    if(target.dataset.securityPreview==='reset'){securityPreviewCount[scope]=0;result.textContent='已重置，下次模拟从第 1 级开始。';return;}
    if(!securityPolicies[scope].enabled){result.textContent='当前已保存的策略关闭了自动保护，不会自动封禁。';return;}
    const incident=++securityPreviewCount[scope],stage=securityStageForIncident(scope,incident);
    result.textContent=`第 ${incident} 次触发 → 第 ${Math.min(incident,4)} 级：${scope==='api'?`每分钟超过 ${stage.limit} 次请求`:`连续失败 ${stage.limit} 次`}，封禁 ${securityDuration(stage)}。`;
  }
});
document.addEventListener('input',event=>{
  const target=event.target;
  if(target.dataset.securityField&&target.dataset.securityField!=='unit'){
    const form=target.closest('#security-policy-form');securityDrafts[form.dataset.scope].stages[Number(target.dataset.securityStage)][target.dataset.securityField]=target.value===''?'':Number(target.value);
  }
});
document.addEventListener('change',event=>{
  const target=event.target;
  if(target.dataset.securityPageSize){
    const kind=target.dataset.securityPageSize,size=Number(target.value);if(![10,20,50].includes(size))return;
    Object.assign(securityListPages[state.securityTab][kind],{page:1,size});refreshSecurityLists(kind,true);
    document.querySelector(`[data-security-page-size="${kind}"]`).focus({preventScroll:true});return;
  }
  if(target.hasAttribute('data-policy-enabled')){const form=target.closest('form');securityDrafts[form.dataset.scope].enabled=target.checked;form.querySelector('fieldset').disabled=!target.checked;}
  if(target.dataset.securityField==='unit'){
    const form=target.closest('#security-policy-form'),index=Number(target.dataset.securityStage);
    securityDrafts[form.dataset.scope].stages[index].unit=target.value;
    form.querySelector(`#security-duration-${index}`).disabled=target.value==='forever';
  }
  if(target.id==='security-entry-unit')document.querySelector('#security-entry-duration').disabled=target.value==='forever';
});
