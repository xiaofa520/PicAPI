'use strict';

// Login audit view populated from authenticated server records.
const loginLogState={search:'',result:'all',startDate:'',endDate:'',page:1,pageSize:10};
const loginLogResults={success:'登录成功',failure:'登录失败',blocked:'已拦截'};
const loginLogDevices=[['Windows 11','Chrome'],['Android','Chrome'],['iOS','Safari'],['macOS','Safari']];
const loginLogClock=new Intl.DateTimeFormat('zh-CN',{timeZone:'Asia/Shanghai',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'});
const loginLogDate=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Shanghai',year:'numeric',month:'2-digit',day:'2-digit'});
const loginLogCalendarDraft={startDate:'',endDate:'',year:0,month:0};
let loginLogRows=[];
let loginLogDates=[];
let loginLogCalendarOpen=false;
function filteredLoginLogs(){
  const query=loginLogState.search.trim().toLowerCase();
  return loginLogRows.filter(row=>{
    const day=loginLogDate.format(row.time);
    return(!loginLogState.startDate||day>=loginLogState.startDate)&&(!loginLogState.endDate||day<=loginLogState.endDate)&&(loginLogState.result==='all'||row.result===loginLogState.result)&&(!query||row.account.toLowerCase().includes(query)||row.ip.includes(query));
  });
}
function loginLogBadge(result){
  return `<span class="login-result ${result}">${icon(result==='success'?'check':result==='failure'?'close':'lock')}${loginLogResults[result]}</span>`;
}
function loginLogTableRows(rows){
  if(!rows.length)return '<tr class="login-log-empty"><td colspan="7"><strong>没有符合条件的登录记录</strong><span>试试其他账号、IP 或筛选条件。</span></td></tr>';
  const start=(loginLogState.page-1)*loginLogState.pageSize;
  return rows.slice(start,start+loginLogState.pageSize).map(row=>`<tr>
    <td class="login-log-time"><time datetime="${new Date(row.time).toISOString()}">${loginLogClock.format(row.time)}</time></td>
    <td class="login-log-account"><span title="${escapeHTML(row.account)}">${escapeHTML(row.account)}</span></td>
    <td class="login-log-ip"><span class="login-log-label" aria-hidden="true">IP</span><span class="mono">${escapeHTML(row.ip)}</span></td>
    <td class="login-log-device"><span class="login-log-label" aria-hidden="true">设备</span><span>${escapeHTML(row.device)}<small>${escapeHTML(row.browser)}</small></span></td>
    <td class="login-log-result">${loginLogBadge(row.result)}</td>
    <td class="login-log-reason">${escapeHTML(row.reason)}</td>
    <td class="login-log-action"><button class="text-button" data-login-log-detail="${row.id}" aria-label="查看 ${escapeHTML(row.account)} ${loginLogClock.format(row.time)} 的登录详情">详情 ${icon('chevron')}</button></td>
  </tr>`).join('');
}
function loginLogPagination(rows){
  const pages=Math.max(1,Math.ceil(rows.length/loginLogState.pageSize)),start=rows.length?(loginLogState.page-1)*loginLogState.pageSize+1:0;
  return `<span class="muted" role="status">共 ${rows.length} 条${rows.length?' · '+start+'–'+Math.min(loginLogState.page*loginLogState.pageSize,rows.length):''} · 最近 5000 条内筛选</span><div class="login-log-paging"><label class="page-size" for="login-log-page-size">每页<select id="login-log-page-size">${[10,20,50].map(n=>`<option value="${n}" ${loginLogState.pageSize===n?'selected':''}>${n} 条</option>`).join('')}</select></label><div class="pagination"><button type="button" data-login-log-page="${loginLogState.page-1}" aria-label="上一页登录日志" ${loginLogState.page===1?'disabled':''}>${icon('chevron')}</button><span aria-label="第 ${loginLogState.page} 页，共 ${pages} 页">${loginLogState.page} / ${pages}</span><button type="button" data-login-log-page="${loginLogState.page+1}" aria-label="下一页登录日志" ${loginLogState.page===pages?'disabled':''}>${icon('chevron')}</button></div></div>`;
}
function loginLogsPage(){
  const rows=filteredLoginLogs();
  loginLogState.page=Math.min(loginLogState.page,Math.max(1,Math.ceil(rows.length/loginLogState.pageSize)));
  const rangeLabel=loginLogState.startDate&&loginLogState.endDate?`${loginLogState.startDate} 至 ${loginLogState.endDate}`:loginLogState.startDate||loginLogState.endDate||'全部日期';
  return `${head('login-logs',button('导出日志','export-login-logs','download',true,rows.length?'':'disabled'))}
    <div class="login-log-toolbar"><label class="search" for="login-log-search">${icon('search')}<input id="login-log-search" type="search" value="${escapeHTML(loginLogState.search)}" placeholder="搜索账号或 IP…" aria-label="搜索登录账号或 IP" maxlength="120"></label>
      <select id="login-log-result" aria-label="登录结果"><option value="all">全部结果</option>${Object.entries(loginLogResults).map(([key,label])=>`<option value="${key}" ${loginLogState.result===key?'selected':''}>${label}</option>`).join('')}</select>
      <div class="login-log-calendar-control"><div class="log-calendar-anchor"><button class="button login-log-calendar-trigger" data-login-log-calendar-open aria-expanded="${loginLogCalendarOpen}">${icon('calendar')}<span>选择日期范围</span></button><div id="login-log-calendar-popover" class="log-calendar-popover login-log-calendar-popover" ${loginLogCalendarOpen?'':'hidden'}>${loginLogCalendarOpen?`<div id="login-log-calendar-content">${renderLoginLogCalendar()}</div><div class="log-calendar-actions"><button type="button" class="text-button" data-action="login-log-calendar-clear" ${loginLogState.startDate||loginLogState.endDate?'':'disabled'}>${icon('close')}清除日期</button><button type="button" class="text-button" data-action="login-log-calendar-close">关闭</button></div>`:''}</div></div><strong class="login-log-date-current">${escapeHTML(rangeLabel)}</strong></div>
      <button class="text-button" data-login-log-reset>${icon('filterClear')}清除筛选</button><button class="icon-button" data-login-log-refresh aria-label="刷新登录日志" title="刷新登录日志">${icon('refresh')}</button>
    </div>
    <div class="login-log-caption"><span>后台登录记录</span><small>北京时间</small></div>
    <div class="login-log-viewport" role="region" aria-label="登录日志，可滚动浏览" tabindex="0"><table class="login-log-table"><caption class="visually-hidden">后台登录时间、账号、来源、设备、结果与原因</caption><thead><tr><th scope="col">登录时间</th><th scope="col">账号</th><th scope="col">来源 IP</th><th scope="col">设备 / 浏览器</th><th scope="col">登录结果</th><th scope="col">原因</th><th scope="col">操作</th></tr></thead><tbody id="login-log-rows">${loginLogTableRows(rows)}</tbody></table></div>
    <div class="login-log-footer" id="login-log-footer">${loginLogPagination(rows)}</div>`;
}
function refreshLoginLogResults(resetPage=true){
  if(state.page!=='login-logs')return;
  const rows=filteredLoginLogs();
  loginLogState.page=resetPage?1:Math.min(Math.max(1,loginLogState.page),Math.max(1,Math.ceil(rows.length/loginLogState.pageSize)));
  document.querySelector('#login-log-rows').innerHTML=loginLogTableRows(rows);
  document.querySelector('#login-log-footer').innerHTML=loginLogPagination(rows);
  document.querySelector('.login-log-viewport').scrollTo({top:0,left:0,behavior:'instant'});
  document.querySelector('[data-action="export-login-logs"]').disabled=!rows.length;
}
function loginCalendarDayKey(date){return date.toISOString().slice(0,10);}
function renderLoginLogCalendar(){
  const first=new Date(Date.UTC(loginLogCalendarDraft.year,loginLogCalendarDraft.month,1));
  const offset=(first.getUTCDay()+6)%7;
  const gridStart=new Date(Date.UTC(loginLogCalendarDraft.year,loginLogCalendarDraft.month,1-offset));
  const monthLabel=new Intl.DateTimeFormat('zh-CN',{year:'numeric',month:'long',timeZone:'UTC'}).format(first);
  const logDays=new Set(loginLogDates);
  const cells=Array.from({length:42},(_,index)=>{
    const date=new Date(gridStart);date.setUTCDate(gridStart.getUTCDate()+index);
    const key=loginCalendarDayKey(date),inMonth=date.getUTCMonth()===loginLogCalendarDraft.month,hasLogs=logDays.has(key);
    const isStart=key===loginLogCalendarDraft.startDate,isEnd=key===loginLogCalendarDraft.endDate;
    const inRange=Boolean(loginLogCalendarDraft.startDate&&loginLogCalendarDraft.endDate&&key>loginLogCalendarDraft.startDate&&key<loginLogCalendarDraft.endDate);
    const classes=['log-calendar-day',hasLogs?'has-logs':'no-logs',inMonth?'':'outside-month',inRange?'in-range':'',isStart?'range-start':'',isEnd?'range-end':''].filter(Boolean).join(' ');
    return `<button type="button" class="${classes}" data-login-log-calendar-date="${key}" aria-pressed="${isStart||isEnd}" aria-label="${key}，${hasLogs?'有日志':'无记录'}"><span>${date.getUTCDate()}</span>${hasLogs?'<i aria-hidden="true"></i>':''}</button>`;
  }).join('');
  const prompt=loginLogCalendarDraft.startDate&&loginLogCalendarDraft.endDate?`${loginLogCalendarDraft.startDate} 至 ${loginLogCalendarDraft.endDate}`:loginLogCalendarDraft.startDate?`开始日期：${loginLogCalendarDraft.startDate}，请选择结束日期`:'先选择开始日期，再选择结束日期';
  return `<div class="log-calendar"><div class="log-calendar-head"><button type="button" class="icon-button" data-login-log-calendar-month="-1" aria-label="上个月">${icon('chevron')}</button><strong>${monthLabel}</strong><button type="button" class="icon-button log-calendar-next" data-login-log-calendar-month="1" aria-label="下个月">${icon('chevron')}</button></div><div class="log-calendar-legend"><span><i class="has-logs"></i>有日志</span><span><i class="no-logs"></i>无记录</span></div><div class="log-calendar-grid" role="grid" aria-label="${monthLabel}">${['一','二','三','四','五','六','日'].map(day=>`<span class="log-calendar-weekday" role="columnheader">${day}</span>`).join('')}${cells}</div><p class="log-calendar-prompt" aria-live="polite">${escapeHTML(prompt)}</p></div>`;
}
function openLoginLogCalendar(){
  loginLogCalendarOpen=!loginLogCalendarOpen;
  if(!loginLogCalendarOpen){render();return;}
  loginLogCalendarDraft.startDate=loginLogState.startDate;loginLogCalendarDraft.endDate=loginLogState.endDate;
  const base=loginLogState.startDate||loginLogState.endDate||loginLogDate.format(new Date()),[year,month]=base.split('-').map(Number);
  loginLogCalendarDraft.year=year;loginLogCalendarDraft.month=month-1;
  render();
  positionLoginLogCalendarPopover();
}
function positionLoginLogCalendarPopover(){
  const trigger=document.querySelector('[data-login-log-calendar-open]'),popover=document.querySelector('#login-log-calendar-popover');
  if(!trigger||!popover)return;
  const rect=trigger.getBoundingClientRect(),width=popover.getBoundingClientRect().width;
  popover.style.left=rect.left+width>window.innerWidth-12?'auto':'0';
  popover.style.right=rect.left+width>window.innerWidth-12?'0':'auto';
}
function shiftLoginLogCalendarMonth(delta){const date=new Date(Date.UTC(loginLogCalendarDraft.year,loginLogCalendarDraft.month+delta,1));loginLogCalendarDraft.year=date.getUTCFullYear();loginLogCalendarDraft.month=date.getUTCMonth();const calendar=document.querySelector('#login-log-calendar-content');if(calendar)calendar.innerHTML=renderLoginLogCalendar();}
function selectLoginLogCalendarDate(date){
  if(!loginLogCalendarDraft.startDate||loginLogCalendarDraft.endDate){loginLogCalendarDraft.startDate=date;loginLogCalendarDraft.endDate='';}
  else if(date<loginLogCalendarDraft.startDate){loginLogCalendarDraft.startDate=date;loginLogCalendarDraft.endDate='';}
  else{
    loginLogCalendarDraft.endDate=date;loginLogState.startDate=loginLogCalendarDraft.startDate;loginLogState.endDate=date;loginLogState.page=1;
    loginLogCalendarOpen=false;render();void perform(async()=>{await loadLogs('login-logs');toast('登录日志日期范围已更新');});return;
  }
  const calendar=document.querySelector('#login-log-calendar-content');if(calendar)calendar.innerHTML=renderLoginLogCalendar();
}
function clearLoginLogDateRange(){
  loginLogState.startDate='';loginLogState.endDate='';loginLogCalendarDraft.startDate='';loginLogCalendarDraft.endDate='';loginLogState.page=1;
  loginLogCalendarOpen=false;render();void perform(async()=>{await loadLogs('login-logs');toast('已显示全部登录日志');});
}
function showLoginLogDetail(id){
  const row=loginLogRows.find(item=>item.id===id);if(!row)return;
  const fields=[['登录时间',loginLogClock.format(row.time)],['登录账号',row.account],['来源 IP',row.ip],['设备',row.device],['浏览器',row.browser],['结果说明',row.reason],['记录来源',row.source]];
  openModal('登录详情',`<div class="modal-body login-log-detail">${loginLogBadge(row.result)}<dl>${fields.map(([label,value])=>`<div><dt>${label}</dt><dd>${escapeHTML(value)}</dd></div>`).join('')}</dl><p class="modal-message">服务器记录，不包含密码。</p></div>`,button('关闭','close-modal',''));
}
document.addEventListener('input',event=>{
  if(event.target.id==='login-log-search'){loginLogState.search=event.target.value;refreshLoginLogResults();}
});
document.addEventListener('change',event=>{
  const field=event.target;
  if(field.id==='login-log-result')loginLogState.result=field.value;
  else if(field.id==='login-log-page-size')loginLogState.pageSize=Number(field.value);
  else return;
  refreshLoginLogResults();
  if(field.id==='login-log-page-size')document.querySelector('#login-log-page-size').focus({preventScroll:true});
});
document.addEventListener('click',event=>{
  const target=event.target.closest('button');if(!target)return;
  if(target.dataset.loginLogCalendarDate){event.preventDefault();event.stopImmediatePropagation();selectLoginLogCalendarDate(target.dataset.loginLogCalendarDate);return;}
  if(target.dataset.loginLogCalendarMonth){event.preventDefault();event.stopImmediatePropagation();shiftLoginLogCalendarMonth(Number(target.dataset.loginLogCalendarMonth));return;}
  if(target.hasAttribute('data-login-log-calendar-open')){event.preventDefault();event.stopImmediatePropagation();openLoginLogCalendar();return;}
  if(target.dataset.action==='login-log-calendar-clear'){event.preventDefault();event.stopImmediatePropagation();clearLoginLogDateRange();return;}
  if(target.dataset.action==='login-log-calendar-close'){event.preventDefault();event.stopImmediatePropagation();loginLogCalendarOpen=false;render();return;}
  if(target.dataset.action==='close-modal'){event.preventDefault();event.stopImmediatePropagation();closeModal();return;}
  if(target.hasAttribute('data-login-log-detail'))showLoginLogDetail(Number(target.dataset.loginLogDetail));
  if(target.hasAttribute('data-login-log-page')){
    loginLogState.page=Number(target.dataset.loginLogPage);const direction=target.getAttribute('aria-label');refreshLoginLogResults(false);
    const next=document.querySelector(`.login-log-footer [aria-label="${direction}"]`);(next.disabled?document.querySelector('.login-log-viewport'):next).focus({preventScroll:true});
  }
  if(target.hasAttribute('data-login-log-reset')){
    loginLogCalendarOpen=false;
    Object.assign(loginLogState,{search:'',result:'all',startDate:'',endDate:'',page:1});
    document.querySelector('#login-log-search').value='';document.querySelector('#login-log-result').value='all';
    refreshLoginLogResults();void perform(()=>loadLogs('login-logs'));
  }
  if(target.hasAttribute('data-login-log-refresh')){void perform(async()=>{await loadLogs('login-logs');toast('登录日志已刷新');},target);}
  if(target.dataset.action==='export-login-logs'){
    const rows=filteredLoginLogs();if(!rows.length)return;
    const csvText=value=>/^[=+\-@\t\r]/.test(String(value))?"'"+value:value;
    downloadCSV('登录日志.csv',[['登录时间（北京时间）','账号','来源 IP','设备','浏览器','登录结果','原因'],...rows.map(row=>[loginLogClock.format(row.time),csvText(row.account),row.ip,row.device,row.browser,loginLogResults[row.result],row.reason])]);
  }
});

document.addEventListener('click',event=>{
  if(!loginLogCalendarOpen||!(event.target instanceof Element)||event.target.closest('.log-calendar-anchor'))return;
  loginLogCalendarOpen=false;
  const popover=document.querySelector('#login-log-calendar-popover');if(popover)popover.hidden=true;
  document.querySelector('[data-login-log-calendar-open]')?.setAttribute('aria-expanded','false');
},true);
document.addEventListener('keydown',event=>{
  if(event.key!=='Escape'||!loginLogCalendarOpen)return;
  loginLogCalendarOpen=false;render();
});
