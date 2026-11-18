import type { EngineProfile } from './types';

export const ENGINE_VERSION = '1';
export const MAX_TEMPLATE_BYTES = 102400;
export const MAX_CSS_BYTES = 51200;
export const MAX_DEPTH = 64;

export const VOID_TAGS = ['br', 'hr', 'img', 'wbr'];
export const URL_ATTRIBUTES = ['href', 'src', 'cite'];
export const BOOLEAN_ATTRIBUTES = ['hidden', 'open', 'reversed'];
export const TARGETS = ['_blank', '_self', '_parent', '_top'];

const DEFAULT_TAGS = [
  'div',
  'span',
  'p',
  'a',
  'strong',
  'em',
  'b',
  'i',
  'u',
  's',
  'small',
  'br',
  'hr',
  'wbr',
  'h1',
  'h2',
  'h3',
  'h4',
  'h5',
  'h6',
  'ul',
  'ol',
  'li',
  'dl',
  'dt',
  'dd',
  'article',
  'section',
  'header',
  'footer',
  'aside',
  'nav',
  'main',
  'figure',
  'figcaption',
  'img',
  'time',
  'address',
  'blockquote',
  'q',
  'cite',
  'abbr',
  'mark',
  'sup',
  'sub',
  'code',
  'table',
  'thead',
  'tbody',
  'tfoot',
  'tr',
  'th',
  'td',
  'caption',
  'details',
  'summary',
];

const DEFAULT_GLOBAL_ATTRIBUTES = [
  'class',
  'id',
  'title',
  'lang',
  'dir',
  'role',
  'hidden',
  'tabindex',
  'style',
];

const DEFAULT_TAG_ATTRIBUTES: Record<string, string[]> = {
  a: ['href', 'target', 'rel', 'hreflang'],
  img: ['src', 'alt', 'width', 'height', 'loading', 'decoding'],
  time: ['datetime'],
  td: ['colspan', 'rowspan', 'headers'],
  th: ['colspan', 'rowspan', 'headers', 'scope'],
  ol: ['start', 'reversed', 'type'],
  details: ['open'],
  blockquote: ['cite'],
  q: ['cite'],
};

export function postlistProfile(): EngineProfile {
  return {
    tags: DEFAULT_TAGS,
    globalAttributes: DEFAULT_GLOBAL_ATTRIBUTES,
    tagAttributes: DEFAULT_TAG_ATTRIBUTES,
    classPrefix: 'swp-tpl-',
    formatters: true,
  };
}

/** CloudCalendar compatible profile: formatters off, calendar class prefix. */
export function cloudcalendarProfile(): EngineProfile {
  return { ...postlistProfile(), classPrefix: 'swc-tpl-', formatters: false };
}

export function scopeClass(profile: EngineProfile, templateId: number | string): string {
  return `${profile.classPrefix}${templateId}`;
}

export function allowsAttribute(profile: EngineProfile, tag: string, attribute: string): boolean {
  if (
    profile.globalAttributes.includes(attribute) ||
    (profile.tagAttributes[tag] ?? []).includes(attribute)
  ) {
    return true;
  }
  return /^(aria-[a-z0-9_-]+|data-[a-z0-9_.:-]+)$/.test(attribute);
}
