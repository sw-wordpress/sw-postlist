import type { TemplateDocument, ValidationIssue, ViewDocument } from '@/documents/types';
import type { FormatterSignature, PlaceholderDefinition, TemplateIssue } from '@/template-engine';

export interface ViewSummary {
  id: number;
  title: string;
  status: 'publish' | 'draft';
  source: string | null;
  layout: string | null;
  modified: string;
}

export interface TemplateSummary {
  id: number;
  title: string;
  role: 'container' | 'entry' | '';
  modified: string;
  used_by: number;
}

export interface SourceInfo {
  id: string;
  label: string;
  fields: {
    taxonomies: string[];
    orderby: string[];
    visitor_filters: string[];
    count_mode: 'exact' | 'continuation';
  };
}

export interface PlaceholderPayload {
  source: string;
  role: 'container' | 'entry';
  placeholders: PlaceholderDefinition[];
  formatters: Record<string, FormatterSignature & { help: string }>;
}

export interface PreviewPayload {
  html: string;
  css: string;
  total?: number | null;
  has_more?: boolean;
  page?: number;
  count: number;
  error: string | null;
}

export interface DiagnosticsPayload {
  registrations: Array<{ code: string; message: string; source: string }>;
  log: Array<{ time: string; code: string; message: string; context: Record<string, unknown> }>;
}

export interface SavedView {
  id: number;
  status: 'publish' | 'draft';
  document: ViewDocument;
}

export interface SavedTemplate {
  id: number;
  document: TemplateDocument;
  used_by?: number[];
}

export interface ServerValidation {
  valid: boolean;
  errors: Array<ValidationIssue & { message?: string }>;
}

export interface TemplateValidation {
  valid: boolean;
  errors: TemplateIssue[];
}

export interface PresetPayload {
  schema_version: 1;
  id: string;
  title: string;
  description: string;
  layout: Record<string, unknown>;
  container: { html: string; css: string };
  entry: { html: string; css: string };
  builder: Record<string, unknown> | null;
  origin: 'bundled' | 'extension' | 'user';
  overrides: string | null;
  can_reset: boolean;
}

export type PresetDocument = Omit<PresetPayload, 'origin' | 'overrides' | 'can_reset'>;

/** Result of an import (or its dry run), see docs/rest-api.md. */
export interface PackageReport {
  ok: boolean;
  dry_run: boolean;
  errors: Array<{ code: string; path: string; message: string }>;
  counts: { views: number; templates: number; presets: number };
  created: { views: number[]; templates: number[]; presets: string[] };
  renamed: string[];
}

/** A named map of the settings for the formatter `map:"name"` (see docs/templates.md). */
export interface ValueMap {
  hide: string[];
  labels: Record<string, string>;
  strip_prefix: string;
}

export interface SettingsPayload {
  meta_keys: Record<string, string[]>;
  maps?: Record<string, ValueMap>;
}
