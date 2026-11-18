import { TEMPLATE_SCHEMA_VERSION, VIEW_SCHEMA_VERSION } from './types';
import type { TemplateDocument, TemplateRole, ViewDocument } from './types';

/** A new view: one source, ten entries per page, grid layout, classic pagination. */
export function emptyView(source: string): ViewDocument {
  return {
    schema_version: VIEW_SCHEMA_VERSION,
    title: '',
    source,
    query: {
      taxonomies: [],
      authors: [],
      date: { after: null, before: null },
      meta: [],
      include: [],
      exclude: [],
      per_page: 10,
      offset: 0,
      orderby: 'date',
      order: 'desc',
      order_meta_key: null,
      context: 'none',
    },
    layout: { type: 'grid' },
    pagination: { mode: 'classic' },
    templates: { container: null, entry: null },
  };
}

const STARTERS: Record<TemplateRole, string> = {
  entry:
    '<article class="card">\n  <h3><a href="{{entry.url}}">{{entry.title}}</a></h3>\n  <time datetime=\'{{entry.date|date:"c"}}\'>{{entry.date|date}}</time>\n  {{#entry.excerpt}}<p>{{entry.excerpt|words:30}}</p>{{/entry.excerpt}}\n</article>',
  container:
    '<div class="list">\n  <h2>{{view.title}}</h2>\n  {{view.entries}}\n  {{view.navigation}}\n</div>',
};

export function emptyTemplate(role: TemplateRole): TemplateDocument {
  return {
    schema_version: TEMPLATE_SCHEMA_VERSION,
    title: '',
    role,
    html: STARTERS[role],
    css: '',
  };
}

/** Parses "1, 2 ,3" into positive unique integers; anything else is dropped. */
export function parseIds(text: string): number[] {
  const ids = new Set<number>();
  for (const part of text.split(',')) {
    const value = Number(part.trim());
    if (Number.isInteger(value) && value > 0) ids.add(value);
  }
  return [...ids];
}
