<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { getConfig } from '@/config';
import { useCapabilities } from '@/composables/useCapabilities';
import { NAV_ITEMS } from '@/router';
import { useAppStore } from '@/stores/app';
import NoAccess from '@/layouts/NoAccess.vue';

const { t } = useI18n();
const route = useRoute();
const app = useAppStore();
const { can } = useCapabilities();
const config = getConfig();
const main = ref<HTMLElement | null>(null);
const allowed = computed(() => can('swf_manage_forms') || can('swf_view_entries'));
const navItems = computed(() => NAV_ITEMS.filter((i) => !i.cap || can(i.cap)));


function focusMain(): void {
  main.value?.focus();
}

// The builder keeps its state while switching its own tabs: key by form, not by route name.
const transitionKey = computed(() => (route.params.id ? `form-${String(route.params.id)}` : String(route.name ?? '')));
const fullWidth = computed(() => route.meta.fullWidth === true);
</script>

<template>
  <div class="swf-shell" :class="{ 'swf-shell--collapsed': app.railCollapsed }">
    <a href="#swf-main" class="swf-skip" @click.prevent="focusMain">{{
      t('app.skipToContent')
    }}</a>

    <aside class="swf-rail">
      <div class="swf-rail__brand">
        <span class="swf-rail__logo" aria-hidden="true"
          ><q-icon name="r_dynamic_form" size="22px"
        /></span>
        <span class="swf-rail__name">
          <strong>SW Forms</strong>
          <small v-if="config.version" class="swf-num">v{{ config.version }}</small>
        </span>
      </div>

      <nav v-if="allowed" class="swf-rail__nav" :aria-label="t('app.mainNavigation')">
        <ul>
          <li v-for="item in navItems" :key="item.name">
            <router-link
              :to="item.to"
              class="swf-nav-link"
              :active-class="item.to === '/' ? '' : 'swf-nav-link--active'"
              exact-active-class="swf-nav-link--active"
            >
              <q-icon :name="item.icon" size="22px" aria-hidden="true" />
              <span class="swf-nav-link__label">{{ t(item.labelKey) }}</span>
              <q-tooltip
                v-if="app.railCollapsed"
                anchor="center right"
                self="center left"
                :offset="[8, 0]"
                >{{ t(item.labelKey) }}</q-tooltip
              >
            </router-link>
          </li>
        </ul>
      </nav>

      <div class="swf-rail__tools">
        <q-btn
          flat
          round
          dense
          :icon="app.isDark ? 'r_light_mode' : 'r_dark_mode'"
          :aria-label="app.isDark ? t('app.switchToLight') : t('app.switchToDark')"
          :aria-pressed="app.isDark"
          @click="app.toggleTheme()"
        >
          <q-tooltip>{{ app.isDark ? t('app.switchToLight') : t('app.switchToDark') }}</q-tooltip>
        </q-btn>
        <q-btn
          class="swf-rail__collapse"
          flat
          round
          dense
          :icon="
            app.railCollapsed ? 'r_keyboard_double_arrow_right' : 'r_keyboard_double_arrow_left'
          "
          :aria-label="app.railCollapsed ? t('app.expandNav') : t('app.collapseNav')"
          :aria-expanded="!app.railCollapsed"
          @click="app.railCollapsed = !app.railCollapsed"
        />
      </div>
    </aside>

    <main id="swf-main" ref="main" class="swf-main" :class="{ 'swf-main--full': fullWidth }" tabindex="-1">
      <NoAccess v-if="!allowed" />
      <router-view v-else v-slot="{ Component }">
        <transition name="swf-fade" mode="out-in">
          <component :is="Component" :key="transitionKey" />
        </transition>
      </router-view>
    </main>

    <div class="swf-sr-only" role="status" aria-live="polite" aria-atomic="true">
      {{ app.announcement }}
    </div>
  </div>
</template>

<style scoped>
.swf-shell {
  --rail-w: 244px;
  --bar-h: var(--wp-admin--admin-bar--height, 32px);
  display: grid;
  grid-template-columns: var(--rail-w) minmax(0, 1fr);
  min-height: calc(100vh - var(--bar-h));
  background: var(--swf-bg);
  position: relative;
}
.swf-shell--collapsed {
  --rail-w: 76px;
}
.swf-skip {
  position: absolute;
  left: 12px;
  top: -60px;
  z-index: 100;
  padding: 8px 14px;
  border-radius: 8px;
  background: var(--swf-primary);
  color: #fff !important;
  font-weight: 600;
}
.swf-skip:focus {
  top: 10px;
}
.swf-rail {
  position: sticky;
  top: var(--bar-h);
  align-self: start;
  height: calc(100vh - var(--bar-h));
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 18px 14px;
  background: var(--swf-rail-bg);
  border-right: 1px solid var(--swf-border);
  transition: width 0.2s ease;
}
.swf-rail__brand {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 4px 8px 16px;
  min-width: 0;
}
.swf-rail__logo {
  display: grid;
  place-items: center;
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: 12px;
  color: #fff;
  background: var(--swf-gradient);
  box-shadow: 0 6px 16px rgba(79, 70, 229, 0.35);
}
.swf-rail__name {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
  min-width: 0;
}
.swf-rail__name strong {
  font-size: 16px;
  letter-spacing: -0.01em;
}
.swf-rail__name small {
  color: var(--swf-muted);
  font-size: 12px;
}
.swf-rail__nav {
  flex: 1;
  overflow-y: auto;
}
.swf-rail__nav ul {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.swf-nav-link {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 12px;
  color: var(--swf-muted) !important;
  font-weight: 600;
  text-decoration: none !important;
  transition:
    background-color 0.15s ease,
    color 0.15s ease;
}
.swf-nav-link:hover {
  background: var(--swf-surface-3);
  color: var(--swf-text) !important;
}
.swf-nav-link--active {
  background: var(--swf-primary-bg);
  color: var(--swf-primary-text) !important;
}
.swf-nav-link--active::before {
  content: '';
  position: absolute;
  left: -14px;
  top: 10px;
  bottom: 10px;
  width: 4px;
  border-radius: 0 4px 4px 0;
  background: var(--swf-gradient);
}
.swf-nav-link__badge {
  margin-left: auto;
  min-width: 22px;
  padding: 0 7px;
  border-radius: 999px;
  text-align: center;
  font-size: 12px;
  line-height: 22px;
  color: #fff;
  background: var(--swf-gradient);
}
.swf-rail__tools {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 4px 0;
  border-top: 1px solid var(--swf-border);
  color: var(--swf-muted);
}
.swf-shell--collapsed .swf-rail__name,
.swf-shell--collapsed .swf-nav-link__label {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
}
.swf-shell--collapsed .swf-nav-link {
  justify-content: center;
}
.swf-shell--collapsed .swf-nav-link__badge {
  position: absolute;
  top: 2px;
  right: 2px;
  margin: 0;
  min-width: 18px;
  line-height: 18px;
  font-size: 11px;
  padding: 0 5px;
}
.swf-shell--collapsed .swf-rail__tools {
  flex-direction: column;
  gap: 4px;
}
.swf-shell--collapsed .swf-rail__brand {
  justify-content: center;
  padding-inline: 0;
}
.swf-main {
  min-width: 0;
  padding: 28px clamp(16px, 3vw, 40px) 48px;
  outline: none;
}
.swf-main > * {
  max-width: 1320px;
  margin-inline: auto;
}
.swf-main--full {
  padding: 0;
}
.swf-main--full > * {
  max-width: none;
}

@media (max-width: 860px) {
  .swf-shell,
  .swf-shell--collapsed {
    grid-template-columns: minmax(0, 1fr);
    grid-template-rows: auto 1fr;
  }
  .swf-rail {
    position: sticky;
    z-index: 20;
    height: auto;
    flex-direction: row;
    align-items: center;
    gap: 6px;
    padding: 8px 10px;
    border-right: 0;
    border-bottom: 1px solid var(--swf-border);
  }
  .swf-rail__brand {
    padding: 0;
  }
  .swf-rail__name {
    display: none;
  }
  .swf-rail__nav {
    flex: 1;
    min-width: 0;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: thin;
  }
  .swf-rail__nav ul {
    flex-direction: row;
  }
  .swf-nav-link {
    flex-direction: column;
    gap: 2px;
    padding: 6px 10px;
    font-size: 11px;
  }
  .swf-shell--collapsed .swf-nav-link__label {
    position: static;
    width: auto;
    height: auto;
    clip: auto;
  }
  .swf-nav-link--active::before {
    left: 10px;
    right: 10px;
    top: auto;
    bottom: 0;
    width: auto;
    height: 3px;
    border-radius: 3px 3px 0 0;
  }
  .swf-nav-link__badge {
    position: absolute;
    top: 0;
    right: 2px;
    margin: 0;
    min-width: 18px;
    line-height: 18px;
    font-size: 11px;
    padding: 0 5px;
  }
  .swf-rail__tools {
    border-top: 0;
    padding: 0;
    flex-direction: row;
  }
  .swf-rail__collapse {
    display: none;
  }
  .swf-main {
    padding-top: 20px;
  }
}
</style>
