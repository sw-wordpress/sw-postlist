import { CompletionContext } from '@codemirror/autocomplete';
import { EditorState } from '@codemirror/state';
import { describe, expect, it } from 'vitest';
import { createTemplateCompletion, toDiagnostics } from '@/components/editorCompletion';
import type { CompletionData } from '@/components/editorCompletion';
import { BUILTIN_SIGNATURES } from '@/template-engine';

const data: CompletionData = {
  role: 'entry',
  signatures: BUILTIN_SIGNATURES,
  definitions: [
    { name: 'entry.title', type: 'text', description: 'Title', example: 'Hello', roles: ['entry'] },
    { name: 'entry.date', type: 'date', description: 'Date', example: '', roles: ['entry'] },
    {
      name: 'entry.terms.category',
      type: 'list',
      description: 'Categories',
      example: '',
      roles: ['entry'],
    },
  ],
};

function complete(text: string, explicit = true) {
  const cursor = text.indexOf('§');
  const source = text.replace('§', '');
  const state = EditorState.create({ doc: source });
  const context = new CompletionContext(state, cursor === -1 ? source.length : cursor, explicit);
  return createTemplateCompletion(() => data)(context) as ReturnType<
    ReturnType<typeof createTemplateCompletion>
  >;
}

describe('template completion', () => {
  it('suggests placeholders after {{', () => {
    const result = complete('<p>{{entry.t§}}</p>');
    expect(
      result && 'options' in result ? result.options.map((option) => option.label) : [],
    ).toEqual(['entry.terms.category', 'entry.title']);
  });

  it('suggests only type compatible formatters after a pipe', () => {
    const labels = (text: string): string[] => {
      const result = complete(text);
      return result && 'options' in result ? result.options.map((option) => option.label) : [];
    };

    expect(labels('{{entry.date|§}}')).toEqual(['date', 'relative_date']);
    expect(labels('{{entry.terms.category|§}}')).toEqual(['chips', 'join', 'link']);
    expect(labels('{{entry.title|tr|§}}')).toEqual([]);
    expect(labels('{{entry.title|strip_tags|up§}}')).toEqual(['upper']);
    expect(labels('{{entry.title|nl2br|§}}')).toEqual([]);
  });

  it('stays silent outside tags, in parameters and for unknown placeholders', () => {
    expect(complete('plain text§')).toBeNull();
    expect(complete('{{entry.title|truncate:§')).toBeNull();
    expect(complete('{{entry.unknown|§')).toBeNull();
    expect(complete('{{nothing.matches|§}}')).toBeNull();
    expect(complete('{{entry.title|bogus|§')).toBeNull();
  });
});

describe('diagnostics mapping', () => {
  const issue = (line: number, column: number) => ({
    code: 'unknown_placeholder',
    message: 'Unknown',
    line,
    column,
    context: {},
  });

  it('underlines the tag from the reported position', () => {
    const doc = EditorState.create({ doc: '<p>ok</p>\n  <p>{{entry.nope}}</p>' }).doc;
    const [diagnostic] = toDiagnostics([issue(2, 6)], doc, (entry) => `msg:${entry.code}`);

    expect(doc.sliceString(diagnostic?.from ?? 0, diagnostic?.to ?? 0)).toBe('{{entry.nope}}');
    expect(diagnostic).toMatchObject({ severity: 'error', message: 'msg:unknown_placeholder' });
  });

  it('marks one character when there is no closing tag and skips lines outside the document', () => {
    const doc = EditorState.create({ doc: '<div>' }).doc;
    const diagnostics = toDiagnostics([issue(1, 1), issue(9, 1), issue(0, 1)], doc, () => 'x');

    expect(diagnostics).toHaveLength(1);
    expect(diagnostics[0]).toMatchObject({ from: 0, to: 1 });
  });
});
