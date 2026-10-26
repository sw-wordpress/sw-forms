import type { Tree } from '@shared/tree';

/** A field a webhook mapping can use: data elements outside repeaters (same rule as the server). */
export interface MappableField {
  value: string;
  label: string;
}

export function mappableFields(tree: Tree): MappableField[] {
  return tree
    .ids()
    .filter((id) => tree.isData(id) && tree.scopeOf(id) === null)
    .map((id) => ({ value: id, label: pathOf(tree, id, (node) => node.adminLabel || node.label || node.key || id).join(' › ') }));
}

/** Default target name of a field: its data path, e.g. `address.city`. */
export function targetName(tree: Tree, id: string): string {
  return pathOf(tree, id, (node) => node.key ?? '').join('.');
}

function pathOf(tree: Tree, id: string, part: (node: NonNullable<ReturnType<Tree['node']>>) => string): string[] {
  const parts: string[] = [];
  let current: string | null = id;
  while (current !== null) {
    const node = tree.node(current);
    if (node && tree.isData(current)) parts.unshift(part(node));
    current = tree.parentOf(current);
  }
  return parts;
}

export function hostOf(url: string): string {
  try {
    return new URL(url).host;
  } catch {
    return url;
  }
}

/** 32 random bytes as hex: the signing secret shared with the receiver. */
export function generateSecret(): string {
  return Array.from(crypto.getRandomValues(new Uint8Array(32)), (b) => b.toString(16).padStart(2, '0')).join('');
}
