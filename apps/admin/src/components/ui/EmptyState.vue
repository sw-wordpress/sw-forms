<script setup lang="ts">
withDefaults(defineProps<{ title: string; text?: string; icon?: string; compact?: boolean }>(), {
  text: undefined,
  icon: 'r_dynamic_form',
  compact: false,
});
</script>

<template>
  <div class="swf-empty" :class="{ 'swf-empty--compact': compact }">
    <span class="swf-empty__art" aria-hidden="true"><q-icon :name="icon" :size="compact ? '32px' : '44px'" /></span>
    <h3 class="swf-empty__title">{{ title }}</h3>
    <p v-if="text" class="swf-empty__text">{{ text }}</p>
    <div v-if="$slots.default" class="swf-empty__actions"><slot /></div>
  </div>
</template>

<style scoped>
.swf-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 8px;
  padding: 44px 20px;
  color: var(--swf-muted);
}
.swf-empty--compact {
  padding: 20px 12px;
}
.swf-empty__art {
  display: grid;
  place-items: center;
  width: 88px;
  height: 88px;
  margin-bottom: 6px;
  border-radius: 28px;
  color: var(--swf-primary);
  background: var(--swf-gradient-soft);
}
.swf-empty--compact .swf-empty__art {
  width: 56px;
  height: 56px;
  border-radius: 18px;
}
.swf-empty__title {
  font-size: 17px;
  font-weight: 700;
  color: var(--swf-text);
}
.swf-empty__text {
  max-width: 46ch;
}
.swf-empty__actions {
  display: flex;
  gap: 8px;
  margin-top: 10px;
}
</style>
