import type { ElementNode } from '@shared/types';
import type { FormDocument } from '@/types/api';

/** Field and step counts shown on a template card. */
export function templateSummary(document: FormDocument): { fields: number; steps: number } {
  let fields = 0;
  const count = (list: ElementNode[]): void => {
    for (const node of list) {
      if (node.type !== 'page-break') fields++;
      count(node.children ?? []);
      node.columns?.forEach((column) => count(column.children));
    }
  };
  count(document.fields);
  const breaks = document.fields.filter((node) => node.type === 'page-break').length;
  return { fields, steps: document.settings?.steps?.enabled === false ? 1 : breaks + 1 };
}
