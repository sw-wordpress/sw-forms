<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { entriesApi } from '@/api';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import SideSheet from '@/components/ui/SideSheet.vue';
import { useCapabilities } from '@/composables/useCapabilities';
import { useFormat } from '@/composables/useFormat';
import { useNotify } from '@/composables/useNotify';
import type { EntryDetail, EntryStatus } from '@/types/api';
import EntryFields from './EntryFields.vue';
import EntryValueFields from './EntryValueFields.vue';
import { isApiError } from '@/api/client';

const props = defineProps<{ entryId: number | null }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'changed'): void }>();
const { t } = useI18n();
const notify = useNotify();
const { dateTime } = useFormat();
const { can } = useCapabilities();
const canManage = can('swf_manage_entries');
const canResend = canManage && can('swf_manage_forms');
const resending = ref<number | null>(null);
async function resend(delivery: number): Promise<void> {
  if (!detail.value) return;
  resending.value = delivery;
  try {
    await entriesApi.resendWebhook(detail.value.entry.id, delivery);
    detail.value = await entriesApi.get(detail.value.entry.id);
    notify.success(t('webhooks.queued'));
    emit('changed');
  } catch (error) {
    notify.error(error);
  } finally {
    resending.value = null;
  }
}

const detail = ref<EntryDetail | null>(null);
const editing = ref<Awaited<ReturnType<typeof entriesApi.editValues>> | null>(null);
const editErrors = ref<Record<string, string>>({});
const entryBusy = ref(false);
const note = ref('');
async function startEdit(): Promise<void> {
  if (!detail.value) return;
  try { editing.value = await entriesApi.editValues(detail.value.entry.id); editErrors.value = {}; } catch (e) { notify.error(e); }
}
async function saveValues(): Promise<void> {
  if (!editing.value || !detail.value) return;
  entryBusy.value = true;
  try {
    await entriesApi.saveValues(detail.value.entry.id, editing.value.values, editing.value.revision);
    detail.value = await entriesApi.get(detail.value.entry.id);
    editing.value = null;
    emit('changed');
    notify.success(t('m3.entrySaved'));
  } catch (e) {
    if (isApiError(e) && e.data.errors) editErrors.value = e.data.errors as Record<string, string>;
    notify.error(e);
  } finally { entryBusy.value = false; }
}
async function addNote(): Promise<void> {
  if (!detail.value) return;
  entryBusy.value = true;
  try { await entriesApi.addNote(detail.value.entry.id, note.value); note.value = ''; detail.value = await entriesApi.get(detail.value.entry.id); } catch (e) { notify.error(e); } finally { entryBusy.value = false; }
}
let pollTimer: ReturnType<typeof setTimeout> | null = null;
let disposed = false;
function pollDelivery(): void {
  if (pollTimer) clearTimeout(pollTimer);
  const id = detail.value?.entry.id;
  if (disposed || editing.value || !id || !detail.value?.webhooks?.some((d) => d.status === 'pending' || d.status === 'running')) return;
  pollTimer = setTimeout(async () => {
    if (disposed || props.entryId !== id) return;
    try {
      const updated = await entriesApi.get(id);
      if (disposed || props.entryId !== id) return;
      if (JSON.stringify(updated.webhooks) !== JSON.stringify(detail.value?.webhooks)) emit('changed');
      detail.value = updated;
    } catch {
      if (!disposed && props.entryId === id) pollDelivery();
    }
  }, 3000);
}
watch(() => detail.value?.webhooks, pollDelivery);
onBeforeUnmount(() => {
  disposed = true;
  if (pollTimer) clearTimeout(pollTimer);
});
const loading = ref(false);
const open = computed({
  get: () => props.entryId !== null,
  set: (value) => {
    if (!value) emit('close');
  },
});

watch(
  () => props.entryId,
  async (id) => {
    detail.value = null;
    editing.value = null;
    note.value = '';
    if (id === null) return;
    loading.value = true;
    try {
      detail.value = await entriesApi.get(id);
      // Opening an entry marks it as read.
      if (canManage && detail.value.entry.status === 'unread') {
        await entriesApi.update(id, { status: 'read' });
        detail.value.entry.status = 'read';
        emit('changed');
      }
    } catch (e) {
      notify.error(e);
      emit('close');
    } finally {
      loading.value = false;
    }
  },
  { immediate: true },
);

async function change(changes: { status?: EntryStatus; starred?: boolean }): Promise<void> {
  if (!detail.value) return;
  try {
    const updated = await entriesApi.update(detail.value.entry.id, changes);
    detail.value.entry.status = updated.status;
    detail.value.entry.starred = updated.starred;
    emit('changed');
    if (changes.status === 'trash' || changes.status === 'spam') {
      notify.success(changes.status === 'trash' ? t('entries.movedToTrash') : t('entries.markedSpam'));
      emit('close');
    }
  } catch (e) {
    notify.error(e);
  }
}

const confirmDelete = ref(false);
async function remove(): Promise<void> {
  if (!detail.value) return;
  try {
    await entriesApi.remove(detail.value.entry.id);
    confirmDelete.value = false;
    notify.success(t('entries.deleted'));
    emit('changed');
    emit('close');
  } catch (e) {
    notify.error(e);
  }
}
</script>

<template>
  <SideSheet v-model="open" :title="t('entries.entry', { id: entryId ?? '' })" :subtitle="detail ? dateTime(detail.entry.createdAt) : undefined" :width="640">
    <div v-if="loading" class="swf-forms__loading"><q-skeleton v-for="i in 5" :key="i" type="text" /></div>
    <div v-else-if="detail" class="swf-stack">
      <div v-if="editing" class="swf-stack swf-entry-editor">
        <p class="swf-muted">{{ t('m3.editHelp') }}</p>
        <ul v-if="Object.keys(editErrors).length" role="alert"><li v-for="(message, path) in editErrors" :key="path">{{ message }}</li></ul>
        <EntryValueFields :fields="editing.fields" :values="editing.values" @update="editing.values = $event" />
        <div class="swf-row"><q-btn no-caps color="primary" :label="t('common.save')" :loading="entryBusy" @click="saveValues" /><q-btn flat no-caps :label="t('common.cancel')" @click="editing = null" /></div>
      </div>
      <EntryFields v-else :nodes="detail.fields" />
      <div v-if="canManage" class="swf-stack">
        <q-btn v-if="!editing" outline no-caps icon="r_edit" :label="t('m3.editEntry')" @click="startEdit" />
        <q-input v-model="note" type="textarea" outlined dense :maxlength="2000" :label="t('m3.note')" />
        <q-btn flat no-caps :label="t('m3.addNote')" :disable="!note.trim()" :loading="entryBusy" @click="addNote" />
      </div>
      <p v-if="detail.fields.length === 0" class="swf-muted">{{ t('entries.noData') }}</p>

      <section class="swf-entry-meta">
        <h3 class="swf-eyebrow">{{ t('entries.meta') }}</h3>
        <dl>
          <dt>{{ t('entries.status') }}</dt>
          <dd>{{ t(`entries.statuses.${detail.entry.status}`) }}</dd>
          <dt>{{ t('entries.user') }}</dt>
          <dd>{{ detail.user || t('entries.guest') }}</dd>
          <template v-if="detail.entry.meta.sourceUrl">
            <dt>{{ t('entries.source') }}</dt>
            <dd><a :href="detail.entry.meta.sourceUrl" target="_blank" rel="noopener">{{ detail.entry.meta.sourceUrl }}</a></dd>
          </template>
          <dt>{{ t('entries.version') }}</dt>
          <dd class="swf-num">v{{ detail.entry.formVersion }}</dd>
        </dl>
      </section>

      <section v-if="detail.webhooks?.length" class="swf-entry-meta swf-stack">
        <h3 class="swf-eyebrow">{{ t('webhooks.deliveries') }}</h3>
        <div v-for="delivery in detail.webhooks" :key="delivery.id" class="swf-stack swf-stack--tight">
          <strong>{{ delivery.webhookId }} · {{ t(`webhooks.states.${delivery.status}`) }}</strong>
          <span class="swf-small">{{ t('webhooks.attempts', { n: delivery.attempts }) }} · HTTP {{ delivery.httpCode }} · {{ delivery.durationMs }} ms</span>
          <span v-if="delivery.nextAttemptAt" class="swf-muted swf-small">{{ t('webhooks.nextAttempt', { date: dateTime(delivery.nextAttemptAt) }) }}</span>
          <span class="swf-small">{{ delivery.message }}</span>
          <q-btn v-if="canResend && ['success', 'failed'].includes(delivery.status)" flat no-caps icon="r_refresh" :label="t('webhooks.resend')" :loading="resending === delivery.id" :disable="resending !== null" @click="resend(delivery.id)" />
        </div>
      </section>

      <section v-if="detail.log.length > 0" class="swf-entry-meta">
        <h3 class="swf-eyebrow">{{ t('entries.log') }}</h3>
        <ul class="swf-entry-log">
          <li v-for="(item, i) in detail.log" :key="i" :class="`swf-entry-log--${item.status}`">
            <q-icon :name="item.status === 'success' ? 'r_check_circle' : item.status === 'failed' ? 'r_error' : 'r_remove_circle_outline'" size="16px" aria-hidden="true" />
            <span><strong>{{ t(`entries.logTypes.${item.type}`, item.type) }}</strong> · {{ item.message }}</span>
            <span class="swf-muted swf-small">{{ dateTime(item.createdAt) }}</span>
          </li>
        </ul>
      </section>
    </div>

    <template v-if="detail && canManage" #footer>
      <q-btn flat no-caps :icon="detail.entry.starred ? 'r_star' : 'r_star_border'" :label="detail.entry.starred ? t('entries.actions.unstar') : t('entries.actions.star')" @click="change({ starred: !detail.entry.starred })" />
      <q-btn flat no-caps icon="r_mark_email_unread" :label="t('entries.actions.unread')" @click="change({ status: 'unread' })" />
      <template v-if="detail.entry.status === 'trash' || detail.entry.status === 'spam'">
        <q-btn flat no-caps icon="r_restore" :label="t('entries.actions.restore')" @click="change({ status: 'read' })" />
        <q-btn flat no-caps color="negative" icon="r_delete_forever" :label="t('entries.actions.deleteForever')" @click="confirmDelete = true" />
      </template>
      <template v-else>
        <q-btn flat no-caps icon="r_report" :label="t('entries.actions.spam')" @click="change({ status: 'spam' })" />
        <q-btn flat no-caps color="negative" icon="r_delete" :label="t('entries.actions.trash')" @click="change({ status: 'trash' })" />
      </template>
    </template>
  </SideSheet>

  <ConfirmDialog v-model="confirmDelete" :title="t('entries.deleteTitle')" :message="t('entries.deleteText')" :confirm-label="t('entries.actions.deleteForever')" @confirm="remove" />
</template>
