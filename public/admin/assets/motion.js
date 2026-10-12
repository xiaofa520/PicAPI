'use strict';

// Animate navigation changes, not input or selection-driven re-renders.
const reducedUIMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const runningUIMotions = new Set();
let previousMotionView = null;

function cancelUIMotions() {
  runningUIMotions.forEach(animation=>animation.cancel());
  runningUIMotions.clear();
}
reducedUIMotion.addEventListener('change',()=>{if(reducedUIMotion.matches)cancelUIMotions();});

// Brief feedback for an explicit refresh; never imply ongoing background work.
document.addEventListener('click',event=>{
  const control=event.target.closest('[data-action="refresh-logs"],[data-login-log-refresh]');
  const glyph=control?.querySelector('svg');
  if(!glyph||control.disabled||reducedUIMotion.matches||!glyph.animate)return;
  glyph.getAnimations().forEach(animation=>animation.cancel());
  const animation=glyph.animate([{transform:'rotate(0deg)'},{transform:'rotate(360deg)'}],
    {duration:420,easing:'cubic-bezier(.22,1,.36,1)'});
  runningUIMotions.add(animation);
  animation.finished.then(()=>runningUIMotions.delete(animation),()=>runningUIMotions.delete(animation));
});

function animateRenderedView() {
  const page=state.page;
  const section={setup:setupState.step,login:loginStep,settings:state.settingsTab,overview:state.period,gallery:state.layout,api:state.apiTab,security:state.securityTab,docs:state.codeLang}[page]??'';
  const previous=previousMotionView;
  previousMotionView={page,section};
  // The previous element may have been replaced even when the view is unchanged.
  cancelUIMotions();
  if(reducedUIMotion.matches||(previous?.page===page&&previous.section===section))return;

  const withinPage=previous?.page===page;
  const selectors={setup:'.setup-card',login:'.login-card',settings:'.settings-layout>.panel',overview:'.statistics-charts',gallery:'#gallery-grid'};
  const selector=(withinPage||page==='setup'||page==='login')?selectors[page]:null;
  const target=(selector&&main.querySelector(selector))||main;
  if(!target.animate)return;
  const stepChange=withinPage&&page==='setup';
  const offset=stepChange?(section>previous.section?12:-12):6;
  const animation=target.animate([
    {opacity:.35,transform:stepChange?`translateX(${offset}px)`:`translateY(${offset}px)`},
    {opacity:1,transform:'translate(0,0)'}
  ],{duration:stepChange?280:220,easing:'cubic-bezier(.22,1,.36,1)'});
  animation.id='picapi-view-change';
  runningUIMotions.add(animation);
  animation.finished.then(()=>runningUIMotions.delete(animation),()=>runningUIMotions.delete(animation));
}
