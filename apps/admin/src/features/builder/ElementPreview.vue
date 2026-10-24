<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ElementNode } from '@shared/types';

/**
 * Approximation of a field for the canvas (fast, not interactive). The authoritative rendering
 * is the PHP output shown in the preview. Unknown add-on types show a placeholder.
 */
const props = defineProps<{ element: ElementNode; known: boolean }>();
const { t } = useI18n();

const settings = computed(() => props.element.settings ?? {});
const options = computed(() => {
  const list = settings.value.options;
  return Array.isArray(list) ? (list as { label: string; value: string }[]) : [];
});
const inputType = computed(() => String(settings.value.inputType ?? 'text'));
const mode = computed(() => String(settings.value.mode ?? 'multiple'));
const placeholder = computed(() => String(settings.value.placeholder ?? ''));
const horizontal = computed(() => settings.value.layout === 'horizontal');
const singleText = computed(() => String(settings.value.text ?? '').replace(/<[^>]*>/g, ''));
const defaults = computed(() => {
  const value = settings.value.defaultValues;
  return Array.isArray(value) ? (value as string[]) : [];
});
</script>

<template>
  <div class="swf-preview" inert>
    <template v-if="!known">
      <div class="swf-preview__unknown">
        <q-icon name="r_extension" size="20px" aria-hidden="true" />
        {{ t('canvas.unknownType', { type: element.type }) }}
      </div>
    </template>

    <template v-else-if="element.type === 'checkbox' && mode === 'single'">
      <label class="swf-preview__choice">
        <span class="swf-preview__box" :class="{ 'swf-preview__box--on': settings.checked === true }" />
        <span>{{ singleText || element.label }}<span v-if="element.required" class="swf-preview__req">*</span></span>
      </label>
      <p v-if="element.description" class="swf-preview__desc">{{ element.description }}</p>
    </template>

    <template v-else>
      <p v-if="element.label" class="swf-preview__label">
        {{ element.label }}<span v-if="element.required" class="swf-preview__req">*</span>
        <span v-if="element.type === 'input' && inputType === 'hidden'" class="swf-chip">{{ t('canvas.hiddenField') }}</span>
      </p>

      <div v-if="element.type === 'input' && inputType !== 'hidden'" class="swf-preview__input">
        <span class="swf-preview__placeholder">{{ placeholder || (settings.default as string) || '' }}</span>
        <q-icon v-if="inputType === 'date'" name="r_calendar_today" size="16px" />
        <q-icon v-else-if="inputType === 'email'" name="r_alternate_email" size="16px" />
      </div>
      <div v-else-if="element.type === 'textarea'" class="swf-preview__input swf-preview__input--area" :style="{ minHeight: `${Math.max(2, Number(settings.rows ?? 4)) * 14}px` }">
        <span class="swf-preview__placeholder">{{ placeholder }}</span>
      </div>
      <div v-else-if="element.type === 'select'" class="swf-preview__input">
        <span class="swf-preview__placeholder">{{ settings.multiple ? options.map((o) => o.label).join(', ') : settings.placeholder }}</span>
        <q-icon name="r_expand_more" size="18px" />
      </div>
      <div v-else-if="element.type === 'checkbox' || element.type === 'radio'" class="swf-preview__choices" :class="{ 'swf-preview__choices--row': horizontal }">
        <label v-for="option in options" :key="option.value" class="swf-preview__choice">
          <span
            :class="[
              element.type === 'radio' ? 'swf-preview__radio' : 'swf-preview__box',
              {
                'swf-preview__box--on':
                  element.type === 'radio' ? settings.default === option.value : defaults.includes(option.value),
              },
            ]"
          />
          {{ option.label }}
        </label>
        <label v-if="element.type === 'radio' && settings.other" class="swf-preview__choice">
          <span class="swf-preview__radio" />{{ settings.otherLabel }} <span class="swf-preview__input swf-preview__input--inline" />
        </label>
        <span v-if="options.length === 0" class="swf-muted">{{ t('canvas.noOptions') }}</span>
      </div>
      <div v-else-if="known && !['input', 'textarea', 'select', 'checkbox', 'radio'].includes(element.type)" class="swf-preview__unknown">
        <q-icon name="r_extension" size="20px" aria-hidden="true" />
        {{ t('canvas.customType') }}
      </div>
      <p v-if="element.description" class="swf-preview__desc">{{ element.description }}</p>
    </template>
  </div>
</template>
