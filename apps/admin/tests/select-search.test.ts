import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type TomSelect from 'tom-select';
import { SelectSearch } from '../frontend/selectSearch';
let form: HTMLFormElement;
let select: HTMLSelectElement;
let manager: SelectSearch;
const control = () => (select as HTMLSelectElement & { tomselect: TomSelect }).tomselect;
beforeEach(() => {
  document.body.innerHTML = '<form><label for="fruit">Fruit</label><select id="fruit" name="fruit[]" data-swf-search data-swf-empty-message="No matches" aria-describedby="hint" multiple><option value="a" selected>Apfel</option><option value="b">Banane</option><option value="c">Äpfel</option></select><p id="hint">Choose fruit</p><select id="native"><option>Native</option></select></form>';
  form = document.querySelector('form')!; select = form.querySelector('select')!;
  manager = new SelectSearch(form); manager.sync();
});
afterEach(() => { manager.destroy(); document.body.replaceChildren(); });
describe('locally bundled Tom Select', () => {
  it('routes programmatic focus to the visible widget and preserves ordinary focus targets', () => {
    manager.focus(select); expect(document.activeElement).toBe(control().control_input);
    const ordinary = document.createElement('input'); form.appendChild(ordinary); manager.focus(ordinary); expect(document.activeElement).toBe(ordinary);
    manager.focus(null); manager.focus(undefined);
  });
  it('keeps a single-select search query while the empty placeholder is selected', () => {
    manager.destroy(); select.multiple = false; select.insertAdjacentHTML('afterbegin', '<option value="">Please choose</option>'); select.value = '';
    manager = new SelectSearch(form); manager.sync(); control().setTextboxValue('Gamma'); manager.sync();
    expect(control().control_input.value).toBe('Gamma');
    control().setValue('b'); expect(select.value).toBe('b');
  });
  it('provides one combined searchable control, preserves selection and skips plain selects', () => {
    expect(form.querySelectorAll('.ts-wrapper')).toHaveLength(1);
    expect(control().items).toEqual(['a']); expect(control().control_input.getAttribute('aria-describedby')).toBe('hint');
    expect(control().control_input.getAttribute('aria-labelledby')).toBeTruthy();
    expect(control().search('BAN').items.map((item) => item.id)).toEqual(['b']);
    expect(control().search('Äpf').items.map((item) => item.id)).toContain('c');
    expect(select.selectedOptions[0]?.value).toBe('a');
    manager.sync(); expect(form.querySelectorAll('.ts-wrapper')).toHaveLength(1);
  });
  it('keeps native multipart values and change events in sync, without allowing free text', () => {
    let changes = 0; select.addEventListener('change', () => { changes++; });
    control().setValue(['a', 'b']); expect(new FormData(form).getAll('fruit[]')).toEqual(['a', 'b']); expect(changes).toBeGreaterThan(0);
    control().setValue(['unconfigured']); expect(control().items).toEqual([]);
    select.querySelector<HTMLOptionElement>('option[value=c]')!.selected = true; manager.sync(); expect(control().items).toEqual(['c']);
    expect(control().settings.create).toBe(false);
  });
  it('synchronizes hidden/visible controls and server error accessibility', () => {
    select.disabled = true; select.setAttribute('aria-invalid', 'true'); manager.sync();
    expect(control().isDisabled).toBe(true); expect(control().control_input.disabled).toBe(true);
    expect(control().control_input.getAttribute('aria-invalid')).toBe('true');
    select.disabled = false; select.removeAttribute('aria-invalid'); manager.sync(); expect(control().isDisabled).toBe(false);
    expect(control().control_input.hasAttribute('aria-invalid')).toBe(false);
  });
  it('initializes added repeater controls and destroys removed instances', () => {
    const added = select.cloneNode(true) as HTMLSelectElement; added.id = 'repeat'; added.removeAttribute('class'); form.appendChild(added); manager.sync();
    const instance = (added as HTMLSelectElement & { tomselect: TomSelect }).tomselect;
    expect(instance).toBeTruthy(); added.remove(); manager.sync(); expect((added as HTMLSelectElement & { tomselect?: TomSelect }).tomselect).toBeUndefined();
  });
  it('restores defaults and clears search on reset', () => {
    control().setValue(['b']); control().setTextboxValue('missing'); form.reset(); manager.reset();
    expect(control().items).toEqual(['a']); expect(control().control_input.value).toBe('');
  });
  it('escapes the translated no-results message and retains native controls on teardown', () => {
    select.dataset.swfEmptyMessage = '<img src=x>';
    control().setTextboxValue('missing'); control().refreshOptions(false);
    expect(form.querySelector('.no-results')?.textContent).toBe('<img src=x>'); expect(form.querySelector('.no-results img')).toBeNull();
    manager.destroy(); expect(form.querySelectorAll('.ts-wrapper')).toHaveLength(0); expect(select.classList.contains('ts-hidden-accessible')).toBe(false);
  });
});
