import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { reactive } from 'vue';
import type * as VueRouter from 'vue-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { formsApi } from '@/api';
import { ApiError } from '@/api/client';
import { normaliseConfig, setConfigForTests } from '@/config';
import BuilderView from '@/features/builder/BuilderView.vue';
import { useAppStore } from '@/stores/app';
import { useBuilderStore } from '@/stores/builder';
import { detail, directives, i18n, stubs, types } from './builder-views-stubs';

type Guard = (to: { params: Record<string, string> }) => Promise<boolean> | boolean;
const router = vi.hoisted(() => ({
  push: vi.fn(),
  guard: null as null | ((to: { params: Record<string, string> }) => Promise<boolean> | boolean),
  route: null as null | { params: Record<string, string>; query: Record<string, string> },
}));
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof VueRouter>()),
  useRouter: () => ({ push: router.push }),
  useRoute: () => router.route,
  onBeforeRouteLeave: (guard: Guard) => {
    router.guard = guard;
  },
}));
const notify = vi.hoisted(() => ({ success: vi.fn(), info: vi.fn(), error: vi.fn() }));
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }));

const ALL = ['swf_manage_forms', 'swf_view_entries', 'swf_manage_entries', 'swf_settings'];
const child = (name: string) => ({ template: `<div class="${name}" />` });
const viewStubs = {
  ...stubs,
  EmptyState: { props: ['title', 'text'], template: '<div class="empty">{{ text }}<slot /></div>' },
  PalettePanel: child('palette'),
  BuilderCanvas: child('canvas'),
  InspectorPanel: child('inspector'),
  FormSettingsPanel: child('form-settings'),
  FormHistory: child('history'),
  NotificationsPanel: child('notifications'),
  WebhooksPanel: child('webhooks'),
  EntriesView: { props: ['formId', 'entryId'], template: '<div class="entries" :data-form="formId" :data-entry="String(entryId)" />' },
  PreviewDialog: { props: ['modelValue'], template: '<div class="preview" :data-open="String(modelValue)" />' },
};

let wrapper: VueWrapper | null = null;
async function mountView(params: Record<string, string> = { id: '3' }, capabilities = ALL) {
  setConfigForTests(normaliseConfig({ restRoot: 'http://x/wp-json/swforms/v1/', capabilities }));
  router.route = reactive({ params, query: {} });
  wrapper = mount(BuilderView, { attachTo: document.body, global: { plugins: [i18n()], stubs: viewStubs, directives } });
  await flushPromises();
  return { wrapper, store: useBuilderStore() };
}
const key = (init: KeyboardEventInit, target: EventTarget = window) => {
  const event = new KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init });
  target.dispatchEvent(event);
  return event;
};
const button = (w: VueWrapper, label: string) => w.find(`button[aria-label="${label}"]`);

beforeEach(() => {
  setActivePinia(createPinia());
  vi.spyOn(formsApi, 'get').mockResolvedValue(detail());
  vi.spyOn(formsApi, 'fieldTypes').mockResolvedValue(types);
  vi.spyOn(formsApi, 'saveDraft').mockResolvedValue({ saved: true, previewUrl: '' });
  vi.spyOn(formsApi, 'save').mockResolvedValue(detail());
});
afterEach(() => {
  wrapper?.unmount();
  wrapper = null;
  vi.clearAllMocks();
  setConfigForTests(null);
});

describe('BuilderView', () => {
  it('loads the form, collapses the navigation and restores it on unmount', async () => {
    const app = useAppStore();
    app.railCollapsed = false;
    const { wrapper: w, store } = await mountView();
    expect(app.railCollapsed).toBe(true);
    expect(formsApi.get).toHaveBeenCalledWith(3);
    expect(w.find('.palette').exists() && w.find('.canvas').exists() && w.find('.inspector').exists()).toBe(true);
    expect(w.find('[role="status"]').text()).toBe('All changes saved');
    expect(w.find('.tabs').attributes('data-current')).toBe('build');

    await w.find('input[aria-label="Form title"]').setValue('Holiday');
    expect(store.title).toBe('Holiday');
    expect(w.find('[role="status"]').classes()).toContain('swf-builder__state--dirty');
    expect(w.find('[role="status"]').text()).toBe('Unsaved changes');

    await button(w, 'Undo').trigger('click');
    expect(store.title).toBe('Trip');
    await button(w, 'Redo').trigger('click');
    expect(store.title).toBe('Holiday');

    await w.findAll('button').find((b) => b.text() === 'Preview')!.trigger('click');
    expect(w.find('.preview').attributes('data-open')).toBe('true');

    w.unmount();
    wrapper = null;
    expect(app.railCollapsed).toBe(false);
  });

  it('shows the draft state', async () => {
    const { wrapper: w, store } = await mountView();
    store.setTitle('Changed');
    const states: Record<string, string> = { saving: 'Saving draft', saved: 'Draft stored', failed: 'could not be stored' };
    for (const [state, text] of Object.entries(states)) {
      store.draftState = state as typeof store.draftState;
      await flushPromises();
      expect(w.find('[role="status"]').text()).toContain(text);
    }
  });

  it('switches tabs through the router and renders each page', async () => {
    const { wrapper: w } = await mountView();
    await w.find('.tab[data-name="webhooks"]').trigger('click');
    expect(router.push).toHaveBeenCalledWith('/forms/3/webhooks');
    const pages: Record<string, string> = { settings: '.history', notifications: '.notifications', webhooks: '.webhooks', entries: '.entries' };
    for (const [tab, selector] of Object.entries(pages)) {
      router.route!.params.tab = tab;
      await flushPromises();
      expect(w.find(selector).exists()).toBe(true);
    }
    expect(w.find('.entries').attributes('data-entry')).toBe('null');
    router.route!.params.entryId = '9';
    await flushPromises();
    expect(w.find('.entries').attributes('data-entry')).toBe('9');
    router.route!.params.tab = 'nonsense';
    await flushPromises();
    expect(w.find('.canvas').exists()).toBe(true);
  });

  it('reloads when the form id changes and retries after a load error', async () => {
    vi.spyOn(formsApi, 'get').mockRejectedValueOnce(new Error('Not found'));
    const { wrapper: w, store } = await mountView();
    expect(w.find('.empty').text()).toContain('Not found');
    expect(w.find('.preview').exists()).toBe(false);
    await w.findAll('button').find((b) => b.text() === 'Try again')!.trigger('click');
    await flushPromises();
    expect(w.find('.canvas').exists()).toBe(true);

    vi.spyOn(formsApi, 'get').mockRejectedValueOnce('plain failure');
    router.route!.params.id = '4';
    await flushPromises();
    expect(formsApi.get).toHaveBeenLastCalledWith(4);
    expect(w.find('.empty').text()).toContain('plain failure');
    expect(store.formId).toBe(3);
  });

  it('saves, reports rejected documents and navigates to errors', async () => {
    const { wrapper: w, store } = await mountView({ id: '3', tab: 'settings' });
    const save = () => w.findAll('button').find((b) => b.text() === 'Save')!;
    expect(save().attributes('disabled')).toBeDefined();
    store.setTitle('Changed');
    await flushPromises();
    await save().trigger('click');
    await flushPromises();
    expect(formsApi.save).toHaveBeenCalledWith(3, 'Changed', store.document);
    expect(notify.success).toHaveBeenCalled();

    const errors = [
      { path: 'webhook:1', code: 'x', message: 'Hook' },
      { path: 'notification:n', code: 'x', message: 'Mail' },
      { path: 'b', code: 'x', message: 'Field' },
      { path: '', code: 'x', message: 'Form' },
    ];
    vi.spyOn(formsApi, 'save').mockRejectedValueOnce(new ApiError('invalid', 'Invalid', 422, {}, { errors }));
    store.setTitle('Again');
    await flushPromises();
    await save().trigger('click');
    await flushPromises();
    expect(notify.info).toHaveBeenCalled();
    expect(router.push).toHaveBeenLastCalledWith('/forms/3/build');
    const links = w.findAll('[role="alert"] a');
    expect(links.map((a) => a.text())).toEqual(['Hook', 'Mail', 'Field', 'Form']);
    await links[0]!.trigger('click');
    expect(router.push).toHaveBeenLastCalledWith('/forms/3/webhooks');
    await links[1]!.trigger('click');
    expect(router.push).toHaveBeenLastCalledWith('/forms/3/notifications');
    router.push.mockClear();
    await links[2]!.trigger('click');
    expect(store.selectedId).toBe('b');
    expect(router.push).toHaveBeenCalledWith('/forms/3/build');
    router.route!.params.tab = 'build';
    await flushPromises();
    router.push.mockClear();
    await w.findAll('[role="alert"] a')[3]!.trigger('click');
    expect(store.selectedId).toBe('b');
    expect(router.push).not.toHaveBeenCalled();

    // Rejected while already on the build tab: no navigation.
    vi.spyOn(formsApi, 'save').mockRejectedValueOnce(new ApiError('invalid', 'Invalid', 422, {}, { errors }));
    await save().trigger('click');
    await flushPromises();
    expect(router.push).not.toHaveBeenCalled();

    vi.spyOn(formsApi, 'save').mockRejectedValueOnce(new Error('offline'));
    await save().trigger('click');
    await flushPromises();
    expect(notify.error).toHaveBeenCalledWith(new Error('offline'));
  });

  it('handles keyboard shortcuts on the build tab', async () => {
    const { store } = await mountView();
    expect(key({ key: 's', ctrlKey: true }).defaultPrevented).toBe(true);
    await flushPromises();
    expect(formsApi.save).toHaveBeenCalledTimes(1);

    store.select('b');
    expect(key({ key: 'd', metaKey: true }).defaultPrevented).toBe(true);
    const copy = store.selectedId!;
    expect(copy).not.toBe('b');
    expect(store.document!.fields).toHaveLength(7);

    key({ key: 'z', ctrlKey: true });
    expect(store.document!.fields).toHaveLength(6);
    key({ key: 'Z', ctrlKey: true, shiftKey: true });
    expect(store.document!.fields).toHaveLength(7);
    key({ key: 'z', ctrlKey: true });
    key({ key: 'y', ctrlKey: true });
    expect(store.document!.fields).toHaveLength(7);

    store.select(copy);
    key({ key: 'Delete' });
    expect(store.document!.fields).toHaveLength(6);
    expect(store.selectedId).toBeNull();

    store.select('c1');
    key({ key: 'Backspace' });
    expect(store.selectedId).toBe('c1');
    key({ key: 'Escape' });
    expect(store.selectedId).toBeNull();

    store.select('b');
    const field = document.createElement('input');
    document.body.appendChild(field);
    key({ key: 'Delete' }, field);
    expect(store.selectedId).toBe('b');
    field.remove();

    const dialog = document.createElement('div');
    dialog.className = 'q-dialog';
    document.body.appendChild(dialog);
    key({ key: 'Escape' });
    expect(store.selectedId).toBe('b');
    dialog.remove();

    key({ key: 'x' });
    expect(store.selectedId).toBe('b');
    key({ key: 'd', ctrlKey: true, target: null } as KeyboardEventInit);
    router.route!.params.tab = 'settings';
    await flushPromises();
    key({ key: 'Escape' });
    expect(store.selectedId).not.toBeNull();
  });

  it('is read-only without the manage capability', async () => {
    const { wrapper: w, store } = await mountView({ id: '3' }, ['swf_view_entries']);
    expect(w.find('.entries').attributes('data-form')).toBe('3');
    expect(w.findAll('.tab').map((t) => t.attributes('data-name'))).toEqual(['entries']);
    expect(w.find('input[aria-label="Form title"]').attributes('readonly')).toBeDefined();
    expect(button(w, 'Undo').exists()).toBe(false);
    store.setTitle('Changed');
    key({ key: 's', ctrlKey: true });
    await flushPromises();
    expect(formsApi.save).not.toHaveBeenCalled();
  });

  it('protects unsaved changes on unload and when leaving the form', async () => {
    const { store } = await mountView();
    const unload = () => {
      const event = new Event('beforeunload', { cancelable: true });
      window.dispatchEvent(event);
      return event.defaultPrevented;
    };
    expect(unload()).toBe(false);
    expect(await router.guard!({ params: { id: '4' } })).toBe(true);
    expect(formsApi.saveDraft).not.toHaveBeenCalled();

    store.setTitle('Changed');
    expect(unload()).toBe(true);
    expect(await router.guard!({ params: { id: '3' } })).toBe(true);
    expect(formsApi.saveDraft).not.toHaveBeenCalled();
    expect(await router.guard!({ params: { id: '4' } })).toBe(true);
    expect(formsApi.saveDraft).toHaveBeenCalledTimes(1);
    expect(store.draftState).toBe('saved');
    expect(unload()).toBe(false);
    expect(await router.guard!({ params: { id: '4' } })).toBe(true);
    expect(formsApi.saveDraft).toHaveBeenCalledTimes(1);
  });
});
