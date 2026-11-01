import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { Quasar } from 'quasar';
import { createI18n } from 'vue-i18n';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { entriesApi } from '@/api';
import { setConfigForTests } from '@/config';
import EntriesView from '@/features/entries/EntriesView.vue';
import en from '@/i18n/en';
import type { EntryList, EntrySummary } from '@/types/api';

const push = vi.fn();
vi.mock('vue-router', () => ({ useRouter: () => ({ push }), useRoute: () => ({ query: {}, params: {} }) }));
const notify = { success: vi.fn(), info: vi.fn(), error: vi.fn() };
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }));

vi.stubGlobal('ResizeObserver', class { observe(): void {} unobserve(): void {} disconnect(): void {} });

function config(capabilities: string[]): void {
  setConfigForTests({ restRoot: 'http://x/wp-json/swforms/v1/', wpRestRoot: 'http://x/wp-json/wp/v2/', nonce: 'n', locale: 'en_US', version: '', adminUrl: '', capabilities, siteUrl: '' });
}

function entry(id: number, over: Partial<EntrySummary> = {}): EntrySummary {
  return { id, formId: 1, formVersion: 1, status: 'read', starred: false, meta: {}, userId: 0, createdAt: '2026-01-02T10:00:00Z', values: { name: `Person ${id}` }, ...over };
}

function list(items: EntrySummary[], over: Partial<EntryList> = {}): EntryList {
  return { items, total: items.length, pages: 1, counts: { inbox: items.length, unread: 1, starred: 0, spam: 2, trash: 3 }, columns: [{ id: 'name', label: 'Name' }, { id: 'email', label: 'E-mail' }], ...over };
}

const sheetStub = {
  props: { entryId: { type: Number, default: null } },
  emits: ['close', 'changed'],
  template: '<div class="sheet-stub" :data-id="entryId"><button class="sheet-close" @click="$emit(\'close\')" /><button class="sheet-changed" @click="$emit(\'changed\')" /></div>',
};

function mountView(props: { formId: number; entryId: number | null } = { formId: 1, entryId: null }): VueWrapper {
  return mount(EntriesView, {
    props,
    global: { plugins: [Quasar, createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: { EntrySheet: sheetStub } },
  });
}

function button(wrapper: VueWrapper, text: string) {
  const found = wrapper.findAll('button').find((b) => b.element.querySelector(':scope > .q-btn__content > .block')?.textContent?.trim() === text);
  if (!found) throw new Error(`No button "${text}"`);
  return found;
}

const lastQuery = (spy: { mock: { lastCall?: unknown[] } }) => spy.mock.lastCall?.[1] as Record<string, unknown>;

beforeEach(() => {
  config(['swf_view_entries', 'swf_manage_entries']);
  push.mockReset().mockResolvedValue(undefined);
  Object.values(notify).forEach((fn) => fn.mockReset());
});
afterEach(() => {
  vi.useRealTimers();
  vi.restoreAllMocks();
  setConfigForTests(null);
});

describe('EntriesView', () => {
  it('loads the inbox and renders rows, counts, export link and the active entry', async () => {
    const spy = vi.spyOn(entriesApi, 'list').mockResolvedValue(list([entry(1, { status: 'unread', starred: true }), entry(2, { values: {} })]));
    const wrapper = mountView({ formId: 1, entryId: 2 });
    expect(wrapper.find('.q-skeleton').exists()).toBe(true);
    await flushPromises();
    expect(spy).toHaveBeenCalledWith(1, { filter: 'inbox', search: '', page: 1, per_page: 25, order: 'desc' });
    expect(wrapper.text()).toContain('Inbox (2)');
    expect(wrapper.text()).toContain('Trash (3)');
    const rows = wrapper.findAll('tbody tr');
    expect(rows).toHaveLength(2);
    expect(rows[0]!.classes()).toContain('swf-entries__row--unread');
    expect(rows[0]!.find('.swf-dot').exists()).toBe(true);
    expect(rows[0]!.text()).toContain('Person 1');
    expect(rows[0]!.find(`[aria-label="${en.entries.starred}"]`).exists()).toBe(true);
    expect(rows[1]!.classes()).toContain('swf-entries__row--active');
    expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual(expect.arrayContaining(['Name', 'E-mail']));
    const href = wrapper.findAll('a').find((a) => a.text().includes(en.m3.exportCsv))?.attributes('href') ?? '';
    expect(href).toContain('forms/1/entries/export');
    expect(href).toContain('filter=inbox');
    expect(wrapper.get('.sheet-stub').attributes('data-id')).toBe('2');
    expect(wrapper.find('.swf-entries__pager').exists()).toBe(false);
  });

  it('opens entries from the row and the date link, and closes the sheet', async () => {
    vi.spyOn(entriesApi, 'list').mockResolvedValue(list([entry(4)]));
    const wrapper = mountView({ formId: 3, entryId: null });
    await flushPromises();
    await wrapper.get('tbody tr').trigger('click');
    expect(push).toHaveBeenLastCalledWith('/forms/3/entries/4');
    await wrapper.get('.swf-entries__open').trigger('click');
    expect(push).toHaveBeenCalledTimes(2);
    await wrapper.get('.sheet-close').trigger('click');
    expect(push).toHaveBeenLastCalledWith('/forms/3/entries');
  });

  it('reloads when the sheet reports changes or the form changes', async () => {
    const spy = vi.spyOn(entriesApi, 'list').mockResolvedValue(list([entry(1)]));
    const wrapper = mountView();
    await flushPromises();
    await wrapper.get('.sheet-changed').trigger('click');
    await flushPromises();
    expect(spy).toHaveBeenCalledTimes(2);
    await wrapper.setProps({ formId: 2 });
    await flushPromises();
    expect(spy).toHaveBeenLastCalledWith(2, expect.objectContaining({ filter: 'inbox' }));
  });

  it('switches filters, toggles the sort order and paginates', async () => {
    const spy = vi.spyOn(entriesApi, 'list').mockResolvedValue(list([entry(1)], { pages: 3 }));
    const wrapper = mountView();
    await flushPromises();
    expect(wrapper.find('.swf-entries__pager').exists()).toBe(true);

    await wrapper.findAll('.q-pagination button').find((b) => b.text() === '2')!.trigger('click');
    await flushPromises();
    expect(lastQuery(spy)).toMatchObject({ page: 2 });

    await wrapper.get('.swf-sort').trigger('click');
    await flushPromises();
    expect(lastQuery(spy)).toMatchObject({ order: 'asc', page: 1 });
    await wrapper.get('.swf-sort').trigger('click');
    await flushPromises();
    expect(lastQuery(spy)).toMatchObject({ order: 'desc' });

    await wrapper.findAll('.q-tab').find((tab) => tab.text().startsWith('Spam'))!.trigger('click');
    await flushPromises();
    expect(lastQuery(spy)).toMatchObject({ filter: 'spam', page: 1 });
  });

  it('debounces the search and shows the no-match state', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    const spy = vi.spyOn(entriesApi, 'list').mockResolvedValue(list([entry(1)]));
    const wrapper = mountView();
    await flushPromises();
    spy.mockResolvedValue(list([]));
    const input = wrapper.get(`input[aria-label="${en.entries.search}"]`);
    await input.setValue('ad');
    await input.setValue(' ada ');
    await vi.advanceTimersByTimeAsync(349);
    expect(spy).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(1);
    await flushPromises();
    expect(spy).toHaveBeenCalledTimes(2);
    expect(lastQuery(spy)).toMatchObject({ search: 'ada', page: 1 });
    expect(wrapper.text()).toContain(en.entries.noMatch);
    expect(wrapper.text()).not.toContain(en.entries.emptyText);
  });

  it('shows the empty inbox', async () => {
    vi.spyOn(entriesApi, 'list').mockResolvedValue(list([]));
    const wrapper = mountView();
    await flushPromises();
    expect(wrapper.text()).toContain(en.entries.emptyTitle);
    expect(wrapper.text()).toContain(en.entries.emptyText);
  });

  it('shows load errors and retries', async () => {
    const spy = vi.spyOn(entriesApi, 'list').mockRejectedValueOnce(new Error('Server down')).mockRejectedValueOnce('plain').mockResolvedValue(list([entry(1)]));
    const wrapper = mountView();
    await flushPromises();
    expect(wrapper.text()).toContain('Server down');
    await button(wrapper, en.common.retry).trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('plain');
    await button(wrapper, en.common.retry).trigger('click');
    await flushPromises();
    expect(spy).toHaveBeenCalledTimes(3);
    expect(wrapper.findAll('tbody tr')).toHaveLength(1);
  });

  it('selects entries and runs bulk actions', async () => {
    const spy = vi.spyOn(entriesApi, 'list').mockResolvedValue(list([entry(1), entry(2)]));
    const bulk = vi.spyOn(entriesApi, 'bulk').mockResolvedValueOnce({ affected: 2 }).mockRejectedValueOnce(new Error('bulk failed'));
    const wrapper = mountView();
    await flushPromises();
    expect(wrapper.find('[role="toolbar"]').exists()).toBe(false);

    await wrapper.get(`[aria-label="${en.entries.selectAll}"]`).trigger('click');
    expect(wrapper.get('[role="toolbar"]').text()).toContain('2 selected');
    expect(wrapper.get('[role="toolbar"]').text()).toContain(en.entries.actions.star);

    await button(wrapper, en.entries.actions.read).trigger('click');
    await flushPromises();
    expect(bulk).toHaveBeenCalledWith([1, 2], 'read');
    expect(notify.success).toHaveBeenCalledWith('2 entries changed');
    expect(spy).toHaveBeenCalledTimes(2);
    expect(wrapper.find('[role="toolbar"]').exists()).toBe(false);

    await wrapper.get('[aria-label="Select entry 2"]').trigger('click');
    expect(wrapper.get('[role="toolbar"]').text()).toContain('1 selected');
    await button(wrapper, en.entries.actions.trash).trigger('click');
    await flushPromises();
    expect(bulk).toHaveBeenLastCalledWith([2], 'trash');
    expect(notify.error).toHaveBeenCalledWith(new Error('bulk failed'));

    await wrapper.get(`[aria-label="${en.entries.selectAll}"]`).trigger('click');
    await wrapper.get(`[aria-label="${en.entries.selectAll}"]`).trigger('click');
    expect(wrapper.find('[role="toolbar"]').exists()).toBe(false);
  });

  it('drops selected ids that are no longer listed and offers restore actions in the trash', async () => {
    const spy = vi.spyOn(entriesApi, 'list').mockResolvedValue(list([entry(1), entry(2)]));
    const wrapper = mountView();
    await flushPromises();
    await wrapper.get('[aria-label="Select entry 1"]').trigger('click');
    await wrapper.get('[aria-label="Select entry 2"]').trigger('click');
    spy.mockResolvedValue(list([entry(2)]));
    await wrapper.findAll('.q-tab').find((tab) => tab.text().startsWith('Trash'))!.trigger('click');
    await flushPromises();
    const toolbar = wrapper.get('[role="toolbar"]');
    expect(toolbar.text()).toContain('1 selected');
    expect(toolbar.text()).toContain(en.entries.actions.restore);
    expect(toolbar.text()).toContain(en.entries.actions.deleteForever);
    expect(toolbar.text()).not.toContain(en.entries.actions.star);
  });

  it('hides selection without the manage capability', async () => {
    config(['swf_view_entries']);
    vi.spyOn(entriesApi, 'list').mockResolvedValue(list([entry(1)]));
    const wrapper = mountView();
    await flushPromises();
    expect(wrapper.find('.q-checkbox').exists()).toBe(false);
    expect(wrapper.findAll('tbody tr')).toHaveLength(1);
  });
});
