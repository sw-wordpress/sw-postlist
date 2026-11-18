import { createRouter, createWebHashHistory } from 'vue-router';
import type { RouteRecordRaw } from 'vue-router';

export interface NavItem {
  name: string;
  to: string;
  icon: string;
  labelKey: string;
}

/** Same order as the plan (PLAN.md §6). */
export const NAV_ITEMS: NavItem[] = [
  { name: 'dashboard', to: '/', icon: 'r_space_dashboard', labelKey: 'nav.dashboard' },
  { name: 'views', to: '/views', icon: 'r_view_quilt', labelKey: 'nav.views' },
  { name: 'templates', to: '/templates', icon: 'r_code', labelKey: 'nav.templates' },
  { name: 'presets', to: '/presets', icon: 'r_style', labelKey: 'nav.presets' },
  {
    name: 'import-export',
    to: '/import-export',
    icon: 'r_swap_vert',
    labelKey: 'nav.importExport',
  },
  { name: 'settings', to: '/settings', icon: 'r_settings', labelKey: 'nav.settings' },
  { name: 'diagnostics', to: '/diagnostics', icon: 'r_monitor_heart', labelKey: 'nav.diagnostics' },
];

const routes: RouteRecordRaw[] = [
  { path: '/', name: 'dashboard', component: () => import('@/pages/DashboardPage.vue') },
  { path: '/views', name: 'views', component: () => import('@/pages/ViewsPage.vue') },
  { path: '/views/new', name: 'view-new', component: () => import('@/pages/ViewEditorPage.vue') },
  {
    path: '/views/:id(\\d+)',
    name: 'view-edit',
    component: () => import('@/pages/ViewEditorPage.vue'),
  },
  { path: '/templates', name: 'templates', component: () => import('@/pages/TemplatesPage.vue') },
  {
    path: '/templates/new',
    name: 'template-new',
    component: () => import('@/pages/TemplateEditorPage.vue'),
  },
  {
    path: '/templates/:id(\\d+)',
    name: 'template-edit',
    component: () => import('@/pages/TemplateEditorPage.vue'),
  },
  { path: '/presets', name: 'presets', component: () => import('@/pages/PresetsPage.vue') },
  {
    path: '/import-export',
    name: 'import-export',
    component: () => import('@/pages/ImportExportPage.vue'),
  },
  { path: '/settings', name: 'settings', component: () => import('@/pages/SettingsPage.vue') },
  {
    path: '/diagnostics',
    name: 'diagnostics',
    component: () => import('@/pages/DiagnosticsPage.vue'),
  },
];

export function createAppRouter() {
  return createRouter({ history: createWebHashHistory(), routes });
}
