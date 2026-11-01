<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SwfCard from '@/components/ui/SwfCard.vue';
import { getConfig } from '@/config';
import { useBuilderStore } from '@/stores/builder';
import type { DesignSettings, DesignToken, DesignVariant, FormSettings } from '@/types/api';
import { contrast } from './contrast';

/** Form design: predefined variant, colour scheme and optional own colours (settings.design). */
const { t } = useI18n();
const store = useBuilderStore();
const galleryUrl = getConfig().galleryUrl;

const VARIANTS: DesignVariant[] = ['classic', 'floating', 'minimal', 'dark-focus', 'guided-card', 'animated-float', 'surprise-motion'];
const TOKENS: DesignToken[] = ['accent', 'text', 'background', 'surface', 'border'];
/** Defaults shown by the colour inputs while a token is not overridden. */
const PLACEHOLDER: Record<DesignToken, string> = { accent: '#2563eb', text: '#1f2430', background: '#ffffff', surface: '#ffffff', border: '#c7cdd6' };

const design = computed<DesignSettings>(() => {
  const stored = (store.document?.settings as FormSettings | undefined)?.design;
  return { variant: stored?.variant ?? 'classic', scheme: stored?.scheme ?? 'light', tokens: stored?.tokens ?? {} };
});

function update(mutate: (d: DesignSettings) => void, coalesce = ''): void {
  store.updateFormSettings((s) => {
    const next: DesignSettings = { ...design.value, tokens: { ...design.value.tokens } };
    mutate(next);
    s.design = next;
  }, coalesce);
}

function setToken(token: DesignToken, value: string | null): void {
  update((d) => {
    if (value && /^#[0-9a-f]{6}$/i.test(value)) d.tokens[token] = value.toLowerCase();
    else delete d.tokens[token];
  }, value ? `design-${token}` : '');
}

function setRadius(value: string | number | null): void {
  update((d) => {
    const radius = Number(value);
    if (value === null || value === '' || !Number.isFinite(radius)) delete d.tokens.radius;
    else d.tokens.radius = Math.max(0, Math.min(32, Math.round(radius)));
  }, 'design-radius');
}

const schemes = computed(() => [
  { value: 'light', label: t('design.schemeLight') },
  { value: 'dark', label: t('design.schemeDark') },
  { value: 'auto', label: t('design.schemeAuto') },
]);

/** Readability warnings for own colours (WCAG AA: text 4.5:1, accent/border-like parts 3:1). */
const warnings = computed(() => {
  const tokens = design.value.tokens;
  const out: string[] = [];
  const background = tokens.background ?? PLACEHOLDER.background;
  if (tokens.text && contrast(tokens.text, background) < 4.5) out.push(t('design.lowTextContrast'));
  if (tokens.accent && contrast(tokens.accent, background) < 3) out.push(t('design.lowAccentContrast'));
  return out;
});
</script>

<template>
  <SwfCard :title="t('design.title')" :subtitle="t('design.text')" icon="r_palette" class="swf-design">
    <div class="swf-stack swf-stack--tight">
      <div class="swf-design__variants" role="group" :aria-label="t('design.variant')">
        <button
          v-for="variant in VARIANTS"
          :key="variant"
          type="button"
          class="swf-design__variant"
          :class="[`swf-design__variant--${variant}`, { 'is-active': design.variant === variant }]"
          :aria-pressed="design.variant === variant"
          :data-variant="variant"
          @click="update((d) => (d.variant = variant))"
        >
          <span class="swf-design__mock" aria-hidden="true"><i /><i /><b /></span>
          <strong>{{ t(`design.variants.${variant}`) }}</strong>
          <span class="swf-muted swf-small">{{ t(`design.variantText.${variant}`) }}</span>
        </button>
      </div>

      <q-btn-toggle
        :model-value="design.scheme"
        no-caps
        unelevated
        toggle-color="primary"
        :options="schemes"
        :disable="design.variant === 'dark-focus'"
        :aria-label="t('design.scheme')"
        @update:model-value="update((d) => (d.scheme = $event))"
      />
      <p v-if="design.variant === 'dark-focus'" class="swf-muted swf-small">{{ t('design.alwaysDark') }}</p>

      <details class="swf-design__custom" :open="Object.keys(design.tokens).length > 0">
        <summary>{{ t('design.custom') }}</summary>
        <p class="swf-muted swf-small">{{ t('design.customText') }}</p>
        <div class="swf-design__tokens">
          <div v-for="token in TOKENS" :key="token" class="swf-design__token">
            <label :for="`swf-design-${token}`">{{ t(`design.tokens.${token}`) }}</label>
            <input
              :id="`swf-design-${token}`"
              type="color"
              :value="design.tokens[token] ?? PLACEHOLDER[token]"
              :class="{ 'is-default': !design.tokens[token] }"
              @change="setToken(token, ($event.target as HTMLInputElement).value)"
            />
            <code>{{ design.tokens[token] ?? t('design.default') }}</code>
            <q-btn v-if="design.tokens[token]" flat dense round size="sm" icon="r_restart_alt" :aria-label="t('design.reset', { name: t(`design.tokens.${token}`) })" @click="setToken(token, null)" />
          </div>
          <q-input
            :model-value="design.tokens.radius ?? ''"
            type="number"
            min="0"
            max="32"
            outlined
            dense
            clearable
            suffix="px"
            :label="t('design.radius')"
            :placeholder="t('design.default')"
            @update:model-value="setRadius($event)"
          />
        </div>
        <p v-for="warning in warnings" :key="warning" class="swf-warning"><q-icon name="r_warning" size="16px" /> {{ warning }}</p>
      </details>

      <div class="swf-design__links">
        <q-btn v-if="galleryUrl" flat dense no-caps icon="r_view_quilt" :label="t('design.gallery')" :href="galleryUrl" target="_blank" rel="noopener" />
        <span class="swf-muted swf-small">{{ t('design.embedHint') }} <code>variant="{{ design.variant }}"</code></span>
      </div>
    </div>
  </SwfCard>
</template>

<style scoped>
.swf-design__variants {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr));
  gap: 0.5rem;
}
.swf-design__variant {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  padding: 0.6rem;
  font: inherit;
  text-align: start;
  color: inherit;
  background: transparent;
  border: 1px solid var(--swf-border, #d0d5dd);
  border-radius: 10px;
  cursor: pointer;
}
.swf-design__variant.is-active {
  border-color: var(--q-primary);
  box-shadow: inset 0 0 0 1px var(--q-primary);
}
.swf-design__variant:focus-visible {
  outline: 2px solid var(--q-primary);
  outline-offset: 2px;
}
/* Miniature: two fields and a button in the variant's character. */
.swf-design__mock {
  display: grid;
  gap: 4px;
  padding: 6px;
  margin-bottom: 0.25rem;
  background: #f6f7f9;
  border-radius: 6px;
}
.swf-design__mock i {
  height: 10px;
  background: #fff;
  border: 1px solid #c7cdd6;
  border-radius: 3px;
}
.swf-design__mock b {
  width: 40%;
  height: 8px;
  background: #2563eb;
  border-radius: 3px;
}
.swf-design__variant--minimal .swf-design__mock i,
.swf-design__variant--surprise-motion .swf-design__mock i {
  background: transparent;
  border-width: 0 0 1px;
  border-radius: 0;
}
.swf-design__variant--minimal .swf-design__mock b {
  background: #111827;
}
.swf-design__variant--surprise-motion .swf-design__mock i:first-child {
  border-bottom: 2px solid #4f46e5;
}
.swf-design__variant--surprise-motion .swf-design__mock b {
  background: #4f46e5;
}
.swf-design__variant--dark-focus .swf-design__mock {
  background: #0f1117;
}
.swf-design__variant--dark-focus .swf-design__mock i {
  background: #1a1d29;
  border-color: #3b4154;
}
.swf-design__variant--dark-focus .swf-design__mock i:first-child {
  border-color: #a78bfa;
  box-shadow: 0 0 6px #a78bfa;
}
.swf-design__variant--dark-focus .swf-design__mock b {
  background: #7c3aed;
}
.swf-design__variant--guided-card .swf-design__mock {
  background: #eef4f8;
  box-shadow: inset 0 0 0 4px #eef4f8, inset 0 0 0 5px #e3e8ef;
}
.swf-design__variant--guided-card .swf-design__mock b {
  background: #0f766e;
}
.swf-design__variant--floating .swf-design__mock i,
.swf-design__variant--animated-float .swf-design__mock i {
  height: 14px;
}
.swf-design__variant--animated-float .swf-design__mock i:first-child {
  border-color: #2563eb;
}
.swf-design__custom summary {
  cursor: pointer;
  font-weight: 600;
}
.swf-design__tokens {
  display: grid;
  gap: 0.5rem;
  margin-top: 0.5rem;
}
.swf-design__token {
  display: grid;
  grid-template-columns: minmax(7rem, 1fr) 2.5rem auto 2rem;
  gap: 0.5rem;
  align-items: center;
}
.swf-design__token input[type='color'] {
  width: 2.5rem;
  height: 2rem;
  padding: 0;
  border: 1px solid #c7cdd6;
  border-radius: 6px;
}
.swf-design__token input.is-default {
  opacity: 0.55;
}
.swf-design__links {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
}
</style>
