"use strict";

const siteIconSettings = {savedURL:'assets/mark.svg',draft:null,previewURL:null};
const siteIconCrop = {file:null,image:null,width:0,height:0,url:null,stage:0,baseScale:1,scale:1,x:0,y:0,cropX:0,cropY:0,half:0,rotation:0,flip:false,pointers:new Map(),drag:null,frame:0};
const SITE_ICON_MAX_BYTES=5*1024*1024,SITE_ICON_OUTPUT_SIZE=512,SITE_ICON_PAD=8,SITE_ICON_MIN_CROP=48;

function siteIconSettingsMarkup(){return `<div class="field full"><label>网站图标</label><div class="site-icon-control"><img id="site-icon-preview" src="${escapeHTML(siteIconSettings.previewURL||siteIconSettings.savedURL)}" alt="网站图标预览" width="56" height="56"><div class="site-icon-fields"><div class="site-icon-actions"><button type="button" class="button" data-site-icon-choose>${icon('upload')}上传网站图标</button><button type="button" class="text-button" data-site-icon-reset ${siteIconSettings.draft?'':'hidden'}>恢复默认</button></div><input id="site-icon-file" type="file" accept="image/png,image/jpeg,image/gif,image/webp,image/x-icon,image/vnd.microsoft.icon,.ico" hidden><small>支持 PNG、JPEG、GIF、WebP、ICO，原图最大 5 MB。选择后可调整裁剪范围，完成后自动保存。</small><small id="site-icon-status">${siteIconSettings.draft?'网站图标已上传':'未更换时使用默认 PicAPI 图标'}</small><small id="site-icon-error" class="site-icon-error" role="alert" hidden></small></div></div></div>`;}

function applySiteIcon(url){
  siteIconSettings.savedURL=url;try{siteIconSettings.draft=new URL(url,location.href).searchParams.has('custom')?true:null;}catch{siteIconSettings.draft=null;}
  if(siteIconSettings.previewURL)URL.revokeObjectURL(siteIconSettings.previewURL);
  siteIconSettings.previewURL=null;
  const preview=document.querySelector('#site-icon-preview');if(preview)preview.src=url;
  const brandIcon=document.querySelector('#workspace-site-icon');if(brandIcon)brandIcon.src=url;
  const status=document.querySelector('#site-icon-status');if(status)status.textContent=siteIconSettings.draft?'当前使用自定义网站图标':'未更换时使用默认 PicAPI 图标';
  const reset=document.querySelector('[data-site-icon-reset]');if(reset)reset.hidden=!siteIconSettings.draft;
  const link=document.querySelector('link[rel="icon"]');if(link){link.href=url;link.removeAttribute('type');}
}

function siteIconError(message){const el=document.querySelector('#site-icon-error');if(el){el.textContent=message;el.hidden=!message;}else if(message)toast(message);}
function siteIconDims(image){return {width:image.naturalWidth||image.width,height:image.naturalHeight||image.height};}
function siteIconStage(){
  const canvas=document.querySelector('#site-icon-crop-canvas');if(!canvas||!siteIconCrop.image)return null;
  const rect=canvas.getBoundingClientRect(),size=Math.max(1,Math.min(rect.width,rect.height)),dpr=Math.min(window.devicePixelRatio||1,2),pixels=Math.round(size*dpr);
  if(canvas.width!==pixels||canvas.height!==pixels){canvas.width=pixels;canvas.height=pixels;}
  return {canvas,size,dpr,ctx:canvas.getContext('2d')};
}
function siteIconExt(){const a=siteIconCrop.rotation*Math.PI/180;return Math.abs(Math.cos(a))+Math.abs(Math.sin(a));}
function siteIconMinScale(half=siteIconCrop.half){const ext=siteIconExt();return Math.max(2*half*ext/siteIconCrop.width,2*half*ext/siteIconCrop.height);}
function clampSiteIconImage(){
  const s=siteIconCrop;if(!s.image)return;
  const minimum=siteIconMinScale();s.scale=Math.max(minimum,Math.min(s.scale,Math.max(minimum,s.baseScale*8)));
  const t=s.rotation*Math.PI/180,c=Math.cos(t),sn=Math.sin(t),q=s.half*siteIconExt()/s.scale,dx=s.stage/2-s.x,dy=s.stage/2-s.y;
  let ux=(c*dx+sn*dy)/s.scale,uy=(-sn*dx+c*dy)/s.scale;
  const maxX=Math.max(0,s.width/2-q),maxY=Math.max(0,s.height/2-q);
  ux=Math.max(-maxX,Math.min(maxX,ux));uy=Math.max(-maxY,Math.min(maxY,uy));
  s.x=s.stage/2-s.scale*(c*ux-sn*uy);s.y=s.stage/2-s.scale*(sn*ux+c*uy);
}
function updateSiteIconZoom(){
  const s=siteIconCrop,ratio=s.scale/s.baseScale,input=document.querySelector('[data-site-icon-zoom]');
  if(input){input.value=String(Math.max(0,Math.min(100,Math.round(Math.log(Math.max(1,ratio))/Math.log(8)*100))));input.style.setProperty('--site-icon-zoom-progress',`${input.value}%`);}
  const value=document.querySelector('[data-site-icon-zoom-value]');if(value)value.textContent=`${ratio.toFixed(1)}×`;
}
function drawSiteIcon(){
  const geo=siteIconStage(),s=siteIconCrop;if(!geo||!s.image)return;
  s.stage=geo.size;clampSiteIconImage();const {ctx,size,dpr}=geo;
  ctx.setTransform(dpr,0,0,dpr,0,0);ctx.clearRect(0,0,size,size);ctx.fillStyle='#0F0A1F';ctx.fillRect(0,0,size,size);
  const x0=s.cropX-s.half,y0=s.cropY-s.half,side=s.half*2,cell=10;
  for(let y=0;y<side;y+=cell)for(let x=0;x<side;x+=cell){ctx.fillStyle=((Math.floor(x/cell)+Math.floor(y/cell))%2)?'#dcd6ec':'#fff';ctx.fillRect(x0+x,y0+y,Math.min(cell,side-x),Math.min(cell,side-y));}
  ctx.save();ctx.translate(s.x,s.y);ctx.rotate(s.rotation*Math.PI/180);if(s.flip)ctx.scale(-1,1);ctx.scale(s.scale,s.scale);ctx.drawImage(s.image,-s.width/2,-s.height/2,s.width,s.height);ctx.restore();
  ctx.save();ctx.beginPath();ctx.rect(0,0,size,size);ctx.rect(x0,y0,side,side);ctx.fillStyle='rgba(12,7,26,.68)';ctx.fill('evenodd');ctx.restore();
  ctx.strokeStyle='rgba(255,255,255,.95)';ctx.lineWidth=1.5;ctx.strokeRect(x0,y0,side,side);
  ctx.strokeStyle='rgba(255,255,255,.38)';ctx.lineWidth=1;ctx.beginPath();for(let i=1;i<3;i++){ctx.moveTo(x0+side*i/3,y0);ctx.lineTo(x0+side*i/3,y0+side);ctx.moveTo(x0,y0+side*i/3);ctx.lineTo(x0+side,y0+side*i/3);}ctx.stroke();
  ctx.fillStyle='#fff';for(const [x,y] of [[x0,y0],[x0+side,y0],[x0,y0+side],[x0+side,y0+side]])ctx.fillRect(x-4,y-4,8,8);
  updateSiteIconZoom();drawSiteIconPreviews();
}
function drawSiteIconOutput(size){
  const s=siteIconCrop,canvas=document.createElement('canvas');canvas.width=canvas.height=size;const ctx=canvas.getContext('2d');if(!ctx||!s.image)return canvas;
  const k=size/(s.half*2);ctx.imageSmoothingQuality='high';ctx.save();ctx.scale(k,k);ctx.translate(-(s.cropX-s.half),-(s.cropY-s.half));ctx.translate(s.x,s.y);ctx.rotate(s.rotation*Math.PI/180);if(s.flip)ctx.scale(-1,1);ctx.scale(s.scale,s.scale);ctx.drawImage(s.image,-s.width/2,-s.height/2,s.width,s.height);ctx.restore();return canvas;
}
function drawSiteIconPreviews(){
  if(!siteIconCrop.image)return;
  const tab=document.querySelector('[data-site-icon-preview-size="16"]'),p32=document.querySelector('[data-site-icon-preview-size="32"]'),p48=document.querySelector('[data-site-icon-preview-size="48"]'),p180=document.querySelector('[data-site-icon-preview-size="180"]');
  for(const canvas of [tab,p32,p48,p180])if(canvas){const ctx=canvas.getContext('2d');ctx.clearRect(0,0,canvas.width,canvas.height);ctx.imageSmoothingQuality='high';ctx.drawImage(drawSiteIconOutput(canvas.width),0,0);}
}
function queueSiteIconDraw(){if(siteIconCrop.frame)return;siteIconCrop.frame=requestAnimationFrame(()=>{siteIconCrop.frame=0;drawSiteIcon();});}
function siteIconCropMarkup(file){
  const name=document.querySelector('#site-name')?.value?.trim()||'我的网站',address=(document.querySelector('#site-url')?.value||'https://example.com').trim().replace(/^https?:\/\//,'')||'example.com',dims=siteIconDims(siteIconCrop.image);
  const metadata=`${escapeHTML(file.name)} · ${dims.width} × ${dims.height} · ${(file.size/1024/1024).toFixed(2)} MB`;
  return `<div class="modal-body site-icon-crop-body"><p class="site-icon-crop-meta">${metadata}</p><div class="site-icon-crop-layout"><section class="site-icon-crop-main"><div class="site-icon-stagebox"><canvas id="site-icon-crop-canvas" tabindex="0" role="img" aria-label="方形网站图标裁剪区域，可拖动图片、拖动四角调整范围，使用方向键移动，正负键缩放">网站图标裁剪预览</canvas></div><p class="site-icon-crop-help">在画布上拖动图片调整位置，拖白框四角调整裁剪范围；滚轮或双指缩放。</p><div class="site-icon-zoom"><label for="site-icon-zoom">缩放</label><input id="site-icon-zoom" type="range" min="0" max="100" step="1" value="0" data-site-icon-zoom aria-label="缩放网站图标"><output data-site-icon-zoom-value>1.0×</output></div><div class="site-icon-tools"><button type="button" class="button" data-site-icon-action="rotate-left"><span aria-hidden="true">↶</span>左转 90°</button><button type="button" class="button" data-site-icon-action="rotate-right"><span aria-hidden="true">↷</span>右转 90°</button><button type="button" class="button" data-site-icon-action="flip" aria-pressed="false"><span aria-hidden="true">↔</span>水平翻转</button><button type="button" class="button" data-site-icon-action="reset"><span aria-hidden="true">⟳</span>重置</button></div></section><aside class="site-icon-crop-preview"><div><h3>预览</h3><div class="site-icon-browser-preview"><div class="site-icon-tabbar"><div class="site-icon-tab"><canvas width="32" height="32" data-site-icon-preview-size="16"></canvas><span data-site-icon-tab-name>${escapeHTML(name)}</span><i aria-hidden="true">×</i></div></div><div class="site-icon-address"><div data-site-icon-address>${escapeHTML(address)}</div></div></div><div class="site-icon-sizes"><figure><div class="site-icon-preview-box"><canvas width="32" height="32" data-site-icon-preview-size="32"></canvas></div>32 × 32</figure><figure><div class="site-icon-preview-box"><canvas width="48" height="48" data-site-icon-preview-size="48"></canvas></div>48 × 48</figure><figure><div class="site-icon-preview-box is-rounded"><canvas width="180" height="180" data-site-icon-preview-size="180"></canvas></div>180 × 180 主屏图标</figure></div></div><small class="site-icon-crop-preserve">完成后会保留所选原图，并上传压缩后的网站图标。</small></aside></div><small class="site-icon-crop-progress" data-site-icon-progress aria-live="polite"></small></div>`;
}
function siteIconSetZoom(value,x=siteIconCrop.stage/2,y=siteIconCrop.stage/2){
  const s=siteIconCrop;if(!s.image)return;const target=s.baseScale*Math.pow(8,Math.max(0,Math.min(100,value))/100),next=Math.max(siteIconMinScale(),Math.min(target,Math.max(siteIconMinScale(),s.baseScale*8))),factor=next/s.scale;
  s.x=x+(s.x-x)*factor;s.y=y+(s.y-y)*factor;s.scale=next;clampSiteIconImage();queueSiteIconDraw();
}
function resetSiteIconCrop(){
  const geo=siteIconStage(),s=siteIconCrop;if(!geo||!s.image)return;
  s.stage=geo.size;s.baseScale=s.stage*.92/Math.max(s.width,s.height);s.scale=s.baseScale;s.x=s.y=s.cropX=s.cropY=s.stage/2;s.half=Math.min(s.width,s.height)*s.baseScale/2;s.rotation=0;s.flip=false;
  const flip=document.querySelector('[data-site-icon-action="flip"]');if(flip)flip.setAttribute('aria-pressed','false');clampSiteIconImage();queueSiteIconDraw();
}
function resizeSiteIconCrop(){
  if(!siteIconCrop.image)return;const geo=siteIconStage();if(!geo)return;const s=siteIconCrop,next=geo.size;if(s.stage&&next!==s.stage){const factor=next/s.stage;s.x*=factor;s.y*=factor;s.cropX*=factor;s.cropY*=factor;s.half*=factor;s.scale*=factor;s.baseScale*=factor;}s.stage=next;clampSiteIconImage();queueSiteIconDraw();
}
function openSiteIconCrop(file,image,url){
  const dims=siteIconDims(image);let source=image;
  if(Math.max(dims.width,dims.height)>2048){const factor=2048/Math.max(dims.width,dims.height),canvas=document.createElement('canvas');canvas.width=Math.round(dims.width*factor);canvas.height=Math.round(dims.height*factor);const ctx=canvas.getContext('2d');ctx.imageSmoothingQuality='high';ctx.drawImage(image,0,0,canvas.width,canvas.height);source=canvas;}
  const size=siteIconDims(source);Object.assign(siteIconCrop,{file,image:source,width:size.width,height:size.height,url,stage:0,baseScale:1,scale:1,x:0,y:0,cropX:0,cropY:0,half:0,rotation:0,flip:false,pointers:new Map(),drag:null});
  const footer=`<button type="button" class="button" data-action="close-modal">取消</button><button type="button" class="button primary" data-site-icon-crop>应用图标并自动保存</button>`;
  document.documentElement.classList.add('site-icon-crop-open');document.body.classList.add('site-icon-crop-open');
  openModal('裁剪网站图标',siteIconCropMarkup(file),footer,'site-icon-crop-dialog');
  modal.addEventListener('close',discardSiteIconCrop,{once:true});
  requestAnimationFrame(()=>{resetSiteIconCrop();document.querySelector('#site-icon-crop-canvas')?.focus({preventScroll:true});});
}
function discardSiteIconCrop(){
  document.documentElement.classList.remove('site-icon-crop-open');document.body.classList.remove('site-icon-crop-open');
  if(siteIconCrop.url)URL.revokeObjectURL(siteIconCrop.url);
  if(siteIconCrop.frame)cancelAnimationFrame(siteIconCrop.frame);
  Object.assign(siteIconCrop,{file:null,image:null,width:0,height:0,url:null,stage:0,drag:null,pointers:new Map(),frame:0});
}
function siteIconHit(point){const s=siteIconCrop,edges=[[s.cropX-s.half,s.cropY-s.half],[s.cropX+s.half,s.cropY-s.half],[s.cropX-s.half,s.cropY+s.half],[s.cropX+s.half,s.cropY+s.half]];for(let i=0;i<edges.length;i++)if(Math.hypot(point.x-edges[i][0],point.y-edges[i][1])<20)return {mode:'resize',corner:i};return {mode:'pan'};}
function siteIconCanvasPoint(event){const c=document.querySelector('#site-icon-crop-canvas'),r=c.getBoundingClientRect();return{x:(event.clientX-r.left)*siteIconCrop.stage/r.width,y:(event.clientY-r.top)*siteIconCrop.stage/r.height};}
function resizeSiteIconCropTo(side){
  const s=siteIconCrop,max=Math.max(SITE_ICON_MIN_CROP,Math.min(side,s.stage-2*SITE_ICON_PAD));
  for(let length=max;length>=SITE_ICON_MIN_CROP;length-=2){if(s.scale>=siteIconMinScale(length/2)-1e-6){s.half=length/2;clampSiteIconImage();queueSiteIconDraw();return;}}
}
function renderSiteIconCrop(size=SITE_ICON_OUTPUT_SIZE){
  const s=siteIconCrop,canvas=document.createElement('canvas');canvas.width=canvas.height=size;const ctx=canvas.getContext('2d');if(!ctx||!s.image)throw new Error('裁剪图片已失效，请重新选择。');
  const k=size/(s.half*2);ctx.imageSmoothingQuality='high';ctx.save();ctx.scale(k,k);ctx.translate(-(s.cropX-s.half),-(s.cropY-s.half));ctx.translate(s.x,s.y);ctx.rotate(s.rotation*Math.PI/180);if(s.flip)ctx.scale(-1,1);ctx.scale(s.scale,s.scale);ctx.drawImage(s.image,-s.width/2,-s.height/2,s.width,s.height);ctx.restore();return canvas;
}
async function createSiteIconCropBlob(){
  const canvas=renderSiteIconCrop();let blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/webp',.84));
  if(blob?.type==='image/webp')return {blob,name:'site-icon.webp'};
  blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/png'));if(!blob)throw new Error('无法生成压缩图标，请更换浏览器后重试。');return {blob,name:'site-icon.png'};
}
async function uploadCroppedSiteIcon(button){
  if(button?.disabled||!siteIconCrop.file)return;
  const originalLabel=button.textContent;button.disabled=true;button.setAttribute('aria-busy','true');
  document.querySelectorAll('.site-icon-crop-dialog [data-action="close-modal"]').forEach(control=>control.disabled=true);
  try{
    const original=siteIconCrop.file,{blob:compressed, name:compressedName}=await createSiteIconCropBlob();
    const uploadId=Array.from(crypto.getRandomValues(new Uint8Array(16)),value=>value.toString(16).padStart(2,'0')).join(''),partSize=768*1024;
    const uploadFile=async(kind,file,name)=>{const total=Math.ceil(file.size/partSize);for(let index=0;index<total;index++){const part=file.slice(index*partSize,Math.min(file.size,(index+1)*partSize)),body=formBody({upload_id:uploadId,kind,index,total});body.set('chunk',part,`${name}.${index}.part`);await requestJSON('site_icon_chunk.php',body);const progress=document.querySelector('[data-site-icon-progress]');if(progress)progress.textContent=`正在上传${kind==='original'?'原图':'压缩图'}：${index+1}/${total}`;}};
    button.textContent='上传中…';await uploadFile('original',original,original.name);await uploadFile('compressed',compressed,compressedName);
    const progress=document.querySelector('[data-site-icon-progress]');if(progress)progress.textContent='正在合并并校验图片…';
    await requestJSON('site_icon_chunk.php',formBody({action:'finalize',upload_id:uploadId}));
    const fresh=await apiRequest('bootstrap');applySiteIcon(fresh.favicon);closeModal();toast('网站图标已更新，原图与压缩图均已保存');
  }catch(error){const progress=document.querySelector('[data-site-icon-progress]');if(progress)progress.textContent=error.message||'上传失败，请检查网络后重试。';else toast(error.message||'网站图标上传失败');}
  finally{if(button.isConnected){button.disabled=false;button.removeAttribute('aria-busy');button.textContent=originalLabel;}document.querySelectorAll('.site-icon-crop-dialog [data-action="close-modal"]').forEach(control=>control.disabled=false);}
}
async function resetSiteIcon(){
  const button=document.querySelector('[data-site-icon-reset]');if(!button||button.disabled)return;
  button.disabled=true;try{const result=await requestJSON('site_icon_reset.php',formBody());applySiteIcon(result.favicon||'assets/mark.svg');toast('已恢复默认网站图标');}catch(error){siteIconError(error.message||'恢复默认图标失败');}finally{if(button.isConnected)button.disabled=false;}
}
function chooseSiteIconFile(file){
  if(!file)return;siteIconError('');
  const ext=(file.name.split('.').pop()||'').toLowerCase(),types=['image/png','image/jpeg','image/gif','image/webp','image/x-icon','image/vnd.microsoft.icon'];
  if((!types.includes(file.type)&&!(file.type===''&&['png','jpg','jpeg','gif','webp','ico'].includes(ext))&&ext!=='ico')||file.size>SITE_ICON_MAX_BYTES){siteIconError('请选择不超过 5 MB 的 PNG、JPEG、GIF、WebP 或 ICO 图片。');return;}
  const url=URL.createObjectURL(file),image=new Image();image.onload=()=>{const {width,height}=siteIconDims(image);if(!width||!height||width>8192||height>8192){URL.revokeObjectURL(url);siteIconError('图片尺寸无效或超过 8192 像素。');return;}openSiteIconCrop(file,image,url);};image.onerror=()=>{URL.revokeObjectURL(url);siteIconError('图片无法读取，或文件已经损坏。');};image.src=url;
}

document.addEventListener('click',event=>{
  if(event.target.closest('[data-site-icon-choose]'))document.querySelector('#site-icon-file')?.click();
  if(event.target.closest('[data-site-icon-crop]'))void uploadCroppedSiteIcon(event.target.closest('[data-site-icon-crop]'));
  if(event.target.closest('[data-site-icon-reset]'))void resetSiteIcon();
  const action=event.target.closest('[data-site-icon-action]')?.dataset.siteIconAction;if(!action||!siteIconCrop.image)return;
  switch(action){case'rotate-left':siteIconCrop.rotation=(siteIconCrop.rotation-90+360)%360;clampSiteIconImage();queueSiteIconDraw();break;case'rotate-right':siteIconCrop.rotation=(siteIconCrop.rotation+90)%360;clampSiteIconImage();queueSiteIconDraw();break;case'flip':siteIconCrop.flip=!siteIconCrop.flip;event.target.closest('[data-site-icon-action]').setAttribute('aria-pressed',String(siteIconCrop.flip));queueSiteIconDraw();break;case'reset':resetSiteIconCrop();break;}
});
document.addEventListener('change',event=>{
  if(event.target.id!=='site-icon-file')return;const file=event.target.files?.[0];event.target.value='';chooseSiteIconFile(file);
});
document.addEventListener('dragover',event=>{const row=event.target.closest?.('.site-icon-control');if(!row)return;event.preventDefault();row.classList.add('is-over');});
document.addEventListener('dragleave',event=>{const row=event.target.closest?.('.site-icon-control');if(row&&!row.contains(event.relatedTarget))row.classList.remove('is-over');});
document.addEventListener('drop',event=>{const row=event.target.closest?.('.site-icon-control');if(!row)return;event.preventDefault();row.classList.remove('is-over');chooseSiteIconFile(event.dataTransfer?.files?.[0]);});
document.addEventListener('input',event=>{if(event.target.matches('[data-site-icon-zoom]'))siteIconSetZoom(Number(event.target.value));});
document.addEventListener('pointerdown',event=>{
  const canvas=event.target.closest?.('#site-icon-crop-canvas');if(!canvas||!siteIconCrop.image)return;event.preventDefault();canvas.setPointerCapture(event.pointerId);const point=siteIconCanvasPoint(event);siteIconCrop.pointers.set(event.pointerId,point);
  if(siteIconCrop.pointers.size===1){const hit=siteIconHit(point);siteIconCrop.drag={id:event.pointerId,mode:hit.mode,corner:hit.corner,start:point,x:siteIconCrop.x,y:siteIconCrop.y,half:siteIconCrop.half};}
  else if(siteIconCrop.pointers.size===2){const points=[...siteIconCrop.pointers.values()];siteIconCrop.drag={id:event.pointerId,mode:'pinch',distance:Math.hypot(points[0].x-points[1].x,points[0].y-points[1].y)};}
});
document.addEventListener('pointermove',event=>{
  const s=siteIconCrop;if(!s.image||!s.pointers.has(event.pointerId))return;const previous=s.pointers.get(event.pointerId),point=siteIconCanvasPoint(event),dx=point.x-previous.x,dy=point.y-previous.y;s.pointers.set(event.pointerId,point);const drag=s.drag;if(!drag)return;
  if(s.pointers.size>=2){const points=[...s.pointers.values()],distance=Math.hypot(points[0].x-points[1].x,points[0].y-points[1].y),center={x:(points[0].x+points[1].x)/2,y:(points[0].y+points[1].y)/2};if(drag.distance>0&&distance>0)siteIconSetZoom(Math.log(Math.max(1,s.scale/s.baseScale*distance/drag.distance))/Math.log(8)*100,center.x,center.y);drag.distance=distance;return;}
  if(drag.mode==='pan'){s.x+=dx;s.y+=dy;clampSiteIconImage();queueSiteIconDraw();}
  else if(drag.mode==='resize'){const side=2*Math.max(Math.abs(point.x-s.cropX),Math.abs(point.y-s.cropY));resizeSiteIconCropTo(side);}
});
const endSiteIconPointer=event=>{const s=siteIconCrop;if(!s.pointers.has(event.pointerId))return;s.pointers.delete(event.pointerId);if(!s.pointers.size)s.drag=null;else{const [id,point]=[...s.pointers.entries()][0];s.drag={id,mode:'pan',start:point,x:s.x,y:s.y};}};
document.addEventListener('pointerup',endSiteIconPointer);document.addEventListener('pointercancel',endSiteIconPointer);
document.addEventListener('wheel',event=>{if(!event.target.closest?.('#site-icon-crop-canvas')||!siteIconCrop.image)return;event.preventDefault();const p=siteIconCanvasPoint(event),ratio=siteIconCrop.scale/siteIconCrop.baseScale*Math.exp(-event.deltaY*.0015);siteIconSetZoom(Math.log(Math.max(1,ratio))/Math.log(8)*100,p.x,p.y);},{passive:false});
document.addEventListener('keydown',event=>{if(event.target.id!=='site-icon-crop-canvas'||!siteIconCrop.image)return;const s=siteIconCrop,step=event.shiftKey?10:2;let handled=true;switch(event.key){case'ArrowLeft':s.x-=step;break;case'ArrowRight':s.x+=step;break;case'ArrowUp':s.y-=step;break;case'ArrowDown':s.y+=step;break;case'+':case'=':siteIconSetZoom(Math.log(Math.max(1,s.scale/s.baseScale)*1.1)/Math.log(8)*100);break;case'-':case'_':siteIconSetZoom(Math.log(Math.max(1,s.scale/s.baseScale)/1.1)/Math.log(8)*100);break;default:handled=false;}if(handled){event.preventDefault();clampSiteIconImage();queueSiteIconDraw();}});
window.addEventListener('resize',resizeSiteIconCrop);
document.addEventListener('input',event=>{if(!event.target.matches('#site-name,#site-url'))return;const name=document.querySelector('[data-site-icon-tab-name]'),address=document.querySelector('[data-site-icon-address]');if(name)name.textContent=event.target.id==='site-name'?(event.target.value.trim()||'我的网站'):(document.querySelector('#site-name')?.value.trim()||'我的网站');if(address)address.textContent=event.target.id==='site-url'?((event.target.value||'https://example.com').trim().replace(/^https?:\/\//,'')||'example.com'):((document.querySelector('#site-url')?.value||'https://example.com').trim().replace(/^https?:\/\//,'')||'example.com');});
modal.addEventListener('close',()=>{if(siteIconCrop.image)discardSiteIconCrop();});
