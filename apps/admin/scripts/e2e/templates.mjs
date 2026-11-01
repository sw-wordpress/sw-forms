/**
 * Starter template gallery against the local WordPress: categories, search, the interactive
 * preview (step validation, conditional reveal, persistence) and creating a standalone form.
 * Selectors stay language independent, because the local site may run in English or German.
 * Usage: WP_ROOT=/var/www/html/wordpress node scripts/e2e/templates.mjs
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const here = dirname(fileURLToPath(import.meta.url));
const php = (...args) => JSON.parse(execFileSync('php', [join(here, 'admin-auth.php'), ...args], { encoding: 'utf8' }));
const cache = join(homedir(), '.cache/ms-playwright');
const chrome = readdirSync(cache).filter((name) => /^chromium-\d+$/.test(name)).sort().pop();
const auth = php();
const host = new URL(auth.admin).hostname;
const browser = await chromium.launch({ executablePath: join(cache, chrome, 'chrome-linux/chrome'), args: ['--no-sandbox'] });
const context = await browser.newContext({ viewport: { width: 1500, height: 1000 } });
context.setDefaultTimeout(20000);
context.setDefaultNavigationTimeout(20000);
await context.addCookies(auth.cookies.map((cookie) => ({ ...cookie, domain: host, path: '/' })));
const page = await context.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
page.on('console', (message) => {
  if (message.type() === 'error' && !message.location().url.endsWith('/favicon.ico')) errors.push(`${message.text()} @ ${message.location().url}`);
});

/** Calls the plugin REST API inside the logged-in app, so nonces and the user locale match. */
const rest = (path, options = {}) =>
  page.evaluate(
    async ({ path, options }) => {
      const response = await fetch(window.swFormsConfig.restRoot + path, {
        ...options,
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.swFormsConfig.nonce, ...(options.headers ?? {}) },
      });
      return { status: response.status, body: await response.json().catch(() => null) };
    },
    { path, options },
  );

/**
 * Fills every required control of one rendered step. Values depend on the input type, because the
 * engine validates e-mail, phone, date and number fields on the server as well.
 */
async function fillStep(content, index) {
  const values = {
    email: 'e2e@example.org',
    tel: '+49301234567',
    date: '2001-05-06',
    time: '14:30',
    number: '5',
    url: 'https://example.org',
    range: '5',
  };
  const step = content.locator(`[data-swf-step="${index}"]`);
  let filled = 0;
  for (const field of await step.locator('.swf-field').all()) {
    if ((await field.locator('.swf-required').count()) === 0) continue;
    const kinds = [];
    for (const control of await field.locator('input, select, textarea').all()) {
      kinds.push({ control, tag: await control.evaluate((node) => node.tagName.toLowerCase()), type: await control.getAttribute('type') });
    }
    const choice = kinds.find((kind) => kind.type === 'radio' || kind.type === 'checkbox');
    if (choice) {
      await choice.control.check().catch(() => {});
      filled += 1;
      continue;
    }
    for (const kind of kinds) {
      if (kind.tag === 'select') await kind.control.selectOption({ index: 1 }).catch(() => {});
      else if (kind.tag === 'input' || kind.tag === 'textarea') await kind.control.fill(values[kind.type ?? ''] ?? 'E2E Beispielwert fuer die Galerie').catch(() => {});
      filled += 1;
    }
  }
  return filled;
}

try {
  await page.goto(`${auth.admin}#/`, { waitUntil: 'domcontentloaded' });
  // The library trigger carries the Quasar icon name, which renders without the `r_` prefix.
  const trigger = page.locator('button.q-btn', { hasText: 'library_books' }).first();
  await trigger.waitFor();
  const list = await rest('form-templates');
  assert.equal(list.status, 200);
  const { categories, items } = list.body;
  assert.equal(categories.length, 4);
  assert.equal(items.length, 16);
  assert.ok(items.every((item) => item.document === undefined), 'the list must not ship documents');

  await trigger.click();
  await page.locator('.swf-library__card').first().waitFor();
  assert.equal(await page.locator('.swf-library__section').count(), categories.length);
  assert.equal(await page.locator('.swf-library__card').count(), items.length);
  const titles = await page.locator('.swf-library__card strong').allTextContents();
  assert.deepEqual(
    titles.sort(),
    items.map((item) => item.title).sort(),
  );
  // Category chips carry the translated titles of the catalog (plus the Quasar icon name).
  const chips = await page.locator('.swf-library__chip').allTextContents();
  assert.equal(chips.length, categories.length + 1);
  assert.deepEqual(
    chips.slice(1).map((text, index) => text.trim().slice(0, -categories[index].title.length)),
    categories.map((category) => category.icon.replace(/^r_/, '')),
  );

  // Search narrows the cards without another request.
  const query = items[0].title.split(' ')[0];
  await page.locator('.swf-library__search input').fill(query);
  const matches = await page.locator('.swf-library__card strong').allTextContents();
  assert.ok(matches.length > 0 && matches.length < items.length, `search for ${query}`);
  assert.ok(matches.every((title) => title.toLowerCase().includes(query.toLowerCase())));
  await page.locator('.swf-library__search input').fill('zzz-nothing-here');
  assert.equal(await page.locator('.swf-library__card').count(), 0);
  assert.ok(await page.locator('.swf-library__empty').isVisible());
  await page.locator('.swf-library__search input').fill('');

  // Category chip filters to exactly one section.
  await page.locator('.swf-library__chip', { hasText: categories[1].title }).click();
  assert.equal(await page.locator('.swf-library__section').count(), 1);
  assert.equal(await page.locator('.swf-library__card').count(), categories[1].count);
  await page.locator('.swf-library__chip').first().click();

  // The detail view of a multi-step template: interactive preview of the real form.
  const wanted = items.find((item) => item.isMultiStep && item.conditions > 0);
  assert.ok(wanted, 'the catalog needs a multi-step template with conditions');
  await page.locator('.swf-library__card', { hasText: wanted.title }).click();
  const frame = page.frameLocator('.swf-template-detail__preview iframe');
  await frame.locator('.swf-form').waitFor();
  const detail = await rest(`form-templates/${wanted.id}`);
  assert.equal(detail.status, 200);
  const src = await page.locator('.swf-template-detail__preview iframe').getAttribute('src');
  assert.ok(src.includes(`swf_template_preview=${wanted.id}`), src);
  assert.ok(await page.locator('.swf-template-detail__version').textContent());

  // Demo form: no submission token, so nothing can be stored or sent from the preview.
  assert.equal(await frame.locator('input[name="swf_token"], input[name="_wpnonce"]').count(), 0);
  const steps = detail.body.document.settings.steps;
  assert.ok(steps.enabled, `${wanted.id} previews a multi-step form`);
  assert.equal(await frame.locator('.swf-steps__item').count(), wanted.steps);

  // Navigating forward only validates the current step. A conditionally shown required field must
  // never block the step while it is hidden.
  await frame.locator('.swf-step-next').click();
  assert.ok((await frame.locator('.swf-field--error').count()) > 0, 'the first step must show field errors');
  assert.equal(await frame.locator('[data-swf-step="1"]:visible').count(), 0, 'step 2 must stay hidden');

  const content = await page.locator('.swf-template-detail__preview iframe').contentFrame();
  const stepFields = [];
  for (const element of detail.body.document.fields) {
    if (element.type === 'page-break') break;
    stepFields.push(element);
    for (const column of element.columns ?? []) stepFields.push(...column.children ?? []);
  }
  const conditional = stepFields.find((element) => element.logic && element.logic.action === 'show');
  if (conditional) {
    assert.equal(await content.locator(`[data-swf-id="${conditional.id}"]`).first().isVisible(), false, 'a hidden conditional field must stay away');
  }
  const filled = await fillStep(content, 0);
  assert.ok(filled > 0, 'the first step has required fields to fill');

  await content.locator('.swf-step-next').click();
  await content.locator('[data-swf-step="1"]:visible').waitFor();
  // Back keeps what was entered.
  await content.locator('.swf-step-back').click();
  await content.locator('[data-swf-step="0"]:visible').waitFor();
  const kept = await content
    .locator('[data-swf-step="0"] input:visible, [data-swf-step="0"] textarea:visible, [data-swf-step="0"] select:visible')
    .evaluateAll((nodes) => nodes.map((node) => String(node.value)));
  assert.ok(kept.some((value) => value !== ''), 'values must survive Back navigation');

  // Choosing the referenced option reveals the dependent field (conditions use stable ids).
  if (conditional && !(await content.locator(`[data-swf-id="${conditional.id}"]`).first().isVisible())) {
    const sourceId = conditional.logic.rules[0].field;
    const option = String(conditional.logic.rules[0].value ?? '');
    const select = content.locator(`[data-swf-id="${sourceId}"] select`);
    if ((await select.count()) > 0) await select.first().selectOption(option);
    else await content.locator(`[data-swf-id="${sourceId}"] input[value="${option}"]`).first().check();
    await content.locator(`[data-swf-id="${conditional.id}"]`).first().waitFor({ state: 'visible', timeout: 4000 });
  }

  // Two cells of one row must stack in a phone-width preview instead of squeezing.
  const pair = stepFields
    .filter((element) => element.type === 'columns' && (element.columns ?? []).length >= 2)
    .map((element) => [element.columns[0].children?.[0]?.id, element.columns[1].children?.[0]?.id])
    .find((ids) => ids[0] && ids[1]);
  assert.ok(pair, 'the gallery preview needs a two-cell row to check responsiveness');
  {
    await page.locator('.swf-template-detail__preview-tools .q-btn-toggle button').nth(2).click();
    await page.waitForTimeout(400);
    const boxes = [];
    for (const id of [pair[0], pair[1]]) {
      await content.locator(`[data-swf-id="${id}"]`).first().scrollIntoViewIfNeeded();
      boxes.push(await content.locator(`[data-swf-id="${id}"]`).first().boundingBox());
    }
    assert.ok(boxes[0] && boxes[1], `both cells of the ${pair[0]}/${pair[1]} row are rendered`);
    assert.ok(boxes[1].y - boxes[0].y > 8, 'cells stack on a narrow preview');
    await page.locator('.swf-template-detail__preview-tools .q-btn-toggle button').nth(0).click();
    await page.waitForTimeout(300);
  }

  // Structure view shows the complete field layout including the required markers.
  await page.locator('.swf-template-detail__switch button').nth(1).click();
  await page.locator('.swf-template-detail__structure').waitFor();
  const rows = await page.locator('.swf-template-detail__structure tbody tr').count();
  assert.ok(rows > wanted.fields, `${wanted.id} shows every field plus the layout rows`);
  assert.ok((await page.locator('.swf-template-detail__required').count()) > 0, 'required fields are marked');

  // One click creates an independent form instance.
  await page.locator('.swf-template-detail__switch button').nth(0).click();
  await page.locator('.swf-template-detail__actions button').click();
  await page.locator('.swf-workspace').waitFor();
  const created = Number(/#\/forms\/(\d+)/.exec(page.url())[1]);
  assert.ok(created > 0, page.url());
  const forms = await rest('forms');
  const stored = forms.body.items.find((form) => form.id === created);
  assert.equal(stored.status, 'inactive', 'a form from a template starts inactive');
  assert.equal(stored.title, wanted.title);
  const createdDocument = (await rest(`forms/${created}`)).body.document;
  assert.deepEqual(
    createdDocument.fields.map((element) => element.id),
    detail.body.document.fields.map((element) => element.id),
    'the created form carries the template structure',
  );
  assert.ok((await page.locator('.swf-el').count()) > 0, 'the builder shows the created fields');

  // Renaming the instance never touches the template.
  createdDocument.settings.submitLabel = 'E2E changed';
  const renamed = await rest(`forms/${created}`, { method: 'PUT', body: JSON.stringify({ title: 'E2E Templates gallery', document: createdDocument }) });
  assert.equal(renamed.status, 200);
  assert.equal((await rest(`form-templates/${wanted.id}`)).body.document.settings.submitLabel, detail.body.document.settings.submitLabel);

  assert.deepEqual(errors, []);
  console.log(`PASS: template gallery — ${items.length} templates, ${categories.length} categories, interactive preview and independent instantiation.`);
} finally {
  await browser.close();
  php('cleanup', 'E2E Templates');
}
