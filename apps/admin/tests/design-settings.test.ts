import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { setConfigForTests } from '@/config';
import { contrast } from '@/features/builder/contrast';
import DesignSettings from '@/features/builder/DesignSettings.vue';
import en from '@/i18n/en';
import { useBuilderStore } from '@/stores/builder';
import type { DesignSettings as Design, FormSettings } from '@/types/api';
import { change, control$, detail, global, mockApi } from './builder-panels-helpers';

const config = (galleryUrl: string) => setConfigForTests({ restRoot: 'http://x/', wpRestRoot: 'http://x/wp/v2/', nonce: 'n', locale: 'en_US', version: '', adminUrl: '', capabilities: [], siteUrl: '', galleryUrl });

async function panel(patch: Partial<FormSettings> = {}, galleryUrl = 'http://x/?swf_design_gallery=1') {
  config(galleryUrl);
  mockApi(detail(patch));
  const store = useBuilderStore();
  await store.load(7);
  const wrapper = mount(DesignSettings, { global: global() });
  await flushPromises();
  return { store, wrapper, design: () => store.document!.settings.design as Design };
}

beforeEach(() => setActivePinia(createPinia()));
afterEach(() => {
  setConfigForTests(null);
  vi.restoreAllMocks();
});

describe('DesignSettings', () => {
  it('computes WCAG contrast like the server', () => {
    expect(contrast('#000000', '#ffffff')).toBeCloseTo(21, 3);
    expect(contrast('#2563eb', '#ffffff')).toBeCloseTo(5.17, 2);
  });

  it('chooses a variant and scheme for documents without a stored design', async () => {
    const { store, wrapper, design } = await panel();
    const buttons = wrapper.findAll('.swf-design__variant');
    expect(buttons).toHaveLength(7);
    expect(buttons[0]!.attributes('aria-pressed')).toBe('true');
    await wrapper.find('[data-variant="animated-float"]').trigger('click');
    expect(design()).toEqual({ variant: 'animated-float', scheme: 'light', tokens: {} });
    expect(wrapper.find('[data-variant="animated-float"]').attributes('aria-pressed')).toBe('true');
    expect(wrapper.text()).toContain('variant="animated-float"');

    await change(control$(wrapper, 'QBtnToggle', en.design.scheme), 'auto');
    expect(design().scheme).toBe('auto');
    await wrapper.find('[data-variant="dark-focus"]').trigger('click');
    expect(control$(wrapper, 'QBtnToggle', en.design.scheme).props('disable')).toBe(true);
    expect(wrapper.text()).toContain(en.design.alwaysDark);
    store.undo();
    expect(design().variant).toBe('animated-float');
    expect(wrapper.text()).toContain(en.design.gallery);
  });

  it('edits own colours and radius, warns about low contrast and resets tokens', async () => {
    const { wrapper, design } = await panel({ design: { variant: 'minimal', scheme: 'dark', tokens: { text: '#eeeeee' } } });
    expect(wrapper.find('details').attributes('open')).toBeDefined();
    expect(wrapper.text()).toContain(en.design.lowTextContrast);

    const accent = wrapper.find<HTMLInputElement>('#swf-design-accent');
    expect(accent.classes()).toContain('is-default');
    accent.element.value = '#FDE047';
    await accent.trigger('change');
    expect(design().tokens.accent).toBe('#fde047');
    expect(wrapper.text()).toContain(en.design.lowAccentContrast);

    const text = wrapper.find<HTMLInputElement>('#swf-design-text');
    text.element.value = '#111827';
    await text.trigger('change');
    expect(wrapper.text()).not.toContain(en.design.lowTextContrast);

    await wrapper.find(`button[aria-label="${en.design.reset.replace('{name}', en.design.tokens.accent)}"]`).trigger('click');
    expect(design().tokens.accent).toBeUndefined();

    const radius = control$(wrapper, 'QInput', en.design.radius);
    await change(radius, '99');
    expect(design().tokens.radius).toBe(32);
    await change(radius, '-3');
    expect(design().tokens.radius).toBe(0);
    await change(radius, null);
    expect(design().tokens).not.toHaveProperty('radius');
    expect(design()).toMatchObject({ variant: 'minimal', scheme: 'dark' });
  });

  it('hides the gallery link without permission', async () => {
    const { wrapper } = await panel({}, '');
    expect(wrapper.text()).not.toContain(en.design.gallery);
  });
});
