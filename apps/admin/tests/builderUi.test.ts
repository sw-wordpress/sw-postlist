import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  createNode,
  defaultModel,
  emptyModel,
  generate,
  isValidModel,
  parseModel,
} from '@/builder/model';
import type { BuilderModel, ContainerNode } from '@/builder/model';
import { addNode, flatten, moveNode, nodeAt, removeNode, updateNode } from '@/builder/tree';
import BuilderPanel from '@/components/BuilderPanel.vue';
import PresetsPage from '@/pages/PresetsPage.vue';
import TemplateEditorPage from '@/pages/TemplateEditorPage.vue';
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
  document.body.innerHTML = '';
});

describe('builder tree helpers', () => {
  it('flattens in document order with depth and paths', () => {
    const rows = flatten(defaultModel());

    expect(rows.map((row) => [row.path.join('.'), row.depth, row.node.type])).toEqual([
      ['', 0, 'container'],
      ['0', 1, 'image'],
      ['1', 1, 'container'],
      ['1.0', 2, 'title'],
      ['1.1', 2, 'date'],
      ['1.2', 2, 'excerpt'],
      ['1.3', 2, 'readmore'],
    ]);
  });

  it('finds nodes by path and returns null for broken paths', () => {
    const model = defaultModel();

    expect(nodeAt(model, [])?.type).toBe('container');
    expect(nodeAt(model, [1, 0])?.type).toBe('title');
    expect(nodeAt(model, [9])).toBeNull();
    expect(nodeAt(model, [0, 0])).toBeNull();
  });

  it('adds into the selected container, falls back to the root and never mutates the input', () => {
    const model = defaultModel();
    const inside = addNode(model, [1], createNode('author'));
    expect(inside.path).toEqual([1, 4]);
    expect((nodeAt(inside.model, [1]) as ContainerNode).children).toHaveLength(5);
    expect((nodeAt(model, [1]) as ContainerNode).children).toHaveLength(4);

    const fallback = addNode(model, [0], createNode('spacer'));
    expect(fallback.path).toEqual([2]);
    const missing = addNode(model, [7, 7], createNode('spacer'));
    expect(missing.path).toEqual([2]);
  });

  it('removes, moves and updates nodes', () => {
    const model = defaultModel();

    expect(nodeAt(removeNode(model, [1, 0]), [1, 0])?.type).toBe('date');
    expect(removeNode(model, [])).toEqual(model);

    const down = moveNode(model, [1, 0], 1);
    expect(down.path).toEqual([1, 1]);
    expect(nodeAt(down.model, [1, 0])?.type).toBe('date');
    expect(moveNode(model, [1, 0], -1).model).toEqual(model);
    expect(moveNode(model, [1, 3], 1).model).toEqual(model);
    expect(moveNode(model, [], 1).path).toEqual([]);

    const updated = updateNode(model, [1, 0], { tag: 'h2' });
    expect(nodeAt(updated, [1, 0])).toMatchObject({ type: 'title', tag: 'h2' });
    expect(updateNode(model, [8], { tag: 'h2' })).toEqual(model);
  });
});

describe('builder panel', () => {
  it('shows the tree, edits settings and reports every change as a valid model', async () => {
    const { wrapper } = await mountPage(BuilderPanel, { props: { modelValue: defaultModel() } });
    expect(wrapper.findAll('[role="treeitem"]')).toHaveLength(7);

    await wrapper.get('[data-path="1.0"] .swp-builder__label').trigger('click');
    expect(wrapper.get('[data-testid="builder-settings"]').text()).toContain('Title');
    await wrapper.get('[data-path="1.1"] .swp-builder__label').trigger('click');
    const format = wrapper.get('[data-field="format"]');
    await format.setValue('d/m/Y');
    const emitted = wrapper.emitted('update:modelValue') as BuilderModel[][];
    const last = emitted[emitted.length - 1]?.[0] as BuilderModel;
    expect(isValidModel(last)).toBe(true);
    expect(nodeAt(last, [1, 1])).toMatchObject({ type: 'date', format: 'd/m/Y' });
  });

  it('refuses input that the generator could not express', async () => {
    const { wrapper } = await mountPage(BuilderPanel, { props: { modelValue: defaultModel() } });
    await wrapper.get('[data-path="1.1"] .swp-builder__label').trigger('click');
    await wrapper.get('[data-field="format"]').setValue('bad"format');

    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
  });

  it('adds, moves and removes elements with the row buttons', async () => {
    const { wrapper } = await mountPage(BuilderPanel, { props: { modelValue: emptyModel() } });
    await wrapper.get('[data-testid="builder-add"]').trigger('click');

    let emitted = wrapper.emitted('update:modelValue') as BuilderModel[][];
    expect(flatten(emitted[0]?.[0] as BuilderModel).map((row) => row.node.type)).toEqual([
      'container',
      'title',
    ]);

    await wrapper.setProps({ modelValue: defaultModel() });
    await wrapper.get('[data-path="1.0"] [data-action="down"]').trigger('click');
    emitted = wrapper.emitted('update:modelValue') as BuilderModel[][];
    expect(nodeAt(emitted[emitted.length - 1]?.[0] as BuilderModel, [1, 1])?.type).toBe('title');

    await wrapper.get('[data-path="1.0"] [data-action="remove"]').trigger('click');
    emitted = wrapper.emitted('update:modelValue') as BuilderModel[][];
    expect(flatten(emitted[emitted.length - 1]?.[0] as BuilderModel)).toHaveLength(6);
    await wrapper.get('[data-path="1.0"] [data-action="up"]').trigger('click');
    expect(wrapper.find('[data-path="root"] [data-action]').exists()).toBe(false);
  });

  it('resets the selection when the selected element disappears', async () => {
    const { wrapper } = await mountPage(BuilderPanel, { props: { modelValue: defaultModel() } });
    await wrapper.get('[data-path="1.3"] .swp-builder__label').trigger('click');
    expect(wrapper.find('[data-testid="builder-settings"]').exists()).toBe(true);

    await wrapper.setProps({ modelValue: emptyModel() });
    expect(wrapper.get('[data-testid="builder-settings"]').text()).toContain('Container');
  });

  it('edits numbers, selects, checkboxes and colours', async () => {
    const model = emptyModel();
    model.root.children = [createNode('excerpt'), createNode('image'), createNode('container')];
    const { wrapper } = await mountPage(BuilderPanel, { props: { modelValue: model } });

    await wrapper.get('[data-path="0"] .swp-builder__label').trigger('click');
    await wrapper.get('[data-field="words"]').setValue('12');
    let emitted = wrapper.emitted('update:modelValue') as BuilderModel[][];
    expect(nodeAt(emitted[emitted.length - 1]?.[0] as BuilderModel, [0])).toMatchObject({
      words: 12,
    });

    await wrapper.get('[data-path="2"] .swp-builder__label').trigger('click');
    await wrapper.get('[data-field="background"]').setValue('#ff0000');
    emitted = wrapper.emitted('update:modelValue') as BuilderModel[][];
    expect(nodeAt(emitted[emitted.length - 1]?.[0] as BuilderModel, [2])).toMatchObject({
      background: '#ff0000',
    });

    await wrapper.get('[data-path="1"] .swp-builder__label').trigger('click');
    expect(wrapper.find('[data-field="size"]').exists()).toBe(true);
    expect(wrapper.find('[data-field="link"]').exists()).toBe(true);
  });
});

/** Lets the lazily loaded editor chunk resolve. */
const settle = async (): Promise<void> => {
  await flushPromises();
  await new Promise((resolve) => setTimeout(resolve, 30));
  await flushPromises();
};

const signatures = () => {
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
};
const def = (name: string, type: string) => ({
  name,
  type,
  description: '',
  example: '',
  roles: ['entry'],
});
const templateRoutes = (
  document: Record<string, unknown>,
  extra: Record<string, Reply> = {},
): Record<string, Reply> => ({
  'GET /admin/sources': {
    json: {
      sources: [
        {
          id: 'post:post',
          label: 'Posts',
          fields: {
            taxonomies: ['category'],
            orderby: [],
            visitor_filters: [],
            count_mode: 'exact',
          },
        },
      ],
    },
  },
  'GET /admin/templates': { json: { templates: [] } },
  'GET /admin/templates/5': { json: { id: 5, used_by: [], document } },
  'GET /admin/placeholders': {
    json: {
      source: 'post:post',
      role: 'entry',
      placeholders: [
        def('entry.title', 'text'),
        def('entry.url', 'url'),
        def('entry.image', 'image'),
        def('entry.date', 'date'),
        def('entry.excerpt', 'text'),
        def('entry.author.name', 'text'),
        def('entry.author.url', 'url'),
        def('entry.terms.category', 'list'),
        def('entry.meta.subtitle', 'text'),
      ],
      formatters: signatures(),
    },
  },
  'POST /admin/templates/validate': { json: { valid: true, errors: [] } },
  'POST /admin/templates/preview': { json: { html: '', css: '', count: 0, error: null } },
  ...extra,
});

describe('builder tab of the template editor (M5 acceptance)', () => {
  it('opens builder generated code, edits it and saves exactly the generated output', async () => {
    const model = defaultModel();
    const nested = model.root.children[1] as ContainerNode;
    nested.children.push({
      ...createNode('container'),
      direction: 'row',
      children: [createNode('author')],
    } as ContainerNode);
    const generated = generate(model);
    const calls = stubApi(
      templateRoutes(
        {
          schema_version: 1,
          title: 'Card',
          role: 'entry',
          html: generated.html,
          css: generated.css,
        },
        {
          'PUT /admin/templates/5': (call) => ({
            json: { id: 5, document: (call.body as { document: unknown }).document },
          }),
        },
      ),
    );
    const { wrapper } = await mountPage(TemplateEditorPage, { path: '/templates/5' });
    await settle();

    await wrapper.get('[data-testid="tab-builder"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-testid="builder-notice"]').exists()).toBe(false);
    expect(wrapper.findAll('[role="treeitem"]').length).toBe(9);

    await wrapper.get('[data-path="1.0"] .swp-builder__label').trigger('click');
    await wrapper.get('[data-field="tag"]').trigger('click');
    // Change the heading through the panel API: select the h2 option.
    const changed = JSON.parse(JSON.stringify(model)) as BuilderModel;
    ((changed.root.children[1] as ContainerNode).children[0] as { tag: string }).tag = 'h2';
    wrapper.getComponent(BuilderPanel).vm.$emit('update:modelValue', changed);
    await flushPromises();

    const expected = generate(changed);
    await wrapper.get('[data-testid="publish-template"]').trigger('click');
    await flushPromises();
    const put = calls.find((call) => call.method === 'PUT');
    const saved = (put?.body as { document: { html: string; css: string } }).document;
    expect(saved.html).toBe(expected.html);
    expect(saved.css).toBe(expected.css);
    expect(saved.html).toContain('<h2 class="swp-b-title">');
    expect(parseModel(saved.html)).toEqual(changed);
  });

  it('warns before replacing hand written code and keeps it on request', async () => {
    stubApi(
      templateRoutes({
        schema_version: 1,
        title: 'Mine',
        role: 'entry',
        html: '<p>{{entry.title}}</p>',
        css: '',
      }),
    );
    const { wrapper } = await mountPage(TemplateEditorPage, { path: '/templates/5' });
    await settle();
    await wrapper.get('[data-testid="tab-builder"]').trigger('click');
    await flushPromises();

    expect(wrapper.get('[data-testid="builder-notice"]').text()).toContain(
      'was not created with the builder',
    );
    await wrapper.get('[data-testid="builder-keep"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-testid="html-editor"] .cm-content').text()).toContain(
      '{{entry.title}}',
    );
    expect(wrapper.find('[data-testid="builder-notice"]').exists()).toBe(false);
  });

  it('replaces hand written code with a new card only after the explicit choice', async () => {
    stubApi(
      templateRoutes({
        schema_version: 1,
        title: 'Mine',
        role: 'entry',
        html: '<p>{{entry.title}}</p>',
        css: '',
      }),
    );
    const { wrapper } = await mountPage(TemplateEditorPage, { path: '/templates/5' });
    await settle();
    await wrapper.get('[data-testid="tab-builder"]').trigger('click');
    await flushPromises();
    await wrapper.get('[data-testid="builder-start"]').trigger('click');
    await flushPromises();

    expect(wrapper.find('[data-testid="builder-notice"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="builder"]').exists()).toBe(true);
    expect(wrapper.get('[data-testid="html-editor"] .cm-content').text()).toContain(
      'data-swp-builder',
    );
  });

  it('recognises builder code that was edited by hand and restores the builder state on request', async () => {
    const generated = generate(defaultModel());
    stubApi(
      templateRoutes({
        schema_version: 1,
        title: 'Card',
        role: 'entry',
        html: generated.html.replace('swp-b-title', 'swp-b-title extra'),
        css: generated.css,
      }),
    );
    const { wrapper } = await mountPage(TemplateEditorPage, { path: '/templates/5' });
    await settle();
    await wrapper.get('[data-testid="tab-builder"]').trigger('click');
    await flushPromises();

    expect(wrapper.get('[data-testid="builder-notice"]').text()).toContain('edited by hand');
    await wrapper.get('[data-testid="builder-start"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-testid="html-editor"] .cm-content').text()).not.toContain(
      'swp-b-title extra',
    );
  });

  it('starts an empty template with the default card and offers no builder for container templates', async () => {
    stubApi(templateRoutes({ schema_version: 1, title: '', role: 'entry', html: '', css: '' }));
    const { wrapper } = await mountPage(TemplateEditorPage, { path: '/templates/5' });
    await settle();
    await wrapper.get('[data-testid="tab-builder"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-testid="builder"]').exists()).toBe(true);

    stubApi(
      templateRoutes({
        schema_version: 1,
        title: 'C',
        role: 'container',
        html: '<div>{{view.entries}}</div>',
        css: '',
      }),
    );
    const container = await mountPage(TemplateEditorPage, { path: '/templates/5' });
    expect(container.wrapper.find('[data-testid="tab-builder"]').exists()).toBe(false);
  });

  it('saves the current template as a preset and keeps the builder model only when it matches', async () => {
    const generated = generate(defaultModel());
    const calls = stubApi(
      templateRoutes(
        {
          schema_version: 1,
          title: 'Card',
          role: 'entry',
          html: generated.html,
          css: generated.css,
        },
        { 'POST /admin/presets': { status: 201, json: { id: 'my-card' } } },
      ),
    );
    vi.stubGlobal(
      'prompt',
      vi.fn(() => 'my-card'),
    );
    const { wrapper } = await mountPage(TemplateEditorPage, { path: '/templates/5' });
    await wrapper.get('[data-testid="save-as-preset"]').trigger('click');
    await flushPromises();

    const post = calls.find((call) => call.path === '/admin/presets');
    const preset = (
      post?.body as {
        preset: {
          id: string;
          builder: unknown;
          entry: { html: string };
          container: { html: string };
        };
      }
    ).preset;
    expect(preset.id).toBe('my-card');
    expect(preset.entry.html).toBe(generated.html);
    expect(preset.builder).toEqual(defaultModel());
    expect(preset.container.html).toContain('view.entries');
    expect(notices.success).toHaveBeenCalledWith('Preset saved.');

    vi.stubGlobal(
      'prompt',
      vi.fn(() => null),
    );
    const before = calls.length;
    await wrapper.get('[data-testid="save-as-preset"]').trigger('click');
    expect(calls.length).toBe(before);
  });

  it('reports a failing preset save', async () => {
    stubApi(
      templateRoutes(
        { schema_version: 1, title: '', role: 'entry', html: '<p>{{entry.title}}</p>', css: '' },
        {
          'POST /admin/presets': {
            status: 422,
            json: { code: 'invalid_document', message: 'Invalid preset' },
          },
        },
      ),
    );
    vi.stubGlobal(
      'prompt',
      vi.fn(() => 'x'),
    );
    const { wrapper } = await mountPage(TemplateEditorPage, { path: '/templates/5' });
    await wrapper.get('[data-testid="save-as-preset"]').trigger('click');
    await flushPromises();

    expect(notices.failure).toHaveBeenCalledWith('Invalid preset');
  });
});

describe('presets page', () => {
  const preset = (id: string, extra: Record<string, unknown> = {}) => ({
    schema_version: 1,
    id,
    title: `Preset ${id}`,
    description: 'Description',
    layout: { type: 'grid' },
    container: { html: '<div>{{view.entries}}</div>', css: '' },
    entry: {
      html: '{{#entry.image}}<i></i>{{/entry.image}}<h3>{{entry.title}}</h3>',
      css: 'h3{color:red}',
    },
    builder: null,
    origin: 'bundled',
    overrides: null,
    can_reset: false,
    ...extra,
  });
  const routes = (extra: Record<string, Reply> = {}): Record<string, Reply> => ({
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
    'GET /admin/templates': { json: { templates: [] } },
    'GET /admin/presets': {
      json: {
        presets: [
          preset('card'),
          preset('mine', { origin: 'user', can_reset: true, overrides: 'bundled' }),
        ],
        diagnostics: [],
      },
    },
    ...extra,
  });

  it('lists presets with origin and override hints and previews the sample', async () => {
    stubApi(routes());
    const { wrapper } = await mountPage(PresetsPage);

    expect(wrapper.findAll('[data-preset]')).toHaveLength(2);
    expect(wrapper.get('[data-preset="mine"]').text()).toContain('overrides the original');
    expect(wrapper.get('[data-preset="card"]').text()).toContain('Bundled');
    await wrapper.get('[data-preset="card"] [data-action="preview"]').trigger('click');
    const frame = wrapper.get('[data-testid="preview-frame"]');
    expect(frame.attributes('srcdoc')).toContain('<h3>Beispiel-Titel eines Eintrags</h3>');
    expect(frame.attributes('srcdoc')).toContain('h3{color:red}');
    await wrapper.get('[data-preset="card"] [data-action="preview"]').trigger('click');
    expect(wrapper.find('[data-testid="preview-frame"]').exists()).toBe(false);
  });

  it('applies a preset for the chosen source and moves to the templates', async () => {
    const calls = stubApi(
      routes({
        'POST /admin/presets/card/apply': {
          status: 201,
          json: { container_template: 1, entry_template: 2, layout: {} },
        },
      }),
    );
    const { wrapper, router } = await mountPage(PresetsPage);
    await wrapper.get('[data-preset="card"] [data-action="apply"]').trigger('click');
    await flushPromises();

    expect(calls.find((call) => call.path === '/admin/presets/card/apply')?.body).toEqual({
      source: 'post:post',
    });
    expect(notices.success).toHaveBeenCalledWith('Templates created.');
    expect(router.currentRoute.value.path).toBe('/templates');
  });

  it('reports failures to apply and reset', async () => {
    stubApi(
      routes({
        'POST /admin/presets/card/apply': {
          status: 422,
          json: { code: 'preset_template_invalid', message: 'Does not compile' },
        },
        'DELETE /admin/presets/mine': { status: 500, json: { code: 'x', message: 'Boom' } },
      }),
    );
    vi.stubGlobal(
      'prompt',
      vi.fn(() => 'copy'),
    );
    vi.stubGlobal(
      'confirm',
      vi.fn(() => true),
    );
    const { wrapper } = await mountPage(PresetsPage);
    await wrapper.get('[data-preset="card"] [data-action="apply"]').trigger('click');
    await wrapper.get('[data-preset="mine"] [data-action="reset"]').trigger('click');
    await flushPromises();

    expect(notices.failure).toHaveBeenCalledWith('Does not compile');
    expect(notices.failure).toHaveBeenCalledWith('Boom');
  });

  it('resets with confirmation and reloads the list', async () => {
    const calls = stubApi(
      routes({
        'DELETE /admin/presets/mine': { json: { id: 'mine' } },
      }),
    );
    vi.stubGlobal(
      'confirm',
      vi.fn(() => true),
    );
    const { wrapper } = await mountPage(PresetsPage);
    await wrapper.get('[data-preset="mine"] [data-action="reset"]').trigger('click');
    await flushPromises();
    expect(
      calls.some((call) => call.method === 'DELETE' && call.path === '/admin/presets/mine'),
    ).toBe(true);
    expect(
      calls.filter((call) => call.path === '/admin/presets' && call.method === 'GET').length,
    ).toBe(2);

    const before = calls.length;
    vi.stubGlobal(
      'confirm',
      vi.fn(() => false),
    );
    await wrapper.get('[data-preset="mine"] [data-action="reset"]').trigger('click');
    expect(calls.length).toBe(before);
  });

  it('exports a preset as a JSON file without management fields', async () => {
    stubApi(routes());
    const created: Array<{ href: string; download: string; click: () => void }> = [];
    const createElement = document.createElement.bind(document);
    vi.spyOn(document, 'createElement').mockImplementation((tag: string) => {
      const element = createElement(tag);
      if (tag === 'a') {
        element.click = vi.fn();
        created.push(element as unknown as { href: string; download: string; click: () => void });
      }
      return element;
    });
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:x'), revokeObjectURL: vi.fn() });
    const { wrapper } = await mountPage(PresetsPage);
    await wrapper.get('[data-preset="card"] [data-action="export"]').trigger('click');

    expect(created.at(-1)?.download).toBe('postlist-preset-card.json');
    expect(created.at(-1)?.click).toHaveBeenCalled();
    vi.restoreAllMocks();
  });

  it('survives a failing load', async () => {
    stubApi({});
    const { wrapper } = await mountPage(PresetsPage);

    expect(wrapper.find('h1').exists()).toBe(true);
    expect(notices.failure).toHaveBeenCalledWith('The data could not be loaded.');
  });
});
