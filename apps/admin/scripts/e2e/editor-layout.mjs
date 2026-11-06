/** Verify the editor's viewport frame and independent scrolling in real wp-admin. */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const here = dirname(fileURLToPath(import.meta.url));
const php = (file, ...args) => JSON.parse(execFileSync('php', [join(here, file), ...args], { encoding: 'utf8' }));
const auth = php('admin-auth.php');
const { id } = php('webhooks-fixture.php', 'setup', `Layout ${Date.now()}`);
const base = join(homedir(), '.cache/ms-playwright');
const chrome = readdirSync(base).filter((d) => /^chromium-\d+$/.test(d)).sort().pop();
const browser = await chromium.launch({ executablePath: join(base, chrome, 'chrome-linux/chrome'), args: ['--no-sandbox'] });
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  await context.addCookies(auth.cookies.map((cookie) => ({ ...cookie, domain: 'localhost', path: '/' })));
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto(`${auth.admin}#/forms/${id}/build`);
  await page.locator('.swf-canvas__paper').waitFor();

  const measure = () => page.evaluate(() => {
    const rect = (selector) => {
      const element = document.querySelector(selector);
      const r = element.getBoundingClientRect();
      return { top: r.top, bottom: r.bottom, right: r.right, height: r.height, scrollHeight: element.scrollHeight, clientHeight: element.clientHeight };
    };
    return {
      width: innerWidth, height: innerHeight, scrollY,
      scrollHeight: document.scrollingElement.scrollHeight,
      scrollWidth: document.scrollingElement.scrollWidth,
      adminbar: rect('#wpadminbar'), shell: rect('.swf-shell'),
      toolbar: rect('.swf-builder__bar'), canvas: rect('.swf-canvas'), palette: rect('.swf-palette'),
    };
  });
  const checkFrame = async () => {
    const m = await measure();
    assert.ok(Math.abs(m.shell.top - m.adminbar.bottom) <= 1, 'No gap below the WordPress toolbar');
    assert.ok(Math.abs(m.shell.right - m.width) <= 1, 'Editor reaches the right viewport edge');
    assert.ok(Math.abs(m.shell.bottom - m.height) <= 1, 'Editor ends at viewport bottom');
    assert.ok(m.scrollHeight <= m.height + 1, 'No vertical document overflow');
    assert.ok(m.scrollWidth <= m.width, 'No horizontal document overflow');
    assert.ok(m.canvas.top >= m.toolbar.bottom - 1, 'Toolbar does not cover the canvas');
    assert.ok(m.canvas.bottom <= m.height + 1, `Canvas stays inside the viewport: ${JSON.stringify(m)}`);
  };
  for (const width of [1440, 1280, 1024]) {
    await page.setViewportSize({ width, height: 900 });
    await checkFrame();
  }
  await page.setViewportSize({ width: 1440, height: 1000 });
  for (let i = 0; i < 20; i++) await page.locator('.swf-palette__item[data-type="input"]').click();
  await checkFrame();
  const before = await measure();
  assert.ok(before.canvas.scrollHeight > before.canvas.clientHeight, 'Long form has its own scroll area');
  await page.locator('.swf-canvas').hover();
  await page.mouse.wheel(0, 800);
  await page.waitForFunction(() => document.querySelector('.swf-canvas').scrollTop > 0);
  const after = await measure();
  assert.equal(after.scrollY, 0, 'Scrolling a form does not move wp-admin');
  assert.equal(after.toolbar.top, before.toolbar.top, 'Toolbar remains visible while form scrolls');
  await page.locator('.swf-palette').hover();
  await page.mouse.wheel(0, 800);
  await page.waitForFunction(() => document.querySelector('.swf-palette').scrollTop > 0);
  await checkFrame();
  await page.screenshot({ path: '/tmp/swf-editor-layout-desktop.png' });
  await page.setViewportSize({ width: 1280, height: 600 });
  assert.ok(await page.locator('.swf-inspector').evaluate((el) => el.scrollHeight > el.clientHeight), 'Inspector scrolls in a short window');
  await page.locator('.swf-inspector').hover();
  await page.mouse.wheel(0, 800);
  await page.waitForFunction(() => document.querySelector('.swf-inspector').scrollTop > 0);
  await checkFrame();
  await page.setViewportSize({ width: 1440, height: 1000 });

  // All editor tabs use the same frame, with their content scrolling internally.
  for (const tab of ['settings', 'notifications', 'webhooks', 'entries']) {
    await page.goto(`${auth.admin}#/forms/${id}/${tab}`);
    await page.locator('.swf-builder__page').waitFor();
    const m = await page.evaluate(() => ({
      top: document.querySelector('.swf-shell').getBoundingClientRect().top,
      right: document.querySelector('.swf-shell').getBoundingClientRect().right,
      bottom: document.querySelector('.swf-shell').getBoundingClientRect().bottom,
      bar: document.querySelector('#wpadminbar').getBoundingClientRect().bottom,
      scrollHeight: document.scrollingElement.scrollHeight, width: innerWidth, height: innerHeight,
    }));
    assert.ok(Math.abs(m.top - m.bar) <= 1 && Math.abs(m.right - m.width) <= 1 && Math.abs(m.bottom - m.height) <= 1, `${tab} keeps the viewport frame`);
    assert.ok(m.scrollHeight <= m.height + 1, `${tab} does not scroll the document`);
  }
  await page.goto(`${auth.admin}#/forms/${id}/build`);
  await page.locator('.swf-canvas__paper').waitFor();
  await page.locator('.swf-rail__tools .q-btn').first().click();
  await page.screenshot({ path: '/tmp/swf-editor-layout-dark.png' });
  await page.locator('.swf-rail__tools .q-btn').first().click();

  // Narrow screens use the stacked layout so no panel is clipped or unreachable.
  await page.setViewportSize({ width: 390, height: 844 });
  assert.ok(await page.evaluate(() => document.scrollingElement.scrollWidth <= innerWidth), 'Narrow layout has no horizontal page overflow');
  await page.locator('.swf-inspector').scrollIntoViewIfNeeded();
  assert.ok(await page.locator('.swf-inspector').isVisible());
  await page.screenshot({ path: '/tmp/swf-editor-layout-mobile.png' });
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(auth.admin + '#/');
  await page.locator('.swf-page-header').waitFor();
  assert.equal(await page.locator('.swf-shell--editor').count(), 0, 'List route releases the editor scroll lock');
  assert.equal(await page.evaluate(() => getComputedStyle(document.documentElement).overflow === 'hidden'), false);
  assert.deepEqual(errors, []);
  console.log('PASS: Edge-to-edge editor, viewport sizes, independent panel scrolling, editor tabs, dark/mobile layout and route cleanup.');
} finally {
  await browser.close();
  php('webhooks-fixture.php', 'cleanup', String(id));
}
