import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ElementPreview from '@/features/builder/ElementPreview.vue';
import SettingControl from '@/features/builder/SettingControl.vue';
import type { SettingDefinition } from '@/types/api';
import type { ElementNode } from '@shared/types';
import { change, controls, global } from './builder-panels-helpers';

const preview = (element: Partial<ElementNode>, known = true) =>
  mount(ElementPreview, { props: { element: { id: 'e', type: 'input', ...element }, known }, global: global() });

describe('ElementPreview', () => {
  it('marks unknown add-on types and renders layout elements', () => {
    expect(preview({ type: 'acme' }, false).text()).toBe('Element type "acme" is not available (add-on missing?)');
    expect(preview({ type: 'acme' }).text()).toBe('Element of an add-on: shown in the preview');
    expect(preview({ type: 'page-break', label: 'Contact' }).text()).toBe('Contact');
    expect(preview({ type: 'page-break' }).text()).toBe('Page break');
    expect(preview({ type: 'html', settings: { content: '<b>Hello</b>\nworld' } }).text()).toBe('Hello\nworld');
    expect(preview({ type: 'html' }).text()).toBe('');
    const calc = preview({ type: 'calculation', label: 'Total' });
    expect(calc.text()).toBe('Total');
    expect(calc.find('input').element.value).toBe('0.00');
  });

  it('renders single checkboxes and switches', () => {
    const single = preview({ type: 'checkbox', label: 'Agree', required: true, description: 'Needed', settings: { mode: 'single', checked: true, switch: true, text: '<a>Terms</a>' } });
    expect(single.find('.swf-preview__box').classes()).toEqual(expect.arrayContaining(['swf-preview__box--on', 'swf-preview__switch']));
    expect(single.text()).toBe('Terms*Needed');
    const plain = preview({ type: 'checkbox', label: 'Agree', settings: { mode: 'single' } });
    expect(plain.find('.swf-preview__box').classes()).not.toContain('swf-preview__box--on');
    expect(plain.text()).toBe('Agree');
  });

  it('renders text inputs with label, placeholder and icons', () => {
    const hidden = preview({ type: 'input', label: 'Ref', required: true, settings: { inputType: 'hidden' } });
    expect(hidden.find('.swf-preview__label').text()).toBe('Ref*hidden field');
    expect(hidden.find('.swf-preview__input').exists()).toBe(false);
    expect(preview({ type: 'input', label: 'Name', settings: { placeholder: 'Your name' } }).find('.swf-preview__placeholder').text()).toBe('Your name');
    expect(preview({ type: 'input', settings: { default: 'Bob' } }).find('.swf-preview__placeholder').text()).toBe('Bob');
    expect(preview({ type: 'input' }).find('.swf-preview__label').exists()).toBe(false);
    expect(preview({ type: 'input', settings: { inputType: 'date' } }).html()).toContain('r_calendar_today');
    expect(preview({ type: 'input', settings: { inputType: 'email' } }).html()).toContain('r_alternate_email');
  });

  it.each([['file', 'r_upload_file'], ['image', 'r_add_photo_alternate'], ['signature', 'r_draw'], ['audio', 'r_mic'], ['video', 'r_videocam']])('renders %s uploads', (type, icon) => {
    const wrapper = preview({ type, settings: { extensions: 'pdf' } });
    expect(wrapper.html()).toContain(icon);
    expect(wrapper.text()).toBe('pdf');
  });

  it('renders text areas, tags and selects', () => {
    expect(preview({ type: 'textarea', settings: { rows: 6, placeholder: 'More' } }).find('.swf-preview__input--area').attributes('style')).toContain('min-height: 84px');
    expect(preview({ type: 'tags', settings: { rows: 1 } }).find('.swf-preview__input--area').attributes('style')).toContain('min-height: 28px');
    expect(preview({ type: 'textarea' }).find('.swf-preview__input--area').attributes('style')).toContain('min-height: 56px');
    const options = [{ label: 'A', value: 'a' }, { label: 'B', value: 'b', image: 'https://x/b.png' }, { label: 'C', value: 'c', image: 'javascript:x' }];
    expect(preview({ type: 'select', settings: { options, multiple: true } }).text()).toBe('A, B, C');
    expect(preview({ type: 'select', settings: { options, placeholder: 'Pick' } }).text()).toBe('Pick');
  });

  it('renders checkbox and radio choices with defaults, images and an other option', () => {
    const options = [{ label: 'A', value: 'a' }, { label: 'B', value: 'b', image: 'https://x/b.png' }, { label: 'C', value: 'c', image: 'javascript:x' }];
    const boxes = preview({ type: 'checkbox', settings: { options, defaultValues: ['b'], layout: 'horizontal', presentation: 'images' } });
    expect(boxes.find('.swf-preview__choices').classes()).toEqual(expect.arrayContaining(['swf-preview__choices--row', 'swf-preview__choices--cards']));
    expect(boxes.findAll('.swf-preview__box').map((b) => b.classes().includes('swf-preview__box--on'))).toEqual([false, true, false]);
    expect(boxes.findAll('img').map((i) => i.attributes('src'))).toEqual(['https://x/b.png']);
    const radios = preview({ type: 'radio', settings: { options, default: 'c', other: true, otherLabel: 'Other', presentation: 'buttons' } });
    expect(radios.findAll('.swf-preview__radio')).toHaveLength(4);
    expect(radios.findAll('.swf-preview__box--on')).toHaveLength(1);
    expect(radios.text()).toContain('Other');
    expect(radios.findAll('img')).toHaveLength(0);
    const empty = preview({ type: 'radio', settings: { options: 'broken' } });
    expect(empty.text()).toBe('No options yet');
    expect(empty.find('.swf-preview__choices').classes()).not.toContain('swf-preview__choices--row');
  });

  it('renders matrix, date, interval, range, color and score elements', () => {
    const matrix = preview({ type: 'matrix', settings: { options: [{ label: 'Good', value: 'g' }, { label: 'Bad', value: 'b' }], rows: [{ label: 'Food', value: 'f' }] } });
    expect(matrix.findAll('th').map((th) => th.text())).toEqual(['Good', 'Bad', 'Food']);
    expect(matrix.findAll('tbody .swf-preview__radio')).toHaveLength(2);
    expect(preview({ type: 'matrix' }).findAll('tbody tr')).toHaveLength(0);
    expect(preview({ type: 'birthdate' }).find('input[type="date"]').exists()).toBe(true);
    const dates = preview({ type: 'date-interval', settings: { defaultFrom: '2026-01-01', defaultTo: '2026-01-05' } }).findAll('input');
    expect(dates.map((i) => [i.attributes('type'), i.element.value])).toEqual([['date', '2026-01-01'], ['date', '2026-01-05']]);
    expect(preview({ type: 'time-interval' }).findAll('input[type="time"]')).toHaveLength(2);
    const range = preview({ type: 'range', settings: { min: 5, max: 50, step: 5, default: 10 } }).find('input');
    expect([range.attributes('min'), range.attributes('max'), range.attributes('step'), range.element.value]).toEqual(['5', '50', '5', '10']);
    expect(preview({ type: 'range' }).find('input').attributes('max')).toBe('100');
    expect(preview({ type: 'color', settings: { default: '#AbCdEf' } }).find('input').element.value).toBe('#abcdef');
    expect(preview({ type: 'color', settings: { default: 'red' } }).find('input').element.value).toBe('#000000');
    expect(preview({ type: 'rating' }).text()).toBe('★★★★★');
    expect(preview({ type: 'rating', settings: { max: 3, min: 2 } }).text()).toBe('★★★');
    expect(preview({ type: 'scale' }).findAll('.swf-preview__scores span').map((s) => s.text())).toEqual(['1', '2', '3', '4', '5', '6', '7', '8', '9', '10']);
    expect(preview({ type: 'scale', settings: { min: -3, max: 30 } }).findAll('.swf-preview__scores span')).toHaveLength(21);
    expect(preview({ type: 'scale', settings: { min: 8, max: 2 } }).findAll('.swf-preview__scores span')).toHaveLength(0);
    expect(preview({ type: 'scale', description: 'How likely?' }).find('.swf-preview__desc').text()).toBe('How likely?');
  });
});

const setting = (patch: Partial<SettingDefinition>): SettingDefinition => ({ name: 'x', type: 'text', tab: 'general', label: 'X', ...patch });
const control = (def: Partial<SettingDefinition>, value: unknown = null, extra: Record<string, unknown> = {}) =>
  mount(SettingControl, { props: { setting: setting(def), value, settings: {}, ...extra }, global: global() });
const emitted = (wrapper: ReturnType<typeof control>) => (wrapper.emitted('update') ?? []).map((e) => e[0]);

describe('SettingControl', () => {
  it('renders text, code, textarea and html settings as inputs', async () => {
    const text = control({ type: 'text', help: 'Help' }, 5);
    const input = controls(text, 'QInput')[0]!;
    expect([input.props('modelValue'), input.props('hint')]).toEqual(['5', 'Help']);
    await change(input, 'abc');
    await change(input, null);
    expect(emitted(text)).toEqual(['abc', '']);
    expect(controls(control({ type: 'code' }), 'QInput')[0]!.attributes('input-class')).toBe('swf-mono');
    expect(controls(control({ type: 'unknown' }, undefined), 'QInput')[0]!.props('modelValue')).toBe('');
    for (const type of ['textarea', 'html']) {
      const area = control({ type }, 'Hi');
      const field = controls(area, 'QInput')[0]!;
      expect(field.attributes('type')).toBe('textarea');
      await change(field, 'Ho');
      await change(field, undefined);
      expect(emitted(area)).toEqual(['Ho', '']);
    }
  });

  it('parses numbers and keeps integers whole', async () => {
    const number = control({ type: 'number', min: 1, max: 9 }, 3);
    const input = controls(number, 'QInput')[0]!;
    expect(input.attributes('type')).toBe('number');
    for (const raw of ['4.5', 7, '', null, 'abc']) await change(input, raw);
    expect(emitted(number)).toEqual([4.5, 7, null, null]);
    const integer = control({ type: 'number', integer: true });
    await change(controls(integer, 'QInput')[0]!, '4.9');
    expect(emitted(integer)).toEqual([4]);
  });

  it('renders toggles, selects, lists, options and field pickers', async () => {
    const toggle = control({ type: 'toggle' }, true);
    expect(controls(toggle, 'QToggle')[0]!.props('modelValue')).toBe(true);
    await change(controls(toggle, 'QToggle')[0]!, false);
    expect(emitted(toggle)).toEqual([false]);
    expect(controls(control({ type: 'toggle' }, 'yes'), 'QToggle')[0]!.props('modelValue')).toBe(false);

    const choices = [{ value: 'a', label: 'A' }];
    const select = control({ type: 'select', choices }, 'a');
    expect(controls(select, 'QSelect')[0]!.props('options')).toEqual(choices);
    await change(controls(select, 'QSelect')[0]!, 'b');
    expect(emitted(select)).toEqual(['b']);
    expect(controls(control({ type: 'select' }), 'QSelect')[0]!.props('options')).toEqual([]);

    const list = mount(SettingControl, { props: { setting: setting({ type: 'list' }), value: ['a'], settings: { options: choices } }, global: global() });
    const listSelect = controls(list, 'QSelect')[0]!;
    expect([listSelect.props('modelValue'), listSelect.props('options')]).toEqual([['a'], choices]);
    await change(listSelect, ['a', 'b']);
    await change(listSelect, null);
    expect(emitted(list)).toEqual([['a', 'b'], []]);
    const noList = control({ type: 'list' }, 'a');
    expect([controls(noList, 'QSelect')[0]!.props('modelValue'), controls(noList, 'QSelect')[0]!.props('options')]).toEqual([[], []]);

    const options = control({ type: 'options', help: 'One per line', images: true }, [{ label: 'A', value: 'a' }]);
    expect(options.text()).toBe('XOne per line');
    const editor = controls(options, 'OptionsEditor')[0]!;
    expect([editor.props('modelValue'), editor.attributes('allow-images')]).toEqual([[{ label: 'A', value: 'a' }], 'true']);
    await change(editor, []);
    expect(emitted(options)).toEqual([[]]);
    expect(controls(control({ type: 'options' }, 'broken'), 'OptionsEditor')[0]!.props('modelValue')).toEqual([]);

    const fields = [{ value: 'a', label: 'A', type: 'input' }, { value: 'f', label: 'F', type: 'file' }];
    const picker = control({ type: 'field-picker', fieldTypes: ['file'] }, 'f', { fields });
    expect(controls(picker, 'QSelect')[0]!.props('options')).toEqual([fields[1]]);
    await change(controls(picker, 'QSelect')[0]!, null);
    await change(controls(picker, 'QSelect')[0]!, 'a');
    expect(emitted(picker)).toEqual(['', 'a']);
    expect(controls(control({ type: 'field-picker' }, null, { fields }), 'QSelect')[0]!.props('options')).toEqual(fields);
  });

  it('edits key-value rows', async () => {
    const wrapper = control({ type: 'key-value', help: 'Pairs', maxItems: 2 }, [{ key: 'a', value: '1' }, 'junk', ['nested']]);
    expect(wrapper.find('legend').text()).toBe('X');
    expect(wrapper.text()).toContain('Pairs');
    const inputs = controls(wrapper, 'QInput');
    expect(inputs.map((i) => [i.props('label'), i.props('modelValue')])).toEqual([['Key', 'a'], ['Value', '1']]);
    await change(inputs[1]!, '2');
    expect(emitted(wrapper).at(-1)).toEqual([{ key: 'a', value: '2' }]);
    await wrapper.find('button[aria-label="Add row"]').trigger('click');
    expect(emitted(wrapper).at(-1)).toEqual([{ key: 'a', value: '1' }, { key: null, value: null }]);
    await wrapper.find('button[aria-label="Remove row 1"]').trigger('click');
    expect(emitted(wrapper).at(-1)).toEqual([]);

    await wrapper.setProps({ value: [{ key: 'a' }, { key: 'b' }] });
    const add = wrapper.find('button[aria-label="Add row"]');
    expect(add.attributes('disabled')).toBeDefined();
    const before = emitted(wrapper).length;
    // The button guard and the handler guard both respect maxItems.
    (add.element as HTMLButtonElement).disabled = false;
    await add.trigger('click');
    expect(emitted(wrapper)).toHaveLength(before);
  });

  it('edits repeater rows with conditional children and stops nesting at depth 4', async () => {
    const def = setting({
      type: 'repeater',
      fields: [
        { name: 'kind', type: 'select', tab: 'general', label: 'Kind', choices: [{ value: 'a', label: 'A' }], default: 'a' },
        { name: 'extra', type: 'text', tab: 'general', label: 'Extra', showIf: { setting: 'kind', equals: ['b'] }, default: { deep: [1] } },
      ],
    });
    const wrapper = mount(SettingControl, { props: { setting: def, value: [{ kind: 'a' }, { kind: 'b', extra: 'x' }], settings: {} }, global: global() });
    expect(controls(wrapper, 'QInput').map((i) => i.props('label'))).toEqual(['Extra']);
    await change(controls(wrapper, 'QSelect')[0]!, 'b');
    expect(emitted(wrapper).at(-1)).toEqual([{ kind: 'b' }, { kind: 'b', extra: 'x' }]);
    await wrapper.find('button[aria-label="Add row"]').trigger('click');
    const added = (emitted(wrapper).at(-1) as Record<string, unknown>[])[2]!;
    expect(added).toEqual({ kind: 'a', extra: { deep: [1] } });
    expect(added.extra).not.toBe(def.fields![1]!.default);
    await wrapper.setProps({ value: 'none' });
    expect(wrapper.findAll('button[aria-label^="Remove row"]')).toHaveLength(0);

    const deep = mount(SettingControl, { props: { setting: def, value: [], settings: {}, depth: 4 }, global: global() });
    expect(deep.find('fieldset').exists()).toBe(false);
    expect(controls(deep, 'QInput')).toHaveLength(1);
    const empty = mount(SettingControl, { props: { setting: setting({ type: 'repeater' }), value: [{}], settings: {} }, global: global() });
    await empty.find('button[aria-label="Add row"]').trigger('click');
    expect(emitted(empty).at(-1)).toEqual([{}, {}]);
  });
});
