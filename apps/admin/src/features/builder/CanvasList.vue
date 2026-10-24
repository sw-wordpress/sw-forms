<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ElementNode } from '@shared/types';
import { useBuilderStore } from '@/stores/builder';
import CanvasElement from './CanvasElement.vue';
import { useSortableList } from './sortable';

const props = withDefaults(defineProps<{ listId: string; elements: ElementNode[]; root?: boolean }>(), {
  root: false,
});
const { t } = useI18n();
const store = useBuilderStore();
const el = ref<HTMLElement | null>(null);

useSortableList(el, {
  listId: () => props.listId,
  onMove: (fromList, fromIndex, toList, toIndex) => store.moveElement(fromList, fromIndex, toList, toIndex),
});
</script>

<template>
  <div
    ref="el"
    class="swf-list"
    :class="{ 'swf-list--empty': elements.length === 0, 'swf-list--root': root }"
    :data-list-id="listId"
    :data-empty-text="root ? t('canvas.emptyRoot') : t('canvas.emptyList')"
  >
    <CanvasElement v-for="(element, index) in elements" :key="element.id" :element="element" :index="index" :list-id="listId" :count="elements.length" />
  </div>
</template>
