<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/ui/EmptyState.vue';

const { t } = useI18n();
</script>

<template>
  <EmptyState icon="r_lock" :title="t('errors.noAccessTitle')" :text="t('errors.noAccessText')" />
</template>
