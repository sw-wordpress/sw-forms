import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { mount } from '@vue/test-utils';
import Sortable from 'sortablejs';
import type { Options, SortableEvent } from 'sortablejs';
import { useSortablePalette } from '../src/features/builder/sortable';

afterEach(() => { vi.restoreAllMocks(); document.body.replaceChildren(); });

describe('palette drops', () => {
  it.each([['root', 0], ['root', 2], ['root', 4], ['group', 1], ['column', 3]] as const)('inserts at %s index %i, ignoring non-card siblings', (list, position) => {
    let options: Options = {};
    const destroy = vi.fn();
    vi.spyOn(Sortable, 'create').mockImplementation((_element, settings) => { options = settings ?? {}; return { destroy } as unknown as Sortable; });
    const add = vi.fn();
    const wrapper = mount(defineComponent({ setup() { const element = ref<HTMLElement | null>(null); useSortablePalette(element, add); return () => h('div', { ref: element }, [h('button', { 'data-type': 'input', class: 'swf-palette__item' })]); } }));
    const source = wrapper.element as HTMLElement;
    const item = source.firstElementChild as HTMLElement;
    const clone = item.cloneNode(true) as HTMLElement; source.appendChild(clone);
    const target = document.createElement('div'); target.dataset.listId = list;
    target.innerHTML = '<p>Hint</p>' + '<div class="swf-el"></div>'.repeat(4);
    const cards = target.querySelectorAll('.swf-el'); target.insertBefore(item, cards[position] ?? null);
    options.onEnd?.({ from: source, to: target, item, clone, newDraggableIndex: 0 } as unknown as SortableEvent);
    expect(add).toHaveBeenCalledWith('input', list, position);
    expect(source.children).toHaveLength(1); expect(source.firstElementChild).toBe(item);
    expect(target.querySelectorAll('.swf-el')).toHaveLength(4);
    wrapper.unmount(); expect(destroy).toHaveBeenCalledOnce();
  });
});
