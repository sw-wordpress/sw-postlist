import { describe, expect, it } from 'vitest';
import {
  BUILTIN_SIGNATURES,
  chainType,
  completionKind,
  compileTemplate,
  formatterSuggestions,
  placeholderSuggestions,
  postlistProfile,
  tagAtCursor,
} from '@/template-engine';
import type { PlaceholderDefinition } from '@/template-engine';

const definitions: PlaceholderDefinition[] = [
  { name: 'entry.title', type: 'text', description: 'Title', example: 'Hello', roles: ['entry'] },
  {
    name: 'entry.terms.category',
    type: 'list',
    description: 'Categories',
    example: 'News',
    roles: ['entry'],
  },
  {
    name: 'entry.date',
    type: 'date',
    description: 'Date',
    example: '2026-11-18',
    roles: ['entry'],
  },
  { name: 'view.entries', type: 'html', description: 'Entries', example: '', roles: ['container'] },
  { name: 'view.title', type: 'text', description: 'View title', example: '', roles: [] },
];

describe('placeholder suggestions', () => {
  it('filters by prefix and role and sorts by name', () => {
    expect(
      placeholderSuggestions('entry.', definitions, 'entry').map((entry) => entry.label),
    ).toEqual(['entry.date', 'entry.terms.category', 'entry.title']);
    expect(
      placeholderSuggestions('', definitions, 'container').map((entry) => entry.label),
    ).toEqual(['view.entries', 'view.title']);
    expect(
      placeholderSuggestions('VIEW.t', definitions, 'entry').map((entry) => entry.label),
    ).toEqual(['view.title']);
    const first = placeholderSuggestions('entry.title', definitions, 'entry')[0];
    expect(first).toEqual({
      label: 'entry.title',
      type: 'text',
      detail: 'Title',
      example: 'Hello',
    });
  });
});

describe('formatter suggestions', () => {
  it('offers only formatters that accept the current type', () => {
    const names = (type: Parameters<typeof formatterSuggestions>[0], prefix = ''): string[] =>
      formatterSuggestions(type, BUILTIN_SIGNATURES, prefix).map((entry) => entry.name);

    expect(names('date')).toEqual(['date', 'relative_date']);
    expect(names('list')).toEqual(['chips', 'join', 'link']);
    expect(names('number')).toEqual(['currency', 'default', 'number']);
    expect(names('text', 'tr')).toEqual(['truncate']);
    expect(names('html')).toEqual([]);
    expect(names('flag')).toEqual([]);
  });

  it('describes parameters in the call syntax', () => {
    const entries = formatterSuggestions('text', BUILTIN_SIGNATURES, 'trunc');
    expect(entries[0]).toMatchObject({ signature: 'truncate:length', output: 'text' });
    expect(formatterSuggestions('date', BUILTIN_SIGNATURES, 'date')[0]?.signature).toBe(
      'date:format?',
    );
  });

  it('follows the type through a chain', () => {
    expect(chainType('text', ['strip_tags', 'words', 'upper'], BUILTIN_SIGNATURES)).toBe('text');
    expect(chainType('text', ['nl2br'], BUILTIN_SIGNATURES)).toBe('html');
    expect(chainType('text', ['nl2br', 'upper'], BUILTIN_SIGNATURES)).toBeNull();
    expect(chainType('text', ['nope'], BUILTIN_SIGNATURES)).toBeNull();
    expect(chainType('number', [], BUILTIN_SIGNATURES)).toBe('number');
  });
});

describe('cursor context', () => {
  const source = 'a {{entry.title|upper}} b {{#entry.t';

  it('finds the placeholder tag around the cursor', () => {
    expect(tagAtCursor(source, source.indexOf('|upper'))).toEqual({
      expression: 'entry.title',
      start: 4,
    });
    expect(tagAtCursor(source, source.length)).toEqual({ expression: 'entry.t', start: 28 });
    expect(tagAtCursor(source, source.indexOf(' b '))).toBeNull();
    expect(tagAtCursor('no tag', 3)).toBeNull();
  });

  it('classifies what is being completed', () => {
    expect(completionKind('entry.ti')).toEqual({ kind: 'placeholder', prefix: 'entry.ti' });
    expect(completionKind('entry.title|')).toEqual({
      kind: 'formatter',
      chain: [],
      name: '',
      placeholder: 'entry.title',
    });
    expect(completionKind('entry.title|truncate:')).toBeNull();
    expect(completionKind('entry.title | strip_tags | tr')).toEqual({
      kind: 'formatter',
      chain: ['strip_tags'],
      name: 'tr',
      placeholder: 'entry.title',
    });
    expect(completionKind('entry.title|truncate:6')).toBeNull();
    expect(completionKind('not a name|x')).toBeNull();
  });
});

describe('live validation inputs', () => {
  const context = {
    profile: postlistProfile(),
    find: (name: string) => definitions.find((entry) => entry.name === name) ?? null,
    formatters: BUILTIN_SIGNATURES,
    role: 'entry',
  };

  it('reports positions of errors', () => {
    const result = compileTemplate('<p>\n  {{entry.nope}}</p>', '', context, 1);
    expect(result.errors).toHaveLength(1);
    expect(result.errors[0]).toMatchObject({ code: 'unknown_placeholder', line: 2, column: 3 });
  });

  it('rejects oversized templates', () => {
    expect(compileTemplate('a'.repeat(200000), '', context, 1).errors[0]?.code).toBe('too_long');
  });

  it('flags css problems with a reason', () => {
    const result = compileTemplate('<p>x</p>', '.a{background:url(x)}', context, 1);
    expect(result.errors[0]).toMatchObject({
      code: 'invalid_css',
      context: { reason: 'forbidden: url()' },
    });
  });
});
