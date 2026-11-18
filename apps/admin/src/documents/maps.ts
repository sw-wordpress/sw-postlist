import type { ValueMap } from '@/api/types';

/** Editable form of a map: the text areas of the settings page. */
export interface MapRow {
  name: string;
  /** One value per line. */
  hide: string;
  /** One "Original = Display text" per line. */
  labels: string;
  prefix: string;
}

export const MAP_NAME = /^[a-z0-9_-]{1,40}$/;

export function toRows(maps: Record<string, ValueMap> | undefined): MapRow[] {
  return Object.entries(maps ?? {}).map(([name, map]) => ({
    name,
    hide: map.hide.join('\n'),
    labels: Object.entries(map.labels)
      .map(([from, to]) => `${from} = ${to}`)
      .join('\n'),
    prefix: map.strip_prefix,
  }));
}

const lines = (text: string): string[] =>
  text
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line !== '');

/** Rows with an invalid or duplicate name are dropped here and reported by `invalidNames`. */
export function fromRows(rows: MapRow[]): Record<string, ValueMap> {
  const maps: Record<string, ValueMap> = {};
  for (const row of rows) {
    if (!MAP_NAME.test(row.name) || row.name in maps) continue;
    const labels: Record<string, string> = {};
    for (const line of lines(row.labels)) {
      const at = line.indexOf('=');
      const from = at < 0 ? '' : line.slice(0, at).trim();
      const to = at < 0 ? '' : line.slice(at + 1).trim();
      if (from !== '' && to !== '') labels[from] = to;
    }
    maps[row.name] = { hide: lines(row.hide), labels, strip_prefix: row.prefix };
  }
  return maps;
}

export function invalidNames(rows: MapRow[]): string[] {
  const seen = new Set<string>();
  const bad: string[] = [];
  for (const row of rows) {
    if (!MAP_NAME.test(row.name) || seen.has(row.name)) bad.push(row.name);
    seen.add(row.name);
  }
  return bad;
}
