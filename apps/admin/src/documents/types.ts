/** Schema version 1 documents (schemas/*.v1.schema.json, PLAN.md §2). */

export const VIEW_SCHEMA_VERSION = 1;
export const TEMPLATE_SCHEMA_VERSION = 1;

export const CONTEXT_MODES = [
  'none',
  'same_category',
  'same_tags',
  'same_author',
  'archive',
  'search',
] as const;
export const ORDERBY = [
  'date',
  'modified',
  'title',
  'menu_order',
  'id',
  'meta_num',
  'meta_text',
] as const;
export const TERM_OPERATORS = ['in', 'not_in', 'and'] as const;
export const META_COMPARES = [
  '=',
  '!=',
  '>',
  '>=',
  '<',
  '<=',
  'LIKE',
  'EXISTS',
  'NOT EXISTS',
] as const;
export const PAGINATION_MODES = ['classic', 'none', 'ajax', 'load_more', 'infinite'] as const;
export const TEMPLATE_ROLES = ['container', 'entry'] as const;

export type ContextMode = (typeof CONTEXT_MODES)[number];
export type OrderBy = (typeof ORDERBY)[number];
export type TermOperator = (typeof TERM_OPERATORS)[number];
export type PaginationMode = (typeof PAGINATION_MODES)[number];
export type TemplateRole = (typeof TEMPLATE_ROLES)[number];

export interface TaxonomyFilter {
  taxonomy: string;
  terms: number[];
  operator: TermOperator;
}

export interface MetaFilter {
  key: string;
  value: string;
  compare: (typeof META_COMPARES)[number];
}

export interface ViewQuery {
  taxonomies: TaxonomyFilter[];
  authors: number[];
  date: { after: string | null; before: string | null };
  meta: MetaFilter[];
  include: number[];
  exclude: number[];
  per_page: number;
  offset: number;
  orderby: OrderBy;
  order: 'asc' | 'desc';
  order_meta_key: string | null;
  context: ContextMode;
}

export type DeviceValues = Partial<Record<'desktop' | 'tablet' | 'mobile', number>>;

/** Options of the grid and list layouts; other registered layout types bring their own schema (M6). */
export interface ViewLayout {
  type: string;
  columns?: DeviceValues;
  gap?: DeviceValues;
  font_size?: DeviceValues;
  hidden?: Partial<Record<'desktop' | 'tablet' | 'mobile', boolean>>;
  aspect_ratio?: 'auto' | '1/1' | '4/3' | '3/2' | '16/9' | '2/1';
  accent?: string | null;
  [key: string]: unknown;
}

/** A configured visitor filter; the options depend on the filter type (see filters.ts). */
export interface FilterConfig {
  type: string;
  [key: string]: unknown;
}

export interface ViewDocument {
  schema_version: typeof VIEW_SCHEMA_VERSION;
  title: string;
  /** Exactly one entry source ID, for example "post:post". */
  source: string;
  query: ViewQuery;
  layout: ViewLayout;
  pagination: { mode: PaginationMode; [key: string]: unknown };
  templates: { container: number | null; entry: number | null };
  /** Optional; written only when used. */
  filters?: FilterConfig[];
}

export interface TemplateDocument {
  schema_version: typeof TEMPLATE_SCHEMA_VERSION;
  title: string;
  role: TemplateRole;
  html: string;
  css: string;
}

export interface ValidationIssue {
  code: string;
  path: string;
}
