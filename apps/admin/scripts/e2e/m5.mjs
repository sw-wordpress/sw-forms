/** M5 integration: real multi-step submissions, document tools and Gutenberg's live renderer. */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';
const here = dirname(fileURLToPath(import.meta.url));
const php = (file, ...args) => JSON.parse(execFileSync('php', [join(here, file), ...args], { encoding: 'utf8' }));
const fixture = php('m5-fixture.php');
const auth = php('admin-auth.php');
const created = [fixture.form];
const cache = join(homedir(), '.cache/ms-playwright');
const chrome = readdirSync(cache).filter((name) => /^chromium-\d+$/.test(name)).sort().pop();
const browser = await chromium.launch({ executablePath: join(cache, chrome, 'chrome-linux/chrome'), args: ['--no-sandbox'] });
const context = await browser.newContext({ viewport: { width: 1600, height: 1100 } });
context.setDefaultTimeout(15000); context.setDefaultNavigationTimeout(15000);
await context.route('**/*', (route) => new URL(route.request().url()).hostname === new URL(auth.admin).hostname ? route.continue() : route.fulfill({ status: 204, body: '' }));
await context.addCookies(auth.cookies.map((cookie) => ({ ...cookie, domain: new URL(auth.admin).hostname, path: '/' })));
const page = await context.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
const entries = () => php('m5-fixture.php', 'entries', String(fixture.form)).items;
try {
  const visitor = await browser.newPage(); visitor.setDefaultTimeout(15000);
  await visitor.goto(fixture.url, { waitUntil: 'domcontentloaded' });
  await visitor.locator('.swf-step-next').click();
  await visitor.locator('[data-swf-id="quantity"].swf-field--error').waitFor();
  assert.equal(await visitor.locator('[data-swf-step="1"]').isVisible(), false);
  await visitor.getByLabel('Quantity', { exact: false }).fill('2'); await visitor.getByLabel('Price', { exact: true }).fill('3');
  await visitor.locator('.swf-step-next').click();
  await visitor.locator('[data-swf-step="1"]:visible').waitFor();
  assert.equal(await visitor.getByLabel('Total', { exact: true }).inputValue(), '6.00');
  assert.equal(entries().length, 0);
  await visitor.locator('.swf-step-back').click();
  await visitor.getByLabel('Quantity', { exact: false }).fill('4');
  await visitor.locator('.swf-step-next').click();
  await visitor.locator('[data-swf-step="1"]:visible').waitFor();
  await visitor.getByLabel('Name', { exact: false }).fill('M5 person'); await visitor.locator('.swf-submit').click();
  await visitor.locator('.swf-confirmation').waitFor();
  assert.equal((await visitor.locator('.swf-confirmation').textContent()).trim(), 'Total: 12.00');
  assert.equal(entries().length, 1);

  await page.goto(`${auth.admin}#/forms/${fixture.form}/build`, { waitUntil: 'domcontentloaded' });
  await page.locator('.swf-workspace').waitFor();
  await page.locator('.swf-list--root > .swf-el').first().click();
  await page.locator('.swf-inspector input').first().fill('Quantity updated');
  const saved = page.waitForResponse((response) => response.request().method() === 'PUT' && (new URL(response.url()).searchParams.get('rest_route') ?? new URL(response.url()).pathname).endsWith(`/forms/${fixture.form}`));
  await page.keyboard.press('Control+s'); assert.equal((await saved).status(), 200);
  await page.goto(`${auth.admin}#/forms/${fixture.form}/settings`);
  await page.getByRole('button', { name: 'Restore as draft', exact: true }).first().waitFor();
  await page.getByRole('button', { name: 'View', exact: true }).last().click();
  await page.getByLabel('Version document', { exact: true }).waitFor();
  assert.ok((await page.getByLabel('Version document', { exact: true }).inputValue()).includes('Quantity'));
  await page.keyboard.press('Escape');
  await page.getByRole('button', { name: 'Restore as draft', exact: true }).last().click();
  await page.locator('.q-dialog').getByRole('button', { name: 'Restore as draft', exact: true }).click();
  await page.locator('.swf-builder__state--dirty').waitFor();
  await page.goto(`${auth.admin}#/forms/${fixture.form}/build`);
  await page.locator('.swf-workspace').waitFor();
  assert.ok((await page.locator('.swf-el').first().textContent()).includes('Quantity'));
  await page.goto(`${auth.admin}#/`);
  await page.getByRole('button', { name: 'Templates / import', exact: true }).click();
  await page.getByRole('button', { name: 'Contact', exact: true }).click();
  await page.locator('.swf-workspace').waitFor();
  const imported = Number(/#\/forms\/(\d+)/.exec(page.url())[1]); created.push(imported);
  assert.equal(await page.locator('.swf-list--root > .swf-el').count(), 3);
  const exported = await page.evaluate(async (id) => {
    const root = window.swFormsConfig.restRoot;
    return (await fetch(root + `forms/${id}/export`, { headers: { 'X-WP-Nonce': window.swFormsConfig.nonce } })).json();
  }, imported);
  assert.equal(exported.format, 'swforms');
  await page.goto(`${auth.admin}#/`);
  await page.getByRole('button', { name: 'Templates / import', exact: true }).click();
  await page.locator('input[type="file"]').setInputFiles({ name: 'contact.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(exported)) });
  await page.locator('.swf-workspace').waitFor();
  created.push(Number(/#\/forms\/(\d+)/.exec(page.url())[1]));
  assert.equal(await page.locator('.swf-list--root > .swf-el').count(), 3);

  await page.goto(fixture.editPost, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => window.wp?.blocks?.getBlockType('swforms/form'));
  await page.evaluate((id) => { window.wp.data.dispatch('core/block-editor').resetBlocks([window.wp.blocks.createBlock('swforms/form', { formId: id })]); }, fixture.form);
  // Gutenberg may use an iframe; check its rendered content as well as the parent editor.
  await page.waitForFunction(() => {
    const documents = [document, ...[...document.querySelectorAll('iframe')].map((frame) => { try { return frame.contentDocument; } catch { return null; } }).filter(Boolean)];
    return documents.some((doc) => doc.querySelector('.swf-form'));
  });
  assert.deepEqual(errors, []);
  console.log('PASS: step validation/back navigation, authoritative calculation, versions, templates, JSON round-trip and Gutenberg live preview.');
} finally {
  await browser.close(); php('m5-fixture.php', 'cleanup', ...created.map(String));
}
