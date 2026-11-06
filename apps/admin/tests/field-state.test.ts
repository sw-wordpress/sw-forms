import { afterEach, describe, expect, it } from 'vitest';
import { clearValid, hasValue, markValid, syncFilled } from '../frontend/fieldState';

const field = (id: string, control: string, extra = '') => `<div class="swf-field swf-field--floatable${extra}" data-swf-id="${id}" data-swf-path="${id}"><label class="swf-label" for="${id}">${id}</label>${control}</div>`;
const el = (id: string) => document.querySelector<HTMLElement>(`[data-swf-id="${id}"]`)!;

afterEach(() => document.body.replaceChildren());

describe('field presentation state', () => {
  it('detects values of every control kind and ignores disabled controls', () => {
    document.body.innerHTML = [
      field('text', '<input id="text" value="  ">'),
      field('typed', '<input id="typed" value="Erika">'),
      field('off', '<input id="off" value="x" disabled>'),
      field('select', '<select id="select"><option value="">-</option><option value="a">A</option></select>'),
      field('multi', '<select id="multi" multiple><option value="a" selected>A</option></select>'),
      field('box', '<input type="checkbox" id="box">'),
      field('radio', '<input type="radio" id="radio" checked>'),
      field('file', '<input type="file" id="file">'),
      field('area', '<textarea id="area">Hi</textarea>'),
    ].join('');
    Object.defineProperty(el('file').querySelector('input'), 'files', { value: { length: 1 } });
    const result = Object.fromEntries(['text', 'typed', 'off', 'select', 'multi', 'box', 'radio', 'file', 'area'].map((id) => [id, hasValue(el(id))]));
    expect(result).toEqual({ text: false, typed: true, off: false, select: false, multi: true, box: false, radio: true, file: true, area: true });
  });

  it('synchronises the filled class for a whole scope or a single field, including programmatic values', () => {
    document.body.innerHTML = field('a', '<input id="a">') + field('b', '<select id="b"><option value="">-</option><option value="x">X</option></select>', ' swf-field--filled') + '<div class="swf-field" data-swf-path="c"><input value="x"></div>';
    el('a').querySelector('input')!.value = 'set by script';
    syncFilled(document);
    expect(el('a').classList.contains('swf-field--filled')).toBe(true);
    expect(el('b').classList.contains('swf-field--filled')).toBe(false);
    expect(document.querySelector('[data-swf-path="c"]')!.classList.contains('swf-field--filled')).toBe(false);
    el('b').querySelector('select')!.value = 'x';
    syncFilled(el('b'));
    expect(el('b').classList.contains('swf-field--filled')).toBe(true);
  });

  it('marks only validated, visible, enabled leaf fields with a value and no error', () => {
    document.body.innerHTML = [
      field('ok', '<input value="Erika">'),
      field('bad', '<input value="x">', ' swf-field--error'),
      field('empty', '<input value="">'),
      `<div hidden>${field('hidden', '<input value="x">')}</div>`,
      field('inactive', '<input value="x">').replace('data-swf-path', 'aria-disabled="true" data-swf-path'),
      `<fieldset class="swf-field" data-swf-path="group">${field('inner', '<input value="x">')}</fieldset>`,
    ].join('');
    markValid(document);
    const valid = [...document.querySelectorAll<HTMLElement>('.swf-field--valid')].map((f) => f.dataset.swfPath);
    expect(valid).toEqual(['ok', 'inner']);
    clearValid(el('ok'));
    clearValid(null);
    expect(el('ok').classList.contains('swf-field--valid')).toBe(false);
  });
});
