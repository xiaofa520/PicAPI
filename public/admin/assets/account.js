'use strict';

// Profile editor and local avatar preview.
const profile = {name:'',email:'',avatar:''};
const profileDraft = {...profile};
const avatarURLs = new Set();
let avatarRequest = 0, avatarLoading = false, loginStep = 'login';


function avatarMarkup(url) {
  return url ? `<img src="${escapeHTML(url)}" alt="" width="80" height="80">` : icon('user');
}
function updateSidebarProfile() {
  const link=document.querySelector('#sidebar-profile');
  link.innerHTML=`<span class="user-avatar">${avatarMarkup(profile.avatar)}</span><span class="sidebar-identity"><strong title="${escapeHTML(profile.name)}">${escapeHTML(profile.name)}</strong><small>管理员</small></span>`;
  link.setAttribute('aria-label',profile.name+'，查看个人资料');
  link.title=profile.name+' · 个人资料';
  const active=state.page==='settings'&&state.settingsTab==='account';
  link.classList.toggle('active',active);
  if(active)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');
}
function profileSettingsMarkup() {
  return `<form id="profile-form" class="settings-section profile-section">
    <h2>个人资料</h2><p>设置你的头像与账号信息。</p>
    <div class="profile-avatar-editor">
      <div id="profile-avatar" class="user-avatar profile-avatar" role="img" aria-label="头像预览">${avatarMarkup(profileDraft.avatar)}</div>
      <div class="profile-avatar-controls"><div class="actions"><button type="button" class="button" data-account-action="pick-avatar">${icon('upload')}上传头像</button><button type="button" class="text-button" data-account-action="remove-avatar" ${profileDraft.avatar?'':'hidden'}>移除</button></div><p id="avatar-hint">支持 JPG、PNG、WebP，最大 2 MB；上传前可调整裁剪范围。</p><p id="avatar-feedback" class="avatar-feedback" role="status" aria-live="polite"></p></div>
      <input id="avatar-file" type="file" accept="image/jpeg,image/png,image/webp" aria-label="选择头像文件" aria-describedby="avatar-hint" hidden>
    </div>
    <div class="form-grid"><div class="field"><label for="account-name">用户名</label><input id="account-name" name="username" data-profile-field="name" value="${escapeHTML(profileDraft.name)}" autocomplete="username" required maxlength="40"></div><div class="field"><label for="account-email">邮箱地址</label><input id="account-email" name="email" data-profile-field="email" type="email" value="${escapeHTML(profileDraft.email)}" autocomplete="email" required maxlength="120"></div></div>
    <div class="settings-inline-save"><span>更换邮箱需要验证码确认</span><button id="save-profile" type="submit" class="button primary" ${avatarLoading?'disabled':''}>${icon('save')}保存个人资料</button></div>
  </form>
  <form id="profile-password-form" class="settings-section"><div class="account-security-heading"><div><h2>账号安全</h2><p>通过管理员绑定邮箱验证后更新密码。</p></div></div><div class="form-grid"><div class="field full"><div class="password-label"><label for="password-verification-code">邮箱验证码</label><button class="text-button" type="button" data-account-action="send-password-code">发送验证码</button></div><input id="password-verification-code" name="verification_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="输入 6 位验证码" required><small>验证码将发送至 ${escapeHTML(profile.email||'管理员邮箱')}，15 分钟内有效。</small></div><div class="field"><label for="new-password">新密码</label><input id="new-password" type="password" placeholder="至少 8 位字符" autocomplete="new-password" required minlength="8" maxlength="128"></div><div class="field"><label for="confirm-password">确认新密码</label><input id="confirm-password" type="password" placeholder="再次输入新密码" autocomplete="new-password" required minlength="8" maxlength="128"></div></div><div class="settings-inline-save"><span>新密码不能与当前密码相同；更新后其他会话将失效</span><button class="button" type="submit">${icon('lock')}更新密码</button></div></form>`;
}
function releaseAvatar(url) {
  if(url&&avatarURLs.has(url)){URL.revokeObjectURL(url);avatarURLs.delete(url);}
}
function updateAvatarEditor(message='',error=false) {
  const preview=document.querySelector('#profile-avatar');
  if(preview)preview.innerHTML=avatarMarkup(profileDraft.avatar);
  const remove=document.querySelector('[data-account-action="remove-avatar"]');if(remove)remove.hidden=!profileDraft.avatar;
  const save=document.querySelector('#save-profile');if(save)save.disabled=avatarLoading;
  const feedback=document.querySelector('#avatar-feedback');if(feedback){feedback.textContent=message;feedback.classList.toggle('error',error);}
}
let avatarCropState=null, resolveAvatarCrop=null;
function cropGeometry(){
  const canvas=document.querySelector('#avatar-crop-canvas');
  if(!canvas||!avatarCropState)return null;
  const box=canvas.getBoundingClientRect(),size=Math.max(1,Math.min(box.width,box.height));
  const dpr=Math.min(window.devicePixelRatio||1,2),px=Math.round(size*dpr);
  if(canvas.width!==px||canvas.height!==px){canvas.width=px;canvas.height=px;}
  return {canvas,size,dpr,ctx:canvas.getContext('2d')};
}
function cropMinScale(){
  const s=avatarCropState,theta=s.rotation*Math.PI/180,c=Math.abs(Math.cos(theta)),sn=Math.abs(Math.sin(theta));
  const w=s.image.naturalWidth,h=s.image.naturalHeight;
  const rotatedW=c*w+sn*h,rotatedH=sn*w+c*h;
  return Math.max(.0001,s.stage*.8/rotatedW,s.stage*.8/rotatedH);
}
function clampCrop(){
  const s=avatarCropState;if(!s)return;
  const min=cropMinScale();s.scale=Math.max(min,Math.min(s.scale,min*8));
  const theta=s.rotation*Math.PI/180,c=Math.cos(theta),sn=Math.sin(theta),radius=s.stage*.4;
  const q=radius/s.scale,dx=s.stage/2-s.x,dy=s.stage/2-s.y;
  let ux=(c*dx+sn*dy)/s.scale,uy=(-sn*dx+c*dy)/s.scale;
  const maxX=Math.max(0,s.image.naturalWidth/2-q),maxY=Math.max(0,s.image.naturalHeight/2-q);
  ux=Math.max(-maxX,Math.min(maxX,ux));uy=Math.max(-maxY,Math.min(maxY,uy));
  s.x=s.stage/2-s.scale*(c*ux-sn*uy);s.y=s.stage/2-s.scale*(sn*ux+c*uy);
}
function drawAvatarCrop(){
  const geo=cropGeometry(),s=avatarCropState;if(!geo||!s)return;
  s.stage=geo.size;clampCrop();const {ctx,size,dpr}=geo;
  ctx.setTransform(dpr,0,0,dpr,0,0);ctx.clearRect(0,0,size,size);
  ctx.save();ctx.translate(s.x,s.y);ctx.rotate(s.rotation*Math.PI/180);ctx.scale(s.scale*(s.flip?-1:1),s.scale);
  ctx.drawImage(s.image,-s.image.naturalWidth/2,-s.image.naturalHeight/2);ctx.restore();
  ctx.save();ctx.beginPath();ctx.rect(0,0,size,size);ctx.arc(size/2,size/2,size*.4,0,Math.PI*2);ctx.clip('evenodd');ctx.fillStyle='rgba(5,10,20,.58)';ctx.fillRect(0,0,size,size);ctx.restore();
  ctx.beginPath();ctx.arc(size/2,size/2,size*.4,0,Math.PI*2);ctx.strokeStyle='rgba(255,255,255,.95)';ctx.lineWidth=2;ctx.stroke();
  const ratio=s.scale/cropMinScale(),zoom=document.querySelector('#avatar-crop-zoom');if(zoom){zoom.value=String(Math.round((ratio-1)*100));zoom.style.setProperty('--zoom-progress',`${Math.max(0,Math.min(100,(ratio-1)/7*100))}%`);}
  const label=document.querySelector('#avatar-crop-zoom-value');if(label)label.textContent=ratio.toFixed(1)+'×';
  const preview=document.querySelector('#avatar-crop-preview');if(preview){const pctx=preview.getContext('2d');pctx.clearRect(0,0,preview.width,preview.height);drawCroppedAvatar(pctx,preview.width,s);}
}
function drawCroppedAvatar(ctx,size,s){const scale=size/(s.stage*.8);ctx.save();ctx.translate(-s.stage*.1*scale,-s.stage*.1*scale);ctx.scale(scale,scale);ctx.translate(s.x,s.y);ctx.rotate(s.rotation*Math.PI/180);ctx.scale(s.scale*(s.flip?-1:1),s.scale);ctx.drawImage(s.image,-s.image.naturalWidth/2,-s.image.naturalHeight/2);ctx.restore();}
function cropDialogMarkup(file){
  const username=document.querySelector('#account-name')?.value||profileDraft.name||'管理员';
  const email=document.querySelector('#account-email')?.value||profileDraft.email||'';
  const metadata=`${escapeHTML(file.name)} · ${avatarCropState.image.naturalWidth} × ${avatarCropState.image.naturalHeight} · ${(file.size/1024/1024).toFixed(2)} MB`;
  const lowResolution=avatarCropState.image.naturalWidth<160||avatarCropState.image.naturalHeight<160;
  return `<div class="modal-body avatar-crop-body"><p class="avatar-crop-file">${metadata}</p><div class="avatar-crop-layout"><div class="avatar-crop-main"><div class="avatar-crop-stagebox"><canvas id="avatar-crop-canvas" tabindex="0" role="img" aria-label="头像裁剪区域，使用方向键移动，正负键缩放">头像裁剪预览</canvas></div><p class="avatar-crop-help">拖动图片调整位置，滚轮或双指缩放，圆圈内为最终头像。</p>${lowResolution?'<p class="avatar-crop-warning">图片分辨率较低，放大后可能不够清晰。</p>':''}<div class="avatar-crop-zoom"><label for="avatar-crop-zoom">缩放</label><input id="avatar-crop-zoom" type="range" min="0" max="700" value="0" aria-label="缩放图片"><output id="avatar-crop-zoom-value">1.0×</output></div><div class="avatar-crop-tools"><button class="button" type="button" data-avatar-crop-action="rotate-left"><span class="avatar-crop-tool-icon">↶</span><span>左转 90°</span></button><button class="button" type="button" data-avatar-crop-action="rotate-right"><span class="avatar-crop-tool-icon">↷</span><span>右转 90°</span></button><button class="button" type="button" data-avatar-crop-action="flip"><span class="avatar-crop-tool-icon">↔</span><span>水平翻转</span></button><button class="button" type="button" data-avatar-crop-action="reset"><span class="avatar-crop-tool-icon">⟳</span><span>重置</span></button></div></div><aside class="avatar-crop-preview-panel"><h3>预览</h3><div class="avatar-crop-preview-card"><canvas id="avatar-crop-preview" width="160" height="160" aria-label="圆形头像效果预览"></canvas><div><strong data-avatar-preview-name>${escapeHTML(username)}</strong><small data-avatar-preview-email>${escapeHTML(email)}</small></div></div></aside></div></div>`;
}
function closeAvatarCrop(){
  document.documentElement.classList.remove('avatar-crop-open');document.body.classList.remove('avatar-crop-open');
  if(!avatarCropState)return;
  const source=avatarCropState.sourceURL;avatarCropState=null;releaseAvatar(source);
  avatarLoading=false;updateAvatarEditor();
  if(resolveAvatarCrop){resolveAvatarCrop(null);resolveAvatarCrop=null;}
}
async function applyAvatarCrop(){
  const state=avatarCropState;if(!state)return;
  const button=document.querySelector('[data-avatar-crop-apply]');if(button)button.disabled=true;
  try{
    const canvas=document.createElement('canvas');canvas.width=512;canvas.height=512;const ctx=canvas.getContext('2d');
    drawCroppedAvatar(ctx,512,state);
    const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/png'));
    if(!blob)throw new Error('PNG export failed');
    const cropped=URL.createObjectURL(blob);avatarURLs.add(cropped);
    if(profileDraft.avatar!==profile.avatar)releaseAvatar(profileDraft.avatar);
    profileDraft.avatar=cropped;avatarLoading=false;updateAvatarEditor('裁剪预览已更新，保存个人资料后生效。');
    const file=new File([blob],'avatar.png',{type:'image/png'}),resolve=resolveAvatarCrop;resolveAvatarCrop=null;
    if(avatarCropState){const source=avatarCropState.sourceURL;avatarCropState=null;releaseAvatar(source);}
    if(resolve)resolve(file);closeModal();
  }catch{if(button)button.disabled=false;toast('头像裁剪失败，请重试。');}
}
async function chooseAvatar(file){
  if(!file)return null;
  ++avatarRequest;
  const supported=['image/jpeg','image/png','image/webp'].includes(file.type)||(!file.type&&/\.(jpe?g|png|webp)$/i.test(file.name));
  if(!supported){updateAvatarEditor('请选择 JPG、PNG 或 WebP 图片。',true);return null;}
  if(file.size>2*1024*1024){updateAvatarEditor('图片超过 2 MB，请选择更小的文件。',true);return null;}
  avatarLoading=true;updateAvatarEditor('正在读取图片…');
  const url=URL.createObjectURL(file);avatarURLs.add(url);
  try{
    const image=new Image();image.src=url;await image.decode();
    if(!image.naturalWidth||!image.naturalHeight)throw new Error('Invalid image');
    avatarCropState={image,sourceURL:url,rotation:0,flip:false,stage:1,x:.5,y:.5,scale:1,pointers:new Map()};
    const minimum=cropMinScale();avatarCropState.scale=minimum;avatarCropState.x=avatarCropState.y=avatarCropState.stage/2;
    const footer=`<button class="button" type="button" data-action="close-modal">取消</button><button class="button primary" type="button" data-avatar-crop-apply>${icon('check')}应用裁剪</button>`;
    document.documentElement.classList.add('avatar-crop-open');document.body.classList.add('avatar-crop-open');
    openModal('裁剪头像',cropDialogMarkup(file),footer,'avatar-crop-dialog');
    requestAnimationFrame(()=>{const c=document.querySelector('#avatar-crop-canvas');const box=c?.getBoundingClientRect();if(!c||!box)return;avatarCropState.stage=Math.min(box.width,box.height);avatarCropState.x=avatarCropState.y=avatarCropState.stage/2;avatarCropState.scale=cropMinScale();drawAvatarCrop();c.focus();});
    return await new Promise(resolve=>{resolveAvatarCrop=resolve;modal.addEventListener('close',closeAvatarCrop,{once:true});});
  }catch{
    document.documentElement.classList.remove('avatar-crop-open');document.body.classList.remove('avatar-crop-open');releaseAvatar(url);avatarCropState=null;avatarLoading=false;updateAvatarEditor('无法读取这张图片，请换一个文件。',true);return null;
  }
}
function cropZoomAt(value,x,y){
  const s=avatarCropState;if(!s)return;const min=cropMinScale(),next=min*Math.max(1,Math.min(8,value));
  const factor=next/s.scale;s.x=x+(s.x-x)*factor;s.y=y+(s.y-y)*factor;s.scale=next;drawAvatarCrop();
}
document.addEventListener('pointerdown',event=>{const canvas=event.target.closest?.('#avatar-crop-canvas');if(!canvas||!avatarCropState)return;canvas.setPointerCapture(event.pointerId);avatarCropState.pointers.set(event.pointerId,{x:event.clientX,y:event.clientY});avatarCropState.lastPinch=null;});
document.addEventListener('pointermove',event=>{const s=avatarCropState;if(!s||!s.pointers.has(event.pointerId))return;const p=s.pointers.get(event.pointerId),dx=event.clientX-p.x,dy=event.clientY-p.y;p.x=event.clientX;p.y=event.clientY;if(s.pointers.size===1){s.x+=dx;s.y+=dy;}else{const pts=[...s.pointers.values()],distance=Math.hypot(pts[0].x-pts[1].x,pts[0].y-pts[1].y),center=document.querySelector('#avatar-crop-canvas').getBoundingClientRect(),cx=(pts[0].x+pts[1].x)/2-center.left,cy=(pts[0].y+pts[1].y)/2-center.top;if(s.lastPinch)cropZoomAt((s.scale/s.lastPinch.distance*distance)/cropMinScale(),cx,cy);s.lastPinch={distance};}drawAvatarCrop();});
document.addEventListener('pointerup',event=>{if(avatarCropState){avatarCropState.pointers.delete(event.pointerId);if(avatarCropState.pointers.size<2)avatarCropState.lastPinch=null;}});
document.addEventListener('pointercancel',event=>{if(avatarCropState){avatarCropState.pointers.delete(event.pointerId);avatarCropState.lastPinch=null;}});
document.addEventListener('wheel',event=>{if(!event.target.closest?.('#avatar-crop-canvas')||!avatarCropState)return;event.preventDefault();const rect=event.target.getBoundingClientRect();cropZoomAt((avatarCropState.scale/cropMinScale())*Math.exp(-event.deltaY*.0015),(event.clientX-rect.left),(event.clientY-rect.top));},{passive:false});
document.addEventListener('input',event=>{if(event.target.id==='avatar-crop-zoom'&&avatarCropState)cropZoomAt(1+Number(event.target.value)/100,avatarCropState.stage/2,avatarCropState.stage/2);if(event.target.matches('[data-profile-field="name"], [data-profile-field="email"]')){const preview=document.querySelector(event.target.matches('[data-profile-field="name"]')?'[data-avatar-preview-name]':'[data-avatar-preview-email]');if(preview)preview.textContent=event.target.value;}});
document.addEventListener('keydown',event=>{if(event.target.id!=='avatar-crop-canvas'||!avatarCropState)return;const s=avatarCropState,step=event.shiftKey?10:2;let handled=true;switch(event.key){case'ArrowLeft':s.x-=step;break;case'ArrowRight':s.x+=step;break;case'ArrowUp':s.y-=step;break;case'ArrowDown':s.y+=step;break;case'+':case'=':cropZoomAt(s.scale/cropMinScale()*1.1,s.stage/2,s.stage/2);break;case'-':case'_':cropZoomAt(s.scale/cropMinScale()/1.1,s.stage/2,s.stage/2);break;default:handled=false;}if(handled){event.preventDefault();drawAvatarCrop();}});
document.addEventListener('click',event=>{const button=event.target.closest?.('[data-avatar-crop-action], [data-avatar-crop-apply]');if(!button)return;const action=button.dataset.avatarCropAction;if(button.hasAttribute('data-avatar-crop-apply')){void applyAvatarCrop();return;}const s=avatarCropState;if(!s)return;switch(action){case'zoom-in':cropZoomAt(s.scale/cropMinScale()*1.15,s.stage/2,s.stage/2);break;case'zoom-out':cropZoomAt(s.scale/cropMinScale()/1.15,s.stage/2,s.stage/2);break;case'rotate-left':s.rotation=(s.rotation-90+360)%360;drawAvatarCrop();break;case'rotate-right':s.rotation=(s.rotation+90)%360;drawAvatarCrop();break;case'flip':s.flip=!s.flip;drawAvatarCrop();break;case'reset':s.rotation=0;s.flip=false;s.x=s.y=s.stage/2;s.scale=cropMinScale();drawAvatarCrop();break;}});
window.addEventListener('resize',()=>{if(avatarCropState){const canvas=document.querySelector('#avatar-crop-canvas'),rect=canvas?.getBoundingClientRect();if(rect){const next=Math.min(rect.width,rect.height),ratio=next/avatarCropState.stage;avatarCropState.x*=ratio;avatarCropState.y*=ratio;avatarCropState.scale*=ratio;avatarCropState.stage=next;drawAvatarCrop();}}});

function passwordControl(id,label,placeholder,auto='current-password',help='') {
  return `<div class="field"><div class="password-label"><label for="${id}">${label}</label>${help}</div><div class="password-control"><input id="${id}" type="password" placeholder="${placeholder}" autocomplete="${auto}" required ${auto==='new-password'?'minlength="8"':''} maxlength="128"><button class="icon-button" type="button" data-password-toggle="${id}" aria-label="显示${label}" aria-pressed="false">${icon('eye')}</button></div></div>`;
}
