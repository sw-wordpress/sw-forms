import { describe, expect, it } from 'vitest';
import { Tree } from '@shared/tree';
import { BUILT_IN_CATALOG } from '@shared/types';
import { generateSecret, hostOf, mappableFields, targetName } from '@/features/webhooks/webhooks';

const tree = new Tree(
  [
    { id: 'mail', type: 'input', key: 'email', label: 'E-Mail' },
    {
      id: 'cols',
      type: 'columns',
      columns: [{ id: 'c1', width: 12, logic: null, children: [{ id: 'first', type: 'input', key: 'first', label: 'First name' }] }],
    },
    { id: 'addr', type: 'group', key: 'address', label: 'Address', children: [{ id: 'city', type: 'input', key: 'city', label: 'City' }] },
    { id: 'rep', type: 'group', key: 'people', label: 'People', settings: { repeatable: true }, children: [{ id: 'pname', type: 'input', key: 'name' }] },
  ],
  BUILT_IN_CATALOG,
);

describe('webhook mapping helpers', () => {
  it('offers data elements outside repeaters, like the server', () => {
    expect(mappableFields(tree)).toEqual([
      { value: 'mail', label: 'E-Mail' },
      { value: 'first', label: 'First name' },
      { value: 'addr', label: 'Address' },
      { value: 'city', label: 'Address › City' },
      { value: 'rep', label: 'People' },
    ]);
  });

  it('defaults target names to the data path', () => {
    expect(targetName(tree, 'city')).toBe('address.city');
    expect(targetName(tree, 'first')).toBe('first');
  });

  it('shows hosts and generates hex secrets', () => {
    expect(hostOf('https://crm.example.org:8443/hook')).toBe('crm.example.org:8443');
    expect(hostOf('not a url')).toBe('not a url');
    expect(generateSecret()).toMatch(/^[0-9a-f]{64}$/);
    expect(generateSecret()).not.toBe(generateSecret());
  });
});
