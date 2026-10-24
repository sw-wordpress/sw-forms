<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ElementNode } from '@shared/types';
import { useBuilderStore } from '@/stores/builder';
import { ROOT } from './document';
import { useSortablePalette } from './sortable';

const { t, te } = useI18n();
const store = useBuilderStore();
const search = ref('');
const listEl = ref<HTMLElement | null>(null);

const ORDER = ['basic', 'choice', 'layout'];
const groups = computed(() => {
  const q = search.value.trim().toLowerCase();
  const byCategory = new Map<string, typeof store.types>();
  for (const type of store.types) {
    if (q !== '' && !type.label.toLowerCase().includes(q)) continue;
    const list = byCategory.get(type.category) ?? [];
    list.push(type);
    byCategory.set(type.category, list);
  }
  return [...byCategory.entries()].sort(
    ([a], [b]) => (ORDER.indexOf(a) + 1 || 99) - (ORDER.indexOf(b) + 1 || 99),
  );
});

function categoryLabel(category: string): string {
  const key = `palette.categories.${category}`;
  return te(key) ? t(key) : category;
}

useSortablePalette(listEl, (type, listId, index) => {
  store.addElement(type, listId, index);
});

/**
 * Click / Enter adds the element: into the selected group or column, otherwise after the
 * selected element, otherwise at the end of the form.
 */
function add(type: string): void {
  const selected = store.selected;
  const document = store.document;
  if (!document) return;
  if (selected) {
    const node = selected.node as ElementNode;
    const isGroup = !selected.isColumn && Array.isArray(node.children) && !node.columns;
    if (selected.isColumn || isGroup) {
      store.addElement(type, node.id, node.children?.length ?? 0);
      return;
    }
    store.addElement(type, selected.listId, selected.index + 1);
    return;
  }
  store.addElement(type, ROOT, document.fields.length);
}
</script>

<template>
  <aside class="swf-palette" :aria-label="t('palette.title')">
    <div class="swf-panel-head">
      <h2 class="swf-panel-title">{{ t('palette.title') }}</h2>
    </div>
    <q-input v-model="search" dense outlined clearable class="swf-palette__search" :placeholder="t('palette.search')" :aria-label="t('palette.search')">
      <template #prepend><q-icon name="r_search" size="18px" /></template>
    </q-input>
    <p class="swf-palette__hint">{{ t('palette.hint') }}</p>
    <div ref="listEl" class="swf-palette__list">
      <template v-for="[category, items] in groups" :key="category">
        <p class="swf-eyebrow swf-palette__category">{{ categoryLabel(category) }}</p>
        <button
          v-for="type in items"
          :key="type.type"
          type="button"
          class="swf-palette__item"
          :data-type="type.type"
          :title="t('palette.addHint', { type: type.label })"
          @click="add(type.type)"
        >
          <q-icon :name="type.icon || 'r_widgets'" size="20px" aria-hidden="true" />
          <span>{{ type.label }}</span>
        </button>
      </template>
    </div>
  </aside>
</template>
