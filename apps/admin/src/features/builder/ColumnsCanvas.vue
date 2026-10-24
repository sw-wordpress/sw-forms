<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ElementNode } from '@shared/types';
import { useBuilderStore } from '@/stores/builder';
import CanvasList from './CanvasList.vue';
import { resizeColumn } from './document';

const props = defineProps<{ element: ElementNode }>();
const { t } = useI18n();
const store = useBuilderStore();
const grid = ref<HTMLElement | null>(null);

const columns = computed(() => props.element.columns ?? []);
const tracks = computed(() => columns.value.map((c) => `${c.width ?? 6}fr`).join(' '));

/**
 * Drag the handle between two columns: the pointer distance is converted into 12-grid units
 * and moved from the right to the left column (each column keeps at least one unit).
 */
function startResize(index: number, event: PointerEvent): void {
  const gridEl = grid.value;
  if (!gridEl) return;
  event.preventDefault();
  const unit = gridEl.getBoundingClientRect().width / 12;
  const startX = event.clientX;
  let applied = 0;
  const handle = event.currentTarget as HTMLElement;
  handle.setPointerCapture(event.pointerId);
  const coalesce = `${props.element.id}:resize:${Date.now()}`;

  const onMove = (e: PointerEvent): void => {
    const units = Math.round((e.clientX - startX) / unit);
    if (units !== applied) {
      const step = units - applied;
      store.columnsChange(props.element.id, (node) => resizeColumn(node, index, step), coalesce);
      applied = units;
    }
  };
  const onUp = (): void => {
    handle.removeEventListener('pointermove', onMove);
    handle.removeEventListener('pointerup', onUp);
    handle.removeEventListener('pointercancel', onUp);
  };
  handle.addEventListener('pointermove', onMove);
  handle.addEventListener('pointerup', onUp);
  handle.addEventListener('pointercancel', onUp);
}

function onHandleKey(index: number, e: KeyboardEvent): void {
  const step = e.key === 'ArrowLeft' ? -1 : e.key === 'ArrowRight' ? 1 : 0;
  if (step === 0) return;
  e.preventDefault();
  store.columnsChange(props.element.id, (node) => resizeColumn(node, index, step));
}
</script>

<template>
  <div ref="grid" class="swf-cols" :style="{ gridTemplateColumns: tracks }">
    <template v-for="(column, index) in columns" :key="column.id">
      <div
        class="swf-col-box"
        :class="{ 'swf-col-box--selected': store.selectedId === column.id }"
        @click.stop="store.select(column.id)"
      >
        <div class="swf-col-box__head">
          <span>{{ t('canvas.column', { n: index + 1 }) }} · <span class="swf-num">{{ column.width }}/12</span></span>
          <q-icon v-if="(column.logic?.rules.length ?? 0) > 0" name="r_alt_route" size="14px" :aria-label="t('canvas.conditional')" />
        </div>
        <CanvasList :list-id="column.id" :elements="column.children" />
        <span
          v-if="index < columns.length - 1"
          class="swf-col-resize swf-no-drag"
          role="separator"
          tabindex="0"
          aria-orientation="vertical"
          :aria-valuenow="column.width"
          aria-valuemin="1"
          aria-valuemax="11"
          :aria-label="t('canvas.resizeColumn', { n: index + 1 })"
          @pointerdown.stop="startResize(index, $event)"
          @click.stop
          @keydown="onHandleKey(index, $event)"
        />
      </div>
    </template>
  </div>
</template>
