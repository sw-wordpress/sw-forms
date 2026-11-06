<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { formsApi } from '@/api';
import { useNotify } from '@/composables/useNotify';
import type { FormTemplate, FormTemplateCard, TemplateCategory } from '@/types/api';
import { groupTemplates } from './templateBrowser';
import TemplateDetail from './TemplateDetail.vue';

/**
 * Template gallery and JSON import. Templates are grouped by category, searchable and open a
 * detail view with the real rendered form plus the full field structure; creating a form always
 * produces a new inactive instance that is never updated from the template again.
 */
const IMPORT_LIMIT = 1048576;
const { t } = useI18n();
const router = useRouter();
const notify = useNotify();
const open = ref(false);
const tab = ref<'templates' | 'import'>('templates');
const loading = ref(false);
/** Template id or `file` while a request runs. */
const busy = ref<string | null>(null);
const dragging = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);
const categories = ref<TemplateCategory[]>([]);
const cards = ref<FormTemplateCard[]>([]);
/** Quasar's clearable input sets the model to null, not ''. */
const query = ref<string | null>('');
const category = ref('');
const detailId = ref('');
const detailOpen = ref(false);

const groups = computed(() => groupTemplates(cards.value, categories.value, query.value, category.value));
const matches = computed(() => groups.value.reduce((total, group) => total + group.items.length, 0));

async function show(): Promise<void> {
  open.value = true;
  if (cards.value.length > 0) return;
  loading.value = true;
  try {
    const list = await formsApi.templates();
    categories.value = list.categories;
    cards.value = list.items;
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
    detailOpen.value = false;
    detailId.value = '';
    await router.push(`/forms/${result.id}`);
  } catch (error) {
    notify.error(error);
  } finally {
    busy.value = null;
  }
}

function openDetail(card: FormTemplateCard): void {
  detailId.value = card.id;
  detailOpen.value = true;
}

function createFrom(template: FormTemplate): void {
  void importData({ format: 'swforms', formatVersion: 1, title: template.title, document: template.document }, template.id);
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

      <q-tabs v-model="tab" class="swf-library__tabs" dense align="left" active-color="primary" indicator-color="primary" narrow-indicator>
        <q-tab name="templates" no-caps icon="r_grid_view" :label="t('library.templates')" />
        <q-tab name="import" no-caps icon="r_upload_file" :label="t('m5.import')" />
      </q-tabs>

      <q-tab-panels v-model="tab" animated>
        <q-tab-panel name="templates" class="swf-library__body">
          <div class="swf-library__tools">
            <q-input
              v-model="query"
              type="search"
              dense
              outlined
              clearable
              bg-color="white"
              class="swf-library__search"
              :placeholder="t('library.search')"
              :aria-label="t('library.search')"
            >
              <template #append><q-icon name="r_search" /></template>
            </q-input>
            <div class="swf-library__categories" role="group" :aria-label="t('library.categories')">
              <button
                type="button"
                class="swf-library__chip"
                :class="{ 'is-active': category === '' }"
                :aria-pressed="category === ''"
                @click="category = ''"
              >
                {{ t('library.allCategories') }}
              </button>
              <button
                v-for="entry in categories"
                :key="entry.slug"
                type="button"
                class="swf-library__chip"
                :class="{ 'is-active': category === entry.slug }"
                :aria-pressed="category === entry.slug"
                @click="category = entry.slug"
              >
                <q-icon :name="entry.icon" size="15px" aria-hidden="true" />{{ entry.title }}
              </button>
            </div>
          </div>

          <template v-if="loading">
            <div class="swf-library__grid">
              <q-skeleton v-for="n in 6" :key="n" type="rect" height="104px" class="swf-library__skeleton" />
            </div>
          </template>
          <template v-else>
            <p v-if="matches === 0" class="swf-library__empty">{{ t('library.noResults', { query: query ?? '' }) }}</p>
            <section v-for="group in groups" :key="group.slug" class="swf-library__section" :aria-labelledby="`swf-library-${group.slug}`">
              <h3 :id="`swf-library-${group.slug}`" class="swf-eyebrow">
                <q-icon :name="group.icon" size="16px" aria-hidden="true" />{{ group.title }}
                <span class="swf-library__count">{{ t('library.count', group.items.length) }}</span>
              </h3>
              <div class="swf-library__grid">
                <button
                  v-for="item in group.items"
                  :key="item.id"
                  type="button"
                  class="swf-library__card"
                  :class="{ 'is-busy': busy === item.id }"
                  :disabled="busy !== null"
                  :aria-busy="busy === item.id"
                  aria-haspopup="dialog"
                  @click="openDetail(item)"
                >
                  <span class="swf-library__icon" aria-hidden="true">
                    <q-spinner v-if="busy === item.id" size="20px" />
                    <q-icon v-else :name="item.icon || 'r_description'" size="22px" />
                  </span>
                  <span class="swf-library__text">
                    <strong>{{ item.title }}</strong>
                    <span v-if="item.description" class="swf-library__desc">{{ item.description }}</span>
                    <span class="swf-library__meta">
                      {{ t('library.fields', item.fields) }}
                      <template v-if="item.isMultiStep"> · {{ t('library.steps', { n: item.steps }) }}</template>
                      <template v-if="item.conditions > 0"> · {{ t('library.conditions', item.conditions) }}</template>
                    </span>
                  </span>
                  <q-icon name="r_chevron_right" size="18px" class="swf-library__go" aria-hidden="true" />
                </button>
              </div>
            </section>
          </template>

          <p class="swf-library__notice">
            <q-icon name="r_info" size="18px" aria-hidden="true" />
            {{ t('library.templateNotice') }}
          </p>
        </q-tab-panel>

        <q-tab-panel name="import" class="swf-library__body">
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
        </q-tab-panel>
      </q-tab-panels>
    </q-card>
  </q-dialog>

  <TemplateDetail
    :card-id="detailId"
    :open="detailOpen"
    :creating="busy !== null && busy !== 'file'"
    @update:open="detailOpen = $event"
    @back="detailOpen = false"
    @create="createFrom"
  />
</template>

<style scoped>
.swf-library {
  width: min(900px, calc(100vw - 24px));
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
.swf-library__tabs {
  border-bottom: 1px solid var(--swf-border);
}
.swf-library__body {
  display: flex;
  flex-direction: column;
  gap: 14px;
  max-height: min(62vh, 560px);
  overflow: auto;
}
.swf-library__body .swf-eyebrow {
  display: flex;
  gap: 6px;
  align-items: center;
  margin: 8px 0 0;
}
.swf-library__tools {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}
.swf-library__search {
  flex: 1 1 220px;
  min-width: 200px;
}
.swf-library__categories {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.swf-library__chip {
  display: inline-flex;
  gap: 5px;
  align-items: center;
  padding: 5px 10px;
  font: inherit;
  font-size: 13px;
  color: var(--swf-text);
  cursor: pointer;
  background: var(--swf-surface-2);
  border: 1px solid var(--swf-border);
  border-radius: 999px;
}
.swf-library__chip.is-active {
  color: var(--swf-primary-text);
  background: var(--swf-primary-bg);
  border-color: var(--swf-primary);
}
.swf-library__chip:focus-visible {
  outline: 2px solid var(--swf-focus);
  outline-offset: 2px;
}
.swf-library__section {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.swf-library__count {
  margin-left: auto;
  font-size: 12px;
  font-weight: 400;
  color: var(--swf-muted);
}
.swf-library__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
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
.swf-library__go {
  margin-left: auto;
  color: var(--swf-muted);
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
.swf-library__empty {
  margin: 0;
  padding: 14px;
  font-size: 13px;
  color: var(--swf-muted);
  text-align: center;
  border: 1px dashed var(--swf-border);
  border-radius: var(--swf-radius-sm);
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
  .swf-library__grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
@media (prefers-reduced-motion: reduce) {
  .swf-library__card {
    transition: none;
  }
}
</style>
