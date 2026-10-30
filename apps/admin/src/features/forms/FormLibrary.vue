<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { formsApi } from '@/api';
import { useNotify } from '@/composables/useNotify';
import type { FormDocument } from '@/types/api';
const { t } = useI18n();
const router = useRouter();
const notify = useNotify();
const open = ref(false);
const busy = ref(false);
const templates = ref<{ id: string; title: string; document: FormDocument }[]>([]);
async function show(): Promise<void> {
  open.value = true;
  try { templates.value = await formsApi.templates(); } catch (error) { notify.error(error); }
}
async function importData(data: unknown): Promise<void> {
  busy.value = true;
  try {
    const result = await formsApi.import(data);
    open.value = false;
    await router.push(`/forms/${result.id}`);
  } catch (error) { notify.error(error); } finally { busy.value = false; }
}
async function file(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const selected = input.files?.[0];
  if (!selected) return;
  try {
    if (selected.size > 1048576) throw new Error(t('m5.importLimit'));
    await importData(JSON.parse(await selected.text()));
  } catch (error) { notify.error(error); } finally { input.value = ''; }
}
</script>
<template>
  <q-btn outline no-caps icon="r_library_books" :label="t('m5.library')" @click="show" />
  <q-dialog v-model="open">
    <q-card class="swf-dialog">
      <q-card-section><h2>{{ t('m5.library') }}</h2><p>{{ t('m5.importNotice') }}</p></q-card-section>
      <q-card-section class="swf-stack">
        <label>{{ t('m5.import') }} <input type="file" accept="application/json,.json" :disabled="busy" @change="file"></label>
        <q-btn v-for="item in templates" :key="item.id" outline no-caps :label="item.title" :disable="busy" @click="importData({ format: 'swforms', formatVersion: 1, title: item.title, document: item.document })" />
      </q-card-section>
      <q-card-actions align="right"><q-btn v-close-popup flat :label="t('common.close')" /></q-card-actions>
    </q-card>
  </q-dialog>
</template>
