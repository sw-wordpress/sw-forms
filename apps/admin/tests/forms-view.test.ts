import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { Dialog, Notify, Quasar } from 'quasar';
import { createI18n } from 'vue-i18n';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { formsApi } from '@/api';
import { ApiError } from '@/api/client';
import { setConfigForTests } from '@/config';
import FormsView from '@/features/forms/FormsView.vue';
import en from '@/i18n/en';
import type { FormDetail, FormSummary } from '@/types/api';

const push = vi.fn();
vi.mock('vue-router', () => ({ useRouter: () => ({ push }), useRoute: () => ({ query: {}, params: {} }) }));
const notify = { success: vi.fn(), info: vi.fn(), error: vi.fn() };
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }));

const ALL_CAPS = ['swf_manage_forms', 'swf_view_entries', 'swf_manage_entries', 'swf_settings'];

function config(capabilities: string[]): void {
  setConfigForTests({ restRoot: 'http://x/wp-json/swforms/v1/', wpRestRoot: 'http://x/wp-json/wp/v2/', nonce: 'n', locale: 'en_US', version: '', adminUrl: '', capabilities, siteUrl: '' });
}

function summary(over: Partial<FormSummary> = {}): FormSummary {
  return { id: 1, title: 'Contact', status: 'active', currentVersion: 2, hasDraft: false, entries: 3, unread: 0, createdAt: '2026-01-01T10:00:00Z', updatedAt: '2026-01-02T10:00:00Z', ...over };
}

const dialogStub = { props: { modelValue: Boolean }, emits: ['update:modelValue', 'hide'], template: '<div v-if="modelValue" class="dialog-stub"><slot /></div>' };
const slotStub = { template: '<div><slot /></div>' };

function mountView(): VueWrapper {
  return mount(FormsView, {
    attachTo: document.body,
    global: {
      plugins: [[Quasar, { plugins: { Notify, Dialog } }], createI18n({ legacy: false, locale: 'en', messages: { en } })],
      stubs: { RouterLink: { props: ['to'], template: '<a :href="String(to)"><slot /></a>' }, QDialog: dialogStub, QMenu: slotStub, QTooltip: true, FormLibrary: true },
    },
  });
}

function buttonByText(wrapper: VueWrapper, text: string, index = 0, selector = 'button') {
  const label = (el: Element) => el.querySelector(':scope > .q-btn__content > .block, :scope > .q-item__section--main')?.textContent?.trim();
  const found = wrapper.findAll(selector).filter((b) => label(b.element) === text);
  if (!found[index]) throw new Error(`No button "${text}"`);
  return found[index];
}

beforeEach(() => {
  setActivePinia(createPinia());
  config(ALL_CAPS);
  push.mockReset().mockResolvedValue(undefined);
  Object.values(notify).forEach((fn) => fn.mockReset());
});
afterEach(() => {
  vi.restoreAllMocks();
  document.body.innerHTML = '';
  setConfigForTests(null);
});

describe('FormsView', () => {
  it('lists forms with chips, links and shortcode, and filters by search', async () => {
    vi.spyOn(formsApi, 'list').mockResolvedValue([
      summary({ hasDraft: true, unread: 2, webhookFailures: 4 }),
      summary({ id: 2, title: 'Newsletter', status: 'inactive', currentVersion: 0 }),
    ]);
    const wrapper = mountView();
    await flushPromises();
    const rows = wrapper.findAll('tbody tr');
    expect(rows).toHaveLength(2);
    expect(rows[0]!.text()).toContain('Unsaved changes');
    expect(rows[0]!.text()).toContain('2 new');
    expect(rows[0]!.find('a[href="/forms/1/entries"].swf-chip--danger').exists()).toBe(true);
    expect(rows[0]!.text()).toContain('[sw_form id="1"]');
    expect(rows[1]!.text()).toContain('Not saved yet');
    expect(rows[1]!.text()).toContain('Inactive');

    await wrapper.get('input[aria-label="Search forms"]').setValue('news');
    expect(wrapper.findAll('tbody tr')).toHaveLength(1);
    await wrapper.get('input[aria-label="Search forms"]').setValue('zzz');
    expect(wrapper.text()).toContain('No form matches the search.');
    wrapper.unmount();
  });

  it('shows the empty state and opens the create dialog, then routes to the builder', async () => {
    vi.spyOn(formsApi, 'list').mockResolvedValue([]);
    const create = vi.spyOn(formsApi, 'create').mockResolvedValue({ id: 9 } as FormDetail);
    const wrapper = mountView();
    await flushPromises();
    expect(wrapper.text()).toContain('No forms yet');
    await buttonByText(wrapper, 'New form', 1).trigger('click');
    expect(wrapper.find('.dialog-stub').exists()).toBe(true);
    await wrapper.get('.dialog-stub input').setValue('  Survey  ');
    await wrapper.get('.dialog-stub form').trigger('submit');
    await flushPromises();
    expect(create).toHaveBeenCalledWith('Survey');
    expect(push).toHaveBeenCalledWith('/forms/9');
    expect(wrapper.find('.dialog-stub').exists()).toBe(false);
    wrapper.unmount();
  });

  it('reports a failed create without leaving the dialog', async () => {
    vi.spyOn(formsApi, 'list').mockResolvedValue([]);
    vi.spyOn(formsApi, 'create').mockRejectedValue(new ApiError('swf_invalid', 'Title missing', 400));
    const wrapper = mountView();
    await flushPromises();
    await buttonByText(wrapper, 'New form').trigger('click');
    await wrapper.get('.dialog-stub form').trigger('submit');
    await flushPromises();
    expect(push).not.toHaveBeenCalled();
    expect(notify.error).toHaveBeenCalledWith(expect.objectContaining({ message: 'Title missing' }));
    expect(wrapper.find('.dialog-stub').exists()).toBe(true);
    wrapper.unmount();
  });

  it('shows a load error and retries', async () => {
    const list = vi.spyOn(formsApi, 'list').mockRejectedValueOnce(new ApiError('swf_down', 'Server down', 500)).mockResolvedValue([summary()]);
    const wrapper = mountView();
    await flushPromises();
    expect(wrapper.text()).toContain('Server down');
    await buttonByText(wrapper, 'Try again').trigger('click');
    await flushPromises();
    expect(list).toHaveBeenCalledTimes(2);
    expect(wrapper.findAll('tbody tr')).toHaveLength(1);
    wrapper.unmount();
  });

  it('toggles the status and reloads; reports errors', async () => {
    const list = vi.spyOn(formsApi, 'list').mockResolvedValue([summary(), summary({ id: 2, title: 'B', status: 'inactive' })]);
    const setStatus = vi.spyOn(formsApi, 'setStatus').mockResolvedValue({} as FormDetail);
    const wrapper = mountView();
    await flushPromises();
    const toggles = wrapper.findAll('.q-toggle');
    await toggles[0]!.trigger('click');
    await flushPromises();
    expect(setStatus).toHaveBeenCalledWith(1, 'inactive');
    await toggles[1]!.trigger('click');
    await flushPromises();
    expect(setStatus).toHaveBeenCalledWith(2, 'active');
    expect(list).toHaveBeenCalledTimes(3);
    setStatus.mockRejectedValueOnce(new Error('nope'));
    await wrapper.findAll('.q-toggle')[0]!.trigger('click');
    await flushPromises();
    expect(notify.error).toHaveBeenCalledWith(new Error('nope'));
    wrapper.unmount();
  });

  it('duplicates, exports and copies the shortcode', async () => {
    const list = vi.spyOn(formsApi, 'list').mockResolvedValue([summary()]);
    const duplicate = vi.spyOn(formsApi, 'duplicate').mockResolvedValue({} as FormDetail);
    const exportForm = vi.spyOn(formsApi, 'export').mockResolvedValue({ title: 'Contact' });
    const createUrl = vi.fn(() => 'blob:x');
    const revoke = vi.fn();
    Object.assign(URL, { createObjectURL: createUrl, revokeObjectURL: revoke });
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
    vi.useFakeTimers({ toFake: ['setTimeout'] });
    const wrapper = mountView();
    await flushPromises();

    await buttonByText(wrapper, 'Duplicate', 0, '.q-item').trigger('click');
    await flushPromises();
    expect(duplicate).toHaveBeenCalledWith(1);
    expect(notify.success).toHaveBeenCalledWith('Form duplicated');
    expect(list).toHaveBeenCalledTimes(2);

    await buttonByText(wrapper, en.m5.export, 0, '.q-item').trigger('click');
    await flushPromises();
    expect(exportForm).toHaveBeenCalledWith(1);
    expect(createUrl).toHaveBeenCalled();
    expect(click).toHaveBeenCalled();
    vi.advanceTimersByTime(1000);
    expect(revoke).toHaveBeenCalledWith('blob:x');
    vi.useRealTimers();

    await buttonByText(wrapper, '[sw_form id="1"]').trigger('click');
    await flushPromises();
    expect(writeText).toHaveBeenCalledWith('[sw_form id="1"]');
    expect(notify.success).toHaveBeenCalledWith('Copied to the clipboard');

    duplicate.mockRejectedValueOnce(new Error('dup failed'));
    exportForm.mockRejectedValueOnce(new Error('export failed'));
    await buttonByText(wrapper, 'Duplicate', 0, '.q-item').trigger('click');
    await buttonByText(wrapper, en.m5.export, 0, '.q-item').trigger('click');
    await flushPromises();
    expect(notify.error).toHaveBeenCalledWith(new Error('dup failed'));
    expect(notify.error).toHaveBeenCalledWith(new Error('export failed'));
    wrapper.unmount();
  });

  it('confirms deletion, handles errors and cancel', async () => {
    const list = vi.spyOn(formsApi, 'list').mockResolvedValue([summary({ entries: 7 })]);
    const remove = vi.spyOn(formsApi, 'remove').mockRejectedValueOnce(new Error('locked')).mockResolvedValue({ deleted: true });
    const wrapper = mountView();
    await flushPromises();
    await buttonByText(wrapper, 'Delete', 0, '.q-item').trigger('click');
    const dialog = wrapper.get('.dialog-stub');
    expect(dialog.text()).toContain('Delete this form?');
    expect(dialog.text()).toContain('All 7 entries of this form are deleted as well.');

    await buttonByText(wrapper, 'Delete', 0, '.dialog-stub button').trigger('click');
    await flushPromises();
    expect(notify.error).toHaveBeenCalledWith(new Error('locked'));
    expect(wrapper.find('.dialog-stub').exists()).toBe(true);

    await buttonByText(wrapper, 'Delete', 0, '.dialog-stub button').trigger('click');
    await flushPromises();
    expect(remove).toHaveBeenCalledTimes(2);
    expect(remove).toHaveBeenCalledWith(1);
    expect(notify.success).toHaveBeenCalledWith('Form deleted');
    expect(list).toHaveBeenCalledTimes(2);
    expect(wrapper.find('.dialog-stub').exists()).toBe(false);

    await buttonByText(wrapper, 'Delete', 0, '.q-item').trigger('click');
    await buttonByText(wrapper, 'Cancel', 0, '.dialog-stub button').trigger('click');
    expect(wrapper.find('.dialog-stub').exists()).toBe(false);
    wrapper.unmount();
  });

  it('hides management actions without the manage capability', async () => {
    config(['swf_view_entries']);
    vi.spyOn(formsApi, 'list').mockResolvedValue([summary()]);
    const wrapper = mountView();
    await flushPromises();
    expect(wrapper.findAll('button').some((b) => b.text().includes('New form'))).toBe(false);
    expect(wrapper.text()).toContain('Show entries');
    expect(wrapper.text()).not.toContain('Duplicate');
    expect(wrapper.find('.q-toggle').classes()).toContain('disabled');
    wrapper.unmount();
  });
});
