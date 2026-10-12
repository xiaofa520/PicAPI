'use strict';

const sidebarSizing = {min:72,max:320,iconsBelow:112,compactBelow:184,default:224};
const sidebarWidthKey = 'picapi-sidebar-width';
let desktopSidebarWidth = sidebarSizing.default;

function initSidebarResize() {
  const sidebar=document.querySelector('.sidebar');
  const handle=document.querySelector('#sidebar-resizer');
  const tooltip=document.querySelector('#sidebar-tooltip');
  let drag=null,pendingWidth=null,frame=0,sizingFrame=0,hintTarget=null,hintTitle=null;

  sidebar.querySelectorAll('#navigation a,.settings-link').forEach(link=>{
    const label=link.querySelector('span:not([data-icon])').textContent.trim();
    link.setAttribute('aria-label',label);link.title=label;
  });

  function hideHint() {
    if(hintTarget?.getAttribute('aria-describedby')==='sidebar-tooltip')hintTarget.removeAttribute('aria-describedby');
    if(hintTarget&&hintTitle!==null)hintTarget.setAttribute('title',hintTitle);
    hintTarget=null;hintTitle=null;tooltip.hidden=true;
  }
  function applyWidth(value) {
    // Width changes must follow the handle immediately; keep the separate hide/show motion.
    document.body.classList.add('sidebar-sizing');
    cancelAnimationFrame(sizingFrame);
    sizingFrame=requestAnimationFrame(()=>{
      sizingFrame=requestAnimationFrame(()=>{document.body.classList.remove('sidebar-sizing');sizingFrame=0;});
    });
    desktopSidebarWidth=Math.round(Math.min(sidebarSizing.max,Math.max(sidebarSizing.min,value)));
    document.documentElement.style.setProperty('--desktop-sidebar-width',desktopSidebarWidth+'px');
    document.body.classList.toggle('sidebar-icons',desktopSidebarWidth<sidebarSizing.iconsBelow);
    document.body.classList.toggle('sidebar-narrow',desktopSidebarWidth>=sidebarSizing.iconsBelow&&desktopSidebarWidth<sidebarSizing.compactBelow);
    handle.setAttribute('aria-valuenow',desktopSidebarWidth);
    handle.setAttribute('aria-valuetext',desktopSidebarWidth+' 像素'+(desktopSidebarWidth<sidebarSizing.iconsBelow?'，图标导航':''));
    hideHint();
  }
  function rememberWidth() {
    try{localStorage.setItem(sidebarWidthKey,String(desktopSidebarWidth));}catch{/* Storage may be disabled; resizing still works. */}
  }
  function flushWidth() {
    if(frame)cancelAnimationFrame(frame);frame=0;
    if(pendingWidth!==null){applyWidth(pendingWidth);pendingWidth=null;}
  }
  function finishResize(cancel=false) {
    if(!drag)return;
    const previous=drag;drag=null;
    flushWidth();if(cancel)applyWidth(previous.width);
    document.body.classList.remove('sidebar-resizing');
    if(handle.hasPointerCapture(previous.id))handle.releasePointerCapture(previous.id);
    rememberWidth();
  }
  function showHint(target) {
    if(!target||!sidebar.contains(target)||compactNavigation.matches||drag)return;
    if(target===hintTarget&&!tooltip.hidden)return;
    const clipped=Array.from(target.querySelectorAll('span,strong,small')).some(label=>label.clientWidth>0&&label.scrollWidth>label.clientWidth);
    if(desktopSidebarWidth>=sidebarSizing.iconsBelow&&!clipped)return;
    const text=target.title||target.getAttribute('aria-label');if(!text)return;
    hideHint();hintTarget=target;hintTitle=target.getAttribute('title');target.removeAttribute('title');tooltip.textContent=text;tooltip.hidden=false;
    target.setAttribute('aria-describedby','sidebar-tooltip');
    const bounds=target.getBoundingClientRect(),box=tooltip.getBoundingClientRect();
    tooltip.style.left=(sidebar.getBoundingClientRect().right+10)+'px';
    tooltip.style.top=Math.min(innerHeight-box.height-8,Math.max(8,bounds.top+(bounds.height-box.height)/2))+'px';
  }

  try{
    const saved=localStorage.getItem(sidebarWidthKey);
    if(saved!==null&&Number.isFinite(Number(saved)))desktopSidebarWidth=Number(saved);
  }catch{/* Use the default when storage is unavailable. */}
  applyWidth(desktopSidebarWidth);

  handle.addEventListener('pointerdown',event=>{
    if(event.button!==0||compactNavigation.matches||sidebar.inert)return;
    event.preventDefault();finishResize();hideHint();
    drag={id:event.pointerId,width:desktopSidebarWidth,offset:event.clientX-sidebar.getBoundingClientRect().right};
    handle.focus({preventScroll:true});handle.setPointerCapture(event.pointerId);
    document.body.classList.add('sidebar-resizing');
  });
  handle.addEventListener('pointermove',event=>{
    if(!drag||event.pointerId!==drag.id)return;
    pendingWidth=event.clientX-drag.offset;
    if(!frame)frame=requestAnimationFrame(()=>{frame=0;flushWidth();});
  });
  handle.addEventListener('pointerup',event=>{if(drag?.id===event.pointerId)finishResize();});
  handle.addEventListener('pointercancel',()=>finishResize(true));
  handle.addEventListener('lostpointercapture',()=>finishResize());
  handle.addEventListener('dblclick',()=>{finishResize();applyWidth(sidebarSizing.default);rememberWidth();});
  handle.addEventListener('keydown',event=>{
    if(compactNavigation.matches)return;
    const step=event.shiftKey?32:8;
    const value={ArrowLeft:desktopSidebarWidth-step,ArrowRight:desktopSidebarWidth+step,Home:sidebarSizing.min,End:sidebarSizing.max,Enter:sidebarSizing.default}[event.key];
    if(value===undefined)return;
    event.preventDefault();applyWidth(value);rememberWidth();
  });
  document.addEventListener('keydown',event=>{if(event.key==='Escape'){finishResize(true);hideHint();}});
  document.addEventListener('click',hideHint);
  sidebar.addEventListener('pointerover',event=>showHint(event.target.closest('a,button,.sidebar-storage-summary')));
  sidebar.addEventListener('pointerout',event=>{if(hintTarget&&!hintTarget.contains(event.relatedTarget))hideHint();});
  sidebar.addEventListener('focusin',event=>showHint(event.target.closest('a,button,.sidebar-storage-summary')));
  sidebar.addEventListener('focusout',hideHint);
  sidebar.querySelector('.workspace-scroll').addEventListener('scroll',hideHint);
  window.addEventListener('blur',()=>{finishResize();hideHint();});
  window.addEventListener('resize',hideHint);
  window.addEventListener('hashchange',()=>{finishResize();hideHint();});
  compactNavigation.addEventListener('change',()=>{finishResize();hideHint();});
}
