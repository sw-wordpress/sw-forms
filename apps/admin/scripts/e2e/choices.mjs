/** Choice presentations and searchable selects, with and without JavaScript. */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const here = dirname(fileURLToPath(import.meta.url));
const php = (...args) => JSON.parse(execFileSync('php', [join(here, 'choice-fixture.php'), ...args], { encoding: 'utf8' }));
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
    if (javaScriptEnabled) {
      await page.locator('.swf-submit').click(); await page.locator('.swf-summary a[href$="search-err"]').click();
      assert.equal(await page.locator('[data-swf-id=search] .ts-control input').evaluate((input) => input === document.activeElement), true);
    }
    const search = page.locator('[data-swf-id="search"] .ts-control input');
    assert.equal(await search.isVisible(), javaScriptEnabled);
    const toggle = page.getByRole('switch', { name: /Consent switch/ });
    await toggle.focus();
    await toggle.press('Space');
    assert.equal(await toggle.isChecked(), true);
    await page.locator('[data-swf-id="buttons"] label').filter({ hasText: 'Alpha' }).click();
    const radio = page.locator('[data-swf-id="buttons"] input[value="a"]');
    assert.equal(await radio.isChecked(), true);
    await radio.focus();
    await radio.press('ArrowRight');
    assert.equal(await page.locator('[data-swf-id="buttons"] input[value="b"]').isChecked(), true);
    await page.locator('[data-swf-id="images"] label').filter({ hasText: 'Alpha' }).click();
    assert.equal(await page.locator('.swf-option-image').count(), 1);
    const select = page.locator('[data-swf-id="search"] select');
    if (javaScriptEnabled) {
      await search.fill('Alpha');
      await page.locator('[data-swf-id=search] .ts-dropdown [data-value="b"]').waitFor({ state: 'detached' });
      await page.locator('[data-swf-id=search] .ts-dropdown [data-value="a"]').waitFor({ state: 'visible' });
      await search.press('Enter');
    } else await select.selectOption(['a']);
    if (javaScriptEnabled) {
      await search.fill('Beta');
      await page.locator('[data-swf-id=search] .ts-dropdown [data-value="c"]').waitFor({ state: 'detached' });
      await page.locator('[data-swf-id=search] .ts-dropdown [data-value="b"]').waitFor({ state: 'visible' });
      assert.equal(await page.locator('[data-swf-id=search] .ts-dropdown [data-value="c"]').count(), 0);
      assert.deepEqual(await select.evaluate((el) => Array.from(el.selectedOptions).map((option) => option.value)), ['a']);
      await search.press('Enter');
      await search.fill('missing');
      assert.match(await page.locator('[data-swf-id="search"] .no-results').textContent(), /No matching/);
      await search.fill('');
      await search.focus(); await search.press('ArrowDown');
      assert.equal(await select.evaluate((el) => Array.from(el.selectedOptions).map((option) => option.value).join(',')), 'a,b');
      await page.locator('[data-swf-id="rows"] .swf-row-add').click();
      const row = page.locator('[data-swf-id="rows"] .swf-rows > .swf-row').last();
      const nestedSearch = row.locator('.ts-control input');
      assert.equal(await nestedSearch.evaluate((input) => input === document.activeElement), true);
      assert.equal(await nestedSearch.isVisible(), true);
      assert.equal(await nestedSearch.getAttribute('aria-controls'), await row.locator('.ts-dropdown-content').getAttribute('id'));
      await nestedSearch.fill('Gamma');
      await row.locator('.ts-dropdown [data-value="a"]').waitFor({ state: 'detached' });
      await row.locator('.ts-dropdown [data-value="c"]').waitFor({ state: 'visible' });
      assert.equal(await row.locator('.ts-dropdown [data-value="a"]').count(), 0);
      await nestedSearch.press('Enter');
      assert.equal(await row.locator('select').inputValue(), 'c');
    }
    await page.locator('.swf-submit').click();
    await page.locator('.swf-confirmation').waitFor({ state: 'visible' });
    assert.match(await page.locator('.swf-confirmation').textContent(), /Choices saved/);
    assert.deepEqual(errors, []);
    await context.close();
    console.log(`PASS: Choice controls and submission (JavaScript ${javaScriptEnabled ? 'on' : 'off'}).`);
  }
  const auth = JSON.parse(execFileSync('php', [join(here, 'admin-auth.php')], { encoding: 'utf8' }));
  const admin = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await admin.addCookies(auth.cookies.map((cookie) => ({ ...cookie, domain: 'localhost', path: '/' })));
  const page = await admin.newPage();
  await page.goto(`${auth.admin}#/forms/${fixture.form}`);
  await page.locator('.swf-list--root > .swf-el').nth(2).click();
  const image = page.locator('.swf-options__image input').first();
  const imageUrl = new URL('/wp-includes/images/blank.gif?updated', fixture.url).href;
  await image.fill(imageUrl);
  await page.keyboard.press('Control+s');
  await page.locator('.swf-builder__state--dirty').waitFor({ state: 'hidden' });
  await page.reload();
  await page.locator('.swf-list--root > .swf-el').nth(2).click();
  assert.equal(await page.locator('.swf-options__image input').first().inputValue(), imageUrl);
  // Existing presentation survives an unrelated inspector edit and reload.
  assert.equal(await page.locator('.swf-list--root > .swf-el').nth(2).locator('.swf-preview__option-image').count(), 1);
  await admin.close();
  console.log('PASS: Builder image editing, saving and reload.');
} finally {
  await browser?.close();
  php('teardown', String(fixture.form), String(fixture.page));
}
