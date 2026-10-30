<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { formsApi } from '@/api';
import { useBuilderStore } from '@/stores/builder';
import { useNotify } from '@/composables/useNotify';
import { useFormat } from '@/composables/useFormat';
import SwfCard from '@/components/ui/SwfCard.vue';
const store = useBuilderStore();
const { t } = useI18n();
const { dateTime } = useFormat();
const notify = useNotify();
const items = ref<{ version: number; createdAt: string; createdBy: number }[]>([]);
const busy = ref(false);
const page = ref(1);
const document = ref('');
const selected = ref<number | null>(null);
async function load(): Promise<void> {
  try { items.value = await formsApi.versions(store.formId, page.value); } catch (error) { notify.error(error); }
}
watch(() => [store.formId, store.meta?.currentVersion, page.value], load, { immediate: true });
async function view(version: number): Promise<void> {
  try { document.value = JSON.stringify((await formsApi.version(store.formId, version)).document, null, 2); } catch (error) { notify.error(error); }
}
async function restore(): Promise<void> {
  if (selected.value === null || store.dirty) return;
  busy.value = true;
  try {
    await formsApi.restoreVersion(store.formId, selected.value);
    await store.load(store.formId);
    selected.value = null;
    notify.success(t('m5.restored'));
  } catch (error) { notify.error(error); } finally { busy.value = false; }
}
</script>
<template>
  <SwfCard :title="t('m5.history')">
    <p>{{ t('m5.historyNotice') }}</p>
    <div v-for="item in items" :key="item.version" class="swf-row">
      <span>#{{ item.version }} · {{ dateTime(item.createdAt) }}</span>
      <q-btn flat no-caps :label="t('m5.view')" @click="view(item.version)" />
      <q-btn flat no-caps :label="t('m5.restore')" :disable="store.dirty || busy" @click="selected = item.version" />
    </div>
    <q-btn flat :disable="page === 1" icon="r_chevron_left" :aria-label="t('m5.previousVersions')" @click="page--" />
    <q-btn flat :disable="items.length < 50" icon="r_chevron_right" :aria-label="t('m5.nextVersions')" @click="page++" />
  </SwfCard>
  <q-dialog :model-value="document !== ''" @update:model-value="document = ''"><q-card class="swf-dialog"><q-card-section><q-input :model-value="document" type="textarea" readonly autogrow :label="t('m5.versionDocument')" /></q-card-section><q-card-actions><q-btn v-close-popup flat :label="t('common.close')" /></q-card-actions></q-card></q-dialog>
  <q-dialog :model-value="selected !== null" @update:model-value="selected = null"><q-card class="swf-dialog"><q-card-section>{{ t('m5.confirmRestore') }}</q-card-section><q-card-actions><q-btn v-close-popup flat :label="t('common.cancel')" /><q-btn :loading="busy" :label="t('m5.restore')" @click="restore" /></q-card-actions></q-card></q-dialog>
</template>
