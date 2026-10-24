<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useBuilderStore } from '@/stores/builder';

/** Shows the real PHP output (theme + frontend script) of the current draft in an iframe. */
const open = defineModel<boolean>({ required: true });
const { t } = useI18n();
const store = useBuilderStore();
const url = ref('');
const width = ref<'100%' | '768px' | '390px'>('100%');
const loading = ref(false);

watch(open, async (value) => {
  if (!value) return;
  loading.value = true;
  url.value = '';
  const previewUrl = (await store.saveDraft()) ?? store.meta?.previewUrl ?? '';
  // Cache busting: the draft just changed.
  url.value = previewUrl ? `${previewUrl}&t=${Date.now()}` : '';
});
</script>

<template>
  <q-dialog v-model="open" maximized transition-show="fade" transition-hide="fade">
    <q-card class="swf-preview-dialog">
      <header class="swf-preview-dialog__bar">
        <strong>{{ t('preview.title') }}</strong>
        <span class="swf-muted swf-small">{{ t('preview.hint') }}</span>
        <q-btn-toggle
          v-model="width"
          dense
          no-caps
          unelevated
          toggle-color="primary"
          :options="[
            { value: '100%', icon: 'r_desktop_windows', attrs: { 'aria-label': t('preview.desktop') } },
            { value: '768px', icon: 'r_tablet_mac', attrs: { 'aria-label': t('preview.tablet') } },
            { value: '390px', icon: 'r_smartphone', attrs: { 'aria-label': t('preview.mobile') } },
          ]"
        />
        <q-btn flat round dense icon="r_close" :aria-label="t('common.close')" @click="open = false" />
      </header>
      <div class="swf-preview-dialog__stage">
        <q-spinner v-if="loading" size="36px" color="primary" class="swf-preview-dialog__spinner" />
        <iframe v-if="url" :src="url" :style="{ width }" :title="t('preview.title')" @load="loading = false" />
      </div>
    </q-card>
  </q-dialog>
</template>
