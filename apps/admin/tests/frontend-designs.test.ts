import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import type { ElementNode } from '../shared/types';

/** Runtime behaviour the design variants rely on: filled/valid state and the gallery demo mode. */
const node = (id: string, extra: Partial<ElementNode> = {}): ElementNode => ({ id, type: 'input', kind: 'text', data: true, container: '', ...extra });
const field = (id: string, control: string) => `<div class="swf-field swf-field--floatable" data-swf-id="${id}" data-swf-path="${id}"><label class="swf-label" for="f-${id}">${id}</label>${control}<p id="f-${id}-err" class="swf-error-message" hidden></p></div>`;
const html = field('salutation', '<select id="f-salutation" name="swf[salutation]"><option value="">-</option><option value="ms">Ms</option></select>')
  + field('first', '<input id="f-first" name="swf[first]" placeholder=" ">')
  + field('title', '<input id="f-title" name="swf[title]" value="Dr.">')
  + field('total', '<input id="f-total" name="swf[total]" readonly>');
const fields = [node('salutation'), node('first', { required: true }), node('title'), node('total', { type: 'calculation', settings: { operation: 'sum', decimals: 0, sources: [{ field: 'title' }] } })];
const response = (data: unknown) => ({ json: async () => data }) as Response;
const $ = <T extends HTMLElement>(selector: string) => document.querySelector<T>(selector)!;
const classes = (id: string) => $(`[data-swf-id="${id}"]`).classList;
const submit = () => $('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

async function mount(config: Record<string, unknown> = {}, body = html) {
  const full = { formId: 4, rest: '/submit', ajax: '/ajax', preview: true, nonce: '', fields, i18n: { sending: 'Sending', networkError: 'Offline', summary: 'Correct fields', required: 'Required', demoComplete: 'Demo done' }, ...config };
  document.body.innerHTML = `<div class="swf swf--animated-float"><form class="swf-form" data-swf-form="4"><div class="swf-summary" tabindex="-1" hidden><p class="swf-summary__title"></p><ul></ul></div>${body}<button class="swf-submit">Weiter</button><script class="swf-config" type="application/json">${JSON.stringify(full)}</script></form></div>`;
  await import('../frontend/main');
}

beforeEach(() => {
  vi.resetModules();
  Object.defineProperty(document, 'readyState', { value: 'complete', configurable: true });
  vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => { callback(0); return 1; });
  vi.stubGlobal('IntersectionObserver', class { observe() {} disconnect() {} });
  vi.stubGlobal('fetch', vi.fn());
});
afterEach(() => {
  document.body.replaceChildren();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('design state in the form runtime', () => {
  it('keeps floating labels up for prefilled, typed, selected, calculated and restored values', async () => {
    await mount();
    expect(classes('title').contains('swf-field--filled')).toBe(true);
    expect(classes('first').contains('swf-field--filled')).toBe(false);
    // Calculations write values programmatically.
    expect(classes('total').contains('swf-field--filled')).toBe(false);

    const first = $<HTMLInputElement>('#f-first');
    first.value = 'Erika';
    first.dispatchEvent(new Event('input', { bubbles: true }));
    expect(classes('first').contains('swf-field--filled')).toBe(true);
    first.value = '';
    first.dispatchEvent(new Event('input', { bubbles: true }));
    expect(classes('first').contains('swf-field--filled')).toBe(false);

    const select = $<HTMLSelectElement>('#f-salutation');
    select.value = 'ms';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    expect(classes('salutation').contains('swf-field--filled')).toBe(true);

    // Browser form restoration (back/forward cache) sets values without events.
    first.value = 'Restored';
    window.dispatchEvent(new Event('pageshow'));
    expect(classes('first').contains('swf-field--filled')).toBe(true);
  });

  it('shows success ticks only for fields the server validated without error', async () => {
    await mount();
    $<HTMLInputElement>('#f-first').value = 'Erika';
    vi.mocked(fetch).mockResolvedValueOnce(response({ status: 'invalid', message: '', errors: { salutation: 'Pick one' } }));
    submit();
    await flushPromises();
    expect(classes('salutation').contains('swf-field--error')).toBe(true);
    expect(classes('salutation').contains('swf-field--valid')).toBe(false);
    expect(classes('first').contains('swf-field--valid')).toBe(true);
    expect(classes('title').contains('swf-field--valid')).toBe(true);
    // Editing invalidates the tick until the next validation.
    $('#f-first').dispatchEvent(new Event('input', { bubbles: true }));
    expect(classes('first').contains('swf-field--valid')).toBe(false);

    // Network errors are not a validation result.
    vi.mocked(fetch).mockRejectedValueOnce(new Error('offline'));
    submit();
    await flushPromises();
    expect(classes('first').contains('swf-field--valid')).toBe(false);

    $('form').dispatchEvent(new Event('reset'));
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(document.querySelector('.swf-field--valid')).toBeNull();
  });

  it('marks the fields of a validated step as valid', async () => {
    const steps = `<section class="swf-step" data-swf-step="0"><h3 tabindex="-1">A</h3>${field('first', '<input id="f-first" name="swf[first]" value="Erika">')}</section><section class="swf-step" data-swf-step="1"><h3 tabindex="-1">B</h3>${field('title', '<input id="f-title" name="swf[title]" value="Dr.">')}</section><div class="swf-step-nav" hidden><button type="button" class="swf-step-back">Back</button><button type="button" class="swf-step-next">Next</button></div>`;
    await mount({ fields: [node('first'), node('title')] }, steps);
    vi.mocked(fetch).mockResolvedValueOnce(response({ status: 'success', code: 'step' }));
    $('.swf-step-next').click();
    await flushPromises();
    expect(classes('first').contains('swf-field--valid')).toBe(true);
    expect(classes('title').contains('swf-field--valid')).toBe(false);
  });

  it('validates demo forms locally and never sends anything', async () => {
    await mount({ demo: true, rest: '' });
    submit();
    await flushPromises();
    expect(fetch).not.toHaveBeenCalled();
    expect(classes('first').contains('swf-field--error')).toBe(true);
    expect($('#f-first-err').textContent).toBe('Required');
    expect($('#f-first').getAttribute('aria-invalid')).toBe('true');
    expect(classes('title').contains('swf-field--valid')).toBe(true);
    expect($('.swf-summary').textContent).toContain('Correct fields');

    $<HTMLInputElement>('#f-first').value = 'Erika';
    $('#f-first').dispatchEvent(new Event('input', { bubbles: true }));
    submit();
    await flushPromises();
    expect(fetch).not.toHaveBeenCalled();
    expect($('.swf-summary').classList.contains('swf-summary--ok')).toBe(true);
    expect($('.swf-summary__title').textContent).toBe('Demo done');
    expect(classes('first').contains('swf-field--valid')).toBe(true);
    expect($('.swf-submit').textContent).toBe('Weiter');
  });

  it('walks through demo steps locally', async () => {
    const steps = `<section class="swf-step" data-swf-step="0"><h3 tabindex="-1">A</h3>${field('first', '<input id="f-first" name="swf[first]">')}</section><section class="swf-step" data-swf-step="1"><h3 tabindex="-1">B</h3>${field('title', '<input id="f-title" name="swf[title]">')}</section><div class="swf-step-nav" hidden><button type="button" class="swf-step-back">Back</button><button type="button" class="swf-step-next">Next</button></div>`;
    await mount({ demo: true, fields: [node('first', { required: true }), node('title', { required: true })], i18n: { sending: '', networkError: '', summary: 'Correct fields' } }, steps);
    const pages = [...document.querySelectorAll<HTMLElement>('.swf-step')];
    $('.swf-step-next').click();
    await flushPromises();
    expect(pages[1]!.hidden).toBe(true);
    expect($('#f-first-err').textContent).toBe('');
    expect(classes('title').contains('swf-field--error')).toBe(false);
    $<HTMLInputElement>('#f-first').value = 'Erika';
    $('.swf-step-next').click();
    await flushPromises();
    expect(pages[1]!.hidden).toBe(false);
    expect(fetch).not.toHaveBeenCalled();
  });
});
