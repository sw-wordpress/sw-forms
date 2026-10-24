<script setup lang="ts">
withDefaults(
  defineProps<{
    title?: string;
    subtitle?: string;
    icon?: string;
    padding?: boolean;
    interactive?: boolean;
    tag?: string;
    accent?: boolean;
  }>(),
  {
    title: undefined,
    subtitle: undefined,
    icon: undefined,
    padding: true,
    interactive: false,
    tag: 'section',
    accent: false,
  },
);
</script>

<template>
  <component
    :is="tag"
    class="swf-card"
    :class="{ 'swf-card--interactive': interactive, 'swf-card--accent': accent }"
  >
    <header v-if="title || $slots.header || $slots.actions" class="swf-card__header">
      <slot name="header">
        <div class="swf-card__heading">
          <span v-if="icon" class="swf-card__icon" aria-hidden="true"
            ><q-icon :name="icon" size="20px"
          /></span>
          <div class="swf-card__titles">
            <h2 class="swf-card__title">{{ title }}</h2>
            <p v-if="subtitle" class="swf-card__subtitle">{{ subtitle }}</p>
          </div>
        </div>
      </slot>
      <div v-if="$slots.actions" class="swf-card__actions"><slot name="actions" /></div>
    </header>
    <div class="swf-card__body" :class="{ 'swf-card__body--flush': !padding }"><slot /></div>
    <footer v-if="$slots.footer" class="swf-card__footer"><slot name="footer" /></footer>
  </component>
</template>

<style scoped>
.swf-card {
  position: relative;
  background: var(--swf-surface);
  border: 1px solid var(--swf-border);
  border-radius: var(--swf-radius);
  box-shadow: var(--swf-shadow-1);
  min-width: 0;
  transition:
    box-shadow 0.2s ease,
    transform 0.2s ease,
    border-color 0.2s ease;
}
.swf-card--interactive:hover {
  box-shadow: var(--swf-shadow-2);
  transform: translateY(-1px);
}
.swf-card--accent::before {
  content: '';
  position: absolute;
  inset: 0 0 auto 0;
  height: 3px;
  border-radius: var(--swf-radius) var(--swf-radius) 0 0;
  background: var(--swf-gradient);
}
.swf-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding: 18px 22px 0;
}
.swf-card__heading {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}
.swf-card__icon {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  flex: none;
  border-radius: 10px;
  color: var(--swf-primary);
  background: var(--swf-gradient-soft);
}
.swf-card__title {
  font-size: 16px;
  font-weight: 650;
  line-height: 1.3;
}
.swf-card__subtitle {
  font-size: 13px;
  color: var(--swf-muted);
}
.swf-card__actions {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}
.swf-card__body {
  padding: 18px 22px 22px;
}
.swf-card__body--flush {
  padding: 12px 0 0;
}
.swf-card__footer {
  padding: 14px 22px;
  border-top: 1px solid var(--swf-border);
}
@media (max-width: 520px) {
  .swf-card__header {
    padding: 16px 16px 0;
  }
  .swf-card__body {
    padding: 14px 16px 18px;
  }
}
</style>
