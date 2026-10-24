<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ElementNode } from '@shared/types';
import { useBuilderStore } from '@/stores/builder';

/** Inserts merge tags; field tags are offered for fields outside repeaters (`group.key` for groups). */
const props = withDefaults(defineProps<{ html?: boolean; emailOnly?: boolean }>(), { html: false, emailOnly: false });
const emit = defineEmits<{ (e: 'insert', tag: string): void }>();
const { t } = useI18n();
const store = useBuilderStore();

const fieldTags = computed(() => {
  void store.revision;
  const out: { tag: string; label: string }[] = [];
  const visit = (list: ElementNode[], prefix: string, labels: string[]): void => {
    for (const node of list) {
      node.columns?.forEach((c) => visit(c.children, prefix, labels));
      if (!node.key) continue;
      if (node.children) {
        if (node.settings?.repeatable !== true) visit(node.children, `${prefix}${node.key}.`, [...labels, node.label || node.key]);
        continue;
      }
      if (props.emailOnly && !(node.type === 'input' && node.settings?.inputType === 'email')) continue;
      out.push({ tag: `{field:${prefix}${node.key}}`, label: [...labels, node.label || node.key].join(' › ') });
    }
  };
  visit(store.document?.fields ?? [], '', []);
  return out;
});

const general = computed(() =>
  props.emailOnly
    ? [{ tag: '{admin_email}', label: t('mergeTags.adminEmail') }]
    : [
        ...(props.html ? [{ tag: '{all_fields}', label: t('mergeTags.allFields') }] : []),
        { tag: '{form:title}', label: t('mergeTags.formTitle') },
        { tag: '{entry:id}', label: t('mergeTags.entryId') },
        { tag: '{entry:url}', label: t('mergeTags.entryUrl') },
        { tag: '{entry:date}', label: t('mergeTags.entryDate') },
        { tag: '{site:name}', label: t('mergeTags.siteName') },
        { tag: '{site:url}', label: t('mergeTags.siteUrl') },
        { tag: '{admin_email}', label: t('mergeTags.adminEmail') },
      ],
);
</script>

<template>
  <q-btn flat dense round size="sm" icon="r_data_object" :aria-label="t('mergeTags.insert')">
    <q-tooltip>{{ t('mergeTags.insert') }}</q-tooltip>
    <q-menu>
      <q-list dense style="min-width: 260px; max-height: 360px">
        <q-item-label header>{{ t('mergeTags.fields') }}</q-item-label>
        <q-item v-if="fieldTags.length === 0"><q-item-section class="swf-muted">{{ t('mergeTags.noFields') }}</q-item-section></q-item>
        <q-item v-for="item in fieldTags" :key="item.tag" v-close-popup clickable @click="emit('insert', item.tag)">
          <q-item-section>{{ item.label }}</q-item-section>
          <q-item-section side class="swf-mono swf-small">{{ item.tag }}</q-item-section>
        </q-item>
        <q-separator />
        <q-item-label header>{{ t('mergeTags.general') }}</q-item-label>
        <q-item v-for="item in general" :key="item.tag" v-close-popup clickable @click="emit('insert', item.tag)">
          <q-item-section>{{ item.label }}</q-item-section>
          <q-item-section side class="swf-mono swf-small">{{ item.tag }}</q-item-section>
        </q-item>
      </q-list>
    </q-menu>
  </q-btn>
</template>
