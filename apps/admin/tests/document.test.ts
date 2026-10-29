import { describe, expect, it } from 'vitest';
import type { ElementNode } from '@shared/types';
import * as doc from '@/features/builder/document';
import type { FieldTypeDefinition, FormDocument } from '@/types/api';

const input: FieldTypeDefinition = {
  type: 'input',
  label: 'Input',
  icon: '',
  category: 'basic',
  isData: true,
  container: '',
  supports: ['key', 'label', 'description', 'required', 'logic'],
  settings: [],
  defaults: { inputType: 'text' },
};

function document(fields: ElementNode[]): FormDocument {
  return {
    schemaVersion: 1,
    fields,
    settings: {
      submitLabel: 'Send',
      cssClass: '',
      confirmation: { type: 'message', message: '', url: '', pageId: 0 },
      storeEntries: true,
      retentionDays: 0,
      spam: { honeypot: true, minSeconds: 2, rateLimit: 10 },
      notifications: [
        { id: 'n1', name: 'n', enabled: true, to: '', subject: '', message: '', replyTo: '', fromName: '', logic: { action: 'show', match: 'all', rules: [{ field: 'a', op: 'notempty' }] } },
      ],
    },
  };
}

function sample(): FormDocument {
  return document([
    { id: 'a', type: 'input', key: 'name', label: 'Name' },
    {
      id: 'cols',
      type: 'columns',
      columns: [
        { id: 'c1', width: 6, children: [{ id: 'b', type: 'input', key: 'email', logic: { action: 'show', match: 'all', rules: [{ field: 'a', op: 'notempty' }] } }] },
        { id: 'c2', width: 6, children: [] },
      ],
    },
    { id: 'g', type: 'group', key: 'address', children: [{ id: 'city', type: 'input', key: 'name' }] },
  ]);
}

describe('keys', () => {
  it('slugifies labels', () => {
    expect(doc.slugKey('Vor- und Nachname')).toBe('vor_und_nachname');
    expect(doc.slugKey('Straße & Hausnr.')).toBe('strasse_hausnr');
    expect(doc.slugKey('1. Wahl')).toBe('field_1_wahl');
    expect(doc.slugKey('')).toBe('field');
    expect(doc.isValidKey(doc.slugKey('Ünïcödé Fïeld'))).toBe(true);
  });

  it('collects keys per data object (columns transparent, groups separate)', () => {
    const d = sample();
    expect([...doc.keysInObject(d, doc.ROOT)].sort()).toEqual(['address', 'email', 'name']);
    expect([...doc.keysInObject(d, 'c2')].sort()).toEqual(['address', 'email', 'name']);
    expect([...doc.keysInObject(d, 'g')]).toEqual(['name']);
    expect(doc.uniqueKey('name', doc.keysInObject(d, 'c2'))).toBe('name_2');
  });
});

describe('structure operations', () => {
  it('creates elements with unique keys and inserts them into column lists', () => {
    const d = sample();
    const el = doc.createElement(input, doc.keysInObject(d, 'c2'), 'Name');
    expect(el.key).toBe('name_2');
    expect(doc.insert(d, 'c2', 0, el)).toBe(true);
    expect(d.fields[1]?.columns?.[1]?.children[0]?.id).toBe(el.id);
  });

  it('moves between data objects and renames a clashing key', () => {
    const d = sample();
    expect(doc.move(d, 'g', 0, doc.ROOT, 0)).toBe(true);
    expect(d.fields[0]?.id).toBe('city');
    expect(d.fields[0]?.key).toBe('name_2');
  });

  it('refuses to move a container into itself', () => {
    const d = sample();
    expect(doc.move(d, doc.ROOT, 1, 'c2', 0)).toBe(false);
    expect(d.fields[1]?.id).toBe('cols');
  });

  it('duplicates with new ids, unique keys and remapped internal rules', () => {
    const d = sample();
    const copy = doc.duplicate(d, 'cols');
    expect(copy).not.toBeNull();
    const field = copy!.columns![0]!.children[0]!;
    expect(field.id).not.toBe('b');
    expect(field.key).toBe('email_2');
    // Rule points outside the copy: unchanged.
    expect(field.logic?.rules[0]?.field).toBe('a');
    expect(d.fields[2]?.id).toBe(copy!.id);
  });

  it('removes elements and strips rules that referenced them', () => {
    const d = sample();
    expect(doc.dependents(d, new Set(['a']))).toEqual(['b', 'notification:n1']);
    expect(doc.remove(d, 'a')).toEqual(['a']);
    expect(doc.findElement(d.fields, 'b')?.logic).toBeNull();
    expect(d.settings.notifications[0]?.logic).toBeNull();
  });
});

describe('columns', () => {
  it('resizes within bounds and keeps fields when columns are removed', () => {
    const d = sample();
    const cols = d.fields[1]!;
    doc.resizeColumn(cols, 0, 4);
    expect(cols.columns!.map((c) => c.width)).toEqual([10, 2]);
    doc.resizeColumn(cols, 0, 5);
    expect(cols.columns!.map((c) => c.width)).toEqual([11, 1]);
    doc.applyPreset(cols, [4, 4, 4]);
    expect(cols.columns!.length).toBe(3);
    expect(doc.removeColumn(cols, cols.columns![0]!.id)).toBe(true);
    // The email field moved to the remaining first column.
    expect(cols.columns![0]!.children.map((c) => c.id)).toContain('b');
    expect(cols.columns!.map((c) => c.width)).toEqual([6, 6]);
    expect(doc.addColumn(cols)).not.toBeNull();
    expect(cols.columns!.map((c) => c.width)).toEqual([4, 4, 4]);
  });
});

describe('webhook references', () => {
  it('warns before deletion and keeps mappings and conditions invalid until explicitly repaired', () => {
    const form = sample();
    form.settings.webhooks = [{
      id: 'hook', name: 'Receiver', enabled: true, url: 'https://example.org/', method: 'POST', format: 'json', headers: [],
      mapping: [{ field: 'a', target: 'receiver_name' }],
      logic: { action: 'show', match: 'all', rules: [{ field: 'a', op: 'notempty' }] },
    }];
    expect(doc.dependents(form, new Set(['a']))).toContain('webhook:hook');
    doc.remove(form, 'a');
    expect(form.settings.webhooks[0]!.mapping).toEqual([{ field: 'a', target: 'receiver_name' }]);
    expect(form.settings.webhooks[0]!.logic?.rules).toEqual([{ field: 'a', op: 'notempty' }]);
  });
});
