/**
 * Browser test of the design variants against the local WordPress (real layout engine):
 * gallery interactivity, floating label geometry/motion (no overlap, no layout shift), filled,
 * prefilled and programmatic values, keyboard and error states, surprise-motion effects,
 * reduced motion, responsive layout, dark schemes and unchanged submission data.
 * Usage: node scripts/e2e/designs.mjs   (PHP CLI with access to the WordPress DB)
 */
import { execFileSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const here = dirname(fileURLToPath(import.meta.url));
const php = (...args) => JSON.parse(execFileSync('php', [join(here, 'designs-fixture.php'), ...args], { encoding: 'utf8' }));
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
const settle = (page) => page.waitForTimeout(350);
const local = (url) => url.replace('http://localhost/', 'http://127.0.0.1/');

/** Label/control geometry of one field: rects, scale and the input's text box. */
const geometry = (page, card, name) => page.evaluate(([card, name]) => {
  const control = document.querySelector(`.swfg-card[data-swfg-variant="${card}"] [name="swf[${name}]"]`) ?? document.querySelector(`[name="swf[${name}]"]`);
  const field = control.closest('.swf-field');
  const label = field.querySelector('.swf-label');
  const l = label.getBoundingClientRect();
  const c = control.getBoundingClientRect();
  const style = getComputedStyle(control);
  const line = parseFloat(style.lineHeight) || parseFloat(style.fontSize) * 1.2;
  const padTop = parseFloat(style.paddingTop) + parseFloat(style.borderTopWidth);
  const padBottom = parseFloat(style.paddingBottom) + parseFloat(style.borderBottomWidth);
  // Text box: content area for padded controls, centred line for padding-free ones.
  const content = c.height - padTop - padBottom;
  const textTop = c.top + padTop + Math.max(0, (content - line) / 2);
  const matrix = new DOMMatrixReadOnly(getComputedStyle(label).transform);
  const y = window.scrollY;
  return { page: { controlTop: c.top + y, labelTop: l.top + y }, label: { top: l.top, bottom: l.bottom, height: l.height, center: l.top + l.height / 2 }, control: { top: c.top, bottom: c.bottom, center: c.top + c.height / 2, left: c.left }, textTop, textBottom: textTop + line, scale: matrix.a, transition: getComputedStyle(label).transitionDuration.split(',')[0], color: getComputedStyle(label).color };
}, [card, name]);

const fixture = php('setup');
const browser = await chromium.launch({ executablePath: findChrome(), args: ['--no-sandbox'] });
const newContext = async (options = {}) => {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, ...options });
  await context.addCookies(fixture.cookies.map((c) => ({ ...c, domain: '127.0.0.1', path: '/' })));
  return context;
};
try {
  const context = await newContext();
  const page = await context.newPage();
  const errors = [];
  const submits = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('request', (r) => { if (r.method() === 'POST' || r.url().includes('/submit')) submits.push(r.url()); });
  await page.goto(local(fixture.gallery));

  // ---------- gallery ----------
  const variants = await page.locator('.swfg-card').evaluateAll((cards) => cards.map((c) => c.dataset.swfgVariant));
  check('gallery renders seven variants', variants.length === 7, variants.join(','));
  check('every variant has the same reference fields', await page.locator('.swfg [name="swf[first_name]"]').count() === 7 && await page.locator('.swfg [name="swf[salutation]"]').count() === 7 && await page.locator('.swfg .swf-submit').count() === 7);
  check('labels are linked to their controls', await page.getByLabel('First name').count() + await page.getByLabel('Vorname').count() >= 7);

  // ---------- 05 animated float ----------
  const af = 'animated-float';
  await page.locator(`.swfg-card[data-swfg-variant="${af}"] [name="swf[first_name]"]`).scrollIntoViewIfNeeded();
  const rest = await geometry(page, af, 'first_name');
  check('animated float: resting label centred in the field', Math.abs(rest.label.center - rest.control.center) <= 2, JSON.stringify(rest));
  check('animated float: resting label at full size', Math.abs(rest.scale - 1) < 0.01);
  check('animated float: 180–240 ms transition', ['0.22s', '0.18s', '0.2s', '0.24s'].includes(rest.transition), rest.transition);
  const docBox = (name) => page.locator(`.swfg-card[data-swfg-variant="${af}"] [name="swf[${name}]"]`).evaluate((el) => { const r = el.getBoundingClientRect(); return [r.left, r.top + window.scrollY, r.width, r.height].join(); });
  const neighbour = await docBox('title');
  await page.locator(`.swfg-card[data-swfg-variant="${af}"] [name="swf[first_name]"]`).focus();
  await page.waitForTimeout(90);
  const mid = await geometry(page, af, 'first_name');
  check('animated float: label moves smoothly (intermediate frame)', mid.scale < 0.999 && mid.scale > 0.75, String(mid.scale));
  await settle(page);
  const up = await geometry(page, af, 'first_name');
  check('animated float: floated label scaled to 12px', Math.abs(up.scale - 0.75) < 0.01, String(up.scale));
  check('animated float: label sits on the top border', Math.abs(up.label.center - up.control.top) <= 1.5, `${up.label.center} vs ${up.control.top}`);
  check('animated float: label does not overlap the input text', up.label.bottom <= up.textTop, `${up.label.bottom} > ${up.textTop}`);
  check('animated float: focus colours the label with the accent', up.color !== rest.color);
  check('animated float: no layout shift while animating', up.page.controlTop === rest.page.controlTop && await docBox('title') === neighbour, JSON.stringify([rest.page, up.page]));
  const notch = await page.evaluate(() => getComputedStyle(document.querySelector('.swfg-card[data-swfg-variant="animated-float"] [data-swf-id="first_name"] .swf-label')).backgroundImage);
  check('animated float: label background interrupts the border', notch.includes('linear-gradient'), notch);
  await page.keyboard.type('Erika');
  await page.keyboard.press('Tab');
  await settle(page);
  check('animated float: label stays up after blur with content', Math.abs((await geometry(page, af, 'first_name')).scale - 0.75) < 0.01);
  check('animated float: Tab moves to the next field', await page.evaluate(() => document.activeElement?.getAttribute('name')) === 'swf[last_name]');
  await page.keyboard.press('Tab');
  await settle(page);
  check('animated float: label returns after blur without content', Math.abs((await geometry(page, af, 'last_name')).scale - 1) < 0.01);
  // Programmatic value without any event (script, password manager): CSS alone floats it.
  await page.evaluate(() => { document.querySelector('.swfg-card[data-swfg-variant="animated-float"] [name="swf[title]"]').value = 'Prof.'; });
  await settle(page);
  check('animated float: programmatic value keeps the label up', Math.abs((await geometry(page, af, 'title')).scale - 0.75) < 0.01);
  await page.locator(`.swfg-card[data-swfg-variant="${af}"] [name="swf[salutation]"]`).selectOption('ms');
  await page.locator('body').click({ position: { x: 5, y: 5 } });
  await settle(page);
  check('animated float: chosen select option keeps the label up', Math.abs((await geometry(page, af, 'salutation')).scale - 0.75) < 0.01);

  // ---------- 01 floating ----------
  await page.locator('.swfg-card[data-swfg-variant="floating"] [name="swf[first_name]"]').fill('Erika');
  await settle(page);
  const floating = await geometry(page, 'floating', 'first_name');
  check('floating: label above the text inside the field', floating.label.top >= floating.control.top && floating.label.bottom <= floating.textTop + 1, JSON.stringify(floating));

  // ---------- validation and keyboard ----------
  await page.locator('[data-swfg="reset"]').click();
  await page.waitForTimeout(50);
  const submit = page.locator(`.swfg-card[data-swfg-variant="${af}"] .swf-submit`);
  await submit.focus();
  await page.keyboard.press('Enter');
  await settle(page);
  const invalid = page.locator(`.swfg-card[data-swfg-variant="${af}"] [name="swf[first_name]"]`);
  check('keyboard submit shows errors with aria-invalid', await invalid.getAttribute('aria-invalid') === 'true');
  check('errors are referenced by aria-describedby', (await invalid.getAttribute('aria-describedby')).includes(await page.locator(`.swfg-card[data-swfg-variant="${af}"] [data-swf-id="first_name"] .swf-error-message`).getAttribute('id')));
  check('focus moves to the error summary', await page.evaluate(() => document.activeElement?.classList.contains('swf-summary')));
  await page.locator('[data-swfg="validate"]').click();
  await settle(page);
  check('every variant shows its error state', await page.locator('.swfg .swf-field--error').count() === 14);
  const errorOverlap = await geometry(page, af, 'first_name');
  check('animated float: error state keeps the label clear of the text', errorOverlap.label.bottom <= errorOverlap.textTop || errorOverlap.scale === 1);

  // ---------- 06 surprise motion ----------
  const sm = '.swfg-card[data-swfg-variant="surprise-motion"]';
  await page.locator('[data-swfg="reset"]').click();
  await page.locator(`${sm} [name="swf[last_name]"]`).focus();
  await settle(page);
  check('surprise motion: underline grows to full width on focus', (await page.locator(`${sm} [name="swf[last_name]"]`).evaluate((el) => getComputedStyle(el).backgroundSize)).startsWith('100% 2px'));
  const button = page.locator(`${sm} .swf-submit`);
  await button.hover();
  await settle(page);
  const arrow = await button.evaluate((el) => new DOMMatrixReadOnly(getComputedStyle(el, '::after').transform).e);
  check('surprise motion: arrow moves 3–4 px on hover', arrow >= 3 && arrow <= 4, String(arrow));
  check('surprise motion: button stays enabled during effects', await button.isEnabled());
  const tick = () => page.locator(`${sm} [data-swf-id="first_name"] .swf-label`).evaluate((el) => getComputedStyle(el, '::after').opacity);
  await page.locator('[data-swfg="fill"]').click();
  await settle(page);
  check('surprise motion: no success tick before validation', await tick() === '0');
  await page.locator('[data-swfg="validate"]').click();
  await settle(page);
  check('surprise motion: success tick after successful validation', await tick() === '1');
  await page.locator(`${sm} [name="swf[first_name]"]`).fill('');
  await settle(page);
  check('surprise motion: tick disappears when the value changes', await tick() === '0');
  check('gallery never submits data', submits.length === 0, submits.join(', '));

  // ---------- schemes ----------
  const luminance = (selector) => page.locator(selector).first().evaluate((el) => {
    const [r, g, b] = getComputedStyle(el).backgroundColor.match(/\d+/g).map(Number);
    return (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;
  });
  check('dark focus: dark input surfaces in light mode', await luminance('.swfg-card[data-swfg-variant="dark-focus"] .swf-input') < 0.2);
  await page.getByLabel('Dark', { exact: true }).check();
  // Some themes transition every input property; measure the settled colours.
  await page.waitForTimeout(800);
  check('dark scheme: classic and animated float switch to dark fields', await luminance('.swfg-card[data-swfg-variant="classic"] .swf-input') < 0.2 && await luminance('.swfg-card[data-swfg-variant="animated-float"] .swf-input') < 0.2);
  await page.getByRole('radio', { name: 'Animated float' }).check();
  check('variant switcher shows one variant', await page.locator('.swfg-card:visible').count() === 1);

  // ---------- responsive ----------
  await page.getByRole('radio', { name: 'All', exact: true }).check();
  await page.setViewportSize({ width: 390, height: 900 });
  await settle(page);
  check('mobile: no horizontal overflow', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
  const [firstBox, lastBox] = [await page.locator(`.swfg-card[data-swfg-variant="${af}"] [name="swf[first_name]"]`).boundingBox(), await page.locator(`.swfg-card[data-swfg-variant="${af}"] [name="swf[last_name]"]`).boundingBox()];
  check('mobile: name columns stack', Math.abs(firstBox.x - lastBox.x) < 1 && lastBox.y > firstBox.y);
  // Columns follow the form's own width (container query): side by side in the single view.
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.getByRole('radio', { name: 'Animated float' }).check();
  await page.waitForTimeout(100);
  const [wideFirst, wideLast] = [await page.locator(`.swfg-card[data-swfg-variant="${af}"] [name="swf[first_name]"]`).boundingBox(), await page.locator(`.swfg-card[data-swfg-variant="${af}"] [name="swf[last_name]"]`).boundingBox()];
  check('desktop: name fields side by side', Math.abs(wideFirst.y - wideLast.y) < 1 && wideLast.x > wideFirst.x);
  check('no script errors in the gallery', errors.length === 0, errors.join(' | '));

  // ---------- reduced motion ----------
  const calm = await newContext({ reducedMotion: 'reduce' });
  const calmPage = await calm.newPage();
  await calmPage.goto(local(fixture.gallery));
  check('reduced motion: no label transition', (await geometry(calmPage, af, 'first_name')).transition === '0s');
  await calmPage.locator(`.swfg-card[data-swfg-variant="${af}"] [name="swf[first_name]"]`).focus();
  check('reduced motion: state still changes (label floats)', Math.abs((await geometry(calmPage, af, 'first_name')).scale - 0.75) < 0.01);
  const calmButton = calmPage.locator(`${sm} .swf-submit`);
  await calmButton.hover();
  check('reduced motion: no light sweep or arrow movement', await calmButton.evaluate((el) => getComputedStyle(el, '::before').display === 'none' && getComputedStyle(el, '::after').transform === 'none'));
  await calm.close();

  // ---------- real form: unchanged data binding ----------
  const visitor = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const form = await visitor.newPage();
  await form.goto(local(fixture.url));
  check('published form renders the stored variant', await form.locator('.swf.swf--animated-float').count() === 1);
  check('prefilled value floats its label without interaction', Math.abs((await geometry(form, '', 'title')).scale - 0.75) < 0.01);
  await form.getByLabel('Anrede').selectOption('mr');
  await form.getByLabel('Vorname').fill('Erika');
  await form.getByLabel('Nachname').fill('Mustermann');
  await form.getByLabel('Nachricht').fill('Hallo');
  await form.getByRole('button', { name: 'Weiter' }).click();
  await form.locator('.swf-confirmation').waitFor({ timeout: 10000 });
  check('submission succeeds', (await form.locator('.swf-confirmation').textContent()).includes('Danke, Erika!'));
  const entries = php('entries', String(fixture.form));
  check('stored data unchanged by the design', JSON.stringify(entries.at(-1)) === JSON.stringify({ salutation: 'mr', first_name: 'Erika', last_name: 'Mustermann', title: 'Dr.', note: 'Hallo' }), JSON.stringify(entries));
  await visitor.close();
} finally {
  await browser.close();
  php('teardown', String(fixture.form), String(fixture.page));
}
console.log(failures === 0 ? 'designs e2e: all checks passed' : `designs e2e: ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
