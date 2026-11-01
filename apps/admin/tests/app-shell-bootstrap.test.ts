import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { defineComponent, getCurrentInstance, h } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { SwfConfig } from '@/types/config';

const initializeBuilderAPI = vi.hoisted(() => vi.fn());
vi.mock('@/extensions/builder', () => ({ initializeBuilderAPI }));

// The router resolves the lazy view of the initial route; keep the real views out.
vi.mock('@/features/forms/FormsView.vue', () => ({ default: { render: () => null } }));
vi.mock('@/features/builder/BuilderView.vue', () => ({ default: { render: () => null } }));
vi.mock('@/features/settings/SettingsView.vue', () => ({ default: { render: () => null } }));

/** Records what the real bootstrap installed on the app. */
const seen = vi.hoisted(() => ({ locale: '', hasRouter: false, hasPinia: false, quasarLang: '', closeLabel: '' }));
vi.mock('@/App.vue', () => ({
  default: defineComponent({
    name: 'AppMock',
    setup() {
      const { locale, t } = useI18n();
      const router = useRouter();
      const instance = getCurrentInstance()!;
      const $q = instance.proxy!.$q;
      seen.locale = locale.value;
      seen.hasRouter = typeof router.push === 'function';
      seen.hasPinia = !!instance.appContext.config.globalProperties.$pinia;
      seen.quasarLang = $q.lang.isoName;
      seen.closeLabel = t('common.close');
      return () => h('div', { class: 'app-mock' }, 'mounted');
    },
  }),
}));

const config = (locale: string): SwfConfig => ({
  restRoot: 'http://x/wp-json/swforms/v1/',
  wpRestRoot: 'http://x/wp-json/wp/v2/',
  nonce: 'n',
  locale,
  version: '1',
  adminUrl: '',
  capabilities: ['swf_manage_forms'],
  siteUrl: '',
});

function mountNode(): HTMLElement {
  const el = document.createElement('div');
  el.id = 'sw-forms-app';
  el.innerHTML = '<p>Loading…</p>';
  document.body.append(el);
  return el;
}

beforeEach(() => {
  vi.resetModules();
  initializeBuilderAPI.mockClear();
});
afterEach(() => {
  delete window.swFormsConfig;
  document.body.innerHTML = '';
  document.body.className = '';
  vi.restoreAllMocks();
});

describe('bootstrap', () => {
  it('mounts the app with pinia, router, i18n and Quasar', async () => {
    window.swFormsConfig = config('de_DE');
    const el = mountNode();
    const { bootstrap } = await import('@/bootstrap');
    vi.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
    bootstrap();
    await flushPromises();
    expect(el.classList.contains('swf-root')).toBe(true);
    expect(el.classList.contains('swf-fullbleed')).toBe(true);
    expect(document.body.classList.contains('swf-admin-page')).toBe(true);
    expect(initializeBuilderAPI).toHaveBeenCalledTimes(1);
    expect(el.querySelector('.app-mock')?.textContent).toBe('mounted');
    expect(seen).toMatchObject({ locale: 'de', hasRouter: true, hasPinia: true, quasarLang: 'de', closeLabel: 'Schließen' });
  });

  it('reports a missing mount node', async () => {
    window.swFormsConfig = config('en_US');
    const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const { bootstrap } = await import('@/bootstrap');
    bootstrap();
    expect(error).toHaveBeenCalledWith('SW Forms: mount node #sw-forms-app not found');
    expect(initializeBuilderAPI).not.toHaveBeenCalled();
  });

  it('shows a fatal message when the config is missing', async () => {
    const el = mountNode();
    const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    const { bootstrap } = await import('@/bootstrap');
    bootstrap();
    expect(error).toHaveBeenCalledWith(expect.any(Error));
    const alert = el.querySelector('[role="alert"]');
    expect(alert?.textContent).toBe('SW Forms: configuration missing (window.swFormsConfig).');
    expect(el.children).toHaveLength(1);
    expect(el.classList.contains('swf-root')).toBe(true);
    expect(el.classList.contains('swf-fullbleed')).toBe(false);
    expect(initializeBuilderAPI).not.toHaveBeenCalled();
  });
});

describe('main entry', () => {
  it('bootstraps on import', async () => {
    const bootstrap = vi.fn();
    vi.doMock('@/bootstrap', () => ({ bootstrap }));
    await import('@/main');
    expect(bootstrap).toHaveBeenCalledTimes(1);
    vi.doUnmock('@/bootstrap');
  });
});

describe('App', () => {
  it('renders the shell and syncs the theme onto the mount node', async () => {
    vi.doUnmock('@/App.vue');
    vi.doMock('@/layouts/ShellLayout.vue', () => ({ default: { name: 'ShellMock', render: () => h('div', { class: 'shell-mock' }) } }));
    const { default: App } = await import('@/App.vue');
    const { useAppStore } = await import('@/stores/app');
    setActivePinia(createPinia());
    useAppStore().setTheme('dark');
    const el = mountNode();
    const wrapper = mount(App, { attachTo: el });
    await flushPromises();
    expect(wrapper.find('.shell-mock').exists()).toBe(true);
    expect(el.classList.contains('swf-dark')).toBe(true);
    wrapper.unmount();
    vi.doUnmock('@/layouts/ShellLayout.vue');
    window.localStorage.clear();
  });
});
