import type {
  Completion,
  CompletionContext,
  CompletionResult,
  CompletionSource,
} from '@codemirror/autocomplete';
import type { Diagnostic } from '@codemirror/lint';
import type { Text } from '@codemirror/state';
import {
  chainType,
  completionKind,
  formatterSuggestions,
  placeholderSuggestions,
  tagAtCursor,
} from '@/template-engine';
import type { FormatterSignature, PlaceholderDefinition, TemplateIssue } from '@/template-engine';

export interface CompletionData {
  definitions: PlaceholderDefinition[];
  signatures: Record<string, FormatterSignature>;
  role: string;
}

/**
 * CodeMirror completion for template tags: placeholder names after `{{` and, after `|`, only formatters that accept
 * the type the chain has reached (typed chain, with parameter help).
 */
export function createTemplateCompletion(data: () => CompletionData): CompletionSource {
  return (context: CompletionContext): CompletionResult | null => {
    const source = context.state.doc.toString();
    const tag = tagAtCursor(source, context.pos);
    if (tag === null) return null;
    const kind = completionKind(tag.expression);
    const current = data();
    if (kind === null) return null;
    if (kind.kind === 'placeholder') {
      const from = context.pos - kind.prefix.length;
      const options: Completion[] = placeholderSuggestions(
        kind.prefix,
        current.definitions,
        current.role,
      ).map((entry) => ({
        label: entry.label,
        type: 'variable',
        detail: entry.type,
        info: entry.example === '' ? entry.detail : `${entry.detail} (${entry.example})`,
      }));
      return options.length === 0 ? null : { from, options, validFor: /^[\w.-]*$/ };
    }
    const definition = current.definitions.find((entry) => entry.name === kind.placeholder);
    if (definition === undefined) return null;
    const type = chainType(definition.type, kind.chain, current.signatures);
    if (type === null) return null;
    const options: Completion[] = formatterSuggestions(type, current.signatures, kind.name).map(
      (entry) => ({
        label: entry.name,
        type: 'function',
        detail: `${entry.signature} → ${entry.output}`,
        info: entry.help,
        apply: entry.name,
      }),
    );
    return options.length === 0
      ? null
      : { from: context.pos - kind.name.length, options, validFor: /^[a-z_]*$/ };
  };
}

/** Maps compiler findings (line and column) to editor diagnostics that underline the tag. */
export function toDiagnostics(
  issues: TemplateIssue[],
  doc: Text,
  message: (issue: TemplateIssue) => string,
): Diagnostic[] {
  const diagnostics: Diagnostic[] = [];
  for (const issue of issues) {
    if (issue.line < 1 || issue.line > doc.lines) continue;
    const line = doc.line(issue.line);
    const from = Math.min(line.to, line.from + Math.max(0, issue.column - 1));
    const closing = line.text.indexOf('}}', from - line.from);
    const to = closing === -1 ? Math.min(line.to, from + 1) : line.from + closing + 2;
    diagnostics.push({ from, to: Math.max(to, from), severity: 'error', message: message(issue) });
  }
  return diagnostics;
}
