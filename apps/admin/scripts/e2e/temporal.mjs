/** Birthdates and intervals through AJAX, postback, repeaters and the builder. */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const here = dirname(fileURLToPath(import.meta.url));
const php = (...args) => JSON.parse(execFileSync('php', [join(here, 'temporal-fixture.php'), ...args], { encoding: 'utf8' }));
const base = join(homedir(), '.cache/ms-playwright');
const chrome = readdirSync(base).filter((name) => /^chromium-\d+$/.test(name)).sort().pop();
const fixture = php('setup');
let browser;
try {
  browser = await chromium.launch({ executablePath: join(base, chrome, 'chrome-linux/chrome'), args: ['--no-sandbox'] });
  for (const javaScriptEnabled of [true, false]) {
    const context = await browser.newContext({ javaScriptEnabled });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(fixture.url);
    await page.locator('input[name="swf[birth]"]').fill(fixture.tooYoung);
    const dates = page.locator('[data-swf-id="dates"]');
    const times = page.locator('[data-swf-id="times"]');
    await dates.locator('[data-swf-interval="from"]').fill(fixture.to);
    await dates.locator('[data-swf-interval="to"]').fill(fixture.from);
    await times.locator('[data-swf-interval="from"]').fill('09:00');
    await page.locator('.swf-submit').click();
    await page.locator('.swf-summary').waitFor({ state: 'visible' });
    assert.equal(await page.locator('.swf-summary li').count(), 4); // age, reversed dates, partial time, conditional note
    await page.locator('input[name="swf[birth]"]').fill(fixture.birth);
    await dates.locator('[data-swf-interval="from"]').fill(fixture.from);
    await dates.locator('[data-swf-interval="to"]').fill(fixture.to);
    await times.locator('[data-swf-interval="to"]').fill('10:30');
    await page.locator('[data-swf-id="note"]').waitFor({ state: 'visible' });
    await page.locator('input[name="swf[note]"]').fill('pair condition works');
    if (javaScriptEnabled) {
      await page.locator('[data-swf-id="rows"] .swf-row-add').click();
      const row = page.locator('[data-swf-id="rows"] .swf-rows > .swf-row').last();
      await row.getByLabel('From', { exact: true }).fill('11:00');
      await row.getByLabel('To', { exact: true }).fill('12:00');
      assert.equal(await row.getByLabel('From', { exact: true }).getAttribute('id'), await row.locator('label').filter({ hasText: /^From$/ }).getAttribute('for'));
    }
    await page.locator('.swf-submit').click();
    await page.locator('.swf-confirmation').waitFor({ state: 'visible' });
    assert.match(await page.locator('.swf-confirmation').textContent(), /Temporal fields saved/);
    const stored = php('latest', String(fixture.form));
    assert.equal(stored.birth, fixture.birth);
    assert.deepEqual(stored.dates, [fixture.from, fixture.to]);
    assert.deepEqual(stored.times, ['09:00', '10:30']);
    if (javaScriptEnabled) assert.deepEqual(stored.rows[1].nested, ['11:00', '12:00']);
    assert.deepEqual(errors, []);
    await context.close();
    console.log(`PASS: Temporal validation, conditions and storage (JavaScript ${javaScriptEnabled ? 'on' : 'off'}).`);
  }
  const auth = JSON.parse(execFileSync('php', [join(here, 'admin-auth.php')], { encoding: 'utf8' }));
  const admin = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await admin.addCookies(auth.cookies.map((cookie) => ({ ...cookie, domain: 'localhost', path: '/' })));
  const page = await admin.newPage();
  await page.goto(`${auth.admin}#/forms/${fixture.form}`);
  await page.locator('.swf-palette__item[data-type="birthdate"]').waitFor();
  for (const type of ['birthdate', 'date-interval', 'time-interval']) assert.equal(await page.locator(`.swf-palette__item[data-type="${type}"]`).count(), 1);
  await page.locator('.swf-list--root > .swf-el').nth(1).click();
  const start = page.locator('.swf-inspector').getByLabel('Default start', { exact: true });
  await start.fill(fixture.from);
  await page.keyboard.press('Control+s');
  await page.locator('.swf-builder__state--dirty').waitFor({ state: 'hidden' });
  await page.reload();
  await page.locator('.swf-list--root > .swf-el').nth(1).click();
  assert.equal(await start.inputValue(), fixture.from);
  assert.equal(await page.locator('.swf-list--root > .swf-el').nth(1).locator('.swf-preview__interval input').first().inputValue(), fixture.from);
  await admin.close();
  console.log('PASS: Temporal palette and endpoint default editing/saving/reload.');
} finally {
  await browser?.close();
  php('teardown', String(fixture.form), String(fixture.page));
}
