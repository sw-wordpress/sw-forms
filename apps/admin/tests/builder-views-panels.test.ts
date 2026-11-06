/* eslint-disable vue/one-component-per-file -- small child stubs for component tests */
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { defineComponent } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { formsApi } from '@/api';
import ColumnsInspector from '@/features/builder/ColumnsInspector.vue';
import FormHistory from '@/features/builder/FormHistory.vue';
import InspectorPanel from '@/features/builder/InspectorPanel.vue';
import PalettePanel from '@/features/builder/PalettePanel.vue';
import PreviewDialog from '@/features/builder/PreviewDialog.vue';
import { registerFieldType } from '@/extensions/builder';
import { useBuilderStore } from '@/stores/builder';
import type { ElementNode } from '@shared/types';
import { detail, directives, i18n, stubs, types } from './builder-views-stubs';

const notify = vi.hoisted(() => ({ success: vi.fn(), info: vi.fn(), error: vi.fn() }));
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }));

const palette = vi.hoisted(() => ({ onAdd: null as null | ((type: string, listId: string, index: number) => void) }));
vi.mock('@/features/builder/sortable', () => ({
  useSortablePalette: (_el: unknown, onAdd: (type: string, listId: string, index: number) => void) => {
    palette.onAdd = onAdd;
  },
}));

const LogicEditor = defineComponent({
  props: { logic: { type: null, default: null }, ownerId: { type: String, required: true } },
  emits: ['update'],
  template: '<button type="button" class="logic-editor" :data-owner="ownerId" @click="$emit(\'update\', { action: \'show\', match: \'all\', rules: [] })" />',
});
const SettingControl = defineComponent({
  props: { setting: { type: Object, required: true }, value: { type: null, default: null } },
  emits: ['update'],
  template: '<button type="button" class="setting" :data-name="setting.name" @click="$emit(\'update\', \'changed\')">{{ setting.label }}</button>',
});
const global = () => ({ plugins: [i18n()], stubs: { ...stubs, LogicEditor, SettingControl }, directives });

async function loaded(overrides = {}) {
  const store = useBuilderStore();
  vi.spyOn(formsApi, 'get').mockResolvedValue(detail(overrides));
  await store.load(3);
  return store;
}
const node = (store: ReturnType<typeof useBuilderStore>, id: string) => store.tree.node(id) as ElementNode;

beforeEach(() => {
  setActivePinia(createPinia());
  vi.spyOn(formsApi, 'fieldTypes').mockResolvedValue(types);
  vi.spyOn(formsApi, 'saveDraft').mockResolvedValue({ saved: true, previewUrl: 'http://x/?draft=1' });
});
afterEach(() => {
  vi.clearAllMocks();
});

describe('InspectorPanel', () => {
  it('shows the shortcuts while nothing is selected', async () => {
    await loaded();
    const wrapper = mount(InspectorPanel, { global: global() });
    expect(wrapper.text()).toContain('Ctrl');
    expect(wrapper.find('.tabs').exists()).toBe(false);
  });

  it('edits the general settings of an element', async () => {
    const store = await loaded();
    store.select('b');
    store.errors = [{ path: 'b', code: 'x', message: 'Broken' }];
    const wrapper = mount(InspectorPanel, { global: global() });
    expect(wrapper.find('h2').text()).toBe('Input');
    expect(wrapper.find('[role="alert"]').text()).toContain('Broken');
    expect(wrapper.findAll('.tab').map((t) => t.attributes('data-name'))).toEqual(['general', 'validation', 'logic', 'appearance']);

    await wrapper.find('input[aria-label="Label"]').setValue('First name');
    expect(node(store, 'b').label).toBe('First name');
    expect(node(store, 'b').key).toBe('first_name');
    expect(wrapper.find('[data-label="Field name (data key)"]').attributes('data-hint')).toContain('Follows the label');

    await wrapper.find('input[aria-label="Field name (data key)"]').setValue('9bad');
    expect(node(store, 'b').key).toBe('9bad');
    expect(wrapper.find('[data-label="Field name (data key)"]').attributes('data-error')).not.toBe('');
    await wrapper.find('input[aria-label="Field name (data key)"]').setValue('a');
    expect(wrapper.find('[data-label="Field name (data key)"]').attributes('data-error')).toBe('Already used at this level.');
    await wrapper.find('input[aria-label="Field name (data key)"]').setValue('fine');
    expect(wrapper.find('[data-label="Field name (data key)"]').attributes('data-error')).toBe('');

    await wrapper.find('input[aria-label="Help text"]').setValue('Help');
    expect(node(store, 'b').description).toBe('Help');
    await wrapper.find('input[type="checkbox"]').setValue(true);
    expect(node(store, 'b').required).toBe(true);

    // General settings: `mode` has an empty tab (= general), `pattern` is hidden by showIf.
    expect(wrapper.findAll('.setting').map((s) => s.attributes('data-name'))).toEqual(['placeholder', 'mode']);
    await wrapper.find('.setting[data-name="placeholder"]').trigger('click');
    expect(node(store, 'b').settings?.placeholder).toBe('changed');

    await wrapper.find('button[aria-label="Close properties"]').trigger('click');
    expect(store.selectedId).toBeNull();
  });

  it('warns when a published key changes and shows conditional settings', async () => {
    const store = await loaded();
    store.select('a');
    const wrapper = mount(InspectorPanel, { global: global() });
    expect(wrapper.findAll('.setting').map((s) => s.attributes('data-name'))).toEqual(['placeholder', 'mode', 'pattern']);
    expect(wrapper.find('.swf-warning').exists()).toBe(false);
    await wrapper.find('input[aria-label="Field name (data key)"]').setValue('renamed');
    expect(wrapper.find('.swf-warning').text()).toContain('a');
    expect(wrapper.find('[data-label="Field name (data key)"]').attributes('data-hint')).not.toContain('Follows the label');
  });

  it('switches between validation, logic and appearance tabs', async () => {
    const store = await loaded();
    store.select('b');
    const wrapper = mount(InspectorPanel, { global: global() });

    await wrapper.find('.tab[data-name="validation"]').trigger('click');
    expect(wrapper.findAll('.setting').map((s) => s.attributes('data-name'))).toEqual(['minLength']);
    await wrapper.find('.setting').trigger('click');
    expect(node(store, 'b').settings?.minLength).toBe('changed');

    await wrapper.find('.tab[data-name="logic"]').trigger('click');
    expect(wrapper.find('.logic-editor').attributes('data-owner')).toBe('b');
    await wrapper.find('.logic-editor').trigger('click');
    expect(node(store, 'b').logic).toEqual({ action: 'show', match: 'all', rules: [] });

    await wrapper.find('.tab[data-name="appearance"]').trigger('click');
    expect(wrapper.findAll('.setting').map((s) => s.attributes('data-name'))).toEqual(['width']);
    await wrapper.find('.setting').trigger('click');
    expect(node(store, 'b').settings?.width).toBe('changed');
    await wrapper.find('input[aria-label="Admin label"]').setValue('Internal');
    expect(node(store, 'b').adminLabel).toBe('Internal');
    await wrapper.find('input[aria-label="CSS classes"]').setValue('wide');
    expect(node(store, 'b').cssClass).toBe('wide');
    expect(wrapper.find('code').text()).toBe('b');
  });

  it('falls back to general when the next element has no logic tab', async () => {
    const store = await loaded();
    store.select('b');
    const wrapper = mount(InspectorPanel, { global: global() });
    await wrapper.find('.tab[data-name="logic"]').trigger('click');
    store.select('h');
    await flushPromises();
    expect(wrapper.findAll('.tab').map((t) => t.attributes('data-name'))).toEqual(['general', 'appearance']);
    expect(wrapper.find('.tabs').attributes('data-current')).toBe('general');
    expect(wrapper.find('h2').text()).toBe('HTML');
    expect(wrapper.find('input[aria-label="Label"]').exists()).toBe(false);
  });

  it('edits a column and its logic', async () => {
    const store = await loaded();
    store.select('c2');
    const wrapper = mount(InspectorPanel, { global: global() });
    expect(wrapper.find('h2').text()).toBe('Column 2');
    expect(wrapper.findAll('.tab').map((t) => t.attributes('data-name'))).toEqual(['general', 'logic']);
    await wrapper.find('input[aria-label="Width"]').setValue('40');
    expect(node(store, 'r').columns![1]!.width).toBe(12);
    await wrapper.find('input[aria-label="Width"]').setValue('abc');
    expect(node(store, 'r').columns![1]!.width).toBe(1);

    await wrapper.find('.tab[data-name="logic"]').trigger('click');
    store.select('c1');
    await flushPromises();
    // Columns always have a logic tab: the tab is kept on selection change.
    expect(wrapper.find('.logic-editor').attributes('data-owner')).toBe('c1');
    await wrapper.find('.logic-editor').trigger('click');
    expect(node(store, 'r').columns![0]!.logic).toEqual({ action: 'show', match: 'all', rules: [] });

    await wrapper.find('.tab[data-name="general"]').trigger('click');
    await wrapper.findAll('button').find((b) => b.text() === 'Back to the column layout')!.trigger('click');
    expect(store.selectedId).toBe('r');
    expect(wrapper.find('.swf-presets').exists()).toBe(true);
  });

  it('renders the inspector of a registered add-on field type', async () => {
    const AddonInspector = defineComponent({
      props: { element: { type: Object, required: true }, modelValue: { type: Object, required: true } },
      emits: ['update:modelValue'],
      template: '<div class="addon"><button class="addon-ok" @click="$emit(\'update:modelValue\', { color: \'red\' })" /><button class="addon-bad" @click="$emit(\'update:modelValue\', [1])" /></div>',
    });
    registerFieldType('addon', { inspector: AddonInspector });
    const store = await loaded();
    store.select('z');
    const wrapper = mount(InspectorPanel, { global: global() });
    expect(wrapper.find('.addon').exists()).toBe(true);
    await wrapper.find('.addon-bad').trigger('click');
    expect(node(store, 'z').settings).toBeUndefined();
    await wrapper.find('.addon-ok').trigger('click');
    expect(node(store, 'z').settings).toEqual({ color: 'red' });
  });

  it('labels elements of unknown types by their type', async () => {
    const store = await loaded();
    store.document!.fields.push({ id: 'u', type: 'mystery' });
    store.select('u');
    const wrapper = mount(InspectorPanel, { global: global() });
    expect(wrapper.find('h2').text()).toBe('mystery');
    expect(wrapper.findAll('.tab').map((t) => t.attributes('data-name'))).toEqual(['general', 'appearance']);
  });
});

describe('ColumnsInspector', () => {
  it('applies presets, edits widths and adds, removes or equalizes columns', async () => {
    const store = await loaded();
    const wrapper = mount(ColumnsInspector, { props: { element: node(store, 'r') }, global: global() });
    const row = () => node(store, 'r');
    const presets = () => wrapper.findAll('.swf-preset');
    expect(presets()[0]!.attributes('aria-pressed')).toBe('true');

    await presets()[1]!.trigger('click');
    await wrapper.setProps({ element: row() });
    expect(row().columns!.map((c) => c.width)).toEqual([4, 4, 4]);
    expect(presets()[1]!.classes()).toContain('swf-preset--active');

    await wrapper.find('input[aria-label="Column 1"]').setValue('20');
    expect(row().columns![0]!.width).toBe(12);
    await wrapper.find('input[aria-label="Column 2"]').setValue('');
    expect(row().columns![1]!.width).toBe(1);

    await wrapper.find('button[aria-label="Condition of column 3"]').trigger('click');
    expect(store.selectedId).toBe(row().columns![2]!.id);

    await wrapper.find('button[aria-label="Remove column 3"]').trigger('click');
    await wrapper.setProps({ element: row() });
    expect(row().columns).toHaveLength(2);

    await wrapper.findAll('button').find((b) => b.text() === 'Add column')!.trigger('click');
    await wrapper.setProps({ element: row() });
    expect(row().columns!.map((c) => c.width)).toEqual([4, 4, 4]);

    await wrapper.find('input[aria-label="Column 1"]').setValue('8');
    await wrapper.findAll('button').find((b) => b.text() === 'Equal widths')!.trigger('click');
    await wrapper.setProps({ element: row() });
    expect(row().columns!.map((c) => c.width)).toEqual([4, 4, 4]);
  });

  it('limits the number of columns', async () => {
    await loaded();
    const one: ElementNode = { id: 'one', type: 'row', columns: [{ id: 'k', children: [] }] };
    const wrapper = mount(ColumnsInspector, { props: { element: one }, global: global() });
    expect(wrapper.find('input[aria-label="Column 1"]').element.getAttribute('value')).toBeNull();
    expect(wrapper.find('button[aria-label="Remove column 1"]').attributes('disabled')).toBeDefined();
    const six: ElementNode = { id: 'six', type: 'row', columns: Array.from({ length: 6 }, (_, i) => ({ id: `k${i}`, width: 2, children: [] })) };
    await wrapper.setProps({ element: six });
    expect(wrapper.findAll('button').find((b) => b.text() === 'Add column')!.attributes('disabled')).toBeDefined();
    const none: ElementNode = { id: 'none', type: 'row' };
    await wrapper.setProps({ element: none });
    expect(wrapper.findAll('li')).toHaveLength(0);
  });
});

describe('PalettePanel', () => {
  it('groups, sorts and filters the field types', async () => {
    await loaded();
    const wrapper = mount(PalettePanel, { global: global() });
    expect(wrapper.findAll('.swf-palette__category').map((p) => p.text())).toEqual(['Basic fields', 'Choices', 'Layout', 'extras']);
    expect(wrapper.findAll('.swf-palette__item').map((b) => b.attributes('data-type'))).toEqual(['input', 'select', 'html', 'group', 'row', 'addon']);
    await wrapper.find('input[aria-label="Find element"]').setValue('  SEL ');
    expect(wrapper.findAll('.swf-palette__item').map((b) => b.attributes('data-type'))).toEqual(['select']);
  });

  it('adds by click relative to the selection', async () => {
    const store = await loaded();
    const wrapper = mount(PalettePanel, { global: global() });
    const add = (type: string) => wrapper.find(`.swf-palette__item[data-type="${type}"]`).trigger('click');

    await add('select');
    expect(store.document!.fields.at(-1)!.type).toBe('select');

    store.select('a');
    await add('html');
    expect(store.document!.fields[1]!.type).toBe('html');

    store.select('g');
    await add('select');
    expect(node(store, 'g').children!.map((c) => c.type)).toEqual(['input', 'select']);

    store.select('c1');
    await add('input');
    expect(node(store, 'r').columns![0]!.children.map((c) => c.type)).toEqual(['input']);

    store.select('r');
    await add('html');
    const index = store.document!.fields.findIndex((f) => f.id === 'r');
    expect(store.document!.fields[index + 1]!.type).toBe('html');
  });

  it('adds into the active step and through drag and drop', async () => {
    const store = await loaded();
    store.document!.settings.steps = { enabled: true, firstLabel: '', indicator: 'numbers' };
    store.document!.fields = [{ id: 'a', type: 'input', key: 'a', label: 'A' }, { id: 'p', type: 'page-break', label: 'Two' }, { id: 'b', type: 'input', key: 'b', label: 'B' }];
    store.showStep(0);
    const wrapper = mount(PalettePanel, { global: global() });
    await wrapper.find('.swf-palette__item[data-type="html"]').trigger('click');
    expect(store.document!.fields.map((f) => f.type)).toEqual(['input', 'html', 'page-break', 'input']);

    palette.onAdd!('select', 'root', 0);
    expect(store.document!.fields[0]!.type).toBe('select');
  });

  it('does nothing without a document', () => {
    const store = useBuilderStore();
    store.types = types;
    const wrapper = mount(PalettePanel, { global: global() });
    void wrapper.find('.swf-palette__item').trigger('click');
    expect(store.document).toBeNull();
  });
});

describe('FormHistory', () => {
  const items = [{ version: 2, createdAt: '2026-01-02 10:00:00', createdBy: 1 }, { version: 1, createdAt: '2026-01-01 10:00:00', createdBy: 1 }];

  it('lists versions, shows a version document and restores it', async () => {
    const store = await loaded();
    const versions = vi.spyOn(formsApi, 'versions').mockResolvedValue(items);
    vi.spyOn(formsApi, 'version').mockResolvedValue({ document: detail().document });
    const restore = vi.spyOn(formsApi, 'restoreVersion').mockResolvedValue({ restored: true });
    const wrapper = mount(FormHistory, { global: global() });
    await flushPromises();
    expect(versions).toHaveBeenCalledWith(3, 1);
    const rows = wrapper.findAll('.swf-row');
    expect(rows.map((r) => r.find('span').text().split(' ')[0])).toEqual(['#2', '#1']);

    await rows[1]!.findAll('button').find((b) => b.text() === 'View')!.trigger('click');
    await flushPromises();
    expect(formsApi.version).toHaveBeenCalledWith(3, 1);
    const shown = wrapper.find('.dialog textarea, .dialog input');
    expect((shown.element as HTMLInputElement).value).toContain('"schemaVersion": 1');
    await wrapper.find('.dialog-dismiss').trigger('click');
    expect(wrapper.find('.dialog').exists()).toBe(false);

    await rows[1]!.findAll('button').find((b) => b.text() === 'Restore as draft')!.trigger('click');
    expect(wrapper.find('.dialog').exists()).toBe(true);
    await wrapper.find('.dialog').findAll('button').find((b) => b.text() === 'Restore as draft')!.trigger('click');
    await flushPromises();
    expect(restore).toHaveBeenCalledWith(3, 1);
    expect(formsApi.get).toHaveBeenCalledTimes(2);
    expect(notify.success).toHaveBeenCalled();
    expect(wrapper.find('.dialog').exists()).toBe(false);
    expect(store.formId).toBe(3);
  });

  it('pages through versions and cancels a restore', async () => {
    await loaded();
    const many = Array.from({ length: 50 }, (_, i) => ({ version: 60 - i, createdAt: 'invalid', createdBy: 1 }));
    const versions = vi.spyOn(formsApi, 'versions').mockResolvedValue(many);
    const wrapper = mount(FormHistory, { global: global() });
    await flushPromises();
    const prev = () => wrapper.find('button[aria-label="Newer versions"]');
    const next = () => wrapper.find('button[aria-label="Older versions"]');
    expect(prev().attributes('disabled')).toBeDefined();
    await next().trigger('click');
    await flushPromises();
    expect(versions).toHaveBeenLastCalledWith(3, 2);
    await prev().trigger('click');
    await flushPromises();
    expect(versions).toHaveBeenLastCalledWith(3, 1);

    await wrapper.findAll('button').find((b) => b.text() === 'Restore as draft')!.trigger('click');
    await wrapper.find('.dialog-dismiss').trigger('click');
    expect(wrapper.find('.dialog').exists()).toBe(false);
  });

  it('reports failures and refuses to restore over unsaved changes', async () => {
    const store = await loaded();
    vi.spyOn(formsApi, 'versions').mockRejectedValueOnce(new Error('offline')).mockResolvedValue(items);
    vi.spyOn(formsApi, 'version').mockRejectedValue(new Error('gone'));
    const restore = vi.spyOn(formsApi, 'restoreVersion').mockRejectedValue(new Error('denied'));
    const wrapper = mount(FormHistory, { global: global() });
    await flushPromises();
    expect(notify.error).toHaveBeenCalledWith(new Error('offline'));
    expect(wrapper.findAll('.swf-row')).toHaveLength(0);

    store.meta = { ...store.meta!, currentVersion: 3 };
    await flushPromises();
    expect(wrapper.findAll('.swf-row')).toHaveLength(2);

    await wrapper.findAll('button').find((b) => b.text() === 'View')!.trigger('click');
    await flushPromises();
    expect(notify.error).toHaveBeenCalledWith(new Error('gone'));

    const confirm = () => wrapper.find('.dialog').findAll('button').find((b) => b.text() === 'Restore as draft')!;
    await wrapper.findAll('button').find((b) => b.text() === 'Restore as draft')!.trigger('click');
    await confirm().trigger('click');
    await flushPromises();
    expect(notify.error).toHaveBeenCalledWith(new Error('denied'));
    expect(wrapper.find('.dialog').exists()).toBe(true);

    store.setTitle('Changed');
    await flushPromises();
    expect(wrapper.findAll('.swf-row')[0]!.findAll('button')[1]!.attributes('disabled')).toBeDefined();
    await confirm().trigger('click');
    await flushPromises();
    expect(restore).toHaveBeenCalledTimes(1);
  });
});

describe('PreviewDialog', () => {
  it('stores the draft and shows the real preview at several widths', async () => {
    const store = await loaded();
    const wrapper = mount(PreviewDialog, { props: { modelValue: false, 'onUpdate:modelValue': (v: boolean) => wrapper.setProps({ modelValue: v }) }, global: global() });
    expect(wrapper.find('.dialog').exists()).toBe(false);
    await wrapper.setProps({ modelValue: true });
    expect(wrapper.find('.spinner').exists()).toBe(true);
    await flushPromises();
    expect(formsApi.saveDraft).toHaveBeenCalledWith(3, store.document);
    const frame = wrapper.find('iframe');
    expect(frame.attributes('src')).toMatch(/^http:\/\/x\/\?draft=1&t=\d+$/);
    expect(frame.attributes('style')).toContain('width: 100%');
    await frame.trigger('load');
    expect(wrapper.find('.spinner').exists()).toBe(false);

    await wrapper.find('[data-value="390px"]').trigger('click');
    expect(wrapper.find('iframe').attributes('style')).toContain('width: 390px');

    await wrapper.find('button[aria-label="Close"]').trigger('click');
    expect(wrapper.props('modelValue')).toBe(false);
    expect(wrapper.find('.dialog').exists()).toBe(false);
  });

  it('falls back to the published preview URL or shows nothing', async () => {
    await loaded();
    vi.spyOn(formsApi, 'saveDraft').mockRejectedValue(new Error('offline'));
    const wrapper = mount(PreviewDialog, { props: { modelValue: false }, global: global() });
    await wrapper.setProps({ modelValue: true });
    await flushPromises();
    expect(wrapper.find('iframe').attributes('src')).toMatch(/^http:\/\/x\/\?swf_preview=3&t=\d+$/);

    await wrapper.setProps({ modelValue: false });
    useBuilderStore().meta = { ...detail(), previewUrl: '' };
    await wrapper.setProps({ modelValue: true });
    await flushPromises();
    expect(wrapper.find('iframe').exists()).toBe(false);
  });
});
