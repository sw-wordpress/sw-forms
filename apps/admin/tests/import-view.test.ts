import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { Quasar } from 'quasar';
import { createI18n } from 'vue-i18n';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { importApi, setApiForTests } from '@/api';
import { ApiClient, ApiError } from '@/api/client';
import { setConfigForTests } from '@/config';
import ImportView from '@/features/import/ImportView.vue';
import en from '@/i18n/en';
import type { ImportAdapter, ImportReport } from '@/types/api';

const notify = { success: vi.fn() };
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }));
const adapters: ImportAdapter[] = [
  { slug: 'contact-form-7', name: 'Contact Form 7', available: true, imports: ['basicFields', 'Extension feature'], omits: ['notifications'] },
  { slug: 'wpforms', name: 'WPForms', available: false, imports: ['required'], omits: ['entries'] },
];
const report = (): ImportReport => ({ title: 'Contact', document: { schemaVersion: 1, fields: [], settings: { submitLabel: 'Send', cssClass: '', confirmation: { type: 'message', message: '', url: '', pageId: 0 }, storeEntries: true, retentionDays: 0, spam: { honeypot: true, minSeconds: 2, rateLimit: 10 }, notifications: [] } }, imported: ['Email → field_1'], skipped: ['Payment'], warnings: ['notifications', 'advanced'], fingerprint: 'abc' });
let wrappers: VueWrapper[] = [];

function mountView(allowed = true): VueWrapper {
  setConfigForTests({ restRoot: 'http://x/', wpRestRoot: 'http://x/wp/v2/', nonce: 'n', locale: 'en_US', version: '', adminUrl: '', capabilities: allowed ? ['swf_manage_forms'] : ['swf_view_entries'], siteUrl: '', galleryUrl: '' });
  const wrapper = mount(ImportView, { global: { plugins: [Quasar, createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: { RouterLink: true } } });
  wrappers.push(wrapper);
  return wrapper;
}

beforeEach(() => {
  vi.spyOn(importApi, 'catalog').mockResolvedValue(structuredClone(adapters));
  vi.spyOn(importApi, 'forms').mockResolvedValue([{ id: 1, title: 'Source' }]);
  vi.spyOn(importApi, 'preview').mockResolvedValue(report());
  vi.spyOn(importApi, 'import').mockResolvedValue({ id: 42, report: report() });
});
afterEach(() => {
  wrappers.forEach((w) => w.unmount()); wrappers = [];
  vi.restoreAllMocks();
  setApiForTests(null);
  setConfigForTests(null);
});

async function choose(wrapper: VueWrapper): Promise<void> {
  await flushPromises();
  await wrapper.get('[data-adapter="contact-form-7"]').trigger('click');
  await flushPromises();
}
async function preview(wrapper: VueWrapper): Promise<void> {
  await choose(wrapper);
  await wrapper.get('[data-source="1"]').trigger('click');
  await flushPromises();
}

describe('plugin import workflow', () => {
  it('requires form management without querying import sources', async () => {
    const wrapper = mountView(false);
    await flushPromises();
    expect(wrapper.text()).toContain(en.errors.noAccessTitle);
    expect(importApi.catalog).not.toHaveBeenCalled();
  });

  it('shows capabilities and creates only after an explicit report confirmation', async () => {
    const wrapper = mountView();
    expect(wrapper.find('.q-skeleton').exists()).toBe(true);
    await choose(wrapper);
    expect(wrapper.text()).toContain('Extension feature');
    expect(wrapper.text()).toContain(en.importer.unavailable);
    expect(wrapper.find('[data-adapter="wpforms"]').exists()).toBe(false);
    expect(importApi.preview).not.toHaveBeenCalled();
    await wrapper.get('[data-source="1"]').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Email → field_1');
    expect(wrapper.text()).toContain('Payment');
    expect(wrapper.text()).toContain(en.importer.features.advanced);
    expect(importApi.import).not.toHaveBeenCalled();
    await wrapper.get('[data-confirm-import]').trigger('click');
    await flushPromises();
    expect(importApi.import).toHaveBeenCalledExactlyOnceWith('contact-form-7', 1, 'abc');
    expect(wrapper.text()).toContain(en.importer.saved);
    expect(wrapper.find('[data-confirm-import]').exists()).toBe(false);
  });

  it('retries a failed catalog request and handles no registered importers', async () => {
    vi.mocked(importApi.catalog).mockRejectedValueOnce(new Error('Offline')).mockResolvedValue([]);
    const wrapper = mountView();
    await flushPromises();
    expect(wrapper.text()).toContain('Offline');
    await wrapper.get('button').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain(en.importer.noAdapters);
  });

  it('retries source loading and paginates without retaining an old report', async () => {
    vi.mocked(importApi.forms).mockRejectedValueOnce(new Error('Cannot list'));
    const wrapper = mountView();
    await choose(wrapper);
    expect(wrapper.text()).toContain('Cannot list');
    expect(wrapper.text()).toContain(en.importer.empty);
    vi.mocked(importApi.forms).mockResolvedValueOnce(Array.from({ length: 50 }, (_, i) => ({ id: i + 1, title: 'Form ' + i })));
    await wrapper.findAll('button').find((b) => b.text() === en.common.retry)!.trigger('click');
    await flushPromises();
    await wrapper.findAll('button').find((b) => b.text() === en.importer.next)!.trigger('click');
    await flushPromises();
    expect(importApi.forms).toHaveBeenLastCalledWith('contact-form-7', 2);
    await wrapper.findAll('button').find((b) => b.text() === en.importer.previous)!.trigger('click');
    await flushPromises();
    expect(importApi.forms).toHaveBeenLastCalledWith('contact-form-7', 1);
  });

  it('shows skipped fields for a rejected form without offering confirmation', async () => {
    vi.mocked(importApi.preview).mockRejectedValue(new ApiError('invalid', 'No fields', 422, {}, { report: { ...report(), fingerprint: undefined } }));
    const wrapper = mountView();
    await preview(wrapper);
    expect(wrapper.text()).toContain('No fields');
    expect(wrapper.text()).toContain('Payment');
    expect(wrapper.find('[data-confirm-import]').exists()).toBe(false);
  });

  it('requires a fresh report after source changes and preserves retry on ordinary failures', async () => {
    const wrapper = mountView();
    await preview(wrapper);
    vi.mocked(importApi.import).mockRejectedValueOnce(new Error('Save failed'));
    await wrapper.get('[data-confirm-import]').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Save failed');
    vi.mocked(importApi.import).mockRejectedValueOnce(new ApiError('changed', 'Review again', 409));
    await wrapper.get('[data-confirm-import]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-import-report]').exists()).toBe(false);
    await wrapper.get('[data-source="1"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-confirm-import]').exists()).toBe(true);
  });

  it('handles preview failure and reports an empty skipped list', async () => {
    vi.mocked(importApi.preview).mockRejectedValueOnce(new Error('Read failed')).mockResolvedValueOnce({ ...report(), skipped: [] });
    const wrapper = mountView();
    await preview(wrapper);
    expect(wrapper.text()).toContain('Read failed');
    await wrapper.get('[data-source="1"]').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain(en.importer.none);
  });
});

it('uses encoded authenticated API routes and passes only the confirmation fingerprint', async () => {
  vi.restoreAllMocks();
  const client = new ApiClient({ restRoot: 'http://x/', nonce: 'n' });
  const get = vi.spyOn(client, 'get').mockResolvedValue({ items: [] });
  const post = vi.spyOn(client, 'post').mockResolvedValue({ id: 1 });
  setApiForTests(client);
  await importApi.catalog();
  await importApi.forms('test source', 2);
  await importApi.preview('test source', 3);
  await importApi.import('test source', 3, 'hash');
  expect(get.mock.calls).toEqual([['importers'], ['importers/test%20source/forms', { page: 2 }], ['importers/test%20source/forms/3']]);
  expect(post).toHaveBeenCalledWith('importers/test%20source/forms/3', { fingerprint: 'hash' });
});
