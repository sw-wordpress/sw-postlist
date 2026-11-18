import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DashboardPage from '@/pages/DashboardPage.vue';
import DiagnosticsPage from '@/pages/DiagnosticsPage.vue';
import SettingsPage from '@/pages/SettingsPage.vue';
import TemplatesPage from '@/pages/TemplatesPage.vue';
import ViewsPage from '@/pages/ViewsPage.vue';
import { mountPage, stubApi } from './helpers';

const notices = vi.hoisted(() => ({ success: vi.fn(), failure: vi.fn() }));
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notices }));

beforeEach(() => {
  vi.stubGlobal(
    'confirm',
    vi.fn(() => true),
  );
});

afterEach(() => {
  vi.unstubAllGlobals();
  document.body.innerHTML = '';
});

const view = (id: number, title: string, status = 'publish') => ({
  id,
  title,
  status,
  source: 'post:post',
  layout: 'grid',
  modified: '2026-11-18T10:00:00+00:00',
});
const template = (id: number, title: string, usedBy = 0) => ({
  id,
  title,
  role: 'entry',
  modified: '2026-11-18T10:00:00+00:00',
  used_by: usedBy,
});

describe('dashboard', () => {
  it('shows the counts from the API', async () => {
    stubApi({
      'GET /admin/views': { json: { views: [view(1, 'A'), view(2, 'B')] } },
      'GET /admin/templates': { json: { templates: [template(3, 'T')] } },
      'GET /admin/diagnostics': { json: { registrations: [], log: [] } },
    });
    const { wrapper } = await mountPage(DashboardPage);

    expect(wrapper.get('[data-testid="stat-views"] strong').text()).toBe('2');
    expect(wrapper.get('[data-testid="stat-templates"] strong').text()).toBe('1');
    expect(wrapper.get('[data-testid="stat-problems"] strong').text()).toBe('0');
    expect(wrapper.text()).toContain('Nothing unusual.');
  });

  it('survives a failing API', async () => {
    stubApi({});
    const { wrapper } = await mountPage(DashboardPage);

    expect(wrapper.find('[data-testid="stat-views"]').exists()).toBe(true);
  });
});

describe('views list', () => {
  it('lists views, copies the shortcode and trashes after confirmation', async () => {
    const calls = stubApi({
      'GET /admin/views': { json: { views: [view(7, 'News'), view(8, 'Draft view', 'draft')] } },
      'DELETE /admin/views/7': { json: { id: 7 } },
    });
    const writeText = vi.fn(async () => undefined);
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    const { wrapper } = await mountPage(ViewsPage);

    expect(wrapper.findAll('[data-testid="views-table"] tbody tr')).toHaveLength(2);
    expect(wrapper.text()).toContain('Draft');
    const buttons =
      wrapper.findAll('[data-testid="views-table"] tbody tr')[0]?.findAll('button') ?? [];
    await buttons[0]?.trigger('click');
    expect(writeText).toHaveBeenCalledWith('[sw_postlist id="7"]');

    await buttons[2]?.trigger('click');
    await flushPromises();
    expect(calls.some((call) => call.method === 'DELETE' && call.path === '/admin/views/7')).toBe(
      true,
    );
  });

  it('does not trash without confirmation and shows an empty state', async () => {
    vi.stubGlobal(
      'confirm',
      vi.fn(() => false),
    );
    const calls = stubApi({ 'GET /admin/views': { json: { views: [view(7, 'News')] } } });
    const { wrapper } = await mountPage(ViewsPage);
    await wrapper.findAll('button')[2]?.trigger('click');
    expect(calls.some((call) => call.method === 'DELETE')).toBe(false);

    stubApi({ 'GET /admin/views': { json: { views: [] } } });
    const empty = await mountPage(ViewsPage);
    expect(empty.wrapper.text()).toContain('No view yet.');
  });

  it('reports failures to trash or copy', async () => {
    stubApi({
      'GET /admin/views': { json: { views: [view(7, 'News')] } },
      'DELETE /admin/views/7': { status: 500, json: { code: 'trash_failed', message: 'Boom' } },
    });
    vi.stubGlobal('navigator', {
      clipboard: { writeText: vi.fn(async () => Promise.reject(new Error('denied'))) },
    });
    const { wrapper } = await mountPage(ViewsPage);
    const buttons = wrapper.findAll('button');
    await buttons[0]?.trigger('click');
    await buttons[2]?.trigger('click');
    await flushPromises();

    expect(notices.failure).toHaveBeenCalledWith('Saving failed.');
    expect(notices.failure).toHaveBeenCalledWith('Boom');
  });
});

describe('templates list', () => {
  it('blocks trashing a used template and trashes an unused one', async () => {
    const calls = stubApi({
      'GET /admin/templates': {
        json: { templates: [template(1, 'Used', 2), template(2, 'Free', 0)] },
      },
      'DELETE /admin/templates/2': { json: { id: 2 } },
    });
    const { wrapper } = await mountPage(TemplatesPage);
    const rows = wrapper.findAll('[data-testid="templates-table"] tbody tr');
    expect(rows[0]?.text()).toContain('2 view(s)');

    await rows[0]?.findAll('button')[1]?.trigger('click');
    expect(calls.some((call) => call.method === 'DELETE')).toBe(false);
    expect(notices.failure).toHaveBeenCalledWith(expect.stringContaining('still used by views'));

    await rows[1]?.findAll('button')[1]?.trigger('click');
    await flushPromises();
    expect(
      calls.some((call) => call.method === 'DELETE' && call.path === '/admin/templates/2'),
    ).toBe(true);
  });

  it('maps a server side in-use conflict to the same message', async () => {
    stubApi({
      'GET /admin/templates': { json: { templates: [template(2, 'Free', 0)] } },
      'DELETE /admin/templates/2': {
        status: 409,
        json: { code: 'template_in_use', message: 'used', used_by: [4] },
      },
    });
    const { wrapper } = await mountPage(TemplatesPage);
    await wrapper.findAll('tbody button')[1]?.trigger('click');
    await flushPromises();

    expect(notices.failure).toHaveBeenCalledWith(expect.stringContaining('still used by views'));
  });

  it('shows an empty state', async () => {
    stubApi({ 'GET /admin/templates': { json: { templates: [] } } });
    const { wrapper } = await mountPage(TemplatesPage);

    expect(wrapper.text()).toContain('No template yet.');
  });
});

describe('settings', () => {
  it('loads and saves the allowed meta keys per source', async () => {
    const calls = stubApi({
      'GET /admin/sources': {
        json: {
          sources: [
            {
              id: 'post:post',
              label: 'Posts',
              fields: { taxonomies: [], orderby: [], visitor_filters: [], count_mode: 'exact' },
            },
          ],
        },
      },
      'GET /admin/settings': { json: { meta_keys: { 'post:post': ['subtitle'] } } },
      'PUT /admin/settings': (call) => ({
        json: {
          meta_keys: {
            'post:post': (
              (call.body?.meta_keys as Record<string, string[]>)['post:post'] ?? []
            ).slice(0, 2),
          },
        },
      }),
    });
    const { wrapper } = await mountPage(SettingsPage);
    const input = wrapper.get('[data-testid="meta-keys-post:post"]');
    expect((input.element as HTMLInputElement).value).toBe('subtitle');

    await input.setValue('subtitle, teaser, extra');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    const put = calls.find((call) => call.method === 'PUT');
    expect(put?.body).toEqual({
      meta_keys: { 'post:post': ['subtitle', 'teaser', 'extra'] },
      maps: {},
    });
    expect(
      (wrapper.get('[data-testid="meta-keys-post:post"]').element as HTMLInputElement).value,
    ).toBe('subtitle, teaser');
    expect(notices.success).toHaveBeenCalledWith(expect.stringContaining('Settings saved'));
  });

  it('edits named maps: add, fill, rename rules and remove, and refuses invalid names', async () => {
    const sources = {
      'GET /admin/sources': {
        json: {
          sources: [
            {
              id: 'post:post',
              label: 'Posts',
              fields: { taxonomies: [], orderby: [], visitor_filters: [], count_mode: 'exact' },
            },
          ],
        },
      },
    };
    const calls = stubApi({
      ...sources,
      'GET /admin/settings': {
        json: {
          meta_keys: {},
          maps: {
            cats: {
              hide: ['Uncategorized'],
              labels: { 'Kategorie Markt': 'Markt' },
              strip_prefix: 'Kategorie ',
            },
          },
        },
      },
      'PUT /admin/settings': (call) => ({
        json: { meta_keys: {}, maps: (call.body as { maps: Record<string, unknown> }).maps },
      }),
    });
    const { wrapper } = await mountPage(SettingsPage);
    const field = (id: string) => wrapper.get(`[data-testid="${id}"]`).element as HTMLInputElement;

    expect(field('map-name-0').value).toBe('cats');
    expect(field('map-hide-0').value).toBe('Uncategorized');
    expect(field('map-labels-0').value).toBe('Kategorie Markt = Markt');
    expect(field('map-prefix-0').value).toBe('Kategorie ');

    await wrapper.get('[data-testid="map-hide-0"]').setValue('Uncategorized\n  Allgemein  \n\n');
    await wrapper.get('[data-testid="map-labels-0"]').setValue('a = b\nnonsense\n= x\ny =');
    await wrapper.get('[data-testid="map-add"]').trigger('click');
    await wrapper.get('[data-testid="map-name-1"]').setValue('Bad Name');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(calls.some((call) => call.method === 'PUT')).toBe(false);
    expect(notices.failure).toHaveBeenCalledWith(expect.stringContaining('invalid or used twice'));

    await wrapper.get('[data-testid="map-name-1"]').setValue('cats');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(calls.some((call) => call.method === 'PUT')).toBe(false);

    await wrapper.get('[data-testid="map-name-1"]').setValue('other');
    await wrapper.get('[data-testid="map-prefix-1"]').setValue('Pre ');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const put = calls.find((call) => call.method === 'PUT');
    expect((put?.body as { maps: unknown }).maps).toEqual({
      cats: {
        hide: ['Uncategorized', 'Allgemein'],
        labels: { a: 'b' },
        strip_prefix: 'Kategorie ',
      },
      other: { hide: [], labels: {}, strip_prefix: 'Pre ' },
    });

    await wrapper.get('[data-testid="map-remove-0"]').trigger('click');
    expect(wrapper.find('[data-testid="map-1"]').exists()).toBe(false);
    expect(field('map-name-0').value).toBe('other');
  });

  it('shows a hint when no map exists yet', async () => {
    stubApi({
      'GET /admin/sources': { json: { sources: [] } },
      'GET /admin/settings': { json: { meta_keys: {} } },
    });
    const { wrapper } = await mountPage(SettingsPage);
    expect(wrapper.find('[data-testid="maps-empty"]').exists()).toBe(true);
  });

  it('reports failures', async () => {
    stubApi({
      'GET /admin/sources': {
        json: {
          sources: [
            {
              id: 'post:post',
              label: 'Posts',
              fields: { taxonomies: [], orderby: [], visitor_filters: [], count_mode: 'exact' },
            },
          ],
        },
      },
      'GET /admin/settings': { json: { meta_keys: {} } },
      'PUT /admin/settings': { status: 500, json: { code: 'x', message: 'x' } },
    });
    const { wrapper } = await mountPage(SettingsPage);
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(notices.failure).toHaveBeenCalledWith('Saving failed.');
  });

  it('survives a failing load', async () => {
    stubApi({});
    const { wrapper } = await mountPage(SettingsPage);

    expect(wrapper.find('form').exists()).toBe(true);
    expect(notices.failure).toHaveBeenCalledWith('The data could not be loaded.');
  });
});

describe('diagnostics', () => {
  it('shows registrations and the log, newest first', async () => {
    stubApi({
      'GET /admin/diagnostics': {
        json: {
          registrations: [{ code: 'source_interface', message: 'Bad source', source: 'stdClass' }],
          log: [
            { time: '2026-11-18T10:00:00+00:00', code: 'first', message: 'one', context: {} },
            { time: '2026-11-18T11:00:00+00:00', code: 'second', message: 'two', context: {} },
          ],
        },
      },
    });
    const { wrapper } = await mountPage(DiagnosticsPage);

    expect(wrapper.get('[data-testid="registrations-table"]').text()).toContain('source_interface');
    const codes = wrapper
      .findAll('[data-testid="log-table"] tbody td:nth-child(2)')
      .map((cell) => cell.text());
    expect(codes).toEqual(['second', 'first']);
  });

  it('shows empty states', async () => {
    stubApi({ 'GET /admin/diagnostics': { json: { registrations: [], log: [] } } });
    const { wrapper } = await mountPage(DiagnosticsPage);

    expect(wrapper.text()).toContain('All extensions are registered correctly.');
    expect(wrapper.text()).toContain('No entries.');
  });

  it('survives a failing load', async () => {
    stubApi({});
    const { wrapper } = await mountPage(DiagnosticsPage);

    expect(wrapper.find('h1').exists()).toBe(true);
  });
});
