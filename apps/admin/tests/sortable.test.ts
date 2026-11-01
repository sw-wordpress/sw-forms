/* eslint-disable vue/one-component-per-file -- inline host components for the composables */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { mount } from '@vue/test-utils';
import Sortable from 'sortablejs';
import type { Options, SortableEvent } from 'sortablejs';
import { SORTABLE_GROUP, useSortableList, useSortablePalette } from '../src/features/builder/sortable';

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

describe('palette edge cases', () => {
  function palette(withElement = true) {
    let options: Options = {};
    const create = vi.spyOn(Sortable, 'create').mockImplementation((_element, settings) => { options = settings ?? {}; return { destroy: vi.fn() } as unknown as Sortable; });
    const add = vi.fn();
    const wrapper = mount(defineComponent({ setup() { const element = ref<HTMLElement | null>(null); useSortablePalette(element, add); return () => withElement ? h('div', { ref: element }, [h('button', { class: 'swf-palette__item' })]) : h('span'); } }));
    return { wrapper, add, create, options: () => options };
  }

  it('configures a clone-only group', () => {
    const { options } = palette();
    expect(options().group).toEqual({ name: SORTABLE_GROUP, pull: 'clone', put: false });
    expect(options().sort).toBe(false);
    expect(options().draggable).toBe('.swf-palette__item');
  });

  it('ignores drops back onto the palette and items without type or list', () => {
    const { wrapper, add, options } = palette();
    const source = wrapper.element as HTMLElement;
    const item = source.firstElementChild as HTMLElement;
    options().onEnd?.({ from: source, to: source, item, clone: item, newDraggableIndex: 0 } as unknown as SortableEvent);
    const target = document.createElement('div');
    target.appendChild(item);
    const clone = item.cloneNode() as HTMLElement; source.appendChild(clone);
    options().onEnd?.({ from: source, to: target, item, clone } as unknown as SortableEvent);
    expect(add).not.toHaveBeenCalled();
    expect(source.firstElementChild).toBe(item);
  });

  it('does nothing without an element', () => {
    const { wrapper, create } = palette(false);
    expect(create).not.toHaveBeenCalled();
    wrapper.unmount();
  });
});

describe('canvas lists', () => {
  function list(listId = 'root', withElement = true) {
    let options: Options = {};
    const destroy = vi.fn();
    const create = vi.spyOn(Sortable, 'create').mockImplementation((_element, settings) => { options = settings ?? {}; return { destroy } as unknown as Sortable; });
    const onMove = vi.fn();
    const wrapper = mount(defineComponent({ setup() { const element = ref<HTMLElement | null>(null); useSortableList(element, { listId: () => listId, onMove }); return () => withElement ? h('div', { ref: element }, [h('div', { class: 'swf-el', id: 'one' }), h('div', { class: 'swf-el', id: 'two' })]) : h('span'); } }));
    return { wrapper, onMove, destroy, create, options: () => options };
  }

  it('sets the list id and shared group, and destroys Sortable on unmount', () => {
    const { wrapper, destroy, options } = list('group-1');
    expect((wrapper.element as HTMLElement).dataset.listId).toBe('group-1');
    expect(options().group).toEqual({ name: SORTABLE_GROUP, pull: true, put: true });
    expect(options().filter).toBe('.swf-no-drag');
    wrapper.unmount();
    expect(destroy).toHaveBeenCalledOnce();
  });

  it('reverts the DOM and reports moves between and within lists', () => {
    const { wrapper, onMove, options } = list('root');
    const from = wrapper.element as HTMLElement;
    const [one, two] = Array.from(from.children) as HTMLElement[];
    const to = document.createElement('div'); to.dataset.listId = 'col-1';
    to.appendChild(one!);
    options().onEnd?.({ from, to, item: one, oldIndex: 0, oldDraggableIndex: 0, newDraggableIndex: 0 } as unknown as SortableEvent);
    expect(onMove).toHaveBeenLastCalledWith('root', 0, 'col-1', 0);
    expect(Array.from(from.children)).toEqual([one, two]);
    expect(to.children).toHaveLength(0);

    from.insertBefore(two!, one!);
    options().onEnd?.({ from, to: from, item: two, oldIndex: 1, oldDraggableIndex: 1, newDraggableIndex: 0 } as unknown as SortableEvent);
    expect(onMove).toHaveBeenLastCalledWith('root', 1, 'root', 0);
    expect(Array.from(from.children)).toEqual([one, two]);

    // Missing indexes default to the first slot; lists without id report ''.
    const bare = document.createElement('div');
    from.removeChild(two!); bare.appendChild(two!);
    options().onEnd?.({ from: bare, to: from, item: two } as unknown as SortableEvent);
    expect(onMove).toHaveBeenLastCalledWith('', 0, 'root', 0);
    expect(bare.firstElementChild).toBe(two);
  });

  it('ignores clones and drops at the original position', () => {
    const { wrapper, onMove, options } = list();
    const from = wrapper.element as HTMLElement;
    const item = from.firstElementChild as HTMLElement;
    options().onEnd?.({ from, to: from, item, pullMode: 'clone', oldDraggableIndex: 0, newDraggableIndex: 1 } as unknown as SortableEvent);
    options().onEnd?.({ from, to: from, item, oldDraggableIndex: 1, newDraggableIndex: 1 } as unknown as SortableEvent);
    expect(onMove).not.toHaveBeenCalled();
  });

  it('does nothing without an element', () => {
    const { wrapper, create } = list('root', false);
    expect(create).not.toHaveBeenCalled();
    wrapper.unmount();
  });
});
