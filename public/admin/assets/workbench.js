'use strict';

let dailyStats = [];
let requestsBeforeWindow=0;
const endpointSeries=[
  {key:'auto',label:'自适应',field:3,path:'/'},
  {key:'pc',label:'PC',field:4,path:'/pc/'},
  {key:'mobile',label:'手机',field:5,path:'/m/'}
];
const number = value => new Intl.NumberFormat('zh-CN').format(value);
function galleryHeading(){return state.category!=='all'?escapeHTML(state.category):state.device==='pc'?'PC 图库':state.device==='mobile'?'手机图库':'全部图片';}
function restoreGalleryURL(query) {
  const params=new URLSearchParams(query);
  state.device=['pc','mobile'].includes(params.get('device'))?params.get('device'):'all';
  state.category=categories.some(c=>c.name===params.get('category'))?params.get('category'):'all';
  state.search=params.get('q')||'';
  state.sort=['name','size'].includes(params.get('sort'))?params.get('sort'):'new';
  state.layout=params.get('view')==='list'?'list':'grid';
  state.pageSize=[12,24,48].includes(Number(params.get('size')))?Number(params.get('size')):12;
  const page=Number(params.get('page'));
  state.galleryPage=Number.isSafeInteger(page)&&page>0?page:1;
  state.selected.clear();
}
function syncGalleryURL() {
  if(state.page!=='gallery')return;
  const params=new URLSearchParams();
  if(state.device!=='all')params.set('device',state.device);
  if(state.category!=='all')params.set('category',state.category);
  if(state.search)params.set('q',state.search);
  if(state.sort!=='new')params.set('sort',state.sort);
  if(state.layout!=='grid')params.set('view',state.layout);
  if(state.galleryPage>1)params.set('page',state.galleryPage);
  if(state.pageSize!==12)params.set('size',state.pageSize);
  const next='#gallery'+(params.size?'?'+params.toString():'');
  if(location.hash!==next)history.replaceState(null,'',location.pathname+location.search+next);
}
function libraryCounts(){return {all:images.length,pc:images.filter(x=>x.device==='pc').length,mobile:images.filter(x=>x.device==='mobile').length};}
function formatLibraryStorage(megabytes){
  const units=['B','KB','MB','GB','TB','PB'];let value=megabytes*1048576,index=0;
  while(value>=1024&&index<units.length-1){value/=1024;index++;}
  return {value:new Intl.NumberFormat('zh-CN',{maximumFractionDigits:index===0?0:1}).format(value),unit:units[index]};
}
function overviewLibrarySummary(){
  return `<section class="overview-library-summary" aria-label="本地图库数量与占用空间">${[['pc','PC 图库','monitor'],['mobile','手机图库','phone']].map(([device,label,symbol])=>{
    const items=images.filter(image=>image.device===device),storage=formatLibraryStorage(items.reduce((sum,image)=>sum+image.size,0));
    return `<a class="overview-library-item" href="#gallery?device=${device}" data-library-summary="${device}" aria-label="${label}：${number(items.length)} 张图片，占用 ${escapeHTML(storage.value)} ${storage.unit}，查看图片"><div class="overview-library-title">${icon(symbol)}<h2>${label}</h2></div><dl><div><dt>图片数量</dt><dd><span data-library-count>${number(items.length)}</span><small>张</small></dd></div><div><dt>占用空间</dt><dd data-library-storage>${escapeHTML(storage.value)}<small>${storage.unit}</small></dd></div></dl></a>`;
  }).join('')}</section>`;
}
function chartMaximum(peak){if(peak<=0)return 4;const power=10**Math.floor(Math.log10(peak));return Math.ceil(peak*1.1/power)*power;}
function axisLabel(value){return value>=1000?number(value/1000)+'k':number(Number(value.toFixed(3)));}
function statsChart(kind, width = 560) {
  if(kind==='endpoints')return endpointRequestsChart(width);
  const data=dailyStats.slice(-state.period), field=kind==='requests'?1:2;
  const max=chartMaximum(Math.max(...data.map(day=>day[field]))), top=24, bottom=202, left=42, right=width-24;
  const slot=(right-left)/data.length, barWidth=Math.min(32,slot*.64);
  const x=i=>kind==='requests'?left+i*(right-left)/(data.length-1):left+slot*(i+.5), y=value=>bottom-value/max*(bottom-top);
  const points=data.map((d,i)=>`${x(i)},${y(d[field])}`).join(' ');
  const ticks=Array.from({length:5},(_,i)=>i*max/4);
  const labelCount=Math.max(2,Math.min(7,Math.floor((right-left)/72)));
  const labelIndices=new Set(Array.from({length:labelCount},(_,i)=>Math.round(i*(data.length-1)/(labelCount-1))));
  const labels=data.map((d,i)=>labelIndices.has(i)?`<text class="chart-date" x="${x(i)}" y="228" text-anchor="middle">${d[0]}</text>`:'').join('');
  const marks=kind==='requests'?`<polygon class="chart-area" points="${left},${bottom} ${points} ${right},${bottom}"/><polyline class="chart-line" points="${points}"/>${data.map((d,i)=>`<circle cx="${x(i)}" cy="${y(d[1])}" r="3" class="chart-point"><title>${d[0]}：${number(d[1])} 次请求</title></circle>`).join('')}`:data.map((d,i)=>`<rect class="traffic-bar" x="${x(i)-barWidth/2}" y="${y(d[2])}" width="${barWidth}" height="${bottom-y(d[2])}" rx="3"><title>${d[0]}：${d[2].toFixed(2)} GB</title></rect>${state.period===7&&width>=450?`<text x="${x(i)}" y="${y(d[2])-8}" text-anchor="middle" class="bar-number">${d[2].toFixed(2)}</text>`:''}`).join('');
  return `<svg viewBox="0 0 ${width} 244" width="${width}" height="244" role="img" aria-label="近 ${state.period} 天${kind==='requests'?'请求次数折线图':'每日流量柱状图'}，精确数据可在每日明细中查看。">${ticks.map(v=>`<path class="chart-grid" d="M${left} ${y(v)}H${right}"/><text x="${left-12}" y="${y(v)+4}" text-anchor="end">${axisLabel(v)}</text>`).join('')}${marks}${labels}</svg>`;
}
function endpointRequestsChart(width) {
  const data=dailyStats.slice(-state.period),left=42,right=width-24,top=24,bottom=202;
  const peak=Math.max(...data.flatMap(d=>endpointSeries.map(series=>d[series.field])));
  const max=chartMaximum(peak);
  const x=i=>left+i*(right-left)/(data.length-1),y=value=>bottom-value/max*(bottom-top);
  const labelCount=Math.max(2,Math.min(7,Math.floor((right-left)/72)));
  const labelIndices=new Set(Array.from({length:labelCount},(_,i)=>Math.round(i*(data.length-1)/(labelCount-1))));
  const grid=Array.from({length:5},(_,i)=>max*i/4).map(value=>`<path class="chart-grid" d="M${left} ${y(value)}H${right}"/><text x="${left-12}" y="${y(value)+4}" text-anchor="end">${axisLabel(value)}</text>`).join('');
  const lines=endpointSeries.map(series=>`<g class="endpoint-series series-${series.key}" data-series="${series.key}"><polyline points="${data.map((d,i)=>`${x(i)},${y(d[series.field])}`).join(' ')}"/>${data.map((d,i)=>`<circle cx="${x(i)}" cy="${y(d[series.field])}" r="3"><title>${d[0]} · ${series.label}：${number(d[series.field])} 次</title></circle>`).join('')}</g>`).join('');
  const labels=data.map((d,i)=>labelIndices.has(i)?`<text class="chart-date" x="${x(i)}" y="228" text-anchor="middle">${d[0]}</text>`:'').join('');
  return `<svg viewBox="0 0 ${width} 244" width="${width}" height="244" role="img" aria-label="近 ${state.period} 天接口请求趋势：自适应、PC、手机。精确数据可在每日明细中查看。"><desc>蓝色实线为 PC，绿色长虚线为自适应，紫色短虚线为手机；纵轴为请求次数。</desc>${grid}${lines}${labels}</svg>`;
}
let statsChartObserver;
let activeStatsInspection=null;
function hideStatsInspection(){activeStatsInspection?.hide();}
function attachStatsInspection(chart) {
  const kind=chart.dataset.statsChart,data=dailyStats.slice(-state.period),svg=chart.querySelector('svg');
  const tooltip=document.querySelector('#chart-tooltip'),width=svg.viewBox.baseVal.width,left=42,right=width-24;
  const names={endpoints:'接口请求趋势',requests:'请求次数',traffic:'每日流量'};
  const x=index=>kind==='traffic'?left+(index+.5)*(right-left)/data.length:left+index*(right-left)/(data.length-1);
  svg.insertAdjacentHTML('beforeend',`<rect class="chart-hit-area" x="0" y="0" width="${width}" height="244" aria-hidden="true"/><line class="chart-hover-guide" y1="24" y2="202" hidden aria-hidden="true"/>`);
  const guide=svg.querySelector('.chart-hover-guide');
  let selected=-1,highlighted=[];
  chart.tabIndex=0;chart.setAttribute('role','group');
  chart.setAttribute('aria-label',names[kind]+'，左右方向键逐日查看，Home 和 End 跳到首尾，Escape 关闭提示');
  function hide() {
    guide.setAttribute('hidden','');highlighted.forEach(mark=>mark.classList.remove('is-inspected'));highlighted=[];
    chart.removeAttribute('aria-describedby');selected=-1;
    if(activeStatsInspection?.chart===chart){tooltip.hidden=true;activeStatsInspection=null;}
  }
  function show(index,clientY) {
    if(activeStatsInspection?.chart!==chart){hideStatsInspection();activeStatsInspection={chart,hide};}
    if(selected!==index){
      selected=index;const day=data[index];
      const row=(label,value,series='')=>`<div class="chart-tooltip-row"><span class="chart-tooltip-key ${series?`series-${series}`:''}">${series?'<svg viewBox="0 0 24 10" aria-hidden="true"><path d="M1 5H23"/></svg>':''}${label}</span><strong>${value}</strong></div>`;
      const rows=kind==='endpoints'?endpointSeries.map(series=>row(series.label,number(day[series.field])+' 次',series.key)).join('')+`<div class="chart-tooltip-total">${row('总请求',number(day[1])+' 次')}</div>`:row(kind==='requests'?'请求次数':'流量',kind==='requests'?number(day[1])+' 次':day[2].toFixed(2)+' GB');
      tooltip.innerHTML=`<div class="chart-tooltip-date">${day[0]}${day===dailyStats.at(-1)?' · 今日':''}</div>${rows}`;
      highlighted.forEach(mark=>mark.classList.remove('is-inspected'));
      highlighted=kind==='endpoints'?Array.from(svg.querySelectorAll('.endpoint-series')).map(series=>series.querySelectorAll('circle')[index]):[svg.querySelectorAll(kind==='requests'?'.chart-point':'.traffic-bar')[index]];
      highlighted.forEach(mark=>mark.classList.add('is-inspected'));
      guide.setAttribute('x1',x(index));guide.setAttribute('x2',x(index));guide.removeAttribute('hidden');
    }
    tooltip.hidden=false;chart.setAttribute('aria-describedby','chart-tooltip');
    const bounds=svg.getBoundingClientRect(),box=tooltip.getBoundingClientRect();
    const anchorX=bounds.left+x(index)/width*bounds.width,anchorY=clientY??Math.max(8,bounds.top+24);
    const tooltipX=anchorX+14+box.width>innerWidth-8?anchorX-box.width-14:anchorX+14;
    const tooltipY=anchorY+14+box.height>innerHeight-8?anchorY-box.height-14:anchorY+14;
    tooltip.style.left=Math.max(8,Math.min(innerWidth-box.width-8,tooltipX))+'px';
    tooltip.style.top=Math.max(8,Math.min(innerHeight-box.height-8,tooltipY))+'px';
  }
  function inspectPointer(event) {
    const bounds=svg.getBoundingClientRect(),position=((event.clientX-bounds.left)/bounds.width*width-left)/(right-left);
    const index=kind==='traffic'?Math.floor(position*data.length):Math.round(position*(data.length-1));
    show(Math.max(0,Math.min(data.length-1,index)),event.clientY);
  }
  chart.onpointermove=event=>{if(event.pointerType!=='touch')inspectPointer(event);};
  chart.onpointerdown=inspectPointer;
  chart.onpointerleave=event=>{if(event.pointerType!=='touch')hide();};
  chart.onpointercancel=hide;
  chart.onfocus=()=>{if(activeStatsInspection?.chart!==chart)show(data.length-1);};
  chart.onblur=hide;
  chart.onkeydown=event=>{
    if(event.key==='Escape'){event.preventDefault();hide();return;}
    const current=selected<0?data.length-1:selected;
    const next={ArrowLeft:current-1,ArrowRight:current+1,Home:0,End:data.length-1}[event.key];
    if(next===undefined)return;
    event.preventDefault();show(Math.max(0,Math.min(data.length-1,next)));
  };
}
document.addEventListener('pointerdown',event=>{if(activeStatsInspection&&!activeStatsInspection.chart.contains(event.target))hideStatsInspection();});
document.addEventListener('keydown',event=>{if(event.key==='Escape')hideStatsInspection();});
window.addEventListener('scroll',hideStatsInspection,true);
window.addEventListener('resize',hideStatsInspection);
window.addEventListener('blur',hideStatsInspection);
function mountStatsCharts() {
  hideStatsInspection();
  statsChartObserver?.disconnect();
  const charts=document.querySelectorAll('[data-stats-chart]');
  if(!charts.length)return;
  const draw=el=>{const width=Math.round(el.getBoundingClientRect().width);if(width<160||el.dataset.chartWidth===String(width))return;if(activeStatsInspection?.chart===el)hideStatsInspection();el.dataset.chartWidth=String(width);el.innerHTML=statsChart(el.dataset.statsChart,width);attachStatsInspection(el);};
  charts.forEach(draw);
  statsChartObserver=new ResizeObserver(entries=>entries.forEach(({target})=>draw(target)));
  charts.forEach(el=>statsChartObserver.observe(el));
}
function overview() {
  const data=dailyStats.slice(-state.period),today=dailyStats.at(-1),yesterday=dailyStats.at(-2);
  const totalRequests=data.reduce((sum,d)=>sum+d[1],0),totalTraffic=data.reduce((sum,d)=>sum+d[2],0);
  const allTimeRequests=requestsBeforeWindow+dailyStats.reduce((sum,d)=>sum+d[1],0);
  return `${head('overview',button('上传图片','goto-upload','upload',true))}
    <div class="overview-summary"><section class="today-stats overview-metrics" aria-label="访问与流量统计">
      <div class="panel today-metric" data-overview-metric="today"><div class="metric-top">今日访问</div><div class="metric-value">${number(today[1])}</div><p>较昨日 <span class="positive">${icon('trend')}${(yesterday[1]?((today[1]/yesterday[1]-1)*100).toFixed(1):'0')}%</span></p></div>
      <div class="panel today-metric" data-overview-metric="yesterday"><div class="metric-top">昨日访问</div><div class="metric-value">${number(yesterday[1])}</div></div>
      <div class="panel today-metric" data-overview-metric="total"><div class="metric-top">总访问</div><div class="metric-value">${number(allTimeRequests)}</div><p>累计至今日</p></div>
      <div class="panel today-metric" data-overview-metric="traffic"><div class="metric-top">今日流量</div><div class="metric-value">${today[2].toFixed(2)}<small>GB</small></div><p>较昨日 <span class="positive">${icon('trend')}${(yesterday[2]?((today[2]/yesterday[2]-1)*100).toFixed(1):'0')}%</span></p></div>
    </section>${overviewLibrarySummary()}</div>
    <section class="panel statistics-panel"><div class="panel-head"><h2>近期统计</h2><div class="segmented" aria-label="统计周期">${[7,14,30].map(d=>`<button class="${state.period===d?'active':''}" data-period="${d}" aria-pressed="${state.period===d}">近 ${d} 天</button>`).join('')}</div></div>
      <div class="endpoint-trend"><div class="stat-chart-head"><h3>接口请求趋势 <small>次</small></h3><div class="endpoint-legend" aria-label="接口曲线图例">${endpointSeries.map(series=>`<span class="series-${series.key}"><svg viewBox="0 0 24 10" aria-hidden="true"><path d="M1 5H23"/><circle cx="12" cy="5" r="2.5"/></svg>${series.label}</span>`).join('')}</div></div><div class="chart" data-stats-chart="endpoints">${statsChart('endpoints')}</div></div>
      <div class="statistics-charts"><div><div class="stat-chart-head"><h3>请求次数</h3><span>累计 <strong>${number(totalRequests)}</strong> 次</span></div><div class="chart" data-stats-chart="requests">${statsChart('requests')}</div></div><div><div class="stat-chart-head"><h3>每日流量 <small>GB</small></h3><span>累计 <strong>${totalTraffic.toFixed(2)}</strong> GB</span></div><div class="chart" data-stats-chart="traffic">${statsChart('traffic')}</div></div></div>
      <details class="daily-details"><summary>每日明细 ${icon('chevron')}</summary><div class="table-wrap"><table><thead><tr><th>日期</th><th>总请求</th>${endpointSeries.map(series=>`<th>${series.label}</th>`).join('')}<th>流量</th></tr></thead><tbody>${[...data].reverse().map(d=>`<tr><td>${d[0]}${d===today?' · 今日':''}</td><td class="mono">${number(d[1])}</td>${endpointSeries.map(series=>`<td class="mono">${number(d[series.field])}</td>`).join('')}<td class="mono">${d[2].toFixed(2)} GB</td></tr>`).join('')}</tbody></table></div></details>
    </section>
    <section class="panel endpoint-summary"><div class="panel-head"><h2>今日接口统计</h2><a class="text-button" href="#logs">访问日志 ${icon('arrow')}</a></div><div class="table-wrap"><table><thead><tr><th>接口</th><th>请求次数</th><th>地址</th></tr></thead><tbody>${endpointSeries.map(series=>`<tr><td><strong>${series.label}图片</strong><code class="muted mono">${series.path}</code></td><td class="mono">${number(today[series.field])}</td><td><button class="icon-button" data-copy="${escapeHTML(serverData.baseURL.replace(/\/$/,'')+series.path)}" aria-label="复制${series.label}图片接口">${icon('copy')}</button></td></tr>`).join('')}</tbody></table></div></section>`;
}

function imageCard(item, selectable = true) {
  return `<article class="image-card ${state.selected.has(item.id)?'selected':''}">${selectable?`<label class="image-select" title="选择 ${escapeHTML(item.original)}"><input type="checkbox" data-select="${item.id}" aria-label="选择 ${escapeHTML(item.original)}" ${state.selected.has(item.id)?'checked':''}></label>`:''}<button class="image-open" data-preview="${item.id}" aria-label="预览 ${escapeHTML(item.original)}"><img src="${escapeHTML(item.src)}" alt="${escapeHTML(item.original)}" width="600" height="400" loading="lazy" data-thumbnail-device="${escapeHTML(item.device)}" data-thumbnail-relative="${escapeHTML(item.relative)}" ${item.width>320||item.height>320||item.bytes>100000?'class="thumbnail-needs-check"':''}><span class="image-badge">${item.device==='pc'?'PC':'手机'}</span></button><div class="image-info"><div class="image-title"><h3 title="${escapeHTML(item.original)}">${escapeHTML(item.original)}</h3><button class="icon-button image-menu-toggle" data-image-menu="${item.id}" aria-label="${escapeHTML(item.original)} 的操作" aria-haspopup="menu" aria-expanded="false" aria-controls="image-menu">${icon('more')}</button></div>${state.layout==='list'?`<div class="image-list-meta"><div class="image-list-details"><span class="image-resolution">${item.width} × ${item.height}</span><span class="image-device">${item.device==='pc'?'PC':'安卓'}</span><span class="image-folder" title="所属文件夹：${escapeHTML(item.category)}">${escapeHTML(item.category)}</span></div><span class="image-file-size">${item.size<.1?'&lt; 0.1':item.size.toFixed(1)} MB</span></div>`:`<div class="image-meta"><span>${item.width} × ${item.height}</span><span>·</span><span>${item.size<.1?'&lt; 0.1':item.size.toFixed(1)} MB</span><span>${escapeHTML(item.category)}</span></div>`}</div></article>`;
}
function gallery() {
  const counts=libraryCounts();
  return `${head('gallery',button('上传图片','goto-upload','upload',true))}
    <section class="panel library-toolbar" aria-label="图库筛选与批量操作">
      <div class="tabs" aria-label="图片设备筛选">${[['all','全部图片'],['pc','PC 图库'],['mobile','手机图库']].map(d=>`<button data-device="${d[0]}" class="${state.device===d[0]?'active':''}" aria-pressed="${state.device===d[0]}">${d[1]}<small>${counts[d[0]]}</small></button>`).join('')}</div>
      <div class="filter-controls"><label class="search">${icon('search')}<span class="visually-hidden">搜索图片</span><input id="gallery-search" placeholder="搜索文件名…" value="${escapeHTML(state.search)}" type="search"></label><select id="gallery-category" aria-label="按分类筛选"><option value="all">全部分类</option>${categories.map(c=>`<option value="${escapeHTML(c.name)}" ${state.category===c.name?'selected':''}>${escapeHTML(c.name)}</option>`).join('')}</select><select id="gallery-sort" aria-label="图片排序"><option value="new" ${state.sort==='new'?'selected':''}>最新上传</option><option value="name" ${state.sort==='name'?'selected':''}>文件名排序</option><option value="size" ${state.sort==='size'?'selected':''}>体积从大到小</option></select><button class="button reset-filter" data-action="gallery-reset">${icon('filterClear')}清除筛选</button><div class="segmented" aria-label="展示方式"><button class="icon-button ${state.layout==='grid'?'active':''}" data-layout="grid" aria-label="网格视图" aria-pressed="${state.layout==='grid'}">${icon('overview')}</button><button class="icon-button ${state.layout==='list'?'active':''}" data-layout="list" aria-label="列表视图" aria-pressed="${state.layout==='list'}">${icon('list')}</button></div></div>
      <div class="library-bulkbar"><label class="select-page"><input id="select-page" type="checkbox">全选本页</label><div id="selectionbar"></div></div>
    </section>
    <h2 class="visually-hidden">图片列表</h2>
    <div id="gallery-viewport" class="gallery-viewport ${state.layout==='list'?'is-list':''}" role="region" aria-label="图片内容，可滚动浏览" tabindex="0"><div id="gallery-grid" class="gallery-grid ${state.layout==='list'?'list-view':''}">${galleryItems()}</div></div>
    <div id="gallery-footer" class="gallery-footer">${pagination()}</div>`;
}
function filteredImages() {
  return images.filter(x=>(state.device==='all'||x.device===state.device)&&(state.category==='all'||x.category===state.category)&&`${x.title} ${x.original}`.toLowerCase().includes(state.search.toLowerCase())).sort((a,b)=>state.sort==='size'?b.size-a.size:state.sort==='name'?a.original.localeCompare(b.original,'zh'):b.id-a.id);
}
function pageCount(){return Math.max(1,Math.ceil(filteredImages().length/state.pageSize));}
function currentImages(){state.galleryPage=Math.min(state.galleryPage,pageCount());const start=(state.galleryPage-1)*state.pageSize;return filteredImages().slice(start,start+state.pageSize);}
function galleryItems() {
  const found=currentImages();
  if(!images.length)return `<div class="empty-state gallery-empty"><span>图库暂无图片</span></div>`;
  return found.length?found.map(x=>imageCard(x)).join(''):`<div class="empty-state">${icon('search')}<h2>没有找到匹配的图片</h2><p>请调整关键词或分类。</p>${button('清除筛选','gallery-reset','filterClear')}</div>`;
}
function pagination() {
  const count=filteredImages().length,pages=pageCount(),page=state.galleryPage;
  const numbers=Array.from({length:pages},(_,i)=>i+1).filter(n=>n===1||n===pages||Math.abs(n-page)<=1);
  let last=0;
  const pageButtons=numbers.map(n=>{const gap=n-last>1?'<span class="pagination-gap" aria-hidden="true">…</span>':'';last=n;return `${gap}<button class="page-number ${n===page?'active':''}" data-library-page="${n}" aria-label="第 ${n} 页" ${n===page?'aria-current="page"':''}>${n}</button>`;}).join('');
  return `<span id="gallery-count" role="status" aria-live="polite">共 ${count} 张${count?' · '+((page-1)*state.pageSize+1)+'–'+Math.min(page*state.pageSize,count):''}</span><div class="pagination-controls"><label class="page-size">每页 <select id="gallery-page-size" aria-label="每页图片数量">${[12,24,48].map(n=>`<option value="${n}" ${state.pageSize===n?'selected':''}>${n} 张</option>`).join('')}</select></label><nav class="pagination" aria-label="图库分页"><button class="page-turn" aria-label="上一页" data-library-page="${page-1}" data-page-direction="previous" ${page===1?'disabled':''}>${icon('chevron')}<span>上一页</span></button>${pageButtons}<button class="page-turn" aria-label="下一页" data-library-page="${page+1}" data-page-direction="next" ${page===pages?'disabled':''}><span>下一页</span>${icon('chevron')}</button></nav></div>`;
}
function bindImageLoadState(root=document){
  const hosts=new Set([...root.querySelectorAll('.image-open img,.category-cover img')].map(img=>img.closest('.image-open,.category-cover')).filter(Boolean));
  hosts.forEach(host=>{
    if(host.dataset.picapiLoadStateBound)return;
    host.dataset.picapiLoadStateBound='1';
    const imgs=[...host.querySelectorAll('img')];
    const settled=new Set();
    host.classList.add('is-loading');host.classList.remove('is-error');
    const update=()=>{if(settled.size!==imgs.length)return;host.classList.remove('is-loading');if(imgs.some(img=>!img.naturalWidth))host.classList.add('is-error');};
    const settle=img=>{if(settled.has(img)||!img.complete)return;const decoded=img.naturalWidth&&typeof img.decode==='function'?img.decode().catch(()=>{}):Promise.resolve();decoded.then(()=>{settled.add(img);update();});};
    imgs.forEach(img=>{if(!img.dataset.picapiLoadBound){img.dataset.picapiLoadBound='1';img.addEventListener('load',()=>settle(img));img.addEventListener('error',()=>settle(img));}settle(img);});
  });
}
function updateSelection() {
  const bar=document.querySelector('#selectionbar');if(!bar)return;
  const page=currentImages(),checked=page.filter(x=>state.selected.has(x.id)).length,box=document.querySelector('#select-page');
  box.checked=page.length>0&&checked===page.length;box.indeterminate=checked>0&&checked<page.length;box.disabled=!page.length;
  document.querySelectorAll('[data-select]').forEach(el=>{el.checked=state.selected.has(Number(el.dataset.select));el.closest('.image-card').classList.toggle('selected',el.checked);});
  const total=filteredImages().length;
  bar.innerHTML=state.selected.size?`<div class="selection-actions"><span>已选 <strong>${state.selected.size}</strong> 张</span>${state.selected.size<total?'<button class="text-button" data-action="select-all-matching">全选筛选结果（'+total+' 张）</button>':''}<button class="text-button" data-action="move-selected">${icon('folder')}移动</button><button class="text-button danger-text" data-action="delete-selected">${icon('trash')}删除</button><button class="text-button muted" data-action="clear-selection">取消选择</button></div>`:`<div class="select-empty"><button class="text-button" data-action="select-all-matching" ${total?'':'disabled'}>全选筛选结果（${total} 张）</button><div><button class="text-button" disabled>${icon('folder')}移动</button><button class="text-button" disabled>${icon('trash')}删除</button></div></div>`;
}
function resetGalleryScroll(){document.querySelector('#gallery-viewport')?.scrollTo({top:0,behavior:'instant'});}
function refreshGallery(resetScroll=false) {
  closeImageMenu(false);
  document.querySelector('#gallery-grid').innerHTML=galleryItems();
  document.querySelector('#gallery-footer').innerHTML=pagination();
  if(resetScroll)resetGalleryScroll();
  updateSelection();
  updateWorkspace();
  const heading=main.querySelector('.page-head h1');if(heading)heading.innerHTML=galleryHeading()+'<span class="heading-count">'+filteredImages().length+'</span>';
  syncGalleryURL();
  bindImageLoadState(document.querySelector('#gallery-grid'));
}
function resetLibraryFilter() {state.galleryPage=1;state.selected.clear();closeImageMenu(false);resetGalleryScroll();}
function openMove(ids) {
  state.actionIds=ids.filter(id=>images.some(x=>x.id===id));if(!state.actionIds.length)return;
  openModal('移动图片',`<form id="move-form" class="modal-body"><p class="modal-message">已选择 <strong>${state.actionIds.length}</strong> 张图片。</p><div class="field"><label for="move-category">目标分类</label><select id="move-category" name="category">${categories.map(c=>`<option>${escapeHTML(c.name)}</option>`).join('')}</select></div></form>`,button('取消','close-modal','')+'<button class="button primary" type="submit" form="move-form">'+icon('folder')+'确认移动</button>');
}
function openDelete(ids) {
  state.actionIds=ids.filter(id=>images.some(x=>x.id===id));if(!state.actionIds.length)return;
  openModal('删除图片',`<div class="modal-body"><p class="modal-message">删除所选 <strong>${state.actionIds.length}</strong> 张图片？</p><p class="modal-message">删除后无法恢复，请确认所选图片。</p></div>`,button('取消','close-modal','')+'<button class="button danger" data-action="confirm-delete">'+icon('trash')+'确认删除</button>');
}
function imageURL(item){return item.url;}
let menuTrigger=null;
function closeImageMenu(restore=true) {
  const menu=document.querySelector('#image-menu');if(!menu)return;
  menu.remove();menuTrigger?.setAttribute('aria-expanded','false');
  if(restore&&menuTrigger?.isConnected)menuTrigger.focus({preventScroll:true});
}
function openImageMenu(id,trigger) {
  const item=images.find(x=>x.id===id);if(!item)return;
  const content=[['preview','预览图片','image'],['copy','复制地址','copy'],['move','移动到分类','folder'],['delete','删除图片','trash']].map(d=>`<button role="menuitem" tabindex="-1" data-image-command="${d[0]}" data-image-id="${id}" class="${d[0]==='delete'?'danger-text':''}">${icon(d[2])}${d[1]}</button>`).join('');
  openActionMenu(trigger,`${item.original} 的操作`,content);
}
function openCategoryMenu(index,trigger) {
  const category=categories[index];if(!category)return;
  const content=`<button role="menuitem" tabindex="-1" data-category-command="edit" data-category-index="${index}">${icon('settings')}编辑名称与别名</button>${category.default?'':`<button role="menuitem" tabindex="-1" class="danger-text" data-category-command="delete" data-category-index="${index}">${icon('trash')}删除分类</button>`}`;
  openActionMenu(trigger,`${category.name} 的分类操作`,content);
}
function openActionMenu(trigger,label,content) {
  const same=menuTrigger===trigger&&document.querySelector('#image-menu');closeImageMenu(false);if(same)return;
  menuTrigger=trigger;trigger.setAttribute('aria-expanded','true');
  const menu=document.createElement('div');menu.id='image-menu';menu.className='image-action-menu';menu.setAttribute('role','menu');menu.setAttribute('aria-label',label);
  menu.innerHTML=content;
  document.body.append(menu);
  const rect=trigger.getBoundingClientRect(),height=menu.offsetHeight,width=menu.offsetWidth;
  menu.style.left=Math.max(8,Math.min(rect.right-width,innerWidth-width-8))+'px';
  menu.style.top=(rect.bottom+height+8<innerHeight?rect.bottom+5:Math.max(8,rect.top-height-5))+'px';
  menu.querySelector('button').focus({preventScroll:true});
}
document.addEventListener('click',e=>{
  const categoryToggle=e.target.closest('[data-category-menu]');if(categoryToggle){openCategoryMenu(Number(categoryToggle.dataset.categoryMenu),categoryToggle);return;}
  const categoryCommand=e.target.closest('[data-category-command]');
  if(categoryCommand){const index=Number(categoryCommand.dataset.categoryIndex),action=categoryCommand.dataset.categoryCommand;closeImageMenu(true);if(action==='edit')categoryModal(index);if(action==='delete')deleteCategoryModal(index);return;}
  const toggle=e.target.closest('[data-image-menu]');if(toggle){openImageMenu(Number(toggle.dataset.imageMenu),toggle);return;}
  const command=e.target.closest('[data-image-command]');
  if(command){const id=Number(command.dataset.imageId),action=command.dataset.imageCommand;closeImageMenu(true);if(action==='preview')preview(id);if(action==='copy'){const item=images.find(x=>x.id===id);if(item)copy(imageURL(item));}if(action==='move')openMove([id]);if(action==='delete')openDelete([id]);return;}
  if(!e.target.closest('#image-menu'))closeImageMenu(false);
  const pageButton=e.target.closest('[data-library-page]');
  if(pageButton&&!pageButton.disabled){state.galleryPage=Math.max(1,Math.min(pageCount(),Number(pageButton.dataset.libraryPage)));refreshGallery(true);document.querySelector(`.page-number[data-library-page="${state.galleryPage}"]`)?.focus({preventScroll:true});}
});
document.addEventListener('change',e=>{
  if(e.target.id==='select-page'){currentImages().forEach(x=>{if(e.target.checked)state.selected.add(x.id);else state.selected.delete(x.id);});updateSelection();}
  if(e.target.id==='gallery-page-size'){state.pageSize=Number(e.target.value);state.galleryPage=1;refreshGallery(true);document.querySelector('#gallery-page-size')?.focus({preventScroll:true});}
});
document.addEventListener('keydown',e=>{
  const menu=document.querySelector('#image-menu');if(!menu)return;
  const nodes=Array.from(menu.querySelectorAll('button')),index=nodes.indexOf(document.activeElement);
  if(e.key==='Escape'||e.key==='Tab'){if(e.key==='Escape')e.preventDefault();closeImageMenu(true);}
  if(['ArrowDown','ArrowUp','Home','End'].includes(e.key)){e.preventDefault();const next=e.key==='Home'?0:e.key==='End'?nodes.length-1:(index+(e.key==='ArrowDown'?1:-1)+nodes.length)%nodes.length;nodes[next].focus();}
});
window.addEventListener('scroll',()=>closeImageMenu(false),true);
window.addEventListener('resize',()=>closeImageMenu(false));
