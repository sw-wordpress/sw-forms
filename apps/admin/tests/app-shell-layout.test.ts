import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { Dialog, Notify, Quasar, QDialog } from 'quasar';
import { defineComponent, h } from 'vue';
import type { Plugin } from 'vue';
import { createI18n } from 'vue-i18n';
import { createMemoryHistory, createRouter } from 'vue-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SideSheet from '@/components/ui/SideSheet.vue';
import { setConfigForTests } from '@/config';
import NoAccess from '@/layouts/NoAccess.vue';
import ShellLayout from '@/layouts/ShellLayout.vue';
import { BUILDER_TABS, NAV_ITEMS, createAppRouter } from '@/router';
import { useAppStore } from '@/stores/app';
import type { Capability } from '@/types/config';
import en from '@/i18n/en';

vi.mock('@/features/forms/FormsView.vue', () => ({ default: { name: 'FormsViewMock', render: () => null } }));
vi.mock('@/features/builder/BuilderView.vue', () => ({ default: { name: 'BuilderViewMock', render: () => null } }));
vi.mock('@/features/settings/SettingsView.vue', () => ({ default: { name: 'SettingsViewMock', render: () => null } }));

const ALL_CAPS: Capability[] = ['swf_manage_forms', 'swf_view_entries', 'swf_manage_entries', 'swf_settings'];

function useConfig(capabilities: string[], version = '1.2.3') {
  setConfigForTests({ restRoot: 'http://x/', wpRestRoot: 'http://x/wp/v2/', nonce: 'n', locale: 'en_US', version, adminUrl: '', capabilities, siteUrl: '' });
}

const plugins = (): Array<Plugin | [Plugin, ...unknown[]]> => [
  [Quasar, { plugins: { Notify, Dialog } }],
  createI18n({ legacy: false, locale: 'en', messages: { en } }),
];

function view(name: string) {
  return defineComponent({ name, setup: () => () => h('section', { class: 'view' }, name) });
}

function testRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'forms', component: view('Forms') },
      { path: '/forms/:id', name: 'builder', component: view('Builder'), meta: { fullWidth: true } },
      { path: '/settings', name: 'settings', component: view('Settings') },
    ],
  });
}

async function mountShell(path = '/') {
  const router = testRouter();
  await router.push(path);
  await router.isReady();
  const wrapper = mount(ShellLayout, { global: { plugins: [...plugins(), router] }, attachTo: document.body });
  await flushPromises();
  return { wrapper, router };
}

beforeEach(() => {
  setActivePinia(createPinia());
  window.localStorage.clear();
});
afterEach(() => {
  setConfigForTests(null);
  document.body.innerHTML = '';
});

describe('router', () => {
  it('declares navigation and builder tabs', () => {
    expect(NAV_ITEMS.map((i) => i.name)).toEqual(['forms', 'settings']);
    expect(NAV_ITEMS.find((i) => i.name === 'settings')?.cap).toBe('swf_settings');
    expect(BUILDER_TABS).toContain('entries');
  });

  it('resolves builder routes, redirects unknown paths and scrolls to the top', async () => {
    const router = createAppRouter();
    const builder = router.resolve('/forms/12/entries/34');
    expect(builder.name).toBe('builder');
    expect(builder.params).toMatchObject({ id: '12', tab: 'entries', entryId: '34' });
    expect(builder.meta.fullWidth).toBe(true);
    expect(router.resolve('/forms/12').name).toBe('builder');
    expect(router.resolve('/forms/abc').name).toBeUndefined();
    expect(router.resolve('/settings').name).toBe('settings');

    const scroll = router.options.scrollBehavior!;
    const route = router.resolve('/') as unknown as Parameters<typeof scroll>[0];
    expect(scroll(route, route, null)).toEqual({ top: 0 });

    const scrollTo = vi.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
    await router.push('/does/not/exist');
    await flushPromises();
    expect(scrollTo).toHaveBeenCalled();
    scrollTo.mockRestore();
    expect(router.currentRoute.value.fullPath).toBe('/');

    for (const record of router.getRoutes()) {
      const loader = record.components?.default as unknown;
      if (typeof loader === 'function') {
        const mod = await (loader as () => Promise<{ default: { name: string } }>)();
        expect(mod.default.name).toMatch(/ViewMock$/);
      }
    }
  });
});

describe('ShellLayout', () => {
  it('renders navigation, version and the routed view', async () => {
    useConfig(ALL_CAPS);
    const { wrapper } = await mountShell('/');
    expect(wrapper.text()).toContain('SW Forms');
    expect(wrapper.find('.swf-num').text()).toBe('v1.2.3');
    const links = wrapper.findAll('.swf-nav-link__label');
    expect(links.map((l) => l.text())).toEqual(['Forms', 'Settings']);
    expect(wrapper.find('.view').text()).toBe('Forms');
    expect(wrapper.find('main').classes()).not.toContain('swf-main--full');
    wrapper.unmount();
  });

  it('hides nav items without capability and the version when empty', async () => {
    useConfig(['swf_view_entries'], '');
    const { wrapper } = await mountShell('/');
    expect(wrapper.find('.swf-num').exists()).toBe(false);
    expect(wrapper.findAll('.swf-nav-link__label').map((l) => l.text())).toEqual(['Forms']);
    wrapper.unmount();
  });

  it('shows NoAccess without form or entry capability', async () => {
    useConfig(['swf_settings']);
    const { wrapper } = await mountShell('/');
    expect(wrapper.find('nav').exists()).toBe(false);
    expect(wrapper.find('.view').exists()).toBe(false);
    expect(wrapper.text()).toContain(en.errors.noAccessTitle);
    expect(wrapper.text()).toContain(en.errors.noAccessText);
    wrapper.unmount();
  });

  it('uses the full width layout for the builder', async () => {
    useConfig(ALL_CAPS);
    const { wrapper, router } = await mountShell('/forms/5');
    expect(wrapper.find('.swf-shell').classes()).toContain('swf-shell--editor');
    expect(wrapper.find('main').classes()).toContain('swf-main--full');
    expect(wrapper.find('.view').text()).toBe('Builder');
    await router.push('/settings');
    await flushPromises();
    expect(wrapper.find('.view').text()).toBe('Settings');
    expect(wrapper.find('.swf-shell').classes()).not.toContain('swf-shell--editor');
    wrapper.unmount();
  });

  it('toggles theme and rail, focuses main and announces', async () => {
    useConfig(ALL_CAPS);
    const { wrapper } = await mountShell('/');
    const app = useAppStore();
    app.setTheme('light');
    await flushPromises();

    const themeButton = wrapper.find('button[aria-label="Switch to dark theme"]');
    expect(themeButton.attributes('aria-pressed')).toBe('false');
    await themeButton.trigger('click');
    expect(app.isDark).toBe(true);
    expect(wrapper.find('button[aria-label="Switch to light theme"]').exists()).toBe(true);

    const collapse = wrapper.find('button.swf-rail__collapse');
    expect(collapse.attributes('aria-label')).toBe('Collapse navigation');
    await collapse.trigger('click');
    expect(app.railCollapsed).toBe(true);
    expect(wrapper.find('.swf-shell').classes()).toContain('swf-shell--collapsed');
    expect(wrapper.find('button.swf-rail__collapse').attributes('aria-label')).toBe('Expand navigation');
    expect(wrapper.find('button.swf-rail__collapse').attributes('aria-expanded')).toBe('false');

    await wrapper.find('a.swf-skip').trigger('click');
    expect(document.activeElement).toBe(wrapper.find('main').element);

    app.announce('Saved');
    await flushPromises();
    expect(wrapper.find('[role="status"]').text()).toBe('Saved');
    wrapper.unmount();
  });
});

describe('UI components', () => {
  it('NoAccess shows a lock empty state', () => {
    const wrapper = mount(NoAccess, { global: { plugins: [...plugins()] } });
    expect(wrapper.find('.swf-empty__title').text()).toBe(en.errors.noAccessTitle);
    expect(wrapper.find('.swf-empty__text').text()).toBe(en.errors.noAccessText);
  });

  it('EmptyState renders optional text, actions and compact mode', () => {
    const plain = mount(EmptyState, { props: { title: 'Nothing' }, global: { plugins: [...plugins()] } });
    expect(plain.find('.swf-empty__title').text()).toBe('Nothing');
    expect(plain.find('.swf-empty__text').exists()).toBe(false);
    expect(plain.find('.swf-empty__actions').exists()).toBe(false);
    expect(plain.classes()).not.toContain('swf-empty--compact');

    const full = mount(EmptyState, {
      props: { title: 'Nothing', text: 'Add one', icon: 'r_add', compact: true },
      slots: { default: '<button>Add</button>' },
      global: { plugins: [...plugins()] },
    });
    expect(full.classes()).toContain('swf-empty--compact');
    expect(full.find('.swf-empty__text').text()).toBe('Add one');
    expect(full.find('.swf-empty__actions button').text()).toBe('Add');
  });

  it('PageHeader renders title, subtitle, back link and actions', async () => {
    const router = testRouter();
    await router.push('/settings');
    await router.isReady();
    const minimal = mount(PageHeader, { props: { title: 'Forms' }, global: { plugins: [...plugins(), router] } });
    expect(minimal.find('h1').text()).toBe('Forms');
    expect(minimal.find('.swf-page-header__subtitle').exists()).toBe(false);
    expect(minimal.find('a').exists()).toBe(false);
    expect(minimal.find('.swf-page-header__actions').exists()).toBe(false);

    const full = mount(PageHeader, {
      props: { title: 'Form', subtitle: 'Edit it', backTo: '/', backLabel: 'All forms' },
      slots: { default: '<button>Save</button>' },
      global: { plugins: [...plugins(), router] },
    });
    expect(full.find('.swf-page-header__subtitle').text()).toBe('Edit it');
    const back = full.find('a.swf-page-header__back');
    expect(back.text()).toContain('All forms');
    expect(back.attributes('href')).toBe('/');
    expect(full.find('.swf-page-header__actions button').text()).toBe('Save');
  });

  it('SideSheet renders a labelled dialog and closes', async () => {
    const wrapper = mount(SideSheet, {
      props: { modelValue: true, title: 'Entry 5', subtitle: 'Contact', width: 500 },
      slots: { default: '<p class="body">Content</p>', footer: '<button class="foot">OK</button>' },
      global: { plugins: [...plugins()] },
      attachTo: document.body,
    });
    await flushPromises();
    const card = document.body.querySelector<HTMLElement>('.swf-sheet');
    expect(card).not.toBeNull();
    const heading = card!.querySelector('h2')!;
    expect(heading.textContent).toBe('Entry 5');
    expect(card!.getAttribute('aria-labelledby')).toBe(heading.id);
    expect(card!.style.getPropertyValue('--sheet-w')).toBe('500px');
    expect(card!.querySelector('.swf-sheet__subtitle')?.textContent).toBe('Contact');
    expect(card!.querySelector('.body')?.textContent).toBe('Content');
    expect(card!.querySelector('.swf-sheet__foot .foot')).not.toBeNull();

    card!.querySelector<HTMLButtonElement>('button[aria-label="Close"]')!.click();
    await flushPromises();
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([false]);

    wrapper.findComponent(QDialog).vm.$emit('hide');
    expect(wrapper.emitted('hide')).toHaveLength(1);
    wrapper.unmount();
  });

  it('SideSheet omits subtitle and footer when not given', async () => {
    const wrapper = mount(SideSheet, {
      props: { modelValue: true, title: 'Plain' },
      global: { plugins: [...plugins()] },
      attachTo: document.body,
    });
    await flushPromises();
    const card = document.body.querySelector<HTMLElement>('.swf-sheet')!;
    expect(card.style.getPropertyValue('--sheet-w')).toBe('720px');
    expect(card.querySelector('.swf-sheet__subtitle')).toBeNull();
    expect(card.querySelector('.swf-sheet__foot')).toBeNull();
    wrapper.unmount();
  });
});
