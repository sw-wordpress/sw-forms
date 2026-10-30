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
  });
});
