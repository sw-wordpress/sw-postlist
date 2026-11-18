import { defineStore } from 'pinia';
import { api } from '@/api/endpoints';
import type { SourceInfo, TemplateSummary } from '@/api/types';
import { BUILTIN_LAYOUTS } from '@/documents/layouts';
import type { LayoutDefinition } from '@/documents/layouts';
import { BUILTIN_FILTER_TYPES } from '@/documents/filters';
import type { FilterTypeDefinition } from '@/documents/filters';

/** Lookup data shared by the editors: entry sources and the templates that can be assigned. */
export const useCatalog = defineStore('catalog', {
  state: () => ({
    sources: [] as SourceInfo[],
    templates: [] as TemplateSummary[],
    layouts: [...BUILTIN_LAYOUTS] as LayoutDefinition[],
    filterTypes: [...BUILTIN_FILTER_TYPES] as FilterTypeDefinition[],
    loaded: false,
  }),
  getters: {
    source: (state) => (id: string) => state.sources.find((entry) => entry.id === id) ?? null,
    templatesFor: (state) => (role: 'container' | 'entry') =>
      state.templates.filter((entry) => entry.role === role),
  },
  actions: {
    async load(force = false): Promise<void> {
      if (this.loaded && !force) return;
      const [sources, templates, layouts, filterTypes] = await Promise.all([
        api.system.sources(),
        api.templates.list(),
        // Without the route (older server) the built-in layouts are used.
        api.system.layouts().catch(() => ({ layouts: BUILTIN_LAYOUTS })),
        api.system.filterTypes().catch(() => ({ filter_types: [] })),
      ]);
      this.layouts = layouts.layouts.map(
        (layout) =>
          BUILTIN_LAYOUTS.find((builtin) => builtin.id === layout.id) ?? {
            id: layout.id,
            label: layout.label,
            schema: null,
            module: layout.module,
          },
      );
      this.sources = sources.sources;
      this.filterTypes = [
        ...BUILTIN_FILTER_TYPES,
        ...filterTypes.filter_types
          .filter(
            (type) => !type.builtin && !BUILTIN_FILTER_TYPES.some((known) => known.id === type.id),
          )
          .map((type) => ({ id: type.id, label: type.label, schema: null })),
      ];
      this.templates = templates.templates;
      this.loaded = true;
    },
  },
});
