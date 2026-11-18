import { issue } from './position';
import type { TemplateIssue } from './types';

export type Token =
  | { kind: 'text'; offset: number; value: string }
  | { kind: 'mustache'; offset: number; value: string; sigil: '' | '#' | '^' | '/' }
  | {
      kind: 'tag_open';
      offset: number;
      value: string;
      attributes: RawAttribute[];
      selfClosing: boolean;
    }
  | { kind: 'tag_close'; offset: number; value: string };

export interface RawAttribute {
  name: string;
  value: Array<Extract<Token, { kind: 'text' | 'mustache' }>> | null;
  offset: number;
}

export interface LexResult {
  tokens: Token[];
  errors: TemplateIssue[];
  source: string;
}

type ValueToken = Extract<Token, { kind: 'text' | 'mustache' }>;

/** Mirrors Syntax/Lexer.php. */
export function tokenize(template: string): LexResult {
  const source = template.replace(/\r\n?/g, '\n');
  const errors: TemplateIssue[] = [];
  const tokens: Token[] = [];
  const length = source.length;
  let pos = 0;

  const fail = (
    code: string,
    message: string,
    offset: number,
    context: Record<string, unknown> = {},
  ): void => {
    errors.push(issue(source, code, message, offset, context));
  };
  const skipWhitespace = (): void => {
    while (pos < length && /\s/.test(source[pos] as string)) pos += 1;
  };

  const mustacheToken = (innerRaw: string, offset: number): ValueToken | null => {
    let inner = innerRaw.trim();
    if (inner !== '' && '{!>&=<$'.includes(inner[0] as string)) {
      fail('unsupported_syntax', `Unsupported template syntax "{{${inner[0]}".`, offset);
      return null;
    }
    let sigil: '' | '#' | '^' | '/' = '';
    if (inner !== '' && '#^/'.includes(inner[0] as string)) {
      sigil = inner[0] as '#' | '^' | '/';
      inner = inner.slice(1).trim();
    }
    if (inner === '') {
      fail('invalid_placeholder', 'Empty placeholder.', offset);
      return null;
    }
    return { kind: 'mustache', offset, value: inner, sigil };
  };

  const splitValue = (raw: string, base: number): ValueToken[] => {
    const out: ValueToken[] = [];
    let cursor = 0;
    while (cursor < raw.length) {
      const open = raw.indexOf('{{', cursor);
      if (open === -1) {
        out.push({ kind: 'text', offset: base + cursor, value: raw.slice(cursor) });
        break;
      }
      if (open > cursor)
        out.push({ kind: 'text', offset: base + cursor, value: raw.slice(cursor, open) });
      const close = raw.indexOf('}}', open + 2);
      if (close === -1) {
        fail('unclosed_placeholder', 'Placeholder is not closed with "}}".', base + open);
        break;
      }
      const token = mustacheToken(raw.slice(open + 2, close), base + open);
      if (token !== null) out.push(token);
      cursor = close + 2;
    }
    return out;
  };

  const attributeValue = (tag: string): ValueToken[] | null => {
    const quote = source[pos] ?? '';
    if (quote === '"' || quote === "'") {
      const end = source.indexOf(quote, pos + 1);
      if (end === -1) {
        fail('malformed_tag', `Unclosed attribute value in <${tag}>.`, pos);
        pos = length;
        return null;
      }
      const rawStart = pos + 1;
      const raw = source.slice(rawStart, end);
      pos = end + 1;
      return splitValue(raw, rawStart);
    }
    const match = /^[^\s"'=<>`]+/.exec(source.slice(pos));
    if (match === null) {
      fail('malformed_tag', `Missing attribute value in <${tag}>.`, pos);
      return null;
    }
    const rawStart = pos;
    pos += match[0].length;
    if (match[0].includes('{{')) {
      fail('unquoted_placeholder', 'Attribute values with placeholders must be quoted.', rawStart);
      return [];
    }
    return splitValue(match[0], rawStart);
  };

  const startTag = (): Token | null => {
    const start = pos;
    const open = /^<([a-zA-Z][a-zA-Z0-9-]*)/.exec(source.slice(pos));
    if (open === null) {
      pos += 1;
      return { kind: 'text', offset: start, value: '<' };
    }
    const name = (open[1] as string).toLowerCase();
    pos = start + open[0].length;
    const attributes: RawAttribute[] = [];
    const seen = new Set<string>();
    for (;;) {
      skipWhitespace();
      if (pos >= length) {
        fail('malformed_tag', `Tag <${name}> is not closed.`, start);
        return null;
      }
      const char = source[pos] as string;
      if (char === '>') {
        pos += 1;
        return { kind: 'tag_open', offset: start, value: name, attributes, selfClosing: false };
      }
      if (char === '/' && source[pos + 1] === '>') {
        pos += 2;
        return { kind: 'tag_open', offset: start, value: name, attributes, selfClosing: true };
      }
      if (source.startsWith('{{', pos)) {
        fail('placeholder_in_tag', 'Placeholders are only allowed inside attribute values.', pos);
        const end = source.indexOf('}}', pos);
        pos = end === -1 ? length : end + 2;
        continue;
      }
      const attrStart = pos;
      const attr = /^[^\s"'>/=]+/.exec(source.slice(pos));
      if (attr === null) {
        fail('malformed_tag', `Malformed attribute in <${name}>.`, pos);
        const close = source.indexOf('>', pos);
        pos = close === -1 ? length : close + 1;
        return null;
      }
      const attrName = attr[0].toLowerCase();
      pos += attr[0].length;
      skipWhitespace();
      let value: ValueToken[] | null = null;
      if (source[pos] === '=') {
        pos += 1;
        skipWhitespace();
        value = attributeValue(name);
        if (value === null) return null;
      }
      if (seen.has(attrName)) {
        fail('duplicate_attribute', `Duplicate attribute "${attrName}".`, attrStart, {
          attribute: attrName,
        });
        continue;
      }
      seen.add(attrName);
      attributes.push({ name: attrName, value, offset: attrStart });
    }
  };

  const endTag = (): Token | null => {
    const start = pos;
    const match = /^<\/([a-zA-Z][a-zA-Z0-9-]*)\s*>/.exec(source.slice(pos));
    if (match === null) {
      fail('malformed_tag', 'Malformed closing tag.', start);
      const close = source.indexOf('>', start);
      pos = close === -1 ? length : close + 1;
      return null;
    }
    pos = start + match[0].length;
    return { kind: 'tag_close', offset: start, value: (match[1] as string).toLowerCase() };
  };

  const tag = (): Token | null => {
    const start = pos;
    if (source.startsWith('<!--', start)) {
      const end = source.indexOf('-->', start + 4);
      if (end === -1) {
        fail('unclosed_comment', 'HTML comment is not closed.', start);
        pos = length;
        return null;
      }
      pos = end + 3;
      return null;
    }
    const next = source[start + 1] ?? '';
    if (next === '/') return endTag();
    if (/[a-zA-Z]/.test(next)) return startTag();
    if (next === '!' || next === '?') {
      fail(
        'disallowed_markup',
        'Doctype, CDATA and processing instructions are not allowed.',
        start,
      );
      const close = source.indexOf('>', start);
      pos = close === -1 ? length : close + 1;
      return null;
    }
    pos += 1;
    return { kind: 'text', offset: start, value: '<' };
  };

  while (pos < length) {
    const lt = source.indexOf('<', pos);
    const mu = source.indexOf('{{', pos);
    const next = Math.min(lt === -1 ? length : lt, mu === -1 ? length : mu);
    if (next > pos) {
      tokens.push({ kind: 'text', offset: pos, value: source.slice(pos, next) });
      pos = next;
    }
    if (pos >= length) break;
    if (mu !== -1 && mu === pos) {
      const end = source.indexOf('}}', pos + 2);
      if (end === -1) {
        fail('unclosed_placeholder', 'Placeholder is not closed with "}}".', pos);
        pos = length;
        continue;
      }
      const start = pos;
      pos = end + 2;
      const token = mustacheToken(source.slice(start + 2, end), start);
      if (token !== null) tokens.push(token);
      continue;
    }
    const token = tag();
    if (token !== null) tokens.push(token);
  }
  return { tokens, errors, source };
}
