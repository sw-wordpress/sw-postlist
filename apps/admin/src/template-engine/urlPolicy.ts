const SCHEMES = ['http', 'https', 'mailto', 'tel'];

/** Mirrors UrlPolicy.php: http, https, mailto, tel and relative references. */
export function isSafeUrl(input: string): boolean {
  const url = input.trim();
  if (
    url === '' ||
    [...url].some(
      (char) =>
        (char.codePointAt(0) ?? 0) <= 32 ||
        (char.codePointAt(0) ?? 0) === 127 ||
        '\\<>"\'`'.includes(char),
    )
  ) {
    return false;
  }
  const scheme = /^([a-zA-Z][a-zA-Z0-9+.-]*):/.exec(url);
  if (scheme) return SCHEMES.includes((scheme[1] ?? '').toLowerCase());
  return !url.startsWith('//');
}
