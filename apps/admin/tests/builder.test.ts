import { readFileSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  BUILDER_VERSION,
  MAX_DEPTH,
  MAX_NODES,
  NODE_TYPES,
  createNode,
  defaultModel,
  emptyModel,
  generate,
  isGeneratedFrom,
  isValidModel,
  parseModel,
} from '@/builder/model';
import type { BuilderModel, ContainerNode } from '@/builder/model';
import {
  BUILTIN_SIGNATURES,
  IMAGE_SIGNATURE,
  compileTemplate,
  postlistProfile,
} from '@/template-engine';
import type { CompileContext, PlaceholderDefinition, ValueType } from '@/template-engine';

const def = (name: string, type: ValueType): PlaceholderDefinition => ({
  name,
  type,
  description: '',
  example: '',
  roles: ['entry'],
});
const DEFINITIONS = [
  def('entry.title', 'text'),
  def('entry.excerpt', 'text'),
  def('entry.url', 'url'),
  def('entry.image', 'image'),
  def('entry.date', 'date'),
  def('entry.author.name', 'text'),
  def('entry.author.url', 'url'),
  def('entry.terms.category', 'list'),
  def('entry.terms.post_tag', 'list'),
  def('entry.meta.subtitle', 'text'),
  def('entry.meta.price', 'text'),
];
const context: CompileContext = {
  profile: postlistProfile(),
  find: (name) => DEFINITIONS.find((entry) => entry.name === name) ?? null,
  formatters: { ...BUILTIN_SIGNATURES, image: IMAGE_SIGNATURE },
  role: 'entry',
};

function everything(): BuilderModel {
  const model = emptyModel();
  const inner = createNode('container') as ContainerNode;
  inner.direction = 'row';
  inner.background = '#AABBCC';
  inner.children = [createNode('title'), createNode('spacer')];
  model.root.children = [
    createNode('image'),
    { type: 'image', size: 'full', link: false },
    { type: 'title', tag: 'h2', link: false },
    createNode('excerpt'),
    createNode('date'),
    { type: 'date', format: 'd.m.Y', relative: true },
    createNode('author'),
    { type: 'author', link: true },
    createNode('taxonomy'),
    { type: 'taxonomy', taxonomy: 'post_tag', links: false, separator: ' | ' },
    createNode('meta'),
    { type: 'meta', key: 'price', label: 'Price:', fallback: 'on request' },
    createNode('readmore'),
    createNode('text'),
    inner,
  ];
  return model;
}

describe('builder output', () => {
  it('compiles with the template engine for every element type', () => {
    for (const model of [defaultModel(), everything(), emptyModel()]) {
      const { html, css } = generate(model);
      const result = compileTemplate(html, css, context, 'builder');
      expect(result.errors, JSON.stringify(result.errors)).toEqual([]);
      expect(result.template).not.toBeNull();
    }
    expect(NODE_TYPES.every((type) => createNode(type).type === type)).toBe(true);
  });

  it('generates nested containers with their own scoped classes', () => {
    const { html, css } = generate(everything());

    expect(html.startsWith('<article class="swp-b swp-b-n1" data-swp-builder="')).toBe(true);
    expect(html).toContain(
      '<div class="swp-b-box swp-b-n2"><h3 class="swp-b-title"><a href="{{entry.url}}">{{entry.title}}</a></h3><div class="swp-b-spacer swp-b-n3" aria-hidden="true"></div></div>',
    );
    expect(css).toContain(
      '.swp-b-n2{display:flex;flex-direction:row;gap:8px;padding:0px;background:#aabbcc;}',
    );
    expect(css).toContain('.swp-b-n3{height:16px}');
    expect(html).toContain('{{entry.image|image:"medium_large"}}');
    expect(html).toContain('{{entry.date|relative_date}}');
    expect(html).toContain('{{entry.terms.post_tag|join:" | "}}');
    expect(html).toContain('{{entry.meta.price|default:"on request"}}');
    expect(html).toContain('<strong>Price:</strong>');
  });

  it('escapes free text so it can never become a placeholder or markup', () => {
    const model = emptyModel();
    model.root.children = [
      { type: 'text', text: '{{entry.title}} <script>alert(1)</script> "q" & more' },
      { type: 'readmore', label: '{{x}} <b>' },
      { type: 'meta', key: 'subtitle', label: '<i>L</i>', fallback: 'say "hi" \\ there' },
    ];
    const { html, css } = generate(model);

    expect(html).not.toContain('<script');
    expect(html).toContain(
      '&#123;&#123;entry.title&#125;&#125; &lt;script&gt;alert(1)&lt;/script&gt; &quot;q&quot; &amp; more',
    );
    expect(html).toContain('<strong>&lt;i&gt;L&lt;/i&gt;</strong>');
    expect(html).toContain('default:"say \\"hi\\" \\\\ there"');
    const result = compileTemplate(html, css, context, 'builder');
    expect(result.errors).toEqual([]);
  });
});

describe('round trip and manual edits', () => {
  it('reads the model back from generated code', () => {
    const model = everything();
    const { html } = generate(model);

    expect(parseModel(html)).toEqual(model);
    expect(parseModel(generate({ ...defaultModel() }).html)).toEqual(defaultModel());
  });

  it('keeps unicode text through the embedded model', () => {
    const model = emptyModel();
    model.root.children = [{ type: 'text', text: 'Grüße – 日本語 🚀' }];

    expect(parseModel(generate(model).html)).toEqual(model);
  });

  it('recognises code that was edited by hand', () => {
    const model = defaultModel();
    const { html, css } = generate(model);

    expect(isGeneratedFrom(html, css, model)).toBe(true);
    expect(isGeneratedFrom(html.replace('swp-b-title', 'swp-b-title extra'), css, model)).toBe(
      false,
    );
    expect(isGeneratedFrom(html, `${css}\n.x{}`, model)).toBe(false);
    expect(parseModel(html.replace('swp-b-title', 'swp-b-title extra'))).toEqual(model);
  });

  it('returns null for hand written, damaged or forged code', () => {
    expect(parseModel('<p>{{entry.title}}</p>')).toBeNull();
    expect(parseModel('<article data-swp-builder="not-base64!">')).toBeNull();
    expect(parseModel('<article data-swp-builder="e30">')).toBeNull();
    expect(parseModel('<article data-swp-builder="">')).toBeNull();
    const forged = btoa(
      JSON.stringify({
        version: 1,
        root: {
          type: 'container',
          direction: 'column',
          gap: 0,
          padding: 0,
          background: 'url(x)',
          children: [],
        },
      }),
    );
    expect(parseModel(`<article data-swp-builder="${forged}">`)).toBeNull();
  });
});

describe('model validation', () => {
  const withChild = (child: unknown): unknown => ({
    version: BUILDER_VERSION,
    root: { ...emptyModel().root, children: [child] },
  });

  it('accepts everything the builder creates', () => {
    expect(isValidModel(everything())).toBe(true);
    expect(isValidModel(defaultModel())).toBe(true);
  });

  it.each([
    ['unknown type', { type: 'script' }],
    ['image size', { type: 'image', size: 'huge', link: true }],
    ['title tag', { type: 'title', tag: 'script', link: true }],
    ['excerpt words', { type: 'excerpt', words: 0 }],
    ['date format with a quote', { type: 'date', format: 'd"x', relative: false }],
    ['taxonomy name', { type: 'taxonomy', taxonomy: 'Bad Name', links: true, separator: ',' }],
    [
      'separator with a brace',
      { type: 'taxonomy', taxonomy: 'category', links: true, separator: '{{' },
    ],
    ['meta key', { type: 'meta', key: '_hidden', label: '', fallback: '' }],
    ['empty read more label', { type: 'readmore', label: '' }],
    ['spacer height', { type: 'spacer', height: 999 }],
    [
      'container direction',
      {
        type: 'container',
        direction: 'diagonal',
        gap: 0,
        padding: 0,
        background: '',
        children: [],
      },
    ],
    [
      'container colour',
      { type: 'container', direction: 'row', gap: 0, padding: 0, background: 'red', children: [] },
    ],
    ['not an object', 'text'],
  ])('rejects %s', (_name, child) => {
    expect(isValidModel(withChild(child))).toBe(false);
  });

  it('bounds depth and size', () => {
    let deep: ContainerNode = emptyModel().root;
    for (let level = 0; level < MAX_DEPTH + 1; level += 1) {
      deep = { ...deep, children: [{ ...emptyModel().root, children: [deep] }] };
    }
    expect(isValidModel({ version: BUILDER_VERSION, root: deep })).toBe(false);

    const wide = emptyModel();
    wide.root.children = Array.from({ length: MAX_NODES }, () => createNode('text'));
    expect(isValidModel(wide)).toBe(false);
    wide.root.children = wide.root.children.slice(0, MAX_NODES - 1);
    expect(isValidModel(wide)).toBe(true);
  });

  it('rejects wrong versions and roots', () => {
    expect(isValidModel({ version: 2, root: emptyModel().root })).toBe(false);
    expect(isValidModel({ version: 1, root: { type: 'text', text: 'x' } })).toBe(false);
    expect(isValidModel(null)).toBe(false);
    expect(isValidModel([])).toBe(false);
  });

  it('clamps numeric output defensively when a model is built in code', () => {
    const model = emptyModel();
    model.root.gap = 900;
    model.root.padding = -4;
    model.root.children = [
      { type: 'spacer', height: 9999 },
      { type: 'excerpt', words: 9999 },
    ];
    const { html, css } = generate(model);

    expect(css).toContain('gap:64px;padding:0px');
    expect(css).toContain('height:200px');
    expect(html).toContain('words:200');
  });
});

describe('contract fixtures shared with PHP (tests/contract/builder)', () => {
  const dir = resolve(import.meta.dirname, '../../../tests/contract/builder');
  const models: Record<string, BuilderModel> = {
    default: defaultModel(),
    everything: everything(),
    empty: emptyModel(),
  };

  it.each(Object.entries(models))('%s model matches its fixture', (name, model) => {
    const file = join(dir, `${name}.json`);
    const fixture = {
      name: `builder-${name}`,
      type: 'builder',
      input: { model },
      expect: generate(model),
    };
    if (process.env.SWP_UPDATE_CONTRACT === '1') {
      writeFileSync(file, `${JSON.stringify(fixture, null, 2)}\n`);
    }
    expect(JSON.parse(readFileSync(file, 'utf8'))).toEqual(fixture);
  });
});

describe('bundled builder preset', () => {
  const file = resolve(import.meta.dirname, '../../../templates/presets/builder-card.json');

  /** A card built with the builder: image, then a nested column with the text elements and a row with author and date. */
  function model(): BuilderModel {
    const built = emptyModel();
    const meta = createNode('container') as ContainerNode;
    meta.direction = 'row';
    meta.gap = 12;
    meta.children = [createNode('author'), createNode('date')];
    const body = createNode('container') as ContainerNode;
    body.padding = 14;
    body.gap = 8;
    body.children = [
      createNode('taxonomy'),
      createNode('title'),
      meta,
      createNode('excerpt'),
      createNode('readmore'),
    ];
    built.root.children = [createNode('image'), body];
    return built;
  }

  it('is exactly what the builder generates for its model', () => {
    const built = model();
    const generated = generate(built);
    const preset = {
      schema_version: 1,
      id: 'builder-card',
      title: 'Builder card',
      description:
        'A card built with the visual builder: image, categories, headline, author and date, excerpt.',
      layout: {
        type: 'grid',
        columns: { desktop: 3, tablet: 2, mobile: 1 },
        gap: { desktop: 24, tablet: 16, mobile: 12 },
        aspect_ratio: '3/2',
      },
      container: {
        html: '<div class="builder-cards">{{view.entries}}</div>\n{{view.navigation}}',
        css: '',
      },
      entry: generated,
      builder: built,
    };
    if (process.env.SWP_UPDATE_CONTRACT === '1')
      writeFileSync(file, `${JSON.stringify(preset, null, 2)}\n`);

    const stored = JSON.parse(readFileSync(file, 'utf8')) as typeof preset;
    expect(stored).toEqual(preset);
    expect(parseModel(stored.entry.html)).toEqual(built);
    expect(isValidModel(stored.builder)).toBe(true);
  });
});
