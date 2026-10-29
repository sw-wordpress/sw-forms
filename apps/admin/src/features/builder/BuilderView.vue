<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router';
import EmptyState from '@/components/ui/EmptyState.vue';
import { useCapabilities } from '@/composables/useCapabilities';
import { useNotify } from '@/composables/useNotify';
import { BUILDER_TABS, type BuilderTab } from '@/router';
import { useAppStore } from '@/stores/app';
import { useBuilderStore } from '@/stores/builder';
import EntriesView from '@/features/entries/EntriesView.vue';
import BuilderCanvas from './BuilderCanvas.vue';
import FormSettingsPanel from './FormSettingsPanel.vue';
import InspectorPanel from './InspectorPanel.vue';
import NotificationsPanel from './NotificationsPanel.vue';
import PalettePanel from './PalettePanel.vue';
import PreviewDialog from './PreviewDialog.vue';
import WebhooksPanel from './WebhooksPanel.vue';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const notify = useNotify();
const store = useBuilderStore();
const app = useAppStore();
// The builder needs the width: collapse the plugin navigation while it is open.
const railWasCollapsed = app.railCollapsed;
app.railCollapsed = true;
onBeforeUnmount(() => {
  app.railCollapsed = railWasCollapsed;
});
const { can } = useCapabilities();
const canManage = can('swf_manage_forms');

const formId = computed(() => Number(route.params.id));
const tab = computed<BuilderTab>(() => {
  const value = String(route.params.tab ?? '');
  if ((BUILDER_TABS as readonly string[]).includes(value)) return value as BuilderTab;
  return canManage ? 'build' : 'entries';
});

const loading = ref(true);
const loadError = ref('');
const previewOpen = ref(false);

async function load(): Promise<void> {
  loading.value = true;
  loadError.value = '';
  try {
    await store.load(formId.value);
  } catch (e) {
    loadError.value = e instanceof Error ? e.message : String(e);
  } finally {
    loading.value = false;
  }
}
watch(formId, load);
onMounted(load);

function go(next: BuilderTab): void {
  void router.push(`/forms/${formId.value}/${next}`);
}

async function save(): Promise<void> {
  try {
    const ok = await store.save();
    if (ok) {
      notify.success(t('builder.saved'));
    } else {
      notify.info(t('builder.saveRejected'));
      if (tab.value !== 'build') go('build');
    }
  } catch (e) {
    notify.error(e);
  }
}

function selectError(path: string): void {
  if (path.startsWith('webhook:')) {
    go('webhooks');
    return;
  }
  if (path.startsWith('notification:')) {
    go('notifications');
    return;
  }
  if (path !== '') store.select(path);
  if (tab.value !== 'build') go('build');
}

const draftLabel = computed(() => {
  if (!store.dirty) return t('builder.allSaved');
  switch (store.draftState) {
    case 'saving':
      return t('builder.draftSaving');
    case 'saved':
      return t('builder.draftSaved');
    case 'failed':
      return t('builder.draftFailed');
    default:
      return t('builder.unsaved');
  }
});

// ---------- keyboard ----------
function isEditable(target: EventTarget | null): boolean {
  const el = target as HTMLElement | null;
  return !!el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName));
}

function onKey(e: KeyboardEvent): void {
  const mod = e.ctrlKey || e.metaKey;
  if (mod && e.key.toLowerCase() === 's') {
    e.preventDefault();
    if (canManage) void save();
    return;
  }
  if (tab.value !== 'build' || isEditable(e.target) || document.querySelector('.q-dialog')) return;
  if (mod && e.key.toLowerCase() === 'z') {
    e.preventDefault();
    if (e.shiftKey) store.redo();
    else store.undo();
  } else if (mod && e.key.toLowerCase() === 'y') {
    e.preventDefault();
    store.redo();
  } else if (mod && e.key.toLowerCase() === 'd' && store.selectedId) {
    e.preventDefault();
    store.duplicateElement(store.selectedId);
  } else if ((e.key === 'Delete' || e.key === 'Backspace') && store.selectedId && store.selected && !store.selected.isColumn) {
    e.preventDefault();
    store.removeElement(store.selectedId);
  } else if (e.key === 'Escape') {
    store.select(null);
  }
}

function onBeforeUnload(e: BeforeUnloadEvent): void {
  if (store.dirty && store.draftState !== 'saved') {
    e.preventDefault();
  }
}
onMounted(() => {
  window.addEventListener('keydown', onKey);
  window.addEventListener('beforeunload', onBeforeUnload);
});
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey);
  window.removeEventListener('beforeunload', onBeforeUnload);
});

onBeforeRouteLeave(async (to) => {
  if (to.params.id === route.params.id || !store.dirty) return true;
  // Unsaved changes are kept as a draft; leaving is safe once the draft is stored.
  if (store.draftState !== 'saved') await store.saveDraft();
  return true;
});
</script>

<template>
  <div class="swf-builder">
    <header class="swf-builder__bar">
      <div class="swf-builder__left">
        <q-btn flat round dense icon="r_arrow_back" to="/" :aria-label="t('builder.back')">
          <q-tooltip>{{ t('builder.back') }}</q-tooltip>
        </q-btn>
        <q-input
          v-if="store.document"
          :model-value="store.title"
          borderless
          dense
          class="swf-builder__title"
          :readonly="!canManage"
          :aria-label="t('builder.formTitle')"
          @update:model-value="store.setTitle(String($event ?? ''))"
        />
        <span v-if="store.document" class="swf-builder__state" :class="{ 'swf-builder__state--dirty': store.dirty }" role="status">
          <q-icon :name="store.dirty ? 'r_edit_note' : 'r_cloud_done'" size="16px" aria-hidden="true" />
          {{ draftLabel }}
        </span>
      </div>

      <q-tabs
        :model-value="tab"
        dense
        no-caps
        inline-label
        class="swf-builder__tabs"
        active-color="primary"
        indicator-color="primary"
        @update:model-value="go($event as BuilderTab)"
      >
        <q-tab v-if="canManage" name="build" icon="r_view_quilt" :label="t('builder.tabs.build')" />
        <q-tab v-if="canManage" name="settings" icon="r_tune" :label="t('builder.tabs.settings')" />
        <q-tab v-if="canManage" name="notifications" icon="r_mail" :label="t('builder.tabs.notifications')" />
        <q-tab v-if="canManage" name="webhooks" icon="r_webhook" :label="t('webhooks.title')" />
        <q-tab name="entries" icon="r_inbox" :label="t('builder.tabs.entries')" />
      </q-tabs>

      <div v-if="canManage && store.document" class="swf-builder__right">
        <q-btn flat round dense icon="r_undo" :disable="!store.canUndo" :aria-label="t('builder.undo')" @click="store.undo()">
          <q-tooltip>{{ t('builder.undo') }} (Ctrl+Z)</q-tooltip>
        </q-btn>
        <q-btn flat round dense icon="r_redo" :disable="!store.canRedo" :aria-label="t('builder.redo')" @click="store.redo()">
          <q-tooltip>{{ t('builder.redo') }} (Ctrl+Shift+Z)</q-tooltip>
        </q-btn>
        <q-btn outline no-caps icon="r_visibility" :label="t('builder.preview')" @click="previewOpen = true" />
        <q-btn
          unelevated
          no-caps
          color="primary"
          icon="r_save"
          :label="t('builder.save')"
          :loading="store.saving"
          :disable="!store.dirty && store.meta?.currentVersion !== 0"
          @click="save"
        >
          <q-tooltip>{{ t('builder.saveHint') }} (Ctrl+S)</q-tooltip>
        </q-btn>
      </div>
    </header>

    <div v-if="loading" class="swf-builder__loading"><q-spinner size="36px" color="primary" /></div>
    <EmptyState v-else-if="loadError" icon="r_error" :title="t('errors.loadFailed')" :text="loadError">
      <q-btn outline no-caps :label="t('common.retry')" @click="load" />
    </EmptyState>
    <template v-else-if="store.document">
      <div v-if="store.errors.length > 0" class="swf-builder__errors" role="alert">
        <q-icon name="r_error" size="20px" aria-hidden="true" />
        <div>
          <strong>{{ t('builder.errorsTitle') }}</strong>
          <ul>
            <li v-for="(error, i) in store.errors" :key="i">
              <a href="#" @click.prevent="selectError(error.path)">{{ error.message }}</a>
            </li>
          </ul>
        </div>
      </div>

      <div v-if="tab === 'build'" class="swf-workspace">
        <PalettePanel />
        <BuilderCanvas />
        <InspectorPanel />
      </div>
      <div v-else-if="tab === 'settings'" class="swf-builder__page"><FormSettingsPanel /></div>
      <div v-else-if="tab === 'notifications'" class="swf-builder__page"><NotificationsPanel /></div>
      <div v-else-if="tab === 'webhooks'" class="swf-builder__page"><WebhooksPanel /></div>
      <div v-else class="swf-builder__page">
        <EntriesView :form-id="formId" :entry-id="route.params.entryId ? Number(route.params.entryId) : null" />
      </div>
    </template>

    <PreviewDialog v-if="store.document" v-model="previewOpen" />
  </div>
</template>
