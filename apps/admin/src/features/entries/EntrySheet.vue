<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { entriesApi } from '@/api';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import SideSheet from '@/components/ui/SideSheet.vue';
import { useCapabilities } from '@/composables/useCapabilities';
import { useFormat } from '@/composables/useFormat';
import { useNotify } from '@/composables/useNotify';
import type { EntryDetail, EntryStatus } from '@/types/api';
import EntryFields from './EntryFields.vue';

const props = defineProps<{ entryId: number | null }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'changed'): void }>();
const { t } = useI18n();
const notify = useNotify();
const { dateTime } = useFormat();
const { can } = useCapabilities();
const canManage = can('swf_manage_entries');

const detail = ref<EntryDetail | null>(null);
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
      <EntryFields :nodes="detail.fields" />
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
