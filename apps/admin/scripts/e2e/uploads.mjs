/** Real multipart transport, limits, conditional/repeated files, downloads and entry cleanup. */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';
const here = dirname(fileURLToPath(import.meta.url));
const php = (...args) => JSON.parse(execFileSync('php', [join(here, 'uploads-fixture.php'), ...args], { encoding: 'utf8' }));
const fixture = php('setup');
const baseline = php('count');
const base = join(homedir(), '.cache/ms-playwright');
const chrome = readdirSync(base).filter((name) => /^chromium-\d+$/.test(name)).sort().pop();
const payload = (name, text = 'plain text upload') => ({ name, mimeType: 'text/plain', buffer: Buffer.from(text) });
let browser;
let admin;
let nonce;
try {
  browser = await chromium.launch({ executablePath: join(base, chrome, 'chrome-linux/chrome'), args: ['--no-sandbox'] });
  const auth = JSON.parse(execFileSync('php', [join(here, 'admin-auth.php')], { encoding: 'utf8' }));
  admin = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await admin.addCookies(auth.cookies.map((cookie) => ({ ...cookie, domain: 'localhost', path: '/' })));
  const manager = await admin.newPage();
  manager.on('pageerror', (error) => console.error('ADMIN:', error.message));
  manager.on('requestfailed', (request) => console.error('ADMIN request:', request.url(), request.failure()?.errorText));
  await manager.goto(`${auth.admin}#/forms/${fixture.form}`);
  await manager.locator('.swf-palette__item[data-type="file"]').waitFor();
  nonce = await manager.evaluate(() => window.swFormsConfig.nonce);
  // Add and configure a new field through the real builder, then preserve it after reload.
  const roots = manager.locator('.swf-list--root > .swf-el');
  const original = await roots.count();
  await manager.locator('.swf-palette__item[data-type="file"]').click();
  await manager.locator('.swf-inspector input').first().fill('Extra documents');
  await manager.locator('.swf-inspector .q-toggle').filter({ hasText: 'Allow multiple files' }).click();
  await manager.keyboard.press('Control+s');
  await manager.locator('.swf-builder__state--dirty').waitFor({ state: 'hidden' });
  await manager.reload();
  await manager.locator('.swf-palette__item[data-type="file"]').waitFor();
  await manager.waitForFunction((count) => document.querySelectorAll('.swf-list--root > .swf-el').length === count, original + 1);
  const document = php('document', String(fixture.form));
  assert.equal(document.fields.at(-1).type, 'file');
  assert.equal(document.fields.at(-1).settings.multiple, true);
  assert.equal(document.fields.at(-1).label, 'Extra documents');
  console.log('PASS: Upload palette, multiple setting and save/reload.');
  for (const javaScriptEnabled of [true, false]) {
    const context = await browser.newContext({ javaScriptEnabled });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const single = page.locator('input[name="swf[single][]"]');
    const multi = page.locator('input[name="swf[multi][]"]');
    const submitInvalid = async (field) => {
      await Promise.all([javaScriptEnabled ? page.waitForResponse((r) => r.url().endsWith('/submit') && r.request().method() === 'POST') : page.waitForNavigation(), page.locator('.swf-submit').click()]);
      await page.locator(`[data-swf-id="${field}"].swf-field--error`).waitFor();
      assert.equal(php('count'), baseline, 'failed submissions never persist files');
    };
    await page.goto(fixture.url);
    assert.equal(await page.locator('form').getAttribute('enctype'), 'multipart/form-data');
    await submitInvalid('single');
    await single.setInputFiles(payload('fake.pdf', 'This is plain text, not a PDF.'));
    await submitInvalid('single');
    await single.setInputFiles(payload('large.txt', 'x'.repeat(1024 * 1024 + 1)));
    await submitInvalid('single');
    await single.setInputFiles(payload('consent.txt'));
    await multi.setInputFiles([payload('one.txt'), payload('two.txt'), payload('three.txt')]);
    if (javaScriptEnabled) await page.getByLabel('Consent note').fill('Agreed');
    await submitInvalid('multi');
    // Native postback clears file controls; AJAX preserves selection for correction.
    await single.setInputFiles(payload('consent.txt'));
    await page.getByLabel('Consent note').fill('Agreed');
    if (javaScriptEnabled) {
      await multi.evaluate((input) => {
        const transfer = new DataTransfer();
        transfer.items.add(new File(['dropped one'], 'one.txt', { type: 'text/plain' }));
        transfer.items.add(new File(['dropped two'], 'two.txt', { type: 'text/plain' }));
        input.parentElement.dispatchEvent(new DragEvent('drop', { bubbles: true, dataTransfer: transfer }));
      });
    } else {
      await multi.setInputFiles([payload('one.txt'), payload('two.txt')]);
    }
    await page.locator('input[name="swf[hidden][]"]').setInputFiles(payload('malicious.php', '<?php echo "bad";'));
    const rows = page.locator('[data-swf-id="rows"]');
    await rows.locator('input[type=file]').first().setInputFiles(payload('row.txt', 'row data'));
    if (javaScriptEnabled) {
      await rows.locator('.swf-row-add').click();
      await rows.locator('.swf-row').last().locator('input[type=file]').setInputFiles(payload('removed.txt'));
      await rows.locator('.swf-row').last().locator('.swf-row-remove').click();
      await rows.locator('.swf-row-add').click();
      await rows.locator('.swf-row').last().locator('input[type=file]').setInputFiles(payload('second-row.txt'));
    }
    if (javaScriptEnabled) {
      const [response] = await Promise.all([page.waitForResponse((r) => r.url().endsWith('/submit') && r.request().method() === 'POST'), page.locator('.swf-submit').click()]);
      assert.equal(response.status(), 200, await response.text());
    } else {
      await Promise.all([page.waitForNavigation(), page.locator('.swf-submit').click()]);
      if (await page.locator('.swf-summary:visible').count()) assert.fail(await page.locator('.swf-summary').textContent());
    }
    await page.locator('.swf-confirmation').waitFor();
    const entry = php('latest', String(fixture.form));
    assert.equal(entry.data.single[0].name, 'consent.txt');
    assert.equal(entry.data.single[0].type, 'text/plain');
    assert.equal(entry.data.single[0].size, Buffer.byteLength('plain text upload'));
    assert.equal(entry.data.multi.length, 2);
    assert.equal(entry.data.note, 'Agreed');
    assert.equal(entry.data.rows[0].attachment[0].name, 'row.txt');
    assert.equal('hidden' in entry.data, false);
    if (javaScriptEnabled) assert.equal(entry.data.rows[1].attachment[0].name, 'second-row.txt');
    const urls = [entry.data.single[0].url, ...entry.data.multi.map((file) => file.url), ...entry.data.rows.flatMap((row) => row.attachment.map((file) => file.url))];
    assert.equal(php('count'), baseline + urls.length);
    for (const url of urls) {
      assert.match(url, /\/swforms\/swf-[a-f0-9]{32}\.txt$/);
      assert.equal((await page.request.get(url)).status(), 200);
    }
    assert.ok(entry.fields.find((field) => field.id === 'single').files[0].url);
    const patch = await admin.request.patch(`${fixture.rest}${entry.id}`, { headers: { 'X-WP-Nonce': nonce }, data: { status: 'trash' } });
    assert.equal(patch.ok(), true);
    assert.equal((await page.request.get(urls[0])).status(), 200, 'trash retains files');
    const deletion = await admin.request.delete(`${fixture.rest}${entry.id}`, { headers: { 'X-WP-Nonce': nonce } });
    assert.equal(deletion.ok(), true);
    assert.equal(php('count'), baseline);
    assert.equal((await page.request.get(urls[0])).status(), 404, 'permanent deletion removes files');
    assert.deepEqual(errors, []);
    await context.close();
    console.log(`PASS: Multipart validation, conditions, repeaters, downloads and cleanup (JavaScript ${javaScriptEnabled ? 'on' : 'off'}).`);
  }
  // Preview checks real HTTP files without moving them, even with valid image content.
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6zQAAAABJRU5ErkJggg==', 'base64');
  const preview = await admin.request.post(`http://localhost/wp-json/swforms/v1/forms/${fixture.form}/submit`, {
    headers: { 'X-WP-Nonce': nonce }, multipart: { swf_preview: '1', 'swf[single][]': { name: 'pixel.png', mimeType: 'image/png', buffer: png }, 'swf[rows][0][attachment][]': payload('preview-row.txt') },
  });
  const previewResult = await preview.json();
  assert.equal(previewResult.code, 'preview', JSON.stringify(previewResult));
  assert.equal(php('count'), baseline, 'preview never persists files');
  const imageMismatch = await admin.request.post(`http://localhost/wp-json/swforms/v1/forms/${fixture.form}/submit`, {
    headers: { 'X-WP-Nonce': nonce }, multipart: { swf_preview: '1', 'swf[single][]': { name: 'wrong.jpg', mimeType: 'image/jpeg', buffer: png } },
  });
  assert.equal(imageMismatch.status(), 422);
  assert.ok((await imageMismatch.json()).errors.single);
  // Exceed PHP post_max_size: neither transport may accept the emptied request as valid.
  const huge = { name: 'huge.txt', mimeType: 'text/plain', buffer: Buffer.alloc(9 * 1024 * 1024, 'x') };
  const oversized = await admin.request.post(`http://localhost/wp-json/swforms/v1/forms/${fixture.form}/submit`, { headers: { 'X-WP-Nonce': nonce }, multipart: { 'swf[single][]': huge } });
  assert.equal((await oversized.json()).code, 'upload_size');
  const postback = await admin.request.post(`${fixture.url}?swf_form=${fixture.form}`, { multipart: { 'swf[single][]': huge } });
  assert.match(await postback.text(), /submission exceeds the server upload limit/);
  assert.equal(php('count'), baseline);
  console.log('PASS: Real-image preview without storage; discarded oversized POST is rejected through REST and postback.');
} finally {
  if (admin && nonce) await admin.request.delete(`http://localhost/wp-json/swforms/v1/forms/${fixture.form}`, { headers: { 'X-WP-Nonce': nonce } }).catch(() => {});
  if (browser) await browser.close();
  php('teardown', String(fixture.form), String(fixture.page));
}
