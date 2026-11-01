/* eslint-disable vue/one-component-per-file -- small Quasar stubs shared by the builder view tests */
import { defineComponent, h, inject, provide } from 'vue';
import { createI18n } from 'vue-i18n';
import en from '@/i18n/en';
import type { FieldTypeDefinition, FormDetail } from '@/types/api';

const TABS = Symbol('tabs');

export const i18n = () => createI18n({ legacy: false, locale: 'en', messages: { en } });

const button = defineComponent({
  props: { label: { type: String, default: '' }, disable: Boolean, ariaLabel: { type: String, default: '' }, icon: { type: String, default: '' } },
  emits: ['click'],
  template: '<button type="button" :disabled="disable" :data-icon="icon" :aria-label="ariaLabel || label" @click="$emit(\'click\', $event)">{{ label }}<slot /></button>',
});
const input = defineComponent({
  props: {
    modelValue: { type: null, default: null },
    label: { type: String, default: '' },
    ariaLabel: { type: String, default: '' },
    hint: { type: String, default: '' },
    error: Boolean,
    errorMessage: { type: String, default: '' },
    readonly: Boolean,
  },
  emits: ['update:modelValue'],
  template: `<div class="q-input" :data-label="label || ariaLabel" :data-error="error ? errorMessage : ''" :data-hint="hint">
    <input :aria-label="label || ariaLabel" :readonly="readonly" :value="modelValue" @input="$emit('update:modelValue', $event.target.value)" />
    <slot name="prepend" />
  </div>`,
});
const toggle = defineComponent({
  props: { modelValue: { type: null, default: null }, label: { type: String, default: '' } },
  emits: ['update:modelValue'],
  template: '<input type="checkbox" :aria-label="label" :checked="!!modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" />',
});
const btnToggle = defineComponent({
  props: { modelValue: { type: null, default: null }, options: { type: Array, default: () => [] } },
  emits: ['update:modelValue'],
  template: '<div class="btn-toggle"><button v-for="o in options" :key="o.value" type="button" :data-value="o.value" @click="$emit(\'update:modelValue\', o.value)">{{ o.value }}</button></div>',
});
/** Tabs: each tab button emits the model update of its parent tab bar. */
const tabs = defineComponent({
  props: { modelValue: { type: null, default: null } },
  emits: ['update:modelValue'],
  setup(props, { emit, slots }) {
    provide(TABS, { select: (name: string) => emit('update:modelValue', name), current: () => props.modelValue as unknown });
    return () => h('div', { class: 'tabs', 'data-current': String(props.modelValue) }, slots.default?.());
  },
});
const tab = defineComponent({
  props: { name: { type: String, required: true }, label: { type: String, default: '' } },
  setup(props) {
    const bar = inject<{ select: (name: string) => void; current: () => unknown }>(TABS)!;
    return () => h('button', { type: 'button', class: 'tab', 'data-name': props.name, onClick: () => bar.select(props.name) }, props.label);
  },
});
const slot = { template: '<div><slot /></div>' };

export const stubs = {
  QBtn: button,
  QInput: input,
  QToggle: toggle,
  QBtnToggle: btnToggle,
  QTabs: tabs,
  QTab: tab,
  QIcon: true,
  QTooltip: true,
  QSpinner: { template: '<div class="spinner" />' },
  QDialog: { props: ['modelValue'], emits: ['update:modelValue'], template: '<div v-if="modelValue" class="dialog"><button class="dialog-dismiss" @click="$emit(\'update:modelValue\', false)" /><slot /></div>' },
  QCard: slot,
  QCardSection: slot,
  QCardActions: slot,
  SwfCard: { props: ['title'], template: '<section :data-title="title"><slot /></section>' },
};

export const directives = { closePopup: {} };

export const types: FieldTypeDefinition[] = [
  {
    type: 'input',
    label: 'Input',
    icon: 'r_input',
    category: 'basic',
    isData: true,
    container: '',
    supports: ['key', 'label', 'description', 'required', 'logic', 'adminLabel', 'cssClass'],
    settings: [
      { name: 'placeholder', type: 'text', tab: 'general', label: 'Placeholder' },
      { name: 'mode', type: 'select', tab: '', label: 'Mode' },
      { name: 'pattern', type: 'text', tab: 'general', label: 'Pattern', showIf: { setting: 'mode', equals: ['custom'] } },
      { name: 'minLength', type: 'number', tab: 'validation', label: 'Min length' },
      { name: 'width', type: 'select', tab: 'appearance', label: 'Width' },
    ],
    defaults: {},
  },
  { type: 'select', label: 'Select', icon: '', category: 'choice', isData: true, container: '', supports: ['key', 'label'], settings: [], defaults: {} },
  { type: 'html', label: 'HTML', icon: '', category: 'layout', isData: false, container: '', supports: [], settings: [], defaults: {} },
  { type: 'group', label: 'Group', icon: '', category: 'layout', isData: false, container: 'children', supports: ['label'], settings: [], defaults: {} },
  { type: 'row', label: 'Columns', icon: '', category: 'layout', isData: false, container: 'columns', supports: [], settings: [], defaults: {} },
  { type: 'addon', label: 'Addon', icon: '', category: 'extras', isData: true, container: '', supports: ['key', 'label'], settings: [], defaults: {} },
  { type: 'page-break', label: 'Page break', icon: '', category: 'layout', isData: false, container: '', supports: ['label'], settings: [], defaults: {} },
];

export function detail(overrides: Partial<FormDetail> = {}): FormDetail {
  return {
    id: 3,
    title: 'Trip',
    status: 'active',
    currentVersion: 2,
    hasDraft: false,
    publishedKeys: { a: 'a' },
    previewUrl: 'http://x/?swf_preview=3',
    shortcode: '',
    updatedAt: '',
    document: {
      schemaVersion: 1,
      fields: [
        { id: 'a', type: 'input', key: 'a', label: 'A', settings: { mode: 'custom' } },
        { id: 'b', type: 'input', key: 'b', label: 'B' },
        { id: 'g', type: 'group', label: 'G', children: [{ id: 'x', type: 'input', key: 'x', label: 'X' }] },
        { id: 'r', type: 'row', columns: [{ id: 'c1', width: 6, logic: null, children: [] }, { id: 'c2', width: 6, logic: null, children: [] }] },
        { id: 'h', type: 'html' },
        { id: 'z', type: 'addon', key: 'z', label: 'Z' },
      ],
      settings: {
        submitLabel: 'Send',
        cssClass: '',
        confirmation: { type: 'message', message: '', url: '', pageId: 0 },
        storeEntries: true,
        retentionDays: 0,
        spam: { honeypot: true, minSeconds: 2, rateLimit: 10 },
        notifications: [],
      },
    },
    ...overrides,
  };
}
