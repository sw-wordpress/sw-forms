<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { formsApi } from '@/api';
import { useNotify } from '@/composables/useNotify';
import type { FormTemplate } from '@/types/api';
import { structureRows, widthKey, type StructureRow } from './templateBrowser';

/**
 * Detail view of one starter template: the real PHP form in an iframe (interactive, but the
 * demo form never sends or stores data), the complete field structure and the create action.
 */
const props = defineProps<{ cardId: string; open: boolean; creating: boolean }>();
const emit = defineEmits<{ (event: 'update:open', value: boolean): void; (event: 'back'): void; (event: 'create', template: FormTemplate): void }>();

const { t } = useI18n();
const notify = useNotify();
const detail = ref<FormTemplate | null>(null);
const loading = ref(false);
const view = ref<'preview' | 'structure'>('preview');
const width = ref<'100%' | '768px' | '390px'>('100%');
/** Field-type labels come from the server, so they are translated and match the builder palette. */
const typeLabels = ref<Record<string, string>>({});

const rows = computed<StructureRow[]>(() => (detail.value ? structureRows(detail.value.document) : []));

function typeLabel(row: StructureRow): string {
  if (row.type === 'column') return t('library.column');
  return typeLabels.value[row.type] ?? row.type;
}

async function loadTypes(): Promise<void> {
  try {
    const items = await formsApi.fieldTypes();
    typeLabels.value = Object.fromEntries(items.map((item) => [item.type, item.label]));
  } catch {
    typeLabels.value = {};
  }
}

/** Generation of the latest load, so responses of superseded requests are dropped. */
let generation = 0;

async function load(): Promise<void> {
  const current = ++generation;
  if (!props.open || props.cardId === '') {
    loading.value = false;
    return;
  }
  loading.value = true;
  detail.value = null;
  view.value = 'preview';
  try {
    const template = await formsApi.template(props.cardId);
    if (current !== generation) return;
    detail.value = template;
    await loadTypes();
  } catch (error) {
    if (current === generation) notify.error(error);
  } finally {
    if (current === generation) loading.value = false;
  }
}

watch(() => [props.open, props.cardId], () => void load(), { immediate: true });

function create(): void {
  if (!detail.value || props.creating) return;
  emit('create', detail.value);
}

function conditionText(row: StructureRow): string {
  const condition = row.condition;
  if (!condition) return '';
  const joiner = condition.match === 'any' ? ` ${t('logic.or')} ` : ` ${t('logic.and')} `;
  const rules = condition.rules.map((rule) => `${rule.fieldLabel} ${t(`logic.ops.${rule.op}`)}${rule.value ? ` ${rule.value}` : ''}`);
  return `${t(`library.condition.${condition.action}`)} ${t('library.condition.when')} ${rules.join(joiner)}`;
}
</script>

<template>
  <q-dialog :model-value="open" @update:model-value="$emit('update:open', $event)">
    <q-card class="swf-dialog swf-template-detail" aria-labelledby="swf-template-detail-title">
      <q-card-section class="swf-template-detail__head">
        <q-btn flat round dense icon="r_arrow_back" :aria-label="t('library.back')" @click="$emit('back')" />
        <div class="swf-template-detail__heading">
          <h2 id="swf-template-detail-title" class="swf-dialog__title">{{ detail?.title ?? t('library.detail') }}</h2>
          <p v-if="detail?.description" class="swf-muted">{{ detail.description }}</p>
        </div>
        <q-btn v-close-popup flat round dense icon="r_close" :aria-label="t('common.close')" />
      </q-card-section>

      <q-card-section v-if="loading" class="swf-template-detail__body">
        <q-skeleton type="rect" height="320px" />
      </q-card-section>

      <q-card-section v-else-if="detail" class="swf-template-detail__body">
        <div class="swf-template-detail__facts">
          <span><q-icon name="r_input" size="16px" aria-hidden="true" />{{ t('library.fields', detail.fields) }}</span>
          <span v-if="detail.steps > 1"><q-icon name="r_account_tree" size="16px" aria-hidden="true" />{{ t('library.steps', { n: detail.steps }) }}</span>
          <span v-if="detail.conditions > 0"><q-icon name="r_tune" size="16px" aria-hidden="true" />{{ t('library.conditions', detail.conditions) }}</span>
          <span class="swf-template-detail__version">v{{ detail.version }}</span>
        </div>
        <ul v-if="detail.tags.length" class="swf-template-detail__tags">
          <li v-for="tag in detail.tags" :key="tag">{{ tag }}</li>
        </ul>

        <q-btn-toggle
          v-model="view"
          dense
          no-caps
          unelevated
          toggle-color="primary"
          class="swf-template-detail__switch"
          :options="[
            { value: 'preview', icon: 'r_visibility', label: t('library.tabPreview') },
            { value: 'structure', icon: 'r_schema', label: t('library.tabStructure') },
          ]"
        />

        <div v-if="view === 'preview'" class="swf-template-detail__preview">
          <div class="swf-template-detail__preview-tools">
            <p class="swf-muted swf-small">{{ t('library.previewHint') }}</p>
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
          </div>
          <iframe :src="detail.previewUrl" :style="{ width }" :title="t('library.previewTitle', { title: detail.title })" />
        </div>

        <table v-else class="swf-template-detail__structure">
          <tbody>
            <tr v-for="row in rows" :key="row.id" :class="[`is-${row.type}`]" :style="{ '--swf-depth': row.depth }">
              <th scope="row">
                <q-icon :name="row.type === 'page-break' ? 'r_auto_stories' : 'r_crop_square'" size="14px" aria-hidden="true" />
                <span>{{ row.label || typeLabel(row) }}</span>
                <strong v-if="row.required" class="swf-template-detail__required">{{ t('library.required') }}</strong>
              </th>
              <td>
                <code v-if="row.key">{{ row.key }}</code>
                <span v-if="row.width !== null">{{ t(`library.width.${widthKey(row.width)}`, { n: row.width }) }}</span>
                <span v-if="row.options.length">{{ row.options.join(', ') }}</span>
                <span v-if="row.content" class="swf-template-detail__content">{{ row.content }}</span>
              </td>
              <td class="swf-template-detail__condition">
                <span v-if="row.condition">{{ conditionText(row) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </q-card-section>

      <q-card-actions v-if="detail && !loading" align="between" class="swf-template-detail__actions">
        <span class="swf-muted swf-small">{{ t('library.createHint') }}</span>
        <q-btn color="primary" unelevated no-caps icon="r_add" :label="t('library.create')" :loading="creating" :disable="creating" @click="create" />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<style scoped>
.swf-template-detail {
  width: min(980px, calc(100vw - 24px));
  max-height: calc(100vh - 48px);
  display: flex;
  flex-direction: column;
}
.swf-template-detail__head {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding-bottom: 4px;
}
.swf-template-detail__heading {
  flex: 1 1 auto;
  min-width: 0;
}
.swf-template-detail__head p {
  margin: 4px 0 0;
}
.swf-template-detail__body {
  flex: 1 1 auto;
  overflow: auto;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.swf-template-detail__facts {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 16px;
  align-items: center;
  font-size: 13px;
  color: var(--swf-muted);
}
.swf-template-detail__facts span {
  display: inline-flex;
  gap: 5px;
  align-items: center;
}
.swf-template-detail__version {
  margin-left: auto;
  font-variant-numeric: tabular-nums;
}
.swf-template-detail__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.swf-template-detail__tags li {
  padding: 2px 8px;
  font-size: 12px;
  color: var(--swf-muted);
  background: var(--swf-surface-2);
  border: 1px solid var(--swf-border);
  border-radius: 999px;
}
.swf-template-detail__switch {
  align-self: flex-start;
}
.swf-template-detail__preview {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.swf-template-detail__preview-tools {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  justify-content: space-between;
}
.swf-template-detail__preview iframe {
  width: 100%;
  max-width: 100%;
  min-height: 420px;
  background: #fff;
  border: 1px solid var(--swf-border);
  border-radius: var(--swf-radius-sm);
}
.swf-template-detail__structure {
  width: 100%;
  font-size: 13px;
  border-collapse: collapse;
}
.swf-template-detail__structure th,
.swf-template-detail__structure td {
  padding: 6px 8px;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid var(--swf-border);
}
.swf-template-detail__structure th {
  font-weight: 500;
  padding-left: calc(8px + var(--swf-depth, 0) * 14px);
  display: flex;
  gap: 6px;
  align-items: center;
}
.swf-template-detail__structure td {
  color: var(--swf-muted);
}
.swf-template-detail__structure code {
  font-size: 12px;
}
.swf-template-detail__content {
  display: block;
  max-width: 46ch;
  font-size: 12px;
}
.swf-template-detail__structure tr.is-page-break th {
  font-weight: 700;
}
.swf-template-detail__required {
  font-size: 11px;
  color: var(--swf-danger-text, inherit);
}
.swf-template-detail__condition {
  max-width: 34ch;
}
.swf-template-detail__actions {
  border-top: 1px solid var(--swf-border);
  gap: 12px;
}
@media (max-width: 720px) {
  .swf-template-detail__condition {
    display: none;
  }
  .swf-template-detail__preview iframe {
    min-height: 320px;
  }
}
</style>
