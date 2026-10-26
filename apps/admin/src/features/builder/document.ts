/**
 * Pure operations on a form document (no Vue, no store). Lists are addressed by a list id:
 * `root` for the form level, a group id for its children, a column id for a column's children.
 */
import type { ColumnNode, ElementNode, Logic } from '@shared/types';
import type { FieldTypeDefinition, FormDocument } from '@/types/api';

export const ROOT = 'root';
export const MAX_COLUMNS = 6;
export const COLUMN_PRESETS: number[][] = [
  [6, 6],
  [4, 4, 4],
  [8, 4],
  [4, 8],
  [3, 9],
  [9, 3],
  [3, 3, 3, 3],
];

export function newId(prefix = 'f'): string {
  const random =
    typeof crypto !== 'undefined' && 'getRandomValues' in crypto
      ? Array.from(crypto.getRandomValues(new Uint8Array(6)), (b) => (b % 36).toString(36)).join('')
      : Math.random().toString(36).slice(2, 8);
  return `${prefix}${Date.now().toString(36).slice(-3)}${random}`;
}

/** `Vor- und Nachname` -> `vor_und_nachname`. Matches the server pattern ^[A-Za-z_][A-Za-z0-9_]{0,63}$. */
export function slugKey(label: string): string {
  let key = label
    .toLowerCase()
    .replace(/ä/g, 'ae')
    .replace(/ö/g, 'oe')
    .replace(/ü/g, 'ue')
    .replace(/ß/g, 'ss')
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 40);
  if (key === '' || /^[0-9]/.test(key)) key = `field_${key}`.replace(/_$/, '');
  return key;
}

export function isValidKey(key: string): boolean {
  return /^[A-Za-z_][A-Za-z0-9_]{0,63}$/.test(key);
}

export function uniqueKey(base: string, taken: Set<string>): string {
  if (!taken.has(base)) return base;
  for (let i = 2; ; i++) {
    const candidate = `${base}_${i}`;
    if (!taken.has(candidate)) return candidate;
  }
}

/** Location of an element (or column) inside the document. */
export interface Location {
  node: ElementNode | ColumnNode;
  list: (ElementNode | ColumnNode)[];
  index: number;
  /** List id of the list holding the node; for columns the id of the columns element. */
  listId: string;
  isColumn: boolean;
}

export function locate(fields: ElementNode[], id: string, listId = ROOT): Location | null {
  for (let index = 0; index < fields.length; index++) {
    const node = fields[index] as ElementNode;
    if (node.id === id) return { node, list: fields, index, listId, isColumn: false };
    if (node.children) {
      const found = locate(node.children, id, node.id);
      if (found) return found;
    }
    for (let c = 0; c < (node.columns ?? []).length; c++) {
      const column = node.columns![c] as ColumnNode;
      if (column.id === id) return { node: column, list: node.columns!, index: c, listId: node.id, isColumn: true };
      const found = locate(column.children, id, column.id);
      if (found) return found;
    }
  }
  return null;
}

export function findElement(fields: ElementNode[], id: string): ElementNode | null {
  const found = locate(fields, id);
  return found && !found.isColumn ? (found.node as ElementNode) : null;
}

/** The element list behind a list id (root, group children or column children). */
export function listById(document: FormDocument, listId: string): ElementNode[] | null {
  if (listId === ROOT) return document.fields;
  const found = locate(document.fields, listId);
  if (!found) return null;
  if (found.isColumn) return (found.node as ColumnNode).children;
  const node = found.node as ElementNode;
  if (!node.columns) {
    node.children ??= [];
    return node.children;
  }
  return null;
}

export function walk(
  fields: ElementNode[],
  callback: (node: ElementNode, listId: string) => void,
  listId = ROOT,
): void {
  for (const node of fields) {
    callback(node, listId);
    if (node.children) walk(node.children, callback, node.id);
    for (const column of node.columns ?? []) walk(column.children, callback, column.id);
  }
}

/** Id of the data object (`root` or a group id) a list belongs to: columns are transparent. */
export function dataOwner(document: FormDocument, listId: string): string {
  let current = listId;
  for (;;) {
    if (current === ROOT) return ROOT;
    const found = locate(document.fields, current);
    if (!found) return ROOT;
    if (!found.isColumn && !(found.node as ElementNode).columns) return current;
    // A column (or the columns element): continue with the list that holds the columns element.
    current = found.isColumn ? (locate(document.fields, found.listId)?.listId ?? ROOT) : found.listId;
  }
}

/** Keys used in the data object of a list, optionally ignoring one element. */
export function keysInObject(document: FormDocument, listId: string, ignoreId?: string): Set<string> {
  const owner = dataOwner(document, listId);
  const keys = new Set<string>();
  const collect = (list: ElementNode[]): void => {
    for (const node of list) {
      if (node.id !== ignoreId && node.key) keys.add(node.key);
      if (node.columns) node.columns.forEach((c) => collect(c.children));
    }
  };
  const list = owner === ROOT ? document.fields : (findElement(document.fields, owner)?.children ?? []);
  collect(list);
  return keys;
}

export function createElement(definition: FieldTypeDefinition, taken: Set<string>, label?: string): ElementNode {
  const node: ElementNode = {
    id: newId(),
    type: definition.type,
    settings: structuredClone(definition.defaults),
  };
  const supports = new Set(definition.supports);
  const text = label ?? definition.label;
  if (supports.has('label')) node.label = text;
  if (supports.has('key')) node.key = uniqueKey(slugKey(text), taken);
  if (supports.has('description')) node.description = '';
  if (supports.has('required')) node.required = false;
  if (supports.has('logic')) node.logic = null;
  if (definition.container === 'children') {
    node.children = (definition.initialChildren ?? []).map((template) => {
      const child: ElementNode = { ...structuredClone(template), id: '' };
      reassignIds(child);
      return child;
    });
  }
  if (definition.container === 'columns') {
    node.columns = [6, 6].map((width) => ({ id: newId('c'), width, logic: null, children: [] }));
  }
  return node;
}

export function insert(document: FormDocument, listId: string, index: number, node: ElementNode): boolean {
  const list = listById(document, listId);
  if (!list) return false;
  list.splice(Math.max(0, Math.min(index, list.length)), 0, node);
  return true;
}

/** Whether `listId` lies inside the element `id` (dropping a container into itself). */
export function isInside(document: FormDocument, listId: string, id: string): boolean {
  let current: string = listId;
  while (current !== ROOT) {
    if (current === id) return true;
    const found = locate(document.fields, current);
    if (!found) return false;
    current = found.listId;
  }
  return false;
}

export function move(
  document: FormDocument,
  fromListId: string,
  fromIndex: number,
  toListId: string,
  toIndex: number,
): boolean {
  const from = listById(document, fromListId);
  const to = listById(document, toListId);
  const node = from?.[fromIndex];
  if (!from || !to || !node || isInside(document, toListId, node.id)) return false;
  from.splice(fromIndex, 1);
  to.splice(Math.max(0, Math.min(toIndex, to.length)), 0, node);
  // A field moved into another data object keeps its key only when it is free there.
  if (node.key && dataOwner(document, fromListId) !== dataOwner(document, toListId)) {
    node.key = uniqueKey(node.key, keysInObject(document, toListId, node.id));
  }
  return true;
}

function reassignIds(node: ElementNode): void {
  node.id = newId();
  node.children?.forEach(reassignIds);
  node.columns?.forEach((column) => {
    column.id = newId('c');
    column.children.forEach(reassignIds);
  });
}

/** Duplicates an element below the original. Keys in the same data object get a suffix. */
export function duplicate(document: FormDocument, id: string): ElementNode | null {
  const found = locate(document.fields, id);
  if (!found || found.isColumn) return null;
  // Documents are JSON data; serialize to also accept reactive store proxies.
  const copy = JSON.parse(JSON.stringify(found.node)) as ElementNode;
  // Rules inside the copy that point into the copy must follow the new ids.
  const idMap = new Map<string, string>();
  const before: string[] = [];
  walk([copy], (n) => before.push(n.id));
  reassignIds(copy);
  const after: string[] = [];
  walk([copy], (n) => after.push(n.id));
  before.forEach((oldId, i) => idMap.set(oldId, after[i] ?? oldId));
  walk([copy], (n) => remapLogic(n.logic, idMap));
  copy.columns?.forEach((c) => remapLogic(c.logic, idMap));

  const taken = keysInObject(document, found.listId);
  const renameInObject = (list: ElementNode[]): void => {
    for (const node of list) {
      if (node.key) {
        node.key = uniqueKey(node.key, taken);
        taken.add(node.key);
      }
      node.columns?.forEach((c) => renameInObject(c.children));
    }
  };
  renameInObject([copy]);
  found.list.splice(found.index + 1, 0, copy);
  return copy;
}

function remapLogic(logic: Logic | null | undefined, ids: Map<string, string>): void {
  logic?.rules.forEach((rule) => {
    rule.field = ids.get(rule.field) ?? rule.field;
  });
}

/** Removes an element; returns the ids of all removed elements (incl. descendants). */
export function remove(document: FormDocument, id: string): string[] {
  const found = locate(document.fields, id);
  if (!found || found.isColumn) return [];
  const removed: string[] = [];
  walk([found.node as ElementNode], (n) => removed.push(n.id));
  found.list.splice(found.index, 1);
  stripRules(document, new Set(removed));
  return removed;
}

/** Ids of elements (and notifications, webhooks) whose conditions or field mappings refer to one of `ids`. */
export function dependents(document: FormDocument, ids: Set<string>): string[] {
  const out: string[] = [];
  const check = (owner: string, logic: Logic | null | undefined): void => {
    if (logic?.rules.some((r) => ids.has(r.field))) out.push(owner);
  };
  walk(document.fields, (node) => {
    if (!ids.has(node.id)) check(node.id, node.logic);
    node.columns?.forEach((c) => check(c.id, c.logic));
  });
  document.settings.notifications.forEach((n) => check(`notification:${n.id}`, n.logic));
  (document.settings.webhooks ?? []).forEach((w) => {
    if (w.logic?.rules.some((r) => ids.has(r.field)) || w.mapping.some((m) => ids.has(m.field))) out.push(`webhook:${w.id}`);
  });
  return out;
}

/**
 * Drops rules and webhook mappings that refer to removed fields; an empty rule list switches the
 * condition off.
 */
export function stripRules(document: FormDocument, ids: Set<string>): void {
  const clean = (logic: Logic | null | undefined): Logic | null => {
    if (!logic) return null;
    const rules = logic.rules.filter((r) => !ids.has(r.field));
    return rules.length > 0 ? { ...logic, rules } : null;
  };
  walk(document.fields, (node) => {
    if ('logic' in node) node.logic = clean(node.logic);
    node.columns?.forEach((c) => (c.logic = clean(c.logic)));
  });
  document.settings.notifications.forEach((n) => (n.logic = clean(n.logic)));
  (document.settings.webhooks ?? []).forEach((w) => {
    w.logic = clean(w.logic);
    w.mapping = w.mapping.filter((m) => !ids.has(m.field));
  });
}

// ---------- columns ----------

export function addColumn(node: ElementNode): ColumnNode | null {
  if (!node.columns || node.columns.length >= MAX_COLUMNS) return null;
  const column: ColumnNode = { id: newId('c'), width: 6, logic: null, children: [] };
  node.columns.push(column);
  equalize(node);
  return column;
}

/** Removes a column; its fields move to the neighbouring column so nothing is lost. */
export function removeColumn(node: ElementNode, columnId: string): boolean {
  const columns = node.columns;
  if (!columns || columns.length <= 1) return false;
  const index = columns.findIndex((c) => c.id === columnId);
  if (index < 0) return false;
  const [removed] = columns.splice(index, 1);
  const target = columns[Math.max(0, index - 1)];
  if (removed && target) target.children.push(...removed.children);
  equalize(node);
  return true;
}

export function equalize(node: ElementNode): void {
  const count = node.columns?.length ?? 0;
  if (count === 0) return;
  const base = Math.max(1, Math.floor(12 / count));
  node.columns!.forEach((c) => (c.width = base));
}

/** Applies a preset; adds or removes columns (fields of removed columns move to the last one kept). */
export function applyPreset(node: ElementNode, widths: number[]): void {
  if (!node.columns) return;
  while (node.columns.length < widths.length) {
    node.columns.push({ id: newId('c'), width: 6, logic: null, children: [] });
  }
  while (node.columns.length > widths.length) {
    const removed = node.columns.pop();
    node.columns[node.columns.length - 1]?.children.push(...(removed?.children ?? []));
  }
  node.columns.forEach((c, i) => (c.width = widths[i] ?? 6));
}

/** Moves `delta` grid units from the right neighbour to column `index` (each column keeps >= 1). */
export function resizeColumn(node: ElementNode, index: number, delta: number): void {
  const left = node.columns?.[index];
  const right = node.columns?.[index + 1];
  if (!left || !right || delta === 0) return;
  const l = left.width ?? 6;
  const r = right.width ?? 6;
  const applied = Math.max(1 - l, Math.min(r - 1, delta));
  left.width = l + applied;
  right.width = r - applied;
}
