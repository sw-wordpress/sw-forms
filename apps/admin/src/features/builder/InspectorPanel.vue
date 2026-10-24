<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ColumnNode, ElementNode } from '@shared/types';
import { useBuilderStore } from '@/stores/builder';
import type { SettingDefinition } from '@/types/api';
import ColumnsInspector from './ColumnsInspector.vue';
import LogicEditor from './LogicEditor.vue';
import SettingControl from './SettingControl.vue';
import { isValidKey, keysInObject } from './document';

type Tab = 'general' | 'validation' | 'logic' | 'appearance';

const { t } = useI18n();
const store = useBuilderStore();
const tab = ref<Tab>('general');

const location = computed(() => store.selected);
const isColumn = computed(() => location.value?.isColumn === true);
const element = computed(() => (location.value && !location.value.isColumn ? (location.value.node as ElementNode) : null));
const column = computed(() => (location.value?.isColumn ? (location.value.node as ColumnNode) : null));
const definition = computed(() => (element.value ? store.definition(element.value.type) : undefined));
const supports = computed(() => new Set(definition.value?.supports ?? []));
const settings = computed(() => element.value?.settings ?? {});
const errors = computed(() => (location.value ? store.errorsById.get(location.value.node.id) ?? [] : []));

watch(
  () => store.selectedId,
  () => {
    if (tab.value === 'logic' && !supports.value.has('logic') && !isColumn.value) tab.value = 'general';
  },
);

function visible(setting: SettingDefinition): boolean {
  if (!setting.showIf) return true;
  return setting.showIf.equals.includes(settings.value[setting.showIf.setting]);
}

function settingsFor(target: string): SettingDefinition[] {
  return (definition.value?.settings ?? []).filter((s) => (s.tab || 'general') === target && visible(s));
}

const tabs = computed(() => {
  const list: { name: Tab; label: string; icon: string }[] = [{ name: 'general', label: t('inspector.tabs.general'), icon: 'r_edit' }];
  if (isColumn.value) {
    list.push({ name: 'logic', label: t('inspector.tabs.logic'), icon: 'r_alt_route' });
    return list;
  }
  if (settingsFor('validation').length > 0) {
    list.push({ name: 'validation', label: t('inspector.tabs.validation'), icon: 'r_rule' });
  }
  if (supports.value.has('logic')) list.push({ name: 'logic', label: t('inspector.tabs.logic'), icon: 'r_alt_route' });
  list.push({ name: 'appearance', label: t('inspector.tabs.appearance'), icon: 'r_palette' });
  return list;
});
const activeTab = computed<Tab>(() => (tabs.value.some((x) => x.name === tab.value) ? tab.value : 'general'));

// ---------- key ----------
const publishedKey = computed(() => (element.value ? store.meta?.publishedKeys[element.value.id] : undefined));
const keyError = computed(() => {
  const el = element.value;
  if (!el || el.key === undefined || !store.document || !location.value) return '';
  if (!isValidKey(el.key)) return t('inspector.keyInvalid');
  if (keysInObject(store.document, location.value.listId, el.id).has(el.key)) return t('inspector.keyDuplicate');
  return '';
});
const keyChanged = computed(() => publishedKey.value !== undefined && element.value?.key !== publishedKey.value);

function setColumnWidth(value: string | number | null): void {
  const target = column.value;
  if (!target || !location.value) return;
  const width = Math.max(1, Math.min(12, Number(value) || 1));
  store.columnsChange(
    location.value.listId,
    (node) => {
      const c = node.columns?.find((x) => x.id === target.id);
      if (c) c.width = width;
    },
    `${target.id}:width`,
  );
}

function update(patch: Partial<ElementNode>): void {
  if (element.value) store.updateElement(element.value.id, patch);
}
</script>

<template>
  <aside class="swf-inspector" :aria-label="t('inspector.title')">
    <template v-if="!location">
      <div class="swf-panel-head">
        <h2 class="swf-panel-title">{{ t('inspector.title') }}</h2>
      </div>
      <div class="swf-inspector__empty">
        <q-icon name="r_touch_app" size="36px" aria-hidden="true" />
        <p>{{ t('inspector.nothingSelected') }}</p>
        <ul class="swf-shortcuts">
          <li><kbd>Ctrl</kbd>+<kbd>Z</kbd> {{ t('builder.undo') }}</li>
          <li><kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>Z</kbd> {{ t('builder.redo') }}</li>
          <li><kbd>Ctrl</kbd>+<kbd>D</kbd> {{ t('common.duplicate') }}</li>
          <li><kbd>Entf</kbd> {{ t('common.delete') }}</li>
          <li><kbd>Alt</kbd>+<kbd>↑</kbd>/<kbd>↓</kbd> {{ t('inspector.shortcutMove') }}</li>
          <li><kbd>Ctrl</kbd>+<kbd>S</kbd> {{ t('builder.save') }}</li>
        </ul>
      </div>
    </template>

    <template v-else>
      <div class="swf-panel-head">
        <h2 class="swf-panel-title">
          <q-icon :name="isColumn ? 'r_view_column' : definition?.icon ?? 'r_widgets'" size="18px" aria-hidden="true" />
          {{ isColumn ? t('canvas.column', { n: (location.index ?? 0) + 1 }) : definition?.label ?? element?.type }}
        </h2>
        <q-btn flat round dense icon="r_close" :aria-label="t('inspector.deselect')" @click="store.select(null)" />
      </div>

      <div v-if="errors.length > 0" class="swf-inspector__errors" role="alert">
        <p v-for="(error, i) in errors" :key="i"><q-icon name="r_error" size="16px" aria-hidden="true" /> {{ error }}</p>
      </div>

      <q-tabs v-model="tab" dense no-caps align="justify" class="swf-inspector__tabs" active-color="primary" indicator-color="primary">
        <q-tab v-for="item in tabs" :key="item.name" :name="item.name" :label="item.label" />
      </q-tabs>

      <div class="swf-inspector__body">
        <!-- column -->
        <template v-if="column">
          <div v-if="activeTab === 'general'" class="swf-stack swf-stack--tight">
            <q-input
              :model-value="column.width"
              type="number"
              dense
              outlined
              min="1"
              max="12"
              suffix="/12"
              :label="t('columns.width')"
              @update:model-value="setColumnWidth($event)"
            />
            <q-btn flat dense no-caps icon="r_arrow_back" :label="t('columns.backToLayout')" @click="store.select(location.listId)" />
          </div>
          <LogicEditor v-else :logic="column.logic" :owner-id="column.id" @update="store.updateLogic(column!.id, $event)" />
        </template>

        <!-- element -->
        <template v-else-if="element">
          <div v-if="activeTab === 'general'" class="swf-stack swf-stack--tight">
            <q-input
              v-if="supports.has('label')"
              :model-value="element.label ?? ''"
              dense
              outlined
              autofocus
              :label="t('inspector.label')"
              @update:model-value="update({ label: String($event ?? '') })"
            />
            <div v-if="supports.has('key')">
              <q-input
                :model-value="element.key ?? ''"
                dense
                outlined
                input-class="swf-mono"
                :label="t('inspector.key')"
                :hint="store.keyFollowsLabel(element.id) ? t('inspector.keyFollows') : t('inspector.keyHint')"
                :error="keyError !== ''"
                :error-message="keyError"
                @update:model-value="update({ key: String($event ?? '') })"
              />
              <p v-if="keyChanged" class="swf-warning" role="status">
                <q-icon name="r_warning" size="16px" aria-hidden="true" />
                {{ t('inspector.keyChanged', { old: publishedKey }) }}
              </p>
            </div>
            <q-input
              v-if="supports.has('description')"
              :model-value="element.description ?? ''"
              type="textarea"
              autogrow
              dense
              outlined
              :label="t('inspector.description')"
              :hint="t('inspector.descriptionHint')"
              @update:model-value="update({ description: String($event ?? '') })"
            />
            <q-toggle v-if="supports.has('required')" :model-value="element.required === true" :label="t('inspector.required')" @update:model-value="update({ required: !!$event })" />
            <ColumnsInspector v-if="definition?.container === 'columns'" :element="element" />
            <SettingControl
              v-for="setting in settingsFor('general')"
              :key="setting.name"
              :setting="setting"
              :value="settings[setting.name]"
              :settings="settings"
              @update="store.updateSetting(element!.id, setting.name, $event)"
            />
          </div>

          <div v-else-if="activeTab === 'validation'" class="swf-stack swf-stack--tight">
            <SettingControl
              v-for="setting in settingsFor('validation')"
              :key="setting.name"
              :setting="setting"
              :value="settings[setting.name]"
              :settings="settings"
              @update="store.updateSetting(element!.id, setting.name, $event)"
            />
          </div>

          <LogicEditor v-else-if="activeTab === 'logic'" :logic="element.logic" :owner-id="element.id" @update="store.updateLogic(element!.id, $event)" />

          <div v-else class="swf-stack swf-stack--tight">
            <SettingControl
              v-for="setting in settingsFor('appearance')"
              :key="setting.name"
              :setting="setting"
              :value="settings[setting.name]"
              :settings="settings"
              @update="store.updateSetting(element!.id, setting.name, $event)"
            />
            <q-input
              v-if="supports.has('adminLabel')"
              :model-value="element.adminLabel ?? ''"
              dense
              outlined
              :label="t('inspector.adminLabel')"
              :hint="t('inspector.adminLabelHint')"
              @update:model-value="update({ adminLabel: String($event ?? '') })"
            />
            <q-input
              v-if="supports.has('cssClass')"
              :model-value="element.cssClass ?? ''"
              dense
              outlined
              input-class="swf-mono"
              :label="t('inspector.cssClass')"
              @update:model-value="update({ cssClass: String($event ?? '') })"
            />
            <p class="swf-muted swf-small">{{ t('inspector.idInfo') }} <code>{{ element.id }}</code></p>
          </div>
        </template>
      </div>
    </template>
  </aside>
</template>
