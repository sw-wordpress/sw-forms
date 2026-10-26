/**
 * End-to-end test of a published form against the local WordPress (anonymous visitor).
 * Usage: node scripts/e2e/frontend.mjs   (needs PHP CLI with access to the WordPress DB)
 */
import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const here = dirname(fileURLToPath(import.meta.url));
const php = (...args) => JSON.parse(execFileSync('php', [join(here, 'fixture.php'), ...args], { encoding: 'utf8' }));
const findChrome = () => {
  const base = join(homedir(), '.cache/ms-playwright');
  const dir = readdirSync(base).filter((d) => /^chromium-\d+$/.test(d)).sort().pop();
  return join(base, dir, 'chrome-linux/chrome');
};

let failures = 0;
/** Logic is applied on the next animation frame: poll instead of asserting immediately. */
const eventually = async (fn, timeout = 2000) => {
  const end = Date.now() + timeout;
  for (;;) {
    if (await fn()) return true;
    if (Date.now() > end) return false;
    await new Promise((r) => setTimeout(r, 50));
  }
};
const check = (name, ok, detail = '') => {
  console.log(`${ok ? '✓' : '✗'} ${name}${ok || !detail ? '' : ' — ' + detail}`);
  if (!ok) failures++;
};

const fixture = php('setup');
const url = fixture.url.replace('http://localhost/', 'http://127.0.0.1/');
const browser = await chromium.launch({ executablePath: findChrome(), args: ['--no-sandbox'] });
try {
  // ---------- with JavaScript ----------
  const page = await browser.newPage();
  const consoleErrors = [];
  page.on('pageerror', (e) => consoleErrors.push(e.message));
  await page.goto(url);
  const company = page.locator('[data-swf-id="company"]');
  check('company hidden initially', await company.isHidden());
  await page.getByLabel('Firma', { exact: true }).check();
  check('company shown for "Firma"', await eventually(() => company.isVisible()));
  check('company input enabled when shown', await page.locator('input[name="swf[company]"]').isEnabled());
  await page.getByLabel('Privat').check();
  check('company input disabled when hidden', await eventually(() => page.locator('input[name="swf[company]"]').isDisabled()));

  // repeater
  const rows = page.locator('[data-swf-id="people"] .swf-rows > .swf-row');
  check('one row initially', (await rows.count()) === 1);
  check('remove hidden at min rows', await rows.nth(0).locator('.swf-row-remove').isHidden());
  await page.getByRole('button', { name: 'Person hinzufügen' }).click();
  await page.getByRole('button', { name: 'Person hinzufügen' }).click();
  check('three rows after adding', (await rows.count()) === 3);
  check('add hidden at max rows', await page.getByRole('button', { name: 'Person hinzufügen' }).isHidden());
  const age1 = rows.nth(1).locator('[data-swf-id="age"]');
  check('age hidden in new row', await age1.isHidden());
  await rows.nth(1).getByLabel('Kind').check();
  check('age shown in row 2 only', await eventually(async () => (await age1.isVisible()) && (await rows.nth(0).locator('[data-swf-id="age"]').isHidden())));
  await rows.nth(2).locator('.swf-row-remove').click();
  check('row removed', (await rows.count()) === 2);

  // errors
  await page.waitForTimeout(2200);
  await page.getByRole('button', { name: /Submit|Absenden/ }).click();
  const summary = page.locator('.swf-summary');
  await summary.waitFor({ state: 'visible' });
  check('summary visible and focused', await summary.evaluate((el) => el === document.activeElement));
  const nameError = page.locator('[data-swf-id="name"] .swf-error-message');
  check('name error shown', (await nameError.textContent())?.length > 0);
  check('error in repeater row 2 (age)', await rows.nth(1).locator('[data-swf-id="age"] .swf-error-message').isVisible());
  check('aria-invalid set', (await page.locator('input[name="swf[name]"]').getAttribute('aria-invalid')) === 'true');
  await page.locator('input[name="swf[name]"]').fill('Erika');
  check('error cleared on input', await nameError.isHidden());

  // valid
  await page.locator('input[name="swf[mail]"]').fill('erika@example.org');
  await rows.nth(0).getByLabel('Name').fill('Anna');
  await rows.nth(1).getByLabel('Name').fill('Ben');
  await rows.nth(1).getByLabel('Alter').fill('7');
  await page.getByLabel('Thema').selectOption('b');
  await page.getByLabel(/Ich stimme/).check();
  await page.getByRole('button', { name: /Submit|Absenden/ }).click();
  const confirmation = page.locator('.swf-confirmation');
  await confirmation.waitFor({ state: 'visible', timeout: 10000 });
  check('confirmation shown', (await confirmation.textContent())?.includes('Danke, Erika!'), await confirmation.textContent());
  check('no JS errors', consoleErrors.length === 0, consoleErrors.join('; '));

  // ---------- without JavaScript (postback) ----------
  // Some themes (e.g. The7) keep a JS-driven loading overlay that intercepts clicks when JS is off:
  // submit with the keyboard instead.
  const noJs = await browser.newContext({ javaScriptEnabled: false });
  const p2 = await noJs.newPage();
  await p2.goto(url);
  await p2.waitForTimeout(2200);
  await p2.locator('input[name="swf[name]"]').fill('Max');
  await p2.locator('.swf-submit').press('Enter');
  await p2.waitForLoadState();
  check('no-JS: server rendered errors', await p2.locator('.swf-summary li').count() > 0);
  check('no-JS: value kept', (await p2.locator('input[name="swf[name]"]').inputValue()) === 'Max');
  await p2.locator('input[name="swf[mail]"]').fill('max@example.org');
  await p2.locator('input[name="swf[people][0][pname]"]').fill('Max');
  await p2.locator('input[name="swf[ok]"]').press('Space');
  await p2.waitForTimeout(2200);
  await p2.locator('.swf-submit').press('Enter');
  await p2.waitForLoadState();
  check('no-JS: confirmation', (await p2.locator('.swf-confirmation').textContent())?.includes('Danke, Max!'));
} finally {
  await browser.close();
  const count = execFileSync('php', ['-r', `define('WP_USE_THEMES',false);$_SERVER['HTTP_HOST']='localhost';require '${join(process.env.WP_ROOT ?? join(here, '../../../../../../..'), 'wp-load.php')}';echo json_encode((new \\SWForms\\WordPress\\Entries\\EntryRepository())->search(${fixture.form},'inbox','',1,10,'asc')['items'][0]['values'] ?? null);`], { encoding: 'utf8' });
  console.log('first stored entry values:', count);
  php('teardown', String(fixture.form), String(fixture.page));
}
console.log(failures === 0 ? '\nAll checks passed.' : `\n${failures} check(s) failed.`);
process.exit(failures === 0 ? 0 : 1);
