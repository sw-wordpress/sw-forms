<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { slugKey } from './document';

interface Option {
  label: string;
  value: string;
  image?: string;
}

/**
 * Choice list editor. The stored value follows the label until it is edited by hand
 * (values end up in entries and webhooks, so they should not change silently later).
 */
const props = defineProps<{ modelValue: Option[] }>();
const emit = defineEmits<{ (e: 'update:modelValue', value: Option[]): void }>();
const { t } = useI18n();
const showValues = ref(false);
const showImages = ref(props.modelValue.some((option) => !!option.image));
const bulkOpen = ref(false);
const bulkText = ref('');

function valueFor(label: string, index: number, list: Option[]): string {
  const base = slugKey(label).replace(/_/g, '-') || `option-${index + 1}`;
  let candidate = base;
  for (let i = 2; list.some((o, j) => j !== index && o.value === candidate); i++) candidate = `${base}-${i}`;
  return candidate;
}

function update(index: number, patch: Partial<Option>): void {
  const list = props.modelValue.map((o) => ({ ...o }));
  const current = list[index];
  if (!current) return;
  const followed = current.value === valueFor(current.label, index, list);
  Object.assign(current, patch);
  if (patch.label !== undefined && followed) current.value = valueFor(current.label, index, list);
  emit('update:modelValue', list);
}

function add(): void {
  const list = [...props.modelValue];
  const label = t('options.newOption', { n: list.length + 1 });
  list.push({ label, value: valueFor(label, list.length, list) });
  emit('update:modelValue', list);
}

function removeAt(index: number): void {
  emit('update:modelValue', props.modelValue.filter((_, i) => i !== index));
}

function moveAt(index: number, delta: number): void {
  const list = [...props.modelValue];
  const [item] = list.splice(index, 1);
  if (!item) return;
  list.splice(index + delta, 0, item);
  emit('update:modelValue', list);
}

function openBulk(): void {
  bulkText.value = props.modelValue.map((o) => (o.value === valueFor(o.label, 0, []) ? o.label : `${o.label} | ${o.value}`)).join('\n');
  bulkOpen.value = true;
}

/** One option per line, optionally `Label | value`. */
function applyBulk(): void {
  const list: Option[] = [];
  for (const line of bulkText.value.split('\n')) {
    const [label = '', value] = line.split('|').map((part) => part.trim());
    if (label === '') continue;
    const resolved = value || valueFor(label, list.length, list);
    const previous = props.modelValue.find((option) => option.value === resolved);
    list.push({ label, value: resolved, ...(previous?.image ? { image: previous.image } : {}) });
  }
  emit('update:modelValue', list);
  bulkOpen.value = false;
}
</script>

<template>
  <div class="swf-options">
    <ol class="swf-options__list">
      <li v-for="(option, index) in modelValue" :key="index" class="swf-options__row">
        <q-input
          :model-value="option.label"
          dense
          outlined
          class="swf-options__label"
          :aria-label="t('options.label', { n: index + 1 })"
          @update:model-value="update(index, { label: String($event ?? '') })"
        />
        <q-input
          v-if="showValues"
          :model-value="option.value"
          dense
          outlined
          class="swf-options__value"
          input-class="swf-mono"
          :aria-label="t('options.value', { n: index + 1 })"
          @update:model-value="update(index, { value: String($event ?? '') })"
        />
        <q-input
          v-if="showImages"
          :model-value="option.image ?? ''"
          dense outlined type="url" class="swf-options__image"
          :aria-label="t('options.image', { n: index + 1 })"
          :placeholder="t('options.imageUrl')"
          @update:model-value="update(index, { image: String($event ?? '') })"
        />
        <q-btn flat round dense size="sm" icon="r_arrow_upward" :disable="index === 0" :aria-label="t('canvas.moveUp')" @click="moveAt(index, -1)" />
        <q-btn flat round dense size="sm" icon="r_close" :aria-label="t('options.remove', { n: index + 1 })" @click="removeAt(index)" />
      </li>
    </ol>
    <div class="swf-row">
      <q-btn flat dense no-caps icon="r_add" :label="t('options.add')" @click="add" />
      <q-btn flat dense no-caps icon="r_playlist_add" :label="t('options.bulk')" @click="openBulk" />
      <q-toggle v-model="showValues" dense :label="t('options.showValues')" />
      <q-toggle v-model="showImages" dense :label="t('options.showImages')" />
    </div>

    <q-dialog v-model="bulkOpen">
      <q-card class="swf-dialog">
        <q-card-section>
          <h2 class="swf-dialog__title">{{ t('options.bulkTitle') }}</h2>
          <p class="swf-muted">{{ t('options.bulkText') }}</p>
        </q-card-section>
        <q-card-section>
          <q-input v-model="bulkText" type="textarea" outlined autogrow autofocus input-style="min-height: 200px" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn v-close-popup flat no-caps :label="t('common.cancel')" />
          <q-btn unelevated no-caps color="primary" :label="t('common.apply')" @click="applyBulk" />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </div>
</template>
