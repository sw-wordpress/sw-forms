import { describe, expect, it } from 'vitest';
import { COLUMN_TYPE, Tree } from '@shared/tree';
import { staticCatalog } from '@shared/types';
import type { ElementNode } from '@shared/types';

const catalog = staticCatalog({
  input: { data: true, container: '' },
  html: { data: false, container: '' },
  group: { data: true, container: 'children' },
  columns: { data: false, container: 'columns' },
});

const fields = [
  { id: 'name', type: 'input', key: 'name' },
  {
    id: 'layout',
    type: 'columns',
    columns: [
      { id: 'c1', children: [{ id: 'email', type: 'input', key: 'email' }] },
      { id: 'c2', children: [{ id: 'note', type: 'html' }] },
    ],
  },
  {
    id: 'people',
    type: 'group',
    settings: { repeatable: true },
    children: [
      { id: 'person', type: 'input', key: 'person' },
      { id: 'inner', type: 'group', children: [{ id: 'deep', type: 'input' }] },
      { id: 'kids', type: 'group', settings: { repeatable: true }, children: [{ id: 'kid', type: 'input' }] },
    ],
  },
  { id: 'plain', type: 'group' },
  // Invalid and duplicate entries are skipped.
  null,
  'text',
  { id: '', type: 'input' },
  { type: 'input' },
  { id: 'name', type: 'html' },
] as unknown as ElementNode[];

describe('shared Tree', () => {
  const tree = new Tree(fields, catalog);

  it('indexes roots, children and columns as pseudo nodes', () => {
    expect(tree.rootIds).toEqual(['name', 'layout', 'people', 'plain']);
    expect(tree.ids()).toEqual(['name', 'layout', 'c1', 'email', 'c2', 'note', 'people', 'person', 'inner', 'deep', 'kids', 'kid', 'plain']);
    expect(tree.has('email')).toBe(true);
    expect(tree.has('missing')).toBe(false);
    expect(tree.node('name')?.type).toBe('input');
    expect(tree.node('missing')).toBeUndefined();
    expect(tree.childIds('layout')).toEqual(['c1', 'c2']);
    expect(tree.childIds('c1')).toEqual(['email']);
    expect(tree.childIds('plain')).toEqual([]);
    expect(tree.childIds('missing')).toEqual([]);
    expect(tree.type('c1')).toBe(COLUMN_TYPE);
    expect(tree.type('missing')).toBe('');
  });

  it('tracks parents and descendants', () => {
    expect(tree.parentOf('name')).toBeNull();
    expect(tree.parentOf('email')).toBe('c1');
    expect(tree.parentOf('c1')).toBe('layout');
    expect(tree.parentOf('missing')).toBeNull();
    expect(tree.isDescendant('email', 'layout')).toBe(true);
    expect(tree.isDescendant('deep', 'people')).toBe(true);
    expect(tree.isDescendant('email', 'people')).toBe(false);
    expect(tree.isDescendant('name', 'layout')).toBe(false);
  });

  it('classifies columns, data, leaves and repeaters', () => {
    expect(tree.isColumn('c1')).toBe(true);
    expect(tree.isColumn('email')).toBe(false);
    expect(tree.isData('email')).toBe(true);
    expect(tree.isData('c1')).toBe(false);
    expect(tree.isData('note')).toBe(false);
    expect(tree.isLeafData('email')).toBe(true);
    expect(tree.isLeafData('people')).toBe(false);
    expect(tree.isRepeater('people')).toBe(true);
    expect(tree.isRepeater('inner')).toBe(false);
    expect(tree.isRepeater('name')).toBe(false);
    expect(tree.isRepeater('missing')).toBe(false);
    expect(Tree.repeatable({ id: 'x', type: 'group', settings: { repeatable: 'yes' } })).toBe(false);
  });

  it('assigns repeater scopes and checks visibility between scopes', () => {
    expect(tree.scopeOf('name')).toBeNull();
    expect(tree.scopeOf('email')).toBeNull();
    expect(tree.scopeOf('people')).toBeNull();
    expect(tree.scopeOf('person')).toBe('people');
    expect(tree.scopeOf('deep')).toBe('people');
    expect(tree.scopeOf('kids')).toBe('people');
    expect(tree.scopeOf('kid')).toBe('kids');
    expect(tree.scopeOf('missing')).toBeNull();

    expect(tree.scopeEncloses(null, null)).toBe(true);
    expect(tree.scopeEncloses(null, 'kids')).toBe(true);
    expect(tree.scopeEncloses('people', 'kids')).toBe(true);
    expect(tree.scopeEncloses('kids', 'people')).toBe(false);
    expect(tree.scopeEncloses('kids', null)).toBe(false);
  });
});
