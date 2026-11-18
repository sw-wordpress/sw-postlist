import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ImportExportPage from '@/pages/ImportExportPage.vue';
import TemplatesPage from '@/pages/TemplatesPage.vue';
import ViewsPage from '@/pages/ViewsPage.vue';
import { mountPage, stubApi } from './helpers';
import type { Reply } from './helpers';

const notices = vi.hoisted(() => ({ success: vi.fn(), failure: vi.fn() }));
vi.mock('@/composables/useNotify', () => ({ useNotify: () => notices }));

beforeEach(() => {
  notices.success.mockReset();
  notices.failure.mockReset();
});
afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});

const lists = (): Record<string, Reply> => ({
  'GET /admin/views': {
    json: {
      views: [
        {
          id: 7,
          title: 'News',
          status: 'publish',
          source: 'post:post',
          layout: 'grid',
          modified: '2026-11-18T10:00:00+00:00',
        },
      ],
    },
  },
  'GET /admin/templates': {
    json: {
      templates: [
        { id: 5, title: 'Card', role: 'entry', modified: '2026-11-18T10:00:00+00:00', used_by: 1 },
      ],
    },
  },
  'GET /admin/presets': {
    json: {
      presets: [
        { id: 'builtin', title: 'Bundled', origin: 'bundled' },
        { id: 'mine', title: 'Mine', origin: 'user' },
      ],
      diagnostics: [],
    },
  },
});

const report = (overrides: Record<string, unknown> = {}) => ({
  ok: true,
  dry_run: true,
  errors: [],
  counts: { views: 2, templates: 1, presets: 0 },
  created: { views: [], templates: [], presets: [] },
  renamed: [],
  ...overrides,
});

async function pick(wrapper: { get: (s: string) => { element: Element } }, file: File) {
  const input = wrapper.get('[data-testid="import-file"]').element as HTMLInputElement;
  Object.defineProperty(input, 'files', { value: [file], configurable: true });
  input.dispatchEvent(new Event('change'));
  // FileReader answers in a later task.
  await new Promise((resolve) => setTimeout(resolve, 40));
  await flushPromises();
}

describe('export', () => {
  it('exports the selection as a download and offers only own presets', async () => {
    const calls = stubApi({
      ...lists(),
      'POST /admin/export': { json: { package: { format: 'sw-postlist-package' } } },
    });
    const created: Array<{ download: string; click: () => void }> = [];
    const createElement = document.createElement.bind(document);
    vi.spyOn(document, 'createElement').mockImplementation((tag: string) => {
      const element = createElement(tag);
      if (tag === 'a') {
        element.click = vi.fn();
        created.push(element as unknown as { download: string; click: () => void });
      }
      return element;
    });
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:x'), revokeObjectURL: vi.fn() });
    const { wrapper } = await mountPage(ImportExportPage);

    expect(wrapper.find('[data-testid="export-preset-builtin"]').exists()).toBe(false);
    expect(wrapper.get('[data-testid="run-export"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-testid="export-view-7"]').trigger('click');
    await wrapper.get('[data-testid="export-template-5"]').trigger('click');
    await wrapper.get('[data-testid="export-preset-mine"]').trigger('click');
    await wrapper.get('[data-testid="run-export"]').trigger('click');
    await flushPromises();

    expect(calls.find((call) => call.path === '/admin/export')?.body).toEqual({
      views: [7],
      templates: [5],
      presets: ['mine'],
    });
    expect(created.at(-1)?.download).toMatch(/^postlist-export-\d{4}-\d{2}-\d{2}\.json$/);
    expect(created.at(-1)?.click).toHaveBeenCalled();
    expect(notices.success).toHaveBeenCalled();
  });

  it('reports a failing export', async () => {
    stubApi({
      ...lists(),
      'POST /admin/export': { status: 404, json: { code: 'export_item_missing', message: 'gone' } },
    });
    const { wrapper } = await mountPage(ImportExportPage);
    await wrapper.get('[data-testid="export-view-7"]').trigger('click');
    await wrapper.get('[data-testid="run-export"]').trigger('click');
    await flushPromises();
    expect(notices.failure).toHaveBeenCalledWith('gone');
  });
});

describe('import', () => {
  it('checks the file first, then imports on request and shows what happened', async () => {
    const calls = stubApi({
      ...lists(),
      'POST /admin/import': (call) =>
        call.body?.dry_run
          ? { json: report() }
          : {
              json: report({
                dry_run: false,
                created: { views: [31, 32], templates: [40], presets: [] },
                renamed: ['News → News (2)'],
              }),
            },
    });
    const { wrapper } = await mountPage(ImportExportPage);
    await pick(wrapper, new File(['{"format":"sw-postlist-package"}'], 'x.json'));

    expect(wrapper.get('[data-testid="import-summary"]').text()).toContain('2 views, 1 templates');
    expect(calls.filter((call) => call.path === '/admin/import')).toHaveLength(1);
    await wrapper.get('[data-testid="run-import"]').trigger('click');
    await flushPromises();

    expect(calls.filter((call) => call.path === '/admin/import')[1]?.body).toMatchObject({
      dry_run: false,
    });
    expect(wrapper.get('[data-testid="import-done"]').text()).toContain('Imported: 2 views');
    expect(wrapper.get('[data-testid="import-renamed"]').text()).toContain('News (2)');
    expect(wrapper.find('[data-testid="import-summary"]').exists()).toBe(false);
    expect(notices.success).toHaveBeenCalled();
  });

  it('lists the findings of a refused package and offers no import', async () => {
    stubApi({
      ...lists(),
      'POST /admin/import': {
        status: 422,
        json: report({
          ok: false,
          errors: [{ code: 'source_unknown', path: 'views.0.source', message: 'No such source' }],
        }),
      },
    });
    const { wrapper } = await mountPage(ImportExportPage);
    await pick(wrapper, new File(['{}'], 'x.json'));

    expect(wrapper.get('[role="alert"]').text()).toContain('No such source');
    expect(wrapper.find('[data-testid="run-import"]').exists()).toBe(false);
  });

  it('refuses files that are not JSON or too large before any request', async () => {
    const calls = stubApi(lists());
    const { wrapper } = await mountPage(ImportExportPage);
    await pick(wrapper, new File(['not json'], 'x.json'));
    expect(wrapper.get('[data-testid="import-file-error"]').text()).toContain('valid JSON');

    await pick(wrapper, new File([new Uint8Array(2 * 1024 * 1024 + 1)], 'big.json'));
    expect(wrapper.get('[data-testid="import-file-error"]').text()).toContain('2 MB');
    expect(calls.some((call) => call.path === '/admin/import')).toBe(false);

    await pick(wrapper, new File([], 'none.json'));
    expect(wrapper.find('[data-testid="import-file-error"]').exists()).toBe(true);
  });

  it('shows a failing check or import as a notice', async () => {
    stubApi({ ...lists(), 'POST /admin/import': { status: 500, json: { message: 'boom' } } });
    const { wrapper } = await mountPage(ImportExportPage);
    await pick(wrapper, new File(['{}'], 'x.json'));
    expect(notices.failure).toHaveBeenCalledWith('boom');
  });

  it('keeps the checked file when the final import is refused', async () => {
    stubApi({
      ...lists(),
      'POST /admin/import': (call) =>
        call.body?.dry_run
          ? { json: report() }
          : {
              status: 422,
              json: report({
                ok: false,
                errors: [{ code: 'import_failed', path: '', message: 'undone' }],
              }),
            },
    });
    const { wrapper } = await mountPage(ImportExportPage);
    await pick(wrapper, new File(['{}'], 'x.json'));
    await wrapper.get('[data-testid="run-import"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('undone');
  });

  it('survives failing lists', async () => {
    stubApi({});
    const { wrapper } = await mountPage(ImportExportPage);
    expect(wrapper.find('h1').exists()).toBe(true);
    expect(notices.failure).toHaveBeenCalled();
  });
});

describe('duplicate', () => {
  it('duplicates a view and a template and reloads the lists', async () => {
    const calls = stubApi({
      ...lists(),
      'POST /admin/views/7/duplicate': { status: 201, json: { id: 9, status: 'draft' } },
      'POST /admin/templates/5/duplicate': { status: 201, json: { id: 6 } },
    });
    const views = await mountPage(ViewsPage);
    await views.wrapper.get('[data-testid="duplicate-view-7"]').trigger('click');
    await flushPromises();
    const templates = await mountPage(TemplatesPage);
    await templates.wrapper.get('[data-testid="duplicate-template-5"]').trigger('click');
    await flushPromises();

    expect(calls.some((call) => call.path === '/admin/views/7/duplicate')).toBe(true);
    expect(calls.some((call) => call.path === '/admin/templates/5/duplicate')).toBe(true);
    expect(notices.success).toHaveBeenCalledTimes(2);
  });

  it('reports a failing duplicate', async () => {
    stubApi({
      ...lists(),
      'POST /admin/views/7/duplicate': {
        status: 404,
        json: { code: 'view_not_found', message: 'nope' },
      },
      'POST /admin/templates/5/duplicate': { status: 500, json: {} },
    });
    const views = await mountPage(ViewsPage);
    await views.wrapper.get('[data-testid="duplicate-view-7"]').trigger('click');
    await flushPromises();
    const templates = await mountPage(TemplatesPage);
    await templates.wrapper.get('[data-testid="duplicate-template-5"]').trigger('click');
    await flushPromises();
    expect(notices.failure).toHaveBeenCalledWith('nope');
    expect(notices.failure).toHaveBeenCalledTimes(2);
  });
});
