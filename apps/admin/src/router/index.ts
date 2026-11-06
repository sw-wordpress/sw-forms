import { createRouter, createWebHashHistory } from 'vue-router';
import type { RouteRecordRaw } from 'vue-router';
import type { Capability } from '@/types/config';

export interface NavItem {
  name: string;
  to: string;
  icon: string;
  labelKey: 'nav.forms' | 'nav.settings';
  /** UX only: hide the entry without this capability. */
  cap?: Capability;
}

export const NAV_ITEMS: NavItem[] = [
  { name: 'forms', to: '/', icon: 'r_dynamic_form', labelKey: 'nav.forms' },
  { name: 'settings', to: '/settings', icon: 'r_settings', labelKey: 'nav.settings', cap: 'swf_settings' },
];

export const BUILDER_TABS = ['build', 'settings', 'notifications', 'webhooks', 'entries'] as const;
export type BuilderTab = (typeof BUILDER_TABS)[number];

const routes: RouteRecordRaw[] = [
  { path: '/', name: 'forms', component: () => import('@/features/forms/FormsView.vue') },
  {
    path: '/forms/:id(\\d+)/:tab(build|settings|notifications|webhooks|entries)?/:entryId(\\d+)?',
    name: 'builder',
    component: () => import('@/features/builder/BuilderView.vue'),
    meta: { fullWidth: true },
  },
  { path: '/settings', name: 'settings', component: () => import('@/features/settings/SettingsView.vue') },
  { path: '/:pathMatch(.*)*', redirect: '/' },
];

export function createAppRouter() {
  return createRouter({
    history: createWebHashHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
  });
}
