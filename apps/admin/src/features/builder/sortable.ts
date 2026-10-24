import Sortable from 'sortablejs';
import type { SortableEvent } from 'sortablejs';
import { onBeforeUnmount, onMounted, type Ref } from 'vue';

export const SORTABLE_GROUP = 'swf-elements';

interface ListOptions {
  /** Id of the list (`root`, group id or column id), also set as `data-list-id` on the element. */
  listId: () => string;
  onMove: (fromList: string, fromIndex: number, toList: string, toIndex: number) => void;
}

type AddHandler = (type: string, toList: string, toIndex: number) => void;

/**
 * Restores the DOM after a drop so that Vue stays the only one rendering: the model change
 * (store) then re-renders the list. Sortable reports draggable indexes (ignoring non-items).
 */
function revert(evt: SortableEvent): void {
  const { item, from, oldIndex } = evt;
  item.remove();
  from.insertBefore(item, from.children[oldIndex ?? 0] ?? null);
}

/** Canvas list (form level, group children or column children). */
export function useSortableList(el: Ref<HTMLElement | null>, options: ListOptions): void {
  let sortable: Sortable | null = null;
  onMounted(() => {
    if (!el.value) return;
    sortable = Sortable.create(el.value, {
      group: { name: SORTABLE_GROUP, pull: true, put: true },
      draggable: '.swf-el',
      // The whole card is the handle; toolbar buttons and resize handles opt out.
      filter: '.swf-no-drag',
      preventOnFilter: false,
      animation: 160,
      fallbackOnBody: true,
      swapThreshold: 0.6,
      emptyInsertThreshold: 24,
      ghostClass: 'swf-el--ghost',
      chosenClass: 'swf-el--chosen',
      dragClass: 'swf-el--drag',
      onEnd: (evt) => {
        if (evt.pullMode === 'clone') return;
        const fromList = (evt.from as HTMLElement).dataset.listId ?? '';
        const toList = (evt.to as HTMLElement).dataset.listId ?? '';
        const fromIndex = evt.oldDraggableIndex ?? 0;
        const toIndex = evt.newDraggableIndex ?? 0;
        if (fromList === toList && fromIndex === toIndex) return;
        revert(evt);
        options.onMove(fromList, fromIndex, toList, toIndex);
      },
    });
    el.value.dataset.listId = options.listId();
  });
  onBeforeUnmount(() => sortable?.destroy());
}

/** Palette: items are cloned into canvas lists; `data-type` names the element type. */
export function useSortablePalette(el: Ref<HTMLElement | null>, onAdd: AddHandler): void {
  let sortable: Sortable | null = null;
  onMounted(() => {
    if (!el.value) return;
    sortable = Sortable.create(el.value, {
      group: { name: SORTABLE_GROUP, pull: 'clone', put: false },
      sort: false,
      draggable: '.swf-palette__item',
      animation: 160,
      fallbackOnBody: true,
      ghostClass: 'swf-el--ghost',
      onEnd: (evt) => {
        const target = evt.to as HTMLElement;
        if (target === evt.from) return;
        const type = (evt.item as HTMLElement).dataset.type ?? '';
        const toList = target.dataset.listId ?? '';
        const toIndex = evt.newDraggableIndex ?? 0;
        // Put the original palette item back where Vue expects it and drop the clone.
        evt.item.remove();
        evt.clone.replaceWith(evt.item);
        if (type !== '' && toList !== '') onAdd(type, toList, toIndex);
      },
    });
  });
  onBeforeUnmount(() => sortable?.destroy());
}
