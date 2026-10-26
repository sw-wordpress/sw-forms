<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { settingsApi } from '@/api';
import PageHeader from '@/components/ui/PageHeader.vue';
import SwfCard from '@/components/ui/SwfCard.vue';
import { useAsyncData } from '@/composables/useAsyncData';
import { useNotify } from '@/composables/useNotify';
import type { GlobalSettings } from '@/types/api';

const { t } = useI18n();
const notify = useNotify();
const form = ref<GlobalSettings | null>(null);
const saving = ref(false);
const { loading, error, load } = useAsyncData(
  async () => {
    form.value = await settingsApi.get();
    return form.value;
  },
  { immediate: true },
);

/** One host, wildcard, IP or CIDR range per line. */
const allowedHosts = computed({
  get: () => (form.value?.webhook_allowed_hosts ?? []).join('\n'),
  set: (value: string) => {
    if (form.value) form.value.webhook_allowed_hosts = value.split(/[\s,]+/).filter((h) => h !== '');
  },
});

async function save(): Promise<void> {
  if (!form.value) return;
  saving.value = true;
  try {
    form.value = await settingsApi.save(form.value);
    notify.success(t('settings.saved'));
  } catch (e) {
    notify.error(e);
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <div class="swf-stack">
    <PageHeader :title="t('settings.title')" :subtitle="t('settings.subtitle')" />
    <div v-if="loading && !form"><q-skeleton type="rect" height="240px" /></div>
    <SwfCard v-else-if="error" :title="t('errors.loadFailed')"><q-btn outline no-caps :label="t('common.retry')" @click="load" /></SwfCard>
    <q-form v-else-if="form" class="swf-stack" @submit.prevent="save">
      <SwfCard :title="t('settings.privacy')" :subtitle="t('settings.privacyText')" icon="r_privacy_tip">
        <q-option-group
          v-model="form.ip_mode"
          :options="[
            { value: 'hash', label: t('settings.ipHash') },
            { value: 'none', label: t('settings.ipNone') },
          ]"
        />
      </SwfCard>
      <SwfCard :title="t('settings.email')" :subtitle="t('settings.emailText')" icon="r_mail">
        <div class="swf-grid">
          <q-input v-model="form.from_email" outlined dense type="email" :label="t('settings.fromEmail')" :hint="t('settings.fromEmailHint')" />
          <q-input v-model="form.from_name" outlined dense :label="t('settings.fromName')" />
        </div>
      </SwfCard>
      <SwfCard :title="t('settings.webhooks')" :subtitle="t('settings.webhooksText')" icon="r_webhook">
        <div class="swf-stack">
          <q-input
            v-model="form.webhook_alert_email"
            outlined
            dense
            type="email"
            :label="t('settings.webhookAlertEmail')"
            :hint="t('settings.webhookAlertEmailHint')"
          />
          <q-input
            v-model="allowedHosts"
            outlined
            type="textarea"
            autogrow
            class="swf-mono"
            :label="t('settings.webhookAllowedHosts')"
            :hint="t('settings.webhookAllowedHostsHint')"
            data-test="allowed-hosts"
          />
        </div>
      </SwfCard>
      <SwfCard :title="t('settings.frontend')" icon="r_web">
        <q-toggle v-model="form.load_css" :label="t('settings.loadCss')" />
        <p class="swf-muted swf-small">{{ t('settings.loadCssHint') }}</p>
      </SwfCard>
      <SwfCard :title="t('settings.uninstall')" icon="r_delete_sweep">
        <q-toggle v-model="form.delete_data_on_uninstall" :label="t('settings.deleteData')" />
        <p class="swf-muted swf-small">{{ t('settings.deleteDataHint') }}</p>
      </SwfCard>
      <div><q-btn type="submit" unelevated no-caps color="primary" icon="r_save" :loading="saving" :label="t('common.save')" /></div>
    </q-form>
  </div>
</template>
