<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { pagesApi } from '@/api';
import SwfCard from '@/components/ui/SwfCard.vue';
import { useClipboard } from '@/composables/useClipboard';
import { useBuilderStore } from '@/stores/builder';
import type { FormSettings, WpPage } from '@/types/api';
import MergeTagMenu from './MergeTagMenu.vue';

const { t } = useI18n();
const store = useBuilderStore();
const copy = useClipboard();
const settings = computed(() => store.document?.settings as FormSettings);

function set(mutate: (s: FormSettings) => void, coalesce: string): void {
  store.updateFormSettings(mutate, coalesce);
}

// ---------- confirmation page picker ----------
const pageOptions = ref<{ value: number; label: string }[]>([]);
async function searchPages(term: string, done: (fn: () => void) => void): Promise<void> {
  let pages: WpPage[] = [];
  try {
    pages = await pagesApi.search(term);
  } catch {
    pages = [];
  }
  done(() => {
    pageOptions.value = pages.map((p) => ({ value: p.id, label: p.title.rendered.replace(/<[^>]*>/g, '') || `#${p.id}` }));
  });
}
watch(
  () => settings.value?.confirmation.pageId,
  async (id) => {
    if (!id || pageOptions.value.some((o) => o.value === id)) return;
    try {
      const page = await pagesApi.get(id);
      pageOptions.value = [{ value: page.id, label: page.title.rendered.replace(/<[^>]*>/g, '') }, ...pageOptions.value];
    } catch {
      /* page deleted: the select shows the id */
    }
  },
  { immediate: true },
);

const shortcode = computed(() => store.meta?.shortcode ?? '');
const php = computed(() => `<?php echo swf_render_form( ${store.formId} ); ?>`);
</script>

<template>
  <div v-if="settings" class="swf-settings-grid">
    <SwfCard :title="t('formSettings.general')" icon="r_tune">
      <div class="swf-stack swf-stack--tight">
        <q-input :model-value="settings.submitLabel" outlined dense :label="t('formSettings.submitLabel')" @update:model-value="set((s) => (s.submitLabel = String($event ?? '')), 'submitLabel')" />
        <q-input :model-value="settings.cssClass" outlined dense input-class="swf-mono" :label="t('formSettings.cssClass')" @update:model-value="set((s) => (s.cssClass = String($event ?? '')), 'cssClass')" />
      </div>
    </SwfCard>

    <SwfCard :title="t('formSettings.confirmation')" :subtitle="t('formSettings.confirmationText')" icon="r_task_alt">
      <div class="swf-stack swf-stack--tight">
        <q-btn-toggle
          :model-value="settings.confirmation.type"
          no-caps
          unelevated
          toggle-color="primary"
          :options="[
            { value: 'message', label: t('formSettings.confirmMessage') },
            { value: 'page', label: t('formSettings.confirmPage') },
            { value: 'redirect', label: t('formSettings.confirmRedirect') },
          ]"
          @update:model-value="set((s) => (s.confirmation.type = $event), '')"
        />
        <div v-if="settings.confirmation.type === 'message'" class="swf-with-tags">
          <q-input
            :model-value="settings.confirmation.message"
            type="textarea"
            autogrow
            outlined
            :label="t('formSettings.message')"
            :hint="t('formSettings.messageHint')"
            @update:model-value="set((s) => (s.confirmation.message = String($event ?? '')), 'confirmMessage')"
          />
          <MergeTagMenu @insert="set((s) => (s.confirmation.message += $event), '')" />
        </div>
        <q-select
          v-else-if="settings.confirmation.type === 'page'"
          :model-value="settings.confirmation.pageId || null"
          outlined
          dense
          use-input
          emit-value
          map-options
          :options="pageOptions"
          :label="t('formSettings.page')"
          @filter="searchPages"
          @update:model-value="set((s) => (s.confirmation.pageId = Number($event ?? 0)), '')"
        />
        <div v-else class="swf-with-tags">
          <q-input
            :model-value="settings.confirmation.url"
            outlined
            dense
            type="url"
            :label="t('formSettings.url')"
            :hint="t('formSettings.urlHint')"
            @update:model-value="set((s) => (s.confirmation.url = String($event ?? '')), 'confirmUrl')"
          />
          <MergeTagMenu @insert="set((s) => (s.confirmation.url += $event), '')" />
        </div>
      </div>
    </SwfCard>

    <SwfCard :title="t('formSettings.entries')" icon="r_inventory_2">
      <div class="swf-stack swf-stack--tight">
        <q-toggle :model-value="settings.storeEntries" :label="t('formSettings.storeEntries')" @update:model-value="set((s) => (s.storeEntries = !!$event), '')" />
        <q-input
          :model-value="settings.retentionDays"
          type="number"
          min="0"
          outlined
          dense
          :suffix="t('formSettings.days')"
          :label="t('formSettings.retention')"
          :hint="t('formSettings.retentionHint')"
          @update:model-value="set((s) => (s.retentionDays = Math.max(0, Number($event) || 0)), 'retention')"
        />
      </div>
    </SwfCard>

    <SwfCard :title="t('formSettings.spam')" :subtitle="t('formSettings.spamText')" icon="r_shield">
      <div class="swf-stack swf-stack--tight">
        <q-toggle :model-value="settings.spam.honeypot" :label="t('formSettings.honeypot')" @update:model-value="set((s) => (s.spam.honeypot = !!$event), '')" />
        <q-input
          :model-value="settings.spam.minSeconds"
          type="number"
          min="0"
          max="60"
          outlined
          dense
          :suffix="t('formSettings.seconds')"
          :label="t('formSettings.minSeconds')"
          :hint="t('formSettings.minSecondsHint')"
          @update:model-value="set((s) => (s.spam.minSeconds = Math.max(0, Number($event) || 0)), 'minSeconds')"
        />
        <q-input
          :model-value="settings.spam.rateLimit"
          type="number"
          min="0"
          outlined
          dense
          :label="t('formSettings.rateLimit')"
          :hint="t('formSettings.rateLimitHint')"
          @update:model-value="set((s) => (s.spam.rateLimit = Math.max(0, Number($event) || 0)), 'rateLimit')"
        />
      </div>
    </SwfCard>

    <SwfCard :title="t('formSettings.embed')" :subtitle="t('formSettings.embedText')" icon="r_code">
      <div class="swf-stack swf-stack--tight">
        <div class="swf-embed">
          <code>{{ shortcode }}</code>
          <q-btn flat dense no-caps icon="r_content_copy" :label="t('common.copy')" @click="copy(shortcode)" />
        </div>
        <div class="swf-embed">
          <code>{{ php }}</code>
          <q-btn flat dense no-caps icon="r_content_copy" :label="t('common.copy')" @click="copy(php)" />
        </div>
        <p v-if="store.meta?.currentVersion === 0" class="swf-warning"><q-icon name="r_info" size="16px" /> {{ t('formSettings.notPublished') }}</p>
      </div>
    </SwfCard>
  </div>
</template>
