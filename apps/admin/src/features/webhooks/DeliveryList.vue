<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useFormat } from '@/composables/useFormat';
import type { WebhookDelivery } from '@/types/api';

/** Webhook deliveries with status, last result and resend / dismiss actions. */
defineProps<{
  deliveries: WebhookDelivery[];
  /** Webhook id => name; shows the webhook per row when given. */
  names?: Record<string, string>;
  /** Entry links per row (form level list). */
  showEntry?: boolean;
  canResend?: boolean;
}>();
const emit = defineEmits<{ (e: 'resend', id: number): void; (e: 'dismiss', id: number): void }>();
const { t } = useI18n();
const { dateTime } = useFormat();

const icons: Record<string, string> = {
  pending: 'r_schedule',
  success: 'r_check_circle',
  failed: 'r_error',
  dismissed: 'r_remove_circle_outline',
};
</script>

<template>
  <ul class="swf-entry-log swf-deliveries">
    <li v-for="d in deliveries" :key="d.id" :class="`swf-entry-log--${d.status}`" :data-delivery="d.id">
      <q-icon :name="icons[d.status] ?? 'r_help'" size="16px" aria-hidden="true" />
      <span class="swf-deliveries__text">
        <strong>{{ t(`webhooks.status.${d.status}`) }}</strong>
        <template v-if="names"> · {{ names[d.webhookId] ?? d.webhookId }}</template>
        <template v-if="showEntry && d.entryId > 0">
          · <router-link :to="`/forms/${d.formId}/entries/${d.entryId}`">{{ t('webhooks.entry', { id: d.entryId }) }}</router-link>
        </template>
        <template v-if="d.attempts > 0"> · {{ t('webhooks.attempts', { n: d.attempts }, d.attempts) }}</template>
        <span v-if="d.message" class="swf-deliveries__message">{{ d.message }}</span>
        <span v-if="d.status === 'pending' && d.nextAttemptAt" class="swf-muted swf-small">{{ t('webhooks.nextAttempt', { date: dateTime(d.nextAttemptAt) }) }}</span>
      </span>
      <span class="swf-deliveries__side">
        <span class="swf-muted swf-small">{{ dateTime(d.updatedAt) }}</span>
        <span v-if="canResend" class="swf-deliveries__actions">
          <q-btn v-if="d.resendable" flat dense no-caps size="sm" icon="r_replay" :label="t('webhooks.resend')" @click="emit('resend', d.id)" />
          <q-btn v-if="d.status === 'failed'" flat dense no-caps size="sm" icon="r_done" :label="t('webhooks.dismiss')" @click="emit('dismiss', d.id)" />
        </span>
      </span>
    </li>
  </ul>
</template>
