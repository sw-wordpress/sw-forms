import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { Quasar } from 'quasar';
import { createI18n } from 'vue-i18n';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { entriesApi } from '@/api';
import { ApiError } from '@/api/client';
import { setConfigForTests } from '@/config';
import EntrySheet from '@/features/entries/EntrySheet.vue';
import en from '@/i18n/en';
import type { EntryDetail, EntrySummary, WebhookDelivery } from '@/types/api';

const notify = { success: vi.fn(), info: vi.fn(), error: vi.fn() };
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }));

const ALL_CAPS = ['swf_manage_forms', 'swf_view_entries', 'swf_manage_entries'];

function config(capabilities: string[]): void {
  setConfigForTests({ restRoot: 'http://x/wp-json/swforms/v1/', wpRestRoot: 'http://x/wp-json/wp/v2/', nonce: 'n', locale: 'en_US', version: '', adminUrl: '', capabilities, siteUrl: '' });
}

function delivery(over: Partial<WebhookDelivery> = {}): WebhookDelivery {
  return { id: 5, webhookId: 'crm', eventId: 'e', status: 'success', attempts: 1, httpCode: 200, durationMs: 42, message: 'OK', nextAttemptAt: null, ...over };
}

function detail(over: Partial<EntryDetail['entry']> = {}, rest: Partial<EntryDetail> = {}): EntryDetail {
  return {
    entry: { id: 7, formId: 1, formVersion: 3, status: 'read', starred: false, meta: { sourceUrl: 'https://site.test/contact' }, userId: 0, createdAt: '2026-01-02T10:00:00Z', values: {}, data: {}, ...over },
    fields: [{ id: 'name', type: 'text', label: 'Name', value: 'Ada' }],
    user: '',
    log: [
      { type: 'email', actionId: 'n1', status: 'success', message: 'Mail sent', createdAt: '2026-01-02T10:00:01Z' },
      { type: 'webhook', actionId: 'w1', status: 'failed', message: 'Timeout', createdAt: '2026-01-02T10:00:02Z' },
      { type: 'custom', actionId: 'c1', status: 'skipped', message: 'Skipped', createdAt: '2026-01-02T10:00:03Z' },
    ],
    ...rest,
  };
}

const dialogStub = { props: { modelValue: Boolean }, emits: ['update:modelValue', 'hide'], template: '<div v-if="modelValue" class="dialog-stub"><slot /></div>' };

function mountSheet(entryId: number | null): VueWrapper {
  return mount(EntrySheet, {
    props: { entryId },
    global: { plugins: [Quasar, createI18n({ legacy: false, locale: 'en', missingWarn: false, fallbackWarn: false, messages: { en } })], stubs: { QDialog: dialogStub, RouterLink: true } },
  });
}

function button(wrapper: VueWrapper, text: string) {
  const found = wrapper.findAll('button').find((b) => b.element.querySelector(':scope > .q-btn__content > .block')?.textContent?.trim() === text);
  if (!found) throw new Error(`No button "${text}"`);
  return found;
}

beforeEach(() => {
  config(ALL_CAPS);
  Object.values(notify).forEach((fn) => fn.mockReset());
});
afterEach(() => {
  vi.useRealTimers();
  vi.restoreAllMocks();
  setConfigForTests(null);
});

describe('EntrySheet', () => {
  it('stays closed without an entry', () => {
    const get = vi.spyOn(entriesApi, 'get');
    const wrapper = mountSheet(null);
    expect(wrapper.find('.dialog-stub').exists()).toBe(false);
    expect(get).not.toHaveBeenCalled();
  });

  it('loads an unread entry, marks it as read and renders meta, log and fields', async () => {
    vi.spyOn(entriesApi, 'get').mockResolvedValue(detail({ status: 'unread' }));
    const update = vi.spyOn(entriesApi, 'update').mockResolvedValue({} as EntrySummary);
    const wrapper = mountSheet(7);
    expect(wrapper.find('.q-skeleton').exists()).toBe(true);
    await flushPromises();
    expect(update).toHaveBeenCalledWith(7, { status: 'read' });
    expect(wrapper.emitted('changed')).toHaveLength(1);
    const text = wrapper.text();
    expect(text).toContain('Entry #7');
    expect(text).toContain('Ada');
    expect(text).toContain('Read');
    expect(text).toContain(en.entries.guest);
    expect(text).toContain('v3');
    expect(wrapper.get('a[href="https://site.test/contact"]').attributes('target')).toBe('_blank');
    expect(text).toContain('E-mail · Mail sent');
    expect(text).toContain('custom · Skipped');
    expect(wrapper.findAll('.swf-entry-log--failed')).toHaveLength(1);
    expect(text).not.toContain(en.entries.noData);
  });

  it('shows the no-data hint, the user name and read-only mode without manage capability', async () => {
    config(['swf_view_entries']);
    vi.spyOn(entriesApi, 'get').mockResolvedValue(detail({ status: 'unread', meta: {} }, { fields: [], user: 'admin', log: [] }));
    const update = vi.spyOn(entriesApi, 'update');
    const wrapper = mountSheet(7);
    await flushPromises();
    expect(update).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain(en.entries.noData);
    expect(wrapper.text()).toContain('admin');
    expect(wrapper.text()).not.toContain(en.m3.editEntry);
    expect(wrapper.text()).not.toContain(en.entries.actions.trash);
  });

  it('closes and reports when loading fails', async () => {
    const error = new ApiError('swf_not_found', 'Gone', 404);
    vi.spyOn(entriesApi, 'get').mockRejectedValue(error);
    const wrapper = mountSheet(7);
    await flushPromises();
    expect(notify.error).toHaveBeenCalledWith(error);
    expect(wrapper.emitted('close')).toHaveLength(1);
  });

  it('stars, marks unread, spams and trashes from the footer', async () => {
    vi.spyOn(entriesApi, 'get').mockResolvedValue(detail());
    const update = vi.spyOn(entriesApi, 'update').mockImplementation((_id, changes) => Promise.resolve({ status: changes.status ?? 'read', starred: changes.starred ?? false } as EntrySummary));
    const wrapper = mountSheet(7);
    await flushPromises();

    await button(wrapper, en.entries.actions.star).trigger('click');
    await flushPromises();
    expect(update).toHaveBeenLastCalledWith(7, { starred: true });
    expect(button(wrapper, en.entries.actions.unstar).exists()).toBe(true);

    await button(wrapper, en.entries.actions.unread).trigger('click');
    await flushPromises();
    expect(update).toHaveBeenLastCalledWith(7, { status: 'unread' });
    expect(wrapper.emitted('close')).toBeUndefined();

    await button(wrapper, en.entries.actions.spam).trigger('click');
    await flushPromises();
    expect(notify.success).toHaveBeenLastCalledWith(en.entries.markedSpam);
    expect(wrapper.emitted('close')).toHaveLength(1);

    // Spam entries offer restore and permanent deletion.
    await button(wrapper, en.entries.actions.restore).trigger('click');
    await flushPromises();
    expect(update).toHaveBeenLastCalledWith(7, { status: 'read' });

    await button(wrapper, en.entries.actions.trash).trigger('click');
    await flushPromises();
    expect(notify.success).toHaveBeenLastCalledWith(en.entries.movedToTrash);
    expect(wrapper.emitted('changed')).toHaveLength(5);

    update.mockRejectedValueOnce(new Error('denied'));
    await button(wrapper, en.entries.actions.unread).trigger('click');
    await flushPromises();
    expect(notify.error).toHaveBeenCalledWith(new Error('denied'));
  });

  it('deletes a trashed entry permanently after confirmation', async () => {
    vi.spyOn(entriesApi, 'get').mockResolvedValue(detail({ status: 'trash' }));
    const remove = vi.spyOn(entriesApi, 'remove').mockRejectedValueOnce(new Error('locked')).mockResolvedValue({ deleted: true });
    const wrapper = mountSheet(7);
    await flushPromises();
    await button(wrapper, en.entries.actions.deleteForever).trigger('click');
    expect(wrapper.text()).toContain(en.entries.deleteTitle);
    const confirm = () => wrapper.findAll('.swf-cd button').find((b) => b.text().includes(en.entries.actions.deleteForever))!;
    await confirm().trigger('click');
    await flushPromises();
    expect(notify.error).toHaveBeenCalledWith(new Error('locked'));
    await confirm().trigger('click');
    await flushPromises();
    expect(remove).toHaveBeenCalledWith(7);
    expect(notify.success).toHaveBeenCalledWith(en.entries.deleted);
    expect(wrapper.emitted('close')).toHaveLength(1);
    expect(wrapper.find('.swf-cd').exists()).toBe(false);
  });

  it('closes via the sheet close button', async () => {
    vi.spyOn(entriesApi, 'get').mockResolvedValue(detail());
    const wrapper = mountSheet(7);
    await flushPromises();
    await wrapper.get(`button[aria-label="${en.common.close}"]`).trigger('click');
    expect(wrapper.emitted('close')).toHaveLength(1);
  });

  it('edits values, shows validation errors and saves', async () => {
    const get = vi.spyOn(entriesApi, 'get').mockResolvedValue(detail());
    vi.spyOn(entriesApi, 'editValues').mockRejectedValueOnce(new Error('no edit')).mockResolvedValue({ fields: [{ id: 'name', type: 'text', key: 'name', label: 'Name' }], values: { name: 'Ada' }, revision: 'r1' });
    const save = vi.spyOn(entriesApi, 'saveValues')
      .mockRejectedValueOnce(new ApiError('swf_invalid', 'Invalid', 422, {}, { errors: { name: 'Too short' } }))
      .mockResolvedValue({} as EntrySummary);
    const wrapper = mountSheet(7);
    await flushPromises();

    await button(wrapper, en.m3.editEntry).trigger('click');
    await flushPromises();
    expect(notify.error).toHaveBeenCalledWith(new Error('no edit'));

    await button(wrapper, en.m3.editEntry).trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain(en.m3.editHelp);
    await wrapper.get('.swf-entry-editor input').setValue('Grace');
    await button(wrapper, en.common.save).trigger('click');
    await flushPromises();
    expect(save).toHaveBeenCalledWith(7, { name: 'Grace' }, 'r1');
    expect(wrapper.get('[role="alert"]').text()).toContain('Too short');

    await button(wrapper, en.common.save).trigger('click');
    await flushPromises();
    expect(get).toHaveBeenCalledTimes(2);
    expect(notify.success).toHaveBeenCalledWith(en.m3.entrySaved);
    expect(wrapper.find('.swf-entry-editor').exists()).toBe(false);
    expect(wrapper.emitted('changed')).toHaveLength(1);

    await button(wrapper, en.m3.editEntry).trigger('click');
    await flushPromises();
    await button(wrapper, en.common.cancel).trigger('click');
    expect(wrapper.find('.swf-entry-editor').exists()).toBe(false);
  });

  it('adds an internal note and reports failures', async () => {
    const get = vi.spyOn(entriesApi, 'get').mockResolvedValue(detail());
    const addNote = vi.spyOn(entriesApi, 'addNote').mockResolvedValueOnce({ saved: true }).mockRejectedValueOnce(new Error('note failed'));
    const wrapper = mountSheet(7);
    await flushPromises();
    expect(button(wrapper, en.m3.addNote).attributes('disabled')).toBeDefined();
    await wrapper.get('textarea').setValue('Called back');
    await button(wrapper, en.m3.addNote).trigger('click');
    await flushPromises();
    expect(addNote).toHaveBeenCalledWith(7, 'Called back');
    expect(get).toHaveBeenCalledTimes(2);
    expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('');

    await wrapper.get('textarea').setValue('Again');
    await button(wrapper, en.m3.addNote).trigger('click');
    await flushPromises();
    expect(notify.error).toHaveBeenCalledWith(new Error('note failed'));
  });

  it('lists webhook deliveries and resends finished ones', async () => {
    const webhooks = [delivery(), delivery({ id: 6, webhookId: 'erp', status: 'failed', httpCode: 500, nextAttemptAt: '2026-01-03T10:00:00Z' })];
    const get = vi.spyOn(entriesApi, 'get').mockResolvedValue(detail({}, { webhooks }));
    const resend = vi.spyOn(entriesApi, 'resendWebhook').mockResolvedValueOnce({ queued: true }).mockRejectedValueOnce(new Error('busy'));
    const wrapper = mountSheet(7);
    await flushPromises();
    expect(wrapper.text()).toContain('crm · Delivered');
    expect(wrapper.text()).toContain('erp · Failed');
    expect(wrapper.text()).toContain('HTTP 500');
    expect(wrapper.text()).toContain('Next attempt:');
    const resendButtons = wrapper.findAll('button').filter((b) => b.text().includes(en.webhooks.resend));
    expect(resendButtons).toHaveLength(2);
    await resendButtons[1]!.trigger('click');
    await flushPromises();
    expect(resend).toHaveBeenCalledWith(7, 6);
    expect(get).toHaveBeenCalledTimes(2);
    expect(notify.success).toHaveBeenCalledWith(en.webhooks.queued);
    expect(wrapper.emitted('changed')).toHaveLength(1);

    await wrapper.findAll('button').filter((b) => b.text().includes(en.webhooks.resend))[0]!.trigger('click');
    await flushPromises();
    expect(notify.error).toHaveBeenCalledWith(new Error('busy'));
  });

  it('hides resend without the manage forms capability', async () => {
    config(['swf_manage_entries']);
    vi.spyOn(entriesApi, 'get').mockResolvedValue(detail({}, { webhooks: [delivery()] }));
    const wrapper = mountSheet(7);
    await flushPromises();
    expect(wrapper.text()).toContain('crm · Delivered');
    expect(wrapper.text()).not.toContain(en.webhooks.resend);
  });

  it('polls pending deliveries until they settle and retries after errors', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    const pending = detail({}, { webhooks: [delivery({ status: 'pending' })] });
    const get = vi.spyOn(entriesApi, 'get')
      .mockResolvedValueOnce(pending)
      .mockRejectedValueOnce(new Error('offline'))
      .mockResolvedValueOnce(detail({}, { webhooks: [delivery({ status: 'running' })] }))
      .mockResolvedValue(detail({}, { webhooks: [delivery({ status: 'success' })] }));
    const wrapper = mountSheet(7);
    await flushPromises();
    expect(wrapper.text()).toContain('crm · Queued');

    await vi.advanceTimersByTimeAsync(3000);
    expect(get).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(3000);
    expect(get).toHaveBeenCalledTimes(3);
    expect(wrapper.text()).toContain('crm · Sending');
    expect(wrapper.emitted('changed')).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(3000);
    expect(get).toHaveBeenCalledTimes(4);
    expect(wrapper.text()).toContain('crm · Delivered');
    expect(wrapper.emitted('changed')).toHaveLength(2);
    await vi.advanceTimersByTimeAsync(9000);
    expect(get).toHaveBeenCalledTimes(4);
    wrapper.unmount();
  });

  it('stops polling when the sheet switches entries or unmounts', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    const get = vi.spyOn(entriesApi, 'get').mockResolvedValue(detail({}, { webhooks: [delivery({ status: 'running' })] }));
    const wrapper = mountSheet(7);
    await flushPromises();
    await wrapper.setProps({ entryId: null });
    expect(wrapper.find('.dialog-stub').exists()).toBe(false);
    await vi.advanceTimersByTimeAsync(3000);
    expect(get).toHaveBeenCalledTimes(1);

    await wrapper.setProps({ entryId: 8 });
    await flushPromises();
    expect(get).toHaveBeenLastCalledWith(8);
    wrapper.unmount();
    await vi.advanceTimersByTimeAsync(3000);
    expect(get).toHaveBeenCalledTimes(2);
  });
});
