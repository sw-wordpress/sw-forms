<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ElementNode } from '@shared/types';
import { useBuilderStore } from '@/stores/builder';
import { addColumn, applyPreset, COLUMN_PRESETS, equalize, MAX_COLUMNS, removeColumn } from './document';

const props = defineProps<{ element: ElementNode }>();
const { t } = useI18n();
const store = useBuilderStore();
const columns = computed(() => props.element.columns ?? []);
const current = computed(() => columns.value.map((c) => c.width ?? 6).join('-'));

function setWidth(index: number, value: string | number | null): void {
  const width = Math.max(1, Math.min(12, Number(value) || 1));
  store.columnsChange(props.element.id, (node) => {
    const column = node.columns?.[index];
    if (column) column.width = width;
  }, `${props.element.id}:width:${index}`);
}
</script>

<template>
  <div class="swf-stack swf-stack--tight">
    <div>
      <p class="swf-setting-label">{{ t('columns.presets') }}</p>
      <div class="swf-presets">
        <button
          v-for="preset in COLUMN_PRESETS"
          :key="preset.join('-')"
          type="button"
          class="swf-preset"
          :class="{ 'swf-preset--active': current === preset.join('-') }"
          :aria-label="t('columns.preset', { widths: preset.join(' / ') })"
          :aria-pressed="current === preset.join('-')"
          @click="store.columnsChange(element.id, (node) => applyPreset(node, preset))"
        >
          <span v-for="(w, i) in preset" :key="i" :style="{ flex: w }" />
        </button>
      </div>
    </div>

    <div>
      <p class="swf-setting-label">{{ t('columns.widths') }}</p>
      <ul class="swf-col-widths">
        <li v-for="(column, index) in columns" :key="column.id">
          <q-input
            :model-value="column.width"
            type="number"
            dense
            outlined
            min="1"
            max="12"
            suffix="/12"
            :label="t('canvas.column', { n: index + 1 })"
            @update:model-value="setWidth(index, $event)"
          />
          <q-btn flat round dense size="sm" icon="r_tune" :aria-label="t('columns.selectColumn', { n: index + 1 })" @click="store.select(column.id)">
            <q-tooltip>{{ t('columns.selectColumn', { n: index + 1 }) }}</q-tooltip>
          </q-btn>
          <q-btn
            flat
            round
            dense
            size="sm"
            icon="r_close"
            :disable="columns.length <= 1"
            :aria-label="t('columns.remove', { n: index + 1 })"
            @click="store.columnsChange(element.id, (node) => removeColumn(node, column.id))"
          />
        </li>
      </ul>
      <div class="swf-row">
        <q-btn flat dense no-caps icon="r_add" :disable="columns.length >= MAX_COLUMNS" :label="t('columns.add')" @click="store.columnsChange(element.id, (node) => addColumn(node))" />
        <q-btn flat dense no-caps icon="r_view_week" :label="t('columns.equalize')" @click="store.columnsChange(element.id, (node) => equalize(node))" />
      </div>
      <p class="swf-muted swf-small">{{ t('columns.removeHint') }}</p>
    </div>
  </div>
</template>
