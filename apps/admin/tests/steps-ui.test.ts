/* eslint-disable vue/one-component-per-file -- small Quasar stubs for component tests */
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { defineComponent } from 'vue';
import { createI18n } from 'vue-i18n';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { formsApi } from '@/api';
import BuilderCanvas from '@/features/builder/BuilderCanvas.vue';
import FormSettingsPanel from '@/features/builder/FormSettingsPanel.vue';
import FormLibrary from '@/features/forms/FormLibrary.vue';
import { templateSummary } from '@/features/forms/librarySummary';
import { useBuilderStore } from '@/stores/builder';
import type { FieldTypeDefinition, FormDetail, FormTemplate } from '@/types/api';
import en from '@/i18n/en';

const push = vi.fn();
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }));

const button = defineComponent({
  props: { label: { type: String, default: '' }, disable: Boolean, ariaLabel: { type: String, default: '' } },
  emits: ['click'],
  template: '<button type="button" :disabled="disable" :aria-label="ariaLabel || label" @click="$emit(\'click\', $event)">{{ label }}<slot /></button>',
});
const input = defineComponent({
  props: { modelValue: { type: null, default: null }, label: { type: String, default: '' } },
  emits: ['update:modelValue'],
  template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
});
const toggle = defineComponent({
  props: { modelValue: { type: null, default: null }, label: { type: String, default: '' } },
  emits: ['update:modelValue'],
  template: '<input type="checkbox" :aria-label="label" :checked="!!modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" />',
});
const btnToggle = defineComponent({
  props: { modelValue: { type: null, default: null }, options: { type: Array, default: () => [] } },
  emits: ['update:modelValue'],
  template: '<div class="btn-toggle"><button v-for="o in options" :key="o.value" type="button" :data-value="o.value" @click="$emit(\'update:modelValue\', o.value)">{{ o.label }}</button></div>',
});
const slot = { template: '<div><slot /></div>' };
const stubs = {
  DesignSettings: true,
  QBtn: button, QInput: input, QToggle: toggle, QBtnToggle: btnToggle, QSelect: input, QIcon: true, QTooltip: true, QSpinner: true, QSkeleton: { template: '<div class="skeleton" />' },
  QDialog: { props: ['modelValue'], template: '<div v-if="modelValue" class="dialog"><slot /></div>' }, QCard: slot, QCardSection: slot,
  CanvasList: { props: ['listId', 'elements'], template: '<div class="list" :data-list="listId">{{ elements.map((e) => e.id).join(",") }}</div>' },
  SwfCard: { props: ['title'], template: '<section :data-title="title"><slot /></section>' },
  MergeTagMenu: true,
  ConfirmDialog: { props: ['modelValue', 'title'], emits: ['confirm'], template: '<div v-if="modelValue" class="confirm"><button class="do-confirm" @click="$emit(\'confirm\')">{{ title }}</button></div>' },
};
/** jsdom's File lacks `text()`; the library only needs size and text. */
const jsonFile = (text: string, size = text.length) => ({ size, text: async () => text }) as unknown as File;
const i18n = () => createI18n({ legacy: false, locale: 'en', messages: { en } });
const types: FieldTypeDefinition[] = [
  { type: 'input', label: 'Input', icon: '', category: 'basic', isData: true, container: '', supports: ['key', 'label'], settings: [], defaults: {} },
  { type: 'page-break', label: 'Page break', icon: '', category: 'layout', isData: false, container: '', supports: ['label'], settings: [], defaults: {} },
];
function detail(): FormDetail {
  return {
    id: 3, title: 'Trip', status: 'active', currentVersion: 1, hasDraft: false, publishedKeys: {}, previewUrl: '', shortcode: '', updatedAt: '',
    document: {
      schemaVersion: 1,
      fields: [{ id: 'a', type: 'input', key: 'a', label: 'A' }, { id: 'p1', type: 'page-break', label: 'Contact' }, { id: 'b', type: 'input', key: 'b', label: 'B' }],
      settings: { submitLabel: 'Send', cssClass: '', confirmation: { type: 'message', message: '', url: '', pageId: 0 }, storeEntries: true, retentionDays: 0, spam: { honeypot: true, minSeconds: 2, rateLimit: 10 }, notifications: [], steps: { enabled: true, firstLabel: '', indicator: 'numbers' } },
    },
  };
}

beforeEach(() => {
  setActivePinia(createPinia());
  vi.spyOn(formsApi, 'get').mockResolvedValue(detail());
  vi.spyOn(formsApi, 'fieldTypes').mockResolvedValue(types);
  vi.spyOn(formsApi, 'saveDraft').mockResolvedValue({ saved: true, previewUrl: '' });
});
afterEach(() => {
  vi.restoreAllMocks();
  push.mockReset();
});

describe('step tabs on the canvas', () => {
  it('shows one step at a time and edits steps through the tab bar', async () => {
    const store = useBuilderStore();
    await store.load(3);
    store.errors = [{ path: 'b', code: 'x', message: 'bad' }];
    const wrapper = mount(BuilderCanvas, { global: { plugins: [i18n()], stubs } });
    const tabs = () => wrapper.findAll('[role="tab"]');
    expect(tabs().map((tab) => tab.text())).toEqual(['1Step 11', '2Contact1']);
    expect(tabs()[1]!.classes()).toContain('swf-step-tab--error');
    expect(wrapper.find('.list').attributes('data-list')).toBe('step:0');
    expect(wrapper.find('.list').text()).toBe('a');
    expect(wrapper.find('.swf-canvas__submit').text()).toBe('Next');

    await tabs()[0]!.trigger('keydown', { key: 'ArrowRight' });
    expect(store.activeStep).toBe(1);
    await tabs()[1]!.trigger('keydown', { key: 'Enter' });
    expect(wrapper.find('.list').text()).toBe('b');
    expect(wrapper.find('.swf-canvas__submit').text()).toBe('BackSend');
    await tabs()[0]!.trigger('click');
    expect(store.activeStep).toBe(0);

    await wrapper.find('input[aria-label="Title of step 1"]').setValue('Trip');
    expect(store.document!.settings.steps!.firstLabel).toBe('Trip');
    await wrapper.find('button[aria-label="Move step to the right"]').trigger('click');
    expect(store.steps.map((s) => s.label)).toEqual(['Contact', 'Trip']);
    await wrapper.find('button[aria-label="Move step to the left"]').trigger('click');
    expect(store.steps.map((s) => s.label)).toEqual(['Trip', 'Contact']);
    await wrapper.findAll('button').find((b) => b.text() === 'Step')!.trigger('click');
    expect(store.steps.map((s) => s.label)).toEqual(['Trip', 'Contact', 'Step 3']);
    expect(store.activeStep).toBe(2);
    await wrapper.find('button[aria-label="Remove step"]').trigger('click');
    expect(store.steps).toHaveLength(2);

    store.setStepsEnabled(false);
    await flushPromises();
    expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
    expect(wrapper.find('.list').attributes('data-list')).toBe('root');
  });

  it('toggles multi-step in the form settings and confirms merging steps', async () => {
    const store = useBuilderStore();
    await store.load(3);
    const wrapper = mount(FormSettingsPanel, { global: { plugins: [i18n()], stubs } });
    await wrapper.find('[data-value="dots"]').trigger('click');
    expect(store.document!.settings.steps!.indicator).toBe('dots');
    const enable = () => wrapper.find('input[aria-label="Enable multi-step"]');
    await enable().setValue(false);
    expect(wrapper.find('.confirm').exists()).toBe(true);
    expect(store.stepsEnabled).toBe(true);
    await wrapper.find('.do-confirm').trigger('click');
    expect(store.stepsEnabled).toBe(false);
    expect(store.document!.fields.map((n) => n.id)).toEqual(['a', 'b']);
    await enable().setValue(true);
    expect(store.stepsEnabled).toBe(true);
    await enable().setValue(false);
    expect(store.stepsEnabled).toBe(false);
  });
});

describe('template library', () => {
  const travel: FormTemplate = { id: 'travel', title: 'Travel', description: 'Multi-step', icon: 'r_flight_takeoff', document: detail().document };
  const contact: FormTemplate = { id: 'contact', title: 'Contact', document: { ...detail().document, fields: [{ id: 'g', type: 'group', children: [{ id: 'x', type: 'input' }], columns: [{ id: 'c', width: 12, children: [{ id: 'y', type: 'input' }] }] }], settings: { ...detail().document.settings, steps: { enabled: false, firstLabel: '', indicator: 'numbers' } } } };

  it('summarizes fields and steps', () => {
    expect(templateSummary(travel.document)).toEqual({ fields: 2, steps: 2 });
    expect(templateSummary(contact.document)).toEqual({ fields: 3, steps: 1 });
  });

  it('lists templates as cards and imports a template or a dropped JSON file', async () => {
    let resolve: (items: FormTemplate[]) => void = () => {};
    vi.spyOn(formsApi, 'templates').mockReturnValue(new Promise((done) => { resolve = done; }));
    const imported = vi.spyOn(formsApi, 'import').mockResolvedValue({ id: 42 });
    const wrapper = mount(FormLibrary, { global: { plugins: [i18n()], stubs } });
    await wrapper.find('button').trigger('click');
    expect(wrapper.findAll('.skeleton')).toHaveLength(4);
    resolve([travel, contact]);
    await flushPromises();
    const cards = wrapper.findAll('.swf-library__card');
    expect(cards.map((c) => c.text().replace(/\s+/g, ' '))).toEqual(['TravelMulti-step2 fields · 2 steps', 'Contact3 fields']);
    await cards[0]!.trigger('click');
    await flushPromises();
    expect(imported).toHaveBeenCalledWith({ format: 'swforms', formatVersion: 1, title: 'Travel', document: travel.document });
    expect(push).toHaveBeenCalledWith('/forms/42');

    await wrapper.find('button').trigger('click');
    expect(formsApi.templates).toHaveBeenCalledTimes(1);
    const drop = wrapper.find('.swf-library__drop');
    await drop.trigger('dragenter');
    expect(drop.classes()).toContain('swf-library__drop--active');
    const file = jsonFile('{"format":"swforms"}');
    await drop.trigger('drop', { dataTransfer: { files: [file] } });
    await flushPromises();
    expect(drop.classes()).not.toContain('swf-library__drop--active');
    expect(imported).toHaveBeenLastCalledWith({ format: 'swforms' });
  });

  it('reports oversized files, failed requests and a failed template list', async () => {
    const notify = await import('@/composables/useNotify');
    const error = vi.fn();
    vi.spyOn(notify, 'useNotify').mockReturnValue({ error } as unknown as ReturnType<typeof notify.useNotify>);
    vi.spyOn(formsApi, 'templates').mockRejectedValue(new Error('offline'));
    vi.spyOn(formsApi, 'import').mockRejectedValue(new Error('invalid'));
    const wrapper = mount(FormLibrary, { global: { plugins: [i18n()], stubs } });
    await wrapper.find('button').trigger('click');
    await flushPromises();
    expect(error).toHaveBeenCalledTimes(1);
    const picker = wrapper.find<HTMLInputElement>('input[type="file"]');
    const big = jsonFile('{}', 2 * 1048576);
    Object.defineProperty(picker.element, 'files', { value: [big], configurable: true });
    await picker.trigger('change');
    await flushPromises();
    expect(String(error.mock.calls[1]![0])).toContain('1 MB');
    Object.defineProperty(picker.element, 'files', { value: [jsonFile('{}')], configurable: true });
    await picker.trigger('change');
    await flushPromises();
    expect(String(error.mock.calls[2]![0])).toContain('invalid');
    Object.defineProperty(picker.element, 'files', { value: [], configurable: true });
    await picker.trigger('change');
    await flushPromises();
    expect(error).toHaveBeenCalledTimes(3);
  });
});
