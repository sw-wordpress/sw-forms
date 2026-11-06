/** Real signed HTTP, builder test-send, delivery status, failure badge and resend UI. */
import assert from 'node:assert/strict';
import { execFile, spawn } from 'node:child_process';
import { randomBytes, createHmac } from 'node:crypto';
import { createServer } from 'node:http';
import { createServer as createNetServer } from 'node:net';
import { readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const here = dirname(fileURLToPath(import.meta.url));
const run = promisify(execFile);
const requests = [];
let responseCode = 200;
const echo = createServer(async (req, res) => {
  let body = '';
  for await (const chunk of req) body += chunk;
  requests.push({ body, signature: req.headers['x-swforms-signature'] });
  const valid = req.headers['x-swforms-signature'] === 'sha256=' + createHmac('sha256', 'E2E known signing secret').update(body).digest('hex');
  res.writeHead(valid ? responseCode : 401, { 'Content-Type': 'text/plain' });
  res.end(valid ? 'Echo accepted' : 'Bad signature');
});
await new Promise((r) => echo.listen(0, '127.0.0.1', r));
const echoUrl = `http://127.0.0.1:${echo.address().port}/echo`;
const key = randomBytes(24).toString('hex');
const env = { ...process.env, WP_ROOT: process.env.WP_ROOT ?? resolve(here, '../../../../../../..'), SWF_TEST_WEBHOOK_URL: echoUrl, SWF_TEST_HTTP_KEY: key };
const php = async (file, ...args) => JSON.parse((await run('php', [join(here, file), ...args], { env })).stdout);
const auth = await php('admin-auth.php');
const fixture = await php('webhook-delivery-fixture.php', 'setup');
const portSocket = createNetServer();
await new Promise((r) => portSocket.listen(0, '127.0.0.1', r));
const port = portSocket.address().port;
await new Promise((r) => portSocket.close(r));
const proxy = spawn('php', ['-S', `127.0.0.1:${port}`, resolve(here, '../../../../tests/Support/webhook-wordpress-router.php')], { env, stdio: ['ignore', 'ignore', 'ignore'] });
const base = join(homedir(), '.cache/ms-playwright');
const chrome = readdirSync(base).filter((d) => /^chromium-\d+$/.test(d)).sort().pop();
let browser;
try {
  for (let i = 0; i < 100; i++) {
    try { await fetch(`http://127.0.0.1:${port}/`); break; } catch { await new Promise((r) => setTimeout(r, 20)); }
  }
  browser = await chromium.launch({ executablePath: join(base, chrome, 'chrome-linux/chrome'), args: ['--no-sandbox'] });
  const context = await browser.newContext({ viewport: { width: 1600, height: 1000 } });
  await context.addCookies(auth.cookies.map((c) => ({ ...c, domain: 'localhost', path: '/' })));
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route('**/wp-json/swforms/v1/**', async (route) => {
    const target = new URL(route.request().url());
    target.hostname = '127.0.0.1';
    target.port = String(port);
    const response = await route.fetch({ url: target.href, headers: { ...route.request().headers(), 'X-SWF-Test-Key': key } });
    await route.fulfill({ response });
  });
  await page.goto(`${auth.admin}#/forms/${fixture.id}/webhooks`);
  await page.locator('.swf-webhooks').getByRole('button', { name: 'Test senden', exact: true }).click();
  await page.locator('.swf-webhooks [role="status"]').filter({ hasText: 'HTTP 200' }).waitFor();
  assert.equal(requests.length, 1);
  assert.equal(JSON.parse(requests[0].body).event, 'webhook.test');
  assert.equal(JSON.parse(requests[0].body).data.name, 'Example');

  const created = await php('webhook-delivery-fixture.php', 'submit', String(fixture.id));
  responseCode = 500;
  let outcome;
  for (let attempt = 0; attempt < 6; attempt++) outcome = await php('webhook-delivery-fixture.php', 'run', String(created.delivery));
  assert.equal(outcome.status, 'failed');
  const body = requests.at(-1).body;
  await page.goto(auth.admin + '#/');
  await page.locator('tr').filter({ hasText: 'E2E Webhook delivery' }).locator('.swf-chip--danger').waitFor();
  await page.goto(`${auth.admin}#/forms/${fixture.id}/entries/${created.entry}`);
  const sheet = page.locator('.swf-sheet');
  await sheet.getByRole('button', { name: 'Erneut senden', exact: true }).waitFor();
  await sheet.getByRole('button', { name: 'Erneut senden', exact: true }).click();
  await sheet.getByText('Vorgemerkt', { exact: false }).first().waitFor();
  responseCode = 200;
  outcome = await php('webhook-delivery-fixture.php', 'run', String(created.delivery));
  assert.equal(outcome.status, 'success');
  assert.equal(requests.at(-1).body, body, 'Manual resend keeps the original event and bytes');
  await sheet.getByText('Zugestellt', { exact: false }).first().waitFor();
  assert.deepEqual(errors, []);
  console.log('PASS: Signed echo delivery, builder test button, terminal failure badge, entry status and manual resend.');
} finally {
  if (browser) await browser.close();
  proxy.kill();
  await new Promise((r) => echo.close(r));
  await php('webhook-delivery-fixture.php', 'cleanup', String(fixture.id));
}
