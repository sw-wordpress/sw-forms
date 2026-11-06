<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { SettingDefinition } from '@/types/api';
import OptionsEditor from './OptionsEditor.vue';

/** Renders one setting of a settings schema (docs/extending.md). Unknown types fall back to text. */
const props = withDefaults(defineProps<{ setting: SettingDefinition; value: unknown; settings: Record<string, unknown>; fields?: { value: string; label: string; type: string }[]; depth?: number }>(), { fields: () => [], depth: 0 });
const { t } = useI18n();
const emit = defineEmits<{ (e: 'update', value: unknown): void }>();

const text = computed(() => (props.value === null || props.value === undefined ? '' : String(props.value)));
const optionChoices = computed(() => {
  const options = props.settings.options;
  return Array.isArray(options) ? (options as { label: string; value: string }[]) : [];
});

const rows = computed<Record<string, unknown>[]>(() => Array.isArray(props.value) ? props.value.filter((row) => row && typeof row === 'object' && !Array.isArray(row)) : []);
const limit = computed(() => Math.max(0, Math.min(100, props.setting.maxItems ?? 100)));
const pickerChoices = computed(() => props.fields.filter((field) => !props.setting.fieldTypes || props.setting.fieldTypes.includes(field.type)));
const children = computed<SettingDefinition[]>(() => props.setting.type === 'key-value'
  ? [{ name: 'key', type: 'text', tab: 'general', label: t('settingsSchema.key') }, { name: 'value', type: 'text', tab: 'general', label: t('settingsSchema.value') }]
  : props.setting.fields ?? []);
function changeRow(index: number, name: string, value: unknown): void {
  emit('update', rows.value.map((row, i) => i === index ? { ...row, [name]: value } : { ...row }));
}
function addRow(): void {
  if (rows.value.length >= limit.value) return;
  const row = Object.fromEntries(children.value.map((field) => [field.name, JSON.parse(JSON.stringify(field.default ?? null))]));
  emit('update', [...rows.value, row]);
}
function removeRow(index: number): void { emit('update', rows.value.filter((_, i) => i !== index)); }
function visibleChild(child: SettingDefinition, row: Record<string, unknown>): boolean { return !child.showIf || child.showIf.equals.includes(row[child.showIf.setting]); }

function updateNumber(raw: string | number | null): void {
  if (raw === null || raw === '') {
    emit('update', null);
    return;
  }
  const n = Number(raw);
  if (Number.isNaN(n)) return;
  emit('update', props.setting.integer ? Math.trunc(n) : n);
}
</script>

<template>
  <q-select
    v-if="setting.type === 'field-picker'"
    :model-value="text"
    :options="pickerChoices"
    :label="setting.label"
    :hint="setting.help"
    outlined dense clearable emit-value map-options
    @update:model-value="emit('update', $event ?? '')"
  />
  <fieldset v-else-if="(setting.type === 'key-value' || setting.type === 'repeater') && depth < 4" class="swf-setting-block">
    <legend>{{ setting.label }}</legend>
    <p v-if="setting.help" class="swf-muted">{{ setting.help }}</p>
    <div v-for="(row, index) in rows" :key="index" class="swf-stack swf-stack--tight">
      <template v-for="child in children" :key="child.name">
        <SettingControl v-if="visibleChild(child, row)" :setting="child" :settings="row" :value="row[child.name]" :fields="fields" :depth="depth + 1" @update="changeRow(index, child.name, $event)" />
      </template>
      <q-btn flat dense no-caps icon="r_delete" :label="t('settingsSchema.removeRow', { n: index + 1 })" @click="removeRow(index)" />
    </div>
    <q-btn flat dense no-caps icon="r_add" :label="t('settingsSchema.addRow')" :disable="rows.length >= limit" @click="addRow" />
  </fieldset>
  <q-toggle
    v-else-if="setting.type === 'toggle'"
    :model-value="value === true"
    :label="setting.label"
    @update:model-value="emit('update', $event)"
  />
  <q-select
    v-else-if="setting.type === 'select'"
    :model-value="text"
    outlined
    dense
    emit-value
    map-options
    :options="setting.choices ?? []"
    :label="setting.label"
    :hint="setting.help"
    @update:model-value="emit('update', $event)"
  />
  <q-input
    v-else-if="setting.type === 'number'"
    :model-value="text"
    type="number"
    outlined
    dense
    clearable
    :min="setting.min"
    :max="setting.max"
    :label="setting.label"
    :hint="setting.help"
    @update:model-value="updateNumber"
  />
  <q-input
    v-else-if="setting.type === 'textarea' || setting.type === 'html'"
    :model-value="text"
    type="textarea"
    autogrow
    outlined
    dense
    :label="setting.label"
    :hint="setting.help"
    @update:model-value="emit('update', $event ?? '')"
  />
  <div v-else-if="setting.type === 'options'" class="swf-setting-block">
    <p class="swf-setting-label">{{ setting.label }}</p>
    <p v-if="setting.help" class="swf-muted">{{ setting.help }}</p>
    <OptionsEditor :allow-images="setting.images" :model-value="(Array.isArray(value) ? value : []) as { label: string; value: string }[]" @update:model-value="emit('update', $event)" />
  </div>
  <q-select
    v-else-if="setting.type === 'list'"
    :model-value="Array.isArray(value) ? value : []"
    outlined
    dense
    multiple
    use-chips
    emit-value
    map-options
    option-label="label"
    option-value="value"
    :options="optionChoices"
    :label="setting.label"
    :hint="setting.help"
    @update:model-value="emit('update', $event ?? [])"
  />
  <q-input
    v-else
    :model-value="text"
    outlined
    dense
    :input-class="setting.type === 'code' ? 'swf-mono' : undefined"
    :label="setting.label"
    :hint="setting.help"
    @update:model-value="emit('update', $event ?? '')"
  />
</template>
