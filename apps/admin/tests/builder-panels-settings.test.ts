import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { formsApi, pagesApi } from '@/api';
import * as notifyModule from '@/composables/useNotify';
import FormSettingsPanel from '@/features/builder/FormSettingsPanel.vue';
import en from '@/i18n/en';
import { useBuilderStore } from '@/stores/builder';
import type { FormSettings, WpPage } from '@/types/api';
import { change, control$, controls, detail, global, mockApi } from './builder-panels-helpers';

const page = (id: number, title: string): WpPage => ({ id, title: { rendered: title }, link: `https://x/${id}` });
const notify = { success: vi.fn(), info: vi.fn(), error: vi.fn() };

async function panel(patch: Partial<FormSettings> = {}, currentVersion = 1) {
  mockApi({ ...detail(patch), currentVersion });
  vi.spyOn(notifyModule, 'useNotify').mockReturnValue(notify);
  const store = useBuilderStore();
  await store.load(7);
  const wrapper = mount(FormSettingsPanel, { global: global() });
  await flushPromises();
  return { store, wrapper, settings: () => store.document!.settings };
}
const confirmationType = (wrapper: Awaited<ReturnType<typeof panel>>['wrapper']) =>
  controls(wrapper, 'QBtnToggle').find((c) => (c.props('options') as { value: string }[]).some((o) => o.value === 'redirect'))!;

beforeEach(() => setActivePinia(createPinia()));
afterEach(async () => {
  await useBuilderStore().saveDraft();
  vi.restoreAllMocks();
  Object.values(notify).forEach((fn) => fn.mockReset());
});

describe('FormSettingsPanel', () => {
  it('renders nothing without a document', () => {
    vi.spyOn(pagesApi, 'get');
    const wrapper = mount(FormSettingsPanel, { global: global() });
    expect(wrapper.find('.swf-settings-grid').exists()).toBe(false);
    expect(pagesApi.get).not.toHaveBeenCalled();
  });

  it('edits general, entry and spam settings', async () => {
    const { store, wrapper, settings } = await panel();
    await change(control$(wrapper, 'QInput', en.formSettings.submitLabel), 'Go');
    await change(control$(wrapper, 'QInput', en.formSettings.cssClass), null);
    await change(control$(wrapper, 'QToggle', en.formSettings.storeEntries), 0);
    await change(control$(wrapper, 'QInput', en.formSettings.retention), '-5');
    expect(settings()).toMatchObject({ submitLabel: 'Go', cssClass: '', storeEntries: false, retentionDays: 0 });
    await change(control$(wrapper, 'QInput', en.formSettings.retention), '30');
    expect(settings().retentionDays).toBe(30);

    await change(control$(wrapper, 'QToggle', en.formSettings.honeypot), false);
    await change(control$(wrapper, 'QSelect', en.m3.captcha), 'hcaptcha');
    await change(control$(wrapper, 'QInput', en.formSettings.minSeconds), 'x');
    await change(control$(wrapper, 'QInput', en.formSettings.rateLimit), '4');
    expect(settings().spam).toEqual({ honeypot: false, provider: 'hcaptcha', minSeconds: 0, rateLimit: 4 });
    await change(control$(wrapper, 'QSelect', en.m3.captcha), null);
    expect(settings().spam.provider).toBe('');
    expect(control$(wrapper, 'QSelect', en.m3.captcha).props('modelValue')).toBe('');
    // Typing in the same input coalesces into one undo step.
    await change(control$(wrapper, 'QInput', en.formSettings.submitLabel), 'Go!');
    store.undo();
    expect(settings().submitLabel).toBe('Go');
  });

  it('switches the confirmation between message, page and redirect', async () => {
    vi.spyOn(pagesApi, 'get').mockResolvedValue(page(9, '<i>Thanks</i>'));
    const search = vi.spyOn(pagesApi, 'search').mockResolvedValueOnce([page(3, '<b>About</b>'), page(4, '')]).mockRejectedValueOnce(new Error('offline'));
    const { wrapper, settings } = await panel();
    expect(pagesApi.get).not.toHaveBeenCalled();
    await change(control$(wrapper, 'QInput', en.formSettings.message), 'Thanks {field:name}');
    expect(settings().confirmation.message).toBe('Thanks {field:name}');

    await change(confirmationType(wrapper), 'page');
    expect(settings().confirmation.type).toBe('page');
    const select = () => control$(wrapper, 'QSelect', en.formSettings.page);
    expect(select().props('modelValue')).toBeNull();
    const done = vi.fn((fn: () => void) => fn());
    select().vm.$emit('filter', 'ab', done);
    await flushPromises();
    expect(search).toHaveBeenCalledWith('ab');
    expect(select().props('options')).toEqual([{ value: 3, label: 'About' }, { value: 4, label: '#4' }]);
    // Picking a listed page needs no extra request.
    await change(select(), 3);
    await flushPromises();
    expect(settings().confirmation.pageId).toBe(3);
    expect(pagesApi.get).not.toHaveBeenCalled();
    // A failed search empties the options.
    select().vm.$emit('filter', 'zz', done);
    await flushPromises();
    expect(select().props('options')).toEqual([]);
    // An unknown page id is looked up and listed first.
    await change(select(), 9);
    await flushPromises();
    expect(pagesApi.get).toHaveBeenCalledWith(9);
    expect(select().props('options')).toEqual([{ value: 9, label: 'Thanks' }]);
    await change(select(), null);
    expect(settings().confirmation.pageId).toBe(0);

    await change(confirmationType(wrapper), 'redirect');
    await change(control$(wrapper, 'QInput', en.formSettings.url), 'https://example.com/?n={field:name}');
    expect(settings().confirmation).toMatchObject({ type: 'redirect', url: 'https://example.com/?n={field:name}' });
    await change(control$(wrapper, 'QInput', en.formSettings.url), undefined);
    expect(settings().confirmation.url).toBe('');
  });

  it('keeps showing the page id when the stored page was deleted', async () => {
    const get = vi.spyOn(pagesApi, 'get').mockRejectedValue(new Error('404'));
    const { wrapper } = await panel({ confirmation: { type: 'page', message: '', url: '', pageId: 12 } });
    expect(get).toHaveBeenCalledWith(12);
    const select = control$(wrapper, 'QSelect', en.formSettings.page);
    expect([select.props('modelValue'), select.props('options')]).toEqual([12, []]);
  });

  it('sets the step indicator and turns steps on without confirmation', async () => {
    const { store, wrapper, settings } = await panel();
    expect(store.stepsEnabled).toBe(false);
    await change(control$(wrapper, 'QToggle', 'Enable multi-step'), true);
    expect(store.stepsEnabled).toBe(true);
    expect(wrapper.text()).toContain('1 step(s).');
    const indicator = controls(wrapper, 'QBtnToggle').find((c) => (c.props('options') as { value: string }[]).some((o) => o.value === 'dots'))!;
    expect(indicator.props('modelValue')).toBe('numbers');
    await change(indicator, 'text');
    expect(settings().steps).toEqual({ enabled: true, firstLabel: '', indicator: 'text' });
    // A single step is turned off directly.
    await change(control$(wrapper, 'QToggle', 'Enable multi-step'), false);
    expect(wrapper.find('.confirm').exists()).toBe(false);
    expect(store.stepsEnabled).toBe(false);
  });

  it('copies the embed codes and warns about unpublished forms', async () => {
    const writeText = vi.fn().mockResolvedValueOnce(undefined).mockRejectedValueOnce(new Error('denied'));
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
    const { wrapper } = await panel({}, 0);
    expect(wrapper.findAll('code').map((c) => c.text())).toEqual(['[swforms id="7"]', '<?php echo swf_render_form( 7 ); ?>']);
    expect(wrapper.find('.swf-warning').text()).toBe(en.formSettings.notPublished);
    const copies = wrapper.findAll('button[aria-label="Copy"]');
    await copies[0]!.trigger('click');
    await flushPromises();
    expect(writeText).toHaveBeenCalledWith('[swforms id="7"]');
    expect(notify.success).toHaveBeenCalledWith('Copied to the clipboard');
    await copies[1]!.trigger('click');
    await flushPromises();
    expect(notify.info).toHaveBeenCalledWith('<?php echo swf_render_form( 7 ); ?>');
    Reflect.deleteProperty(navigator, 'clipboard');
  });

  it('hides the warning for published forms and falls back to an empty shortcode', async () => {
    const { store, wrapper } = await panel();
    expect(wrapper.find('.swf-warning').exists()).toBe(false);
    store.meta = null;
    await wrapper.vm.$nextTick();
    expect(wrapper.findAll('code')[0]!.text()).toBe('');
    expect(formsApi.get).toHaveBeenCalledWith(7);
  });
});
