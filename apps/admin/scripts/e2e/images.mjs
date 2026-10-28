/** Image previews, URL lifecycle, actual multipart storage, no-JS and palette configuration. */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';
const here = dirname(fileURLToPath(import.meta.url));
const php = (...args) => JSON.parse(execFileSync('php', [join(here, 'images-fixture.php'), ...args], { encoding: 'utf8' }));
const fixture = php('setup');
const png = Buffer.from(fixture.png, 'base64');
const file = (name) => ({ name, mimeType: 'image/png', buffer: png });
const base = join(homedir(), '.cache/ms-playwright');
const chrome = readdirSync(base).filter((name) => /^chromium-\d+$/.test(name)).sort().pop();
let browser;
try {
  browser = await chromium.launch({ executablePath: join(base, chrome, 'chrome-linux/chrome'), args: ['--no-sandbox'] });
  for (const javaScriptEnabled of [true, false]) {
    const context = await browser.newContext({ javaScriptEnabled });
    await context.addInitScript(() => {
      const create = URL.createObjectURL.bind(URL);
      const revoke = URL.revokeObjectURL.bind(URL);
      window.imageURLs = { created: [], revoked: [] };
      const images = new Set();
      URL.createObjectURL = (blob) => { const url = create(blob); if (blob instanceof File && blob.type.startsWith('image/')) { images.add(url); window.imageURLs.created.push(url); } return url; };
      URL.revokeObjectURL = (url) => { if (images.has(url)) window.imageURLs.revoked.push(url); revoke(url); };
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(fixture.url);
    const photo = page.locator('[data-swf-id="photo"] input');
    const images = page.locator('[data-swf-id="photo"] .swf-image-previews img');
    assert.equal(await photo.getAttribute('capture'), 'environment');
    assert.match(await photo.getAttribute('accept'), /image\/jpeg/);
    await page.locator('.swf-submit').click();
    await page.locator('[data-swf-id="photo"].swf-field--error').waitFor();
    await photo.setInputFiles({ name: 'fake.png', mimeType: 'text/plain', buffer: Buffer.from('not an image') });
    await Promise.all([javaScriptEnabled ? page.waitForResponse((r) => r.url().endsWith('/submit')) : page.waitForNavigation(), page.locator('.swf-submit').click()]);
    await page.locator('[data-swf-id="photo"].swf-field--error').waitFor();
    await photo.setInputFiles(file('red.png'));
    if (javaScriptEnabled) {
      await page.waitForFunction(() => document.querySelector('[data-swf-id="photo"] img')?.naturalWidth === 2);
      assert.equal(await images.getAttribute('alt'), 'red.png');
      assert.equal(await page.evaluate(() => window.imageURLs.created.length), 1, JSON.stringify(await page.evaluate(() => window.imageURLs)));
      await photo.setInputFiles(file('other.png'));
      await photo.setInputFiles(file('red.png'));
      assert.equal(await page.evaluate(() => window.imageURLs.revoked.length), 2);
      await page.locator('form').evaluate((form) => form.reset());
      await images.waitFor({ state: 'detached' });
      assert.equal(await photo.evaluate((input) => input.files.length), 0);
      assert.equal(await page.locator('[data-swf-id=note]').isHidden(), true);
      await photo.setInputFiles(file('red.png'));
      await page.getByLabel('Photo note').fill('red image');
    } else {
      await page.locator('.swf-submit').click();
      await page.getByLabel('Photo note').waitFor();
      assert.equal(await page.locator('.swf-image-previews img').count(), 0);
      await photo.setInputFiles(file('red.png'));
      await page.getByLabel('Photo note').fill('red image');
    }
    await page.locator('[data-swf-id="gallery"] input').setInputFiles([file('one.png'), file('two.png')]);
    const rows = page.locator('[data-swf-id="rows"]');
    await rows.locator('input[type=file]').first().setInputFiles(file('first.png'));
    if (javaScriptEnabled) {
      await rows.locator('.swf-row-add').click();
      await rows.locator('.swf-row').last().locator('input[type=file]').setInputFiles(file('removed.png'));
      await rows.locator('.swf-row').last().locator('.swf-row-remove').click();
      await rows.locator('.swf-row-add').click();
      await rows.locator('.swf-row').last().locator('input[type=file]').setInputFiles(file('second.png'));
      await page.waitForFunction(() => [...document.querySelectorAll('.swf-image-previews img')].every((img) => img.naturalWidth === 2));
      assert.equal(await page.locator('.swf-image-previews img').count(), 5);
    }
    await page.locator('.swf-submit').click();
    await page.locator('.swf-confirmation').waitFor();
    const entry = php('latest', String(fixture.form));
    assert.equal(entry.data.photo[0].type, 'image/png');
    assert.equal(entry.data.photo[0].size, png.length);
    assert.equal(entry.data.gallery.length, 2);
    assert.equal(entry.data.rows[0].portrait[0].name, 'first.png');
    if (javaScriptEnabled) {
      assert.equal(entry.data.rows[1].portrait[0].name, 'second.png');
      assert.equal(await page.evaluate(() => window.imageURLs.created.length === window.imageURLs.revoked.length), true);
    }
    const downloaded = await page.request.get(entry.data.photo[0].url);
    assert.equal(downloaded.status(), 200);
    assert.deepEqual(await downloaded.body(), png);
    assert.deepEqual(errors, []);
    await context.close();
    console.log(`PASS: Images, previews, capture attribute, repeaters and original stored bytes (JavaScript ${javaScriptEnabled ? 'on' : 'off'}).`);
  }
  const auth = JSON.parse(execFileSync('php', [join(here, 'admin-auth.php')], { encoding: 'utf8' }));
  const admin = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await admin.addCookies(auth.cookies.map((cookie) => ({ ...cookie, domain: 'localhost', path: '/' })));
  const page = await admin.newPage();
  await page.goto(`${auth.admin}#/forms/${fixture.form}`);
  await page.locator('.swf-palette__item[data-type="image"]').waitFor();
  await page.locator('.swf-palette__item[data-type="image"]').click();
  await page.locator('.swf-inspector input').first().fill('Extra photo');
  await page.locator('.swf-inspector .q-toggle').filter({ hasText: 'Show image previews' }).click();
  await page.locator('.swf-inspector .q-select').filter({ hasText: 'Camera preference' }).locator('.q-field__control').click();
  await page.getByRole('option', { name: 'Front camera' }).click();
  await page.keyboard.press('Control+s');
  await page.locator('.swf-builder__state--dirty').waitFor({ state: 'hidden' });
  await page.reload();
  await page.locator('.swf-palette__item[data-type="image"]').waitFor();
  const document = php('document', String(fixture.form));
  assert.equal(document.fields.at(-1).type, 'image');
  assert.equal(document.fields.at(-1).settings.capture, 'user');
  assert.equal(document.fields.at(-1).settings.preview, false);
  assert.equal(document.fields.at(-1).label, 'Extra photo');
  await admin.close();
  console.log('PASS: Image palette, camera preference, preview toggle and save/reload.');
} finally {
  await browser?.close();
  php('teardown', String(fixture.form), String(fixture.page));
}
