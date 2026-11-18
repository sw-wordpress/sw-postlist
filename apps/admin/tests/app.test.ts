import { createPinia } from 'pinia';
import { describe, expect, it } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { Notify, Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import { createMemoryHistory, createRouter } from 'vue-router';
import type { RouteRecordRaw } from 'vue-router';
import { createAppI18n } from '@/i18n';
import quasarLang from '@/i18n/quasarLang';
import de from '@/i18n/de';
import en from '@/i18n/en';
import { NAV_ITEMS, createAppRouter } from '@/router';
import App from '@/App.vue';
import { stubApi } from './helpers';

function flatten(value: Record<string, unknown>, prefix = ''): string[] {
  return Object.entries(value).flatMap(([key, entry]) =>
    typeof entry === 'object' && entry !== null
      ? flatten(entry as Record<string, unknown>, `${prefix}${key}.`)
      : [`${prefix}${key}`],
  );
}

describe('admin shell', () => {
  it('keeps German and English keys identical', () => {
    expect(flatten(en)).toEqual(flatten(de));
  });

  it('exposes the navigation from PLAN.md', () => {
    expect(NAV_ITEMS.map((item) => item.name)).toEqual([
      'dashboard',
      'views',
      'templates',
      'presets',
      'import-export',
      'settings',
      'diagnostics',
    ]);
  });

  it('renders navigation and a routed page', async () => {
    stubApi({ 'GET /admin/views': { json: { views: [] } } });
    const routes: RouteRecordRaw[] = createAppRouter()
      .getRoutes()
      .map((route) => ({
        path: route.path,
        name: route.name,
        component: route.components?.default as RouteRecordRaw['component'],
        props: route.props.default as RouteRecordRaw['props'],
      })) as RouteRecordRaw[];
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push('/views');
    const wrapper = mount(App, {
      global: {
        plugins: [
          createPinia(),
          router,
          createAppI18n('en_US'),
          [Quasar, { plugins: { Notify }, iconSet, lang: quasarLang('en') }],
        ],
      },
    });
    await flushPromises();
    expect(wrapper.findAll('.swp-nav__item')).toHaveLength(NAV_ITEMS.length);
    expect(wrapper.find('h1').text()).toBe('Views');
  });

  it('has a skip link, a labelled navigation, a live region, a theme switch and a collapsible rail', async () => {
    stubApi({ 'GET /admin/views': { json: { views: [] } } });
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/', name: 'dashboard', component: { template: '<h1>Home</h1>' } }],
    });
    await router.push('/');
    const root = document.createElement('div');
    root.id = 'sw-postlist-app';
    document.body.appendChild(root);
    const wrapper = mount(App, {
      attachTo: root,
      global: {
        plugins: [
          createPinia(),
          router,
          createAppI18n('en_US'),
          [Quasar, { plugins: { Notify }, iconSet, lang: quasarLang('en') }],
        ],
      },
    });
    await flushPromises();

    expect(wrapper.get('.swp-skip').text()).toBe('Skip to content');
    expect(wrapper.get('nav').attributes('aria-label')).toBe('Main navigation');
    expect(wrapper.find('[role="status"]').exists()).toBe(true);
    const buttons = wrapper.findAll('.swp-rail__tools button');
    const theme = buttons[0];
    await theme?.trigger('click');
    expect(theme?.attributes('aria-pressed')).toBe('true');
    expect(root.classList.contains('swp-dark')).toBe(true);
    await theme?.trigger('click');
    expect(root.classList.contains('swp-dark')).toBe(false);

    expect(wrapper.get('.swp-shell').classes()).not.toContain('swp-shell--collapsed');
    await buttons[1]?.trigger('click');
    expect(wrapper.get('.swp-shell').classes()).toContain('swp-shell--collapsed');
    expect(buttons[1]?.attributes('aria-expanded')).toBe('false');
    wrapper.unmount();
    root.remove();
  });
});
