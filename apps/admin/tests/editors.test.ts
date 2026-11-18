import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CodeEditor from '@/components/CodeEditor.vue';
import TemplateEditorPage from '@/pages/TemplateEditorPage.vue';
import ViewEditorPage from '@/pages/ViewEditorPage.vue';
import { BUILTIN_SIGNATURES, IMAGE_SIGNATURE } from '@/template-engine';
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
  vi.useRealTimers();
  document.body.innerHTML = '';
});

const POST_SOURCE = {
  id: 'post:post',
  label: 'Posts',
  fields: {
    taxonomies: ['category', 'post_tag'],
    orderby: [],
    visitor_filters: [],
    count_mode: 'exact',
  },
};
const PAGE_SOURCE = {
  id: 'post:page',
  label: 'Pages',
  fields: { taxonomies: [], orderby: [], visitor_filters: [], count_mode: 'exact' },
};

function signatureJson(): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [name, signature] of Object.entries({
    ...BUILTIN_SIGNATURES,
    image: IMAGE_SIGNATURE,
  })) {
    out[name] = {
      inputs: signature.inputs,
      output: signature.output,
      help: signature.help,
      accepts_empty: signature.acceptsEmpty,
      params: signature.params.map((param) => ({ ...param, max_length: param.maxLength ?? null })),
    };
  }
  return out;
}

const DEFINITIONS = [
  { name: 'entry.title', type: 'text', description: 'Title', example: 'Hello', roles: ['entry'] },
  { name: 'entry.url', type: 'url', description: 'Link', example: '', roles: ['entry'] },
  {
    name: 'entry.terms.category',
    type: 'list',
    description: 'Categories',
    example: '',
    roles: ['entry'],
  },
];

const savedView = (overrides: Record<string, unknown> = {}) => ({
  id: 11,
  status: 'publish',
  document: {
    schema_version: 1,
    title: 'News',
    source: 'post:post',
    query: {
      taxonomies: [{ taxonomy: 'category', terms: [3, 4], operator: 'in' }],
      authors: [2],
      date: { after: '2026-01-01', before: null },
      meta: [{ key: 'featured', value: '1', compare: '=' }],
      include: [],
      exclude: [9],
      per_page: 6,
      offset: 1,
      orderby: 'meta_num',
      order: 'asc',
      order_meta_key: 'rank',
      context: 'none',
    },
    layout: {
      type: 'grid',
      columns: { desktop: 4, tablet: 2, mobile: 1 },
      gap: { desktop: 20 },
      aspect_ratio: '16/9',
      accent: 'primary',
      hidden: { mobile: true },
    },
    pagination: { mode: 'classic' },
    templates: { container: null, entry: 5 },
    ...overrides,
  },
});

const catalogRoutes = (): Record<string, Reply> => ({
  'GET /admin/sources': { json: { sources: [POST_SOURCE, PAGE_SOURCE] } },
  'GET /admin/filter-types': {
    json: {
      filter_types: [
        { id: 'taxonomy', label: 'Taxonomy', builtin: true },
        { id: 'acme', label: 'Acme', builtin: false },
      ],
    },
  },
  'GET /admin/templates': {
    json: {
      templates: [
        { id: 5, title: 'Card', role: 'entry', modified: '2026-11-18T10:00:00+00:00', used_by: 1 },
        {
          id: 6,
          title: 'Frame',
          role: 'container',
          modified: '2026-11-18T10:00:00+00:00',
          used_by: 0,
        },
      ],
    },
  },
});

describe('layout options (M6)', () => {
  it('offers the layouts of the server and sends slider options only for the slider', async () => {
    const calls = stubApi({
      ...catalogRoutes(),
      'GET /admin/layouts': {
        json: {
          layouts: [
            { id: 'grid', label: 'Grid', module: null },
            { id: 'slider', label: 'Slider', module: 'slider' },
            { id: 'timeline', label: 'Timeline', module: 'timeline' },
          ],
        },
      },
      'GET /admin/views/11': {
        json: savedView({ layout: { type: 'slider', slides: { desktop: 4 } } }),
      },
      'PUT /admin/views/11': (call) => ({
        json: { id: 11, status: 'draft', document: (call.body as { document: unknown }).document },
      }),
    });
    const { wrapper } = await mountPage(ViewEditorPage, { path: '/views/11' });
    await wrapper.get('[data-testid="tab-layout"]').trigger('click');
    await flushPromises();

    expect(wrapper.find('[data-testid="slider-options"]').exists()).toBe(true);
    await wrapper.get('[data-testid="save-draft"]').trigger('click');
    await flushPromises();
    const sent = (
      calls.find((call) => call.method === 'PUT')?.body as {
        document: { layout: Record<string, unknown> };
      }
    ).document.layout;
    expect(sent).toMatchObject({
      type: 'slider',
      slides: { desktop: 4, tablet: 2, mobile: 1 },
      autoplay: false,
      interval: 6000,
      loop: false,
      arrows: true,
      dots: true,
    });
  });

  it('does not send slider options for other layouts and falls back when the layout route is missing', async () => {
    const calls = stubApi({
      ...catalogRoutes(),
      'GET /admin/views/11': { json: savedView({ layout: { type: 'masonry' } }) },
      'PUT /admin/views/11': (call) => ({
        json: { id: 11, status: 'draft', document: (call.body as { document: unknown }).document },
      }),
    });
    const { wrapper } = await mountPage(ViewEditorPage, { path: '/views/11' });
    await wrapper.get('[data-testid="tab-layout"]').trigger('click');
    await flushPromises();

    expect(wrapper.find('[data-testid="slider-options"]').exists()).toBe(false);
    await wrapper.get('[data-testid="save-draft"]').trigger('click');
    await flushPromises();
    const sent = (
      calls.find((call) => call.method === 'PUT')?.body as {
        document: { layout: Record<string, unknown> };
      }
    ).document.layout;
    expect(sent.type).toBe('masonry');
    expect(sent).not.toHaveProperty('slides');
  });
});

describe('visitor filters (M7)', () => {
  const putBody = (calls: Array<{ method: string; body?: unknown }>) =>
    (
      calls.find((call) => call.method === 'PUT')?.body as {
        document: { filters?: Array<Record<string, unknown>> };
      }
    ).document;

  it('adds, edits, orders and removes filters and keeps filters of extensions', async () => {
    const calls = stubApi({
      ...catalogRoutes(),
      'GET /admin/views/11': {
        json: savedView({ filters: [{ type: 'acme', size: 3 }] }),
      },
      'PUT /admin/views/11': (call) => ({
        json: { id: 11, status: 'draft', document: (call.body as { document: unknown }).document },
      }),
    });
    const { wrapper } = await mountPage(ViewEditorPage, { path: '/views/11' });
    await wrapper.get('[data-testid="tab-filters"]').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('acme');
    await wrapper.get('[data-testid="filter-add-taxonomy"]').trigger('click');
    await wrapper.get('[data-testid="filter-add-search"]').trigger('click');
    await wrapper.get('[data-testid="filter-add-sort"]').trigger('click');
    await wrapper.get('[data-testid="filter-add-author"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-testid="filters-empty"]').exists()).toBe(false);

    await wrapper.get('[data-testid="filter-sort-3-title"]').trigger('click');
    await wrapper.get('[data-testid="filter-sort-3-modified"]').trigger('click');
    await flushPromises();
    await wrapper.get('[data-testid="filter-remove-2"]').trigger('click');
    await flushPromises();
    await wrapper.get('[data-testid="save-draft"]').trigger('click');
    await flushPromises();

    const filters = putBody(calls).filters ?? [];
    expect(filters.map((filter) => filter.type)).toEqual(['acme', 'taxonomy', 'sort', 'author']);
    expect(filters[0]).toEqual({ type: 'acme', size: 3 });
    expect(filters[1]).toMatchObject({ type: 'taxonomy', taxonomy: 'category' });
  });

  it('writes sort options in the canonical order and no filters key when none are configured', async () => {
    const calls = stubApi({
      ...catalogRoutes(),
      'GET /admin/views/11': {
        json: savedView({ filters: [{ type: 'sort', options: ['title'] }] }),
      },
      'PUT /admin/views/11': (call) => ({
        json: { id: 11, status: 'draft', document: (call.body as { document: unknown }).document },
      }),
    });
    const { wrapper } = await mountPage(ViewEditorPage, { path: '/views/11' });
    await wrapper.get('[data-testid="tab-filters"]').trigger('click');
    await flushPromises();
    await wrapper.get('[data-testid="filter-sort-0-date"]').trigger('click');
    await wrapper.get('[data-testid="filter-sort-0-title"]').trigger('click');
    await wrapper.get('[data-testid="filter-sort-0-modified"]').trigger('click');
    await flushPromises();
    await wrapper.get('[data-testid="save-draft"]').trigger('click');
    await flushPromises();
    expect(putBody(calls).filters?.[0]?.options).toEqual(['date', 'modified']);

    await wrapper.get('[data-testid="filter-remove-0"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-testid="filters-empty"]').exists()).toBe(true);
    await wrapper.get('[data-testid="save-draft"]').trigger('click');
    await flushPromises();
    const last = calls.filter((call) => call.method === 'PUT').at(-1)?.body as {
      document: Record<string, unknown>;
    };
    expect(last.document).not.toHaveProperty('filters');
  });

  it('edits the options of the built-in filter types and limits the number of filters', async () => {
    const calls = stubApi({
      ...catalogRoutes(),
      'GET /admin/views/11': {
        json: savedView({
          filters: [
            { type: 'taxonomy', taxonomy: 'category', display: 'buttons', terms: [3, 4] },
            { type: 'author', display: 'dropdown' },
            { type: 'search' },
          ],
        }),
      },
      'PUT /admin/views/11': (call) => ({
        json: { id: 11, status: 'draft', document: (call.body as { document: unknown }).document },
      }),
    });
    const { wrapper } = await mountPage(ViewEditorPage, { path: '/views/11' });
    await wrapper.get('[data-testid="tab-filters"]').trigger('click');
    await flushPromises();

    const inputs = wrapper.findAll('.swp-filter-row input');
    const byLabel = (label: string) =>
      inputs.find((input) => input.element.closest('label')?.textContent?.includes(label));
    const termsInput = byLabel('Offer only these terms');
    await termsInput?.setValue('5, 6, 6, x');
    const labelInput = wrapper
      .findAll('.swp-filter-row')[0]
      ?.findAll('input')
      .find((input) => input.element.closest('label')?.textContent?.includes('Label'));
    await labelInput?.setValue('Topics');
    await flushPromises();
    await wrapper.get('[data-testid="save-draft"]').trigger('click');
    await flushPromises();
    const sent = putBody(calls).filters ?? [];
    expect(sent[0]).toMatchObject({ terms: [5, 6], label: 'Topics' });

    await termsInput?.setValue('');
    await wrapper.get('[data-testid="filter-add-search"]').trigger('click');
    await wrapper.get('[data-testid="filter-add-search"]').trigger('click');
    await wrapper.get('[data-testid="filter-add-search"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-testid="filter-add-search"]').exists()).toBe(false);
  });
});

describe('CodeEditor', () => {
  it('mounts CodeMirror, reports edits and accepts inserted text', async () => {
    const { wrapper } = await mountPage(CodeEditor, {
      props: { modelValue: '<p>hi</p>', language: 'html', label: 'HTML' },
    });

    expect(wrapper.find('.cm-editor').exists()).toBe(true);
    expect(wrapper.find('.cm-content').attributes('aria-label')).toBe('HTML');
    (wrapper.vm as unknown as { insert: (text: string) => void }).insert('{{entry.title}}');
    await flushPromises();
    expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toBe('{{entry.title}}<p>hi</p>');

    await wrapper.setProps({ modelValue: 'replaced from outside' });
    expect(wrapper.find('.cm-content').text()).toContain('replaced from outside');
  });

  it('supports CSS, completion sources and diagnostics', async () => {
    const diagnostics = vi.fn(() => [
      { from: 0, to: 1, severity: 'error' as const, message: 'bad' },
    ]);
    const { wrapper } = await mountPage(CodeEditor, {
      props: {
        modelValue: '.a{}',
        language: 'css',
        label: 'CSS',
        diagnostics,
        completion: () => null,
      },
    });
    (wrapper.vm as unknown as { refresh: () => void }).refresh();

    expect(wrapper.find('.cm-editor').exists()).toBe(true);
    expect(diagnostics).toHaveBeenCalled();
    wrapper.unmount();
  });
});

describe('view editor', () => {
  it('loads a stored view into the form and saves what the form describes', async () => {
    const calls = stubApi({
      ...catalogRoutes(),
      'GET /admin/views/11': { json: savedView() },
      'PUT /admin/views/11': (call) => ({
        json: {
          id: 11,
          status: 'publish',
          document: (call.body as { document: unknown }).document,
        },
      }),
    });
    const { wrapper } = await mountPage(ViewEditorPage, { path: '/views/11' });

    expect((wrapper.get('[data-testid="view-title"]').element as HTMLInputElement).value).toBe(
      'News',
    );
    expect(wrapper.text()).toContain('[sw_postlist id="11"]');
    await wrapper.get('[data-testid="view-title"]').setValue('Renamed');
    await wrapper.get('[data-testid="tab-query"]').trigger('click');
    await flushPromises();
    expect((wrapper.get('[data-testid="view-per-page"]').element as HTMLInputElement).value).toBe(
      '6',
    );
    await wrapper.get('[data-testid="publish-view"]').trigger('click');
    await flushPromises();

    const put = calls.find((call) => call.method === 'PUT');
    const document = (
      put?.body as {
        document: Record<string, unknown> & {
          query: Record<string, unknown>;
          layout: Record<string, unknown>;
        };
      }
    ).document;
    expect((put?.body as { status: string }).status).toBe('publish');
    expect(document.title).toBe('Renamed');
    expect(document.query).toMatchObject({
      taxonomies: [{ taxonomy: 'category', terms: [3, 4], operator: 'in' }],
      authors: [2],
      per_page: 6,
      offset: 1,
      orderby: 'meta_num',
      order_meta_key: 'rank',
      exclude: [9],
    });
    expect((document.query as { date: unknown }).date).toEqual({
      after: '2026-01-01',
      before: null,
    });
    expect(document.layout).toMatchObject({
      type: 'grid',
      columns: { desktop: 4, tablet: 2, mobile: 1 },
      aspect_ratio: '16/9',
      accent: 'primary',
      hidden: { mobile: true },
    });
    expect(document.templates).toEqual({ container: null, entry: 5 });
    expect(notices.success).toHaveBeenCalledWith('Saved.');
  });

  it('creates a new view and moves to its editor', async () => {
    const calls = stubApi({
      ...catalogRoutes(),
      'POST /admin/views': (call) => ({
        status: 201,
        json: { id: 21, status: 'draft', document: (call.body as { document: unknown }).document },
      }),
      'GET /admin/views/21': { json: savedView() },
    });
    const { wrapper, router } = await mountPage(ViewEditorPage, { path: '/views/new' });
    await wrapper.get('[data-testid="view-title"]').setValue('Fresh');
    await wrapper.get('[data-testid="save-draft"]').trigger('click');
    await flushPromises();

    const post = calls.find((call) => call.method === 'POST');
    expect((post?.body as { status: string }).status).toBe('draft');
    expect(router.currentRoute.value.path).toBe('/views/21');
  });

  it('blocks saving with client side findings and keeps them visible', async () => {
    const calls = stubApi({ ...catalogRoutes() });
    const { wrapper } = await mountPage(ViewEditorPage, { path: '/views/new' });

    expect(wrapper.find('.swp-issues').exists()).toBe(true);
    expect(wrapper.text()).toContain('The title must not be empty');
    await wrapper.get('[data-testid="publish-view"]').trigger('click');
    await flushPromises();

    expect(calls.some((call) => call.method === 'POST')).toBe(false);
  });

  it('shows server findings after a failed save', async () => {
    stubApi({
      ...catalogRoutes(),
      'GET /admin/views/11': { json: savedView() },
      'PUT /admin/views/11': {
        status: 422,
        json: {
          code: 'template_references_invalid',
          message: 'Template problem',
          errors: [{ code: 'template_missing', path: 'templates.entry', message: 'gone' }],
        },
      },
    });
    const { wrapper } = await mountPage(ViewEditorPage, { path: '/views/11' });
    await wrapper.get('[data-testid="publish-view"]').trigger('click');
    await flushPromises();

    expect(wrapper.get('.swp-issues').text()).toContain('An assigned template does not exist.');

    stubApi({
      ...catalogRoutes(),
      'GET /admin/views/11': { json: savedView() },
      'PUT /admin/views/11': { status: 500, json: { code: 'boom', message: 'Server broke' } },
    });
    const second = await mountPage(ViewEditorPage, { path: '/views/11' });
    await second.wrapper.get('[data-testid="publish-view"]').trigger('click');
    await flushPromises();
    expect(second.wrapper.get('.swp-issues').text()).toContain('Server broke');
  });

  it('renders the preview in a sandboxed frame once the preview tab opens', async () => {
    const calls = stubApi({
      ...catalogRoutes(),
      'GET /admin/views/11': { json: savedView() },
      'POST /admin/views/preview': {
        json: {
          html: '<div class="swp-view">Hello</div>',
          css: '.x{}',
          total: 12,
          count: 6,
          page: 1,
          has_more: true,
          error: null,
        },
      },
    });
    const { wrapper } = await mountPage(ViewEditorPage, { path: '/views/11' });
    await wrapper.get('[data-testid="tab-preview"]').trigger('click');
    await flushPromises();

    expect(calls.some((call) => call.path === '/admin/views/preview')).toBe(true);
    const frame = wrapper.get('[data-testid="preview-frame"]');
    expect(frame.attributes('sandbox')).toBe('');
    expect(frame.attributes('srcdoc')).toContain('Hello');
    expect(frame.attributes('srcdoc')).toContain('assets/css/frontend.css');
    expect(wrapper.text()).toContain('12 results in total');

    await wrapper.get('[data-device="mobile"]').trigger('click');
    expect(wrapper.get('.swp-preview__frame').attributes('style')).toContain('375px');
    expect(wrapper.get('.swp-preview__width').text()).toBe('375 px');
  });

  it('reports a failing preview and edits query rows', async () => {
    stubApi({
      ...catalogRoutes(),
      'GET /admin/views/11': { json: savedView() },
      'POST /admin/views/preview': { status: 404, json: { code: 'source_missing', message: 'x' } },
    });
    const { wrapper } = await mountPage(ViewEditorPage, { path: '/views/11' });
    await wrapper.get('[data-testid="tab-query"]').trigger('click');
    await flushPromises();

    const addButtons = wrapper
      .findAll('button')
      .filter(
        (button) =>
          button.text().includes('Add taxonomy filter') ||
          button.text().includes('Add custom field filter'),
      );
    await addButtons[0]?.trigger('click');
    await addButtons[1]?.trigger('click');
    const removers = wrapper.findAll('button').filter((button) => button.text() === 'Remove');
    expect(removers.length).toBe(4);
    await removers[0]?.trigger('click');
    await removers[removers.length - 1]?.trigger('click');
    expect(wrapper.findAll('button').filter((button) => button.text() === 'Remove').length).toBe(2);

    await wrapper.get('[data-testid="tab-preview"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('source_missing');
  });

  it('survives a failing load', async () => {
    stubApi({});
    const { wrapper } = await mountPage(ViewEditorPage, { path: '/views/11' });

    expect(wrapper.find('h1').exists()).toBe(true);
    expect(notices.failure).toHaveBeenCalledWith('The data could not be loaded.');
  });
});

describe('template editor', () => {
  const routes = (extra: Record<string, Reply> = {}): Record<string, Reply> => ({
    ...catalogRoutes(),
    'GET /admin/placeholders': {
      json: {
        source: 'post:post',
        role: 'entry',
        placeholders: DEFINITIONS,
        formatters: signatureJson(),
      },
    },
    'POST /admin/templates/validate': { json: { valid: true, errors: [] } },
    'POST /admin/templates/preview': {
      json: { html: '<p class="x">Sample</p>', css: '', count: 1, error: null },
    },
    ...extra,
  });

  it('loads a template, shows the placeholder panel and inserts a placeholder', async () => {
    stubApi(
      routes({
        'GET /admin/templates/5': {
          json: {
            id: 5,
            used_by: [11],
            document: {
              schema_version: 1,
              title: 'Card',
              role: 'entry',
              html: '<p>{{entry.title}}</p>',
              css: '.a{}',
            },
          },
        },
      }),
    );
    const { wrapper } = await mountPage(TemplateEditorPage, { path: '/templates/5' });
    await flushPromises();

    expect(wrapper.text()).toContain('This template is used in 1 view(s)');
    expect(wrapper.get('[data-testid="placeholder-panel"]').text()).toContain(
      'entry.terms.category',
    );
    expect(wrapper.get('[data-testid="placeholder-panel"]').text()).toContain('truncate');
    expect(wrapper.find('[data-testid="template-valid"]').exists()).toBe(true);
    expect(wrapper.find('.cm-editor').exists()).toBe(true);

    await wrapper.get('[data-placeholder="entry.url"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-testid="html-editor"] .cm-content').text()).toContain(
      '{{entry.url}}',
    );
    await wrapper.get('[data-formatter="upper"]').trigger('click');
    expect(wrapper.get('[data-testid="html-editor"] .cm-content').text()).toContain('|upper');
  });

  it('flags problems instantly while the server answer is pending', async () => {
    stubApi(
      routes({
        'GET /admin/templates/5': {
          json: {
            id: 5,
            used_by: [],
            document: {
              schema_version: 1,
              title: 'Card',
              role: 'entry',
              html: '<p>{{entry.nope}}</p>',
              css: '',
            },
          },
        },
        'POST /admin/templates/validate': {
          json: {
            valid: false,
            errors: [
              {
                code: 'unknown_placeholder',
                message: 'Unknown placeholder "entry.nope".',
                line: 1,
                column: 4,
                context: {},
              },
            ],
          },
        },
      }),
    );
    const { wrapper } = await mountPage(TemplateEditorPage, { path: '/templates/5' });

    expect(wrapper.get('.swp-issues').text()).toContain('Unknown placeholder.');
    expect(wrapper.find('[data-testid="template-valid"]').exists()).toBe(false);
  });

  it('validates on the server after typing pauses and uses its findings', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const calls = stubApi(
      routes({
        'GET /admin/templates/5': {
          json: {
            id: 5,
            used_by: [],
            document: {
              schema_version: 1,
              title: 'Card',
              role: 'entry',
              html: '<p>{{entry.title}}</p>',
              css: '',
            },
          },
        },
        'POST /admin/templates/validate': {
          json: {
            valid: false,
            errors: [{ code: 'invalid_css', message: 'css bad', line: 1, column: 1, context: {} }],
          },
        },
      }),
    );
    const { wrapper } = await mountPage(TemplateEditorPage, { path: '/templates/5' });
    (
      wrapper.getComponent(CodeEditor) as unknown as { vm: { insert: (text: string) => void } }
    ).vm.insert('x');
    await flushPromises();
    await vi.advanceTimersByTimeAsync(700);
    await flushPromises();

    expect(
      calls.filter((call) => call.path === '/admin/templates/validate').length,
    ).toBeGreaterThan(0);
    expect(wrapper.get('.swp-issues').text()).toContain('The CSS is not acceptable.');
  });

  it('publishes a new template in one step and moves to its editor', async () => {
    const calls = stubApi(
      routes({
        'POST /admin/templates': (call) => ({
          status: 201,
          json: { id: 31, document: (call.body as { document: unknown }).document },
        }),
        'GET /admin/templates/31': {
          json: {
            id: 31,
            used_by: [],
            document: {
              schema_version: 1,
              title: 'Mine',
              role: 'entry',
              html: '<p>x</p>',
              css: '',
            },
          },
        },
      }),
    );
    const { wrapper, router } = await mountPage(TemplateEditorPage, {
      path: '/templates/new?role=entry',
    });
    await wrapper.get('[data-testid="template-title"]').setValue('Mine');
    await wrapper.get('[data-testid="publish-template"]').trigger('click');
    await flushPromises();

    const post = calls.find((call) => call.method === 'POST' && call.path === '/admin/templates');
    expect(post?.body).toMatchObject({
      source: 'post:post',
      document: { title: 'Mine', role: 'entry' },
    });
    expect(notices.success).toHaveBeenCalled();
    expect(router.currentRoute.value.path).toBe('/templates/31');
  });

  it('keeps the last valid version when publishing fails and shows why', async () => {
    stubApi(
      routes({
        'GET /admin/templates/5': {
          json: {
            id: 5,
            used_by: [11],
            document: {
              schema_version: 1,
              title: 'Card',
              role: 'entry',
              html: '<p>{{entry.title}}</p>',
              css: '',
            },
          },
        },
        'PUT /admin/templates/5': {
          status: 422,
          json: {
            code: 'template_invalid',
            message: 'x',
            sources: {
              'post:post': [
                {
                  code: 'unknown_placeholder',
                  message: 'Unknown placeholder "entry.nope".',
                  line: 1,
                  column: 4,
                  context: {},
                },
              ],
            },
          },
        },
      }),
    );
    const { wrapper } = await mountPage(TemplateEditorPage, { path: '/templates/5' });
    await wrapper.get('[data-testid="publish-template"]').trigger('click');
    await flushPromises();

    expect(wrapper.get('.swp-issues').text()).toContain('Unknown placeholder.');
    expect(wrapper.text()).toContain('The last valid version stays public.');
    expect(notices.success).not.toHaveBeenCalled();
  });

  it('shows document level errors and generic failures of a publish', async () => {
    stubApi(
      routes({
        'GET /admin/templates/5': {
          json: {
            id: 5,
            used_by: [],
            document: {
              schema_version: 1,
              title: 'Card',
              role: 'entry',
              html: '<p>x</p>',
              css: '',
            },
          },
        },
        'PUT /admin/templates/5': {
          status: 422,
          json: {
            code: 'invalid_document',
            message: 'Invalid',
            errors: [{ code: 'title_invalid', path: 'title', message: 'bad' }],
          },
        },
      }),
    );
    const first = await mountPage(TemplateEditorPage, { path: '/templates/5' });
    await first.wrapper.get('[data-testid="publish-template"]').trigger('click');
    await flushPromises();
    expect(first.wrapper.get('.swp-issues').text()).toContain('The title must not be empty');

    stubApi(
      routes({
        'GET /admin/templates/5': {
          json: {
            id: 5,
            used_by: [],
            document: {
              schema_version: 1,
              title: 'Card',
              role: 'entry',
              html: '<p>x</p>',
              css: '',
            },
          },
        },
        'PUT /admin/templates/5': { status: 500, json: { code: 'boom', message: 'Server broke' } },
      }),
    );
    const second = await mountPage(TemplateEditorPage, { path: '/templates/5' });
    await second.wrapper.get('[data-testid="publish-template"]').trigger('click');
    await flushPromises();
    expect(second.wrapper.get('.swp-issues').text()).toContain('Server broke');
  });

  it('refreshes the preview on demand and reports preview errors', async () => {
    const calls = stubApi(
      routes({
        'GET /admin/templates/5': {
          json: {
            id: 5,
            used_by: [],
            document: {
              schema_version: 1,
              title: 'Card',
              role: 'entry',
              html: '<p>x</p>',
              css: '',
            },
          },
        },
        'POST /admin/templates/preview': {
          status: 422,
          json: { code: 'source_required', message: 'x' },
        },
      }),
    );
    const { wrapper } = await mountPage(TemplateEditorPage, { path: '/templates/5' });
    await wrapper.get('[data-testid="refresh-template-preview"]').trigger('click');
    await flushPromises();

    expect(calls.filter((call) => call.path === '/admin/templates/preview').length).toBe(2);
    expect(wrapper.get('[role="alert"]').text()).toContain('source_required');
  });

  it('starts container templates with a container starter and survives failing loads', async () => {
    stubApi(routes());
    const { wrapper } = await mountPage(TemplateEditorPage, {
      path: '/templates/new?role=container',
    });
    expect(wrapper.get('[data-testid="html-editor"] .cm-content').text()).toContain('view.entries');

    stubApi({});
    const failing = await mountPage(TemplateEditorPage, { path: '/templates/5' });
    expect(failing.wrapper.find('h1').exists()).toBe(true);
    expect(notices.failure).toHaveBeenCalledWith('The data could not be loaded.');
  });
});
