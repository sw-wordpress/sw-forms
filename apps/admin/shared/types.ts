/** Form document types shared by the builder and the frontend runtime (docs/schema.md). */

export type Operator = 'is' | 'isnot' | 'contains' | 'gt' | 'lt' | 'empty' | 'notempty';

export interface LogicRule {
  field: string;
  op: Operator | string;
  value?: string;
}

export interface Logic {
  action: 'show' | 'hide';
  match: 'all' | 'any';
  rules: LogicRule[];
}

export type ContainerKind = '' | 'children' | 'columns';

export interface ColumnNode {
  id: string;
  width?: number;
  logic?: Logic | null;
  children: ElementNode[];
}

export interface ElementNode {
  id: string;
  type: string;
  key?: string;
  label?: string;
  description?: string;
  required?: boolean;
  cssClass?: string;
  adminLabel?: string;
  logic?: Logic | null;
  settings?: Record<string, unknown>;
  children?: ElementNode[];
  columns?: ColumnNode[];
  /** Frontend tree only: produces data / container kind / how to read the value. */
  data?: boolean;
  container?: ContainerKind;
  kind?: 'text' | 'bool' | 'list' | 'radio' | 'none';
}

/** Value of a field (string, list, bool) or the rows of a repeater. */
export type FieldValue = string | boolean | string[] | Values[] | null | undefined;
export type Values = Record<string, FieldValue>;

export interface TypeCatalog {
  has(type: string): boolean;
  isData(type: string): boolean;
  container(type: string): ContainerKind;
}

export function staticCatalog(
  types: Record<string, { data: boolean; container: ContainerKind }>,
): TypeCatalog {
  return {
    has: (type) => type in types,
    isData: (type) => types[type]?.data ?? false,
    container: (type) => types[type]?.container ?? '',
  };
}

export const BUILT_IN_CATALOG = staticCatalog({
  input: { data: true, container: '' },
  'birthdate': { data: true, container: '' },
  'date-interval': { data: true, container: '' },
  'time-interval': { data: true, container: '' },
  'range': { data: true, container: '' },
  'rating': { data: true, container: '' },
  'scale': { data: true, container: '' },
  'color': { data: true, container: '' },
  'tags': { data: true, container: '' },

  file: { data: true, container: '' },
  textarea: { data: true, container: '' },
  checkbox: { data: true, container: '' },
  radio: { data: true, container: '' },
  select: { data: true, container: '' },
  name: { data: true, container: 'children' },
  address: { data: true, container: 'children' },
  group: { data: true, container: 'children' },
  columns: { data: false, container: 'columns' },
});
