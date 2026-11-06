<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useBuilderStore } from '@/stores/builder';
import * as doc from './document';

const { t } = useI18n();
const store = useBuilderStore();

const active = computed(() => store.steps[store.activeStep]);
const last = computed(() => store.steps.length - 1);

/** Steps containing elements (or a break) with schema errors. */
const invalid = computed(() => {
  const out = new Set<number>();
  const fields = store.document?.fields ?? [];
  for (const id of store.errorsById.keys()) {
    const step = doc.stepOf(fields, id);
    if (step >= 0) out.add(step);
  }
  return out;
});

function title(index: number, label: string): string {
  return label.trim() !== '' ? label : t('steps.fallback', { n: index + 1 });
}

function onKey(event: KeyboardEvent, index: number): void {
  const delta = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
  if (delta === 0) return;
  event.preventDefault();
  const next = (index + delta + store.steps.length) % store.steps.length;
  store.showStep(next);
  (event.currentTarget as HTMLElement).parentElement?.querySelectorAll<HTMLElement>('[role="tab"]')[next]?.focus();
}
</script>

<template>
  <div class="swf-steps-editor">
    <div class="swf-steps-editor__bar">
      <div class="swf-steps-editor__tabs" role="tablist" :aria-label="t('steps.tabs')">
        <button
          v-for="step in store.steps"
          :key="step.breakId ?? 'first'"
          type="button"
          role="tab"
          class="swf-step-tab"
          :class="{ 'swf-step-tab--active': step.index === store.activeStep, 'swf-step-tab--error': invalid.has(step.index) }"
          :aria-selected="step.index === store.activeStep"
          :tabindex="step.index === store.activeStep ? 0 : -1"
          @click="store.showStep(step.index)"
          @keydown="onKey($event, step.index)"
        >
          <span class="swf-step-tab__num" aria-hidden="true">{{ step.index + 1 }}</span>
          <span class="swf-step-tab__label">{{ title(step.index, step.label) }}</span>
          <span class="swf-step-tab__count">{{ step.count }}</span>
          <q-icon v-if="invalid.has(step.index)" name="r_error" size="14px" :aria-label="t('canvas.hasErrors')" />
        </button>
      </div>
      <q-btn flat dense no-caps icon="r_add" class="swf-steps-editor__add" :label="t('steps.add')" @click="store.addStep(t('steps.fallback', { n: store.steps.length + 1 }))" />
    </div>

    <div v-if="active" class="swf-steps-editor__head">
      <q-input
        :model-value="active.label"
        dense
        outlined
        class="swf-steps-editor__title"
        :label="t('steps.title', { n: active.index + 1 })"
        :placeholder="t('steps.fallback', { n: active.index + 1 })"
        @update:model-value="store.renameStep(active.index, String($event ?? ''))"
      />
      <q-btn flat round dense icon="r_chevron_left" :disable="active.index === 0" :aria-label="t('steps.moveLeft')" @click="store.moveStep(active.index, -1)">
        <q-tooltip>{{ t('steps.moveLeft') }}</q-tooltip>
      </q-btn>
      <q-btn flat round dense icon="r_chevron_right" :disable="active.index === last" :aria-label="t('steps.moveRight')" @click="store.moveStep(active.index, 1)">
        <q-tooltip>{{ t('steps.moveRight') }}</q-tooltip>
      </q-btn>
      <q-btn flat round dense icon="r_delete" :disable="store.steps.length < 2" :aria-label="t('steps.remove')" @click="store.removeStep(active.index)">
        <q-tooltip>{{ t('steps.removeHint') }}</q-tooltip>
      </q-btn>
    </div>
  </div>
</template>
