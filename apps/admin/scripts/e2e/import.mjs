/** Real built-app imports from both installed source plugins; owns all fixture data. */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const here = dirname(fileURLToPath(import.meta.url));
const php = (file, ...args) => JSON.parse(execFileSync('php', [join(here, file), ...args], { encoding: 'utf8' }));
const fixture = php('import-fixture.php');
const auth = php('admin-auth.php');
const cache = join(homedir(), '.cache/ms-playwright');
const chrome = readdirSync(cache).filter((name) => /^chromium-\d+$/.test(name)).sort().pop();
const browser = await chromium.launch({ executablePath: join(cache, chrome, 'chrome-linux/chrome'), args: ['--no-sandbox'] });
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  context.setDefaultTimeout(15000);
  await context.route('**/*', (route) => new URL(route.request().url()).origin === new URL(auth.admin).origin ? route.continue() : route.fulfill({ status: 204, body: '' }));
  await context.addCookies(auth.cookies.map((cookie) => ({ ...cookie, domain: new URL(auth.admin).hostname, path: '/' })));
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  for (const [adapter, source] of Object.entries(fixture.sources)) {
    await page.goto(`${auth.admin}#/import`, { waitUntil: 'domcontentloaded' });
    await page.locator(`[data-adapter="${adapter}"]`).click();
    await page.locator('[data-source], .import-sources + p').first().waitFor();
    while (await page.locator(`[data-source="${source}"]`).count() === 0 && await page.locator('[data-import-next]').count() > 0) {
      const next = page.waitForResponse((response) => response.request().method() === 'GET' && response.url().includes(`/importers/${adapter}/forms`));
      await page.locator('[data-import-next]').click();
      await next;
      await page.locator('[data-import-next][disabled]').waitFor({ state: 'hidden' });
    }
    await page.locator(`[data-source="${source}"]`).click();
    await page.locator('[data-import-report]').waitFor();
    assert.match(await page.locator('[data-import-report]').textContent(), /email/i);
    const headingSizes = await page.locator('[data-import-report] h3').evaluateAll((headings) => headings.map((heading) => Number.parseFloat(getComputedStyle(heading).fontSize)));
    assert.ok(headingSizes.every((size) => size <= 18), 'Report headings must stay at section-heading size.');
    if (adapter === 'contact-form-7') {
      await page.locator('[data-import-report]').screenshot({ path: '/tmp/swf-import-report-desktop.png' });
      await page.setViewportSize({ width: 390, height: 844 });
      assert.equal(await page.locator('.import-report__fields').evaluate((element) => getComputedStyle(element).gridTemplateColumns.split(' ').length), 1);
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'The import report must fit a narrow screen.');
      await page.locator('[data-import-report]').screenshot({ path: '/tmp/swf-import-report-mobile.png' });
      await page.setViewportSize({ width: 1440, height: 1000 });
      await page.locator('.swf-rail__tools button').first().click();
      await page.locator('[data-import-report]').screenshot({ path: '/tmp/swf-import-report-dark.png' });
      await page.locator('.swf-rail__tools button').first().click();
    }
    const created = page.waitForResponse((response) => response.request().method() === 'POST' && response.url().includes(`/importers/${adapter}/forms/${source}`));
    await page.locator('[data-confirm-import]').click();
    const response = await created;
    assert.equal(response.status(), 201);
    const result = await response.json();
    assert.equal(result.report.document.settings.notifications.length, 0);
    assert.equal(result.report.skipped.length, 1);
    await page.locator(`[data-import-report] a[href$="/forms/${result.id}/build"]`).click();
    await page.locator('.swf-workspace').waitFor();
    const form = await page.evaluate(async (id) => {
      const response = await fetch(window.swFormsConfig.restRoot + `forms/${id}`, { headers: { 'X-WP-Nonce': window.swFormsConfig.nonce } });
      return response.json();
    }, result.id);
    assert.equal(form.status, 'inactive');
    assert.equal(form.document.fields[0].settings.inputType, 'email');
    assert.equal(form.document.fields[0].required, true);
    assert.equal(form.document.settings.notifications.length, 0);
  }
  assert.deepEqual(errors, []);
  console.log('PASS: CF7 and WPForms report, confirmation, inactive draft and builder round trip.');
} finally {
  await browser.close();
  php('import-fixture.php', 'cleanup', fixture.title);
}
