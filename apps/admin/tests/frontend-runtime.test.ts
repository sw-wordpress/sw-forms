import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import type { ElementNode } from '../shared/types';

const field = (id: string, type = 'input', kind: ElementNode['kind'] = 'text', extra: Partial<ElementNode> = {}): ElementNode => ({ id, type, kind, data: true, container: '', ...extra });
const condition = (id: string, op: string, value = '') => ({ action: 'show' as const, match: 'all' as const, rules: [{ field: id, op, value }] });
const wrap = (id: string, control: string) => `<div class="swf-field" data-swf-id="${id}" data-swf-path="${id}"><label class="swf-label">${id}</label>${control}<span id="${id}-err" class="swf-error-message" hidden></span></div>`;
const input = () => document.querySelector<HTMLInputElement>('.swf-upload input')!;
const files = (...names: string[]) => names.map((name) => new File(['document'], name)) as unknown as FileList;
const change = (element: HTMLInputElement) => element.dispatchEvent(new Event('change', { bubbles: true }));
const submit = () => document.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
const response = (data: unknown) => ({ json: async () => data }) as Response;
const drop = (target: Element, names?: string[], type = 'drop') => {
  const event = new Event(type, { bubbles: true, cancelable: true });
  if (names) Object.defineProperty(event, 'dataTransfer', { value: { files: files(...names) } });
  target.dispatchEvent(event);
  return event;
};
const enable = () => {
  const checkbox = document.querySelector<HTMLInputElement>('[name="swf[show]"]')!;
  checkbox.checked = true; change(checkbox);
};

async function mountForm(preview = true, custom?: { fields: ElementNode[]; html: string }) {
  const fields = custom?.fields ?? [field('show', 'checkbox', 'bool'), field('upload', 'file', 'list', { logic: condition('show', 'notempty') }), field('note', 'input', 'text', { logic: condition('upload', 'contains', '.pdf') })];
  const html = custom?.html ?? wrap('show', '<input type="checkbox" name="swf[show]">') + wrap('upload', '<div class="swf-upload"><input type="file" name="swf[upload][]"></div>') + wrap('note', '<input name="swf[note]">');
  const config = { formId: 7, rest: '/submit', ajax: '/ajax', preview, nonce: 'original', fields, i18n: { sending: 'Sending', networkError: 'Offline', summary: 'Correct fields' } };
  document.body.innerHTML = `<div><form class="swf-form" data-swf-form="7"><div class="swf-summary" tabindex="-1" hidden><p class="swf-summary__title"></p><ul></ul></div>${html}<input name="swf_token" value="old"><button class="swf-submit">Send</button><script class="swf-config" type="application/json">${JSON.stringify(config)}</script></form></div>`;
  document.querySelectorAll<HTMLInputElement>('input[type=file]').forEach((control) => Object.defineProperty(control, 'files', { value: files(), writable: true, configurable: true }));
  await import('../frontend/main');
}

beforeEach(() => {
  vi.resetModules();
  Object.defineProperty(document, 'readyState', { value: 'complete', configurable: true });
  vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => { callback(0); return 1; });
  vi.stubGlobal('IntersectionObserver', class { observe() {} disconnect() {} });
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response({ status: 'success', message: '<p>Saved</p>', redirect: '' })));
});
afterEach(() => {
  document.body.replaceChildren();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('actual frontend form runtime', () => {
  it('disables hidden uploads and cascades visibility through plain file names', async () => {
    await mountForm();
    expect(input().disabled).toBe(true);
    enable();
    expect(input().disabled).toBe(false);
    input().files = files('Consent.PDF'); change(input());
    expect(document.querySelector<HTMLElement>('[data-swf-id=note]')!.hidden).toBe(false);
    input().files = files(); change(input());
    expect(document.querySelector<HTMLInputElement>('[name="swf[note]"]')!.disabled).toBe(true);
  });
  it('accepts dropped files through the control and updates conditions', async () => {
    await mountForm(); enable();
    const upload = document.querySelector('.swf-upload')!;
    expect(drop(upload, undefined, 'dragover').defaultPrevented).toBe(true);
    expect(drop(upload, ['one.pdf', 'two.txt']).defaultPrevented).toBe(true);
    expect(Array.from(input().files!).map((file) => file.name)).toEqual(['one.pdf', 'two.txt']);
    expect(document.querySelector<HTMLElement>('[data-swf-id=note]')!.hidden).toBe(false);
  });
  it('ignores disabled uploads, unrelated drop targets and missing file data', async () => {
    await mountForm();
    expect(drop(input(), ['one.pdf']).defaultPrevented).toBe(false);
    expect(input().files).toHaveLength(0);
    expect(drop(document.querySelector('form')!, ['two.pdf']).defaultPrevented).toBe(false);
    enable(); drop(input());
    expect(input().files).toHaveLength(0);
  });
  it('shows server errors as text, focuses the summary and clears errors on changes', async () => {
    vi.mocked(fetch).mockResolvedValue(response({ status: 'invalid', message: 'Invalid', errors: { upload: '<b>Wrong type</b>' } }));
    await mountForm(); enable(); submit(); await flushPromises();
    expect(document.activeElement).toBe(document.querySelector('.swf-summary'));
    expect(document.querySelector('.swf-error-message:not([hidden])')!.textContent).toBe('<b>Wrong type</b>');
    expect(document.querySelector('.swf-summary b')).toBeNull();
    document.querySelector<HTMLAnchorElement>('.swf-summary a')!.click();
    expect(document.activeElement).toBe(input());
    change(input());
    expect(input().hasAttribute('aria-invalid')).toBe(false);
    expect(document.querySelector('.swf-field--error')).toBeNull();
  });
  it('sends multipart data with its nonce and replaces a successful public form', async () => {
    await mountForm(false); submit(); await flushPromises();
    const init = vi.mocked(fetch).mock.calls[0]![1]!;
    expect(init.headers).toEqual({ Accept: 'application/json', 'X-WP-Nonce': 'original' });
    expect(init.body).toBeInstanceOf(FormData);
    expect((init.body as FormData).get('swf_source')).toBe(window.location.href);
    expect(document.querySelector('.swf-confirmation')!.textContent).toBe('Saved');
    expect(document.querySelector('form')).toBeNull();
  });
  it('keeps the preview editable after successful validation', async () => {
    await mountForm(); submit(); await flushPromises();
    expect(document.querySelector('form')).not.toBeNull();
    expect(document.querySelector('.swf-summary--ok')!.textContent).toContain('Saved');
    expect((vi.mocked(fetch).mock.calls[0]![1]!.body as FormData).get('swf_preview')).toBe('1');
  });
  it('restores controls after a network error so visitors can retry', async () => {
    vi.mocked(fetch).mockRejectedValueOnce(new TypeError('offline'));
    await mountForm(); submit(); await flushPromises();
    expect(document.querySelector('.swf-summary')!.textContent).toContain('Offline');
    expect(document.querySelector<HTMLButtonElement>('.swf-submit')!.disabled).toBe(false);
    expect(document.querySelector('form')!.hasAttribute('aria-busy')).toBe(false);
    submit(); await flushPromises(); expect(fetch).toHaveBeenCalledTimes(2);
  });
  it('prevents concurrent submissions', async () => {
    let resolve!: (value: Response) => void;
    vi.mocked(fetch).mockReturnValue(new Promise((done) => { resolve = done; }));
    await mountForm(); submit(); submit(); expect(fetch).toHaveBeenCalledTimes(1);
    expect(document.querySelector<HTMLButtonElement>('.swf-submit')!.disabled).toBe(true);
    resolve(response({ status: 'invalid', message: 'Invalid', errors: {} })); await flushPromises();
    expect(document.querySelector<HTMLButtonElement>('.swf-submit')!.disabled).toBe(false);
  });
  it('refreshes expired tokens and retries once using the refreshed nonce', async () => {
    vi.mocked(fetch).mockResolvedValueOnce(response({ status: 'error', code: 'expired' })).mockResolvedValueOnce(response({ success: true, data: { token: 'fresh', nonce: 'new-nonce' } })).mockResolvedValueOnce(response({ status: 'error', code: 'expired', message: 'Reload' }));
    await mountForm(false); submit(); await flushPromises();
    expect(fetch).toHaveBeenCalledTimes(3);
    expect(vi.mocked(fetch).mock.calls[1]![0]).toContain('action=swf_token&form=7');
    expect(vi.mocked(fetch).mock.calls[2]![1]!.headers).toMatchObject({ 'X-WP-Nonce': 'new-nonce' });
    expect(document.querySelector<HTMLInputElement>('[name=swf_token]')!.value).toBe('fresh');
    expect(document.querySelector('.swf-summary')!.textContent).toContain('Reload');
  });
  it('preserves repeater file names and places errors in the correct row', async () => {
    const rows = field('rows', 'group', 'none', { container: 'children', settings: { repeatable: true }, children: [field('attachment', 'file', 'list')] });
    const row = `<div class="swf-row" data-swf-row="__INDEX__"><div class="swf-row__fields">${wrap('attachment', '<div class="swf-upload"><label for="row-__INDEX__">Attachment</label><input type="file" id="row-__INDEX__" name="swf[rows][__INDEX__][attachment][]"></div>')}</div><button type="button" class="swf-row-remove">Remove</button></div>`;
    await mountForm(true, { fields: [rows], html: `<fieldset class="swf-repeater" data-swf-id="rows" data-swf-min="0" data-swf-max="2"><div class="swf-rows"></div><template class="swf-row-template">${row}</template><button type="button" class="swf-row-add">Add</button></fieldset>` });
    const add = document.querySelector<HTMLButtonElement>('.swf-row-add')!; add.click(); add.click();
    const inputs = Array.from(document.querySelectorAll<HTMLInputElement>('input[type=file]'));
    expect(inputs.map((control) => control.name)).toEqual(['swf[rows][1000][attachment][]', 'swf[rows][1001][attachment][]']);
    expect(add.hidden).toBe(true);
    vi.mocked(fetch).mockResolvedValue(response({ status: 'invalid', message: 'Fix row', errors: { 'rows[1].attachment': 'Wrong type' } }));
    submit(); await flushPromises();
    expect(inputs[0]!.hasAttribute('aria-invalid')).toBe(false);
    expect(inputs[1]!.getAttribute('aria-invalid')).toBe('true');
    document.querySelector<HTMLButtonElement>('.swf-row-remove')!.click();
    expect(document.querySelectorAll('.swf-row')).toHaveLength(1); expect(add.hidden).toBe(false);
  });
});
