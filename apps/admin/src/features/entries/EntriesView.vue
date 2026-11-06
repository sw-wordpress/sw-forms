<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { entriesApi } from '@/api';
import EmptyState from '@/components/ui/EmptyState.vue';
import SwfCard from '@/components/ui/SwfCard.vue';
import { useCapabilities } from '@/composables/useCapabilities';
import { useFormat } from '@/composables/useFormat';
import { useNotify } from '@/composables/useNotify';
import type { BulkAction, EntryFilter, EntryList } from '@/types/api';
import EntrySheet from './EntrySheet.vue';

const props = defineProps<{ formId: number; entryId: number | null }>();
const { t } = useI18n();
const router = useRouter();
const notify = useNotify();
const { dateTime, number } = useFormat();
const { can } = useCapabilities();
const canManage = can('swf_manage_entries');

const filter = ref<EntryFilter>('inbox');
const search = ref('');
const page = ref(1);
const order = ref<'asc' | 'desc'>('desc');
const perPage = 25;
const data = ref<EntryList | null>(null);
const loading = ref(false);
const failed = ref('');
const selection = ref<number[]>([]);
let searchTimer: ReturnType<typeof setTimeout> | null = null;

const FILTERS: EntryFilter[] = ['inbox', 'unread', 'starred', 'spam', 'trash'];

async function load(): Promise<void> {
  loading.value = true;
  failed.value = '';
  try {
    data.value = await entriesApi.list(props.formId, {
      filter: filter.value,
      search: search.value.trim(),
      page: page.value,
      per_page: perPage,
      order: order.value,
    });
    selection.value = selection.value.filter((id) => data.value?.items.some((i) => i.id === id));
  } catch (e) {
    failed.value = e instanceof Error ? e.message : String(e);
  } finally {
    loading.value = false;
  }
}

watch([filter, order], () => {
  page.value = 1;
  void load();
});
watch(page, load);
watch(search, () => {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    page.value = 1;
    void load();
  }, 350);
});
watch(() => props.formId, load, { immediate: true });

const allSelected = computed({
  get: () => (data.value?.items.length ?? 0) > 0 && selection.value.length === data.value?.items.length,
  set: (value: boolean) => {
    selection.value = value ? (data.value?.items.map((i) => i.id) ?? []) : [];
  },
});

function open(id: number): void {
  void router.push(`/forms/${props.formId}/entries/${id}`);
}
function closeSheet(): void {
  void router.push(`/forms/${props.formId}/entries`);
}

async function bulk(action: BulkAction): Promise<void> {
  if (selection.value.length === 0) return;
  try {
    const result = await entriesApi.bulk(selection.value, action);
    notify.success(t('entries.bulkDone', { n: result.affected }));
    selection.value = [];
    await load();
  } catch (e) {
    notify.error(e);
  }
}

const bulkActions = computed<{ action: BulkAction; label: string; icon: string }[]>(() => {
  if (filter.value === 'trash' || filter.value === 'spam') {
    return [
      { action: 'restore', label: t('entries.actions.restore'), icon: 'r_restore' },
      { action: 'delete', label: t('entries.actions.deleteForever'), icon: 'r_delete_forever' },
    ];
  }
  return [
    { action: 'read', label: t('entries.actions.read'), icon: 'r_drafts' },
    { action: 'unread', label: t('entries.actions.unread'), icon: 'r_mark_email_unread' },
    { action: 'star', label: t('entries.actions.star'), icon: 'r_star' },
    { action: 'spam', label: t('entries.actions.spam'), icon: 'r_report' },
    { action: 'trash', label: t('entries.actions.trash'), icon: 'r_delete' },
  ];
});
</script>

<template>
  <div class="swf-stack">
    <div class="swf-entries__toolbar">
      <q-tabs v-model="filter" dense no-caps inline-label class="swf-entries__filters" active-color="primary" indicator-color="primary">
        <q-tab v-for="f in FILTERS" :key="f" :name="f" :label="`${t(`entries.filters.${f}`)} (${number(data?.counts[f] ?? 0)})`" />
      </q-tabs>
      <q-input v-model="search" dense outlined clearable debounce="0" class="swf-entries__search" :placeholder="t('entries.search')" :aria-label="t('entries.search')">
        <template #prepend><q-icon name="r_search" /></template>
      </q-input>
      <q-btn outline no-caps icon="r_download" :label="t('m3.exportCsv')" :href="entriesApi.exportUrl(formId, { filter, search: search.trim(), order })" />
    </div>

    <SwfCard :padding="false">
      <div v-if="canManage && selection.length > 0" class="swf-entries__bulk" role="toolbar" :aria-label="t('entries.bulkLabel')">
        <strong>{{ t('entries.selected', { n: selection.length }) }}</strong>
        <q-btn v-for="b in bulkActions" :key="b.action" flat dense no-caps :icon="b.icon" :label="b.label" @click="bulk(b.action)" />
      </div>

      <div v-if="loading && !data" class="swf-forms__loading"><q-skeleton v-for="i in 4" :key="i" type="rect" height="44px" /></div>
      <EmptyState v-else-if="failed" icon="r_error" :title="t('errors.loadFailed')" :text="failed">
        <q-btn outline no-caps :label="t('common.retry')" @click="load" />
      </EmptyState>
      <EmptyState
        v-else-if="data && data.items.length === 0"
        icon="r_inbox"
        :title="search ? t('entries.noMatch') : t('entries.emptyTitle')"
        :text="search ? undefined : t('entries.emptyText')"
      />
      <div v-else-if="data" class="swf-entries__scroll">
        <table class="swf-table swf-entries__table" :aria-busy="loading">
          <thead>
            <tr>
              <th v-if="canManage" scope="col" class="swf-entries__check">
                <q-checkbox v-model="allSelected" dense :aria-label="t('entries.selectAll')" />
              </th>
              <th scope="col" class="swf-entries__star"><span class="swf-sr-only">{{ t('entries.starred') }}</span></th>
              <th scope="col">
                <button type="button" class="swf-sort" @click="order = order === 'desc' ? 'asc' : 'desc'">
                  {{ t('entries.date') }} <q-icon :name="order === 'desc' ? 'r_south' : 'r_north'" size="14px" />
                </button>
              </th>
              <th v-for="column in data.columns" :key="column.id" scope="col">{{ column.label }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="entry in data.items"
              :key="entry.id"
              class="swf-entries__row"
              :class="{ 'swf-entries__row--unread': entry.status === 'unread', 'swf-entries__row--active': entryId === entry.id }"
              @click="open(entry.id)"
            >
              <td v-if="canManage" data-label="" class="swf-entries__check" @click.stop>
                <q-checkbox v-model="selection" :val="entry.id" dense :aria-label="t('entries.selectOne', { id: entry.id })" />
              </td>
              <td data-label="" class="swf-entries__star">
                <q-icon v-if="entry.starred" name="r_star" color="warning" size="18px" :aria-label="t('entries.starred')" />
              </td>
              <td :data-label="t('entries.date')" class="swf-nowrap">
                <a href="#" class="swf-entries__open" @click.prevent.stop="open(entry.id)">{{ dateTime(entry.createdAt) }}</a>
                <span v-if="entry.status === 'unread'" class="swf-dot" :aria-label="t('entries.filters.unread')" />
              </td>
              <td v-for="column in data.columns" :key="column.id" :data-label="column.label" class="swf-entries__cell">
                {{ entry.values[column.id] ?? '' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="data && data.pages > 1" class="swf-entries__pager">
        <q-pagination v-model="page" :max="data.pages" :max-pages="7" direction-links boundary-links />
      </div>
    </SwfCard>

    <EntrySheet :entry-id="entryId" @close="closeSheet" @changed="load" />
  </div>
</template>
