/** Complete example add-on smoke test; run only against a disposable WordPress. */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const here = dirname(fileURLToPath(import.meta.url));
const php = (file, ...args) => JSON.parse(execFileSync('php', [join(here, file), ...args], { encoding: 'utf8' }));
const fixture = php('extension-api-fixture.php');
const auth = php('admin-auth.php');
const cache = join(homedir(), '.cache/ms-playwright');
const chrome = readdirSync(cache).filter((name) => /^chromium-\d+$/.test(name)).sort().pop();
const browser = await chromium.launch({ executablePath: join(cache, chrome, 'chrome-linux/chrome'), args: ['--no-sandbox'] });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
context.setDefaultTimeout(15000);
context.setDefaultNavigationTimeout(15000);
await context.route('**/*', (route) => ['localhost', '127.0.0.1'].includes(new URL(route.request().url()).hostname) ? route.continue() : route.fulfill({ status: 204, body: '' }));
await context.addCookies(auth.cookies.map((cookie) => ({ ...cookie, domain: new URL(auth.admin).hostname, path: '/' })));
const page = await context.newPage();
page.setDefaultTimeout(15000);
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
page.on('console', (message) => { if (message.type() === 'error') console.error(message.text()); });
try {
  const builder = `${auth.admin}#/forms/${fixture.form}/build`;
  await page.goto(builder, { waitUntil: 'domcontentloaded' });
  await page.locator('.swf-workspace').waitFor();
  const field = page.locator('.swf-list--root > .swf-el').first();
  await field.click();
  const caption = page.getByLabel('Example caption', { exact: true });
  await caption.fill('Custom reference');
  await field.getByText('Custom reference: REF-123', { exact: true }).waitFor();
  await field.focus();
  await page.keyboard.press('Control+z');
  await field.getByText('Reference: REF-123', { exact: true }).waitFor();
  await page.keyboard.press('Control+Shift+z');
  await field.getByText('Custom reference: REF-123', { exact: true }).waitFor();

  const metadata = page.locator('fieldset').filter({ has: page.locator('legend', { hasText: /^Metadata$/ }) });
  await metadata.getByRole('button', { name: /Add row|Zeile hinzufügen/ }).click();
  await metadata.locator('input').nth(0).fill('team');
  await metadata.locator('input').nth(1).fill('Support');
  const steps = page.locator('fieldset').filter({ has: page.locator('legend', { hasText: /^Steps$/ }) });
  await steps.getByRole('button', { name: /Add row|Zeile hinzufügen/ }).click();
  await steps.locator('input').first().fill('Review');
  await page.getByLabel('Related field', { exact: true }).click();
  await page.getByRole('option', { name: 'Name', exact: true }).click();
  const saved = page.waitForResponse((response) => response.request().method() === 'PUT' && (new URL(response.url()).searchParams.get('rest_route') ?? new URL(response.url()).pathname).endsWith(`/forms/${fixture.form}`));
  await page.keyboard.press('Control+s');
  assert.equal((await saved).status(), 200);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.locator('.swf-workspace').waitFor();
  await page.locator('.swf-list--root > .swf-el').first().click();
  assert.equal(await page.getByLabel('Example caption', { exact: true }).inputValue(), 'Custom reference');
  assert.equal(await metadata.locator('input').nth(1).inputValue(), 'Support');
  assert.equal(await steps.locator('input').first().inputValue(), 'Review');

  const visitor = await browser.newPage();
  visitor.setDefaultTimeout(15000);
  visitor.on('pageerror', (error) => errors.push(error.message));
  await visitor.goto(fixture.url, { waitUntil: 'domcontentloaded' });
  await visitor.getByLabel('Reference', { exact: false }).fill('invalid');
  await visitor.locator('.swf-submit').click();
  await visitor.locator('.swf-error-message', { hasText: 'Use REF- followed by three digits.' }).waitFor();
  assert.equal(php('extension-api-fixture.php', 'entries', String(fixture.form)).items.length, 0);
  await visitor.getByLabel('Reference', { exact: false }).fill('REF-123');
  await visitor.locator('.swf-submit').click();
  await visitor.locator('.swf-confirmation').waitFor();
  assert.equal((await visitor.locator('.swf-confirmation').textContent()).trim(), 'REF-123');
  const entries = php('extension-api-fixture.php', 'entries', String(fixture.form));
  assert.equal(entries.items.length, 1);
  assert.ok(entries.logs.some((log) => log.type === 'example' && log.status === 'success'));
  assert.deepEqual(errors, []);
  console.log('PASS: example field, custom inspector/canvas, undo/redo, structured settings persistence, validator, merge tag and action.');
} catch (error) {
  console.error('Inspector:', await page.locator('.swf-inspector').innerText().catch(() => 'missing'));
  console.error('Canvas:', await page.locator('.swf-el').allTextContents());
  console.error('Errors:', errors);

  throw error;
} finally {
  await browser.close();
  php('extension-api-fixture.php', 'cleanup', String(fixture.form), String(fixture.page));
}
