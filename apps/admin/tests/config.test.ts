import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { SwfConfig } from '@/types/config';

const baseConfig: SwfConfig = {
  restRoot: 'http://x/wp-json/swforms/v1/',
  wpRestRoot: 'http://x/wp-json/wp/v2/',
  nonce: 'n',
  locale: 'en_US',
  version: '1.0.0',
  adminUrl: '',
  capabilities: [],
  siteUrl: '',
  galleryUrl: '',
};

describe('config', () => {
  beforeEach(() => {
    vi.resetModules();
  });
  afterEach(() => {
    delete window.swFormsConfig;
  });

  it('normalises the PHP config with defaults and trailing slashes', async () => {
    const { normaliseConfig } = await import('@/config');
    expect(normaliseConfig({ restRoot: 'http://x/wp-json/swforms/v1' })).toEqual({
      restRoot: 'http://x/wp-json/swforms/v1/',
      wpRestRoot: 'http://x/wp-json/wp/v2/',
      nonce: '',
      locale: 'en_US',
      version: '',
      adminUrl: '',
      capabilities: [],
      siteUrl: '',
      galleryUrl: '',
    });
    const full = normaliseConfig({
      restRoot: 'http://x/?rest_route=/swforms/v1/',
      wpRestRoot: 'http://x/?rest_route=/wp/v2',
      nonce: 'abc',
      locale: 'de_DE',
      version: '2.0',
      adminUrl: 'http://x/wp-admin/',
      capabilities: ['swf_settings', 5 as unknown as string, 'swf_manage_forms'],
      siteUrl: 'http://x',
    });
    expect(full.wpRestRoot).toBe('http://x/?rest_route=/wp/v2/');
    expect(full.capabilities).toEqual(['swf_settings', 'swf_manage_forms']);
    expect(full.nonce).toBe('abc');
    expect(full.locale).toBe('de_DE');
  });

  it('rejects a missing REST root', async () => {
    const { normaliseConfig } = await import('@/config');
    expect(() => normaliseConfig(undefined)).toThrow('restRoot is missing');
    expect(() => normaliseConfig({ restRoot: '' })).toThrow();
    expect(() => normaliseConfig({ nonce: 'x' })).toThrow();
  });

  it('caches window.swFormsConfig and allows overriding it in tests', async () => {
    const { getConfig, setConfigForTests } = await import('@/config');
    expect(() => getConfig()).toThrow();
    window.swFormsConfig = { ...baseConfig, version: '3' };
    const first = getConfig();
    expect(first.version).toBe('3');
    window.swFormsConfig = { ...baseConfig, version: '4' };
    expect(getConfig()).toBe(first);
    setConfigForTests({ ...baseConfig, version: '5' });
    expect(getConfig().version).toBe('5');
    setConfigForTests(null);
    expect(getConfig().version).toBe('4');
  });

  it('maps WordPress locales to UI locales', async () => {
    const { resolveUiLocale, UI_LOCALES } = await import('@/config');
    expect(UI_LOCALES).toEqual(['en', 'de']);
    expect(resolveUiLocale('de_DE')).toBe('de');
    expect(resolveUiLocale('de_DE_formal')).toBe('de');
    expect(resolveUiLocale('DE_at')).toBe('de');
    expect(resolveUiLocale('en_GB')).toBe('en');
    expect(resolveUiLocale('fr_FR')).toBe('en');
  });
});

describe('i18n setup', () => {
  it('creates a composition i18n instance for the WordPress locale', async () => {
    const { createAppI18n } = await import('@/i18n');
    const de = createAppI18n('de_DE');
    expect(de.mode).toBe('composition');
    expect(de.global.locale.value).toBe('de');
    expect(de.global.t('common.close')).not.toBe('common.close');
    const en = createAppI18n('fr_FR');
    expect(en.global.locale.value).toBe('en');
    expect(en.global.t('common.close')).toBe('Close');
  });

  it('selects the Quasar language pack', async () => {
    const { default: quasarLang } = await import('@/i18n/quasarLang');
    expect(quasarLang('en').isoName).toBe('en-US');
    expect(quasarLang('de').isoName).toBe('de');
  });
});

describe('ids', () => {
  it('creates unique prefixed DOM ids', async () => {
    const { uid } = await import('@/utils/ids');
    const a = uid();
    const b = uid();
    expect(a).toMatch(/^swf-\d+$/);
    expect(b).not.toBe(a);
    expect(uid('sheet')).toMatch(/^sheet-\d+$/);
  });
});

describe('api endpoints', () => {
  const json = (body: unknown) => new Response(JSON.stringify(body), { headers: { 'Content-Type': 'application/json' } });
  let fetcher: ReturnType<typeof vi.fn<typeof fetch>>;

  beforeEach(async () => {
    vi.resetModules();
    const { setConfigForTests } = await import('@/config');
    setConfigForTests({ ...baseConfig });
    fetcher = vi.fn<typeof fetch>().mockImplementation(async () => json({ items: [{ id: 1 }] }));
    vi.stubGlobal('fetch', fetcher);
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  const call = (index: number) => {
    const [url, init] = fetcher.mock.calls[index] ?? [];
    return { url: String(url), method: init?.method ?? 'GET', body: init?.body ? JSON.parse(String(init.body)) as unknown : undefined };
  };

  it('lazily creates one client from the config and can be replaced', async () => {
    const { api, setApiForTests } = await import('@/api');
    const { ApiClient } = await import('@/api/client');
    const first = api();
    expect(first).toBeInstanceOf(ApiClient);
    expect(api()).toBe(first);
    const custom = new ApiClient({ restRoot: 'http://y/', nonce: 'z' });
    setApiForTests(custom);
    expect(api()).toBe(custom);
    setApiForTests(null);
    expect(api()).not.toBe(custom);
  });

  it('maps form endpoints to REST routes', async () => {
    const { formsApi } = await import('@/api');
    const document = { schemaVersion: 1, fields: [] } as never;
    expect(await formsApi.templates()).toEqual({ categories: [], items: [{ id: 1 }] });
    await formsApi.template('contact');
    await formsApi.import({ a: 1 });
    await formsApi.export(3);
    expect(await formsApi.versions(3, 2)).toEqual([{ id: 1 }]);
    await formsApi.version(3, 4);
    await formsApi.restoreVersion(3, 4);
    expect(await formsApi.list()).toEqual([{ id: 1 }]);
    await formsApi.create('New');
    await formsApi.get(3);
    await formsApi.save(3, 'T', document);
    await formsApi.saveDraft(3, document);
    await formsApi.discardDraft(3);
    await formsApi.setStatus(3, 'inactive');
    await formsApi.duplicate(3);
    await formsApi.remove(3);
    expect(await formsApi.fieldTypes()).toEqual([{ id: 1 }]);
    await formsApi.testWebhook(3, 'hook');

    const root = baseConfig.restRoot;
    expect(fetcher.mock.calls.map((_, i) => `${call(i).method} ${call(i).url.replace(root, '')}`)).toEqual([
      'GET form-templates',
      'GET form-templates/contact',
      'POST forms/import',
      'GET forms/3/export',
      'GET forms/3/versions?page=2',
      'GET forms/3/versions/4',
      'POST forms/3/versions/4/restore',
      'GET forms',
      'POST forms',
      'GET forms/3',
      'PUT forms/3',
      'PUT forms/3/draft',
      'DELETE forms/3/draft',
      'PATCH forms/3',
      'POST forms/3/duplicate',
      'DELETE forms/3',
      'GET field-types',
      'POST forms/3/webhooks/hook/test',
    ]);
    expect(call(2).body).toEqual({ a: 1 });
    expect(call(8).body).toEqual({ title: 'New' });
    expect(call(10).body).toEqual({ title: 'T', document });
    expect(call(11).body).toEqual({ document });
    expect(call(13).body).toEqual({ status: 'inactive' });
  });

  it('maps entry and settings endpoints to REST routes', async () => {
    const { entriesApi, settingsApi } = await import('@/api');
    await entriesApi.editValues(5);
    await entriesApi.saveValues(5, { name: 'x' }, 'rev');
    await entriesApi.addNote(5, 'hi');
    await entriesApi.list(2, { filter: 'inbox', search: 'q', page: 1, per_page: 20, order: 'desc' });
    await entriesApi.get(5);
    await entriesApi.update(5, { starred: true });
    await entriesApi.remove(5);
    await entriesApi.bulk([1, 2], 'read' as never);
    await entriesApi.resendWebhook(5, 9);
    await settingsApi.get();
    await settingsApi.save({ a: 1 } as never);

    const root = baseConfig.restRoot;
    expect(fetcher.mock.calls.map((_, i) => `${call(i).method} ${call(i).url.replace(root, '')}`)).toEqual([
      'GET entries/5/values',
      'PUT entries/5/values',
      'POST entries/5/notes',
      'GET forms/2/entries?filter=inbox&search=q&page=1&per_page=20&order=desc',
      'GET entries/5',
      'PATCH entries/5',
      'DELETE entries/5',
      'POST entries/bulk',
      'POST entries/5/webhooks/9/resend',
      'GET settings',
      'PUT settings',
    ]);
    expect(call(1).body).toEqual({ values: { name: 'x' }, revision: 'rev' });
    expect(call(2).body).toEqual({ note: 'hi' });
    expect(call(5).body).toEqual({ starred: true });
    expect(call(7).body).toEqual({ ids: [1, 2], action: 'read' });
    expect(call(10).body).toEqual({ a: 1 });

    const url = entriesApi.exportUrl(2, { filter: 'unread', search: '', order: 'asc' });
    expect(url).toBe(`${root}forms/2/entries/export?filter=unread&order=asc&_wpnonce=n`);
  });

  it('queries WordPress pages through the core REST root', async () => {
    const { pagesApi } = await import('@/api');
    await pagesApi.search('About');
    await pagesApi.get(12);
    expect(call(0).url).toBe('http://x/wp-json/wp/v2/pages?search=About&per_page=20&_fields=id%2Ctitle%2Clink&status=publish');
    expect(call(1).url).toBe('http://x/wp-json/wp/v2/pages/12?_fields=id%2Ctitle%2Clink');
  });
});
