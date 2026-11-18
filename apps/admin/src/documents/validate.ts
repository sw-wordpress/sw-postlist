import {
  CONTEXT_MODES,
  META_COMPARES,
  ORDERBY,
  PAGINATION_MODES,
  TEMPLATE_ROLES,
  TERM_OPERATORS,
  TEMPLATE_SCHEMA_VERSION,
  VIEW_SCHEMA_VERSION,
} from './types';
import type { ValidationIssue } from './types';
import { BUILTIN_FILTER_TYPES, MAX_FILTERS, filterParam } from './filters';
import type { FilterTypeDefinition } from './filters';
import { BUILTIN_LAYOUTS } from './layouts';
import type { LayoutDefinition, OptionSchema } from './layouts';

/** Mirrors Application\Document\*Validator in PHP; both run against tests/contract fixtures. */

const MAX_IDS = 500;
const MAX_PER_PAGE = 100;
const MAX_HTML = 200000;
const MAX_CSS = 100000;
const DEVICES = ['desktop', 'tablet', 'mobile'];

type Raw = Record<string, unknown>;

const isObject = (value: unknown): value is Raw =>
  typeof value === 'object' && value !== null && !Array.isArray(value);
const isId = (value: unknown): value is number =>
  typeof value === 'number' && Number.isInteger(value) && value >= 1;
const includes = (list: readonly string[], value: unknown): boolean =>
  typeof value === 'string' && list.includes(value);

function checkVersion(doc: Raw, current: number, issues: ValidationIssue[]): boolean {
  const version = doc.schema_version;
  if (typeof version !== 'number' || !Number.isInteger(version) || version < 1) {
    issues.push({ code: 'schema_version_missing', path: 'schema_version' });
    return false;
  }
  if (version > current) {
    issues.push({ code: 'schema_version_newer', path: 'schema_version' });
    return false;
  }
  return true;
}

function checkTitle(doc: Raw, issues: ValidationIssue[]): void {
  const title = doc.title;
  if (typeof title !== 'string' || title.trim() === '' || [...title].length > 200) {
    issues.push({ code: 'title_invalid', path: 'title' });
  }
}

function checkIdList(value: unknown, path: string, issues: ValidationIssue[]): void {
  if (!Array.isArray(value) || value.length > MAX_IDS || !value.every(isId)) {
    issues.push({ code: 'id_list_invalid', path });
  }
}

function checkDate(value: unknown, path: string, issues: ValidationIssue[]): void {
  if (value === null || value === undefined) return;
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    issues.push({ code: 'date_invalid', path });
    return;
  }
  const date = new Date(`${value}T00:00:00Z`);
  if (Number.isNaN(date.getTime()) || date.toISOString().slice(0, 10) !== value) {
    issues.push({ code: 'date_invalid', path });
  }
}

function checkBounded(
  value: unknown,
  min: number,
  max: number,
  path: string,
  issues: ValidationIssue[],
): void {
  if (typeof value !== 'number' || !Number.isInteger(value) || value < min || value > max) {
    issues.push({ code: 'number_out_of_range', path });
  }
}

function checkTaxonomies(value: unknown, issues: ValidationIssue[]): void {
  if (!Array.isArray(value) || value.length > 20) {
    issues.push({ code: 'taxonomies_invalid', path: 'query.taxonomies' });
    return;
  }
  value.forEach((filter: unknown, index) => {
    const path = `query.taxonomies[${index}]`;
    if (
      !isObject(filter) ||
      typeof filter.taxonomy !== 'string' ||
      !/^[a-z0-9_-]{1,32}$/.test(filter.taxonomy)
    ) {
      issues.push({ code: 'taxonomy_invalid', path: `${path}.taxonomy` });
      return;
    }
    if (!includes(TERM_OPERATORS, filter.operator ?? 'in')) {
      issues.push({ code: 'operator_invalid', path: `${path}.operator` });
      return;
    }
    checkIdList(filter.terms, `${path}.terms`, issues);
  });
}

function checkMeta(value: unknown, issues: ValidationIssue[]): void {
  if (!Array.isArray(value) || value.length > 10) {
    issues.push({ code: 'meta_invalid', path: 'query.meta' });
    return;
  }
  value.forEach((filter: unknown, index) => {
    const path = `query.meta[${index}]`;
    if (
      !isObject(filter) ||
      typeof filter.key !== 'string' ||
      filter.key === '' ||
      filter.key.length > 191
    ) {
      issues.push({ code: 'meta_key_invalid', path: `${path}.key` });
      return;
    }
    if (!includes(META_COMPARES, filter.compare ?? '=')) {
      issues.push({ code: 'meta_compare_invalid', path: `${path}.compare` });
      return;
    }
    const metaValue = filter.value ?? '';
    if (typeof metaValue !== 'string' || metaValue.length > 500) {
      issues.push({ code: 'meta_value_invalid', path: `${path}.value` });
    }
  });
}

function checkQuery(raw: unknown, issues: ValidationIssue[]): void {
  if (!isObject(raw)) {
    issues.push({ code: 'query_invalid', path: 'query' });
    return;
  }
  const orderby = raw.orderby ?? 'date';
  if (!includes(ORDERBY, orderby)) issues.push({ code: 'orderby_invalid', path: 'query.orderby' });
  const order =
    typeof (raw.order ?? 'desc') === 'string' ? String(raw.order ?? 'desc').toLowerCase() : '';
  if (order !== 'asc' && order !== 'desc')
    issues.push({ code: 'order_invalid', path: 'query.order' });
  const metaKey = raw.order_meta_key ?? null;
  if (metaKey !== null && (typeof metaKey !== 'string' || metaKey === '' || metaKey.length > 191)) {
    issues.push({ code: 'order_meta_key_invalid', path: 'query.order_meta_key' });
  }
  if (
    (orderby === 'meta_num' || orderby === 'meta_text') &&
    (metaKey === null || typeof metaKey !== 'string')
  ) {
    issues.push({ code: 'order_meta_key_missing', path: 'query.order_meta_key' });
  }
  if (!includes(CONTEXT_MODES, raw.context ?? 'none'))
    issues.push({ code: 'context_invalid', path: 'query.context' });
  const date = raw.date ?? {};
  if (!isObject(date)) {
    issues.push({ code: 'date_invalid', path: 'query.date' });
  } else {
    checkDate(date.after, 'query.date.after', issues);
    checkDate(date.before, 'query.date.before', issues);
  }
  checkTaxonomies(raw.taxonomies ?? [], issues);
  checkIdList(raw.authors ?? [], 'query.authors', issues);
  checkMeta(raw.meta ?? [], issues);
  checkIdList(raw.include ?? [], 'query.include', issues);
  checkIdList(raw.exclude ?? [], 'query.exclude', issues);
  checkBounded(raw.per_page ?? 10, 1, MAX_PER_PAGE, 'query.per_page', issues);
  checkBounded(raw.offset ?? 0, 0, 10000, 'query.offset', issues);
}

function checkDeviceNumbers(
  value: unknown,
  path: string,
  min: number,
  max: number,
  code: string,
  issues: ValidationIssue[],
): void {
  if (!isObject(value) || Object.keys(value).some((key) => !DEVICES.includes(key))) {
    issues.push({ code, path });
    return;
  }
  for (const [device, number] of Object.entries(value)) {
    if (typeof number !== 'number' || !Number.isInteger(number) || number < min || number > max) {
      issues.push({ code, path: `${path}.${device}` });
    }
  }
}

function checkIntOption(value: unknown, min: number, max: number): boolean {
  return typeof value === 'number' && Number.isInteger(value) && value >= min && value <= max;
}

const MAX_LIST = 50;

function checkOption(
  value: unknown,
  schema: OptionSchema,
  path: string,
  code: string,
  issues: ValidationIssue[],
): void {
  switch (schema.type) {
    case 'device_int':
      checkDeviceNumbers(value, path, schema.min, schema.max, code, issues);
      return;
    case 'device_bool':
      if (!isObject(value) || Object.keys(value).some((key) => !DEVICES.includes(key))) {
        issues.push({ code, path });
        return;
      }
      for (const [device, flag] of Object.entries(value)) {
        if (typeof flag !== 'boolean') issues.push({ code, path: `${path}.${device}` });
      }
      return;
    case 'int':
      if (!checkIntOption(value, schema.min, schema.max)) issues.push({ code, path });
      return;
    case 'bool':
      if (typeof value !== 'boolean') issues.push({ code, path });
      return;
    case 'enum':
      if (!includes(schema.values, value)) issues.push({ code, path });
      return;
    case 'id_list':
      if (
        !Array.isArray(value) ||
        value.length > MAX_LIST ||
        value.some((id) => typeof id !== 'number' || !Number.isInteger(id) || id < 1)
      )
        issues.push({ code, path });
      return;
    case 'enum_list':
      if (
        !Array.isArray(value) ||
        value.length > MAX_LIST ||
        new Set(value).size !== value.length ||
        value.some((item) => !includes(schema.values, item))
      )
        issues.push({ code, path });
      return;
    case 'string':
      if (
        !(value === null && schema.nullable) &&
        (typeof value !== 'string' || !schema.pattern.test(value))
      )
        issues.push({ code, path });
  }
}

function checkLayout(layout: Raw, definition: LayoutDefinition, issues: ValidationIssue[]): void {
  if (definition.schema === null) return;
  const schema = definition.schema;
  for (const [key, value] of Object.entries(layout)) {
    if (key === 'type') continue;
    const option = schema[key];
    if (!option) {
      issues.push({ code: 'layout_unknown_key', path: `layout.${key}` });
      continue;
    }
    checkOption(value, option, `layout.${key}`, 'layout_option_invalid', issues);
  }
}

function checkFilters(
  value: unknown,
  types: readonly FilterTypeDefinition[],
  issues: ValidationIssue[],
): void {
  if (value === undefined || value === null) return;
  if (!Array.isArray(value) || value.length > MAX_FILTERS) {
    issues.push({ code: 'filters_invalid', path: 'filters' });
    return;
  }
  const seen = new Set<string>();
  value.forEach((config: unknown, index) => {
    const path = `filters.${index}`;
    const definition = isObject(config) ? types.find((type) => type.id === config.type) : undefined;
    if (!isObject(config) || !definition) {
      issues.push({ code: 'filter_invalid', path: `${path}.type` });
      return;
    }
    const schema = definition.schema;
    if (schema !== null) {
      for (const [key, option] of Object.entries(config)) {
        if (key === 'type') continue;
        const spec = schema[key];
        if (!spec) issues.push({ code: 'filter_unknown_key', path: `${path}.${key}` });
        else checkOption(option, spec, `${path}.${key}`, 'filter_option_invalid', issues);
      }
    }
    if (definition.id === 'taxonomy' && typeof config.taxonomy !== 'string') {
      issues.push({ code: 'filter_option_invalid', path: `${path}.taxonomy` });
    }
    const param = filterParam(config);
    if (seen.has(param)) issues.push({ code: 'filter_duplicate', path });
    seen.add(param);
  });
}

function checkSource(doc: Raw, issues: ValidationIssue[]): void {
  const source = doc.source;
  if (Array.isArray(source)) {
    issues.push({ code: 'source_multiple', path: 'source' });
    return;
  }
  if (
    typeof source !== 'string' ||
    !/^[a-z0-9_-]+(?::[a-z0-9_-]+)*$/.test(source) ||
    source === 'any' ||
    source === 'post:any'
  ) {
    issues.push({ code: 'source_invalid', path: 'source' });
  }
}

function checkTemplateRefs(templates: unknown, issues: ValidationIssue[]): void {
  if (!isObject(templates)) {
    issues.push({ code: 'templates_invalid', path: 'templates' });
    return;
  }
  for (const role of ['container', 'entry']) {
    const id = templates[role] ?? null;
    if (id !== null && !isId(id))
      issues.push({ code: 'template_id_invalid', path: `templates.${role}` });
  }
}

export function validateViewDocument(
  doc: Raw,
  layouts: readonly LayoutDefinition[] = BUILTIN_LAYOUTS,
  filterTypes: readonly FilterTypeDefinition[] = BUILTIN_FILTER_TYPES,
): ValidationIssue[] {
  const issues: ValidationIssue[] = [];
  if (!checkVersion(doc, VIEW_SCHEMA_VERSION, issues)) return issues;
  checkTitle(doc, issues);
  checkSource(doc, issues);
  checkQuery(doc.query ?? {}, issues);
  const layout = doc.layout;
  const definition = isObject(layout) ? layouts.find((item) => item.id === layout.type) : undefined;
  if (!isObject(layout) || !definition) {
    issues.push({ code: 'layout_invalid', path: 'layout.type' });
  } else {
    checkLayout(layout, definition, issues);
  }
  const pagination = doc.pagination;
  if (!isObject(pagination) || !includes(PAGINATION_MODES, pagination.mode)) {
    issues.push({ code: 'pagination_invalid', path: 'pagination.mode' });
  }
  checkFilters(doc.filters, filterTypes, issues);
  checkTemplateRefs(doc.templates ?? {}, issues);
  return issues;
}

export function validateTemplateDocument(doc: Raw): ValidationIssue[] {
  const issues: ValidationIssue[] = [];
  if (!checkVersion(doc, TEMPLATE_SCHEMA_VERSION, issues)) return issues;
  checkTitle(doc, issues);
  if (!includes(TEMPLATE_ROLES, doc.role)) issues.push({ code: 'role_invalid', path: 'role' });
  if (typeof doc.html !== 'string' || doc.html.length > MAX_HTML) {
    issues.push({ code: 'html_invalid', path: 'html' });
  }
  const css = doc.css ?? '';
  if (typeof css !== 'string' || css.length > MAX_CSS)
    issues.push({ code: 'css_invalid', path: 'css' });
  return issues;
}
