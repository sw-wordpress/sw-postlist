import { IMAGE_SIZES } from './model';
import type { NodeType } from './model';

export interface Field {
  key: string;
  kind: 'select' | 'number' | 'text' | 'bool' | 'color';
  options?: string[];
  min?: number;
  max?: number;
}

/** The settings form of each element type (labels live in the message catalogs under builder.fields). */
export const FIELDS: Record<NodeType, Field[]> = {
  image: [
    { key: 'size', kind: 'select', options: IMAGE_SIZES },
    { key: 'link', kind: 'bool' },
  ],
  title: [
    { key: 'tag', kind: 'select', options: ['h2', 'h3', 'h4'] },
    { key: 'link', kind: 'bool' },
  ],
  excerpt: [{ key: 'words', kind: 'number', min: 1, max: 200 }],
  date: [
    { key: 'format', kind: 'text' },
    { key: 'relative', kind: 'bool' },
  ],
  author: [{ key: 'link', kind: 'bool' }],
  taxonomy: [
    { key: 'taxonomy', kind: 'text' },
    { key: 'links', kind: 'bool' },
    { key: 'separator', kind: 'text' },
  ],
  meta: [
    { key: 'key', kind: 'text' },
    { key: 'label', kind: 'text' },
    { key: 'fallback', kind: 'text' },
  ],
  readmore: [{ key: 'label', kind: 'text' }],
  text: [{ key: 'text', kind: 'text' }],
  spacer: [{ key: 'height', kind: 'number', min: 0, max: 200 }],
  container: [
    { key: 'direction', kind: 'select', options: ['column', 'row'] },
    { key: 'gap', kind: 'number', min: 0, max: 64 },
    { key: 'padding', kind: 'number', min: 0, max: 64 },
    { key: 'background', kind: 'color' },
  ],
};
