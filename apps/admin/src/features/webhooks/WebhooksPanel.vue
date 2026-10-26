<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { webhooksApi } from '@/api';
import EmptyState from '@/components/ui/EmptyState.vue';
import SwfCard from '@/components/ui/SwfCard.vue';
import { useClipboard } from '@/composables/useClipboard';
import { useNotify } from '@/composables/useNotify';
import { useBuilderStore } from '@/stores/builder';
import LogicEditor from '@/features/builder/LogicEditor.vue';
import { newId } from '@/features/builder/document';
import type { Webhook, WebhookDelivery, WebhookHeader, WebhookMapping, WebhookTestResult } from '@/types/api';
import DeliveryList from './DeliveryList.vue';
import { generateSecret, hostOf, mappableFields, targetName } from './webhooks';

const { t } = useI18n();
const store = useBuilderStore();
const notify = useNotify();
const copy = useClipboard();
const webhooks = computed(() => store.document?.settings.webhooks ?? []);
const open = ref<string | null>(webhooks.value[0]?.id ?? null);
/** Webhooks of the published version: only those have deliveries. */
const published = computed(() => new Set(store.meta?.document.settings.webhooks?.map((w) => w.id) ?? []));
const fields = computed(() => mappableFields(store.tree));

function update(id: string, patch: Partial<Webhook>, coalesce = ''): void {
  store.updateFormSettings((s) => {
    const w = s.webhooks.find((x) => x.id === id);
    if (w) Object.assign(w, patch);
  }, coalesce === '' ? '' : `webhook:${id}:${coalesce}`);
}

function mutate(id: string, change: (w: Webhook) => void, coalesce = ''): void {
  store.updateFormSettings((s) => {
    const w = s.webhooks.find((x) => x.id === id);
    if (w) change(w);
  }, coalesce === '' ? '' : `webhook:${id}:${coalesce}`);
}

function add(): void {
  const id = newId('w');
  store.updateFormSettings((s) =>
    s.webhooks.push({
      id,
      name: t('webhooks.newName'),
      enabled: true,
      url: '',
      method: 'POST',
      format: 'json',
      envelope: true,
      fields: 'all',
      mapping: [],
      headers: [],
      logic: null,
    }),
  );
  open.value = id;
}

function duplicate(webhook: Webhook): void {
  const id = newId('w');
  store.updateFormSettings((s) => {
    const index = s.webhooks.findIndex((x) => x.id === webhook.id);
    // Secrets are not copied: they belong to the original webhook id.
    const copyOf: Webhook = { ...structuredClone(webhook), id, name: `${webhook.name} (2)`, hasSecret: false };
    delete copyOf.secret;
    delete copyOf.clearSecret;
    copyOf.headers = copyOf.headers.map((h) => ({ ...h, hasValue: false }));
    s.webhooks.splice(index + 1, 0, copyOf);
  });
  open.value = id;
}

function remove(id: string): void {
  store.updateFormSettings((s) => {
    s.webhooks = s.webhooks.filter((x) => x.id !== id);
  });
}

// ---------- headers ----------
function addHeader(id: string): void {
  mutate(id, (w) => w.headers.push({ name: '', value: '', secret: false }));
}
function updateHeader(id: string, index: number, patch: Partial<WebhookHeader>, coalesce = ''): void {
  mutate(
    id,
    (w) => {
      const header = w.headers[index];
      if (header) Object.assign(header, patch);
    },
    coalesce === '' ? '' : `header:${index}:${coalesce}`,
  );
}
function removeHeader(id: string, index: number): void {
  mutate(id, (w) => w.headers.splice(index, 1));
}

// ---------- mapping ----------
function setFieldsMode(webhook: Webhook, mode: 'all' | 'custom'): void {
  mutate(webhook.id, (w) => {
    w.fields = mode;
    if (mode === 'custom' && w.mapping.length === 0) {
      w.mapping = fields.value.map((f) => ({ field: f.value, name: targetName(store.tree, f.value) }));
    }
  });
}
function addMapping(id: string): void {
  const used = new Set(webhooks.value.find((w) => w.id === id)?.mapping.map((m) => m.field));
  const next = fields.value.find((f) => !used.has(f.value)) ?? fields.value[0];
  if (!next) return;
  mutate(id, (w) => w.mapping.push({ field: next.value, name: targetName(store.tree, next.value) }));
}
function updateMapping(id: string, index: number, patch: Partial<WebhookMapping>, coalesce = ''): void {
  mutate(
    id,
    (w) => {
      const row = w.mapping[index];
      if (!row) return;
      // Picking another field renames the target while it still follows the old field.
      if (patch.field && row.name === targetName(store.tree, row.field)) patch = { ...patch, name: targetName(store.tree, patch.field) };
      Object.assign(row, patch);
    },
    coalesce === '' ? '' : `mapping:${index}:${coalesce}`,
  );
}
function removeMapping(id: string, index: number): void {
  mutate(id, (w) => w.mapping.splice(index, 1));
}
function fieldLabel(id: string): string {
  return fields.value.find((f) => f.value === id)?.label ?? t('webhooks.unknownField');
}

// ---------- signing secret ----------
const revealed = ref<Record<string, boolean>>({});
function generate(id: string): void {
  update(id, { secret: generateSecret(), clearSecret: false });
  revealed.value = { ...revealed.value, [id]: true };
}
function clearSecret(id: string): void {
  update(id, { secret: '', clearSecret: true });
}

// ---------- test ----------
const testing = ref<string | null>(null);
const results = ref<Record<string, WebhookTestResult | undefined>>({});
async function test(webhook: Webhook): Promise<void> {
  if (!store.document) return;
  testing.value = webhook.id;
  try {
    results.value = { ...results.value, [webhook.id]: await webhooksApi.test(store.formId, webhook, store.document) };
  } catch (e) {
    notify.error(e);
  } finally {
    testing.value = null;
  }
}

// ---------- deliveries ----------
const deliveries = ref<Record<string, WebhookDelivery[]>>({});
const failures = ref(0);
const loadingDeliveries = ref(false);
async function loadDeliveries(id: string | null = open.value): Promise<void> {
  if (!id || !published.value.has(id)) return;
  loadingDeliveries.value = true;
  try {
    const list = await webhooksApi.deliveries(store.formId, { webhook: id, per_page: 10 });
    deliveries.value = { ...deliveries.value, [id]: list.items };
    failures.value = list.failures;
  } catch (e) {
    notify.error(e);
  } finally {
    loadingDeliveries.value = false;
  }
}
async function loadFailures(): Promise<void> {
  try {
    failures.value = (await webhooksApi.deliveries(store.formId, { status: 'failed', per_page: 1 })).total;
  } catch {
    failures.value = 0;
  }
}
watch(open, (id) => void loadDeliveries(id));
onMounted(() => {
  void loadFailures();
  void loadDeliveries();
});

async function resend(id: number): Promise<void> {
  try {
    await webhooksApi.resend(id);
    notify.success(t('webhooks.resent'));
    await loadDeliveries();
  } catch (e) {
    notify.error(e);
  }
}
async function dismiss(id: number): Promise<void> {
  try {
    await webhooksApi.dismiss(id);
    await loadDeliveries();
  } catch (e) {
    notify.error(e);
  }
}
async function bulk(action: 'resend' | 'dismiss'): Promise<void> {
  try {
    const result = await webhooksApi.bulk(store.formId, action);
    notify.success(t(action === 'resend' ? 'webhooks.bulkResent' : 'webhooks.bulkDismissed', { n: result.affected }));
    await loadFailures();
    await loadDeliveries();
  } catch (e) {
    notify.error(e);
  }
}
</script>

<template>
  <div class="swf-stack swf-webhooks">
    <div class="swf-row swf-row--between">
      <p class="swf-muted">{{ t('webhooks.intro') }}</p>
      <q-btn unelevated no-caps color="primary" icon="r_add" :label="t('webhooks.add')" @click="add" />
    </div>

    <div v-if="failures > 0" class="swf-webhooks__alert" role="alert">
      <q-icon name="r_error" size="20px" aria-hidden="true" />
      <span>{{ t('webhooks.failures', { n: failures }, failures) }}</span>
      <q-btn flat dense no-caps icon="r_replay" :label="t('webhooks.resendAll')" @click="bulk('resend')" />
      <q-btn flat dense no-caps icon="r_done_all" :label="t('webhooks.dismissAll')" @click="bulk('dismiss')" />
    </div>

    <SwfCard v-if="webhooks.length === 0">
      <EmptyState icon="r_webhook" :title="t('webhooks.emptyTitle')" :text="t('webhooks.emptyText')" compact />
    </SwfCard>

    <SwfCard v-for="w in webhooks" :key="w.id" :padding="open === w.id" :class="{ 'swf-notification--off': !w.enabled }" :data-webhook="w.id">
      <template #header>
        <button type="button" class="swf-notification__head" :aria-expanded="open === w.id" @click="open = open === w.id ? null : w.id">
          <q-icon :name="open === w.id ? 'r_expand_less' : 'r_expand_more'" size="20px" aria-hidden="true" />
          <span class="swf-notification__name">{{ w.name }}</span>
          <span class="swf-muted swf-truncate swf-mono">{{ w.method }} {{ hostOf(w.url) }}</span>
          <span v-if="store.errorsById.get(`webhook:${w.id}`)" class="swf-chip swf-chip--danger">{{ t('canvas.hasErrors') }}</span>
          <span v-if="(w.logic?.rules.length ?? 0) > 0" class="swf-chip"><q-icon name="r_alt_route" size="14px" /> {{ t('canvas.conditional') }}</span>
          <span v-if="(w.hasSecret && !w.clearSecret) || w.secret" class="swf-chip"><q-icon name="r_verified_user" size="14px" /> {{ t('webhooks.signed') }}</span>
        </button>
      </template>
      <template #actions>
        <q-toggle :model-value="w.enabled" :aria-label="t('webhooks.enabled')" @update:model-value="update(w.id, { enabled: !!$event })" />
        <q-btn flat round dense icon="r_content_copy" :aria-label="t('common.duplicate')" @click="duplicate(w)" />
        <q-btn flat round dense icon="r_delete" :aria-label="t('common.delete')" @click="remove(w.id)" />
      </template>

      <div v-if="open === w.id" class="swf-notification__body">
        <ul v-if="store.errorsById.get(`webhook:${w.id}`)" class="swf-webhooks__errors">
          <li v-for="(message, i) in store.errorsById.get(`webhook:${w.id}`)" :key="i">{{ message }}</li>
        </ul>
        <q-input :model-value="w.name" outlined dense :label="t('webhooks.name')" @update:model-value="update(w.id, { name: String($event ?? '') }, 'name')" />
        <div class="swf-webhooks__target">
          <q-select
            :model-value="w.method"
            outlined
            dense
            emit-value
            map-options
            :options="['POST', 'PUT', 'PATCH'].map((m) => ({ value: m, label: m }))"
            :label="t('webhooks.method')"
            @update:model-value="update(w.id, { method: $event })"
          />
          <q-input
            :model-value="w.url"
            outlined
            dense
            type="url"
            :label="t('webhooks.url')"
            :hint="t('webhooks.urlHint')"
            data-test="webhook-url"
            @update:model-value="update(w.id, { url: String($event ?? '') }, 'url')"
          />
        </div>

        <section class="swf-webhooks__section">
          <h3 class="swf-eyebrow">{{ t('webhooks.payload') }}</h3>
          <q-option-group
            :model-value="w.format"
            inline
            :options="[
              { value: 'json', label: t('webhooks.formats.json') },
              { value: 'form', label: t('webhooks.formats.form') },
            ]"
            @update:model-value="update(w.id, { format: $event })"
          />
          <q-toggle :model-value="w.envelope" :label="t('webhooks.envelope')" @update:model-value="update(w.id, { envelope: !!$event })" />
          <p class="swf-muted swf-small">{{ w.envelope ? t('webhooks.envelopeOn') : t('webhooks.envelopeOff') }}</p>
          <q-option-group
            :model-value="w.fields"
            inline
            :options="[
              { value: 'all', label: t('webhooks.fieldsAll') },
              { value: 'custom', label: t('webhooks.fieldsCustom') },
            ]"
            @update:model-value="setFieldsMode(w, $event)"
          />
          <template v-if="w.fields === 'custom'">
            <p class="swf-muted swf-small">{{ t('webhooks.mappingHint') }}</p>
            <div v-for="(row, i) in w.mapping" :key="i" class="swf-webhooks__pair">
              <q-select
                :model-value="row.field"
                outlined
                dense
                emit-value
                map-options
                :options="fields"
                :display-value="fieldLabel(row.field)"
                :label="t('webhooks.field')"
                @update:model-value="updateMapping(w.id, i, { field: $event })"
              />
              <q-icon name="r_arrow_forward" size="18px" aria-hidden="true" />
              <q-input
                :model-value="row.name"
                outlined
                dense
                input-class="swf-mono"
                :label="t('webhooks.targetName')"
                @update:model-value="updateMapping(w.id, i, { name: String($event ?? '') }, 'name')"
              />
              <q-btn flat round dense icon="r_close" :aria-label="t('common.delete')" @click="removeMapping(w.id, i)" />
            </div>
            <div><q-btn flat dense no-caps icon="r_add" :label="t('webhooks.addMapping')" :disable="fields.length === 0" @click="addMapping(w.id)" /></div>
          </template>
        </section>

        <section class="swf-webhooks__section">
          <h3 class="swf-eyebrow">{{ t('webhooks.headers') }}</h3>
          <p class="swf-muted swf-small">{{ t('webhooks.headersHint') }}</p>
          <div v-for="(h, i) in w.headers" :key="i" class="swf-webhooks__pair">
            <q-input :model-value="h.name" outlined dense input-class="swf-mono" :label="t('webhooks.headerName')" @update:model-value="updateHeader(w.id, i, { name: String($event ?? '') }, 'name')" />
            <span aria-hidden="true">:</span>
            <q-input
              :model-value="h.value"
              outlined
              dense
              :type="h.secret ? 'password' : 'text'"
              autocomplete="off"
              :label="t('webhooks.headerValue')"
              :placeholder="h.secret && h.hasValue ? t('webhooks.secretStored') : undefined"
              :hint="h.secret ? (h.hasValue ? t('webhooks.secretKeep') : t('webhooks.secretWriteOnly')) : undefined"
              @update:model-value="updateHeader(w.id, i, { value: String($event ?? '') }, 'value')"
            />
            <q-btn
              flat
              round
              dense
              :icon="h.secret ? 'r_lock' : 'r_lock_open'"
              :color="h.secret ? 'primary' : undefined"
              :aria-label="t('webhooks.headerSecret')"
              :aria-pressed="h.secret"
              @click="updateHeader(w.id, i, { secret: !h.secret })"
            >
              <q-tooltip>{{ t('webhooks.headerSecret') }}</q-tooltip>
            </q-btn>
            <q-btn flat round dense icon="r_close" :aria-label="t('common.delete')" @click="removeHeader(w.id, i)" />
          </div>
          <div><q-btn flat dense no-caps icon="r_add" :label="t('webhooks.addHeader')" @click="addHeader(w.id)" /></div>
        </section>

        <section class="swf-webhooks__section">
          <h3 class="swf-eyebrow">{{ t('webhooks.signature') }}</h3>
          <p class="swf-muted swf-small">{{ t('webhooks.signatureHint') }}</p>
          <div class="swf-webhooks__secret">
            <q-input
              :model-value="w.secret ?? ''"
              outlined
              dense
              :type="revealed[w.id] ? 'text' : 'password'"
              autocomplete="off"
              input-class="swf-mono"
              :label="t('webhooks.secret')"
              :placeholder="w.hasSecret && !w.clearSecret ? t('webhooks.secretStored') : undefined"
              :hint="w.secret ? t('webhooks.secretCopyNow') : w.hasSecret && !w.clearSecret ? t('webhooks.secretKeep') : t('webhooks.secretNone')"
              data-test="webhook-secret"
              @update:model-value="update(w.id, { secret: String($event ?? ''), clearSecret: false }, 'secret')"
            >
              <template #append>
                <q-btn v-if="w.secret" flat round dense icon="r_content_copy" :aria-label="t('common.copy')" @click="copy(w.secret)" />
              </template>
            </q-input>
            <q-btn outline no-caps icon="r_key" :label="t('webhooks.generate')" @click="generate(w.id)" />
            <q-btn v-if="(w.hasSecret && !w.clearSecret) || w.secret" flat no-caps icon="r_key_off" :label="t('webhooks.removeSecret')" @click="clearSecret(w.id)" />
          </div>
        </section>

        <div class="swf-notification__logic">
          <LogicEditor :logic="w.logic" :owner-id="null" subject="notification" @update="update(w.id, { logic: $event })" />
        </div>

        <section class="swf-webhooks__section">
          <div class="swf-row swf-row--between">
            <h3 class="swf-eyebrow">{{ t('webhooks.test') }}</h3>
            <q-btn outline no-caps icon="r_send" :label="t('webhooks.sendTest')" :loading="testing === w.id" :disable="w.url.trim() === ''" data-test="webhook-test" @click="test(w)" />
          </div>
          <p class="swf-muted swf-small">{{ t('webhooks.testHint') }}</p>
          <div v-if="results[w.id]" class="swf-webhooks__result" :class="results[w.id]!.success ? 'swf-webhooks__result--ok' : 'swf-webhooks__result--failed'" role="status" data-test="webhook-test-result">
            <q-icon :name="results[w.id]!.success ? 'r_check_circle' : 'r_error'" size="18px" aria-hidden="true" />
            <span>{{ results[w.id]!.message }}</span>
          </div>
          <q-expansion-item v-if="results[w.id]" dense dense-toggle :label="t('webhooks.testPayload')" class="swf-webhooks__payload">
            <pre class="swf-mono">{{ JSON.stringify(results[w.id]!.payload, null, 2) }}</pre>
          </q-expansion-item>
        </section>

        <section v-if="published.has(w.id)" class="swf-webhooks__section">
          <div class="swf-row swf-row--between">
            <h3 class="swf-eyebrow">{{ t('webhooks.deliveries') }}</h3>
            <q-btn flat round dense icon="r_refresh" :loading="loadingDeliveries" :aria-label="t('common.refresh')" @click="loadDeliveries(w.id)" />
          </div>
          <DeliveryList v-if="(deliveries[w.id] ?? []).length > 0" :deliveries="deliveries[w.id] ?? []" show-entry can-resend @resend="resend" @dismiss="dismiss" />
          <p v-else class="swf-muted swf-small">{{ t('webhooks.noDeliveries') }}</p>
        </section>
        <p v-else class="swf-muted swf-small">{{ t('webhooks.unpublished') }}</p>
      </div>
    </SwfCard>
  </div>
</template>
