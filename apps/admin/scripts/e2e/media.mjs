/** Real canvas pixels and MediaRecorder output with fake hardware, plus no-JS uploads and builder persistence. */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';
const here = dirname(fileURLToPath(import.meta.url));
const php = (...args) => JSON.parse(execFileSync('php', [join(here, 'media-fixture.php'), ...args], { encoding: 'utf8' }));
const fixture = php('setup');
const base = join(homedir(), '.cache/ms-playwright');
const chrome = readdirSync(base).filter((name) => /^chromium-\d+$/.test(name)).sort().pop();
let browser;
try {
  browser = await chromium.launch({ executablePath: join(base, chrome, 'chrome-linux/chrome'), args: ['--no-sandbox', '--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'] });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1200 } });
  await context.addInitScript(() => {
    window.mediaTracks = []; window.captureCalls = 0;
    const get = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
    navigator.mediaDevices.getUserMedia = async (constraints) => { window.captureCalls++; const stream = await get(constraints); window.mediaTracks.push(...stream.getTracks()); return stream; };
  });
  const page = await context.newPage(); const errors = []; page.on('pageerror', (error) => errors.push(error.message));
  const field = (id) => page.locator(`[data-swf-id="${id}"]`).first();
  await page.goto(fixture.url);
  await field('audio').locator('.swf-recorder').waitFor({ state: 'visible' });
  assert.equal(await page.evaluate(() => window.captureCalls), 0);
  await field('photo').locator('input[type=file]').setInputFiles({ name: 'red.png', mimeType: 'image/png', buffer: Buffer.from(fixture.png, 'base64') });
  await page.waitForFunction(() => document.querySelector('[data-swf-id=photo] img')?.naturalWidth === 100);
  await field('photo').locator('summary').click();
  await field('photo').getByLabel('Width (%)').fill('50');
  await field('photo').getByRole('button', { name: 'Apply crop' }).click();
  await page.waitForFunction(() => document.querySelector('[data-swf-id=photo] img')?.naturalWidth === 50);
  assert.equal(await field('photo').locator('input[type=file]').evaluate((input) => input.files[0].name), 'red-cropped.png');
  const draw = async (canvas) => {
    await canvas.scrollIntoViewIfNeeded(); const box = await canvas.boundingBox();
    await page.mouse.move(box.x + 20, box.y + 20); await page.mouse.down(); await page.mouse.move(box.x + 80, box.y + 40, { steps: 6 }); await page.mouse.up();
  };
  await draw(field('signature').locator('canvas')); await field('signature').getByRole('button', { name: 'Use signature' }).click();
  await page.waitForFunction(() => document.querySelector('[data-swf-id=signature] input').files.length === 1);
  await field('audio').getByRole('button', { name: 'Start recording' }).click();
  await field('audio').locator('.swf-media-status').filter({ hasText: 'Recording…' }).waitFor();
  await page.locator('.swf-submit').click(); await page.locator('.swf-summary').filter({ hasText: 'Please finish recording' }).waitFor();
  await page.waitForFunction(() => document.querySelector('[data-swf-id=audio] input').files.length === 1);
  assert.equal(await field('audio').locator('audio').count(), 1);
  await field('video').getByRole('button', { name: 'Start recording' }).click();
  await field('video').locator('.swf-media-status').filter({ hasText: 'Recording…' }).waitFor();
  await page.getByLabel('Show video').uncheck();
  await page.waitForFunction(() => window.mediaTracks.every((track) => track.readyState === 'ended'));
  await page.getByLabel('Show video').check();
  assert.equal(await field('video').locator('input[type=file]').evaluate((input) => input.files.length), 0);
  await field('video').getByRole('button', { name: 'Start recording' }).click();
  await page.waitForFunction(() => document.querySelector('[data-swf-id=video] input').files.length === 1);
  const rows = field('rows'); await rows.locator(':scope > .swf-row-add').click();
  const last = rows.locator('.swf-row').last();
  await last.locator('.swf-record-start').click(); await last.locator('.swf-media-status').filter({ hasText: 'Recording…' }).waitFor();
  await last.locator(':scope > .swf-row-remove').click();
  await page.waitForFunction(() => window.mediaTracks.every((track) => track.readyState === 'ended'));
  await draw(rows.locator('canvas').first()); await rows.getByRole('button', { name: 'Use signature' }).click();
  await page.waitForFunction(() => document.querySelector('[data-swf-id=initials] input').files.length === 1);
  await page.locator('.swf-submit').click();
  await page.locator('.swf-confirmation').waitFor();
  const entry = php('latest', String(fixture.form));
  assert.equal(entry.data.photo[0].name, 'red-cropped.png'); assert.equal(entry.data.signature[0].type, 'image/png');
  assert.equal(entry.data.audio[0].name, 'recording.webm'); assert.equal(entry.data.video[0].name, 'recording.webm'); assert.equal(entry.data.rows[0].initials[0].name, 'signature.png');
  const photo = await page.request.get(entry.data.photo[0].url); const photoBytes = await photo.body(); assert.equal(photoBytes.readUInt32BE(16), 50); assert.equal(photoBytes.readUInt32BE(20), 80);
  assert.equal(await page.evaluate(() => window.mediaTracks.every((track) => track.readyState === 'ended')), true);
  assert.deepEqual(errors, []); await context.close();
  console.log('PASS: Real crop pixels, drawn signatures, live audio/video encoders, busy-submit guard and track cleanup in conditions/repeaters.');

  const native = await browser.newContext({ javaScriptEnabled: false }); const fallback = await native.newPage(); await fallback.goto(fixture.url);
  for (const id of ['photo', 'signature']) await fallback.locator(`[data-swf-id=${id}] input[type=file]`).setInputFiles({ name: `${id}.png`, mimeType: 'image/png', buffer: Buffer.from(fixture.png, 'base64') });
  for (const id of ['audio', 'video']) await fallback.locator(`[data-swf-id=${id}] input[type=file]`).setInputFiles(join(here, '../../../../tests/fixtures/media', `${id}.webm`));
  await fallback.locator('.swf-submit').click(); await fallback.locator('.swf-confirmation').waitFor();
  const nativeEntry = php('latest', String(fixture.form)); assert.equal(nativeEntry.data.audio[0].name, 'audio.webm'); assert.equal(nativeEntry.data.video[0].name, 'video.webm');
  assert.deepEqual(await (await fallback.request.get(nativeEntry.data.audio[0].url)).body(), readFileSync(join(here, '../../../../tests/fixtures/media/audio.webm')));
  await native.close(); console.log('PASS: Native no-JS multipart fallback for signatures, audio and video.');

  const auth = JSON.parse(execFileSync('php', [join(here, 'admin-auth.php')], { encoding: 'utf8' }));
  const admin = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  await admin.addCookies(auth.cookies.map((cookie) => ({ ...cookie, domain: 'localhost', path: '/' }))); const builder = await admin.newPage();
  await builder.goto(`${auth.admin}#/forms/${fixture.form}`);
  for (const type of ['signature', 'audio', 'video']) { await builder.locator(`.swf-palette__item[data-type=${type}]`).click(); await builder.locator('.swf-inspector input').first().fill(`Extra ${type}`); }
  await builder.keyboard.press('Control+s'); await builder.locator('.swf-builder__state--dirty').waitFor({ state: 'hidden' }); await builder.reload();
  await builder.locator('.swf-palette__item[data-type=signature]').waitFor();
  assert.deepEqual(php('document', String(fixture.form)).fields.slice(-3).map((field) => field.type), ['signature', 'audio', 'video']);
  await admin.close(); console.log('PASS: Signature/audio/video palette and saved builder configuration.');
} finally { await browser?.close(); php('teardown', String(fixture.form), String(fixture.page)); }
