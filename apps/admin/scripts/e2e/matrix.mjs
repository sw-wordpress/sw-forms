/** Matrix radios with and without JS, mobile overflow, repeaters and builder persistence. */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const here = dirname(fileURLToPath(import.meta.url));
const php = (...args) => JSON.parse(execFileSync('php', [join(here, 'matrix-fixture.php'), ...args], { encoding: 'utf8' }));
const base = join(homedir(), '.cache/ms-playwright');
const chrome = readdirSync(base).filter((name) => /^chromium-\d+$/.test(name)).sort().pop();
const fixture = php('setup');
let browser;
try {
  browser = await chromium.launch({ executablePath: join(base, chrome, 'chrome-linux/chrome'), args: ['--no-sandbox'] });
  for (const javaScriptEnabled of [true, false]) {
    const context = await browser.newContext({ javaScriptEnabled, viewport: { width: 390, height: 844 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(fixture.url);
    const matrix = page.locator('[data-swf-id=matrix]');
    assert.equal(await matrix.locator('input[type=radio]').count(), 10);
    const first = matrix.getByRole('radio', { name: 'Question 1 Strongly agree', exact: true });
    await first.check();
    await page.locator('.swf-submit').click();
    await page.locator('.swf-summary').waitFor({ state: 'visible' });
    assert.match(await page.locator('.swf-summary').textContent(), /every question/);
    assert.equal(await first.isChecked(), true); // AJAX and server postback both preserve answers.
    if (javaScriptEnabled) {
      await page.locator('.swf-summary a').first().click();
      assert.equal(await matrix.locator('input[type=radio]').first().evaluate((el) => el === document.activeElement), true);
    }
    const second = matrix.getByRole('radio', { name: 'Question 2 Strongly disagree', exact: true });
    await second.focus(); await second.press('ArrowRight'); // Native radio keyboard interaction.
    assert.equal(await matrix.locator('input[name="swf[matrix][question-2]"][value="2"]').isChecked(), true);
    await page.locator('[data-swf-id=note]').waitFor({ state: 'visible' });
    await page.locator('input[name="swf[note]"]').fill('Matrix condition works');
    const scroll = matrix.locator('.swf-matrix-scroll');
    assert.equal(await scroll.evaluate((el) => el.scrollWidth > el.clientWidth), true);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
    if (javaScriptEnabled) {
      await page.locator('.swf-row-add').click();
      const row = page.locator('.swf-rows > .swf-row').last();
      assert.equal(await row.locator('input[type=radio]').first().evaluate((el) => el === document.activeElement), true);
      await row.getByRole('radio', { name: 'Question 1 Agree', exact: true }).check();
    }
    await page.locator('.swf-submit').click();
    await page.locator('.swf-confirmation').waitFor({ state: 'visible' });
    const stored = php('latest', String(fixture.form));
    assert.deepEqual(stored.feedback, { 'question-1': '5', 'question-2': '2' });
    assert.equal(stored.note, 'Matrix condition works');
    if (javaScriptEnabled) assert.deepEqual(stored.rows[1].feedback, { 'question-1': '4' });
    assert.deepEqual(errors, []);
    await context.close();
    console.log(`PASS: Matrix validation, keyboard, conditional logic, mobile layout and storage (JavaScript ${javaScriptEnabled ? 'on' : 'off'}).`);
  }
  const auth = JSON.parse(execFileSync('php', [join(here, 'admin-auth.php')], { encoding: 'utf8' }));
  const context = await browser.newContext({ viewport: { width: 1600, height: 1000 } });
  await context.addCookies(auth.cookies.map((cookie) => ({ ...cookie, domain: 'localhost', path: '/' })));
  const page = await context.newPage();
  await page.goto(`${auth.admin}#/forms/${fixture.form}`);
  await page.locator('.swf-palette__item[data-type=matrix]').waitFor();
  const card = page.locator('.swf-list--root > .swf-el').first(); await card.click();
  const editor = page.locator('.swf-inspector .swf-setting-block').first();
  await editor.getByRole('textbox', { name: 'Option 1', exact: true }).fill('Service');
  await page.keyboard.press('Control+s');
  await page.locator('.swf-builder__state--dirty').waitFor({ state: 'hidden' });
  await page.reload(); await card.click();
  assert.match(await card.locator('.swf-preview__matrix').textContent(), /Service/);
  assert.equal(await editor.getByRole('textbox', { name: 'Option 1', exact: true }).inputValue(), 'Service');
  await context.close();
  console.log('PASS: Matrix palette, row label editing, preview, save and reload.');
} finally {
  await browser?.close();
  php('teardown', String(fixture.form), String(fixture.page));
}
