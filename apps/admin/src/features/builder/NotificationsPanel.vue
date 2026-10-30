<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/ui/EmptyState.vue';
import SwfCard from '@/components/ui/SwfCard.vue';
import { useBuilderStore } from '@/stores/builder';
import type { Notification } from '@/types/api';
import LogicEditor from './LogicEditor.vue';
import MergeTagMenu from './MergeTagMenu.vue';
import { newId } from './document';

const { t } = useI18n();
const store = useBuilderStore();
const notifications = computed(() => store.document?.settings.notifications ?? []);
const open = ref<string | null>(notifications.value[0]?.id ?? null);

function update(id: string, patch: Partial<Notification>, coalesce = ''): void {
  store.updateFormSettings((s) => {
    const n = s.notifications.find((x) => x.id === id);
    if (n) Object.assign(n, patch);
  }, coalesce === '' ? '' : `${id}:${coalesce}`);
}

function add(): void {
  const id = newId('n');
  store.updateFormSettings((s) =>
    s.notifications.push({
      id,
      name: t('notifications.newName'),
      enabled: true,
      to: '{admin_email}',
      subject: t('notifications.defaultSubject'),
      message: '{all_fields}',
      replyTo: '',
      fromName: '',
      logic: null,
    }),
  );
  open.value = id;
}

function duplicate(notification: Notification): void {
  const id = newId('n');
  store.updateFormSettings((s) => {
    const index = s.notifications.findIndex((x) => x.id === notification.id);
    s.notifications.splice(index + 1, 0, { ...structuredClone(notification), id, name: `${notification.name} (2)` });
  });
  open.value = id;
}

function remove(id: string): void {
  store.updateFormSettings((s) => {
    s.notifications = s.notifications.filter((x) => x.id !== id);
  });
}
</script>

<template>
  <div class="swf-stack">
    <div class="swf-row swf-row--between">
      <p class="swf-muted">{{ t('notifications.intro') }}</p>
      <q-btn unelevated no-caps color="primary" icon="r_add" :label="t('notifications.add')" @click="add" />
    </div>

    <SwfCard v-if="notifications.length === 0">
      <EmptyState icon="r_mail" :title="t('notifications.emptyTitle')" :text="t('notifications.emptyText')" compact />
    </SwfCard>

    <SwfCard v-for="n in notifications" :key="n.id" :padding="open === n.id" :class="{ 'swf-notification--off': !n.enabled }">
      <template #header>
        <button type="button" class="swf-notification__head" :aria-expanded="open === n.id" @click="open = open === n.id ? null : n.id">
          <q-icon :name="open === n.id ? 'r_expand_less' : 'r_expand_more'" size="20px" aria-hidden="true" />
          <span class="swf-notification__name">{{ n.name }}</span>
          <span class="swf-muted swf-truncate">{{ n.to }}</span>
          <span v-if="store.errorsById.get(`notification:${n.id}`)" class="swf-chip swf-chip--danger">{{ t('canvas.hasErrors') }}</span>
          <span v-if="(n.logic?.rules.length ?? 0) > 0" class="swf-chip"><q-icon name="r_alt_route" size="14px" /> {{ t('canvas.conditional') }}</span>
        </button>
      </template>
      <template #actions>
        <q-toggle :model-value="n.enabled" :aria-label="t('notifications.enabled')" @update:model-value="update(n.id, { enabled: !!$event })" />
        <q-btn flat round dense icon="r_content_copy" :aria-label="t('common.duplicate')" @click="duplicate(n)" />
        <q-btn flat round dense icon="r_delete" :aria-label="t('common.delete')" @click="remove(n.id)" />
      </template>

      <div v-if="open === n.id" class="swf-notification__body">
        <q-input :model-value="n.name" outlined dense :label="t('notifications.name')" @update:model-value="update(n.id, { name: String($event ?? '') }, 'name')" />
        <div class="swf-with-tags">
          <q-input
            :model-value="n.to"
            outlined
            dense
            :label="t('notifications.to')"
            :hint="t('notifications.toHint')"
            @update:model-value="update(n.id, { to: String($event ?? '') }, 'to')"
          />
          <MergeTagMenu email-only />
        </div>
        <div class="swf-with-tags">
          <q-input :model-value="n.subject" outlined dense :label="t('notifications.subject')" @update:model-value="update(n.id, { subject: String($event ?? '') }, 'subject')" />
          <MergeTagMenu />
        </div>
        <div class="swf-with-tags">
          <q-input
            :model-value="n.message"
            type="textarea"
            autogrow
            outlined
            :label="t('notifications.message')"
            :hint="t('notifications.messageHint')"
            input-style="min-height: 140px"
            @update:model-value="update(n.id, { message: String($event ?? '') }, 'message')"
          />
          <MergeTagMenu html />
        </div>
        <div class="swf-grid">
          <div class="swf-with-tags">
            <q-input :model-value="n.replyTo" outlined dense :label="t('notifications.replyTo')" @update:model-value="update(n.id, { replyTo: String($event ?? '') }, 'replyTo')" />
            <MergeTagMenu email-only />
          </div>
          <q-input :model-value="n.fromName" outlined dense :label="t('notifications.fromName')" :hint="t('notifications.fromHint')" @update:model-value="update(n.id, { fromName: String($event ?? '') }, 'fromName')" />
        </div>
        <div class="swf-notification__logic">
          <LogicEditor :logic="n.logic" :owner-id="null" subject="notification" @update="update(n.id, { logic: $event })" />
        </div>
      </div>
    </SwfCard>
  </div>
</template>
