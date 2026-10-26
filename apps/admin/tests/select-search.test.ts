import { describe, expect, it } from 'vitest';
import { filterSelect, initSelectSearch } from '../frontend/selectSearch';

function fixture(): { search: HTMLInputElement; select: HTMLSelectElement; status: HTMLElement } {
  document.body.innerHTML = `<div data-swf-id="a"><input class="swf-select-search" hidden data-swf-empty-message="No matches"><select multiple><option value="a" selected>Apfel</option><option value="b">Banane</option><option value="c">Äpfel</option></select><span class="swf-search-status"></span></div>`;
  return { search: document.querySelector('input')!, select: document.querySelector('select')!, status: document.querySelector('span')! };
}

describe('native select search', () => {
  it('enables search only after initialization, including cloned rows', () => {
    const { search } = fixture();
    expect(search.hidden).toBe(true);
    initSelectSearch(document);
    expect(search.hidden).toBe(false);
    const fragment = document.createDocumentFragment();
    const clone = search.cloneNode(true) as HTMLInputElement;
    clone.hidden = true;
    fragment.append(clone);
    initSelectSearch(fragment);
    expect(clone.hidden).toBe(false);
  });
  it('filters labels without losing selected values or changing option values', () => {
    const { search, select } = fixture();
    search.value = ' BAN ';
    filterSelect(search);
    expect(Array.from(select.options).map((option) => option.hidden)).toEqual([false, false, true]);
    expect(Array.from(select.selectedOptions).map((option) => option.value)).toEqual(['a']);
    expect(select.options[1]!.value).toBe('b');
  });
  it('announces empty results and restores all choices when cleared', () => {
    const { search, select, status } = fixture();
    search.value = 'unknown';
    filterSelect(search);
    expect(status.textContent).toBe('No matches');
    search.value = '';
    filterSelect(search);
    expect(status.textContent).toBe('');
    expect(Array.from(select.options).every((option) => !option.hidden)).toBe(true);
  });
  it('matches non-ASCII labels and affects only its own field', () => {
    const { search, select } = fixture();
    const second = select.cloneNode(true) as HTMLSelectElement;
    document.body.append(second);
    search.value = 'ÄPF';
    filterSelect(search);
    expect(select.options[2]!.hidden).toBe(false);
    expect(select.options[1]!.hidden).toBe(true);
    expect(second.options[1]!.hidden).toBe(false);
  });
});
