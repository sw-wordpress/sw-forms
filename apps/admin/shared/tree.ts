import type { ElementNode, TypeCatalog } from './types';

export const COLUMN_TYPE = '@column';

/**
 * Index over a form's element tree. TypeScript twin of src/Core/Form/Tree.php.
 *
 * Columns are indexed as pseudo nodes of type `@column`. The scope of a node is the id of the
 * nearest enclosing repeatable group (null = form level); a repeater belongs to the scope it
 * is placed in.
 */
export class Tree {
  private readonly nodes = new Map<string, ElementNode>();
  private readonly parents = new Map<string, string | null>();
  private readonly scopes = new Map<string, string | null>();
  private readonly children = new Map<string, string[]>();
  readonly rootIds: string[];

  constructor(
    elements: ElementNode[],
    private readonly types: TypeCatalog,
  ) {
    this.rootIds = this.add(elements, null, null);
  }

  private add(elements: unknown[], parent: string | null, scope: string | null): string[] {
    const ids: string[] = [];
    for (const raw of elements) {
      if (!raw || typeof raw !== 'object') continue;
      const element = raw as ElementNode;
      if (typeof element.id !== 'string' || element.id === '' || this.nodes.has(element.id)) {
        continue;
      }
      const id = element.id;
      this.nodes.set(id, element);
      this.parents.set(id, parent);
      this.scopes.set(id, scope);
      this.children.set(id, []);
      ids.push(id);

      const container = this.types.container(element.type);
      if (container === 'children') {
        const childScope = Tree.repeatable(element) ? id : scope;
        this.children.set(id, this.add(element.children ?? [], id, childScope));
      } else if (container === 'columns') {
        const columns = (element.columns ?? []).map((c) => ({ ...c, type: COLUMN_TYPE }));
        this.children.set(id, this.add(columns, id, scope));
      } else if (element.type === COLUMN_TYPE) {
        this.children.set(id, this.add(element.children ?? [], id, scope));
      }
    }
    return ids;
  }

  static repeatable(element: ElementNode): boolean {
    return element.settings?.repeatable === true;
  }

  has(id: string): boolean {
    return this.nodes.has(id);
  }

  node(id: string): ElementNode | undefined {
    return this.nodes.get(id);
  }

  ids(): string[] {
    return [...this.nodes.keys()];
  }

  childIds(id: string): string[] {
    return this.children.get(id) ?? [];
  }

  parentOf(id: string): string | null {
    return this.parents.get(id) ?? null;
  }

  scopeOf(id: string): string | null {
    return this.scopes.get(id) ?? null;
  }

  type(id: string): string {
    return this.nodes.get(id)?.type ?? '';
  }

  isColumn(id: string): boolean {
    return this.type(id) === COLUMN_TYPE;
  }

  isData(id: string): boolean {
    return !this.isColumn(id) && this.types.isData(this.type(id));
  }

  isRepeater(id: string): boolean {
    const node = this.nodes.get(id);
    return !!node && this.types.container(node.type) === 'children' && Tree.repeatable(node);
  }

  isLeafData(id: string): boolean {
    return this.isData(id) && this.types.container(this.type(id)) === '';
  }

  isDescendant(id: string, ancestor: string): boolean {
    let current = this.parentOf(id);
    while (current !== null) {
      if (current === ancestor) return true;
      current = this.parentOf(current);
    }
    return false;
  }

  /** Whether a node in scope `inner` can see values of scope `outer`. */
  scopeEncloses(outer: string | null, inner: string | null): boolean {
    let current = inner;
    for (;;) {
      if (current === outer) return true;
      if (current === null) return false;
      current = this.scopeOf(current);
    }
  }
}
