<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { formsApi } from '@/api';
import { useNotify } from '@/composables/useNotify';
import type { FormTemplate } from '@/types/api';
import { templateSummary as summary } from './librarySummary';

const IMPORT_LIMIT = 1048576;
const { t } = useI18n();
const router = useRouter();
const notify = useNotify();
const open = ref(false);
const loading = ref(false);
/** Template id or `file` while an import request runs. */
const busy = ref<string | null>(null);
const dragging = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);
const templates = ref<FormTemplate[]>([]);

async function show(): Promise<void> {
  open.value = true;
  if (templates.value.length > 0) return;
  loading.value = true;
  try {
    templates.value = await formsApi.templates();
  } catch (error) {
    notify.error(error);
  } finally {
    loading.value = false;
  }
}

async function importData(data: unknown, source: string): Promise<void> {
  if (busy.value) return;
  busy.value = source;
  try {
    const result = await formsApi.import(data);
    open.value = false;
    await router.push(`/forms/${result.id}`);
  } catch (error) {
    notify.error(error);
  } finally {
    busy.value = null;
  }
}

function useTemplate(item: FormTemplate): void {
  void importData({ format: 'swforms', formatVersion: 1, title: item.title, document: item.document }, item.id);
}

async function importFile(selected: File | undefined): Promise<void> {
  if (!selected) return;
  try {
    if (selected.size > IMPORT_LIMIT) throw new Error(t('m5.importLimit'));
    await importData(JSON.parse(await selected.text()), 'file');
  } catch (error) {
    notify.error(error);
  }
}

function onPick(event: Event): void {
  const input = event.target as HTMLInputElement;
  void importFile(input.files?.[0]).finally(() => {
    input.value = '';
  });
}

function onDrop(event: DragEvent): void {
  dragging.value = false;
  void importFile(event.dataTransfer?.files[0]);
}
</script>

<template>
  <q-btn outline no-caps icon="r_library_books" :label="t('m5.library')" @click="show" />
  <q-dialog v-model="open">
    <q-card class="swf-dialog swf-library" aria-labelledby="swf-library-title">
      <q-card-section class="swf-library__head">
        <div>
          <h2 id="swf-library-title" class="swf-dialog__title">{{ t('m5.library') }}</h2>
          <p class="swf-muted">{{ t('library.intro') }}</p>
        </div>
        <q-btn v-close-popup flat round dense icon="r_close" :aria-label="t('common.close')" />
      </q-card-section>

      <q-card-section class="swf-library__body">
        <h3 class="swf-eyebrow">{{ t('library.templates') }}</h3>
        <div class="swf-library__grid">
          <template v-if="loading">
            <q-skeleton v-for="n in 4" :key="n" type="rect" height="112px" class="swf-library__skeleton" />
          </template>
          <button
            v-for="item in templates"
            v-else
            :key="item.id"
            type="button"
            class="swf-library__card"
            :disabled="busy !== null"
            :aria-busy="busy === item.id"
            @click="useTemplate(item)"
          >
            <span class="swf-library__icon" aria-hidden="true">
              <q-spinner v-if="busy === item.id" size="20px" />
              <q-icon v-else :name="item.icon || 'r_description'" size="22px" />
            </span>
            <span class="swf-library__text">
              <strong>{{ item.title }}</strong>
              <span v-if="item.description" class="swf-library__desc">{{ item.description }}</span>
              <span class="swf-library__meta">
                {{ t('library.fields', summary(item.document).fields) }}
                <template v-if="summary(item.document).steps > 1"> · {{ t('library.steps', { n: summary(item.document).steps }) }}</template>
              </span>
            </span>
          </button>
        </div>

        <h3 class="swf-eyebrow">{{ t('m5.import') }}</h3>
        <div
          class="swf-library__drop"
          :class="{ 'swf-library__drop--active': dragging }"
          @dragenter.prevent="dragging = true"
          @dragover.prevent="dragging = true"
          @dragleave.self="dragging = false"
          @drop.prevent="onDrop"
        >
          <q-icon name="r_upload_file" size="28px" aria-hidden="true" />
          <div class="swf-library__drop-text">
            <strong>{{ t('library.drop') }}</strong>
            <span class="swf-muted">{{ t('library.dropHint') }}</span>
          </div>
          <q-btn outline no-caps :loading="busy === 'file'" :disable="busy !== null" :label="t('library.choose')" @click="fileInput?.click()" />
          <input ref="fileInput" type="file" accept="application/json,.json" class="swf-library__file" :aria-label="t('m5.import')" @change="onPick" />
        </div>

        <p class="swf-library__notice"><q-icon name="r_info" size="18px" aria-hidden="true" />{{ t('m5.importNotice') }}</p>
      </q-card-section>
    </q-card>
  </q-dialog>
</template>

<style scoped>
.swf-library {
  width: min(760px, calc(100vw - 24px));
}
.swf-library__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding-bottom: 4px;
}
.swf-library__head p {
  margin: 4px 0 0;
}
.swf-library__body {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.swf-library__body .swf-eyebrow {
  margin: 8px 0 0;
}
.swf-library__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 12px;
}
.swf-library__skeleton {
  border-radius: var(--swf-radius-sm);
}
.swf-library__card {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 14px;
  font: inherit;
  text-align: left;
  color: var(--swf-text);
  background: var(--swf-surface);
  border: 1px solid var(--swf-border);
  border-radius: var(--swf-radius-sm);
  cursor: pointer;
  transition: border-color 0.15s, box-shadow 0.15s, transform 0.15s;
}
.swf-library__card:hover:not(:disabled) {
  border-color: var(--swf-primary);
  box-shadow: var(--swf-shadow-1);
  transform: translateY(-1px);
}
.swf-library__card:focus-visible {
  outline: 2px solid var(--swf-focus);
  outline-offset: 2px;
}
.swf-library__card:disabled {
  cursor: progress;
  opacity: 0.7;
}
.swf-library__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: 10px;
  color: var(--swf-primary-text);
  background: var(--swf-primary-bg);
}
.swf-library__text {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}
.swf-library__desc {
  font-size: 13px;
  line-height: 1.4;
  color: var(--swf-muted);
}
.swf-library__meta {
  font-size: 12px;
  color: var(--swf-muted);
}
.swf-library__drop {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 18px;
  color: var(--swf-muted);
  border: 1.5px dashed var(--swf-border);
  border-radius: var(--swf-radius-sm);
  background: var(--swf-surface-2);
  transition: border-color 0.15s, background-color 0.15s;
}
.swf-library__drop--active {
  border-color: var(--swf-primary);
  background: var(--swf-primary-soft);
}
.swf-library__drop-text {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  color: var(--swf-text);
  font-size: 13px;
}
.swf-library__file {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
}
.swf-library__notice {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  margin: 4px 0 0;
  padding: 10px 12px;
  font-size: 13px;
  border-radius: var(--swf-radius-sm);
  color: var(--swf-info-text);
  background: var(--swf-info-bg);
}
@media (max-width: 520px) {
  .swf-library__drop {
    flex-wrap: wrap;
  }
}
@media (prefers-reduced-motion: reduce) {
  .swf-library__card {
    transition: none;
  }
}
</style>
