<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { EntryNode } from '@/types/api';

defineOptions({ name: 'EntryFields' });
defineProps<{ nodes: EntryNode[] }>();
const { t } = useI18n();
</script>

<template>
  <dl class="swf-entry-fields">
    <template v-for="node in nodes" :key="node.id">
      <dt>{{ node.label }}</dt>
      <dd v-if="node.rows">
        <ol class="swf-entry-rows">
          <li v-for="(row, index) in node.rows" :key="index">
            <span class="swf-eyebrow">{{ t('entries.row', { n: index + 1 }) }}</span>
            <EntryFields :nodes="row" />
          </li>
        </ol>
        <span v-if="node.rows.length === 0" class="swf-muted">—</span>
      </dd>
      <dd v-else-if="node.children"><EntryFields :nodes="node.children" /></dd>
      <dd v-else-if="node.files" class="swf-entry-value">
        <ul v-if="node.files.length"><li v-for="file in node.files" :key="file.url"><a :href="file.url" target="_blank" rel="noopener noreferrer">{{ file.name }}</a></li></ul>
        <span v-else class="swf-muted">—</span>
      </dd>
      <dd v-else class="swf-entry-value">{{ node.value === '' ? '—' : node.value }}</dd>
    </template>
  </dl>
</template>
