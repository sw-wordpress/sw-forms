import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { formsApi } from '@/api';
import * as notifyModule from '@/composables/useNotify';
import LogicEditor from '@/features/builder/LogicEditor.vue';
import WebhooksPanel from '@/features/builder/WebhooksPanel.vue';
import en from '@/i18n/en';
import { useBuilderStore } from '@/stores/builder';
import type { Webhook } from '@/types/api';
import { change, controls, detail, global, mockApi } from './builder-panels-helpers';

const hook = (id: string, patch: Partial<Webhook> = {}): Webhook => ({
  id, name: `Hook ${id}`, enabled: false, url: 'https://example.com', method: 'POST', format: 'json', headers: [], mapping: [], logic: null, ...patch,
});
const error = vi.fn();

async function panel(webhooks: Webhook[] | undefined) {
  mockApi(detail(webhooks ? { webhooks } : {}));
  vi.spyOn(notifyModule, 'useNotify').mockReturnValue({ error } as unknown as ReturnType<typeof notifyModule.useNotify>);
  const store = useBuilderStore();
  await store.load(7);
  return { store, wrapper: mount(WebhooksPanel, { global: global() }) };
}

beforeEach(() => setActivePinia(createPinia()));
afterEach(async () => {
  await useBuilderStore().saveDraft();
  vi.restoreAllMocks();
  error.mockReset();
});

describe('WebhooksPanel', () => {
  it('adds webhooks to forms without a webhook list and caps them at 20', async () => {
    const { store, wrapper } = await panel(undefined);
    const add = () => wrapper.find('button[aria-label="Add webhook"]');
    await add().trigger('click');
    expect(store.document!.settings.webhooks).toEqual([
      expect.objectContaining({ name: 'New webhook', enabled: false, url: '', method: 'POST', format: 'json', headers: [], mapping: [], logic: null }),
    ]);
    store.updateFormSettings((s) => { s.webhooks = Array.from({ length: 20 }, (_, i) => hook(`w${i}`)); });
    await wrapper.vm.$nextTick();
    expect(add().attributes('disabled')).toBeDefined();
  });

  it('edits settings, headers, mapping and logic', async () => {
    const { store, wrapper } = await panel([hook('w1', { hasSecret: true, headers: [{ name: 'X-A', value: '1' }], mapping: [{ field: 'a', target: 'name' }] }), hook('w2', { secret: 'abc' })]);
    store.errors = [{ path: 'webhook:w1', code: 'x', message: 'Bad URL.' }, { path: 'webhook:w1', code: 'y', message: 'Bad key.' }];
    await wrapper.vm.$nextTick();
    expect(wrapper.find('[role="alert"]').text()).toBe('Bad URL. Bad key.');
    const w1 = () => store.document!.settings.webhooks![0]!;
    const card = () => wrapper.findAll('.swf-card')[0]!;
    const inCard = (name: string, label: string) => {
      const found = controls(wrapper, name).filter((c) => (c.props('label') === label || c.props('ariaLabel') === label) && card().element.contains(c.element));
      return found[0]!;
    };
    expect(controls(wrapper, 'QInput').filter((i) => i.props('label') === en.webhooks.secret).map((i) => [i.props('modelValue'), i.props('hint')]))
      .toEqual([['', en.webhooks.secretSaved], ['abc', en.webhooks.secretHint]]);
    // Only form level data fields can be mapped; labels follow data containers.
    expect((inCard('QSelect', en.webhooks.field).props('options') as { value: string; label: string }[]).map((o) => [o.value, o.label])).toEqual([
      ['a', 'Name'], ['c', 'Agree'], ['s', 'Size'], ['calc', 'Total'], ['in2', 'city'], ['g', 'Repeat'], ['box', 'Box'], ['x', 'Box › x'],
    ]);

    await change(inCard('QToggle', en.webhooks.enabled), 'yes');
    expect(w1().enabled).toBe(true);
    await change(inCard('QInput', en.webhooks.name), 'CRM');
    await change(inCard('QInput', en.webhooks.url), null);
    await change(inCard('QSelect', en.webhooks.method), 'PUT');
    await change(inCard('QSelect', en.webhooks.format), 'form');
    await change(inCard('QInput', en.webhooks.secret), 's3cret');
    expect(w1()).toMatchObject({ name: 'CRM', url: '', method: 'PUT', format: 'form', secret: 's3cret' });

    await change(inCard('QInput', en.webhooks.headerName), 'X-B');
    await change(inCard('QInput', en.webhooks.headerValue), null);
    expect(w1().headers).toEqual([{ name: 'X-B', value: '' }]);
    await card().find('button[aria-label="Add header"]').trigger('click');
    expect(w1().headers).toEqual([{ name: 'X-B', value: '' }, { name: '', value: '' }]);

    await change(inCard('QSelect', en.webhooks.field), 'c');
    await change(inCard('QInput', en.webhooks.target), 'agree');
    expect(w1().mapping).toEqual([{ field: 'c', target: 'agree' }]);
    await card().find('button[aria-label="Add mapping"]').trigger('click');
    expect(w1().mapping).toHaveLength(2);

    // Delete buttons in the card: the webhook itself (header), then header rows, then mapping rows.
    const deletes = () => card().findAll('button[aria-label="Delete"]');
    expect(deletes()).toHaveLength(5);
    await deletes()[2]!.trigger('click'); // second header
    expect(w1().headers).toEqual([{ name: 'X-B', value: '' }]);
    await deletes()[3]!.trigger('click'); // second mapping (one header left)
    expect(w1().mapping).toEqual([{ field: 'c', target: 'agree' }]);

    wrapper.findAllComponents(LogicEditor)[0]!.vm.$emit('update', { action: 'show', match: 'all', rules: [{ field: 'a', op: 'empty' }] });
    expect(w1().logic?.rules).toHaveLength(1);

    await deletes()[0]!.trigger('click');
    expect(store.document!.settings.webhooks!.map((w) => w.id)).toEqual(['w2']);
  });

  it('disables adding headers and mappings at 50 entries', async () => {
    const many = Array.from({ length: 50 }, (_, i) => ({ name: `h${i}`, value: '', field: 'a', target: `t${i}` }));
    const { wrapper } = await panel([hook('w1', { headers: many.map(({ name, value }) => ({ name, value })), mapping: many.map(({ field, target }) => ({ field, target })) })]);
    expect(wrapper.find('button[aria-label="Add header"]').attributes('disabled')).toBeDefined();
    expect(wrapper.find('button[aria-label="Add mapping"]').attributes('disabled')).toBeDefined();
  });

  it('saves the draft before a test request and shows the result', async () => {
    const { store, wrapper } = await panel([hook('w1'), hook('w2')]);
    let resolve: (value: { success: boolean; code: number; duration: number; message: string }) => void = () => {};
    const tested = vi.spyOn(formsApi, 'testWebhook').mockReturnValue(new Promise((done) => { resolve = done; }));
    const save = vi.spyOn(store, 'saveDraft');
    const tests = () => wrapper.findAll('button[aria-label="Send test"]');
    await tests()[0]!.trigger('click');
    await flushPromises();
    expect(save).toHaveBeenCalled();
    expect(tested).toHaveBeenCalledWith(7, 'w1');
    expect(tests().map((b) => [b.attributes('data-loading'), b.attributes('disabled')])).toEqual([['true', ''], ['false', '']]);
    resolve({ success: true, code: 200, duration: 12, message: 'OK' });
    await flushPromises();
    expect(wrapper.find('[role="status"]').text()).toBe('Delivered · HTTP 200 · 12 ms OK');
    expect(wrapper.find('[role="status"]').classes()).toContain('text-positive');
    expect(tests()[1]!.attributes('disabled')).toBeUndefined();

    tested.mockResolvedValue({ success: false, code: 500, duration: 3, message: 'Boom' });
    await tests()[1]!.trigger('click');
    await flushPromises();
    const status = wrapper.findAll('[role="status"]')[1]!;
    expect(status.text()).toBe('Failed · HTTP 500 · 3 ms Boom');
    expect(status.classes()).toContain('text-negative');
    expect(error).not.toHaveBeenCalled();
  });

  it('reports a failed draft save or test request', async () => {
    const { wrapper } = await panel([hook('w1')]);
    const tested = vi.spyOn(formsApi, 'testWebhook').mockRejectedValue(new Error('offline'));
    vi.spyOn(formsApi, 'saveDraft').mockRejectedValue(new Error('down'));
    await wrapper.find('button[aria-label="Send test"]').trigger('click');
    await flushPromises();
    expect(tested).not.toHaveBeenCalled();
    expect(error).toHaveBeenCalledWith(new Error(en.webhooks.saveFailed));

    vi.spyOn(formsApi, 'saveDraft').mockResolvedValue({ saved: true, previewUrl: '' });
    await wrapper.find('button[aria-label="Send test"]').trigger('click');
    await flushPromises();
    expect(error).toHaveBeenLastCalledWith(new Error('offline'));
    expect(wrapper.find('[role="status"]').exists()).toBe(false);
    expect(wrapper.find('button[aria-label="Send test"]').attributes('disabled')).toBeUndefined();
  });
});
