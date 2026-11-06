import { mount } from '@vue/test-utils';
import { defineComponent, h, nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import { describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { fieldUI, initializeBuilderAPI, registerFieldType } from '../src/extensions/builder';
import SettingControl from '../src/features/builder/SettingControl.vue';
import en from '../src/i18n/en';

const control = defineComponent({ props: { modelValue: { type: null, default: null }, label: { type: String, default: '' }, options: { type: Array, default: () => [] }, disable: Boolean }, emits: ['update:modelValue', 'click'], setup: (props, { emit }) => () => h('button', { disabled: props.disable, onClick: () => emit('click') }, props.label) });
const global = { plugins: [createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: { QInput: { ...control, name: 'QInput' }, QSelect: { ...control, name: 'QSelect' }, QToggle: { ...control, name: 'QToggle' }, QBtn: { ...control, name: 'QBtn' } } };

describe('stable builder extension API', () => {
  it('loads queued and late add-ons with the host Vue runtime and isolates a broken registration', () => {
    window.eval(readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../../assets/builder-api.js'), 'utf8'));
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    try {
      window.swFormsBuilder!.register(() => { throw new Error('Broken add-on'); });
      const addon = vi.fn((api) => api.registerFieldType('queued-addon', { canvas: defineComponent(() => () => h('p', 'Custom canvas')) }));
      window.swFormsBuilder!.register(addon);
      initializeBuilderAPI();
      expect(addon).toHaveBeenCalledOnce();
      expect(addon.mock.calls[0]![0].vue.h).toBe(h);
      expect(fieldUI('queued-addon')?.canvas).toBeDefined();
      const late = vi.fn();
      window.swFormsBuilder!.register(late);
      expect(late).toHaveBeenCalledOnce();
      initializeBuilderAPI();
      expect(addon).toHaveBeenCalledOnce();
      expect(error).toHaveBeenCalledOnce();
    } finally { error.mockRestore(); delete window.swFormsBuilder; }
  });

  it('replaces components reactively while ignoring invalid type slugs', async () => {
    const first = defineComponent(() => () => h('p', 'First'));
    const second = defineComponent(() => () => h('p', 'Second'));
    registerFieldType('ui-test', { canvas: first });
    const wrapper = mount(defineComponent(() => () => h(fieldUI('ui-test')!.canvas!)));
    expect(wrapper.text()).toBe('First');
    registerFieldType('ui-test', { canvas: second });
    await nextTick();
    expect(wrapper.text()).toBe('Second');
    registerFieldType('Invalid!', { canvas: first });
    expect(fieldUI('Invalid!')).toBeUndefined();
  });
});

describe('structured settings controls', () => {
  it('uses immutable field IDs and filters the picker by supported field types', () => {
    const wrapper = mount(SettingControl, { props: { setting: { name: 'source', type: 'field-picker', label: 'Source', tab: 'general', fieldTypes: ['input'] }, value: 'stable-id', settings: {}, fields: [{ value: 'stable-id', label: 'Renamed label', type: 'input' }, { value: 'other', label: 'Other', type: 'checkbox' }] }, global });
    const picker = wrapper.findComponent({ name: 'QSelect' });
    expect(picker.props('options')).toEqual([{ value: 'stable-id', label: 'Renamed label', type: 'input' }]);
    picker.vm.$emit('update:modelValue', null);
    expect(wrapper.emitted('update')?.[0]).toEqual(['']);
  });

  it('edits key-value rows without mutating props and enforces the row cap', async () => {
    const rows = [{ key: 'team', value: 'A' }];
    const wrapper = mount(SettingControl, { props: { setting: { name: 'pairs', type: 'key-value', label: 'Pairs', tab: 'general', maxItems: 1 }, value: rows, settings: {} }, global });
    wrapper.findAllComponents({ name: 'QInput' }).find((c) => c.props('label') === 'Value')!.vm.$emit('update:modelValue', 'B');
    expect(wrapper.emitted('update')?.[0]).toEqual([[{ key: 'team', value: 'B' }]]);
    expect(rows).toEqual([{ key: 'team', value: 'A' }]);
    expect(wrapper.findAllComponents({ name: 'QBtn' }).find((c) => c.props('label') === 'Add row')!.props('disable')).toBe(true);
    await wrapper.findAll('button').find((button) => button.text() === 'Remove row 1')!.trigger('click');
    expect(wrapper.emitted('update')?.at(-1)).toEqual([[]]);
  });

  it('adds repeater rows with independent defaults and preserves sibling values', async () => {
    const wrapper = mount(SettingControl, { props: { setting: { name: 'rows', type: 'repeater', label: 'Rows', tab: 'general', fields: [{ name: 'name', type: 'text', label: 'Name', tab: 'general', default: 'New' }, { name: 'tags', type: 'list', label: 'Tags', tab: 'general', default: [] }] }, value: [], settings: {} }, global });
    await wrapper.find('button').trigger('click');
    expect(wrapper.emitted('update')?.[0]).toEqual([[{ name: 'New', tags: [] }]]);
    await wrapper.setProps({ value: [{ name: 'One', tags: ['x'] }] });
    wrapper.findAllComponents({ name: 'QInput' }).find((c) => c.props('label') === 'Name')!.vm.$emit('update:modelValue', 'Changed');
    expect(wrapper.emitted('update')?.at(-1)).toEqual([[{ name: 'Changed', tags: ['x'] }]]);
  });
});
