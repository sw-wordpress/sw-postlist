/**
 * Sample markup for the preset preview: placeholders are replaced by plausible values, images by a neutral
 * placeholder box. The real rendering happens on the server; this only helps judging the layout of a card.
 */
const IMAGE_BOX =
  '<span class="swp-sample-image" role="img" aria-label="Beispielbild"><svg viewBox="0 0 64 48" width="48" height="36" aria-hidden="true"><rect x="0" y="0" width="64" height="48" fill="none"/><circle cx="20" cy="17" r="6" fill="currentColor" opacity=".45"/><path d="M4 42 24 22l12 12 8-8 16 16z" fill="currentColor" opacity=".45"/></svg></span>';

const TEXT: Record<string, string> = {
  'view.title': 'Beispielansicht',
  'view.count': '12',
  'entry.id': '42',
  'entry.title': 'Beispiel-Titel eines Eintrags',
  'entry.excerpt':
    'Ein kurzer Auszug, der zeigt, wie viel Text die Karte aufnimmt und wie er umbricht.',
  'entry.content': 'Der vollständige Inhalt des Eintrags.',
  'entry.date': '18. November 2026',
  'entry.modified': '19. November 2026',
  'entry.url': '#',
  'entry.author.name': 'Alex Autor',
  'entry.author.url': '#',
};

const escape = (text: string): string =>
  text.replace(
    /[&<>"]/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c] ?? c,
  );

function value(expression: string, inAttribute: boolean): string {
  const name = (expression.split('|')[0] ?? '').trim();
  if (name === 'entry.image') return inAttribute ? '#' : IMAGE_BOX;
  if (name.startsWith('entry.terms.')) return 'Kategorie';
  if (name.startsWith('entry.meta.')) return 'Wert';
  return escape(TEXT[name] ?? name);
}

export function sampleMarkup(html: string): string {
  // Sections and their end tags are dropped: the preview shows the content of every section.
  const withoutSections = html.replace(/\{\{[#^/][^}]*\}\}/g, '');
  const attributes = withoutSections.replace(
    /(\s[a-z-]+=)(["'])([^"']*?)\2/gi,
    (match: string, prefix: string, quote: string, content: string) =>
      content.includes('{{')
        ? `${prefix}${quote}${content.replace(/\{\{\s*([^}]*?)\s*\}\}/g, (_m, e: string) => value(e, true))}${quote}`
        : match,
  );
  return attributes.replace(/\{\{\{?\s*([^}]*?)\s*\}?\}\}/g, (_m, e: string) => value(e, false));
}
