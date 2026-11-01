import { mount } from '@vue/test-utils';
import { defineComponent } from 'vue';
import { createI18n } from 'vue-i18n';
import { describe, expect, it } from 'vitest';
import EntryValueFields from '../src/features/entries/EntryValueFields.vue';
import en from '../src/i18n/en';

const control = defineComponent({ props: { modelValue: { type: null, default: null }, label: { type: String, default: '' } }, emits: ['update:modelValue'], template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' });
const global = { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: { QInput: control, QSelect: { ...control, name: 'QSelect' }, QCheckbox: { ...control, name: 'QCheckbox' } } };

describe('entry value editor', () => {
  it('keeps interval endpoints in the stored list shape when changing one endpoint', async () => {
    const values = { period: ['2026-01-01', '2026-01-03'] };
    const wrapper = mount(EntryValueFields, { props: { fields: [{ id: 'period', type: 'date-interval', key: 'period', label: 'Period' }], values }, global });
    expect((wrapper.get('input[aria-label="Period · From"]').element as HTMLInputElement).value).toBe('2026-01-01');
    await wrapper.get('input[aria-label="Period · To"]').setValue('2026-01-05');
    expect(wrapper.emitted('update')?.[0]).toEqual([{ period: ['2026-01-01', '2026-01-05'] }]);
    expect(values.period[1]).toBe('2026-01-03');
  });
  it('uses option selection for checkbox lists and a boolean control only for single checkboxes', () => {
    const wrapper = mount(EntryValueFields, { props: { fields: [
      { id: 'topics', type: 'checkbox', key: 'topics', settings: { mode: 'multiple', options: [{ label: 'A', value: 'a' }] } },
      { id: 'consent', type: 'checkbox', key: 'consent', settings: { mode: 'single' } },
    ], values: { topics: ['a'], consent: true } }, global });
    const selection = wrapper.getComponent({ name: 'QSelect' });
    expect(selection.props('modelValue')).toEqual(['a']);
    expect(selection.attributes('multiple')).toBeDefined();
    expect(wrapper.getComponent({ name: 'QCheckbox' }).props('modelValue')).toBe(true);
    wrapper.getComponent({ name: 'QCheckbox' }).vm.$emit('update:modelValue', false);
    selection.vm.$emit('update:modelValue', []);
    expect(wrapper.emitted('update')).toEqual([[{ topics: ['a'], consent: false }], [{ topics: [], consent: true }]]);
  });
  it('edits nested layouts, repeater rows, matrix, tags and plain values', async () => {
    const values = {
      first: 'Ada',
      street: 'Main',
      people: [{ person: 'A' }, { person: 'B' }],
      grid: { r1: 'yes' },
      labels: ['x', 'y'],
      bio: 'Hi',
      total: 5,
      upload: ['a.pdf', 'b.png'],
      color: 'red',
    };
    const wrapper = mount(EntryValueFields, { props: { fields: [
      { id: 'row', type: 'columns', columns: [{ id: 'c1', children: [{ id: 'first', type: 'text', key: 'first', label: 'First' }] }] },
      { id: 'addr', type: 'group', key: 'addr', label: 'Address', children: [{ id: 'street', type: 'text', key: 'street', label: 'Street' }] },
      { id: 'people', type: 'group', key: 'people', settings: { repeatable: true }, children: [{ id: 'person', type: 'text', key: 'person', label: 'Person' }] },
      { id: 'grid', type: 'matrix', key: 'grid', label: 'Grid', settings: { rows: [{ label: 'Row 1', value: 'r1' }, { label: 'Row 2', value: 'r2' }], options: [{ label: 'Yes', value: 'yes' }] } },
      { id: 'labels', type: 'tags', key: 'labels', label: 'Labels' },
      { id: 'bio', type: 'textarea', key: 'bio' },
      { id: 'total', type: 'calculation', key: 'total', label: 'Total' },
      { id: 'upload', type: 'file', key: 'upload', label: 'Upload' },
      { id: 'color', type: 'select', key: 'color', label: 'Color', settings: { multiple: true } },
    ], values }, global });

    expect(wrapper.text()).toContain('Upload: a.pdf, b.png');
    expect(wrapper.get('legend').text()).toBe('Address');
    expect(wrapper.findAll('legend')[1]!.text()).toBe('people');

    await wrapper.get('input[aria-label="First"]').setValue('Grace');
    expect(wrapper.emitted('update')?.at(-1)).toEqual([{ ...values, first: 'Grace' }]);
    await wrapper.get('input[aria-label="Street"]').setValue('Side');
    expect(wrapper.emitted('update')?.at(-1)).toEqual([{ ...values, street: 'Side' }]);

    const people = wrapper.findAll('input[aria-label="Person"]');
    expect(people).toHaveLength(2);
    await people[1]!.setValue('C');
    expect(wrapper.emitted('update')?.at(-1)).toEqual([{ ...values, people: [{ person: 'A' }, { person: 'C' }] }]);

    const grid = wrapper.findAllComponents({ name: 'QSelect' }).filter((c) => String(c.props('label')).startsWith('Grid'));
    expect(grid.map((c) => c.props('label'))).toEqual(['Grid · Row 1', 'Grid · Row 2']);
    expect(grid[0]!.props('modelValue')).toBe('yes');
    grid[1]!.vm.$emit('update:modelValue', 'no');
    expect(wrapper.emitted('update')?.at(-1)).toEqual([{ ...values, grid: { r1: 'yes', r2: 'no' } }]);

    const tags = wrapper.get('input[aria-label="Labels"]');
    expect((tags.element as HTMLInputElement).value).toBe('x, y');
    await tags.setValue(' a , ,b ');
    expect(wrapper.emitted('update')?.at(-1)).toEqual([{ ...values, labels: ['a', 'b'] }]);

    await wrapper.get('input[aria-label="bio"]').setValue('Hello');
    expect(wrapper.emitted('update')?.at(-1)).toEqual([{ ...values, bio: 'Hello' }]);
    expect((wrapper.get('input[aria-label="Total"]').element as HTMLInputElement).value).toBe('5');

    const color = wrapper.findAllComponents({ name: 'QSelect' }).find((c) => c.props('label') === 'Color')!;
    expect(color.attributes('multiple')).toBeDefined();
    color.vm.$emit('update:modelValue', ['blue']);
    expect(wrapper.emitted('update')?.at(-1)).toEqual([{ ...values, color: ['blue'] }]);
  });
});
