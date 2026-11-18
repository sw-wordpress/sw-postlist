import { MAX_CSS_BYTES } from './profile';

/** Mirrors CssScoper.php. */
const FORBIDDEN: Array<[string, string]> = [
  ['url(', 'url()'],
  ['image-set(', 'image-set()'],
  ['expression(', 'expression()'],
  ['javascript:', 'javascript:'],
  ['vbscript:', 'vbscript:'],
  ['behavior', 'behavior'],
  ['-moz-binding', '-moz-binding'],
  ['@import', '@import'],
  ['@font-face', '@font-face'],
  ['@charset', '@charset'],
  ['@namespace', '@namespace'],
  ['<', '<'],
  ['\\', 'escape sequences'],
  ['{{', 'placeholders'],
];
const NESTING = ['@media', '@supports', '@container'];

const stripComments = (css: string): string => css.replace(/\/\*[\s\S]*?(\*\/|$)/g, '');

export function findForbidden(css: string): string | null {
  const lower = stripComments(css).toLowerCase();
  for (const [needle, label] of FORBIDDEN) {
    if (lower.includes(needle)) return label;
  }
  return null;
}

export function validateDeclarations(css: string): string | null {
  const forbidden = findForbidden(css);
  if (forbidden !== null) return forbidden;
  if (css.includes('{') || css.includes('}') || css.includes('@'))
    return 'rules in style attribute';
  return null;
}

function matchingBrace(css: string, open: number): number {
  let depth = 0;
  let quote: string | null = null;
  for (let index = open; index < css.length; index += 1) {
    const char = css[index] as string;
    if (quote !== null) {
      if (char === quote) quote = null;
      continue;
    }
    if (char === '"' || char === "'") quote = char;
    else if (char === '{') depth += 1;
    else if (char === '}') {
      depth -= 1;
      if (depth === 0) return index;
    }
  }
  throw new Error('unbalanced braces');
}

function findOutsideStrings(css: string, needle: string, from: number): number | null {
  let quote: string | null = null;
  for (let index = from; index < css.length; index += 1) {
    const char = css[index] as string;
    if (quote !== null) {
      if (char === quote) quote = null;
      continue;
    }
    if (char === '"' || char === "'") quote = char;
    else if (char === needle) return index;
    else if (char === '}') throw new Error('unbalanced braces');
  }
  return null;
}

function splitSelectors(prelude: string): string[] {
  const parts: string[] = [];
  let depth = 0;
  let current = '';
  for (const char of prelude) {
    if (char === '(' || char === '[') depth += 1;
    else if (char === ')' || char === ']') depth -= 1;
    else if (char === ',' && depth === 0) {
      parts.push(current);
      current = '';
      continue;
    }
    current += char;
  }
  parts.push(current);
  return parts;
}

function scopeSelectors(prelude: string, scope: string): string {
  const selectors: string[] = [];
  for (const raw of splitSelectors(prelude)) {
    const selector = raw.trim();
    if (selector === '') continue;
    const replaced = selector.replace(/^(:root|html|body)\b/i, scope);
    selectors.push(replaced !== selector ? replaced : `${scope} ${selector}`);
  }
  if (selectors.length === 0) throw new Error('empty selector');
  return selectors.join(', ');
}

function scopeBlock(css: string, scope: string, depth: number): string {
  if (depth > 4) throw new Error('nesting too deep');
  let out = '';
  let pos = 0;
  while (pos < css.length) {
    const open = findOutsideStrings(css, '{', pos);
    if (open === null) {
      if (css.slice(pos).trim() !== '') throw new Error('declarations outside of a rule');
      break;
    }
    const prelude = css.slice(pos, open).trim();
    const close = matchingBrace(css, open);
    const body = css.slice(open + 1, close);
    pos = close + 1;
    if (prelude === '') throw new Error('empty selector');
    if (prelude.startsWith('@')) {
      const atName = prelude.replace(/[\s(][\s\S]*$/, '').toLowerCase();
      if (NESTING.includes(atName)) {
        out += `${prelude}{${scopeBlock(body, scope, depth + 1).trim()}}\n`;
        continue;
      }
      if (atName === '@keyframes' || atName === '@-webkit-keyframes') {
        out += `${prelude}{${body}}\n`;
        continue;
      }
      throw new Error(`unsupported at-rule ${atName}`);
    }
    if (body.includes('{')) throw new Error('nested rules');
    out += `${scopeSelectors(prelude, scope)}{${body.trim()}}\n`;
  }
  return out;
}

/** Throws an Error with a safe reason when the CSS is not acceptable. */
export function scopeCss(css: string, scope: string): string {
  if (new TextEncoder().encode(css).length > MAX_CSS_BYTES) throw new Error('stylesheet too long');
  const forbidden = findForbidden(css);
  if (forbidden !== null) throw new Error(`forbidden: ${forbidden}`);
  return scopeBlock(stripComments(css), scope, 0).trim();
}
