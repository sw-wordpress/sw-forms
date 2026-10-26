<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { formsApi } from '@/api';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SwfCard from '@/components/ui/SwfCard.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useCapabilities } from '@/composables/useCapabilities';
import { useClipboard } from '@/composables/useClipboard';
import { useFormat } from '@/composables/useFormat';
import { useNotify } from '@/composables/useNotify';
import type { FormSummary } from '@/types/api';

const { t } = useI18n();
const router = useRouter();
const notify = useNotify();
const copy = useClipboard();
const { dateTime, number } = useFormat();
const { can } = useCapabilities();
const canManage = can('swf_manage_forms');

const { data, loading, error, load } = useAsyncData(() => formsApi.list(), { immediate: true });
const search = ref('');
const forms = computed(() => {
  const q = search.value.trim().toLowerCase();
  return (data.value ?? []).filter((f) => q === '' || f.title.toLowerCase().includes(q));
});

const createOpen = ref(false);
const newTitle = ref('');
const creating = ref(false);
async function create(): Promise<void> {
  creating.value = true;
  try {
    const form = await formsApi.create(newTitle.value.trim());
    createOpen.value = false;
    await router.push(`/forms/${form.id}`);
  } catch (e) {
    notify.error(e);
  } finally {
    creating.value = false;
  }
}

async function toggleStatus(form: FormSummary): Promise<void> {
  try {
    await formsApi.setStatus(form.id, form.status === 'active' ? 'inactive' : 'active');
    await load();
  } catch (e) {
    notify.error(e);
  }
}

async function duplicate(form: FormSummary): Promise<void> {
  try {
    await formsApi.duplicate(form.id);
    notify.success(t('forms.duplicated'));
    await load();
  } catch (e) {
    notify.error(e);
  }
}

const toDelete = ref<FormSummary | null>(null);
const deleteOpen = computed({
  get: () => toDelete.value !== null,
  set: (v) => {
    if (!v) toDelete.value = null;
  },
});
const deleting = ref(false);
async function remove(): Promise<void> {
  if (!toDelete.value) return;
  deleting.value = true;
  try {
    await formsApi.remove(toDelete.value.id);
    notify.success(t('forms.deleted'));
    toDelete.value = null;
    await load();
  } catch (e) {
    notify.error(e);
  } finally {
    deleting.value = false;
  }
}

function openCreate(): void {
  newTitle.value = '';
  createOpen.value = true;
}
</script>

<template>
  <div class="swf-stack">
    <PageHeader :title="t('forms.title')" :subtitle="t('forms.subtitle')">
      <q-btn v-if="canManage" unelevated no-caps color="primary" icon="r_add" :label="t('forms.create')" @click="openCreate" />
    </PageHeader>

    <SwfCard :padding="false">
      <template #header>
        <q-input v-model="search" dense outlined clearable class="swf-forms__search" :placeholder="t('forms.search')" :aria-label="t('forms.search')">
          <template #prepend><q-icon name="r_search" /></template>
        </q-input>
      </template>

      <div v-if="loading && !data" class="swf-forms__loading"><q-skeleton v-for="i in 3" :key="i" type="rect" height="56px" /></div>
      <EmptyState v-else-if="error" icon="r_error" :title="t('errors.loadFailed')" :text="error.message">
        <q-btn outline no-caps :label="t('common.retry')" @click="load" />
      </EmptyState>
      <EmptyState v-else-if="(data ?? []).length === 0" :title="t('forms.emptyTitle')" :text="t('forms.emptyText')">
        <q-btn v-if="canManage" unelevated no-caps color="primary" icon="r_add" :label="t('forms.create')" @click="openCreate" />
      </EmptyState>
      <EmptyState v-else-if="forms.length === 0" compact icon="r_search" :title="t('forms.noMatch')" />
      <table v-else class="swf-table">
        <thead>
          <tr>
            <th scope="col">{{ t('forms.col.title') }}</th>
            <th scope="col">{{ t('forms.col.status') }}</th>
            <th scope="col">{{ t('forms.col.entries') }}</th>
            <th scope="col">{{ t('forms.col.shortcode') }}</th>
            <th scope="col">{{ t('forms.col.updated') }}</th>
            <th scope="col"><span class="swf-sr-only">{{ t('common.actions') }}</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="form in forms" :key="form.id">
            <td :data-label="t('forms.col.title')">
              <router-link :to="`/forms/${form.id}`" class="swf-forms__title">{{ form.title }}</router-link>
              <span v-if="form.hasDraft" class="swf-chip swf-chip--warning">{{ t('forms.unsaved') }}</span>
              <span v-if="form.currentVersion === 0" class="swf-chip">{{ t('forms.neverSaved') }}</span>
              <router-link v-if="form.webhookFailures > 0" :to="`/forms/${form.id}/webhooks`" class="swf-chip swf-chip--danger" data-test="webhook-failures">
                <q-icon name="r_error" size="14px" aria-hidden="true" />
                {{ t('forms.webhookFailures', { n: form.webhookFailures }, form.webhookFailures) }}
              </router-link>
            </td>
            <td :data-label="t('forms.col.status')">
              <q-toggle
                :model-value="form.status === 'active'"
                :disable="!canManage"
                :label="form.status === 'active' ? t('forms.active') : t('forms.inactive')"
                @update:model-value="toggleStatus(form)"
              />
            </td>
            <td :data-label="t('forms.col.entries')">
              <router-link :to="`/forms/${form.id}/entries`" class="swf-num">{{ number(form.entries) }}</router-link>
              <span v-if="form.unread > 0" class="swf-chip swf-chip--primary swf-num">{{ t('forms.unread', { n: form.unread }) }}</span>
            </td>
            <td :data-label="t('forms.col.shortcode')">
              <q-btn flat dense no-caps class="swf-mono" :label="`[sw_form id=&quot;${form.id}&quot;]`" icon-right="r_content_copy" @click="copy(`[sw_form id=&quot;${form.id}&quot;]`)">
                <q-tooltip>{{ t('common.copy') }}</q-tooltip>
              </q-btn>
            </td>
            <td :data-label="t('forms.col.updated')" class="swf-muted">{{ dateTime(form.updatedAt) }}</td>
            <td data-label="">
              <q-btn flat round dense icon="r_more_vert" :aria-label="t('common.actions')">
                <q-menu>
                  <q-list dense style="min-width: 200px">
                    <q-item v-if="canManage" v-close-popup clickable :to="`/forms/${form.id}`">
                      <q-item-section avatar><q-icon name="r_edit" /></q-item-section>
                      <q-item-section>{{ t('common.edit') }}</q-item-section>
                    </q-item>
                    <q-item v-close-popup clickable :to="`/forms/${form.id}/entries`">
                      <q-item-section avatar><q-icon name="r_inbox" /></q-item-section>
                      <q-item-section>{{ t('forms.showEntries') }}</q-item-section>
                    </q-item>
                    <q-item v-if="canManage" v-close-popup clickable @click="duplicate(form)">
                      <q-item-section avatar><q-icon name="r_content_copy" /></q-item-section>
                      <q-item-section>{{ t('common.duplicate') }}</q-item-section>
                    </q-item>
                    <q-separator v-if="canManage" />
                    <q-item v-if="canManage" v-close-popup clickable class="text-negative" @click="toDelete = form">
                      <q-item-section avatar><q-icon name="r_delete" /></q-item-section>
                      <q-item-section>{{ t('common.delete') }}</q-item-section>
                    </q-item>
                  </q-list>
                </q-menu>
              </q-btn>
            </td>
          </tr>
        </tbody>
      </table>
    </SwfCard>

    <q-dialog v-model="createOpen">
      <q-card class="swf-dialog">
        <q-form @submit.prevent="create">
          <q-card-section>
            <h2 class="swf-dialog__title">{{ t('forms.createTitle') }}</h2>
            <p class="swf-muted">{{ t('forms.createText') }}</p>
          </q-card-section>
          <q-card-section>
            <q-input v-model="newTitle" outlined autofocus :label="t('forms.col.title')" :placeholder="t('forms.titlePlaceholder')" />
          </q-card-section>
          <q-card-actions align="right">
            <q-btn v-close-popup flat no-caps :label="t('common.cancel')" />
            <q-btn type="submit" unelevated no-caps color="primary" :loading="creating" :label="t('forms.createSubmit')" />
          </q-card-actions>
        </q-form>
      </q-card>
    </q-dialog>

    <ConfirmDialog
      v-model="deleteOpen"
      :title="t('forms.deleteTitle')"
      :target="toDelete?.title"
      :consequences="[t('forms.deleteEntries', { n: toDelete?.entries ?? 0 }), t('forms.deleteShortcode')]"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      @confirm="remove"
    />
  </div>
</template>

<style scoped>
.swf-forms__search {
  width: min(360px, 100%);
}
.swf-forms__loading {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 16px 22px 22px;
}
.swf-forms__title {
  font-weight: 650;
  margin-right: 8px;
}
</style>
