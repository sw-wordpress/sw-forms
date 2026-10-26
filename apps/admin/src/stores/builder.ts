import { computed, ref, shallowRef } from 'vue';
import { defineStore } from 'pinia';
import { Tree } from '@shared/tree';
import { staticCatalog } from '@shared/types';
import type { ContainerKind, ElementNode, Logic } from '@shared/types';
import { formsApi } from '@/api';
import { isApiError } from '@/api/client';
import * as doc from '@/features/builder/document';
import type { FieldTypeDefinition, FormDetail, FormDocument, FormSettings, FormStatus, SchemaError } from '@/types/api';

const HISTORY_LIMIT = 100;
/** Typing into one input within this window is a single undo step. */
const COALESCE_MS = 1200;
const AUTOSAVE_MS = 2500;

interface Snapshot {
  title: string;
  document: FormDocument;
}

/**
 * State of the form builder: the document being edited, selection, undo/redo, autosave (draft)
 * and saving (publishing). All changes go through `change()` so history and dirty state stay correct.
 */
export const useBuilderStore = defineStore('builder', () => {
  const formId = ref(0);
  const title = ref('');
  const status = ref<FormStatus>('active');
  const document = ref<FormDocument | null>(null);
  const meta = shallowRef<FormDetail | null>(null);
  const types = shallowRef<FieldTypeDefinition[]>([]);
  const selectedId = ref<string | null>(null);
  const errors = ref<SchemaError[]>([]);
  const saving = ref(false);
  const draftState = ref<'idle' | 'pending' | 'saving' | 'saved' | 'failed'>('idle');
  const past = ref<string[]>([]);
  const future = ref<string[]>([]);
  const savedJson = ref('');
  /** Keys locked by manual edits or preset contracts; they no longer follow labels. */
  const manualKeys = new Set<string>();
  let lastCoalesce = { key: '', at: 0 };
  let draftTimer: ReturnType<typeof setTimeout> | null = null;
  /** Bumped on every change; computed trees depend on it instead of deep watching. */
  const revision = ref(0);

  const typeMap = computed(() => new Map(types.value.map((t) => [t.type, t])));
  const catalog = computed(() =>
    staticCatalog(
      Object.fromEntries(
        types.value.map((t) => [t.type, { data: t.isData, container: t.container as ContainerKind }]),
      ),
    ),
  );
  const tree = computed(() => {
    void revision.value;
    return new Tree(document.value?.fields ?? [], catalog.value);
  });
  const snapshotJson = computed(() => {
    void revision.value;
    return JSON.stringify({ title: title.value, document: document.value });
  });
  const dirty = computed(() => snapshotJson.value !== savedJson.value);
  const canUndo = computed(() => past.value.length > 0);
  const canRedo = computed(() => future.value.length > 0);
  const selected = computed(() => {
    void revision.value;
    if (!document.value || !selectedId.value) return null;
    return doc.locate(document.value.fields, selectedId.value);
  });
  const errorsById = computed(() => {
    const map = new Map<string, string[]>();
    for (const error of errors.value) {
      const list = map.get(error.path) ?? [];
      list.push(error.message);
      map.set(error.path, list);
    }
    return map;
  });

  function definition(type: string): FieldTypeDefinition | undefined {
    return typeMap.value.get(type);
  }

  async function load(id: number): Promise<void> {
    const [detail, fieldTypes] = await Promise.all([
      formsApi.get(id),
      types.value.length > 0 ? Promise.resolve(types.value) : formsApi.fieldTypes(),
    ]);
    types.value = fieldTypes;
    apply(detail);
    past.value = [];
    future.value = [];
    selectedId.value = null;
    manualKeys.clear();
    lockPresetComponentKeys(document.value?.fields ?? []);
    // A pending draft is unsaved work: compare against the published state.
    if (detail.hasDraft) savedJson.value = '';
  }

  function apply(detail: FormDetail): void {
    meta.value = detail;
    formId.value = detail.id;
    title.value = detail.title;
    status.value = detail.status;
    document.value = structuredClone(detail.document);
    lockPresetComponentKeys(document.value.fields);
    errors.value = [];
    revision.value++;
    savedJson.value = snapshotJson.value;
  }

  function snapshot(): string {
    return JSON.stringify({ title: title.value, document: document.value });
  }

  function restore(json: string): void {
    const data = JSON.parse(json) as Snapshot;
    title.value = data.title;
    document.value = data.document;
    if (selectedId.value && !doc.locate(data.document.fields, selectedId.value)) selectedId.value = null;
    revision.value++;
    scheduleDraft();
  }

  /**
   * Applies a mutation as one undo step. Changes with the same `coalesce` key in quick
   * succession (typing) share a step.
   */
  function change(mutate: (d: FormDocument) => void, coalesce = ''): void {
    if (!document.value) return;
    const now = Date.now();
    if (coalesce === '' || coalesce !== lastCoalesce.key || now - lastCoalesce.at > COALESCE_MS) {
      past.value.push(snapshot());
      if (past.value.length > HISTORY_LIMIT) past.value.shift();
    }
    lastCoalesce = { key: coalesce, at: now };
    future.value = [];
    mutate(document.value);
    revision.value++;
    scheduleDraft();
  }

  function undo(): void {
    const previous = past.value.pop();
    if (previous === undefined) return;
    future.value.push(snapshot());
    lastCoalesce = { key: '', at: 0 };
    restore(previous);
  }

  function redo(): void {
    const next = future.value.pop();
    if (next === undefined) return;
    past.value.push(snapshot());
    restore(next);
  }

  function setTitle(value: string): void {
    if (!document.value) return;
    const now = Date.now();
    if (lastCoalesce.key !== 'title' || now - lastCoalesce.at > COALESCE_MS) past.value.push(snapshot());
    lastCoalesce = { key: 'title', at: now };
    title.value = value;
    revision.value++;
  }

  // ---------- elements ----------

  /** Template component keys are data contracts, independent of localized labels. */
  function lockPresetComponentKeys(fields: ElementNode[]): void {
    doc.walk(fields, (parent) => {
      const templates = typeMap.value.get(parent.type)?.initialChildren;
      if (!templates) return;
      const keys = new Set(templates.map((template) => template.key));
      doc.walk(parent.children ?? [], (child) => {
        if (child.key && keys.has(child.key)) manualKeys.add(child.id);
      });
    });
  }

  function addElement(type: string, listId: string, index: number): ElementNode | null {
    const definition = typeMap.value.get(type);
    if (!definition || !document.value) return null;
    let created: ElementNode | null = null;
    change((d) => {
      const node = doc.createElement(definition, doc.keysInObject(d, listId));
      if (doc.insert(d, listId, index, node)) {
        lockPresetComponentKeys([node]);
        created = node;
      }
    });
    if (created) selectedId.value = (created as ElementNode).id;
    return created;
  }

  function moveElement(fromList: string, fromIndex: number, toList: string, toIndex: number): boolean {
    let moved = false;
    change((d) => {
      moved = doc.move(d, fromList, fromIndex, toList, toIndex);
    });
    if (!moved) past.value.pop();
    return moved;
  }

  function duplicateElement(id: string): void {
    let copy: ElementNode | null = null;
    change((d) => {
      copy = doc.duplicate(d, id);
    });
    if (copy) {
      lockPresetComponentKeys([copy as ElementNode]);
      selectedId.value = (copy as ElementNode).id;
    }
  }

  function removeElement(id: string): void {
    change((d) => {
      doc.remove(d, id);
    });
    if (selectedId.value && document.value && !doc.locate(document.value.fields, selectedId.value)) {
      selectedId.value = null;
    }
  }

  /** Whether a new, unpublished and unlocked key still follows its label. */
  function keyFollowsLabel(id: string): boolean {
    return !(id in (meta.value?.publishedKeys ?? {})) && !manualKeys.has(id);
  }

  function updateElement(id: string, patch: Partial<ElementNode>): void {
    change((d) => {
      const found = doc.locate(d.fields, id);
      if (!found) return;
      const node = found.node as ElementNode;
      Object.assign(node, patch);
      if ('key' in patch) manualKeys.add(id);
      if ('label' in patch && node.key !== undefined && !found.isColumn && keyFollowsLabel(id)) {
        const label = patch.label ?? '';
        if (label.trim() !== '') node.key = doc.uniqueKey(doc.slugKey(label), doc.keysInObject(d, found.listId, id));
      }
    }, `${id}:${Object.keys(patch).join(',')}`);
  }

  function updateSetting(id: string, name: string, value: unknown): void {
    change((d) => {
      const node = doc.findElement(d.fields, id);
      if (!node) return;
      node.settings = { ...(node.settings ?? {}), [name]: value };
    }, `${id}:settings.${name}`);
  }

  /** Logic of an element or a column. */
  function updateLogic(id: string, logic: Logic | null): void {
    change((d) => {
      const found = doc.locate(d.fields, id);
      if (found) found.node.logic = logic;
    }, `${id}:logic`);
  }

  function columnsChange(id: string, mutate: (node: ElementNode) => void, coalesce = ''): void {
    change((d) => {
      const node = doc.findElement(d.fields, id);
      if (node?.columns) mutate(node);
    }, coalesce);
  }

  function updateFormSettings(mutate: (settings: FormSettings) => void, coalesce = ''): void {
    change((d) => mutate(d.settings), coalesce === '' ? '' : `settings:${coalesce}`);
  }

  function select(id: string | null): void {
    selectedId.value = id;
  }

  // ---------- persistence ----------

  function scheduleDraft(): void {
    if (draftTimer) clearTimeout(draftTimer);
    if (!dirty.value) return;
    draftState.value = 'pending';
    draftTimer = setTimeout(() => void saveDraft(), AUTOSAVE_MS);
  }

  async function saveDraft(): Promise<string | null> {
    if (draftTimer) clearTimeout(draftTimer);
    draftTimer = null;
    if (!document.value) return null;
    draftState.value = 'saving';
    try {
      const result = await formsApi.saveDraft(formId.value, document.value);
      draftState.value = 'saved';
      return result.previewUrl;
    } catch {
      draftState.value = 'failed';
      return null;
    }
  }

  /** Validates and publishes. Returns false with `errors` filled when the server rejects the form. */
  async function save(): Promise<boolean> {
    if (!document.value) return false;
    if (draftTimer) clearTimeout(draftTimer);
    saving.value = true;
    try {
      const detail = await formsApi.save(formId.value, title.value, document.value);
      const selection = selectedId.value;
      apply(detail);
      selectedId.value = selection;
      manualKeys.clear();
      lockPresetComponentKeys(document.value?.fields ?? []);
      draftState.value = 'idle';
      return true;
    } catch (error) {
      if (isApiError(error) && error.status === 422 && Array.isArray(error.data.errors)) {
        errors.value = error.data.errors as SchemaError[];
        return false;
      }
      throw error;
    } finally {
      saving.value = false;
    }
  }

  async function setStatus(value: FormStatus): Promise<void> {
    const detail = await formsApi.setStatus(formId.value, value);
    status.value = detail.status;
    if (meta.value) meta.value = { ...meta.value, status: detail.status };
  }

  return {
    formId,
    title,
    status,
    document,
    meta,
    types,
    selectedId,
    selected,
    errors,
    errorsById,
    saving,
    draftState,
    dirty,
    canUndo,
    canRedo,
    tree,
    revision,
    definition,
    load,
    undo,
    redo,
    setTitle,
    addElement,
    moveElement,
    duplicateElement,
    removeElement,
    keyFollowsLabel,
    updateElement,
    updateSetting,
    updateLogic,
    columnsChange,
    updateFormSettings,
    select,
    saveDraft,
    save,
    setStatus,
  };
});
