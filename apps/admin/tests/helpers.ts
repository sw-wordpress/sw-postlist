import { flushPromises, mount } from '@vue/test-utils';
import type { Component } from 'vue';
import { createPinia, setActivePinia } from 'pinia';
import { Notify, Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import { createMemoryHistory, createRouter } from 'vue-router';
import { afterEach, vi } from 'vitest';
import { resetConfigForTests } from '@/config';
import { createAppI18n } from '@/i18n';
import quasarLang from '@/i18n/quasarLang';

const mounted: Array<{ unmount: () => void }> = [];

afterEach(() => {
  for (const wrapper of mounted.splice(0)) wrapper.unmount();
});

export interface Call {
  method: string;
  path: string;
  query: URLSearchParams;
  body: Record<string, unknown> | undefined;
}

export type Reply =
  { status?: number; json: unknown } | ((call: Call) => { status?: number; json: unknown });

/** Replaces fetch with a route table: "METHOD /path" (relative to the REST root) → reply. Records every call. */
export function stubApi(routes: Record<string, Reply>): Call[] {
  const calls: Call[] = [];
  window.swpostlistConfig = {
    restRoot: 'https://site.test/wp-json/sw-postlist/v1/',
    nonce: 'test-nonce',
    locale: 'en_US',
    version: '1',
    adminUrl: '',
    siteUrl: 'https://site.test',
    pluginUrl: 'https://site.test/wp-content/plugins/sw-postlist/',
  };
  resetConfigForTests();
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: string, init?: RequestInit) => {
      const url = new URL(input);
      const call: Call = {
        method: init?.method ?? 'GET',
        path: url.pathname.replace('/wp-json/sw-postlist/v1', ''),
        query: url.searchParams,
        body:
          init?.body === undefined
            ? undefined
            : (JSON.parse(String(init.body)) as Record<string, unknown>),
      };
      calls.push(call);
      const reply = routes[`${call.method} ${call.path}`];
      if (reply === undefined)
        return new Response(JSON.stringify({ code: 'no_route', message: 'no route' }), {
          status: 404,
        });
      const resolved = typeof reply === 'function' ? reply(call) : reply;
      return new Response(JSON.stringify(resolved.json), { status: resolved.status ?? 200 });
    }),
  );
  return calls;
}

export async function mountPage(
  component: Component,
  options: {
    path?: string;
    routes?: string[];
    props?: Record<string, unknown>;
    stubs?: Record<string, unknown>;
  } = {},
) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const empty = { template: '<div />' };
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: empty },
      { path: '/views', component: empty },
      { path: '/views/new', name: 'view-new', component: component },
      { path: '/views/:id', name: 'view-edit', component: component },
      { path: '/templates', component: empty },
      { path: '/templates/new', name: 'template-new', component: component },
      { path: '/templates/:id', name: 'template-edit', component: component },
      { path: '/diagnostics', component: empty },
      ...(options.routes ?? []).map((path) => ({ path, component: empty })),
    ],
  });
  await router.push(options.path ?? '/');
  await router.isReady();
  const wrapper = mount(component, {
    props: options.props,
    global: {
      plugins: [
        pinia,
        router,
        createAppI18n('en_US'),
        [Quasar, { plugins: { Notify }, iconSet, lang: quasarLang('en') }],
      ],
      stubs: options.stubs as never,
    },
    attachTo: document.body,
  });
  mounted.push(wrapper);
  await flushPromises();
  return { wrapper, router };
}
