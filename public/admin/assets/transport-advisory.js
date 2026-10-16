(() => {
  const advisory = document.querySelector('#transport-advisory');
  if (!advisory) return;

  const key = 'picapi-http-advisory-dismissed';
  try {
    if (sessionStorage.getItem(key) === '1') {
      advisory.hidden = true;
      return;
    }
  } catch {}

  advisory.querySelector('[data-close-transport-advisory]')?.addEventListener('click', () => {
    advisory.hidden = true;
    try { sessionStorage.setItem(key, '1'); } catch {}
  });
})();
