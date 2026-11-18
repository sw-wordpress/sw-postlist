import type { FormatterCall, Literal } from './types';

export const NAME_PATTERN = /^[a-z][a-z0-9_]*(\.[A-Za-z0-9_-]+)+$/;

export type Parsed = { name: string; chain: FormatterCall[] } | { code: string; error: string };

/** Splits on a separator outside of double quoted strings; null when a string is not closed. */
function split(text: string, separator: string): string[] | null {
  const parts: string[] = [];
  let current = '';
  let quoted = false;
  for (let index = 0; index < text.length; index += 1) {
    const char = text[index] as string;
    if (quoted && char === '\\' && index + 1 < text.length) {
      current += char + (text[index + 1] as string);
      index += 1;
      continue;
    }
    if (char === '"') quoted = !quoted;
    else if (!quoted && char === separator) {
      parts.push(current);
      current = '';
      continue;
    }
    current += char;
  }
  if (quoted) return null;
  parts.push(current);
  return parts;
}

function literal(text: string): [Literal] | null {
  if (text === 'true' || text === 'false') return [text === 'true'];
  if (/^-?\d{1,9}$/.test(text)) return [parseInt(text, 10)];
  if (text.length >= 2 && text.startsWith('"') && text.endsWith('"')) {
    const inner = text.slice(1, -1);
    if (/(?<!\\)"/.test(inner)) return null;
    return [inner.replace(/\\(["\\])/g, '$1')];
  }
  return null;
}

function formatter(
  part: string,
  offset: number,
): { call: FormatterCall } | { code: string; error: string } {
  const pieces = split(part, ':');
  if (pieces === null) {
    return { code: 'invalid_formatter_syntax', error: 'Unclosed string in a formatter parameter.' };
  }
  const name = (pieces.shift() ?? '').trim();
  if (!/^[a-z][a-z0-9_]*$/.test(name)) {
    return {
      code: 'invalid_formatter_syntax',
      error: `Invalid formatter name "${name.slice(0, 40)}".`,
    };
  }
  const params: Literal[] = [];
  for (const piece of pieces) {
    const parsed = literal(piece.trim());
    if (parsed === null) {
      return {
        code: 'invalid_parameter',
        error: 'Formatter parameters must be string, number or true/false literals.',
      };
    }
    params.push(parsed[0]);
  }
  return { call: { name, params, offset } };
}

/** Parses `entry.title|truncate:60|upper` into a name and a formatter chain. */
export function parseExpression(expression: string, offset: number): Parsed {
  const parts = split(expression, '|');
  if (parts === null) {
    return { code: 'invalid_formatter_syntax', error: 'Unclosed string in the placeholder.' };
  }
  const name = (parts.shift() ?? '').trim();
  if (!NAME_PATTERN.test(name)) {
    return {
      code: 'invalid_placeholder',
      error: `Invalid placeholder name "${name.slice(0, 60)}".`,
    };
  }
  const chain: FormatterCall[] = [];
  for (const part of parts) {
    const call = formatter(part, offset);
    if ('error' in call) return call;
    chain.push(call.call);
  }
  return { name, chain };
}
