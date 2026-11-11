/** Isolated WordPress only: WP_ROOT must point at the disposable installation. */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';
const here = dirname(fileURLToPath(import.meta.url));
assert.match(process.env.WP_ROOT || '', /^\/tmp\/swf-wordpress/);
const auth = JSON.parse(execFileSync('php', [join(here, 'admin-auth.php')], { encoding: 'utf8' }));
const cache = join(homedir(), '.cache/ms-playwright');
const chrome = readdirSync(cache).filter(v => /^chromium-\d+$/.test(v)).sort().pop();
const browser = await chromium.launch({ executablePath: join(cache, chrome, 'chrome-linux/chrome'), args: ['--no-sandbox'] });
try {
  for (const due of [false, true]) {
    const context = await browser.newContext();
    await context.addCookies(auth.cookies.map(c => ({ ...c, domain: 'localhost', path: '/' })));
    const page = await context.newPage();
    const requests = [];
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    await page.addInitScript((dueFlag) => {
      Object.defineProperty(window, 'swfVerifiedUpdates', { set(value) { value.due = dueFlag; this.swfUpdateTestConfig = value; }, get() { return this.swfUpdateTestConfig; } });
    }, due);
    await page.route('**/*', async route => {
      const request = route.request();
      const data = new URLSearchParams(request.postData() || '');
      if (data.get('action') === 'swf_check_github_updates') {
        requests.push(Object.fromEntries(data));
        if (!due) await pending;
        return route.fulfill({ json: { success: true, data: { message: 'Signed metadata checked' } } });
      }
      if (new URL(request.url()).origin !== new URL(auth.admin).origin) return route.fulfill({ status: 204 });
      return route.continue();
    });
    await page.goto(new URL('plugins.php', auth.admin).href, { waitUntil: 'networkidle' });
    const button = page.locator('#swf-verified-updates-check');
    assert.equal(await button.count(), 1);
    assert.equal(await page.locator('#swf-verified-updates-status').count(), 1);
    assert.equal(await page.locator('a[href*="page=sw-forms-updates"]').count(), 0);
    if (!due) {
      assert.equal(requests.length, 0);
      await button.click();
      assert.equal(await button.isDisabled(), true);
      await button.dispatchEvent('click');
      assert.equal(requests.length, 1);
      release();
    }
    await page.waitForFunction(() => !document.getElementById('swf-verified-updates-check').disabled);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].manual, due ? '0' : '1');
    assert.equal(await page.locator('#swf-verified-updates-result').isVisible(), !due);
    assert.equal(await page.locator('#swf-verified-updates-reload').isVisible(), !due);
    assert.equal(new URL(await page.locator('#swf-verified-updates-reload').getAttribute('href')).pathname, '/wp-admin/plugins.php');
    if (!due) assert.match(await page.locator('#swf-verified-updates-result').textContent(), /Signed metadata/);
    assert.equal(await page.locator('a[href*="plugins.php"]').count() > 0, true);
    assert.equal(requests.some(r => /update-plugin|install-plugin/.test(r.action)), false);
    await context.close();
  }
  console.log('PASS: manual and deferred checks, single-flight, status and native navigation.');
} finally { await browser.close(); }
