import { describe, expect, it } from 'vitest';
import type { FieldTypeDefinition, FormDocument } from '@/types/api';
import { createElement, duplicate } from '@/features/builder/document';

const definition: FieldTypeDefinition = {
  type: 'name', label: 'Name', icon: '', category: 'basic', isData: true, container: 'children',
  supports: ['key', 'label', 'logic'], settings: [], defaults: { repeatable: false },
  initialChildren: [
    { type: 'input', key: 'given_name', label: 'Given name', required: true, settings: { inputType: 'text' } },
    { type: 'input', key: 'family_name', label: 'Family name', required: true, settings: { inputType: 'text' } },
  ],
};

describe('contact group creation', () => {
  it('copies templates once with independent immutable ids and component keys', () => {
    const a = createElement(definition, new Set());
    const b = createElement(definition, new Set(['name']));
    expect(a.children?.map((child) => child.key)).toEqual(['given_name', 'family_name']);
    expect(new Set([a.id, b.id, ...a.children!.map((child) => child.id), ...b.children!.map((child) => child.id)]).size).toBe(6);
    a.children![0]!.label = 'Custom';
    a.children![0]!.settings!.inputType = 'email';
    expect(b.children![0]!.label).toBe('Given name');
    expect(definition.initialChildren![0]!.settings!.inputType).toBe('text');
  });
  it('duplicates groups with new ids, unchanged component keys and remapped internal conditions', () => {
    const person = createElement(definition, new Set());
    person.children![1]!.logic = { action: 'show', match: 'all', rules: [{ field: person.children![0]!.id, op: 'notempty' }] };
    const document = { fields: [person] } as FormDocument;
    const copy = duplicate(document, person.id)!;
    expect(copy.key).toBe('name_2');
    expect(copy.children![0]!.key).toBe('given_name');
    expect(copy.children![0]!.id).not.toBe(person.children![0]!.id);
    expect(copy.children![1]!.logic!.rules[0]!.field).toBe(copy.children![0]!.id);
  });
});
