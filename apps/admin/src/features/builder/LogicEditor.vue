<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ElementNode, Logic, LogicRule } from '@shared/types';
import { useBuilderStore } from '@/stores/builder';

/**
 * Condition editor for elements, columns and notifications.
 * `ownerId` null means form level (notifications): only fields outside repeaters can be used.
 */
const props = defineProps<{ logic: Logic | null | undefined; ownerId: string | null; subject?: 'element' | 'notification' | 'webhook' }>();
const emit = defineEmits<{ (e: 'update', logic: Logic | null): void }>();
const { t } = useI18n();
const store = useBuilderStore();

interface FieldChoice {
  value: string;
  label: string;
  node: ElementNode;
}

const enabled = computed(() => !!props.logic && props.logic.rules.length > 0);

function pathLabel(id: string): string {
  const tree = store.tree;
  const parts: string[] = [];
  let current: string | null = id;
  while (current !== null) {
    const node = tree.node(current);
    if (node && !tree.isColumn(current) && node.type !== 'columns') {
      parts.unshift(node.adminLabel || node.label || node.key || current);
    }
    current = tree.parentOf(current);
  }
  return parts.join(' › ');
}

/** Fields a rule of this owner may use (same rules as the server validation). */
const fields = computed<FieldChoice[]>(() => {
  const tree = store.tree;
  const ownerScope = props.ownerId ? tree.scopeOf(props.ownerId) : null;
  return tree
    .ids()
    .filter(
      (id) =>
        tree.isLeafData(id) &&
        id !== props.ownerId &&
        !(props.ownerId && tree.isDescendant(id, props.ownerId)) &&
        tree.scopeEncloses(tree.scopeOf(id), ownerScope),
    )
    .map((id) => ({ value: id, label: pathLabel(id), node: tree.node(id) as ElementNode }));
});

function fieldOf(rule: LogicRule): FieldChoice | undefined {
  return fields.value.find((f) => f.value === rule.field);
}

function options(node: ElementNode | undefined): { label: string; value: string }[] {
  const list = node?.settings?.options;
  return Array.isArray(list) ? (list as { label: string; value: string }[]) : [];
}

function isBool(node: ElementNode | undefined): boolean {
  return node?.type === 'checkbox' && node.settings?.mode === 'single';
}

function operators(node: ElementNode | undefined): { value: string; label: string }[] {
  if (isBool(node)) {
    return [
      { value: 'notempty', label: t('logic.ops.checked') },
      { value: 'empty', label: t('logic.ops.unchecked') },
    ];
  }
  const ops = ['is', 'isnot'];
  if (!options(node).length) ops.push('contains', 'gt', 'lt');
  ops.push('empty', 'notempty');
  return ops.map((op) => ({ value: op, label: t(`logic.ops.${op}` as 'logic.ops.is') }));
}

const needsValue = (op: string): boolean => op !== 'empty' && op !== 'notempty';

function defaultRule(): LogicRule {
  const first = fields.value[0];
  return { field: first?.value ?? '', op: isBool(first?.node) ? 'notempty' : 'is', value: '' };
}

function emitLogic(patch: Partial<Logic>): void {
  const base: Logic = props.logic ?? { action: 'show', match: 'all', rules: [] };
  emit('update', { ...base, ...patch });
}

function toggle(on: boolean): void {
  emit('update', on ? { action: 'show', match: 'all', rules: [defaultRule()] } : null);
}

function updateRule(index: number, patch: Partial<LogicRule>): void {
  const rules = (props.logic?.rules ?? []).map((r) => ({ ...r }));
  const rule = rules[index];
  if (!rule) return;
  Object.assign(rule, patch);
  if (patch.field !== undefined) {
    const node = fieldOf(rule)?.node;
    rule.op = isBool(node) ? 'notempty' : 'is';
    rule.value = '';
  }
  emitLogic({ rules });
}

function addRule(): void {
  emitLogic({ rules: [...(props.logic?.rules ?? []), defaultRule()] });
}

function removeRule(index: number): void {
  const rules = (props.logic?.rules ?? []).filter((_, i) => i !== index);
  emit('update', rules.length > 0 ? { ...(props.logic as Logic), rules } : null);
}
</script>

<template>
  <div class="swf-logic">
    <q-toggle
      :model-value="enabled"
      :label="subject === 'webhook' ? t('webhooks.enableLogic') : subject === 'notification' ? t('logic.enableNotification') : t('logic.enable')"
      :disable="!enabled && fields.length === 0"
      @update:model-value="toggle(!!$event)"
    />
    <p v-if="!enabled && fields.length === 0" class="swf-muted swf-small">{{ t('logic.noFields') }}</p>

    <template v-if="enabled && logic">
      <div class="swf-logic__sentence">
        <q-select
          v-if="subject !== 'notification' && subject !== 'webhook'"
          :model-value="logic.action"
          dense
          outlined
          emit-value
          map-options
          :options="[
            { value: 'show', label: t('logic.show') },
            { value: 'hide', label: t('logic.hide') },
          ]"
          :aria-label="t('logic.action')"
          @update:model-value="emitLogic({ action: $event })"
        />
        <span v-else>{{ t('logic.sendIf') }}</span>
        <span>{{ t('logic.when') }}</span>
        <q-select
          :model-value="logic.match"
          dense
          outlined
          emit-value
          map-options
          :options="[
            { value: 'all', label: t('logic.all') },
            { value: 'any', label: t('logic.any') },
          ]"
          :aria-label="t('logic.match')"
          @update:model-value="emitLogic({ match: $event })"
        />
        <span>{{ t('logic.conditionsApply') }}</span>
      </div>

      <ol class="swf-logic__rules">
        <li v-for="(rule, index) in logic.rules" :key="index" class="swf-logic__rule">
          <q-select
            :model-value="rule.field"
            dense
            outlined
            emit-value
            map-options
            :options="fields"
            :label="t('logic.field')"
            :error="!fieldOf(rule)"
            :error-message="t('logic.unknownField')"
            @update:model-value="updateRule(index, { field: $event })"
          />
          <q-select
            :model-value="rule.op"
            dense
            outlined
            emit-value
            map-options
            :options="operators(fieldOf(rule)?.node)"
            :label="t('logic.operator')"
            @update:model-value="updateRule(index, { op: $event })"
          />
          <template v-if="needsValue(rule.op)">
            <q-select
              v-if="options(fieldOf(rule)?.node).length > 0"
              :model-value="rule.value ?? ''"
              dense
              outlined
              emit-value
              map-options
              :options="options(fieldOf(rule)?.node)"
              :label="t('logic.value')"
              @update:model-value="updateRule(index, { value: String($event ?? '') })"
            />
            <q-input
              v-else
              :model-value="rule.value ?? ''"
              dense
              outlined
              :label="t('logic.value')"
              @update:model-value="updateRule(index, { value: String($event ?? '') })"
            />
          </template>
          <q-btn flat round dense icon="r_close" class="swf-logic__remove" :aria-label="t('logic.removeRule', { n: index + 1 })" @click="removeRule(index)" />
          <span v-if="index < logic.rules.length - 1" class="swf-logic__joiner">{{ logic.match === 'all' ? t('logic.and') : t('logic.or') }}</span>
        </li>
      </ol>
      <q-btn flat dense no-caps icon="r_add" :label="t('logic.addRule')" @click="addRule" />
    </template>
  </div>
</template>
