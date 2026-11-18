import { NAME_PATTERN } from './expression';
import type { FormatterSignature, PlaceholderDefinition, ValueType } from './types';

/**
 * Editor helpers: placeholder and formatter suggestions without any editor dependency. M4 connects them to the
 * CodeMirror completion source (placeholderCompletion.ts).
 *
 * @since 0.1.0
 */

export interface PlaceholderSuggestion {
  label: string;
  type: ValueType;
  detail: string;
  example: string;
}

export interface FormatterSuggestion {
  name: string;
  help: string;
  /** Call syntax with parameter names, for example `truncate:length`. */
  signature: string;
  output: ValueType;
}

/** Placeholders of a role whose name starts with the typed prefix, sorted by name. */
export function placeholderSuggestions(
  prefix: string,
  definitions: PlaceholderDefinition[],
  role: string,
): PlaceholderSuggestion[] {
  const needle = prefix.trim().toLowerCase();
  return definitions
    .filter(
      (entry) =>
        (entry.roles.length === 0 || entry.roles.includes(role)) &&
        entry.name.toLowerCase().startsWith(needle),
    )
    .sort((a, b) => a.name.localeCompare(b.name))
    .map((entry) => ({
      label: entry.name,
      type: entry.type,
      detail: entry.description,
      example: entry.example,
    }));
}

/** Formatters that can be applied to a value of the given type (after the first `|`: the chain's current type). */
export function formatterSuggestions(
  inputType: ValueType,
  signatures: Record<string, FormatterSignature>,
  prefix = '',
): FormatterSuggestion[] {
  return Object.entries(signatures)
    .filter(([name, signature]) => name.startsWith(prefix) && signature.inputs.includes(inputType))
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([name, signature]) => ({
      name,
      help: signature.help,
      signature: [
        name,
        ...signature.params.map((param) => (param.required ? param.name : `${param.name}?`)),
      ].join(':'),
      output: signature.output,
    }));
}

/** Type of the value after applying a chain of formatter names; null when a formatter is unknown or incompatible. */
export function chainType(
  start: ValueType,
  names: string[],
  signatures: Record<string, FormatterSignature>,
): ValueType | null {
  let type: ValueType = start;
  for (const name of names) {
    const signature = signatures[name];
    if (signature === undefined || !signature.inputs.includes(type)) return null;
    type = signature.output;
  }
  return type;
}

/** Text of the placeholder tag that surrounds the cursor, for context aware completion. */
export function tagAtCursor(
  source: string,
  cursor: number,
): { expression: string; start: number } | null {
  const open = source.lastIndexOf('{{', cursor);
  if (open === -1) return null;
  const close = source.indexOf('}}', open);
  if (close !== -1 && close < cursor) return null;
  const inner = source.slice(open + 2, cursor).replace(/^[\s#^/]+/, '');
  return { expression: inner, start: open + 2 };
}

/** What the cursor is completing inside a placeholder tag: a name, a formatter or nothing. */
export function completionKind(
  expression: string,
):
  | { kind: 'placeholder'; prefix: string }
  | { kind: 'formatter'; chain: string[]; name: string; placeholder: string }
  | null {
  const pieces = expression.split('|');
  if (pieces.length === 1) {
    return { kind: 'placeholder', prefix: (pieces[0] ?? '').trim() };
  }
  const placeholder = (pieces[0] ?? '').trim();
  if (!NAME_PATTERN.test(placeholder)) return null;
  const last = pieces[pieces.length - 1] ?? '';
  if (last.includes(':')) return null;
  const chain = pieces
    .slice(1, -1)
    .map((piece) => (piece.split(':')[0] ?? '').trim())
    .filter((name) => name !== '');
  return { kind: 'formatter', chain, name: last.trim(), placeholder };
}
