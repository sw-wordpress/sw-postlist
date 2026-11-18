<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  AUTHOR_DISPLAYS,
  BUILTIN_FILTER_TYPES,
  MAX_FILTERS,
  TAXONOMY_DISPLAYS,
} from '@/documents/filters';
import { parseIds } from '@/documents/defaults';
import { ORDERBY } from '@/documents/types';
import type { FilterConfig } from '@/documents/types';

/** Edits the visitor filters of a view. Filters of third-party types are kept as they are. */
const props = defineProps<{ modelValue: FilterConfig[]; taxonomies: string[] }>();
const emit = defineEmits<{ 'update:modelValue': [value: FilterConfig[]] }>();
const { t } = useI18n();

const builtinIds = BUILTIN_FILTER_TYPES.map((type) => type.id);
const canAdd = computed(() => props.modelValue.length < MAX_FILTERS);

function replace(index: number, next: FilterConfig): void {
  emit(
    'update:modelValue',
    props.modelValue.map((config, position) => (position === index ? next : config)),
  );
}

function patch(index: number, changes: Record<string, unknown>): void {
  const current = props.modelValue[index];
  if (!current) return;
  const next: FilterConfig = { ...current, ...changes };
  for (const key of Object.keys(changes)) {
    // Empty optional values are not written to the document.
    if (changes[key] === '' || changes[key] === null) Reflect.deleteProperty(next, key);
  }
  replace(index, next);
}

function add(type: string): void {
  const config: FilterConfig = { type };
  if (type === 'taxonomy') config.taxonomy = props.taxonomies[0] ?? 'category';
  if (type === 'sort') config.options = ['date', 'title'];
  emit('update:modelValue', [...props.modelValue, config]);
}

function remove(index: number): void {
  emit(
    'update:modelValue',
    props.modelValue.filter((_, position) => position !== index),
  );
}

function move(index: number, by: number): void {
  const target = index + by;
  if (target < 0 || target >= props.modelValue.length) return;
  const list = [...props.modelValue];
  const [item] = list.splice(index, 1);
  if (item) list.splice(target, 0, item);
  emit('update:modelValue', list);
}

const text = (config: FilterConfig, key: string): string =>
  typeof config[key] === 'string' ? (config[key] as string) : '';
const idsText = (config: FilterConfig): string =>
  Array.isArray(config.terms) ? config.terms.join(', ') : '';
const sortOptions = (config: FilterConfig): string[] =>
  Array.isArray(config.options) ? (config.options as string[]) : [];

function toggleSort(index: number, field: string, checked: boolean): void {
  const config = props.modelValue[index];
  if (!config) return;
  const current = sortOptions(config).filter((value) => value !== field);
  patch(index, {
    options: checked ? ORDERBY.filter((value) => [...current, field].includes(value)) : current,
  });
}
</script>

<template>
  <div data-testid="filter-editor">
    <p class="swp-muted">{{ t('views.filters.hint') }}</p>
    <p v-if="modelValue.length === 0" class="swp-muted" data-testid="filters-empty">
      {{ t('views.filters.none') }}
    </p>
    <section
      v-for="(config, index) in modelValue"
      :key="index"
      class="swp-filter-row"
      :data-testid="`filter-${index}`"
      :aria-label="`${t('views.filters.filter')} ${index + 1}`"
    >
      <header class="swp-filter-row__head">
        <strong>
          {{
            builtinIds.includes(config.type)
              ? t(`views.filters.types.${config.type}`)
              : `${config.type} (${t('views.filters.extension')})`
          }}
        </strong>
        <span class="swp-filter-row__actions">
          <q-btn
            flat
            dense
            round
            icon="r_arrow_upward"
            :disable="index === 0"
            :aria-label="t('views.filters.up')"
            @click="move(index, -1)"
          />
          <q-btn
            flat
            dense
            round
            icon="r_arrow_downward"
            :disable="index === modelValue.length - 1"
            :aria-label="t('views.filters.down')"
            @click="move(index, 1)"
          />
          <q-btn
            flat
            dense
            round
            icon="r_delete"
            :aria-label="t('views.filters.remove')"
            :data-testid="`filter-remove-${index}`"
            @click="remove(index)"
          />
        </span>
      </header>

      <template v-if="config.type === 'taxonomy'">
        <q-select
          :model-value="text(config, 'taxonomy')"
          outlined
          dense
          emit-value
          :options="taxonomies"
          :label="t('views.filters.taxonomy')"
          @update:model-value="patch(index, { taxonomy: $event })"
        />
        <q-select
          :model-value="text(config, 'display') || 'buttons'"
          outlined
          dense
          emit-value
          map-options
          :options="
            TAXONOMY_DISPLAYS.map((value) => ({
              value,
              label: t(`views.filters.displays.${value}`),
            }))
          "
          :label="t('views.filters.display')"
          :data-testid="`filter-display-${index}`"
          @update:model-value="patch(index, { display: $event })"
        />
        <q-input
          :model-value="idsText(config)"
          outlined
          dense
          :label="t('views.filters.terms')"
          :hint="t('views.filters.termsHint')"
          @update:model-value="
            patch(index, {
              terms: parseIds(String($event ?? '')).length ? parseIds(String($event ?? '')) : null,
            })
          "
        />
      </template>

      <q-select
        v-else-if="config.type === 'author'"
        :model-value="text(config, 'display') || 'dropdown'"
        outlined
        dense
        emit-value
        map-options
        :options="
          AUTHOR_DISPLAYS.map((value) => ({
            value,
            label: t(`views.filters.displays.${value}`),
          }))
        "
        :label="t('views.filters.display')"
        @update:model-value="patch(index, { display: $event })"
      />

      <q-input
        v-else-if="config.type === 'search'"
        :model-value="text(config, 'placeholder')"
        outlined
        dense
        :label="t('views.filters.placeholder')"
        @update:model-value="patch(index, { placeholder: $event })"
      />

      <fieldset v-else-if="config.type === 'sort'" class="swp-filter-row__sort">
        <legend>{{ t('views.filters.sortFields') }}</legend>
        <q-checkbox
          v-for="field in ORDERBY"
          :key="field"
          dense
          :model-value="sortOptions(config).includes(field)"
          :label="t(`views.query.orderbyOptions.${field}`)"
          :data-testid="`filter-sort-${index}-${field}`"
          @update:model-value="toggleSort(index, field, $event)"
        />
      </fieldset>

      <p v-else class="swp-muted">{{ t('views.filters.extensionHint') }}</p>

      <q-input
        v-if="builtinIds.includes(config.type)"
        :model-value="text(config, 'label')"
        outlined
        dense
        maxlength="60"
        :label="t('views.filters.label')"
        :hint="t('views.filters.labelHint')"
        @update:model-value="patch(index, { label: $event })"
      />
    </section>

    <div v-if="canAdd" class="swp-filter-add">
      <q-btn
        v-for="type in builtinIds"
        :key="type"
        outline
        dense
        no-caps
        icon="r_add"
        :label="t(`views.filters.types.${type}`)"
        :data-testid="`filter-add-${type}`"
        @click="add(type)"
      />
    </div>
  </div>
</template>
