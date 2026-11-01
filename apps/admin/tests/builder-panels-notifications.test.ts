import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import LogicEditor from '@/features/builder/LogicEditor.vue';
import NotificationsPanel from '@/features/builder/NotificationsPanel.vue';
import en from '@/i18n/en';
import { useBuilderStore } from '@/stores/builder';
import type { Notification } from '@/types/api';
import { change, control$, controls, detail, global, mockApi } from './builder-panels-helpers';

const mail = (id: string, patch: Partial<Notification> = {}): Notification => ({
  id, name: `Mail ${id}`, enabled: true, to: 'a@example.com', subject: 'Hi', message: '{all_fields}', replyTo: '', fromName: '', logic: null, ...patch,
});

async function panel(notifications: Notification[]) {
  mockApi(detail({ notifications }));
  const store = useBuilderStore();
  await store.load(7);
  return { store, wrapper: mount(NotificationsPanel, { global: global() }) };
}

beforeEach(() => setActivePinia(createPinia()));
afterEach(async () => {
  await useBuilderStore().saveDraft();
  vi.restoreAllMocks();
});

describe('NotificationsPanel', () => {
  it('shows an empty state and adds an opened notification', async () => {
    const { store, wrapper } = await panel([]);
    expect(wrapper.text()).toContain(en.notifications.emptyTitle);
    await wrapper.find('button[aria-label="Add notification"]').trigger('click');
    const [added] = store.document!.settings.notifications;
    expect(added).toMatchObject({ name: 'Notification', enabled: true, to: '{admin_email}', subject: 'New entry: {form:title}', message: '{all_fields}', logic: null });
    expect(wrapper.text()).not.toContain(en.notifications.emptyTitle);
    expect(wrapper.find('.swf-notification__head').attributes('aria-expanded')).toBe('true');
  });

  it('edits, toggles and removes notifications', async () => {
    const { store, wrapper } = await panel([
      mail('n1', { logic: { action: 'show', match: 'all', rules: [{ field: 'a', op: 'empty' }] } }),
      mail('n2', { enabled: false }),
    ]);
    store.errors = [{ path: 'notification:n1', code: 'x', message: 'bad' }];
    await wrapper.vm.$nextTick();
    const heads = () => wrapper.findAll('.swf-notification__head');
    expect(heads().map((h) => h.attributes('aria-expanded'))).toEqual(['true', 'false']);
    expect(heads()[0]!.text()).toContain('Problem');
    expect(heads()[0]!.text()).toContain('Conditional');
    expect(heads()[1]!.text()).not.toContain('Conditional');
    expect(wrapper.findAll('.swf-card')[1]!.classes()).toContain('swf-notification--off');

    const settings = () => store.document!.settings.notifications;
    const edits: [string, keyof Notification, unknown, unknown][] = [
      [en.notifications.name, 'name', 'Admin', 'Admin'],
      [en.notifications.to, 'to', 'x@example.com', 'x@example.com'],
      [en.notifications.subject, 'subject', null, ''],
      [en.notifications.message, 'message', 'Body', 'Body'],
      [en.notifications.replyTo, 'replyTo', '{field:email}', '{field:email}'],
      [en.notifications.fromName, 'fromName', 'Shop', 'Shop'],
    ];
    for (const [label, prop, value, expected] of edits) {
      await change(control$(wrapper, 'QInput', label), value);
      expect(settings()[0]![prop]).toBe(expected);
    }
    // Typing in one input is one undo step.
    await change(control$(wrapper, 'QInput', en.notifications.name), 'Admins');
    store.undo();
    expect(settings()[0]!.name).toBe('Admin');

    await change(control$(wrapper, 'QToggle', en.m5.attachFiles), true);
    expect(settings()[0]!.attachFiles).toBe(true);
    const enabled = controls(wrapper, 'QToggle').filter((t) => t.props('ariaLabel') === en.notifications.enabled);
    await change(enabled[1]!, 1);
    expect(settings()[1]!.enabled).toBe(true);
    wrapper.findComponent(LogicEditor).vm.$emit('update', null);
    expect(settings()[0]!.logic).toBeNull();

    await heads()[1]!.trigger('click');
    expect(heads().map((h) => h.attributes('aria-expanded'))).toEqual(['false', 'true']);
    await heads()[1]!.trigger('click');
    expect(heads().map((h) => h.attributes('aria-expanded'))).toEqual(['false', 'false']);

    await wrapper.findAll('button[aria-label="Delete"]')[0]!.trigger('click');
    expect(settings().map((n) => n.name)).toEqual(['Mail n2']);
  });

  // Regression: structuredClone() on the reactive store proxy threw DataCloneError.
  it('duplicates a notification after the original and opens it', async () => {
    mockApi(detail({ notifications: [mail('n1'), mail('n2')] }));
    const store = useBuilderStore();
    await store.load(7);
    const errors: unknown[] = [];
    const wrapper = mount(NotificationsPanel, { global: { ...global(), config: { errorHandler: (error: unknown) => { errors.push(error); } } } });
    await wrapper.findAll('button[aria-label="Duplicate"]')[0]!.trigger('click');
    expect(errors).toEqual([]);
    const settings = store.document!.settings.notifications;
    expect(settings.map((n) => n.name)).toEqual(['Mail n1', 'Mail n1 (2)', 'Mail n2']);
    expect(settings[1]!.id).not.toBe('n1');
    expect(wrapper.findAll('.swf-notification__head').map((h) => h.attributes('aria-expanded'))).toEqual(['false', 'true', 'false']);
  });
});
