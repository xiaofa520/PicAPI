"use strict";

const loginSessionSettings = {
  days: 7,
  save: async () => { throw new Error('设置尚未加载，请刷新后重试'); }
};
function loginSessionSettingsMarkup() {
  const days = loginSessionSettings.days;
  const presets = [1, 3, 7, 14, 30];
  const custom = !presets.includes(days);
  return `<section class="settings-section" aria-labelledby="login-session-heading">
    <h2 id="login-session-heading">登录凭证</h2>
    <p>在所选天数内保持登录，每次使用后台会续期。超过该时长未访问，需要重新登录。</p>
    <form id="login-session-form">
      <div class="form-grid">
        <div class="field"><label for="login-session-preset">登录保存时间</label>
          <select id="login-session-preset" aria-controls="login-session-custom-field">
            ${presets.map(value=>`<option value="${value}" ${days===value?'selected':''}>${value} 天${value===7?'（默认）':''}</option>`).join('')}
            <option value="custom" ${custom?'selected':''}>自定义</option>
          </select>
        </div>
        <div id="login-session-custom-field" class="field" ${custom?'':'style="display:none"'}>
          <label for="login-session-custom">自定义天数</label>
          <input id="login-session-custom" type="number" min="1" max="30" step="1" value="${days}" required ${custom?'':'disabled'} aria-describedby="login-session-help">
          <small id="login-session-help">填写 1～30 天</small>
        </div>
      </div>
      <div class="settings-inline-save"><span id="login-session-status" role="status" aria-live="polite">更改后自动保存 · 默认 7 天</span></div>
    </form>
  </section>`;
}
// Serialize writes so a slow response cannot overwrite a newer selection.
let sessionSaveTimer=null,sessionSavePending=null,sessionSaveRunning=false,sessionSaveRevision=0,sessionSaveUncertain=false;
function sessionSaveStatus(form,message){const status=form.querySelector('#login-session-status');if(status)status.textContent=message;}
async function drainSessionSaves(){
  if(sessionSaveRunning)return;
  sessionSaveRunning=true;
  try{
    while(sessionSavePending){
      const job=sessionSavePending;sessionSavePending=null;
      try{
        if(sessionSaveUncertain||job.days!==loginSessionSettings.days){await loginSessionSettings.save(job.days);loginSessionSettings.days=job.days;sessionSaveUncertain=false;}
        if(job.revision===sessionSaveRevision)sessionSaveStatus(job.form,`已自动保存 · ${job.days} 天`);
      }catch(error){
        sessionSaveUncertain=true;
        const message=error instanceof TypeError?'网络连接失败':(error.message||'保存失败');
        if(job.revision===sessionSaveRevision)sessionSaveStatus(job.form,message+'，请重新选择或修改天数重试');
      }
    }
  }finally{sessionSaveRunning=false;}
}
function scheduleSessionSave(form,delay=0){
  clearTimeout(sessionSaveTimer);sessionSavePending=null;
  const revision=++sessionSaveRevision;
  const preset=form.querySelector('#login-session-preset'),input=form.querySelector('#login-session-custom');
  const days=Number(preset.value==='custom'?input.value:preset.value);
  if(!Number.isInteger(days)||days<1||days>30){sessionSaveStatus(form,'请填写 1～30 天的整数，当前修改尚未保存');return;}
  sessionSaveStatus(form,delay?'输入完成后自动保存…':'正在保存…');
  const enqueue=()=>{sessionSavePending={days,form,revision};drainSessionSaves();};
  if(delay)sessionSaveTimer=setTimeout(enqueue,delay);else enqueue();
}
document.addEventListener('change',event=>{
  const form=event.target.closest('#login-session-form');if(!form)return;
  if(event.target.id==='login-session-preset'){
    clearTimeout(sessionSaveTimer);sessionSavePending=null;++sessionSaveRevision;
    const custom=event.target.value==='custom',input=form.querySelector('#login-session-custom');
    form.querySelector('#login-session-custom-field').style.display=custom?'':'none';input.disabled=!custom;
    if(custom){input.value=loginSessionSettings.days;sessionSaveStatus(form,'填写 1～30 天，输入完成后自动保存');input.focus();return;}
  }
  scheduleSessionSave(form);
});
document.addEventListener('input',event=>{
  if(event.target.id==='login-session-custom')scheduleSessionSave(event.target.closest('form'),600);
});
document.addEventListener('submit',event=>{
  if(event.target.id!=='login-session-form')return;
  event.preventDefault();scheduleSessionSave(event.target);
});
