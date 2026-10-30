<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ElementNode } from '@shared/types';
import { useBuilderStore } from '@/stores/builder';
import CanvasList from './CanvasList.vue';
import ColumnsCanvas from './ColumnsCanvas.vue';
import { fieldUI } from '@/extensions/builder';
import ElementPreview from './ElementPreview.vue';

const props = defineProps<{ element: ElementNode; index: number; listId: string; count: number }>();
const { t } = useI18n();
const store = useBuilderStore();

const definition = computed(() => store.definition(props.element.type));
const selected = computed(() => store.selectedId === props.element.id);
const errors = computed(() => store.errorsById.get(props.element.id) ?? []);
const hasLogic = computed(() => (props.element.logic?.rules.length ?? 0) > 0);
const isGroup = computed(() => definition.value?.container === 'children');
const isColumns = computed(() => definition.value?.container === 'columns');
const repeatable = computed(() => props.element.settings?.repeatable === true);
const title = computed(
  () => props.element.adminLabel || props.element.label || definition.value?.label || props.element.type,
);

function select(): void {
  store.select(props.element.id);
}

function move(delta: number): void {
  store.moveElement(props.listId, props.index, props.listId, props.index + delta);
}

function onKey(e: KeyboardEvent): void {
  if (e.target !== e.currentTarget) return;
  if (e.key === 'Enter' || e.key === ' ') {
    e.preventDefault();
    select();
  } else if (e.altKey && e.key === 'ArrowUp' && props.index > 0) {
    e.preventDefault();
    move(-1);
  } else if (e.altKey && e.key === 'ArrowDown' && props.index < props.count - 1) {
    e.preventDefault();
    move(1);
  }
}
</script>

<template>
  <div
    class="swf-el"
    :class="{
      'swf-el--selected': selected,
      'swf-el--error': errors.length > 0,
      'swf-el--container': isGroup || isColumns,
      'swf-el--unknown': !definition,
    }"
    tabindex="0"
    role="group"
    :aria-label="t('canvas.elementLabel', { type: definition?.label ?? element.type, label: title })"
    :aria-current="selected ? 'true' : undefined"
    @click.stop="select"
    @keydown="onKey"
  >
    <div class="swf-el__bar">
      <span class="swf-el__type">
        <q-icon :name="definition?.icon ?? 'r_help'" size="16px" aria-hidden="true" />
        {{ definition?.label ?? element.type }}
      </span>
      <span v-if="element.key" class="swf-el__key swf-mono">{{ element.key }}</span>
      <span v-if="hasLogic" class="swf-el__badge swf-el__badge--logic">
        <q-icon name="r_alt_route" size="14px" aria-hidden="true" />{{ t('canvas.conditional') }}
      </span>
      <span v-if="repeatable" class="swf-el__badge"><q-icon name="r_repeat" size="14px" aria-hidden="true" />{{ t('canvas.repeatable') }}</span>
      <span v-if="errors.length > 0" class="swf-el__badge swf-el__badge--error" :title="errors.join('\n')">
        <q-icon name="r_error" size="14px" aria-hidden="true" />{{ t('canvas.hasErrors') }}
      </span>
      <span class="swf-el__tools swf-no-drag">
        <q-btn flat round dense size="sm" icon="r_arrow_upward" :disable="index === 0" :aria-label="t('canvas.moveUp')" @click.stop="move(-1)" />
        <q-btn flat round dense size="sm" icon="r_arrow_downward" :disable="index === count - 1" :aria-label="t('canvas.moveDown')" @click.stop="move(1)" />
        <q-btn flat round dense size="sm" icon="r_content_copy" :aria-label="t('common.duplicate')" @click.stop="store.duplicateElement(element.id)" />
        <q-btn flat round dense size="sm" icon="r_delete" :aria-label="t('common.delete')" @click.stop="store.removeElement(element.id)" />
      </span>
    </div>

    <div v-if="isGroup" class="swf-el__group">
      <p v-if="element.label" class="swf-el__legend">{{ element.label }}</p>
      <CanvasList :list-id="element.id" :elements="element.children ?? []" />
      <span v-if="repeatable" class="swf-fake-button swf-fake-button--outline">
        <q-icon name="r_add" size="16px" aria-hidden="true" />{{ String(element.settings?.addLabel ?? '') }}
      </span>
    </div>
    <ColumnsCanvas v-else-if="isColumns" :element="element" />
    <div v-else-if="fieldUI(element.type)?.canvas" inert>
      <component :is="fieldUI(element.type)?.canvas" :element="element" :settings="element.settings ?? {}" />
    </div>
    <ElementPreview v-else :element="element" :known="!!definition" />
  </div>
</template>
