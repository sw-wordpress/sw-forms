/** Enhanced input validation with AJAX, postback and repeater auxiliary controls. */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const here = dirname(fileURLToPath(import.meta.url));
const php = (...args) => JSON.parse(execFileSync('php', [join(here, 'inputs-fixture.php'), ...args], { encoding: 'utf8' }));
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
    await page.locator('input[name="swf[email]"]').fill('a@example.org');
    await page.locator('input[name="swf[email:confirm]"]').fill('wrong@example.org');
    await page.locator('input[name="swf[phone]"]').fill('30 123456');
    assert.equal(await page.locator('select[name="swf[phone:country]"]').inputValue(), '+49');
    await page.locator('input[type="password"]').fill('weak');
    const date = page.locator('input[type="date"]');
    assert.equal(await date.getAttribute('min'), fixture.min);
    await date.fill(fixture.date);
    await page.locator('input[name="swf[rows][0][nested]"]').fill('b@example.org');
    await page.locator('input[name="swf[rows][0][nested:confirm]"]').fill('wrong@example.org');
    await page.locator('.swf-submit').click();
    await page.locator('.swf-summary').waitFor({ state: 'visible' });
    assert.equal(await page.locator('.swf-summary li').count(), 3);
    await page.locator('[data-swf-id="matched"]').waitFor({ state: 'visible' });
    if (!javaScriptEnabled) assert.equal(await page.locator('input[type="password"]').inputValue(), '');
    await page.locator('input[name="swf[email:confirm]"]').fill('a@example.org');
    await page.locator('input[type="password"]').fill('Abcdefghij7!');
    await page.locator('input[name="swf[rows][0][nested:confirm]"]').fill('b@example.org');
    if (javaScriptEnabled) {
      await page.locator('[data-swf-id="rows"] .swf-row-add').click();
      const row = page.locator('[data-swf-id="rows"] .swf-rows > .swf-row').last();
      await row.locator('input[name$="[nested]"]').fill('c@example.org');
      const confirm = row.locator('input[name$="[nested:confirm]"]');
      await confirm.fill('c@example.org');
      const label = row.locator('label').filter({ hasText: 'Confirm email' });
      assert.equal(await label.getAttribute('for'), await confirm.getAttribute('id'));
    }
    await page.locator('.swf-submit').click();
    await page.locator('.swf-confirmation').waitFor({ state: 'visible' });
    assert.match(await page.locator('.swf-confirmation').textContent(), /Inputs saved/);
    assert.deepEqual(errors, []);
    await context.close();
    console.log(`PASS: Input options, phone conditions and submission (JavaScript ${javaScriptEnabled ? 'on' : 'off'}).`);
  }
  const auth = JSON.parse(execFileSync('php', [join(here, 'admin-auth.php')], { encoding: 'utf8' }));
  const admin = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await admin.addCookies(auth.cookies.map((cookie) => ({ ...cookie, domain: 'localhost', path: '/' })));
  const page = await admin.newPage();
  await page.goto(`${auth.admin}#/forms/${fixture.form}`);
  await page.locator('.swf-list--root > .swf-el').first().click();
  await page.locator('.swf-inspector .q-tab').filter({ hasText: /Validation|Prüfung/ }).click();
  const confirmationToggle = page.locator('.swf-inspector .q-toggle').filter({ hasText: /Require email confirmation|E-Mail-Bestätigung verlangen/ });
  assert.equal(await confirmationToggle.locator('.q-toggle__inner--truthy').count(), 1);
  await confirmationToggle.click();
  await page.keyboard.press('Control+s');
  await page.locator('.swf-builder__state--dirty').waitFor({ state: 'hidden' });
  await page.reload();
  await page.locator('.swf-list--root > .swf-el').first().click();
  await page.locator('.swf-inspector .q-tab').filter({ hasText: /Validation|Prüfung/ }).click();
  assert.equal(await confirmationToggle.locator('.q-toggle__inner--truthy').count(), 0);
  await page.locator('.swf-list--root > .swf-el').nth(1).click();
  await page.locator('.swf-inspector .q-tab').filter({ hasText: /Validation|Prüfung/ }).click();
  assert.equal(await page.locator('.swf-options__row').count(), 4);
  assert.equal(await page.locator('.swf-options .q-toggle').filter({ hasText: /Image URLs|Bild-URLs/ }).count(), 0);
  await admin.close();
  console.log('PASS: Builder confirmation setting persists; calling-code editor hides image controls.');
} finally {
  await browser?.close();
  php('teardown', String(fixture.form), String(fixture.page));
}
