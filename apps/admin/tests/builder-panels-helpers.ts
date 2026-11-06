/* eslint-disable vue/one-component-per-file -- small Quasar stubs shared by the builder panel tests */
import type { VueWrapper } from '@vue/test-utils';
import { defineComponent } from 'vue';
import { createI18n } from 'vue-i18n';
import { vi } from 'vitest';
import { formsApi } from '@/api';
import en from '@/i18n/en';
import type { FieldTypeDefinition, FormDetail, FormSettings } from '@/types/api';
import type { ElementNode } from '@shared/types';

/** Generic Quasar control stub: exposes the props tests assert on and emits like the real one. */
function control(name: string) {
  return defineComponent({
    name,
    props: {
      modelValue: { type: null, default: null },
      label: { type: String, default: '' },
      ariaLabel: { type: String, default: '' },
      options: { type: Array, default: () => [] },
      error: Boolean,
      disable: Boolean,
      hint: { type: String, default: '' },
    },
    emits: ['update:modelValue', 'filter'],
    template: `<div class="stub-${name}" :data-label="label || ariaLabel"><slot /></div>`,
  });
}

export const QBtnStub = defineComponent({
  name: 'QBtn',
  props: { label: { type: String, default: '' }, ariaLabel: { type: String, default: '' }, disable: Boolean, loading: Boolean },
  emits: ['click'],
  template: '<button type="button" :disabled="disable" :data-loading="loading" :aria-label="ariaLabel || label" @click="$emit(\'click\', $event)">{{ label }}</button>',
});

export const stubs = {
  QBtn: QBtnStub,
  QInput: control('QInput'),
  QSelect: control('QSelect'),
  QToggle: control('QToggle'),
  QBtnToggle: control('QBtnToggle'),
  QIcon: true,
  MergeTagMenu: true,
  DesignSettings: true,
  OptionsEditor: control('OptionsEditor'),
  ConfirmDialog: {
    props: ['modelValue', 'title'],
    emits: ['confirm'],
    template: '<div v-if="modelValue" class="confirm"><button class="do-confirm" @click="$emit(\'confirm\')">{{ title }}</button></div>',
  },
};

export const i18n = () => createI18n({ legacy: false, locale: 'en', messages: { en } });
export const global = () => ({ plugins: [i18n()], stubs });

/** A mounted stub; props are read by name (stubs are untyped for test-utils). */
export type Stub = Omit<VueWrapper, 'props'> & { props(name: string): unknown };

/** Controls of one stub type (`QInput`, `QSelect`, …). */
export function controls(wrapper: VueWrapper, name: string): Stub[] {
  return wrapper.findAllComponents({ name }) as unknown as Stub[];
}

/** The control of a stub type with this label or aria-label (exactly one expected). */
export function control$(wrapper: VueWrapper, name: string, label: string): Stub {
  const found = controls(wrapper, name).filter((c) => c.props('label') === label || c.props('ariaLabel') === label);
  if (found.length !== 1) throw new Error(`${name} "${label}": ${found.length} matches`);
  return found[0]!;
}

/** Emits a model update from a stubbed control. */
export async function change(target: Stub, value: unknown): Promise<void> {
  target.vm.$emit('update:modelValue', value);
  await target.vm.$nextTick();
}

export const types: FieldTypeDefinition[] = (
  [
    ['input', true, ''],
    ['checkbox', true, ''],
    ['select', true, ''],
    ['calculation', true, ''],
    ['page-break', false, ''],
    ['group', true, 'children'],
    ['columns', false, 'columns'],
  ] as const
).map(([type, isData, container]) => ({ type, label: type, icon: '', category: 'basic', isData, container, supports: [], settings: [], defaults: {} }));

/**
 * a (input, admin label), c (single checkbox), s (select with options), calc, in2 inside a column,
 * g (repeatable group with r1 and r2), box (plain group with an unnamed input x).
 */
export function fields(): ElementNode[] {
  return [
    { id: 'a', type: 'input', key: 'name', label: 'Name', adminLabel: 'Full name' },
    { id: 'c', type: 'checkbox', key: 'agree', label: 'Agree', settings: { mode: 'single' } },
    { id: 's', type: 'select', key: 'size', label: 'Size', settings: { options: [{ label: 'One', value: '1' }] } },
    { id: 'calc', type: 'calculation', key: 'total', label: 'Total' },
    { id: 'cols', type: 'columns', columns: [{ id: 'col1', children: [{ id: 'in2', type: 'input', key: 'city' }] }] },
    { id: 'g', type: 'group', key: 'rep', label: 'Repeat', settings: { repeatable: true }, children: [{ id: 'r1', type: 'input', label: 'Row' }, { id: 'r2', type: 'input', label: 'Other' }] },
    { id: 'box', type: 'group', label: 'Box', children: [{ id: 'x', type: 'input' }] },
  ];
}

export function settings(patch: Partial<FormSettings> = {}): FormSettings {
  return {
    submitLabel: 'Send',
    cssClass: '',
    confirmation: { type: 'message', message: '', url: '', pageId: 0 },
    storeEntries: true,
    retentionDays: 0,
    spam: { honeypot: true, minSeconds: 2, rateLimit: 10 },
    notifications: [],
    ...patch,
  };
}

export function detail(patch: Partial<FormSettings> = {}, elements: ElementNode[] = fields()): FormDetail {
  return {
    id: 7, title: 'Form', status: 'active', currentVersion: 1, hasDraft: false, publishedKeys: {}, previewUrl: '', shortcode: '[swforms id="7"]', updatedAt: '',
    document: { schemaVersion: 1, fields: elements, settings: settings(patch) },
  };
}

/** Mocks the form API used by `store.load()` and autosave. */
export function mockApi(form: FormDetail): void {
  vi.spyOn(formsApi, 'get').mockResolvedValue(form);
  vi.spyOn(formsApi, 'fieldTypes').mockResolvedValue(types);
  vi.spyOn(formsApi, 'saveDraft').mockResolvedValue({ saved: true, previewUrl: '' });
}
