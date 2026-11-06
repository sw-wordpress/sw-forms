import type { Tree } from './tree';
import type { Logic, LogicRule, Values } from './types';

/**
 * Conditional visibility. TypeScript twin of src/Core/Logic/Evaluator.php; both are tested
 * against tests/fixtures/logic/*.json.
 *
 * - A node is hidden when its parent is hidden or its own logic says so.
 * - A rule that references a hidden field sees an empty value (cascade).
 * - Inside a repeater row, rules see the same row and all enclosing scopes.
 * - Result paths: `id` at form level, `rep[0].id` inside the first row of repeater `rep`.
 */

export const OPERATORS = ['is', 'isnot', 'contains', 'gt', 'lt', 'empty', 'notempty'] as const;

class Scope {
  readonly memo = new Map<string, boolean>();
  readonly enabledMemo = new Map<string, boolean>();
  constructor(
    readonly repeater: string | null,
    readonly values: Values,
    readonly parent: Scope | null,
    readonly prefix: string,
  ) {}
}

export class Evaluator {
  constructor(private readonly tree: Tree) {}

  private enabledPaths = new Map<string, boolean>();
  private requiredPaths = new Map<string, boolean>();

  states(values: Values): { visible: Map<string, boolean>; enabled: Map<string, boolean>; required: Map<string, boolean> } {
    this.enabledPaths = new Map();
    this.requiredPaths = new Map();
    const visible = new Map<string, boolean>();
    this.collect(this.tree.rootIds, new Scope(null, values, null, ''), visible);
    return { visible, enabled: this.enabledPaths, required: this.requiredPaths };
  }

  evaluate(values: Values): Map<string, boolean> {
    return this.states(values).visible;
  }

  /** Logic not attached to a node (e.g. a notification condition), evaluated at form level. */
  conditionMet(logic: Logic | null | undefined, values: Values): boolean {
    return this.logicAllows(logic, new Scope(null, values, null, ''));
  }

  private collect(ids: string[], scope: Scope, out: Map<string, boolean>): void {
    for (const id of ids) {
      out.set(scope.prefix + id, this.visible(id, scope));
      this.enabledPaths.set(scope.prefix + id, this.enabled(id, scope));
      const node = this.tree.node(id);
      this.requiredPaths.set(scope.prefix + id, !!node?.required || (node?.logic?.action === 'require' && this.logicAllows(node.logic, scope)));
      if (this.tree.isRepeater(id)) {
        const rows = scope.values[id];
        (Array.isArray(rows) ? rows : []).forEach((row, index) => {
          const rowValues = row && typeof row === 'object' && !Array.isArray(row) ? row : {};
          const rowScope = new Scope(id, rowValues as Values, scope, `${scope.prefix}${id}[${index}].`);
          this.collect(this.tree.childIds(id), rowScope, out);
        });
        continue;
      }
      this.collect(this.tree.childIds(id), scope, out);
    }
  }

  private resolve(repeater: string | null, scope: Scope): Scope | null {
    let current: Scope | null = scope;
    while (current !== null) {
      if (current.repeater === repeater) return current;
      current = current.parent;
    }
    return null;
  }

  private visible(id: string, scope: Scope): boolean {
    const memo = scope.memo.get(id);
    if (memo !== undefined) return memo;
    // Guard: a dependency cycle (rejected on save) resolves to hidden instead of recursing forever.
    scope.memo.set(id, false);

    let result = true;
    const parent = this.tree.parentOf(id);
    if (parent !== null) {
      const parentScope = this.resolve(this.tree.scopeOf(parent), scope);
      result = parentScope !== null && this.visible(parent, parentScope);
    }
    const logic = this.tree.node(id)?.logic;
    if (result && logic?.action !== 'require' && logic?.action !== 'disable') result = this.logicAllows(logic, scope);
    scope.memo.set(id, result);
    return result;
  }

  private enabled(id: string, scope: Scope): boolean {
    const memo = scope.enabledMemo.get(id);
    if (memo !== undefined) return memo;
    scope.enabledMemo.set(id, false);
    const parent = this.tree.parentOf(id);
    let result = true;
    if (parent !== null) {
      const parentScope = this.resolve(this.tree.scopeOf(parent), scope);
      result = parentScope !== null && this.enabled(parent, parentScope);
    }
    const logic = this.tree.node(id)?.logic;
    if (result && logic?.action === 'disable') result = !this.logicAllows(logic, scope);
    scope.enabledMemo.set(id, result);
    return result;
  }

  private logicAllows(logic: Logic | null | undefined, scope: Scope): boolean {
    if (!logic || !Array.isArray(logic.rules) || logic.rules.length === 0) return true;
    const any = logic.match === 'any';
    let matched = !any;
    for (const rule of logic.rules) {
      const ok = this.ruleMatches(rule, scope);
      if (any && ok) {
        matched = true;
        break;
      }
      if (!any && !ok) {
        matched = false;
        break;
      }
    }
    return logic.action === 'hide' ? !matched : matched;
  }

  private ruleMatches(rule: LogicRule, scope: Scope): boolean {
    const field = typeof rule.field === 'string' ? rule.field : '';
    let value: unknown = '';
    if (field !== '' && this.tree.isLeafData(field)) {
      const fieldScope = this.resolve(this.tree.scopeOf(field), scope);
      if (fieldScope !== null && this.visible(field, fieldScope) && this.enabled(field, fieldScope)) {
        value = fieldScope.values[field] ?? '';
      }
    }
    const target = rule.value;
    return matches(
      typeof rule.op === 'string' ? rule.op : 'is',
      value,
      typeof target === 'string' || typeof target === 'number' || typeof target === 'boolean'
        ? String(target)
        : '',
    );
  }
}

function normalize(value: unknown): string | string[] {
  if (value !== null && typeof value === 'object') {
    const list: string[] = [];
    for (const item of Object.values(value)) {
      if (typeof item === 'boolean') list.push(item ? '1' : '');
      else if (typeof item === 'string' || typeof item === 'number') list.push(String(item));
    }
    return list;
  }
  if (typeof value === 'boolean') return value ? '1' : '';
  if (typeof value === 'string' || typeof value === 'number') return String(value);
  return '';
}

function fold(value: string): string {
  return value.trim().toLowerCase();
}

/** Accepts `1.5` and the German `1,5`. */
export function toNumber(value: string): number | null {
  const v = value.trim().replace(/,/g, '.');
  return /^-?\d+(\.\d+)?$/.test(v) ? Number(v) : null;
}

export function matches(operator: string, raw: unknown, target: string): boolean {
  const value = normalize(raw);
  const isEmpty = Array.isArray(value) ? value.length === 0 : value.trim() === '';
  const needle = fold(target);
  const items = Array.isArray(value) ? value : [value];

  switch (operator) {
    case 'empty':
      return isEmpty;
    case 'notempty':
      return !isEmpty;
    case 'is':
    case 'isnot': {
      const hit = items.some((item) => fold(item) === needle);
      return operator === 'is' ? hit : !hit;
    }
    case 'contains':
      return items.some((item) => fold(item).includes(needle));
    case 'gt':
    case 'lt': {
      if (Array.isArray(value)) return false;
      const left = toNumber(value);
      const right = toNumber(target);
      if (left === null || right === null) return false;
      return operator === 'gt' ? left > right : left < right;
    }
  }
  return false;
}
