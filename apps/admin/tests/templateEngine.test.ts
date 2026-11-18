import { readdirSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  BUILTIN_SIGNATURES,
  ImageValue,
  ListItem,
  TrustedHtml,
  basicEscaper,
  cloudcalendarProfile,
  compileTemplate,
  isSafeUrl,
  postlistProfile,
  render,
} from '@/template-engine';
import type { CompileContext, PlaceholderDefinition, ValueType } from '@/template-engine';

const root = resolve(import.meta.dirname, '../../../tests/contract');

const def = (name: string, type: ValueType, roles: string[] = []): PlaceholderDefinition => ({
  name,
  type,
  description: '',
  example: '',
  roles,
});

/** The schema shared with ArraySchema::contract() in PHP. */
const CONTRACT_SCHEMA: PlaceholderDefinition[] = [
  def('entry.title', 'text', ['entry']),
  def('entry.excerpt', 'text', ['entry']),
  def('entry.url', 'url', ['entry']),
  def('entry.image', 'image', ['entry']),
  def('entry.price', 'number', ['entry']),
  def('entry.featured', 'flag', ['entry']),
  def('entry.color', 'color', ['entry']),
  def('entry.terms.category', 'list', ['entry']),
  def('entry.meta.subtitle', 'text', ['entry']),
  def('entry.body_html', 'html', ['entry']),
  def('view.title', 'text'),
  def('view.entries', 'html', ['container']),
  def('view.count', 'number', ['container']),
];

function contextFor(profileName: string, role: string): CompileContext {
  return {
    profile: profileName === 'cloudcalendar' ? cloudcalendarProfile() : postlistProfile(),
    find: (name, forRole) =>
      CONTRACT_SCHEMA.find(
        (entry) =>
          entry.name === name && (entry.roles.length === 0 || entry.roles.includes(forRole)),
      ) ?? null,
    formatters: BUILTIN_SIGNATURES,
    role,
  };
}

interface Fixture {
  name: string;
  input: {
    template: string;
    css: string;
    role: string;
    profile: string;
    data: Record<string, unknown>;
  };
  expect: { html?: string; css?: string; errors?: string[] };
}

function typed(value: unknown): unknown {
  if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
    const record = value as Record<string, unknown>;
    if (typeof record.html === 'string') return new TrustedHtml(record.html);
    if (typeof record.url === 'string' && record.label === undefined) {
      return new ImageValue(record.url, typeof record.alt === 'string' ? record.alt : '');
    }
  }
  if (Array.isArray(value)) {
    return value.map((item) => {
      const entry = item as { label: string; url?: string };
      return new ListItem(entry.label, entry.url ?? null);
    });
  }
  return value;
}

const dir = join(root, 'template-engine');
const fixtures = readdirSync(dir)
  .filter((name) => name.endsWith('.json'))
  .map((name) => JSON.parse(readFileSync(join(dir, name), 'utf8')) as Fixture);

describe('template engine contract fixtures (shared with PHP)', () => {
  it('finds the fixtures', () => {
    expect(fixtures.length).toBeGreaterThan(100);
  });

  it.each(fixtures.map((fixture) => [fixture.name, fixture] as const))('%s', (_name, fixture) => {
    const { input, expect: expected } = fixture;
    const result = compileTemplate(
      input.template,
      input.css,
      contextFor(input.profile, input.role),
      7,
    );

    if (expected.errors !== undefined) {
      expect(result.template).toBeNull();
      expect([...new Set(result.errors.map((error) => error.code))].sort()).toEqual(
        expected.errors,
      );
      return;
    }
    expect(result.errors).toEqual([]);
    const template = result.template;
    if (template === null) throw new Error('compilation failed');
    const data = Object.fromEntries(
      Object.entries(input.data).map(([key, value]) => [key, typed(value)]),
    );
    const html = render(template, (name) => data[name] ?? null, basicEscaper(isSafeUrl));
    expect(html).toBe(expected.html);
    if (expected.css !== undefined) expect(template.css).toBe(expected.css);
  });
});

describe('formatter signatures (shared with PHP)', () => {
  it('match tests/contract/formatter-signatures.json', () => {
    const shared = JSON.parse(
      readFileSync(join(root, 'formatter-signatures.json'), 'utf8'),
    ) as Record<string, unknown>;
    const local: Record<string, unknown> = {};
    for (const name of Object.keys(BUILTIN_SIGNATURES).sort()) {
      const signature = BUILTIN_SIGNATURES[name];
      if (signature === undefined) continue;
      local[name] = {
        inputs: signature.inputs,
        output: signature.output,
        params: signature.params.map((spec) => ({
          name: spec.name,
          type: spec.type,
          required: spec.required,
          min: spec.min ?? null,
          max: spec.max ?? null,
          allowed: spec.allowed ?? null,
          max_length: spec.maxLength ?? null,
        })),
        accepts_empty: signature.acceptsEmpty,
      };
    }
    expect(local).toEqual(shared);
  });
});
