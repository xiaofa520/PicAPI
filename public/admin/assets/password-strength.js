"use strict";

function evaluateSetupPassword(password, username = '', email = '') {
  const chars = Array.from(password);
  const length = chars.length;
  if (!length) return {level:0, label:'未输入', hint:'至少 8 位，建议使用 12 位以上的密码。', length};
  if (length < 8) return {level:0, label:'不足 8 位', hint:`还需输入至少 ${8-length} 位字符，建议组合字母、数字和符号。`, length};
  const lower = password.toLocaleLowerCase();
  const identity = [username, email.split('@')[0]].map(value=>value.trim().toLocaleLowerCase()).filter(value=>Array.from(value).length>=3);
  const containsIdentity = identity.some(value=>lower.includes(value));
  const predictable = /(password|qwerty|12345|54321|abcde|admin|letmein)/i.test(password) || /^(.{1,3})\1+$/u.test(password) || new Set(chars).size<4;
  const classes = [/\p{Ll}/u,/\p{Lu}/u,/\p{N}/u,/[^\p{L}\p{N}\s]/u].filter(pattern=>pattern.test(password)).length + (/\p{Lo}/u.test(password)?1:0);
  let level = 1;
  if ((length>=10 && classes>=2) || (length>=16 && new Set(chars).size>=8)) level=2;
  if ((length>=12 && classes>=3 && new Set(chars).size>=8) || (length>=20 && classes>=2 && new Set(chars).size>=10)) level=3;
  if (predictable || containsIdentity) level=1;
  const advice=[];
  if (containsIdentity) advice.push('不要包含用户名或邮箱前缀');
  if (predictable) advice.push('避免常见密码、连续数字或重复字符');
  if (length<12) advice.push('建议增加到 12 位以上');
  if (classes<3) advice.push('混合大小写字母、数字和符号');
  if (level===2 && !advice.length) advice.push('增加长度，避免使用容易猜到的词语');
  return {level, label:['','弱','中','强'][level], hint:level===3?'长度和字符组合较好。':advice.join('；')+'。', length};
}

function updateSetupPasswordStrength() {
  const input=document.querySelector('#setup-form input[name="password"]');
  if(!input)return;
  const form=input.form;
  const result=evaluateSetupPassword(input.value,form.elements.username?.value||'',form.elements.admin_email?.value||'');
  input.setCustomValidity(result.length>0&&result.length<8?'登录密码至少需要 8 位字符。':'');
  let box=form.querySelector('#setup-password-strength');
  if(!box){
    box=document.createElement('div');box.id='setup-password-strength';box.className='setup-password-strength';
    box.innerHTML='<div class="password-strength-title">密码强度：<strong></strong></div><div class="password-strength-meter" role="meter" aria-label="密码强度" aria-valuemin="0" aria-valuemax="3"><i></i><i></i><i></i></div><small id="setup-password-advice" aria-live="polite"></small>';
    (input.closest('.setup-password-control')||input).insertAdjacentElement('afterend',box);
    input.setAttribute('aria-describedby',[input.getAttribute('aria-describedby'),'setup-password-advice'].filter(Boolean).join(' '));
  }
  box.dataset.level=String(result.level);
  box.querySelector('strong').textContent=result.label;
  const meter=box.querySelector('[role="meter"]');meter.setAttribute('aria-valuenow',String(result.level));meter.setAttribute('aria-valuetext',result.label);
  box.querySelectorAll('i').forEach((bar,index)=>bar.classList.toggle('filled',index<result.level));
  const hint=box.querySelector('small');if(hint.textContent!==result.hint)hint.textContent=result.hint;
}
if(typeof document!=='undefined'){
  document.addEventListener('input',event=>{if(event.target.closest('#setup-form')&&['password','username','admin_email'].includes(event.target.name))updateSetupPasswordStrength();});
  document.addEventListener('change',event=>{if(event.target.closest('#setup-form')&&['password','username','admin_email'].includes(event.target.name))updateSetupPasswordStrength();});
  new MutationObserver(records=>{if(records.some(record=>Array.from(record.addedNodes).some(node=>node.nodeType===1&&(node.matches('input[name="password"]')||node.querySelector('input[name="password"]')))))updateSetupPasswordStrength();}).observe(document.body,{childList:true,subtree:true});
  updateSetupPasswordStrength();
}
if(typeof module!=='undefined')module.exports={evaluateSetupPassword};
