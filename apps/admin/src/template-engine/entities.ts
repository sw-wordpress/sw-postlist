/** Same entity set and escaping as Syntax/Entities.php. */
export const NAMED: Record<string, string> = {
  amp: '&',
  lt: '<',
  gt: '>',
  quot: '"',
  apos: "'",
  nbsp: ' ',
  copy: '©',
  reg: '®',
  euro: '€',
  hellip: '…',
  mdash: '—',
  ndash: '–',
  laquo: '«',
  raquo: '»',
  bull: '•',
  middot: '·',
  trade: '™',
  auml: 'ä',
  ouml: 'ö',
  uuml: 'ü',
  Auml: 'Ä',
  Ouml: 'Ö',
  Uuml: 'Ü',
  szlig: 'ß',
};

export function decode(text: string): string {
  return text.replace(
    /&(#[xX][0-9a-fA-F]{1,6}|#\d{1,7}|[A-Za-z][A-Za-z0-9]{1,7});/g,
    (match: string, body: string) => {
      if (!body.startsWith('#')) return NAMED[body] ?? match;
      const code =
        (body[1] ?? '').toLowerCase() === 'x'
          ? parseInt(body.slice(2), 16)
          : parseInt(body.slice(1), 10);
      return code >= 32 && code <= 0x10ffff && (code < 0xd800 || code > 0xdfff)
        ? String.fromCodePoint(code)
        : match;
    },
  );
}

const MARKUP: Record<string, string> = {
  '&': '&amp;',
  '<': '&lt;',
  '>': '&gt;',
  '"': '&quot;',
  "'": '&apos;',
};

/** Static text as it appears in the compiled HTML. */
export function normalize(text: string): string {
  return decode(text).replace(/[&<>"']/g, (char) => MARKUP[char] ?? char);
}
