/** Mirrors Application\Layout\BuiltinLayouts in PHP (option schemas of the five layouts of 1.0). */

export const ASPECT_RATIOS = ['auto', '1/1', '4/3', '3/2', '16/9', '2/1'] as const;

export type OptionSchema =
  | { type: 'device_int'; min: number; max: number }
  | { type: 'device_bool' }
  | { type: 'int'; min: number; max: number }
  | { type: 'bool' }
  | { type: 'enum'; values: readonly string[] }
  | { type: 'id_list' }
  | { type: 'enum_list'; values: readonly string[] }
  | { type: 'string'; pattern: RegExp; nullable: boolean };

export interface LayoutDefinition {
  id: string;
  label: string;
  /** Null for third-party layouts: their options are validated by the server only. */
  schema: Record<string, OptionSchema> | null;
  module: string | null;
}

const COMMON: Record<string, OptionSchema> = {
  columns: { type: 'device_int', min: 1, max: 6 },
  gap: { type: 'device_int', min: 0, max: 64 },
  font_size: { type: 'device_int', min: 50, max: 200 },
  hidden: { type: 'device_bool' },
  aspect_ratio: { type: 'enum', values: ASPECT_RATIOS },
  accent: { type: 'string', pattern: /^[a-z0-9-]{1,40}$/, nullable: true },
};

const SLIDER: Record<string, OptionSchema> = {
  slides: { type: 'device_int', min: 1, max: 6 },
  autoplay: { type: 'bool' },
  interval: { type: 'int', min: 2000, max: 20000 },
  loop: { type: 'bool' },
  arrows: { type: 'bool' },
  dots: { type: 'bool' },
};

export const BUILTIN_LAYOUTS: readonly LayoutDefinition[] = [
  { id: 'grid', label: 'Grid', schema: COMMON, module: null },
  { id: 'list', label: 'List', schema: COMMON, module: null },
  { id: 'masonry', label: 'Masonry', schema: COMMON, module: 'masonry' },
  { id: 'hover_grid', label: 'Hover grid', schema: COMMON, module: null },
  { id: 'slider', label: 'Slider', schema: { ...COMMON, ...SLIDER }, module: 'slider' },
];

export const layoutIds = (layouts: readonly LayoutDefinition[] = BUILTIN_LAYOUTS): string[] =>
  layouts.map((layout) => layout.id);
