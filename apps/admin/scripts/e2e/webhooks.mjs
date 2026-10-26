/**
 * Webhooks in the admin app: configure a webhook with secret header and signing secret, send a
 * signed test, save (secrets become write-only), deliveries in the entry view, failure badge,
 * dismiss, global settings. Uses a local echo receiver (tests/fixtures/webhook-echo.php).
 * Usage: node scripts/e2e/webhooks.mjs
 */
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs';
import { createConnection } from 'node:net';
import { homedir, tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const here = dirname(fileURLToPath(import.meta.url));
const php = (...args) => JSON.parse(execFileSync('php', [join(here, 'webhooks-fixture.php'), ...args], { encoding: 'utf8' }));
const base = join(homedir(), '.cache/ms-playwright');
const chrome = readdirSync(base).filter((name) => /^chromium-\d+$/.test(name)).sort().pop();

const dir = mkdtempSync(join(tmpdir(), 'swf-echo-'));
const log = join(dir, 'requests.jsonl');
const port = 19000 + Math.floor(Math.random() * 900);
const echo = spawn('php', ['-S', `127.0.0.1:${port}`, join(here, '../../../../tests/fixtures/webhook-echo.php')], {
  env: { ...process.env, SWF_ECHO_LOG: log },
  stdio: 'ignore',
});
const url = `http://127.0.0.1:${port}/crm`;
const requests = () => {
  try {
    return readFileSync(log, 'utf8').trim().split('\n').filter(Boolean).map((line) => JSON.parse(line));
  } catch {
    return [];
  }
};
const verify = (header, secret, body) => {
  const parts = Object.fromEntries(header.split(',').map((p) => p.split('=')));
  return createHmac('sha256', secret).update(`${parts.t}.${body}`).digest('hex') === parts.v1;
};
for (let i = 0; i < 50; i++) {
  const open = await new Promise((resolve) => {
    const socket = createConnection(port, '127.0.0.1', () => resolve(socket.end() || true)).on('error', () => resolve(false));
  });
  if (open) break;
  await new Promise((r) => setTimeout(r, 100));
}

const fixture = php('setup', '127.0.0.1');
let browser;
try {
  browser = await chromium.launch({ executablePath: join(base, chrome, 'chrome-linux/chrome'), args: ['--no-sandbox'] });
  const auth = JSON.parse(execFileSync('php', [join(here, 'admin-auth.php')], { encoding: 'utf8' }));
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'de-DE' });
  await context.addCookies(auth.cookies.map((cookie) => ({ ...cookie, domain: 'localhost', path: '/' })));
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));

  // Configure a webhook.
  await page.goto(`${auth.admin}#/forms/${fixture.form}/webhooks`);
  await page.locator('.swf-webhooks').waitFor();
  await page.waitForLoadState('networkidle');
  await page.getByRole('button', { name: /Webhook hinzufügen|Add webhook/ }).click();
  const card = page.locator('[data-webhook]').first();
  await card.locator('[data-test="webhook-url"]').fill(url);
  await card.getByRole('button', { name: /Header hinzufügen|Add header/ }).click();
  const header = card.locator('.swf-webhooks__pair').last();
  await header.locator('input').nth(0).fill('Authorization');
  await header.getByRole('button', { name: /Geheimer Wert|Secret value/ }).click();
  await header.locator('input[type="password"]').fill('Bearer e2e-token');
  await card.getByRole('button', { name: /Erzeugen|Generate/ }).click();
  const secret = await card.locator('[data-test="webhook-secret"]').inputValue();
  assert.match(secret, /^[0-9a-f]{64}$/);
  await card.locator('.q-option-group').nth(1).getByText(/Ausgewählte Felder|Selected fields/).click();
  assert.equal(await card.locator('.swf-webhooks__pair').count(), 5, 'Mapping prefilled with the 4 mappable fields, plus the header row');

  // Signed test with unsaved settings.
  await card.locator('[data-test="webhook-test"]').click();
  await card.locator('[data-test="webhook-test-result"]').waitFor();
  assert.match(await card.locator('[data-test="webhook-test-result"]').textContent(), /HTTP 200/);
  const test = requests().at(-1);
  assert.equal(test.headers['x-swforms-event'], 'webhook.test');
  assert.equal(test.headers.authorization, 'Bearer e2e-token');
  assert.ok(verify(test.headers['x-swforms-signature'], secret, test.body), 'Test request signature');
  const sample = JSON.parse(test.body).data;
  assert.deepEqual(Object.keys(sample).sort(), ['address', 'email', 'name']);
  assert.equal(sample.email, 'jane.doe@example.org');
  assert.equal(typeof sample.address.city, 'string', 'Nested target name from the mapping');
  console.log('PASS: Webhook configured and signed test request sent.');

  // Save: secrets become write-only.
  await page.keyboard.press('Control+s');
  await page.locator('.swf-builder__state--dirty').waitFor({ state: 'hidden' });
  await page.reload();
  await page.locator('[data-webhook]').first().waitFor();
  const saved = page.locator('[data-webhook]').first();
  if ((await saved.locator('[data-test="webhook-url"]').count()) === 0) await saved.locator('.swf-notification__head').click();
  assert.equal(await saved.locator('[data-test="webhook-secret"]').inputValue(), '');
  assert.match(await saved.locator('[data-test="webhook-secret"]').getAttribute('placeholder'), /gespeichert|stored/);
  assert.match(await saved.locator('.swf-notification__head').textContent(), /Signiert|Signed/);
  console.log('PASS: Secrets are write-only after saving.');

  // A real submission is delivered and shown on the entry.
  const delivered = php('submit', String(fixture.form));
  assert.equal(delivered.deliveries[0].status, 'success');
  const real = requests().at(-1);
  assert.equal(real.headers['x-swforms-event'], 'form.submitted');
  assert.ok(verify(real.headers['x-swforms-signature'], secret, real.body), 'Delivery signature with stored secret');
  assert.deepEqual(JSON.parse(real.body).data, { email: 'max@example.org', name: 'Max', address: { city: 'Berlin' } });
  await page.goto(`${auth.admin}#/forms/${fixture.form}/entries/${delivered.entry}`);
  await page.locator('[data-test="entry-deliveries"]').waitFor();
  assert.match(await page.locator('[data-test="entry-deliveries"]').textContent(), /Zugestellt|Delivered/);
  console.log('PASS: Delivery listed on the entry.');

  // Failure: badge in the form list, alert in the tab, dismiss.
  await page.goto(`${auth.admin}#/forms/${fixture.form}/webhooks`);
  await page.locator('[data-webhook]').first().waitFor();
  await page.waitForLoadState('networkidle');
  const again = page.locator('[data-webhook]').first();
  if ((await again.locator('[data-test="webhook-url"]').count()) === 0) await again.locator('.swf-notification__head').click();
  await again.locator('[data-test="webhook-url"]').fill(`${url}?status=400`);
  await page.keyboard.press('Control+s');
  await page.locator('.swf-builder__state--dirty').waitFor({ state: 'hidden' });
  const failed = php('submit', String(fixture.form));
  assert.equal(failed.deliveries[0].status, 'failed');
  await page.goto(auth.admin);
  const badge = page.locator(`[data-test="webhook-failures"][href$="/forms/${fixture.form}/webhooks"]`);
  await badge.waitFor();
  await badge.click();
  const alert = page.locator('.swf-webhooks__alert');
  await alert.waitFor();
  await alert.getByRole('button', { name: /Alle als erledigt markieren|Dismiss all/ }).click();
  await alert.waitFor({ state: 'hidden' });
  console.log('PASS: Failure badge, alert and dismiss.');

  // Global settings.
  await page.goto(`${auth.admin}#/settings`);
  const hosts = page.locator('[data-test="allowed-hosts"]');
  await hosts.waitFor();
  assert.match(await hosts.inputValue(), /127\.0\.0\.1\/32/);
  assert.deepEqual(errors, []);
  await context.close();
  console.log('PASS: Allowed internal hosts shown in the settings.');
} finally {
  await browser?.close();
  php('teardown', String(fixture.form), JSON.stringify(fixture.allowed));
  echo.kill();
  rmSync(dir, { recursive: true, force: true });
}
