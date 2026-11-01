import type { ElementNode } from '@shared/types';
import type { FormDocument, FormTemplateCard, TemplateCategory } from '@/types/api';

/** One category with the templates currently matching the search. */
export interface TemplateGroup {
  slug: string;
  title: string;
  icon: string;
  /** Items in this category before filtering, so the count stays honest. */
  count: number;
  items: FormTemplateCard[];
}

export interface ConditionRule {
  field: string;
  fieldLabel: string;
  op: string;
  value: string;
}

export interface ConditionInfo {
  action: string;
  match: 'all' | 'any';
  rules: ConditionRule[];
}

/** One row of the field structure list (depth drives the indentation). */
export interface StructureRow {
  id: string;
  depth: number;
  type: string;
  label: string;
  key: string;
  required: boolean;
  width: number | null;
  options: string[];
  /** Plain text of an information block, so its content is readable in the structure view. */
  content: string;
  condition: ConditionInfo | null;
}

function normalize(value: string): string {
  return value.trim().toLocaleLowerCase();
}

/** Title, description, tags and category are searchable. */
export function matchesQuery(item: FormTemplateCard, categoryTitle: string, query: string): boolean {
  const needle = normalize(query);
  if (needle === '') return true;
  const haystack = [item.title, item.description ?? '', categoryTitle, ...item.tags].map(normalize).join(' ');
  return needle.split(/\s+/).every((word) => haystack.includes(word));
}

/**
 * Groups the cards by category in catalog order, honoring the search and the selected category.
 * Categories without a match are left out, so the dialog never shows empty sections.
 */
export function groupTemplates(
  items: FormTemplateCard[],
  categories: TemplateCategory[],
  query: string,
  category: string,
): TemplateGroup[] {
  const known = new Map(categories.map((entry) => [entry.slug, entry]));
  const groups: TemplateGroup[] = [];
  for (const item of items) {
    const group = known.get(item.category);
    const title = group?.title ?? item.category;
    if (category !== '' && item.category !== category) continue;
    if (!matchesQuery(item, title, query)) continue;
    let target = groups.find((grouped) => grouped.slug === item.category);
    if (!target) {
      target = { slug: item.category, title, icon: group?.icon ?? 'r_category', count: 0, items: [] };
      groups.push(target);
    }
    target.items.push(item);
    target.count += 1;
  }
  return groups;
}

function optionsOf(node: ElementNode): string[] {
  const options = (node.settings as { options?: unknown } | undefined)?.options;
  if (!Array.isArray(options)) return [];
  return options
    .map((option) => (option && typeof option === 'object' ? String((option as { label?: unknown }).label ?? '') : String(option)))
    .filter((label) => label !== '');
}

/**
 * `id -> label` for every element of a document; conditions reference ids, while people read labels.
 */
export function fieldLabels(document: FormDocument): Record<string, string> {
  const labels: Record<string, string> = {};
  const walk = (nodes: ElementNode[]): void => {
    for (const node of nodes) {
      labels[node.id] = node.label ?? node.id;
      walk(node.children ?? []);
      for (const column of node.columns ?? []) walk(column.children ?? []);
    }
  };
  walk(document.fields ?? []);
  return labels;
}

function plainText(html: unknown): string {
  return String(html ?? '')
    .replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function conditionOf(node: ElementNode, labels: Record<string, string>): ConditionInfo | null {
  const logic = node.logic;
  if (!logic) return null;
  return {
    action: logic.action,
    match: logic.match === 'any' ? 'any' : 'all',
    rules: (logic.rules ?? []).map((rule) => ({
      field: rule.field,
      fieldLabel: labels[rule.field] ?? rule.field,
      op: rule.op,
      value: rule.value ?? '',
    })),
  };
}

/**
 * Flattens the document for the structure view: steps, columns with their width, fields with
 * key, required flag, options and their condition. Layout stays visible, because that is what
 * people want to check before they create a form from a template.
 */
export function structureRows(document: FormDocument): StructureRow[] {
  const labels = fieldLabels(document);
  const stepsEnabled = document.settings?.steps?.enabled !== false;
  const rows: StructureRow[] = [];

  const firstLabel = document.settings?.steps?.firstLabel ?? '';
  if (stepsEnabled && (document.fields ?? []).some((node) => node.type === 'page-break') && firstLabel !== '') {
    rows.push({ id: '__first-step', depth: 0, type: 'page-break', label: firstLabel, key: '', required: false, width: null, options: [], content: '', condition: null });
  }

  const walk = (nodes: ElementNode[], depth: number): void => {
    for (const node of nodes) {
      if (node.type === 'columns') {
        rows.push({ id: node.id, depth, type: 'columns', label: '', key: '', required: false, width: null, options: [], content: '', condition: conditionOf(node, labels) });
        for (const column of node.columns ?? []) {
          rows.push({ id: column.id, depth: depth + 1, type: 'column', label: '', key: '', required: false, width: column.width ?? null, options: [], content: '', condition: null });
          walk(column.children ?? [], depth + 2);
        }
        continue;
      }
      rows.push({
        id: node.id,
        depth,
        type: node.type,
        label: node.label ?? '',
        key: node.key ?? '',
        required: node.required === true,
        width: null,
        options: optionsOf(node),
        content: node.type === 'html' ? plainText((node.settings as { content?: unknown } | undefined)?.content) : '',
        condition: conditionOf(node, labels),
      });
      walk(node.children ?? [], depth + 1);
    }
  };

  walk(document.fields ?? [], 0);
  return rows;
}

/** Human width of a column; the 12 column grid is what the builder shows. */
export function widthKey(width: number): 'full' | 'half' | 'third' | 'twoThirds' | 'units' {
  if (width >= 12) return 'full';
  if (width === 6) return 'half';
  if (width === 4) return 'third';
  if (width === 8) return 'twoThirds';
  return 'units';
}

/** True when the document is worth showing: a template without fields would be useless. */
export function hasFields(document: FormDocument | undefined): boolean {
  return (document?.fields ?? []).length > 0;
}
