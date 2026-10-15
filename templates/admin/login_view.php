<?php if (!isset($siteSettings)) { http_response_code(404); exit; } ?>
<!DOCTYPE html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= html($showReset ? '找回密码' : (string) $siteSettings['site_name']) ?></title><link rel="icon" href="<?= html(site_favicon_url()) ?>"><link rel="stylesheet" href="assets/auth.css?v=login-palette-3"></head>
<body data-login-mode="<?= html((string) ($siteSettings['theme_mode'] ?? 'light')) ?>" data-login-background="<?= html((string) ($siteSettings['login_background_type'] ?? 'color')) ?>" style="--login-bg-light:<?= html((string) ($siteSettings['login_background_light_color'] ?? '#F5F7FB')) ?>;--login-bg-dark:<?= html((string) ($siteSettings['login_background_dark_color'] ?? '#0F172A')) ?>;--login-wallpaper-opacity:<?= number_format((int) ($siteSettings['login_background_opacity'] ?? 55) / 100, 2, '.', '') ?>;--login-wallpaper-blur:<?= max(0, min(50, (int) ($siteSettings['login_background_blur'] ?? 0))) ?>px;--login-wallpaper-image:url('<?= html($loginBackgroundImageUrl) ?>');--login-accent-light:<?= html((string) ($siteSettings['light_theme_color'] ?? '#1974D2')) ?>;--login-accent-dark:<?= html((string) ($siteSettings['dark_theme_color'] ?? '#6366F1')) ?>;--login-surface-light:<?= html((string) ($siteSettings['light_theme_surface'] ?? '#FFFFFF')) ?>;--login-surface-dark:<?= html((string) ($siteSettings['dark_theme_surface'] ?? '#111827')) ?>;--login-ink-light:<?= html((string) ($siteSettings['light_theme_text'] ?? '#2C3B50')) ?>;--login-ink-dark:<?= html((string) ($siteSettings['dark_theme_text'] ?? '#F8FAFC')) ?>;--login-muted-light:<?= html((string) ($siteSettings['light_theme_muted'] ?? '#728198')) ?>;--login-muted-dark:<?= html((string) ($siteSettings['dark_theme_muted'] ?? '#94A3B8')) ?>"><main><section class="auth-card"><header class="auth-heading"><img src="assets/mark.svg" alt="" width="44" height="44"><h1><?= $showReset ? '找回密码' : html((string) $siteSettings['site_name']) ?></h1></header>
<?php if ($error !== null): ?><p class="notice error" role="alert"><?= html($error) ?></p><?php endif; ?>
<?php if ($notice !== null): ?><p class="notice" role="status"><?= html($notice) ?></p><?php endif; ?>
<?php if (!$showReset): ?>
<p id="login-live-status" class="notice<?= $loginIpBlocked ? ' error' : '' ?>" role="status" aria-live="polite" <?= !$loginIpBlocked && $loginRetryAfter <= 0 ? 'hidden' : '' ?>><?php if ($loginIpBlocked): ?><?= html($loginIpBlockMessage) ?><?php elseif ($loginRetryAfter > 0): ?>请在 <strong data-live-countdown><?= $loginRetryAfter ?></strong> 秒后重试。<?php endif; ?></p>
<form method="post"><input type="hidden" name="csrf" value="<?= html($loginCsrf) ?>"><input type="hidden" name="action" value="login"><div class="field"><label for="login-identity">用户名或邮箱</label><input id="login-identity" name="username" required autocomplete="username" value="<?= html($loginIdentity) ?>" <?= $loginRetryAfter > 0 ? 'disabled' : '' ?>></div><div class="field"><label for="login-password">密码</label><div class="password-field"><input id="login-password" name="password" type="password" required autocomplete="current-password" <?= $loginRetryAfter > 0 ? 'disabled' : '' ?>><button class="password-toggle" type="button" aria-label="显示密码" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></div></div><div class="auth-links"><a href="login.php?reset=1">忘记密码？</a></div><button class="primary auth-submit" type="submit" <?= $loginRetryAfter > 0 ? 'disabled' : '' ?>>登录</button></form>
<?php else: ?>
<form method="post"><input type="hidden" name="csrf" value="<?= html($loginCsrf) ?>">
<?php if ($reset === null): ?><input type="hidden" name="action" value="reset_request"><div class="field"><label for="identity">用户名或邮箱</label><input id="identity" name="identity" required autocomplete="username"></div><button class="primary auth-submit" type="submit">发送验证码</button>
<?php elseif (!$resetVerified): ?><input type="hidden" name="action" value="reset_verify"><div class="field"><label for="code">邮箱验证码</label><input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"></div><small>验证码已发送至 <?= html((string) $reset['email']) ?>，15 分钟内有效。</small><button class="primary auth-submit" type="submit">验证</button>
<?php else: ?><input type="hidden" name="action" value="reset_password"><div class="field"><label for="new-password">新密码</label><input id="new-password" name="password" type="password" required minlength="8" autocomplete="new-password"></div><div class="field"><label for="password-confirm">确认新密码</label><input id="password-confirm" name="password_confirm" type="password" required minlength="8" autocomplete="new-password"></div><button class="primary auth-submit" type="submit">保存密码</button><?php endif; ?>
</form><div class="auth-links"><a href="login.php?cancel_reset=1">返回登录</a></div><?php endif; ?>
</section></main>
<script>
document.querySelector('.password-toggle')?.addEventListener('click',function(){const input=document.querySelector('#login-password');const visible=input.type==='password';input.type=visible?'text':'password';this.setAttribute('aria-pressed',String(visible));this.setAttribute('aria-label',visible?'隐藏密码':'显示密码')});
<?php if (!$showReset): ?>
(()=>{
  const form=document.querySelector('form input[name="action"][value="login"]')?.form;
  const identity=document.querySelector('#login-identity'),password=document.querySelector('#login-password'),submit=form?.querySelector('.auth-submit');
  const countdown=document.querySelector('[data-login-unlock-countdown]');
  const channel=(()=>{try{return new BroadcastChannel('picapi-admin-live-v1')}catch{return null}})();
  let latest=null,busy=false;
  function apply(status){
    if(!status?.ok)return;
    if(status.authenticated){location.replace('dashboard.php');return;}
    const locked=Number(status.retry_after)>0||status.blocked;
    if(identity)identity.disabled=locked;if(password)password.disabled=locked;if(submit)submit.disabled=locked;
    const notice=document.querySelector('#login-live-status');
    if(status.blocked){notice.className='notice error';notice.textContent=status.permanent?'当前 IP 已被永久列入黑名单。':'当前 IP 已被临时限制登录。';notice.hidden=false;}
    else if(Number(status.retry_after)>0){notice.className='notice';notice.innerHTML='请在 <strong data-live-countdown></strong> 秒后重试。';notice.hidden=false;}
    else {notice.hidden=true;notice.textContent='';}
    const timer=notice.querySelector('[data-live-countdown]');if(timer)timer.textContent=String(Math.ceil(Number(status.retry_after)||0));
    if(countdown)countdown.textContent=String(Math.ceil(Number(status.retry_after)||0));
    latest=status;
  }
  async function refresh(){if(busy||document.hidden)return;busy=true;try{const response=await fetch('login_status.php',{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});if(response.ok)apply(await response.json());}catch{}finally{busy=false;}}
  form?.addEventListener('submit',()=>{const message={type:'login-attempt',at:Date.now()};try{channel?.postMessage(message);localStorage.setItem('picapi-admin-live-event',JSON.stringify(message));}catch{}});
  channel?.addEventListener('message',event=>{if(event.data?.type==='login-attempt'||event.data?.type==='changed')refresh();});
  window.addEventListener('storage',event=>{if(event.key==='picapi-admin-live-event')refresh();});
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
  setInterval(()=>{if(latest&&!latest.blocked){const remaining=Math.max(0,Number(latest.retry_after)-1);latest.retry_after=remaining;apply(latest);}},1000);
  setInterval(refresh,5000);refresh();
})();
<?php endif; ?>
</script>
<?php require __DIR__ . '/login_transport_view.php'; ?>
</body></html>
