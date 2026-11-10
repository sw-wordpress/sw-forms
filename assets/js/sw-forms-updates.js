/* Backend-only deferred release checks. Installation remains in WordPress core. */
(function () {
  'use strict';
  const config = window.swfVerifiedUpdates;
  if (!config) return;
  function start() {
    let pending = null;
    function check(manual) {
      if (pending) return pending;
      const button = document.getElementById('swf-verified-updates-check');
      const result = document.getElementById('swf-verified-updates-result');
      if (manual && result) { result.hidden = false; result.textContent = config.checking; }
      if (button) button.disabled = true;
      const controller = new AbortController();
      const timer = window.setTimeout(() => controller.abort(), 90000);
      pending = fetch(config.url, {signal:controller.signal, method:'POST', credentials:'same-origin', body:new URLSearchParams({action:'swf_check_github_updates', nonce:config.nonce, manual:manual?'1':'0'})})
        .then(response => { if (!response.ok) throw new Error(config.failed); return response.json(); })
        .then(response => { if (manual && result) result.textContent = response.data?.message || config.completed; })
        .catch(() => { if (manual && result) result.textContent = config.failed; })
        .finally(() => { window.clearTimeout(timer); pending = null; if (button) button.disabled = false; });
      return pending;
    }
    document.getElementById('swf-verified-updates-check')?.addEventListener('click', () => check(true));
    if (config.due) {
      if ('requestIdleCallback' in window) window.requestIdleCallback(() => check(false), {timeout:5000});
      else window.setTimeout(() => check(false), 1500);
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once:true});
  else start();
})();
