/* eslint-disable vue/one-component-per-file -- small reactive hosts mount the real child components */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { Quasar } from 'quasar';
import Sortable from 'sortablejs';
import type { SortableEvent } from 'sortablejs';
import { defineComponent, h, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { formsApi } from '@/api';
import CanvasList from '@/features/builder/CanvasList.vue';
import ColumnsCanvas from '@/features/builder/ColumnsCanvas.vue';
import MergeTagMenu from '@/features/builder/MergeTagMenu.vue';
import OptionsEditor from '@/features/builder/OptionsEditor.vue';
import { stepListId } from '@/features/builder/document';
import { useBuilderStore } from '@/stores/builder';
import { detail, i18n, types } from './builder-views-stubs';

const mounted: VueWrapper[] = [];
const global = () => ({ plugins: [Quasar, i18n()] });
function keep<T extends VueWrapper>(wrapper: T): T { mounted.push(wrapper); return wrapper; }
async function loaded() {
  const store = useBuilderStore();
  vi.spyOn(formsApi, 'get').mockResolvedValue(detail());
  vi.spyOn(formsApi, 'fieldTypes').mockResolvedValue(types);
  vi.spyOn(formsApi, 'saveDraft').mockResolvedValue({ saved: true, previewUrl: '' });
  await store.load(3);
  return store;
}
function clickLabel(wrapper: VueWrapper, label: string) { return wrapper.get(`button[aria-label="${label}"]`).trigger('click'); }
async function menu(wrapper: VueWrapper) {
  await wrapper.get('button').trigger('click');
  await flushPromises();
}
function item(tag: string): HTMLElement {
  const found = [...document.querySelectorAll<HTMLElement>('.q-menu .q-item')].find((el) => el.textContent?.includes(tag));
  if (!found) throw new Error(`Missing tag ${tag}`);
  return found;
}
beforeEach(() => { setActivePinia(createPinia()); vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] }); });
afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount());
  vi.clearAllTimers(); vi.useRealTimers(); vi.restoreAllMocks(); document.body.replaceChildren();
});

describe('real builder canvas components', () => {
  it('selects, reorders, duplicates and removes cards through real nested lists', async () => {
    const store = await loaded();
    store.document!.fields[2]!.settings = { repeatable: true, addLabel: 'Add person' };
    store.document!.fields[0]!.logic = { action: 'show', match: 'all', rules: [{ field: 'b', op: 'is', value: 'yes' }] };
    store.errors = [{ path: 'a', code: 'test', message: 'Invalid field' }];
    const wrapper = keep(mount(CanvasList, { props: { listId: 'root', elements: store.document!.fields, root: true }, global: global() }));
    expect(wrapper.findAll('[data-list-id]')).toHaveLength(4);
    expect(wrapper.text()).toContain('Add person');
    const cards = () => wrapper.findAll('.swf-list--root > .swf-el');
    expect(cards()[0]!.classes()).toContain('swf-el--error');
    expect(cards()[0]!.text()).toContain('Conditional');
    await cards()[0]!.trigger('keydown', { key: 'Enter' });
    expect(store.selectedId).toBe('a');
    expect(cards()[0]!.attributes('aria-current')).toBe('true');
    await cards()[1]!.trigger('keydown', { key: ' ', altKey: false });
    expect(store.selectedId).toBe('b');
    await cards()[1]!.trigger('keydown', { key: 'ArrowUp', altKey: true });
    expect(store.document!.fields[0]!.id).toBe('b');
    await cards()[0]!.trigger('keydown', { key: 'ArrowDown', altKey: true });
    expect(store.document!.fields[1]!.id).toBe('b');
    await cards()[0]!.get('button[aria-label="Move down"]').trigger('click');
    expect(store.document!.fields[1]!.id).toBe('a');
    await cards()[1]!.get('button[aria-label="Move up"]').trigger('click');
    expect(store.document!.fields[0]!.id).toBe('a');
    const before = store.document!.fields.length;
    await cards()[0]!.get('button[aria-label="Duplicate"]').trigger('click');
    expect(store.document!.fields).toHaveLength(before + 1);
    expect(store.document!.fields[1]!.id).not.toBe('a');
    await cards()[1]!.get('button[aria-label="Delete"]').trigger('click');
    expect(store.document!.fields).toHaveLength(before);
    await wrapper.get('[data-list-id="g"] .swf-el').trigger('click');
    expect(store.selectedId).toBe('x');
    await wrapper.get('[data-list-id="c1"]').trigger('click');
    expect(store.selectedId).toBe('c1');
    const root = wrapper.get('.swf-list--root').element as HTMLElement;
    const group = wrapper.get('[data-list-id="g"]').element as HTMLElement;
    const moved = cards()[1]!.element as HTMLElement;
    const originalIndex = [...root.children].indexOf(moved);
    group.appendChild(moved);
    Sortable.get(root)!.option('onEnd')!({ from: root, to: group, item: moved, oldIndex: originalIndex, oldDraggableIndex: originalIndex, newDraggableIndex: 1 } as SortableEvent);
    await flushPromises();
    expect(store.tree.node('g')!.children!.map((node) => node.id)).toContain('b');
    expect(wrapper.get('[data-list-id="g"]').text()).toContain('b');
    expect(store.canUndo).toBe(true);
  });

  it('renders unknown fields and moves a real card between steps', async () => {
    const store = await loaded();
    store.document!.fields = [{ id: 'a', type: 'mystery', adminLabel: 'Unknown addon' }, { id: 'break', type: 'page-break', label: 'Next' }, { id: 'b', type: 'input', key: 'b' }];
    store.document!.settings.steps = { enabled: true, firstLabel: 'Start', indicator: 'numbers' };
    const wrapper = keep(mount(CanvasList, { props: { listId: stepListId(0), elements: [store.document!.fields[0]!] }, global: global() }));
    expect(wrapper.get('.swf-el').classes()).toContain('swf-el--unknown');
    expect(wrapper.get('.swf-el').attributes('aria-label')).toContain('Unknown addon');
    await clickLabel(wrapper, 'Move to step');
    await flushPromises();
    const target = [...document.querySelectorAll<HTMLElement>('.q-menu .q-item')].find((el) => el.textContent?.includes('Next'))!;
    target.click(); await flushPromises();
    expect(store.activeStep).toBe(1);
    expect(store.document!.fields.filter((node) => node.type !== 'page-break').map((node) => node.id)).toEqual(['b', 'a']);
  });

  it('resizes actual columns with keyboard and pointer events and keeps nested cards', async () => {
    const store = await loaded();
    const element = store.document!.fields[3]!;
    element.columns![0]!.children.push({ id: 'inside', type: 'input', key: 'inside', label: 'Inside' });
    element.columns![0]!.logic = { action: 'show', match: 'all', rules: [{ field: 'a', op: 'is', value: 'x' }] };
    const wrapper = keep(mount(ColumnsCanvas, { props: { element }, global: global() }));
    expect(wrapper.get('[data-list-id="c1"]').text()).toContain('inside');
    const handle = wrapper.get('[role="separator"]');
    await handle.trigger('keydown', { key: 'ArrowRight' });
    expect(element.columns!.map((column) => column.width)).toEqual([7, 5]);
    await handle.trigger('keydown', { key: 'ArrowLeft' });
    await handle.trigger('keydown', { key: 'Home' });
    expect(element.columns!.map((column) => column.width)).toEqual([6, 6]);
    vi.spyOn(wrapper.element, 'getBoundingClientRect').mockReturnValue({ width: 1200 } as DOMRect);
    Object.defineProperty(handle.element, 'setPointerCapture', { value: vi.fn(), configurable: true });
    const pointer = async (type: string, clientX: number) => {
      const event = new MouseEvent(type, { bubbles: true, cancelable: true, clientX });
      Object.defineProperty(event, 'pointerId', { value: 1 });
      handle.element.dispatchEvent(event); await flushPromises();
    };
    await pointer('pointerdown', 100);
    await pointer('pointermove', 300);
    await pointer('pointermove', 300);
    expect(element.columns!.map((column) => column.width)).toEqual([8, 4]);
    await handle.trigger('pointerup', { pointerId: 1 });
    await pointer('pointermove', 500);
    expect(element.columns!.map((column) => column.width)).toEqual([8, 4]);
    expect(wrapper.attributes('style')).toContain('8fr 4fr');
  });
});

describe('real options editor', () => {
  it('edits labels and stable values, moves/removes choices and adds unique values', async () => {
    const options = ref([{ label: 'One', value: 'one', image: '' }, { label: 'Two', value: 'fixed', image: '' }]);
    const Host = defineComponent({ setup: () => () => h(OptionsEditor, { modelValue: options.value, allowImages: true, 'onUpdate:modelValue': (value) => { options.value = value as typeof options.value; } }) });
    const wrapper = keep(mount(Host, { global: global() }));
    await wrapper.get('.swf-options__label input').setValue('Renamed');
    expect(options.value[0]!.value).toBe('renamed');
    await wrapper.findAll('.swf-options__label input')[1]!.setValue('Custom');
    expect(options.value[1]!.value).toBe('fixed');
    await wrapper.findAll('.q-toggle')[0]!.trigger('click');
    await wrapper.get('.swf-options__value input').setValue('contract');
    await wrapper.get('.swf-options__label input').setValue('Changed');
    expect(options.value[0]!.value).toBe('contract');
    await wrapper.findAll('.swf-options__row')[1]!.get('button[aria-label="Move up"]').trigger('click');
    expect(options.value.map((option) => option.value)).toEqual(['fixed', 'contract']);
    await wrapper.get('button[aria-label="Remove option 1"]').trigger('click');
    expect(options.value).toHaveLength(1);
    const add = wrapper.findAll('button').find((button) => button.text().includes('Add option'))!;
    await add.trigger('click'); await add.trigger('click');
    expect(new Set(options.value.map((option) => option.value)).size).toBe(3);
  });

  it('bulk edits choices, preserves matching images and generates distinct blank/duplicate values', async () => {
    const options = ref([{ label: 'Old', value: 'kept', image: 'https://x/image.png' }]);
    const Host = defineComponent({ setup: () => () => h(OptionsEditor, { modelValue: options.value, allowImages: true, 'onUpdate:modelValue': (value) => { options.value = value as typeof options.value; } }) });
    const wrapper = keep(mount(Host, { attachTo: document.body, global: global() }));
    await wrapper.get('.swf-options__image input').setValue('https://x/new.png');
    await wrapper.findAll('button').find((button) => button.text().includes('Edit as text'))!.trigger('click');
    await flushPromises();
    const textarea = document.querySelector<HTMLTextAreaElement>('.q-dialog textarea')!;
    expect(textarea.value).toContain('Old | kept');
    textarea.value = 'Changed | kept\n\nSame\nSame\n!!!\nExplicit | stored';
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
    [...document.querySelectorAll<HTMLButtonElement>('.q-dialog button')].find((button) => button.textContent?.includes('Apply'))!.click();
    await flushPromises();
    expect(options.value.map((option) => option.value)).toEqual(['kept', 'same', 'same-2', 'field', 'stored']);
    expect(options.value[0]!.image).toBe('https://x/new.png');
  });
});

describe('real merge tag menu', () => {
  it('offers scoped tags without exposing individual repeater children, and emits without an input', async () => {
    const store = await loaded();
    store.document!.fields[2]!.key = 'group';
    store.document!.fields.push({ id: 'repeat', type: 'group', key: 'rows', settings: { repeatable: true }, children: [{ id: 'secret', type: 'input', key: 'secret' }] });
    const wrapper = keep(mount(MergeTagMenu, { props: { html: true }, attachTo: document.body, global: global() }));
    await menu(wrapper);
    expect(item('{field:group.x}')).toBeTruthy();
    expect(item('{field:rows:raw}')).toBeTruthy();
    expect(document.querySelector('.q-menu')!.textContent).not.toContain('{field:rows.secret}');
    item('{all_fields}').click(); await flushPromises();
    expect(wrapper.emitted('insert')).toEqual([['{all_fields}']]);
  });

  it('filters email recipients and inserts a tag at the caret in a real input', async () => {
    const store = await loaded();
    store.document!.fields[0]!.settings = { inputType: 'email' };
    const value = ref('Dear friend');
    const Host = defineComponent({ setup: () => () => h('div', { class: 'swf-with-tags' }, [h('input', { value: value.value, onInput: (event: Event) => { value.value = (event.target as HTMLInputElement).value; } }), h(MergeTagMenu, { emailOnly: true })]) });
    const wrapper = keep(mount(Host, { attachTo: document.body, global: global() }));
    const input = wrapper.get('input').element;
    input.setSelectionRange(5, 11);
    await menu(wrapper);
    expect(document.querySelector('.q-menu')!.textContent).not.toContain('{field:b}');
    expect(document.querySelector('.q-menu')!.textContent).not.toContain(':raw}');
    item('{field:a}').click(); await flushPromises();
    expect(value.value).toBe('Dear {field:a}');
    expect(document.activeElement).toBe(input);
    expect(input.selectionStart).toBe(value.value.length);
  });

  it('shows the no-fields state and supports native email controls without a caret API', async () => {
    await loaded();
    const Host = defineComponent({ setup: () => () => h('div', { class: 'swf-with-tags' }, [h('input', { type: 'email', value: 'prefix' }), h(MergeTagMenu, { emailOnly: true })]) });
    const wrapper = keep(mount(Host, { attachTo: document.body, global: global() }));
    await menu(wrapper);
    expect(document.querySelector('.q-menu')!.textContent).toContain('No suitable fields');
    item('{admin_email}').click(); await flushPromises();
    expect(wrapper.get('input').element.value).toBe('prefix{admin_email}');
  });
});
