import { decode } from './entities';
import { ImageValue, ListItem, TrustedHtml } from './values';
import type { Escaper } from './values';
import type { FormatterSignature, Literal, ParamSpec, ValueType } from './types';

/** Mirrors Formatter/BuiltinFormatters.php (signatures and the formatters that need no host services). */
export const CURRENCIES = [
  'EUR',
  'USD',
  'GBP',
  'CHF',
  'JPY',
  'CAD',
  'AUD',
  'SEK',
  'NOK',
  'DKK',
  'PLN',
  'CZK',
];

/** String parameters are limited to 200 characters unless stated otherwise (ParamSpec.php). */
const param = (
  name: string,
  type: ParamSpec['type'],
  required: boolean,
  extra: Partial<ParamSpec> = {},
): ParamSpec => ({
  name,
  type,
  required,
  ...(type === 'string' ? { maxLength: 200 } : {}),
  ...extra,
});

const signature = (
  inputs: ValueType[],
  output: ValueType,
  params: ParamSpec[] = [],
  help = '',
  acceptsEmpty = false,
): FormatterSignature => ({ inputs, output, params, help, acceptsEmpty });

export const BUILTIN_SIGNATURES: Record<string, FormatterSignature> = {
  date: signature(
    ['date'],
    'text',
    [param('format', 'string', false, { maxLength: 50 })],
    'Formats a date (PHP date format, site time zone).',
  ),
  relative_date: signature(['date'], 'text', [], 'Distance to now, for example "3 days ago".'),
  number: signature(
    ['number'],
    'text',
    [param('decimals', 'int', false, { min: 0, max: 10 })],
    'Formats a number with the locale of the site.',
  ),
  currency: signature(
    ['number'],
    'text',
    [param('currency', 'string', true, { allowed: CURRENCIES })],
    'Formats an amount with a currency code.',
  ),
  truncate: signature(
    ['text'],
    'text',
    [param('length', 'int', true, { min: 1, max: 10000 })],
    'Shortens text to a number of characters.',
  ),
  words: signature(
    ['text'],
    'text',
    [param('count', 'int', true, { min: 1, max: 1000 })],
    'Shortens text to a number of words.',
  ),
  strip_tags: signature(['text'], 'text', [], 'Removes HTML tags from text.'),
  nl2br: signature(['text'], 'html', [], 'Turns line breaks into <br> (text is escaped).'),
  upper: signature(['text'], 'text', [], 'Upper case.'),
  lower: signature(['text'], 'text', [], 'Lower case.'),
  capitalize: signature(['text'], 'text', [], 'Upper case first letter of every word.'),
  default: signature(
    ['text', 'number'],
    'text',
    [param('fallback', 'string', true)],
    'Fallback text for an empty value.',
    true,
  ),
  join: signature(
    ['list'],
    'text',
    [param('separator', 'string', false, { maxLength: 20 })],
    'Joins the labels of a list.',
  ),
  link: signature(
    ['list'],
    'html',
    [param('separator', 'string', false, { maxLength: 20 })],
    'Renders the items of a list as links.',
  ),
  initials: signature(
    ['text'],
    'text',
    [param('count', 'int', false, { min: 1, max: 3 })],
    'Upper case initials of the first words, for example "Anna Keller" becomes "AK".',
  ),
  chips: signature(
    ['list'],
    'html',
    [param('class', 'string', false, { maxLength: 40 })],
    'Renders every label of a list as its own <span class="swp-chip"> (optional extra class, letters, digits, - and _).',
  ),
  esc_url: signature(['url', 'text'], 'url', [], 'Normalizes a URL; unsafe values become empty.'),
};

/** The WordPress `image` formatter exists on the server only; the editor learns its signature from there. */
export const IMAGE_SIGNATURE: FormatterSignature = signature(
  ['image'],
  'html',
  [param('size', 'string', false, {})],
  'Renders an image with the given WordPress size (srcset and sizes included).',
);

export type Implementation = (value: unknown, params: Literal[], escaper: Escaper) => unknown;

const text = (value: unknown): string => (typeof value === 'string' ? value : '');
const labels = (value: unknown): string[] =>
  Array.isArray(value)
    ? value.filter((item): item is ListItem => item instanceof ListItem).map((item) => item.label)
    : [];

/** Implementations of the formatters that depend on nothing but the value (used by the contract tests). */
export const IMPLEMENTATIONS: Record<string, Implementation> = {
  truncate: (value, params) => {
    const chars = [...text(value)];
    const length = Number(params[0] ?? 0);
    return chars.length <= length ? text(value) : `${chars.slice(0, length).join('').trimEnd()}…`;
  },
  words: (value, params) => {
    const words = text(value)
      .trim()
      .split(/\s+/u)
      .filter((word) => word !== '');
    const limit = Number(params[0] ?? 0);
    return words.length <= limit ? words.join(' ') : `${words.slice(0, limit).join(' ')}…`;
  },
  strip_tags: (value) => decode(text(value).replace(/<\/?[a-zA-Z!][^>]*>/g, '')),
  nl2br: (value, _params, escaper) =>
    new TrustedHtml(escaper.text(text(value).replace(/\r\n?/g, '\n')).replace(/\n/g, '<br>\n')),
  upper: (value) => text(value).toUpperCase(),
  lower: (value) => text(value).toLowerCase(),
  capitalize: (value) =>
    text(value).replace(/(?<![\p{L}\p{N}])\p{L}/gu, (letter) => letter.toUpperCase()),
  default: (value, params) => {
    if (typeof value === 'number') return String(value);
    return typeof value === 'string' && value.trim() !== '' ? value : String(params[0] ?? '');
  },
  join: (value, params) => labels(value).join(params[0] === undefined ? ', ' : String(params[0])),
  link: (value, params, escaper) => {
    const parts: string[] = [];
    for (const item of Array.isArray(value) ? value : []) {
      if (!(item instanceof ListItem)) continue;
      const url = item.url === null ? null : escaper.url(item.url);
      const label = escaper.text(item.label);
      parts.push(
        url === null || url === '' ? label : `<a href="${escaper.attribute(url)}">${label}</a>`,
      );
    }
    return new TrustedHtml(
      parts.join(escaper.text(params[0] === undefined ? ', ' : String(params[0]))),
    );
  },
  initials: (value, params) =>
    text(value)
      .trim()
      .split(/[\s-]+/u)
      .filter((word) => word !== '')
      .slice(0, params[0] === undefined ? 2 : Number(params[0]))
      .map((word) => Array.from(word)[0]?.toUpperCase() ?? '')
      .join(''),
  chips: (value, params, escaper) => {
    const extra =
      params[0] === undefined
        ? ''
        : String(params[0])
            .replace(/[^A-Za-z0-9_ -]/g, '')
            .trim();
    const cls = extra === '' ? 'swp-chip' : `swp-chip ${extra}`;
    return new TrustedHtml(
      labels(value)
        .map((label) => `<span class="${escaper.attribute(cls)}">${escaper.text(label)}</span>`)
        .join(''),
    );
  },
  esc_url: (value, _params, escaper) => (typeof value === 'string' ? escaper.url(value) : null),
};

export { ImageValue };
