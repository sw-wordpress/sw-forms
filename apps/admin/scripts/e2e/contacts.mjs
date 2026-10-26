/** Contact presets through AJAX/postback, repeaters and real palette creation/duplication. */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const here = dirname(fileURLToPath(import.meta.url));
const php = (...args) => JSON.parse(execFileSync('php', [join(here, 'contacts-fixture.php'), ...args], { encoding: 'utf8' }));
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
    const person = page.locator('[data-swf-id="person"]');
    const address = page.locator('[data-swf-id="location"]');
    const people = page.locator('[data-swf-id="people"]');
    assert.equal(await address.isHidden(), true);
    await page.locator('.swf-submit').click();
    await page.locator('.swf-summary').waitFor({ state: 'visible' });
    assert.equal(await page.locator('.swf-summary li').count(), 4);
    await person.locator('[autocomplete="given-name"]').fill('Ada');
    await person.locator('[autocomplete="family-name"]').fill('Lovelace');
    await people.locator('[autocomplete="given-name"]').fill('Grace');
    await people.locator('[autocomplete="family-name"]').fill('Hopper');
    await page.getByLabel('Include address').check();
    if (!javaScriptEnabled) {
      await page.locator('.swf-submit').click();
      await address.waitFor({ state: 'visible' });
      assert.equal(await page.locator('.swf-summary li').count(), 3);
    } else {
      await address.waitFor({ state: 'visible' });
      await people.locator('.swf-row-add').click();
      const row = people.locator('.swf-rows > .swf-row').last();
      await row.locator('[autocomplete="given-name"]').fill('Second');
      await row.locator('[autocomplete="family-name"]').fill('Person');
      const domId = await row.locator('[autocomplete="given-name"]').getAttribute('id');
      assert.equal(await row.locator(`label[for="${domId}"]`).count(), 1);
    }
    await address.locator('[autocomplete="address-line1"]').fill('Main Street');
    await address.getByLabel('House number').fill('12A');
    await address.locator('[autocomplete="postal-code"]').fill('00123');
    await address.locator('[autocomplete="address-level2"]').fill('Berlin');
    await address.locator('[autocomplete="country-name"]').fill('Deutschland');
    if (!javaScriptEnabled) {
      await page.locator('.swf-submit').click();
      await page.locator('[data-swf-id="note"]').waitFor({ state: 'visible' });
      assert.equal(await page.locator('.swf-summary li').count(), 1);
    } else {
      await page.locator('[data-swf-id="note"]').waitFor({ state: 'visible' });
    }
    await page.locator('input[name="swf[note]"]').fill('component condition works');
    await page.locator('.swf-submit').click();
    await page.locator('.swf-confirmation').waitFor({ state: 'visible' });
    const stored = php('latest', String(fixture.form));
    assert.equal(stored.person.given_name, 'Ada');
    assert.equal(stored.location.postal_code, '00123');
    assert.equal(stored.location.house_number, '12A');
    assert.equal(stored.people[0].family_name, 'Hopper');
    if (javaScriptEnabled) assert.equal(stored.people[1].given_name, 'Second');
    assert.deepEqual(errors, []);
    await context.close();
    console.log(`PASS: Contact validation, conditions, repeaters and storage (JavaScript ${javaScriptEnabled ? 'on' : 'off'}).`);
  }
  const auth = JSON.parse(execFileSync('php', [join(here, 'admin-auth.php')], { encoding: 'utf8' }));
  const admin = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await admin.addCookies(auth.cookies.map((cookie) => ({ ...cookie, domain: 'localhost', path: '/' })));
  const page = await admin.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto(`${auth.admin}#/forms/${fixture.form}`);
  await page.locator('.swf-palette__item[data-type="name"]').waitFor();
  const roots = page.locator('.swf-list--root > .swf-el');
  const originalCount = await roots.count();
  await page.locator('.swf-palette__item[data-type="name"]').click();
  const name = roots.nth(originalCount);
  const children = name.locator(':scope > .swf-el__group > .swf-list > .swf-el');
  await children.first().waitFor();
  assert.equal(await children.count(), 4);
  await page.locator('.swf-inspector .swf-panel-head button').click();
  await page.locator('.swf-palette__item[data-type="address"]').click();
  await roots.nth(originalCount + 1).waitFor();
  assert.equal(await roots.last().locator(':scope > .swf-el__group > .swf-list > .swf-el').count(), 5);
  await children.nth(1).click();
  await page.locator('.swf-inspector input[type="text"]').first().fill('Custom given name');
  assert.equal(await children.nth(1).locator('.swf-el__key').textContent(), 'given_name');
  // Remove an optional component: saving must not reconstruct it from the preset.
  await children.last().locator(':scope > .swf-el__bar .swf-el__tools').getByRole('button', { name: /Delete|Löschen/ }).click();
  await children.nth(3).waitFor({ state: 'detached' });
  assert.equal(await children.count(), 3);
  await name.locator(':scope > .swf-el__bar .swf-el__tools').getByRole('button', { name: /Duplicate|Duplizieren/ }).click();
  await roots.nth(originalCount + 2).waitFor();
  assert.equal(await roots.nth(originalCount + 1).locator(':scope > .swf-el__group > .swf-list > .swf-el').count(), 3);
  await page.keyboard.press('Control+s');
  await page.locator('.swf-builder__state--dirty').waitFor({ state: 'hidden' });
  const before = php('document', String(fixture.form));
  const original = before.fields[originalCount];
  const copy = before.fields[originalCount + 1];
  assert.equal(original.children[1].key, 'given_name');
  assert.equal(original.children[1].label, 'Custom given name');
  assert.notEqual(original.children[1].id, copy.children[1].id);
  await page.reload();
  await children.first().waitFor();
  assert.equal(await children.count(), 3);
  const after = php('document', String(fixture.form));
  assert.deepEqual(after.fields[originalCount].children, original.children);
  assert.deepEqual(errors, []);
  await admin.close();
  console.log('PASS: Palette creates parts; edits/deletions and duplicate identities survive save/reload.');
} finally {
  await browser?.close();
  php('teardown', String(fixture.form), String(fixture.page));
}
