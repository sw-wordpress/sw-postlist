import { request } from './client';
import type { TemplateDocument, ViewDocument } from '@/documents/types';
import type {
  DiagnosticsPayload,
  PackageReport,
  PlaceholderPayload,
  PresetDocument,
  PresetPayload,
  PreviewPayload,
  SavedTemplate,
  SavedView,
  ServerValidation,
  SettingsPayload,
  SourceInfo,
  TemplateSummary,
  TemplateValidation,
  ValueMap,
  ViewSummary,
} from './types';

/** Typed access to the private admin routes (docs/rest-api.md). */
export const api = {
  views: {
    list: () => request<{ views: ViewSummary[] }>('GET', '/admin/views'),
    get: (id: number) => request<SavedView>('GET', `/admin/views/${id}`),
    create: (document: ViewDocument, status: 'publish' | 'draft') =>
      request<SavedView>('POST', '/admin/views', { document, status }),
    update: (id: number, document: ViewDocument, status: 'publish' | 'draft') =>
      request<SavedView>('PUT', `/admin/views/${id}`, { document, status }),
    trash: (id: number) => request<{ id: number }>('DELETE', `/admin/views/${id}`),
    duplicate: (id: number) =>
      request<{ id: number; status: string }>('POST', `/admin/views/${id}/duplicate`),
    validate: (document: ViewDocument) =>
      request<ServerValidation>('POST', '/admin/views/validate', { document }),
    preview: (
      document: ViewDocument,
      page = 1,
      templates: {
        container?: { html: string; css: string };
        entry?: { html: string; css: string };
      } = {},
    ) =>
      request<PreviewPayload>('POST', '/admin/views/preview', {
        document,
        page,
        container_template: templates.container,
        entry_template: templates.entry,
      }),
  },
  templates: {
    list: () => request<{ templates: TemplateSummary[] }>('GET', '/admin/templates'),
    get: (id: number) => request<SavedTemplate>('GET', `/admin/templates/${id}`),
    create: (document: TemplateDocument, source: string) =>
      request<SavedTemplate>('POST', '/admin/templates', { document, source }),
    update: (id: number, document: TemplateDocument, source: string) =>
      request<SavedTemplate>('PUT', `/admin/templates/${id}`, { document, source }),
    trash: (id: number) => request<{ id: number }>('DELETE', `/admin/templates/${id}`),
    duplicate: (id: number) => request<{ id: number }>('POST', `/admin/templates/${id}/duplicate`),
    validate: (document: TemplateDocument, source: string) =>
      request<TemplateValidation>('POST', '/admin/templates/validate', { document, source }),
    preview: (document: TemplateDocument, source: string) =>
      request<PreviewPayload>('POST', '/admin/templates/preview', { document, source }),
  },
  presets: {
    list: () =>
      request<{
        presets: PresetPayload[];
        diagnostics: Array<{ code: string; message: string; source: string }>;
      }>('GET', '/admin/presets'),
    save: (preset: PresetDocument) => request<{ id: string }>('POST', '/admin/presets', { preset }),
    reset: (id: string) => request<{ id: string }>('DELETE', `/admin/presets/${id}`),
    apply: (id: string, source: string) =>
      request<{
        container_template: number;
        entry_template: number;
        layout: Record<string, unknown>;
      }>('POST', `/admin/presets/${id}/apply`, { source }),
  },
  packages: {
    export: (selection: { views: number[]; templates: number[]; presets: string[] }) =>
      request<{ package: Record<string, unknown> }>('POST', '/admin/export', selection),
    import: (pkg: unknown, dryRun: boolean) =>
      request<PackageReport>('POST', '/admin/import', { package: pkg, dry_run: dryRun }),
  },
  system: {
    sources: () => request<{ sources: SourceInfo[] }>('GET', '/admin/sources'),
    layouts: () =>
      request<{ layouts: Array<{ id: string; label: string; module: string | null }> }>(
        'GET',
        '/admin/layouts',
      ),
    filterTypes: () =>
      request<{ filter_types: Array<{ id: string; label: string; builtin: boolean }> }>(
        'GET',
        '/admin/filter-types',
      ),
    placeholders: (source: string, role: 'container' | 'entry') =>
      request<PlaceholderPayload>('GET', '/admin/placeholders', undefined, { source, role }),
    diagnostics: () => request<DiagnosticsPayload>('GET', '/admin/diagnostics'),
    settings: () => request<SettingsPayload>('GET', '/admin/settings'),
    /** `maps` is optional: without it the stored maps stay as they are. */
    saveSettings: (metaKeys: Record<string, string[]>, maps?: Record<string, ValueMap>) =>
      request<SettingsPayload>('PUT', '/admin/settings', {
        meta_keys: metaKeys,
        ...(maps === undefined ? {} : { maps }),
      }),
  },
};
