<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useBuilderStore } from '@/stores/builder';
import CanvasList from './CanvasList.vue';
import { ROOT } from './document';

const { t } = useI18n();
const store = useBuilderStore();
</script>

<template>
  <section class="swf-canvas" :aria-label="t('canvas.title')" @click.self="store.select(null)">
    <div class="swf-canvas__paper" @click.self="store.select(null)">
      <CanvasList v-if="store.document" :list-id="ROOT" :elements="store.document.fields" root />
      <div class="swf-canvas__submit" aria-hidden="true">
        <span class="swf-fake-button">{{ store.document?.settings.submitLabel }}</span>
      </div>
    </div>
  </section>
</template>
