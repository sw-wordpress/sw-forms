<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useBuilderStore } from '@/stores/builder';
import CanvasList from './CanvasList.vue';
import StepTabs from './StepTabs.vue';
import { ROOT, stepElements, stepListId } from './document';

const { t } = useI18n();
const store = useBuilderStore();

/** Multi-step mode shows one step (between page breaks) at a time. */
const list = computed(() => {
  void store.revision;
  const fields = store.document?.fields ?? [];
  if (!store.stepsEnabled) return { id: ROOT, elements: fields };
  return { id: stepListId(store.activeStep), elements: stepElements(fields, store.activeStep) };
});
const lastStep = computed(() => !store.stepsEnabled || store.activeStep >= store.steps.length - 1);
</script>

<template>
  <section class="swf-canvas" :aria-label="t('canvas.title')" @click.self="store.select(null)">
    <div class="swf-canvas__paper" :class="{ 'swf-canvas__paper--steps': store.stepsEnabled }" @click.self="store.select(null)">
      <StepTabs v-if="store.stepsEnabled" />
      <div v-if="store.document" :role="store.stepsEnabled ? 'tabpanel' : undefined">
        <CanvasList :key="list.id" :list-id="list.id" :elements="list.elements" root />
      </div>
      <div class="swf-canvas__submit" aria-hidden="true">
        <span v-if="store.stepsEnabled && store.activeStep > 0" class="swf-fake-button swf-fake-button--outline">{{ t('steps.back') }}</span>
        <span v-if="!lastStep" class="swf-fake-button">{{ t('steps.next') }}</span>
        <span v-else class="swf-fake-button">{{ store.document?.settings.submitLabel }}</span>
      </div>
    </div>
  </section>
</template>
