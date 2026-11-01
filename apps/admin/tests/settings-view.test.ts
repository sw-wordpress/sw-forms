import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { Quasar } from 'quasar';
import { createI18n } from 'vue-i18n';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { settingsApi } from '@/api';
import { ApiError } from '@/api/client';
import { setConfigForTests } from '@/config';
import SettingsView from '@/features/settings/SettingsView.vue';
import en from '@/i18n/en';
import type { GlobalSettings } from '@/types/api';

const notify = { success: vi.fn(), info: vi.fn(), error: vi.fn() };
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notify }));

function settings(over: Partial<GlobalSettings> = {}): GlobalSettings {
  return { ip_mode: 'hash', from_email: 'a@example.com', from_name: 'Site', delete_data_on_uninstall: false, load_css: true, ...over };
}

function mountView(): VueWrapper {
  return mount(SettingsView, { global: { plugins: [Quasar, createI18n({ legacy: false, locale: 'en', messages: { en } })], stubs: { RouterLink: true } } });
}

beforeEach(() => {
  setConfigForTests({ restRoot: 'http://x/wp-json/swforms/v1/', wpRestRoot: 'http://x/wp-json/wp/v2/', nonce: 'n', locale: 'en_US', version: '', adminUrl: '', capabilities: ['swf_settings'], siteUrl: '', galleryUrl: '' });
  Object.values(notify).forEach((fn) => fn.mockReset());
});
afterEach(() => {
  vi.restoreAllMocks();
  setConfigForTests(null);
});

describe('SettingsView', () => {
  it('edits and saves the global settings', async () => {
    vi.spyOn(settingsApi, 'get').mockResolvedValue(settings());
    const save = vi.spyOn(settingsApi, 'save').mockImplementation((value) => Promise.resolve({ ...value, from_name: 'Saved' }));
    const wrapper = mountView();
    expect(wrapper.find('.q-skeleton').exists()).toBe(true);
    await flushPromises();
    expect(wrapper.text()).not.toContain(en.m3.captcha);

    await wrapper.findAll('.q-radio')[1]!.trigger('click');
    await wrapper.get('input[type="email"]').setValue('b@example.com');
    const toggles = wrapper.findAll('.q-toggle');
    await toggles[0]!.trigger('click');
    await toggles[1]!.trigger('click');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(save).toHaveBeenCalledWith({ ip_mode: 'none', from_email: 'b@example.com', from_name: 'Site', delete_data_on_uninstall: true, load_css: false });
    expect(notify.success).toHaveBeenCalledWith(en.settings.saved);
    expect((wrapper.findAll('input').find((i) => (i.element as HTMLInputElement).value === 'Saved'))).toBeDefined();
  });

  it('edits captcha keys when providers are available', async () => {
    vi.spyOn(settingsApi, 'get').mockResolvedValue(settings({ captcha: { turnstile: { siteKey: 't', hasSecret: true }, hcaptcha: { siteKey: '' } } }));
    const save = vi.spyOn(settingsApi, 'save').mockImplementation((value) => Promise.resolve(value));
    const wrapper = mountView();
    await flushPromises();
    expect(wrapper.text()).toContain('Cloudflare Turnstile');
    expect(wrapper.text()).toContain('hCaptcha');
    const secrets = wrapper.findAll('input[type="password"]');
    expect(secrets).toHaveLength(2);
    await secrets[1]!.setValue('s3cret');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(save.mock.calls[0]![0].captcha?.hcaptcha.secret).toBe('s3cret');
  });

  it('reports a failed save', async () => {
    vi.spyOn(settingsApi, 'get').mockResolvedValue(settings());
    const error = new ApiError('swf_invalid', 'Invalid email', 400);
    vi.spyOn(settingsApi, 'save').mockRejectedValue(error);
    const wrapper = mountView();
    await flushPromises();
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(notify.error).toHaveBeenCalledWith(error);
    expect(notify.success).not.toHaveBeenCalled();
  });

  it('shows a load error and retries', async () => {
    const get = vi.spyOn(settingsApi, 'get').mockRejectedValueOnce(new Error('down')).mockResolvedValue(settings());
    const wrapper = mountView();
    await flushPromises();
    expect(wrapper.text()).toContain(en.errors.loadFailed);
    expect(wrapper.find('form').exists()).toBe(false);
    await wrapper.get('button').trigger('click');
    await flushPromises();
    expect(get).toHaveBeenCalledTimes(2);
    expect(wrapper.find('form').exists()).toBe(true);
  });
});
