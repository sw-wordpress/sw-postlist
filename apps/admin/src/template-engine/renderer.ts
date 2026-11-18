import { IMPLEMENTATIONS } from './formatters';
import { ImageValue, TrustedHtml } from './values';
import type { Escaper } from './values';
import type { AstChain, AstNode, CompiledTemplate, Literal } from './types';

export type Resolver = (name: string) => unknown;

/** Control characters have no place in HTML output (Renderer::clean in PHP). */
const isControl = (code: number): boolean =>
  code <= 8 || code === 11 || code === 12 || (code >= 14 && code <= 31) || code === 127;
export const clean = (value: string): string =>
  [...value].filter((char) => !isControl(char.codePointAt(0) ?? 0)).join('');

export function truthy(value: unknown): boolean {
  if (value === null || value === undefined || value === false) return false;
  if (typeof value === 'string') return value.trim() !== '';
  if (typeof value === 'number') return value !== 0;
  if (Array.isArray(value)) return value.length > 0;
  if (value instanceof TrustedHtml) return value.html.trim() !== '';
  return true;
}

const scalarText = (value: unknown): string =>
  typeof value === 'string' ? value : typeof value === 'number' ? String(value) : '';

/** Mirrors Renderer.php. Formatters that need host services render empty values here. */
export function render(template: CompiledTemplate, resolve: Resolver, escaper: Escaper): string {
  const formatted = (name: string, chain: AstChain): unknown => {
    let value = resolve(name);
    for (const [formatter, params] of chain) {
      const implementation = IMPLEMENTATIONS[formatter];
      if (implementation === undefined) return null;
      if ((value === null || value === undefined) && formatter !== 'default') return null;
      value = implementation(value, params as Literal[], escaper);
    }
    return value;
  };

  const variable = (name: string, usage: string, chain: AstChain): string => {
    const value = formatted(name, chain);
    if (value === null || value === undefined) return '';
    if (value instanceof TrustedHtml)
      return usage === 'text' ? clean(value.html) : escaper.text(clean(value.html));
    const text = clean(scalarText(value));
    if (usage === 'style') return escaper.color(text) ?? '';
    return usage === 'attr' ? escaper.attribute(text) : escaper.text(text);
  };

  const urlAttribute = (attribute: string, name: string, chain: AstChain): string => {
    let value = formatted(name, chain);
    if (value instanceof ImageValue) value = value.url;
    if (typeof value !== 'string' || value.trim() === '') return '';
    const url = escaper.url(clean(value));
    return url === null || url === '' ? '' : ` ${attribute}="${escaper.attribute(url)}"`;
  };

  const nodes = (list: AstNode[]): string => {
    let out = '';
    for (const node of list) {
      if (node[0] === 't') out += node[1];
      else if (node[0] === 'v') out += variable(node[1], node[2], node[3]);
      else if (node[0] === 'u') out += urlAttribute(node[1], node[2], node[3]);
      else if (truthy(resolve(node[1])) !== node[2]) out += nodes(node[3]);
    }
    return out;
  };
  return nodes(template.ast);
}

const HTML_ESCAPES: Record<string, string> = {
  '&': '&amp;',
  '<': '&lt;',
  '>': '&gt;',
  '"': '&quot;',
  "'": '&#039;',
};
const escapeHtml = (value: string): string =>
  value.replace(/[&<>"']/g, (char) => HTML_ESCAPES[char] ?? char);

/** Escaper equivalent to the PHP test double (htmlspecialchars, engine URL policy); the editor preview uses the server. */
export function basicEscaper(isSafeUrl: (value: string) => boolean): Escaper {
  return {
    text: escapeHtml,
    attribute: escapeHtml,
    url: (value) => (isSafeUrl(value.trim()) ? value.trim() : null),
    color: (value) => (/^#[0-9a-fA-F]{6}$/.test(value) ? value.toLowerCase() : null),
  };
}
