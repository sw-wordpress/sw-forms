<script setup lang="ts">
import { computed } from 'vue';
import type { SettingDefinition } from '@/types/api';
import OptionsEditor from './OptionsEditor.vue';

/** Renders one setting of a settings schema (docs/extending.md). Unknown types fall back to text. */
const props = defineProps<{ setting: SettingDefinition; value: unknown; settings: Record<string, unknown> }>();
const emit = defineEmits<{ (e: 'update', value: unknown): void }>();

const text = computed(() => (props.value === null || props.value === undefined ? '' : String(props.value)));
const optionChoices = computed(() => {
  const options = props.settings.options;
  return Array.isArray(options) ? (options as { label: string; value: string }[]) : [];
});

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
  <q-toggle
    v-if="setting.type === 'toggle'"
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
