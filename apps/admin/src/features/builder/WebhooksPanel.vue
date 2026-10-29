<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SwfCard from '@/components/ui/SwfCard.vue';
import { useBuilderStore } from '@/stores/builder';
import type { Webhook } from '@/types/api';
import LogicEditor from './LogicEditor.vue';
import { newId } from './document';

const { t } = useI18n();
const store = useBuilderStore();
const webhooks = computed(() => store.document?.settings.webhooks ?? []);
const fields = computed(() => store.tree.ids()
  .filter((id) => store.tree.isData(id) && store.tree.scopeOf(id) === null)
  .map((id) => {
    const parts: string[] = [];
    let current: string | null = id;
    while (current !== null) {
      const node = store.tree.node(current);
      if (node && store.tree.isData(current)) parts.unshift(node.label || node.key || current);
      current = store.tree.parentOf(current);
    }
    return { value: id, label: parts.join(' › ') };
  }));

function update(id: string, patch: Partial<Webhook>): void {
  store.updateFormSettings((s) => {
    const webhook = s.webhooks?.find((w) => w.id === id);
    if (webhook) Object.assign(webhook, patch);
  });
}

function add(): void {
  store.updateFormSettings((s) => {
    s.webhooks ??= [];
    s.webhooks.push({ id: newId('w'), name: t('webhooks.newName'), enabled: false, url: '', method: 'POST', format: 'json', headers: [], mapping: [], logic: null });
  });
}

function remove(id: string): void {
  store.updateFormSettings((s) => { s.webhooks = s.webhooks?.filter((w) => w.id !== id); });
}

function header(w: Webhook, index: number, key: 'name' | 'value', value: unknown): void {
  const headers = w.headers.map((h, i) => i === index ? { ...h, [key]: String(value ?? '') } : h);
  update(w.id, { headers });
}

function mapping(w: Webhook, index: number, key: 'field' | 'target', value: unknown): void {
  const entries = w.mapping.map((m, i) => i === index ? { ...m, [key]: String(value ?? '') } : m);
  update(w.id, { mapping: entries });
}
</script>

<template>
  <div class="swf-stack swf-webhooks">
    <div class="swf-row swf-row--between">
      <p class="swf-muted">{{ t('webhooks.intro') }}</p>
      <q-btn unelevated no-caps color="primary" icon="r_add" :label="t('webhooks.add')" :disable="webhooks.length >= 20" @click="add" />
    </div>
    <p role="status" class="swf-muted">{{ t('webhooks.deliveryPending') }}</p>
    <SwfCard v-for="w in webhooks" :key="w.id" :title="w.name">
      <template #actions>
        <q-toggle :model-value="w.enabled" :aria-label="t('webhooks.enabled')" @update:model-value="update(w.id, { enabled: !!$event })" />
        <q-btn flat round dense icon="r_delete" :aria-label="t('common.delete')" @click="remove(w.id)" />
      </template>
      <div class="swf-stack">
        <p v-if="store.errorsById.has(`webhook:${w.id}`)" role="alert" class="text-negative">{{ store.errorsById.get(`webhook:${w.id}`)?.join(' ') }}</p>
        <q-input :model-value="w.name" outlined dense :label="t('webhooks.name')" @update:model-value="update(w.id, { name: String($event ?? '') })" />
        <q-input :model-value="w.url" outlined dense type="url" :label="t('webhooks.url')" @update:model-value="update(w.id, { url: String($event ?? '') })" />
        <div class="swf-grid">
          <q-select :model-value="w.method" :options="['POST', 'PUT']" outlined dense :label="t('webhooks.method')" @update:model-value="update(w.id, { method: $event })" />
          <q-select :model-value="w.format" :options="[{ value: 'json', label: 'JSON' }, { value: 'form', label: 'form-encoded' }]" emit-value map-options outlined dense :label="t('webhooks.format')" @update:model-value="update(w.id, { format: $event })" />
        </div>
        <q-input :model-value="w.secret ?? ''" type="password" autocomplete="new-password" outlined dense :label="t('webhooks.secret')" :hint="w.hasSecret ? t('webhooks.secretSaved') : t('webhooks.secretHint')" @update:model-value="update(w.id, { secret: String($event ?? '') })" />
        <div class="swf-stack">
          <strong>{{ t('webhooks.headers') }}</strong>
          <div v-for="(h, i) in w.headers" :key="i" class="swf-row">
            <q-input :model-value="h.name" outlined dense :label="t('webhooks.headerName')" @update:model-value="header(w, i, 'name', $event)" />
            <q-input :model-value="h.value" outlined dense :label="t('webhooks.headerValue')" @update:model-value="header(w, i, 'value', $event)" />
            <q-btn flat round dense icon="r_delete" :aria-label="t('common.delete')" @click="update(w.id, { headers: w.headers.filter((_, j) => j !== i) })" />
          </div>
          <q-btn flat no-caps icon="r_add" :label="t('webhooks.addHeader')" :disable="w.headers.length >= 50" @click="update(w.id, { headers: [...w.headers, { name: '', value: '' }] })" />
        </div>
        <div class="swf-stack">
          <strong>{{ t('webhooks.mapping') }}</strong>
          <p class="swf-muted">{{ t('webhooks.mappingHint') }}</p>
          <div v-for="(m, i) in w.mapping" :key="i" class="swf-row">
            <q-select :model-value="m.field" :options="fields" emit-value map-options outlined dense :label="t('webhooks.field')" @update:model-value="mapping(w, i, 'field', $event)" />
            <q-input :model-value="m.target" outlined dense :label="t('webhooks.target')" @update:model-value="mapping(w, i, 'target', $event)" />
            <q-btn flat round dense icon="r_delete" :aria-label="t('common.delete')" @click="update(w.id, { mapping: w.mapping.filter((_, j) => j !== i) })" />
          </div>
          <q-btn flat no-caps icon="r_add" :label="t('webhooks.addMapping')" :disable="w.mapping.length >= 50" @click="update(w.id, { mapping: [...w.mapping, { field: '', target: '' }] })" />
        </div>
        <LogicEditor :logic="w.logic" :owner-id="null" subject="webhook" @update="update(w.id, { logic: $event })" />
      </div>
    </SwfCard>
  </div>
</template>
