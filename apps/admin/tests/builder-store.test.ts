import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { formsApi } from '@/api';
import { useBuilderStore } from '@/stores/builder';
import type { FieldTypeDefinition, FormDetail } from '@/types/api';

const types: FieldTypeDefinition[] = [
  { type: 'input', label: 'Input', icon: '', category: 'basic', isData: true, container: '', supports: ['key', 'label', 'required', 'logic'], settings: [], defaults: {} },
  { type: 'group', label: 'Group', icon: '', category: 'layout', isData: true, container: 'children', supports: ['key', 'label', 'logic'], settings: [], defaults: { repeatable: false } },
];

function detail(): FormDetail {
  return {
    id: 7,
    title: 'Contact',
    status: 'active',
    currentVersion: 1,
    hasDraft: false,
    document: {
      schemaVersion: 1,
      fields: [{ id: 'old', type: 'input', key: 'email', label: 'E-Mail' }],
      settings: {
        submitLabel: 'Send',
        cssClass: '',
        confirmation: { type: 'message', message: '', url: '', pageId: 0 },
        storeEntries: true,
        retentionDays: 0,
        spam: { honeypot: true, minSeconds: 2, rateLimit: 10 },
        notifications: [],
      },
    },
    publishedKeys: { old: 'email' },
    previewUrl: '',
    shortcode: '[sw_form id="7"]',
    updatedAt: '',
  };
}

describe('builder store', () => {
  afterEach(() => {
    // Pending autosave timers of a test must not fire in the next one.
    vi.clearAllTimers();
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  beforeEach(() => {
    setActivePinia(createPinia());
    vi.useFakeTimers();
    vi.spyOn(formsApi, 'get').mockResolvedValue(detail());
    vi.spyOn(formsApi, 'fieldTypes').mockResolvedValue(types);
    vi.spyOn(formsApi, 'saveDraft').mockResolvedValue({ saved: true, previewUrl: '' });
  });

  it('adds, edits, undoes and redoes', async () => {
    const store = useBuilderStore();
    await store.load(7);
    expect(store.dirty).toBe(false);

    const el = store.addElement('input', 'root', 1);
    expect(el?.key).toBe('input');
    expect(store.selectedId).toBe(el?.id);
    expect(store.dirty).toBe(true);

    // New field: key follows the label; typing is one undo step.
    store.updateElement(el!.id, { label: 'Vor' });
    store.updateElement(el!.id, { label: 'Vorname' });
    expect(store.document?.fields[1]?.key).toBe('vorname');

    // Published field: key stays.
    store.updateElement('old', { label: 'Mail' });
    expect(store.document?.fields[0]?.key).toBe('email');

    store.undo();
    expect(store.document?.fields[0]?.label).toBe('E-Mail');
    store.undo();
    expect(store.document?.fields[1]?.label).toBe('Input');
    store.undo();
    expect(store.document?.fields.length).toBe(1);
    expect(store.dirty).toBe(false);
    store.redo();
    expect(store.document?.fields.length).toBe(2);
  });

  it('keeps preset component keys when labels change before publishing and after duplication', async () => {
    vi.mocked(formsApi.fieldTypes).mockResolvedValue([...types, {
      type: 'name', label: 'Name', icon: '', category: 'basic', isData: true, container: 'children',
      supports: ['key', 'label'], settings: [], defaults: {},
      initialChildren: [{ type: 'input', key: 'given_name', label: 'Given name', settings: {} }],
    }]);
    const store = useBuilderStore();
    await store.load(7);
    const person = store.addElement('name', 'root', 1)!;
    store.updateElement(person.children![0]!.id, { label: 'Vorname' });
    expect(store.document!.fields[1]!.children![0]!.key).toBe('given_name');
    store.duplicateElement(person.id);
    const copy = store.document!.fields[2]!;
    store.updateElement(copy.children![0]!.id, { label: 'Prénom' });
    expect(copy.children![0]!.key).toBe('given_name');
    const draft = detail();
    draft.hasDraft = true;
    draft.document = JSON.parse(JSON.stringify(store.document));
    vi.mocked(formsApi.get).mockResolvedValue(draft);
    await store.load(7);
    store.updateElement(person.children![0]!.id, { label: 'Renamed after draft reload' });
    expect(store.document!.fields[1]!.children![0]!.key).toBe('given_name');
  });

  it('autosaves a draft after changes', async () => {
    const store = useBuilderStore();
    await store.load(7);
    store.addElement('group', 'root', 0);
    expect(store.draftState).toBe('pending');
    await vi.advanceTimersByTimeAsync(3000);
    expect(formsApi.saveDraft).toHaveBeenCalledTimes(1);
    expect(store.draftState).toBe('saved');
  });

  it('keeps server validation errors on a rejected save', async () => {
    const { ApiError } = await import('@/api/client');
    vi.spyOn(formsApi, 'save').mockRejectedValue(
      new ApiError('swf_invalid_form', 'invalid', 422, {}, { errors: [{ path: 'old', code: 'invalid_key', message: 'bad' }] }),
    );
    const store = useBuilderStore();
    await store.load(7);
    expect(await store.save()).toBe(false);
    expect(store.errorsById.get('old')).toEqual(['bad']);
  });
});
