/**
 * End-to-end test of the admin app (builder) against the local WordPress as administrator.
 * Usage: node scripts/e2e/admin.mjs [screenshot-dir]
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const here = dirname(fileURLToPath(import.meta.url));
const shots = process.argv[2] ?? join(here, '../../screenshots');
mkdirSync(shots, { recursive: true });
const php = (...args) => JSON.parse(execFileSync('php', [join(here, 'admin-auth.php'), ...args], { encoding: 'utf8' }));
const findChrome = () => {
  const base = join(homedir(), '.cache/ms-playwright');
  const dir = readdirSync(base).filter((d) => /^chromium-\d+$/.test(d)).sort().pop();
  return join(base, dir, 'chrome-linux/chrome');
};
let failures = 0;
const check = (name, ok, detail = '') => {
  console.log(`${ok ? '✓' : '✗'} ${name}${ok || !detail ? '' : ' — ' + detail}`);
  if (!ok) failures++;
};
const eventually = async (fn, timeout = 4000) => {
  const end = Date.now() + timeout;
  for (;;) {
    try {
      if (await fn()) return true;
    } catch {
      /* retry */
    }
    if (Date.now() > end) return false;
    await new Promise((r) => setTimeout(r, 100));
  }
};

const TITLE = `E2E Admin ${Date.now()}`;
const auth = php();
const host = 'localhost';
const browser = await chromium.launch({ executablePath: findChrome(), args: ['--no-sandbox'] });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'de-DE' });
await context.addCookies(auth.cookies.map((c) => ({ ...c, domain: host, path: '/' })));
const page = await context.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('response', (r) => {
  if (r.status() === 404 && !r.url().endsWith('/favicon.ico')) errors.push(`404 ${r.url()}`);
});
page.on('console', (m) => {
  // The browser's own favicon request is not ours.
  if (m.type() === 'error' && !m.location().url.endsWith('/favicon.ico')) errors.push(`${m.text()} @ ${m.location().url}`);
});

try {
  await page.goto(auth.admin.replace('localhost', host));
  await page.locator('.swf-page-header__title').waitFor();
  check('forms list renders', (await page.locator('.swf-page-header__title').textContent())?.length > 0);
  await page.screenshot({ path: join(shots, '01-forms.png') });

  // create
  await page.locator('.swf-page-header__actions .q-btn').first().click();
  await page.locator('.swf-dialog input').fill(TITLE);
  await page.locator('.swf-dialog button[type="submit"]').click();
  await page.locator('.swf-workspace').waitFor({ timeout: 10000 });
  check('builder opens', await page.locator('.swf-palette__item').count() >= 7);

  // drag & drop from the palette into the empty form
  const root = page.locator('.swf-list--root');
  await page.locator('.swf-palette__item[data-type="input"]').dragTo(root);
  check('drag from palette adds element', await eventually(async () => (await root.locator(':scope > .swf-el').count()) === 1));

  // click to add (after the selected element)
  await page.locator('.swf-palette__item[data-type="radio"]').click();
  await page.locator('.swf-palette__item[data-type="columns"]').click();
  check('click adds elements', (await root.locator(':scope > .swf-el').count()) === 3);

  // the columns element is selected: click "input" adds into it? (selection is the columns element -> after it)
  const columns = root.locator(':scope > .swf-el').nth(2);
  const col2 = columns.locator('.swf-col-box').nth(1).locator('.swf-list');
  await page.locator('.swf-palette__item[data-type="input"]').dragTo(col2);
  check('drag into a column', await eventually(async () => (await col2.locator('.swf-el').count()) === 1));

  // label -> key follows
  await root.locator(':scope > .swf-el').first().click();
  const label = page.locator('.swf-inspector input').first();
  await label.fill('Firma GmbH');
  check('key follows label', await eventually(async () => (await root.locator(':scope > .swf-el').first().locator('.swf-el__key').textContent()) === 'firma_gmbh'));

  // options of the radio
  await root.locator(':scope > .swf-el').nth(1).click();
  check('radio inspector shows option editor', await page.locator('.swf-options__row').count() === 2);

  // logic on the field in column 2: show if radio is option-1
  await col2.locator('.swf-el').first().click();
  await page.locator('.swf-inspector .q-tab', { hasText: /Logik|Logic/ }).click();
  await page.locator('.swf-logic .q-toggle').first().click();
  check('logic rule created', await eventually(async () => (await page.locator('.swf-logic__rule').count()) === 1));
  check('canvas shows conditional badge', await eventually(async () => (await col2.locator('.swf-el__badge--logic').count()) === 1));
  await page.screenshot({ path: join(shots, '02-builder.png') });

  // resize the columns with the keyboard
  await columns.locator('.swf-col-resize').focus();
  await page.keyboard.press('ArrowRight');
  await page.keyboard.press('ArrowRight');
  check('column resize via keyboard', await eventually(async () => (await columns.locator('.swf-col-box__head .swf-num').first().textContent()) === '8/12'));

  // undo / redo
  await page.locator('.swf-canvas').click({ position: { x: 5, y: 5 } });
  await page.keyboard.press('Control+z');
  check('undo', await eventually(async () => (await columns.locator('.swf-col-box__head .swf-num').first().textContent()) === '7/12'));
  await page.keyboard.press('Control+Shift+z');
  check('redo', await eventually(async () => (await columns.locator('.swf-col-box__head .swf-num').first().textContent()) === '8/12'));

  // save
  await page.keyboard.press('Control+s');
  check('save succeeds', await eventually(async () => (await page.locator('.q-notification').count()) > 0 && (await page.locator('.swf-builder__state--dirty').count()) === 0, 8000));

  // preview with the real PHP output
  await page.locator('.swf-builder__right .q-btn', { hasText: /Vorschau|Preview/ }).click();
  const frame = page.frameLocator('.swf-preview-dialog iframe');
  check('preview renders the form', await eventually(async () => (await frame.locator('form.swf-form').count()) === 1, 15000));
  await page.waitForTimeout(800);
  await page.screenshot({ path: join(shots, '03-preview.png') });
  // preview submit only validates
  await frame.locator('.swf-submit').click();
  check('preview validation shows errors summary', await eventually(async () => await frame.locator('.swf-summary').isVisible(), 8000));
  await page.keyboard.press('Escape');

  // entries: one entry submitted through swf_submit()
  const submitted = php('entry', TITLE);
  check('entry submitted via swf_submit()', submitted.status === 'success' && submitted.entry > 0, JSON.stringify(submitted));
  await page.goto(page.url().replace(/#\/forms\/(\d+).*$/, '#/forms/$1/entries'));
  const row = page.locator('.swf-entries__row').first();
  check('entry listed as unread', await eventually(async () => (await row.getAttribute('class'))?.includes('swf-entries__row--unread')));
  check('entry columns show values', (await row.textContent())?.includes('E2E Wert'));
  check('choice columns show labels, not values', (await row.textContent())?.includes('Option 1'));
  await row.click();
  const sheet = page.locator('.swf-sheet');
  check('entry sheet shows labelled data', await eventually(async () => (await sheet.locator('.swf-entry-fields dt').count()) >= 2));
  check('entry log lists the e-mail action', await eventually(async () => (await sheet.locator('.swf-entry-log li').count()) >= 1));
  await page.waitForTimeout(500); // slide-in animation
  await page.screenshot({ path: join(shots, '07-entry.png') });
  await page.keyboard.press('Escape');
  check('opening marks the entry as read', await eventually(async () => !(await row.getAttribute('class'))?.includes('swf-entries__row--unread')));

  // other tabs
  for (const [tab, file] of [
    ['settings', '04-settings.png'],
    ['notifications', '05-notifications.png'],
    ['entries', '06-entries.png'],
  ]) {
    await page.goto(page.url().replace(/#\/forms\/(\d+).*$/, `#/forms/$1/${tab}`));
    await page.waitForTimeout(700);
    await page.screenshot({ path: join(shots, file) });
  }
  check('settings tab shows shortcode', (await page.goto(page.url().replace(/entries$/, 'settings')), await eventually(async () => (await page.locator('.swf-embed code').first().textContent())?.includes('[sw_form'))));
  check('no console errors', errors.length === 0, errors.join(' | '));
} finally {
  await browser.close();
  php('cleanup', 'E2E Admin');
}
console.log(failures === 0 ? '\nAll checks passed.' : `\n${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
