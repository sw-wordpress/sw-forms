<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { ElementNode } from '@shared/types';
const { t } = useI18n();
const props = defineProps<{ fields: ElementNode[]; values: Record<string, unknown> }>();
const emit = defineEmits<{ (e: 'update', values: Record<string, unknown>): void }>();
const options = (f: ElementNode) => (f.settings?.options ?? []) as { label: string; value: string }[];
const rows = (f: ElementNode) => (f.settings?.rows ?? []) as { label: string; value: string }[];
const files = (f: ElementNode) => ['file', 'image', 'signature', 'audio', 'video'].includes(f.type);
const obj = (v: unknown): Record<string, unknown> => v && typeof v === 'object' && !Array.isArray(v) ? v as Record<string, unknown> : {};
const list = (v: unknown): Record<string, unknown>[] => Array.isArray(v) ? v.map(obj) : [];
function set(id: string, value: unknown): void { emit('update', { ...props.values, [id]: value }); }
function row(id: string, index: number, value: Record<string, unknown>): void { set(id, list(props.values[id]).map((r, i) => i === index ? value : r)); }
</script>
<template>
  <div class="swf-stack swf-stack--tight">
    <template v-for="field in fields" :key="field.id">
      <template v-if="field.columns">
        <EntryValueFields v-for="column in field.columns" :key="column.id" :fields="column.children" :values="values" @update="emit('update', $event)" />
      </template>
      <fieldset v-else-if="field.children" class="swf-entry-edit-group">
        <legend>{{ field.label || field.key }}</legend>
        <template v-if="field.settings?.repeatable">
          <EntryValueFields v-for="(value, i) in list(values[field.id])" :key="i" :fields="field.children" :values="value" @update="row(field.id, i, $event)" />
        </template>
        <EntryValueFields v-else :fields="field.children" :values="values" @update="emit('update', $event)" />
      </fieldset>
      <div v-else class="swf-stack swf-stack--tight">
        <p v-if="files(field)">{{ field.label || field.key }}: {{ (values[field.id] as string[] ?? []).join(', ') }}</p>
        <q-checkbox v-else-if="field.type === 'checkbox' && field.settings?.mode === 'single'" :model-value="!!values[field.id]" :label="field.label || field.key" @update:model-value="set(field.id, $event)" />
        <q-select v-else-if="['select', 'radio', 'checkbox'].includes(field.type)" :model-value="values[field.id]" :options="options(field)" :multiple="field.type === 'checkbox' || !!field.settings?.multiple" emit-value map-options outlined dense :label="field.label || field.key" @update:model-value="set(field.id, $event)" />
        <template v-else-if="field.type === 'matrix'">
          <q-select v-for="r in rows(field)" :key="r.value" :model-value="obj(values[field.id])[r.value]" :options="options(field)" emit-value map-options outlined dense :label="`${field.label} · ${r.label}`" @update:model-value="set(field.id, { ...obj(values[field.id]), [r.value]: $event })" />
        </template>
        <template v-else-if="['date-interval', 'time-interval'].includes(field.type)">
          <q-input v-for="(part, index) in ['from', 'to']" :key="part" :model-value="String((values[field.id] as string[] ?? [])[index] ?? '')" outlined dense :label="`${field.label} · ${t(`m3.${part}`)}`" @update:model-value="set(field.id, [0, 1].map((i) => i === index ? String($event ?? '') : String((values[field.id] as string[] ?? [])[i] ?? '')))" />
        </template>
        <q-input v-else-if="field.type === 'tags'" :model-value="(values[field.id] as string[] ?? []).join(', ')" outlined dense :label="field.label || field.key" @update:model-value="set(field.id, String($event ?? '').split(',').map((v) => v.trim()).filter(Boolean))" />
        <q-input v-else :model-value="String(values[field.id] ?? '')" :type="field.type === 'textarea' ? 'textarea' : 'text'" outlined dense :label="field.label || field.key" @update:model-value="set(field.id, String($event ?? ''))" />
      </div>
    </template>
  </div>
</template>
