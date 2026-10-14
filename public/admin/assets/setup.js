'use strict';
const form=document.querySelector('#setup-form'),sections=[...form.querySelectorAll('section[data-step]')],feedback=document.querySelector('#setup-feedback');
let step=Number(form.dataset.step)||1,busy=false;
const databaseFieldsToTest=['db_type','db_host','db_port','db_name','db_username','db_password'],redisFields=['redis_enabled','redis_host','redis_password'],connectionFields=[...databaseFieldsToTest,...redisFields];
let testedConnection=null,testedAt=0;
const draftKey='picapi-setup-draft-v1';let draftToken=form.elements.setup_draft_token.value;
const draftFields=[...connectionFields,'username','admin_email','password','password_confirmation','smtp_mode','smtp_host','smtp_port','smtp_username','smtp_password','smtp_encryption'];
let draftExpired=false,draftStorageAvailable=true,draftSavedAt=0;
function removeDraft(){try{sessionStorage.removeItem(draftKey);}catch{}}
function expireDraft(){
  if(draftExpired)return;
  draftExpired=true;removeDraft();testedConnection=null;testedAt=0;
  form.reset();
  draftFields.forEach(name=>{const input=form.elements[name];if(!input)return;if(input.type==='checkbox')input.checked=false;else if(input.tagName==='INPUT')input.value='';});
  if(form.elements.setup_code)form.elements.setup_code.value='';
  form.querySelectorAll('input,select,textarea,button').forEach(input=>input.disabled=true);
  document.querySelectorAll('[data-go-step]').forEach(input=>input.disabled=true);
  const restart=document.querySelector('#setup-restart');restart.hidden=false;restart.disabled=false;
  document.querySelector('.setup-draft-notice').classList.add('expired');
  document.querySelector('#setup-draft-time').textContent='初始化会话已失效';
  feedback.textContent='初始化会话已失效，请重新开始。';feedback.classList.add('error');
}
function draftCurrent(){return !draftExpired;}
function formatDraftTime(value){if(!value)return '尚未保存';try{return new Intl.DateTimeFormat('zh-CN',{dateStyle:'short',timeStyle:'medium'}).format(new Date(value));}catch{return new Date(value).toLocaleString();}}
function draftTick(){if(!draftCurrent())return;document.querySelector('#setup-draft-time').textContent=(draftStorageAvailable?'草稿暂存于当前浏览器':'浏览器暂存不可用，请勿刷新')+' · 最近保存 '+formatDraftTime(draftSavedAt);}
function saveDraft(){
  if(!draftCurrent())return;
  const values={};draftFields.forEach(name=>{const input=form.elements[name];if(input)values[name]=input.type==='checkbox'?input.checked:input.value;});
  draftSavedAt=Date.now();
  try{sessionStorage.setItem(draftKey,JSON.stringify({token:draftToken,savedAt:draftSavedAt,step,values,testedConnection,testedAt}));}catch{draftStorageAvailable=false;}
  draftTick();
}
function refreshSetupBindings(data){
  let changed=false;
  if(typeof data.csrf==='string'&&data.csrf){form.elements.csrf.value=data.csrf;changed=true;}
  if(typeof data.setupDraftToken==='string'&&data.setupDraftToken){draftToken=data.setupDraftToken;form.elements.setup_draft_token.value=draftToken;changed=true;}
  if(changed)saveDraft();
}
function restoreDraft(){
  try{
    const saved=JSON.parse(sessionStorage.getItem(draftKey)||'null');
    if(!saved)return;
    if(saved.token!==draftToken){removeDraft();return;}
    draftFields.forEach(name=>{const input=form.elements[name],value=saved.values?.[name];if(!input||value===undefined)return;if(input.type==='checkbox')input.checked=value===true;else input.value=String(value);});
    draftSavedAt=Number(saved.savedAt)||0;
    step=[1,2,3].includes(saved.step)?saved.step:1;
    testedConnection=typeof saved.testedConnection==='string'?saved.testedConnection:null;testedAt=Number(saved.testedAt)||0;
  }catch{removeDraft();draftStorageAvailable=false;}
}
document.querySelector('#setup-restart').onclick=()=>location.replace(location.pathname);
document.addEventListener('click',event=>{
  const button=event.target.closest('[data-password-toggle]');
  if(!button)return;
  const input=form.elements[button.dataset.passwordToggle];
  if(!input)return;
  const visible=input.type==='password';
  input.type=visible?'text':'password';
  button.setAttribute('aria-pressed',String(visible));
  button.setAttribute('aria-label',(visible?'隐藏':'显示')+(input.name==='password'?'登录密码':'确认密码'));
  button.innerHTML=visible
    ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a16 16 0 0 1-3 3.9M6.2 6.2C3.5 8 2 12 2 12s3.5 7 10 7c1.2 0 2.3-.2 3.3-.6"/></svg>'
    : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
});
function updatePasswordConfirmation(){
  const password=form.elements.password,confirmation=form.elements.password_confirmation,error=document.querySelector('#setup-password-confirmation-error');
  if(!password||!confirmation||!error)return;
  const mismatch=confirmation.value.length>0&&password.value!==confirmation.value;
  confirmation.setCustomValidity(mismatch?'两次输入的密码不一致。':'');
  error.hidden=!mismatch;
}
form.addEventListener('input',event=>{if(['password','password_confirmation'].includes(event.target.name))updatePasswordConfirmation();});
form.addEventListener('change',event=>{if(['password','password_confirmation'].includes(event.target.name))updatePasswordConfirmation();});
function markSetupCodeVerified(){
  const code=form.elements.setup_code,status=document.querySelector('#setup-code-status');
  form.dataset.codeVerified='1';clearTimeout(setupCodeTimer);setupCodeController?.abort();
  if(code){code.required=false;code.readOnly=true;code.placeholder='已验证';}
  if(status){status.dataset.state='verified';status.textContent='此浏览器已验证，完成初始化后会自动移除验证码文件。';}
}
const setupCodeInput=form.elements.setup_code,setupCodeStatus=document.querySelector('#setup-code-status');
let setupCodeTimer=0,setupCodeRequest=0,setupCodeController=null;
async function verifySetupCode(){
  const code=setupCodeInput?.value.trim()||'';
  if(!code||form.dataset.codeVerified==='1')return;
  const requestId=++setupCodeRequest;
  setupCodeController?.abort();setupCodeController=new AbortController();
  setupCodeStatus.dataset.state='pending';setupCodeStatus.textContent='正在校验验证码…';
  const body=new FormData();body.set('csrf',form.elements.csrf.value);body.set('setup_draft_token',draftToken);body.set('setup_code',code);
  try{
    const response=await fetch('setup_verify_code.php',{method:'POST',body,headers:{Accept:'application/json'},credentials:'same-origin',signal:setupCodeController.signal});
    const data=await response.json();if(requestId!==setupCodeRequest)return;refreshSetupBindings(data);
    if(response.status===419){expireDraft();return;}
    if(data.setupCodeVerified){form.dataset.codeVerified='1';markSetupCodeVerified();return;}
    setupCodeStatus.dataset.state='error';setupCodeStatus.textContent=data.message||'验证码错误，请检查后重试。';
  }catch(error){
    if(error.name==='AbortError'||requestId!==setupCodeRequest)return;
    setupCodeStatus.dataset.state='error';setupCodeStatus.textContent='验证码校验失败，请检查网络后重试。';
  }
}
function scheduleSetupCodeVerification(){
  if(!setupCodeInput||!setupCodeStatus||form.dataset.codeVerified==='1')return;
  clearTimeout(setupCodeTimer);setupCodeController?.abort();
  const code=setupCodeInput.value.trim();
  if(!code){setupCodeStatus.dataset.state='idle';setupCodeStatus.textContent='打开项目根目录的 setup.txt，粘贴后自动校验；完成初始化后会自动移除验证码文件。';return;}
  if(code.length<32){setupCodeStatus.dataset.state='pending';setupCodeStatus.textContent='验证码输入中…';return;}
  setupCodeTimer=setTimeout(verifySetupCode,250);
}
setupCodeInput?.addEventListener('input',scheduleSetupCodeVerification);
if(form.dataset.codeVerified==='1')markSetupCodeVerified();
function connectionSnapshot(){const fields=[...databaseFieldsToTest,'redis_enabled'];if(form.elements.redis_enabled.checked)fields.push('redis_host','redis_password');return JSON.stringify(fields.map(name=>{const input=form.elements[name];return input.type==='checkbox'?input.checked:input.value;}));}
function needsConnectionTest(){return true;}
function connectionTestCurrent(){return testedConnection===connectionSnapshot()&&Date.now()-testedAt<300000;}
function invalidateConnection(event){const name=event.target.name;if(!databaseFieldsToTest.includes(name)&&name!=='redis_enabled'&&!(form.elements.redis_enabled.checked&&['redis_host','redis_password'].includes(name)))return;testedConnection=null;testedAt=0;feedback.classList.remove('error');feedback.textContent=needsConnectionTest()?'连接配置已修改，请重新测试连接。':'';}
form.addEventListener('input',invalidateConnection);form.addEventListener('change',invalidateConnection);
function databaseFields(){const local=form.elements.db_type.value==='sqlite';document.querySelector('#database-fields').hidden=local;document.querySelector('#sqlite-hint').hidden=!local;document.querySelector('#redis-fields').hidden=!form.elements.redis_enabled.checked;}
function mailFields(){
  const skip=form.elements.smtp_mode.value==='skip';
  const fields=document.querySelector('#setup-mail-fields');fields.hidden=skip;
  fields.querySelectorAll('input,select').forEach(input=>input.disabled=skip);
  document.querySelector('#setup-mail-skip-note').hidden=!skip;
  document.querySelector('#setup-test').hidden=step===2||(step===3&&skip);
}
form.elements.smtp_mode.forEach(input=>input.addEventListener('change',()=>{mailFields();feedback.textContent='';feedback.classList.remove('error');}));
const headings=[['连接数据库','选择用于保存配置、账号和访问记录的数据库。'],['创建管理员','设置用于登录管理后台的账号。'],['配置邮件服务','可现在配置 SMTP，也可以跳过，之后在系统设置中补充。']];
function showStep(){
  sections.forEach(section=>section.hidden=Number(section.dataset.step)!==step);
  document.querySelectorAll('.setup-step').forEach((item,i)=>{item.classList.toggle('active',i+1===step);item.classList.toggle('done',i+1<step);if(i+1===step)item.setAttribute('aria-current','step');else item.removeAttribute('aria-current');});
  document.querySelector('#setup-heading').textContent=headings[step-1][0];document.querySelector('#setup-description').textContent=headings[step-1][1];document.querySelector('#setup-counter').textContent='0'+step+' / 03';
  document.querySelector('#setup-recipient').textContent=form.elements.admin_email.value||'管理员邮箱';
  document.querySelector('#setup-back').hidden=step===1;document.querySelector('#setup-next').hidden=step===3;document.querySelector('#setup-submit').hidden=step!==3;document.querySelector('#setup-test').hidden=step===2;document.querySelector('#setup-test').textContent=step===1?'测试连接':'发送测试邮件';databaseFields();mailFields();
}
function goToStep(target){if(!draftCurrent()||busy||target<1||target>3)return;while(step<target){if(!validStep(true,step!==1))return;step++;showStep();}step=target;feedback.textContent='';feedback.classList.remove('error');showStep();saveDraft();document.querySelector('#setup-heading').focus({preventScroll:true});document.querySelector('.setup-content').scrollTop=0;}
function validStep(checkConnection=true,checkSetupCode=true){if(!draftCurrent())return false;updateSetupPasswordStrength();updatePasswordConfirmation();for(const input of sections[step-1].querySelectorAll('input,select')){if(input.name==='setup_code'&&!checkSetupCode)continue;if(input.offsetParent!==null&&!input.reportValidity())return false}if(step===1&&checkConnection&&needsConnectionTest()&&!connectionTestCurrent()){feedback.textContent='请先点击测试连接，数据库和已启用的 Redis 均测试成功后才能继续。';feedback.classList.add('error');return false}return true;}
const nextButton=document.querySelector('#setup-next'),nextButtonMarkup=nextButton.innerHTML;
nextButton.onclick=async()=>{
  if(step!==1){goToStep(step+1);return;}
  if(busy||!validStep(false,false))return;
  if(connectionTestCurrent()){goToStep(2);return;}
  busy=true;nextButton.disabled=true;nextButton.textContent=form.elements.redis_enabled.checked?'正在测试数据库和 Redis…':'正在测试数据库连接…';document.querySelector('#setup-test').disabled=true;feedback.classList.remove('error');
  let advance=false;
  try{await runDatabaseConnectionTest();advance=true;}
  catch(error){feedback.textContent=error.message;feedback.classList.add('error');}
  finally{busy=false;nextButton.disabled=draftExpired;nextButton.innerHTML=nextButtonMarkup;document.querySelector('#setup-test').disabled=draftExpired;saveDraft();}
  if(advance)goToStep(2);
};
document.querySelector('#setup-back').onclick=()=>goToStep(step-1);
document.querySelectorAll('[data-go-step]').forEach(button=>button.addEventListener('click',()=>goToStep(Number(button.dataset.goStep))));
form.elements.redis_enabled.addEventListener('change',databaseFields);
form.elements.db_type.forEach(input=>input.addEventListener('change',()=>{const port={mysql:3306,pgsql:5432,sqlserver:1433}[input.value];if(port)form.elements.db_port.value=port;databaseFields()}));
async function requestSetupTest(url,body){const response=await fetch(url,{method:'POST',body,headers:{Accept:'application/json'},credentials:'same-origin'});if(!response.headers.get('content-type')?.includes('application/json'))throw new Error('验证失败，请刷新页面后重试。');const data=await response.json();refreshSetupBindings(data);if(data.setupCodeVerified)markSetupCodeVerified();if(response.status===419){expireDraft();throw new Error(data.message);}if(!draftCurrent())throw new Error('初始化会话已失效，请重新开始。');if(!response.ok||!data.ok)throw new Error(data.message||'连接测试失败');return data;}
async function runDatabaseConnectionTest(){
  const snapshot=connectionSnapshot(),body=new FormData(),current=new FormData(form);
  databaseFieldsToTest.forEach(name=>{if(current.has(name))body.set(name,current.get(name));});
  if(current.has('redis_enabled'))redisFields.forEach(name=>{if(current.has(name))body.set(name,current.get(name));});
  ['csrf','setup_draft_token'].forEach(name=>body.set(name,current.get(name)||''));
  testedConnection=null;testedAt=0;feedback.textContent='正在测试数据库连接…';body.set('test_stage','database');
  await requestSetupTest('setup_test_connection.php',body);
  if(body.has('redis_enabled')){
    body.set('csrf',form.elements.csrf.value);body.set('setup_draft_token',draftToken);
    feedback.textContent='数据库连接成功，正在测试 Redis…';body.set('test_stage','redis');
    await requestSetupTest('setup_test_connection.php',body);
  }
  if(snapshot!==connectionSnapshot())throw new Error('连接配置已修改，请重新测试。');
  testedConnection=snapshot;testedAt=Date.now();feedback.textContent=body.has('redis_enabled')?'数据库和 Redis 连接成功，正在进入下一步。':'数据库连接成功，正在进入下一步。';
}
document.querySelector('#setup-test').onclick=async()=>{
  if(busy||!validStep(false,step!==1))return;busy=true;
  const button=document.querySelector('#setup-test');button.disabled=true;feedback.classList.remove('error');
  const body=new FormData();
  const fields=step===1?[]:['admin_email','smtp_host','smtp_port','smtp_username','smtp_password','smtp_encryption'];
  const current=new FormData(form);['csrf','setup_draft_token',...fields].forEach(name=>{if(current.has(name))body.set(name,current.get(name));});
  try{
    if(step===1){
      await runDatabaseConnectionTest();feedback.textContent=form.elements.redis_enabled.checked?'数据库和 Redis 连接成功，可以继续。':'数据库连接成功，可以继续。';
    }else{feedback.textContent='正在发送测试邮件…';const data=await requestSetupTest('setup_test_email.php',body);feedback.textContent=data.message;}
  }catch(error){feedback.textContent=error.message;feedback.classList.add('error');}finally{busy=false;button.disabled=draftExpired;saveDraft();}
};
form.noValidate=true;
form.addEventListener('submit',event=>{if(!draftCurrent()||busy){event.preventDefault();return}if(step<3){event.preventDefault();if(step===1)nextButton.click();else goToStep(step+1);return}saveDraft();const saved=step;for(step=1;step<=3;step++){showStep();if(!validStep()){event.preventDefault();return}}step=saved;showStep();busy=true;document.querySelector('#setup-submit').disabled=true;feedback.textContent='正在验证配置并初始化，请稍候…'});
restoreDraft();showStep();updateSetupPasswordStrength();updatePasswordConfirmation();saveDraft();draftTick();
form.addEventListener('input',saveDraft);form.addEventListener('change',saveDraft);
document.addEventListener('visibilitychange',draftTick);
window.addEventListener('pageshow',draftTick);

const appearance=matchMedia('(prefers-color-scheme: dark)'),themeButton=document.querySelector('#setup-theme');
let themeMode='auto';try{const stored=localStorage.getItem('picapi-production-theme');if(['light','dark','auto'].includes(stored))themeMode=stored;}catch{}
function applySetupTheme(){
  document.documentElement.dataset.theme=themeMode==='auto'?(appearance.matches?'dark':'light'):themeMode;
  const paths={auto:'<path d="m5 20 7-16 7 16M8 14h8"/>',light:'<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/>',dark:'<path d="M20.8 13A9 9 0 0 1 11 3.2 9 9 0 1 0 20.8 13z"/>'};
  const labels={light:'浅色',dark:'深色',auto:'自动（跟随系统）'},next={light:'dark',dark:'auto',auto:'light'};
  themeButton.innerHTML='<svg class="icon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true">'+paths[themeMode]+'</svg>';
  themeButton.setAttribute('aria-label','当前'+labels[themeMode]+'，切换到'+labels[next[themeMode]]);themeButton.title=themeButton.getAttribute('aria-label');
}
themeButton.addEventListener('click',()=>{themeMode={light:'dark',dark:'auto',auto:'light'}[themeMode];try{localStorage.setItem('picapi-production-theme',themeMode);}catch{}applySetupTheme();});
appearance.addEventListener('change',()=>{if(themeMode==='auto')applySetupTheme();});applySetupTheme();
