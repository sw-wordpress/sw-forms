import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { formsApi } from '@/api';
import { useBuilderStore } from '@/stores/builder';
import type { FieldTypeDefinition, FormDetail } from '@/types/api';

const types: FieldTypeDefinition[] = [
  { type: 'input', label: 'Input', icon: '', category: 'basic', isData: true, container: '', supports: ['key', 'label', 'required', 'logic'], settings: [], defaults: {} },
  { type: 'page-break', label: 'Page break', icon: '', category: 'layout', isData: false, container: '', supports: ['label', 'adminLabel'], settings: [], defaults: {} },
  { type: 'columns', label: 'Columns', icon: '', category: 'layout', isData: false, container: 'columns', supports: [], settings: [], defaults: {} },
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

  it('manages steps as tabs and merges them when multi-step is turned off', async () => {
    const store = useBuilderStore();
    await store.load(7);
    expect(store.stepsEnabled).toBe(false);
    store.setStepsEnabled(true);
    expect(store.steps.map((s) => s.count)).toEqual([1]);
    store.addStep('Step 2');
    expect(store.activeStep).toBe(1);
    expect(store.steps.map((s) => s.label)).toEqual(['', 'Step 2']);
    const added = store.addElement('input', 'step:1', 0)!;
    store.renameStep(0, 'Contact');
    store.renameStep(1, 'Details');
    expect(store.steps.map((s) => [s.label, s.count])).toEqual([['Contact', 1], ['Details', 1]]);

    store.select('old');
    expect(store.activeStep).toBe(0);
    store.moveToStep('old', 1);
    expect(store.activeStep).toBe(1);
    expect(store.document!.fields.map((n) => n.id)).toEqual([store.steps[1]!.breakId, added.id, 'old']);
    store.moveToStep('old', 1);
    store.moveToStep('missing', 0);

    store.moveStep(1, -1);
    expect(store.activeStep).toBe(0);
    expect(store.steps.map((s) => s.label)).toEqual(['Details', 'Contact']);
    const before = store.document!.fields.length;
    store.moveStep(0, -1);
    expect(store.document!.fields.length).toBe(before);

    store.removeStep(1);
    expect(store.steps).toHaveLength(1);
    expect(store.activeStep).toBe(0);
    store.addStep('Again');
    store.setStepsEnabled(false);
    expect(store.document!.fields.some((n) => n.type === 'page-break')).toBe(false);
    expect(store.document!.settings.steps?.enabled).toBe(false);
    store.undo();
    expect(store.stepsEnabled).toBe(true);
    expect(store.steps).toHaveLength(2);
    store.showStep(99);
    expect(store.activeStep).toBe(1);
  });
  it('exposes the tree, selection and definitions', async () => {
    const draft = detail();
    draft.document.fields.push({ id: 'grp', type: 'group', key: 'grp', label: 'Group', settings: { repeatable: true }, children: [{ id: 'inner', type: 'input', key: 'inner' }] });
    vi.mocked(formsApi.get).mockResolvedValue(draft);
    const store = useBuilderStore();
    await store.load(7);
    expect(store.definition('input')?.label).toBe('Input');
    expect(store.definition('nope')).toBeUndefined();
    expect(store.tree.ids()).toEqual(['old', 'grp', 'inner']);
    expect(store.tree.isRepeater('grp')).toBe(true);
    expect(store.tree.scopeOf('inner')).toBe('grp');
    expect(store.selected).toBeNull();
    store.select('inner');
    expect(store.selected?.listId).toBe('grp');

    store.removeElement('grp');
    expect(store.selectedId).toBeNull();
    expect(store.tree.ids()).toEqual(['old']);
    store.select('old');
    store.removeElement('missing');
    expect(store.selectedId).toBe('old');
  });

  it('coalesces title edits into one undo step', async () => {
    const store = useBuilderStore();
    store.setTitle('ignored without document');
    expect(store.title).toBe('');
    await store.load(7);
    store.setTitle('C');
    store.setTitle('Co');
    expect(store.title).toBe('Co');
    expect(store.dirty).toBe(true);
    expect(store.canUndo).toBe(true);
    vi.advanceTimersByTime(1500);
    store.setTitle('Contact us');
    store.undo();
    expect(store.title).toBe('Co');
    store.undo();
    expect(store.title).toBe('Contact');
    expect(store.canRedo).toBe(true);
  });

  it('updates settings, logic, columns and form settings', async () => {
    const draft = detail();
    draft.document.fields.push({ id: 'cols', type: 'columns', columns: [{ id: 'c1', children: [] }] });
    vi.mocked(formsApi.get).mockResolvedValue(draft);
    const store = useBuilderStore();
    await store.load(7);

    store.updateSetting('old', 'placeholder', 'you@example.com');
    store.updateSetting('missing', 'placeholder', 'x');
    expect(store.document!.fields[0]!.settings).toEqual({ placeholder: 'you@example.com' });

    const logic = { action: 'show', match: 'all', rules: [] } as never;
    store.updateLogic('old', logic);
    store.updateLogic('c1', null);
    store.updateLogic('missing', logic);
    expect(store.document!.fields[0]!.logic).toEqual(logic);
    expect(store.document!.fields[1]!.columns![0]!.logic).toBeNull();

    store.columnsChange('cols', (node) => node.columns!.push({ id: 'c2', children: [] }));
    const untouched = vi.fn();
    store.columnsChange('old', untouched, 'x');
    expect(untouched).not.toHaveBeenCalled();
    expect(store.document!.fields[1]!.columns!.map((c) => c.id)).toEqual(['c1', 'c2']);

    store.updateFormSettings((settings) => { settings.submitLabel = 'Go'; }, 'submit');
    store.updateFormSettings((settings) => { settings.cssClass = 'x'; });
    expect(store.document!.settings.submitLabel).toBe('Go');
    expect(store.document!.settings.cssClass).toBe('x');
  });

  it('publishes, keeps the selection and reports draft failures', async () => {
    const store = useBuilderStore();
    expect(await store.save()).toBe(false);
    expect(await store.saveDraft()).toBeNull();
    await store.load(7);
    store.select('old');
    store.updateElement('old', { label: 'Mail' });
    const published = detail();
    published.title = 'Published';
    vi.spyOn(formsApi, 'save').mockResolvedValue(published);
    expect(await store.save()).toBe(true);
    expect(store.title).toBe('Published');
    expect(store.selectedId).toBe('old');
    expect(store.dirty).toBe(false);
    expect(store.draftState).toBe('idle');
    expect(store.saving).toBe(false);

    vi.mocked(formsApi.save).mockRejectedValue(new Error('offline'));
    await expect(store.save()).rejects.toThrow('offline');
    expect(store.saving).toBe(false);

    vi.mocked(formsApi.saveDraft).mockRejectedValue(new Error('offline'));
    expect(await store.saveDraft()).toBeNull();
    expect(store.draftState).toBe('failed');
  });

  it('changes the publication status', async () => {
    const store = useBuilderStore();
    const inactive = { ...detail(), status: 'inactive' as const };
    const setStatus = vi.spyOn(formsApi, 'setStatus').mockResolvedValue(inactive);
    await store.setStatus('inactive');
    expect(store.status).toBe('inactive');
    expect(store.meta).toBeNull();
    await store.load(7);
    await store.setStatus('inactive');
    expect(setStatus).toHaveBeenLastCalledWith(7, 'inactive');
    expect(store.meta?.status).toBe('inactive');
  });
});
