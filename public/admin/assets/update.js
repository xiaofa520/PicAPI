'use strict';

const updateCard=document.querySelector('#update-card');
const updateStatus=document.querySelector('#update-status');
const returnLink=document.querySelector('#return-link');

async function installLatestPicApi(){
  try{
    updateStatus.textContent='正在从官方仓库获取更新信息…';
    const body=new FormData();
    body.set('csrf',document.body.dataset.csrf||'');
    body.set('payload','{}');
    const response=await fetch('workbench_api.php?op=update-install',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body,cache:'no-store'});
    const type=response.headers.get('content-type')||'';
    if(!type.includes('application/json'))throw new Error(response.status===403?'登录验证已失效，请返回后台重新登录。':'服务器未返回有效的更新结果。');
    const data=await response.json();
    if(!response.ok||data.ok===false)throw new Error(data.message||'更新未完成。');
    if(!data.result?.updated){
      updateCard.dataset.state='success';
      updateCard.setAttribute('aria-busy','false');
      updateStatus.textContent=`当前已是最新版本（v${data.result?.latest||data.result?.current||''}）。`;
      returnLink.hidden=false;
      return;
    }
    updateCard.dataset.state='success';
    updateCard.setAttribute('aria-busy','false');
    updateStatus.textContent=`v${data.result.latest} 已安装，更新前的程序已备份。正在重新载入后台…`;
    setTimeout(()=>location.replace('index.php#settings?tab=other'),2200);
  }catch(error){
    updateCard.dataset.state='error';
    updateCard.setAttribute('aria-busy','false');
    updateStatus.textContent=error instanceof Error?error.message:'更新失败，请返回后台重试。';
    returnLink.hidden=false;
  }
}

installLatestPicApi();
