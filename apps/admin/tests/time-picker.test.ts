import { afterEach, describe, expect, it } from 'vitest';
import cases from '../../../tests/fixtures/time-options.json';
import { TimePickers } from '../frontend/timePicker';
let manager: TimePickers;
afterEach(() => { manager?.destroy(); document.body.replaceChildren(); });
function setup(min = '', max = '', step = 60, value = '') {
  document.body.innerHTML = `<form><div><label for="from">From</label><input type="time" id="from" name="swf[times][from]" data-swf-time-picker min="${min}" max="${max}" step="${step}" value="${value}" aria-describedby="help" data-swf-time-labels='{"hour":"Hours","minute":"Minutes","second":"Seconds"}'></div></form>`;
  const form = document.querySelector('form')!; const input = form.querySelector('input')!;
  manager = new TimePickers(form); manager.sync();
  const select = (unit: string) => form.querySelector<HTMLSelectElement>(`[data-swf-time-unit=${unit}]`)!;
  const choose = (unit: string, value: string) => { select(unit).value = value; select(unit).dispatchEvent(new Event('change', { bubbles: true })); };
  return { form, input, select, choose };
}
describe('interval time picker', () => {
  for (const item of cases) it(`offers only server-valid combinations ${JSON.stringify(item)}`, () => {
    const { input, select, choose, form } = setup(item.min, item.max, item.step);
    const values: string[] = [];
    for (const hour of Array.from(select('hour').options).map((o) => o.value).filter(Boolean)) {
      choose('hour', hour);
      for (const minute of Array.from(select('minute').options).map((o) => o.value).filter(Boolean)) {
        choose('minute', minute);
        for (const second of Array.from(select('second').options).map((o) => o.value).filter(Boolean)) {
          choose('second', second); values.push(input.value);
          expect(new FormData(form).get('swf[times][from]')).toBe(input.value);
        }
      }
    }
    expect(values).toEqual(item.allowed);
  });
  it('keeps the DOM bounded for one-second steps and handles clearing/reset', () => {
    const { form, input, choose } = setup('', '', 1, '10:30');
    expect(form.querySelectorAll('option').length).toBeLessThan(150);
    choose('hour', '23'); choose('minute', '59'); choose('second', '59'); expect(input.value).toBe('23:59:59');
    choose('minute', ''); expect(input.value).toBe('');
    form.reset(); manager.sync(); expect(input.value).toBe('10:30');
  });
  it('preserves invalid postback values, propagates accessibility/disabled states and cleans removed rows', () => {
    const { form, input, select } = setup('09:00', '10:00', 900, '09:10');
    expect(input.value).toBe('09:10'); expect(select('hour').value).toBe('');
    input.disabled = true; input.setAttribute('aria-invalid', 'true'); manager.sync(); expect(select('hour').disabled).toBe(true);
    expect(select('hour').getAttribute('aria-invalid')).toBe('true'); expect(select('minute').getAttribute('aria-describedby')).toBe('help');
    input.disabled = false; input.removeAttribute('aria-invalid'); manager.sync(); expect(select('hour').hasAttribute('aria-invalid')).toBe(false);
    input.parentElement!.remove(); manager.sync(); expect(input.hidden).toBe(false); expect(form.querySelectorAll('.swf-time-picker')).toHaveLength(0);
  });
  it('initializes repeated controls once, leaving non-interval time inputs native', () => {
    const { form, input } = setup();
    const row = document.createElement('div'); row.innerHTML = '<input type="time" data-swf-time-picker id="repeat" step="900">'; form.appendChild(row);
    form.appendChild(document.createElement('input')).type = 'time'; manager.sync(); manager.sync();
    expect(form.querySelectorAll('.swf-time-picker')).toHaveLength(2); expect(input.hidden).toBe(true);
    manager.destroy(); expect(input.hidden).toBe(false); expect(form.querySelector('label')!.htmlFor).toBe(input.id);
  });
});
