import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import LogicEditor from '@/features/builder/LogicEditor.vue';
import en from '@/i18n/en';
import { useBuilderStore } from '@/stores/builder';
import type { Logic } from '@shared/types';
import { change, control$, controls, detail, global, mockApi } from './builder-panels-helpers';

type Props = { logic: Logic | null | undefined; ownerId: string | null; subject?: 'element' | 'notification' | 'webhook' };

async function editor(props: Props, elements?: Parameters<typeof detail>[1]) {
  mockApi(detail({}, elements));
  await useBuilderStore().load(7);
  return mount(LogicEditor, { props, global: global() });
}
const last = (wrapper: ReturnType<typeof mount>) => (wrapper.emitted('update') ?? []).at(-1)?.[0] as Logic;
const options = (wrapper: ReturnType<typeof mount>, label: string) =>
  (control$(wrapper, 'QSelect', label).props('options') as { value: string; label: string }[]);

beforeEach(() => setActivePinia(createPinia()));
afterEach(async () => {
  await useBuilderStore().saveDraft();
  vi.restoreAllMocks();
});

describe('LogicEditor', () => {
  it('offers form level fields for notifications and toggles the logic on and off', async () => {
    const wrapper = await editor({ logic: null, ownerId: null, subject: 'notification' });
    const toggle = control$(wrapper, 'QToggle', en.logic.enableNotification);
    expect(toggle.props('modelValue')).toBe(false);
    expect(toggle.props('disable')).toBe(false);
    expect(wrapper.find('.swf-logic__rules').exists()).toBe(false);

    await change(toggle, true);
    expect(last(wrapper)).toEqual({ action: 'show', match: 'all', rules: [{ field: 'a', op: 'is', value: '' }] });
    await change(toggle, false);
    expect(last(wrapper)).toBeNull();

    await wrapper.setProps({ logic: { action: 'show', match: 'any', rules: [{ field: 'calc', op: 'gt', value: '3' }] } });
    // Form level: calculations allowed, repeater children (r1, r2) excluded; labels are paths.
    expect(options(wrapper, en.logic.field).map((o) => [o.value, o.label])).toEqual([
      ['a', 'Full name'], ['c', 'Agree'], ['s', 'Size'], ['calc', 'Total'], ['in2', 'city'], ['x', 'Box › x'],
    ]);
    expect(wrapper.text()).toContain(en.logic.sendIf);
    expect(controls(wrapper, 'QSelect').some((s) => s.props('ariaLabel') === en.logic.action)).toBe(false);
  });

  it('disables the toggle when no field can be used', async () => {
    const wrapper = await editor({ logic: undefined, ownerId: 'only' }, [{ id: 'only', type: 'input', key: 'only' }]);
    expect(control$(wrapper, 'QToggle', en.logic.enable).props('disable')).toBe(true);
    expect(wrapper.text()).toContain(en.logic.noFields);
  });

  it('edits actions, matching and rules of an element', async () => {
    const logic: Logic = {
      action: 'show',
      match: 'all',
      rules: [
        { field: 'c', op: 'notempty' },
        { field: 's', op: 'is', value: '1' },
        { field: 'in2', op: 'contains', value: 'x' },
        { field: 'gone', op: 'is' },
      ],
    };
    const wrapper = await editor({ logic, ownerId: 'a' });
    expect(control$(wrapper, 'QToggle', en.logic.enable).props('modelValue')).toBe(true);

    // `require` exists for leaf data owners.
    const action = control$(wrapper, 'QSelect', en.logic.action);
    expect((action.props('options') as { value: string }[]).map((o) => o.value)).toEqual(['show', 'hide', 'require', 'disable']);
    await change(action, 'hide');
    expect(last(wrapper)).toEqual({ ...logic, action: 'hide' });
    await change(control$(wrapper, 'QSelect', en.logic.match), 'any');
    expect(last(wrapper)).toEqual({ ...logic, match: 'any' });

    const fieldSelects = controls(wrapper, 'QSelect').filter((s) => s.props('label') === en.logic.field);
    expect(fieldSelects.map((s) => s.props('error'))).toEqual([false, false, false, true]);
    // An element owner never depends on itself or on calculations.
    expect((fieldSelects[0]!.props('options') as { value: string }[]).map((o) => o.value)).toEqual(['c', 's', 'in2', 'x']);

    const ops = controls(wrapper, 'QSelect').filter((s) => s.props('label') === en.logic.operator).map((s) => (s.props('options') as { value: string }[]).map((o) => o.value));
    expect(ops).toEqual([
      ['notempty', 'empty'],
      ['is', 'isnot', 'empty', 'notempty'],
      ['is', 'isnot', 'contains', 'gt', 'lt', 'empty', 'notempty'],
      ['is', 'isnot', 'contains', 'gt', 'lt', 'empty', 'notempty'],
    ]);
    // Value controls: none for the checkbox, option select for the select, text inputs otherwise.
    const valueSelects = controls(wrapper, 'QSelect').filter((s) => s.props('label') === en.logic.value);
    expect(valueSelects).toHaveLength(1);
    expect(valueSelects[0]!.props('options')).toEqual([{ label: 'One', value: '1' }]);
    const valueInputs = controls(wrapper, 'QInput').filter((s) => s.props('label') === en.logic.value);
    expect(valueInputs.map((i) => i.props('modelValue'))).toEqual(['x', '']);
    expect(wrapper.findAll('.swf-logic__joiner').map((j) => j.text())).toEqual(['and', 'and', 'and']);

    await change(valueSelects[0]!, '2');
    expect(last(wrapper).rules[1]).toEqual({ field: 's', op: 'is', value: '2' });
    await change(valueSelects[0]!, null);
    expect(last(wrapper).rules[1]!.value).toBe('');
    await change(valueInputs[0]!, 'abc');
    expect(last(wrapper).rules[2]).toEqual({ field: 'in2', op: 'contains', value: 'abc' });
    await change(valueInputs[1]!, undefined);
    expect(last(wrapper).rules[3]!.value).toBe('');
    await change(controls(wrapper, 'QSelect').filter((s) => s.props('label') === en.logic.operator)[2]!, 'gt');
    expect(last(wrapper).rules[2]).toEqual({ field: 'in2', op: 'gt', value: 'x' });
    // Changing the field resets operator and value.
    await change(fieldSelects[1]!, 'c');
    expect(last(wrapper).rules[1]).toEqual({ field: 'c', op: 'notempty', value: '' });
    await change(fieldSelects[0]!, 'in2');
    expect(last(wrapper).rules[0]).toEqual({ field: 'in2', op: 'is', value: '' });

    await wrapper.find('button[aria-label="Remove condition 2"]').trigger('click');
    expect(last(wrapper).rules.map((r: { field: string }) => r.field)).toEqual(['c', 'in2', 'gone']);
    await wrapper.find('button[aria-label="Add condition"]').trigger('click');
    // The first usable field is a single checkbox: the new rule checks it.
    expect(last(wrapper).rules.at(-1)).toEqual({ field: 'c', op: 'notempty', value: '' });

    await wrapper.setProps({ logic: { ...logic, match: 'any' } });
    expect(wrapper.findAll('.swf-logic__joiner').map((j) => j.text())).toEqual(['or', 'or', 'or']);
  });

  it('removes the logic with its last rule and adds rules to empty logic', async () => {
    const wrapper = await editor({ logic: { action: 'hide', match: 'all', rules: [{ field: 'a', op: 'empty' }] }, ownerId: 'cols', subject: 'element' });
    // Containers cannot be required.
    expect((control$(wrapper, 'QSelect', en.logic.action).props('options') as { value: string }[]).map((o) => o.value)).toEqual(['show', 'hide', 'disable']);
    expect(controls(wrapper, 'QInput').filter((i) => i.props('label') === en.logic.value)).toHaveLength(0);
    await wrapper.find('button[aria-label="Remove condition 1"]').trigger('click');
    expect(last(wrapper)).toBeNull();
    // The column child cannot be used by its own container.
    expect((control$(wrapper, 'QSelect', en.logic.field).props('options') as { value: string }[]).map((o) => o.value)).not.toContain('in2');
  });

  it('lets repeater children use siblings and form level fields', async () => {
    const wrapper = await editor({ logic: { action: 'show', match: 'all', rules: [{ field: 'r1', op: 'is', value: '' }] }, ownerId: 'r2', subject: 'webhook' });
    expect(control$(wrapper, 'QToggle', 'Only send when these conditions match').exists()).toBe(true);
    expect(options(wrapper, en.logic.field).map((o) => [o.value, o.label])).toEqual([
      ['a', 'Full name'], ['c', 'Agree'], ['s', 'Size'], ['in2', 'city'], ['r1', 'Repeat › Row'], ['x', 'Box › x'],
    ]);
  });
});
