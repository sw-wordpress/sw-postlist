import { describe, expect, it } from 'vitest';
import { sampleMarkup } from '@/documents/sample';

describe('sample markup of the preset preview', () => {
  it('replaces placeholders by sample values and images by a placeholder box', () => {
    const html = sampleMarkup(
      '<a href="{{entry.url}}">{{entry.image|image:"medium"}}<h3>{{entry.title}}</h3></a>{{#entry.excerpt}}<p>{{entry.excerpt|words:35}}</p>{{/entry.excerpt}}<small>{{entry.terms.category|join:", "}} {{entry.meta.rank}} {{entry.unknown}} {{{entry.date}}}</small>',
    );
    expect(html).toContain('href="#"');
    expect(html).toContain('swp-sample-image');
    expect(html).toContain('Beispiel-Titel');
    expect(html).toContain('Ein kurzer Auszug');
    expect(html).toContain('Kategorie');
    expect(html).toContain('Wert');
    expect(html).toContain('entry.unknown');
    expect(html).toContain('18. November 2026');
    expect(html).not.toContain('{{');
  });

  it('keeps an image placeholder inside an attribute harmless and escapes unknown names', () => {
    const html = sampleMarkup('<img src="{{entry.image}}" title="{{view.title}}">{{<b>}}');
    expect(html).toContain('src="#"');
    expect(html).toContain('title="Beispielansicht"');
    expect(html).not.toContain('<svg');
  });
});
