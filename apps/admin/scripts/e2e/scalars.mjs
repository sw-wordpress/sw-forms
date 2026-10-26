/** Scalar controls, keyboard access, tag conditions and storage with/without JavaScript. */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const here = dirname(fileURLToPath(import.meta.url));
const php = (...args) => JSON.parse(execFileSync('php', [join(here, 'scalars-fixture.php'), ...args], { encoding: 'utf8' }));
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
    assert.equal(await page.locator('[data-swf-id="rating"] input:checked').count(), 0);
    await page.locator('.swf-submit').click();
    await page.locator('.swf-summary').waitFor({ state: 'visible' });
    assert.equal(await page.locator('.swf-summary li').count(), 3);
    const range = page.locator('input[type="range"]');
    await range.focus();
    await range.press('End');
    await range.press('ArrowLeft');
    assert.equal(await range.inputValue(), '9');
    if (javaScriptEnabled) assert.equal(await page.locator('output.swf-range-value').textContent(), '9');
    await page.locator('[data-swf-id="rating"] label').nth(2).click();
    const rating = page.locator('[data-swf-id="rating"] input[value="3"]');
    await rating.focus();
    await rating.press('ArrowRight');
    assert.equal(await page.locator('[data-swf-id="rating"] input[value="4"]').isChecked(), true);
    await page.locator('[data-swf-id="scale"] label').nth(6).click();
    await page.locator('textarea[name="swf[tags]"]').fill('alpha, beta\nalpha');
    if (javaScriptEnabled) {
      await page.locator('[data-swf-id="note"]').waitFor({ state: 'visible' });
      await page.locator('input[type="color"]').evaluate((input) => {
        input.value = '#12ab34';
        input.dispatchEvent(new Event('input', { bubbles: true }));
      });
      await page.locator('[data-swf-id="rows"] .swf-row-add').click();
      await page.locator('[data-swf-id="rows"] .swf-rows > .swf-row').last().locator('textarea').fill('red, blue');
    } else {
      await page.locator('.swf-submit').click();
      await page.locator('[data-swf-id="note"]').waitFor({ state: 'visible' });
      assert.equal(await page.locator('.swf-summary li').count(), 1);
    }
    await page.getByLabel('Alpha note').fill('tag condition works');
    await page.locator('.swf-submit').click();
    await page.locator('.swf-confirmation').waitFor({ state: 'visible' });
    assert.match(await page.locator('.swf-confirmation').textContent(), /Scalar fields saved/);
    const stored = php('latest', String(fixture.form));
    assert.equal(stored.range, '9');
    assert.equal(stored.rating, '4');
    assert.equal(stored.scale, '7');
    assert.equal(stored.color, javaScriptEnabled ? '#12ab34' : '#336699');
    assert.deepEqual(stored.tags, ['alpha', 'beta']);
    if (javaScriptEnabled) assert.deepEqual(stored.rows[1].nested, ['red', 'blue']);
    assert.deepEqual(errors, []);
    await context.close();
    console.log(`PASS: Scalar controls, conditions and storage (JavaScript ${javaScriptEnabled ? 'on' : 'off'}).`);
  }
  const auth = JSON.parse(execFileSync('php', [join(here, 'admin-auth.php')], { encoding: 'utf8' }));
  const admin = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await admin.addCookies(auth.cookies.map((cookie) => ({ ...cookie, domain: 'localhost', path: '/' })));
  const page = await admin.newPage();
  await page.goto(`${auth.admin}#/forms/${fixture.form}`);
  await page.locator('.swf-palette__item[data-type="range"]').waitFor();
  for (const type of ['range', 'rating', 'scale', 'color', 'tags']) {
    assert.equal(await page.locator(`.swf-palette__item[data-type="${type}"]`).count(), 1);
  }
  await page.locator('.swf-list--root > .swf-el').first().click();
  const defaultValue = page.locator('.swf-inspector input[type="number"]').first();
  await defaultValue.fill('5');
  await page.keyboard.press('Control+s');
  await page.locator('.swf-builder__state--dirty').waitFor({ state: 'hidden' });
  await page.reload();
  await page.locator('.swf-list--root > .swf-el').first().click();
  assert.equal(await defaultValue.inputValue(), '5');
  assert.equal(await page.locator('.swf-list--root > .swf-el').first().locator('input[type="range"]').inputValue(), '5');
  await admin.close();
  console.log('PASS: New palette types and slider default editing/saving/reload.');
} finally {
  await browser?.close();
  php('teardown', String(fixture.form), String(fixture.page));
}
