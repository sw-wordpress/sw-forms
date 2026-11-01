/* eslint-disable vue/one-component-per-file -- small Quasar stubs for component tests */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { defineComponent } from 'vue';
import { createI18n } from 'vue-i18n';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { formsApi } from '@/api';
import FormLibrary from '@/features/forms/FormLibrary.vue';
import TemplateDetail from '@/features/forms/TemplateDetail.vue';
import { groupTemplates, matchesQuery, structureRows, widthKey } from '@/features/forms/templateBrowser';
import en from '@/i18n/en';
import type { FieldTypeDefinition, FormDocument, FormTemplate, FormTemplateCard, TemplateCategory } from '@/types/api';

const push = vi.fn();
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }));
const notify = { error: vi.fn(), success: vi.fn(), info: vi.fn() };
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }));

const button = defineComponent({
  props: { label: { type: String, default: '' }, disable: Boolean, loading: Boolean },
  emits: ['click'],
  template: '<button type="button" :disabled="disable" @click="$emit(\'click\')">{{ label }}<slot /></button>',
});
const input = defineComponent({
  props: { modelValue: { type: null, default: null } },
  emits: ['update:modelValue'],
  template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
});
const toggleButton = defineComponent({
  props: { modelValue: { type: null, default: null }, options: { type: Array, default: () => [] } },
  emits: ['update:modelValue'],
  template: '<div class="btn-toggle"><button v-for="o in options" :key="o.value" type="button" :data-value="o.value" @click="$emit(\'update:modelValue\', o.value)">{{ o.label ?? o.value }}</button></div>',
});
const slot = { template: '<div><slot /></div>' };
const panel = { props: { name: String }, template: '<div class="panel" :data-panel="name"><slot /></div>' };
const stubs = {
  QBtn: button,
  QInput: input,
  QIcon: true,
  QSpinner: true,
  QSkeleton: { template: '<div class="skeleton" />' },
  QDialog: { props: { modelValue: Boolean }, emits: ['update:modelValue'], template: '<div v-if="modelValue" class="dialog"><slot /></div>' },
  QCard: slot,
  QCardSection: slot,
  QCardActions: slot,
  QTabs: slot,
  QTab: { props: { label: String }, template: '<button type="button" class="tab">{{ label }}</button>' },
  QTabPanels: slot,
  QTabPanel: panel,
  QBtnToggle: toggleButton,
};

const i18n = () => createI18n({ legacy: false, locale: 'en', messages: { en } });
const categories: TemplateCategory[] = [
  { slug: 'general', title: 'General', icon: 'r_chat', count: 2 },
  { slug: 'events', title: 'Events', icon: 'r_event', count: 1 },
];

function card(over: Partial<FormTemplateCard> = {}): FormTemplateCard {
  return {
    id: 'contact',
    version: '2.0.0',
    title: 'Contact form',
    description: 'Name, e-mail and message.',
    icon: 'r_mark_email_unread',
    category: 'general',
    tags: ['contact', 'e-mail'],
    fields: 7,
    steps: 1,
    conditions: 0,
    isMultiStep: false,
    ...over,
  };
}

function document(): FormDocument {
  return {
    schemaVersion: 1,
    fields: [
      { id: 'salutation', type: 'select', key: 'salutation', label: 'Salutation', settings: { options: [{ value: 'mr', label: 'Mr' }, { value: 'ms', label: 'Ms' }] } },
      {
        id: 'row_name',
        type: 'columns',
        columns: [
          { id: 'row_name_1', width: 6, children: [{ id: 'first_name', type: 'input', key: 'first_name', label: 'First name', required: true }] },
          { id: 'row_name_2', width: 6, children: [{ id: 'last_name', type: 'input', key: 'last_name', label: 'Last name', required: true }] },
        ],
      },
      { id: 'privacy_note', type: 'html', settings: { content: '<strong>Data protection</strong> We store your entries.' } },
      { id: 'step_two', type: 'page-break', label: 'Confirm' },
      {
        id: 'email',
        type: 'input',
        key: 'email',
        label: 'E-mail',
        required: true,
        logic: { action: 'show', match: 'all', rules: [{ field: 'contact_allowed', op: 'is', value: 'yes' }] },
        settings: { inputType: 'email' },
      },
    ],
    settings: { steps: { enabled: true, firstLabel: 'Personal details', indicator: 'numbers' } },
  } as unknown as FormDocument;
}

function detail(id = 'contact'): FormTemplate {
  return { ...card({ id }), document: document(), previewUrl: `http://x/?swf_template_preview=${id}&_wpnonce=n` };
}

const fieldTypes: FieldTypeDefinition[] = [
  { type: 'input', label: 'Text field', icon: '', category: 'basic', isData: true, container: '', supports: [], settings: [], defaults: {} },
  { type: 'html', label: 'HTML block', icon: '', category: 'layout', isData: false, container: '', supports: [], settings: [], defaults: {} },
];

function mountLibrary(): VueWrapper {
  return mount(FormLibrary, { global: { plugins: [i18n()], stubs } });
}

beforeEach(() => {
  setActivePinia(createPinia());
  vi.spyOn(formsApi, 'fieldTypes').mockResolvedValue(fieldTypes);
});

afterEach(() => {
  vi.restoreAllMocks();
  push.mockReset();
  notify.error.mockReset();
});

describe('template browser helpers', () => {
  it('searches title, description, tags and category with every word', () => {
    expect(matchesQuery(card(), 'General', 'contact')).toBe(true);
    expect(matchesQuery(card(), 'General', 'E-MAIL')).toBe(true);
    expect(matchesQuery(card(), 'General', 'contact zebra')).toBe(false);
    expect(matchesQuery(card(), 'General', '   ')).toBe(true);
    expect(matchesQuery(card({ description: undefined }), 'General', 'e-mail Contact')).toBe(true);
    expect(matchesQuery(card({ description: undefined }), 'General', 'message')).toBe(false);
  });

  it('groups the matches by category and keeps the category filter separate', () => {
    const items = [card(), card({ id: 'callback', title: 'Request a callback', tags: ['phone'] }), card({ id: 'camp', title: 'Camp', category: 'events', tags: ['youth'] })];

    const all = groupTemplates(items, categories, '', '');
    expect(all.map((group) => [group.slug, group.title, group.count, group.items.length])).toEqual([
      ['general', 'General', 2, 2],
      ['events', 'Events', 1, 1],
    ]);

    expect(groupTemplates(items, categories, 'youth', '').map((group) => group.slug)).toEqual(['events']);
    expect(groupTemplates(items, categories, 'camp', 'general')).toEqual([]);
    expect(groupTemplates(items, categories, '', 'events').map((group) => group.items[0]?.id)).toEqual(['camp']);
    // An add-on category is kept with its slug until the site knows a label for it.
    const foreign = groupTemplates([card({ id: 'x', category: 'acme' })], [], '', '');
    expect(foreign[0]).toEqual({ slug: 'acme', title: 'acme', icon: 'r_category', count: 1, items: [expect.objectContaining({ id: 'x' })] });
  });

  it('flattens the document into readable structure rows', () => {
    const rows = structureRows(document());
    expect(rows.map((row) => [row.depth, row.type, row.label])).toEqual([
      [0, 'page-break', 'Personal details'],
      [0, 'select', 'Salutation'],
      [0, 'columns', ''],
      [1, 'column', ''],
      [2, 'input', 'First name'],
      [1, 'column', ''],
      [2, 'input', 'Last name'],
      [0, 'html', ''],
      [0, 'page-break', 'Confirm'],
      [0, 'input', 'E-mail'],
    ]);

    const email = rows.find((row) => row.id === 'email');
    expect(email?.required).toBe(true);
    expect(email?.condition).toEqual({ action: 'show', match: 'all', rules: [{ field: 'contact_allowed', fieldLabel: 'contact_allowed', op: 'is', value: 'yes' }] });
    expect(rows.find((row) => row.id === 'row_name_1')?.width).toBe(6);
    expect(rows.find((row) => row.id === 'salutation')?.options).toEqual(['Mr', 'Ms']);
    expect(rows.find((row) => row.id === 'privacy_note')?.content).toBe('Data protection We store your entries.');
  });

  it('names referenced fields by their label and skips the first step row without a label', () => {
    const withLabel = document();
    withLabel.settings.steps = { enabled: true, firstLabel: '', indicator: 'numbers' };
    withLabel.fields[4] = {
      ...withLabel.fields[4]!,
      logic: { action: 'require', match: 'any', rules: [{ field: 'first_name', op: 'is', value: 'a' }, { field: 'ghost', op: 'notempty' }] },
    };
    expect(structureRows(withLabel)[0]?.id).toBe('salutation');
    const condition = structureRows(withLabel).find((row) => row.id === 'email')?.condition;
    expect(condition?.action).toBe('require');
    expect(condition?.match).toBe('any');
    expect(condition?.rules.map((rule) => rule.fieldLabel)).toEqual(['First name', 'ghost']);
    expect(condition?.rules[1]?.value).toBe('');

    const off = document();
    off.settings.steps = { enabled: false, firstLabel: 'Personal details', indicator: 'numbers' };
    expect(structureRows(off)[0]?.type).not.toBe('page-break');
  });

  it('maps the 12 column grid to readable widths', () => {
    expect([widthKey(12), widthKey(6), widthKey(4), widthKey(8), widthKey(5)]).toEqual(['full', 'half', 'third', 'twoThirds', 'units']);
  });
});

describe('template gallery dialog', () => {
  it('lists cards grouped by category, opens the detail view and creates an independent form', async () => {
    let resolveList: (value: { categories: TemplateCategory[]; items: FormTemplateCard[] }) => void = () => {};
    const list = new Promise<{ categories: TemplateCategory[]; items: FormTemplateCard[] }>((resolve) => {
      resolveList = resolve;
    });
    vi.spyOn(formsApi, 'templates').mockReturnValue(list);
    const detailSpy = vi.spyOn(formsApi, 'template').mockResolvedValue(detail());
    const imported = vi.spyOn(formsApi, 'import').mockResolvedValue({ id: 42 });

    const wrapper = mountLibrary();
    await wrapper.findAll('button')[0]!.trigger('click');
    expect(wrapper.findAll('.skeleton')).toHaveLength(6);
    resolveList({
      categories,
      items: [card(), card({ id: 'camp', title: 'Camp sign-up', category: 'events', tags: ['youth'], isMultiStep: true, steps: 4, conditions: 6 })],
    });
    await flushPromises();

    expect(wrapper.findAll('.swf-library__section')).toHaveLength(2);
    expect(wrapper.text()).toContain('General');
    expect(wrapper.text()).toContain('Camp sign-up');
    expect(wrapper.text()).toContain('4 steps');
    expect(detailSpy).not.toHaveBeenCalled();

    await wrapper.findAll('.swf-library__card')[0]!.trigger('click');
    await flushPromises();
    expect(detailSpy).toHaveBeenCalledWith('contact');
    const frame = wrapper.find('.dialog iframe');
    expect(frame.attributes('src')).toBe('http://x/?swf_template_preview=contact&_wpnonce=n');
    expect(wrapper.text()).toContain('v2.0.0');
    expect(wrapper.findAll('.swf-template-detail__tags li')).toHaveLength(2);

    // Field structure instead of the iframe.
    const switches = wrapper.findAll('.swf-template-detail__switch button');
    await switches[1]!.trigger('click');
    await flushPromises();
    const rows = wrapper.findAll('.swf-template-detail__structure tbody tr');
    expect(rows).toHaveLength(10);
    expect(rows[9]!.text()).toContain('email');
    expect(rows[9]!.text()).toContain('Shown when contact_allowed is yes');
    expect(rows[3]!.text()).toContain('half width');
    expect(rows[7]!.text()).toContain('Data protection We store your entries.');

    await wrapper.find('.swf-template-detail__actions button').trigger('click');
    await flushPromises();
    expect(imported).toHaveBeenCalledWith({ format: 'swforms', formatVersion: 1, title: 'Contact form', document: document() });
    expect(push).toHaveBeenCalledWith('/forms/42');
  });

  it('searches and filters by category without another request', async () => {
    vi.spyOn(formsApi, 'templates').mockResolvedValue({ categories, items: [card(), card({ id: 'camp', title: 'Camp sign-up', category: 'events', tags: ['youth'] })] });
    const list = vi.spyOn(formsApi, 'templates');
    const wrapper = mountLibrary();
    await wrapper.findAll('button')[0]!.trigger('click');
    await flushPromises();
    expect(list).toHaveBeenCalledTimes(1);

    await wrapper.find('.swf-library__search').setValue('youth');
    expect(wrapper.findAll('.swf-library__card')).toHaveLength(1);
    expect(wrapper.text()).toContain('Events');
    expect(wrapper.text()).not.toContain('Contact form');

    await wrapper.find('.swf-library__search').setValue('nothing here');
    expect(wrapper.find('.swf-library__empty').exists()).toBe(true);

    await wrapper.find('.swf-library__search').setValue('');
    const chips = wrapper.findAll('.swf-library__chip');
    expect(chips).toHaveLength(3);
    expect(chips[0]!.attributes('aria-pressed')).toBe('true');
    await chips[2]!.trigger('click');
    expect(wrapper.findAll('.swf-library__card')).toHaveLength(1);
    expect(chips[2]!.attributes('aria-pressed')).toBe('true');
    expect(chips[0]!.attributes('aria-pressed')).toBe('false');
  });

  it('imports a dropped JSON file in the import tab', async () => {
    vi.spyOn(formsApi, 'templates').mockResolvedValue({ categories, items: [] });
    const imported = vi.spyOn(formsApi, 'import').mockResolvedValue({ id: 7 });
    const wrapper = mountLibrary();
    await wrapper.findAll('button')[0]!.trigger('click');
    await flushPromises();

    const drop = wrapper.find('.swf-library__drop');
    await drop.trigger('dragenter');
    expect(drop.classes()).toContain('swf-library__drop--active');

    const picker = wrapper.find<HTMLInputElement>('input[type="file"]');
    const big = { size: 2 * 1048576, text: async () => '{}' } as unknown as File;
    Object.defineProperty(picker.element, 'files', { value: [big], configurable: true });
    await picker.trigger('change');
    await flushPromises();
    expect(String(notify.error.mock.calls[0]?.[0])).toContain('1 MB');
    expect(imported).not.toHaveBeenCalled();

    await drop.trigger('dragleave');
    expect(drop.classes()).not.toContain('swf-library__drop--active');
    await drop.trigger('drop', { dataTransfer: { files: [{ size: 12, text: async () => '{"format":"swforms"}' } as unknown as File] } });
    await flushPromises();
    expect(imported).toHaveBeenLastCalledWith({ format: 'swforms' });
    expect(push).toHaveBeenCalledWith('/forms/7');
  });

  it('reports a failed list and a failed detail request', async () => {
    vi.spyOn(formsApi, 'templates').mockRejectedValue(new Error('offline'));
    const wrapper = mountLibrary();
    await wrapper.findAll('button')[0]!.trigger('click');
    await flushPromises();
    expect(notify.error).toHaveBeenCalledTimes(1);

    vi.spyOn(formsApi, 'templates').mockResolvedValue({ categories, items: [card()] });
    vi.spyOn(formsApi, 'template').mockRejectedValue(new Error('gone'));
    const second = mountLibrary();
    await second.findAll('button')[0]!.trigger('click');
    await flushPromises();
    await second.findAll('.swf-library__card')[0]!.trigger('click');
    await flushPromises();
    expect(notify.error).toHaveBeenCalledTimes(2);
    expect(second.find('iframe').exists()).toBe(false);
    // Without a document there is nothing to create, so the action stays away.
    expect(second.find('.swf-template-detail__actions').exists()).toBe(false);
  });

  it('ignores clicks while a create request runs and keeps a failed one open', async () => {
    vi.spyOn(formsApi, 'templates').mockResolvedValue({ categories, items: [card()] });
    vi.spyOn(formsApi, 'template').mockResolvedValue(detail());
    vi.spyOn(formsApi, 'import').mockRejectedValue(new Error('busy site'));
    const wrapper = mountLibrary();
    await wrapper.findAll('button')[0]!.trigger('click');
    await flushPromises();
    await wrapper.findAll('.swf-library__card')[0]!.trigger('click');
    await flushPromises();

    const action = wrapper.find('.swf-template-detail__actions button');
    await action.trigger('click');
    await flushPromises();
    expect(notify.error).toHaveBeenCalledTimes(1);
    await action.trigger('click');
    await flushPromises();
    expect(formsApi.import).toHaveBeenCalledTimes(2);
    expect(wrapper.find('.swf-template-detail__actions').exists()).toBe(true);
  });

  it('falls back to the raw type name when the type catalog cannot be loaded', async () => {
    vi.spyOn(formsApi, 'templates').mockResolvedValue({ categories, items: [card()] });
    vi.spyOn(formsApi, 'template').mockResolvedValue(detail());
    vi.spyOn(formsApi, 'fieldTypes').mockRejectedValue(new Error('offline'));
    const wrapper = mountLibrary();
    await wrapper.findAll('button')[0]!.trigger('click');
    await flushPromises();
    await wrapper.findAll('.swf-library__card')[0]!.trigger('click');
    await flushPromises();
    await wrapper.findAll('.swf-template-detail__switch button')[1]!.trigger('click');
    await flushPromises();
    expect(wrapper.findAll('.swf-template-detail__structure tbody tr')[7]!.text()).toContain('html');
  });
});

describe('template detail dialog', () => {
  const Host = defineComponent({
    components: { Detail: TemplateDetail },
    data() {
      return { open: true };
    },
    template: '<Detail card-id="contact" :open="open" :creating="false" @back="open = false" />',
  });

  it('closes through the back button and switches the preview width', async () => {
    vi.spyOn(formsApi, 'template').mockResolvedValue(detail());
    const wrapper = mount(Host, { global: { plugins: [i18n()], stubs } });
    await flushPromises();

    const widths = wrapper.findAll('.swf-template-detail__preview-tools .btn-toggle button');
    await widths[2]!.trigger('click');
    expect(wrapper.find('iframe').attributes('style')).toContain('width: 390px');

    await wrapper.find('.swf-template-detail__head button').trigger('click');
    await flushPromises();
    expect(wrapper.find('.dialog').exists()).toBe(false);
  });
});
