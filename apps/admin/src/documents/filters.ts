import { ORDERBY } from './types';
import type { OptionSchema } from './layouts';

/** Mirrors Application\\Filter\\BuiltinFilterTypes in PHP (option schemas of the visitor filter types of 1.0). */

export const MAX_FILTERS = 6;
export const TAXONOMY_DISPLAYS = ['buttons', 'dropdown', 'checkboxes'] as const;
export const AUTHOR_DISPLAYS = ['dropdown', 'buttons'] as const;

const LABEL: OptionSchema = { type: 'string', pattern: /^[^<>\p{Cc}]{1,60}$/u, nullable: true };

export interface FilterTypeDefinition {
  id: string;
  label: string;
  /** Null for third-party filter types: their options are validated by the server only. */
  schema: Record<string, OptionSchema> | null;
}

export const BUILTIN_FILTER_TYPES: readonly FilterTypeDefinition[] = [
  {
    id: 'taxonomy',
    label: 'Taxonomy',
    schema: {
      taxonomy: { type: 'string', pattern: /^[a-z0-9_-]{1,32}$/, nullable: false },
      display: { type: 'enum', values: TAXONOMY_DISPLAYS },
      terms: { type: 'id_list' },
      label: LABEL,
    },
  },
  {
    id: 'author',
    label: 'Author',
    schema: { display: { type: 'enum', values: AUTHOR_DISPLAYS }, label: LABEL },
  },
  { id: 'search', label: 'Search', schema: { label: LABEL, placeholder: LABEL } },
  {
    id: 'sort',
    label: 'Sorting',
    schema: { options: { type: 'enum_list', values: ORDERBY }, label: LABEL },
  },
];

/** URL parameter of a filter configuration, as in VisitorInputParser::param_for(). */
export function filterParam(config: Record<string, unknown>): string {
  const type = typeof config.type === 'string' ? config.type : '';
  if (type === 'taxonomy')
    return `tax_${typeof config.taxonomy === 'string' ? config.taxonomy : ''}`;
  if (type === 'author') return 'author';
  if (type === 'search') return 'q';
  if (type === 'sort') return 'sort';
  return type;
}
