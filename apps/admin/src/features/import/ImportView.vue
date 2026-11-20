<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { importApi } from '@/api';
import { isApiError } from '@/api/client';
import { useAsyncData } from '@/composables/useAsyncData';
import { useCapabilities } from '@/composables/useCapabilities';
import { useNotify } from '@/composables/useNotify';
import PageHeader from '@/components/ui/PageHeader.vue';
import SwfCard from '@/components/ui/SwfCard.vue';
import NoAccess from '@/layouts/NoAccess.vue';
import type { ImportAdapter, ImportReport, ImportSource } from '@/types/api';

const { t, te } = useI18n();
const { can } = useCapabilities();
const notify = useNotify();
const allowed = can('swf_manage_forms');
const { data: adapters, loading, error, load } = useAsyncData(importApi.catalog, { immediate: allowed });
const selected = ref<ImportAdapter | null>(null);
const sources = ref<ImportSource[]>([]);
const page = ref(1);
const busy = ref(false);
const sourceId = ref<number | null>(null);
const report = ref<ImportReport | null>(null);
const importedId = ref<number | null>(null);
const failure = ref('');

function feature(code: string): string {
  return te(`importer.features.${code}`) ? t(`importer.features.${code}`) : code;
}

async function choose(adapter: ImportAdapter, nextPage = 1): Promise<void> {
  if (busy.value) return;
  selected.value = adapter;
  page.value = nextPage;
  sources.value = [];
  report.value = null;
  importedId.value = null;
  failure.value = '';
  busy.value = true;
  try {
    sources.value = await importApi.forms(adapter.slug, nextPage);
  } catch (e) {
    failure.value = e instanceof Error ? e.message : t('errors.generic');
  } finally {
    busy.value = false;
  }
}

async function preview(id: number): Promise<void> {
  if (!selected.value || busy.value) return;
  busy.value = true;
  failure.value = '';
  report.value = null;
  importedId.value = null;
  sourceId.value = id;
  try {
    report.value = await importApi.preview(selected.value.slug, id);
  } catch (e) {
    failure.value = e instanceof Error ? e.message : t('errors.generic');
    // Even a rejected/empty import exposes its skipped fields for inspection.
    if (isApiError(e) && e.data.report) report.value = e.data.report as ImportReport;
  } finally {
    busy.value = false;
  }
}

async function confirm(): Promise<void> {
  if (!selected.value || sourceId.value === null || !report.value?.fingerprint || busy.value || importedId.value) return;
  busy.value = true;
  failure.value = '';
  try {
    const result = await importApi.import(selected.value.slug, sourceId.value, report.value.fingerprint);
    importedId.value = result.id;
    report.value = result.report;
    notify.success(t('importer.saved'));
  } catch (e) {
    failure.value = e instanceof Error ? e.message : t('errors.generic');
    // A changed source requires another dry run before the confirmation button reappears.
    if (isApiError(e) && e.status === 409) report.value = null;
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <NoAccess v-if="!allowed" />
  <div v-else class="swf-stack">
    <PageHeader :title="t('nav.import')" :subtitle="t('importer.subtitle')" />
    <q-skeleton v-if="loading" type="rect" height="180px" />
    <SwfCard v-else-if="error" :title="t('errors.loadFailed')">
      <p role="alert">{{ error.message }}</p>
      <q-btn outline no-caps :label="t('common.retry')" @click="load" />
    </SwfCard>
    <template v-else>
      <SwfCard v-for="adapter in adapters" :key="adapter.slug" :title="adapter.name" class="import-adapter">
        <p><strong>{{ t('importer.supports') }}</strong> {{ adapter.imports.map(feature).join(', ') }}</p>
        <p><strong>{{ t('importer.omits') }}</strong> {{ adapter.omits.map(feature).join(', ') }}</p>
        <p v-if="!adapter.available" class="swf-muted">{{ t('importer.unavailable') }}</p>
        <q-btn v-else outline no-caps :disable="busy" :label="t('importer.choose')" :data-adapter="adapter.slug" @click="choose(adapter)" />
      </SwfCard>
      <p v-if="adapters?.length === 0">{{ t('importer.noAdapters') }}</p>
      <SwfCard v-if="selected" :title="selected.name" :subtitle="t('importer.sources')">
        <q-linear-progress v-if="busy" indeterminate :aria-label="t('importer.loading')" />
        <ul class="import-sources">
          <li v-for="source in sources" :key="source.id">
            <span>{{ source.title }} (#{{ source.id }})</span>
            <q-btn outline no-caps :disable="busy" :label="t('importer.preview')" :data-source="source.id" @click="preview(source.id)" />
          </li>
        </ul>
        <p v-if="!busy && sources.length === 0">{{ t('importer.empty') }}</p>
        <div class="swf-row">
          <q-btn v-if="page > 1" flat no-caps :disable="busy" :label="t('importer.previous')" @click="choose(selected, page - 1)" />
          <q-btn v-if="sources.length === 50" flat no-caps :disable="busy" :label="t('importer.next')" data-import-next @click="choose(selected, page + 1)" />
        </div>
        <q-btn v-if="failure && !report" flat no-caps :disable="busy" :label="t('common.retry')" @click="choose(selected, page)" />
      </SwfCard>
      <p v-if="failure" role="alert">{{ failure }}</p>
      <SwfCard v-if="report" :title="report.title" :subtitle="t('importer.report')" data-import-report>
        <div class="import-report">
          <p class="import-report__notice">
            <q-icon name="r_info" size="20px" aria-hidden="true" />
            <span>{{ t('importer.review') }}</span>
          </p>
          <div class="import-report__fields">
            <section class="import-report__section" aria-labelledby="import-transferred">
              <h3 id="import-transferred" class="import-report__heading">
                <q-icon name="r_check_circle" size="18px" class="import-report__success" aria-hidden="true" />
                <span>{{ t('importer.imported') }}</span>
                <span class="import-report__count">{{ report.imported.length }}</span>
              </h3>
              <ul class="import-report__list import-report__list--fields"><li v-for="(item, index) in report.imported" :key="index">{{ item }}</li></ul>
            </section>
            <section class="import-report__section" aria-labelledby="import-skipped">
              <h3 id="import-skipped" class="import-report__heading">
                <q-icon :name="report.skipped.length ? 'r_info' : 'r_check_circle'" size="18px" :class="report.skipped.length ? 'import-report__warning' : 'import-report__success'" aria-hidden="true" />
                <span>{{ t('importer.skipped') }}</span>
                <span class="import-report__count">{{ report.skipped.length }}</span>
              </h3>
              <p v-if="report.skipped.length === 0" class="import-report__empty">{{ t('importer.none') }}</p>
              <ul v-else class="import-report__list"><li v-for="(item, index) in report.skipped" :key="index">{{ item }}</li></ul>
            </section>
          </div>
          <section class="import-report__omissions" aria-labelledby="import-omissions">
            <h3 id="import-omissions" class="import-report__heading">{{ t('importer.omits') }}</h3>
            <ul class="import-report__list import-report__list--omissions"><li v-for="(item, index) in report.warnings" :key="index">{{ feature(item) }}</li></ul>
          </section>
        </div>
        <template #footer>
          <div class="import-report__actions">
            <p v-if="importedId" role="status" class="import-report__success">{{ t('importer.saved') }}</p>
            <q-btn v-if="importedId" color="primary" no-caps :to="`/forms/${importedId}/build`" :label="t('common.edit')" />
            <q-btn v-else-if="report.fingerprint" color="primary" no-caps :loading="busy" :label="t('importer.confirm')" data-confirm-import @click="confirm" />
          </div>
        </template>
      </SwfCard>
    </template>
  </div>
</template>

<style scoped>
.import-adapter p { margin-bottom: 12px; line-height: 1.6; }
.import-sources { padding: 0; list-style: none; }
.import-sources li { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 10px 0; border-bottom: 1px solid var(--swf-border); }
.import-sources li span { overflow-wrap: anywhere; }
.import-report { display: grid; gap: 24px; }
.import-report__notice { display: flex; align-items: flex-start; gap: 10px; padding: 14px 16px; border-radius: var(--swf-radius-sm); background: var(--swf-primary-bg); color: var(--swf-primary-text); font-size: 13px; line-height: 1.6; }
.import-report__notice .q-icon { flex: none; margin-top: 1px; }
.import-report__fields { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 24px; }
.import-report__section { min-width: 0; }
.import-report__heading { display: flex; align-items: center; gap: 8px; margin: 0 0 12px; font-size: 14px; font-weight: 650; line-height: 1.5; }
.import-report__heading > span:not(.import-report__count) { flex: 1; min-width: 0; }
.import-report__heading .q-icon { flex: none; }
.import-report__count { display: inline-flex; align-items: center; justify-content: center; flex: none; min-width: 24px; padding: 2px 7px; border-radius: 6px; background: var(--swf-surface-3); color: var(--swf-muted); font-size: 12px; font-weight: 600; }
.import-report__list { list-style: none; margin: 0; padding: 0; font-size: 13px; line-height: 1.6; }
.import-report__list li { padding: 9px 0; border-bottom: 1px solid var(--swf-border); overflow-wrap: anywhere; }
.import-report__list li:last-child { border-bottom: 0; }
.import-report__list--fields { font-family: var(--swf-mono); }
.import-report__empty { padding: 9px 0; color: var(--swf-muted); font-size: 13px; }
.import-report__omissions { padding: 16px; border: 1px solid var(--swf-border); border-radius: var(--swf-radius-sm); background: var(--swf-surface-2); }
.import-report__list--omissions { color: var(--swf-muted); }
.import-report__list--omissions li { position: relative; padding: 4px 0 4px 14px; border: 0; }
.import-report__list--omissions li::before { content: '·'; position: absolute; left: 0; font-weight: 700; }
.import-report__actions { display: flex; justify-content: flex-end; align-items: center; gap: 16px; flex-wrap: wrap; }
.import-report__success { color: var(--swf-success-text); }
.import-report__warning { color: var(--swf-warning-text); }
@media (max-width: 700px) {
  .import-report__fields { grid-template-columns: minmax(0, 1fr); }
  .import-report__actions { align-items: stretch; flex-direction: column; }
  .import-sources li { align-items: flex-start; flex-direction: column; gap: 8px; }
}
</style>
