<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { FIELDS } from '@/builder/fields';
import { NODE_TYPES, createNode, isValidModel } from '@/builder/model';
import type { BuilderModel, NodeType } from '@/builder/model';
import { addNode, flatten, moveNode, nodeAt, removeNode, updateNode } from '@/builder/tree';
import type { Path } from '@/builder/tree';

const props = defineProps<{ modelValue: BuilderModel }>();
const emit = defineEmits<{ 'update:modelValue': [value: BuilderModel] }>();
const { t } = useI18n();

const selected = ref<Path>([]);
const newType = ref<NodeType>('title');

const rows = computed(() => flatten(props.modelValue));
const current = computed(() => nodeAt(props.modelValue, selected.value));
const fields = computed(() => (current.value === null ? [] : FIELDS[current.value.type]));
const key = (path: Path): string => path.join('.') || 'root';

watch(
  () => props.modelValue,
  () => {
    if (nodeAt(props.modelValue, selected.value) === null) selected.value = [];
  },
);

function commit(model: BuilderModel): void {
  // A model the generator could not express never leaves the panel.
  if (isValidModel(model)) emit('update:modelValue', model);
}

function add(): void {
  const result = addNode(props.modelValue, selected.value, createNode(newType.value));
  commit(result.model);
  selected.value = result.path;
}

function remove(path: Path): void {
  commit(removeNode(props.modelValue, path));
  selected.value = [];
}

function move(path: Path, direction: -1 | 1): void {
  const result = moveNode(props.modelValue, path, direction);
  commit(result.model);
  selected.value = result.path;
}

function change(field: string, value: unknown): void {
  commit(updateNode(props.modelValue, selected.value, { [field]: value }));
}

function value(field: string): unknown {
  return (current.value as unknown as Record<string, unknown> | null)?.[field];
}
</script>

<template>
  <div class="swp-builder" data-testid="builder">
    <div class="swp-builder__toolbar">
      <q-select
        v-model="newType"
        dense
        outlined
        emit-value
        map-options
        :options="NODE_TYPES.map((type) => ({ value: type, label: t(`builder.types.${type}`) }))"
        :label="t('builder.add')"
        data-testid="builder-type"
      />
      <q-btn
        color="primary"
        no-caps
        :label="t('builder.addButton')"
        data-testid="builder-add"
        @click="add"
      />
    </div>
    <ul class="swp-builder__tree" role="tree" :aria-label="t('builder.title')">
      <li
        v-for="row in rows"
        :key="key(row.path)"
        role="treeitem"
        :aria-selected="key(row.path) === key(selected)"
        :class="{ 'is-selected': key(row.path) === key(selected) }"
        :style="{ paddingLeft: `${row.depth * 16}px` }"
        :data-path="key(row.path)"
      >
        <button type="button" class="swp-builder__label" @click="selected = row.path">
          {{ t(`builder.types.${row.node.type}`) }}
        </button>
        <span v-if="row.path.length > 0" class="swp-builder__actions">
          <q-btn
            flat
            dense
            round
            size="sm"
            icon="r_arrow_upward"
            :aria-label="t('builder.moveUp')"
            data-action="up"
            @click="move(row.path, -1)"
          />
          <q-btn
            flat
            dense
            round
            size="sm"
            icon="r_arrow_downward"
            :aria-label="t('builder.moveDown')"
            data-action="down"
            @click="move(row.path, 1)"
          />
          <q-btn
            flat
            dense
            round
            size="sm"
            icon="r_delete"
            color="negative"
            :aria-label="t('builder.remove')"
            data-action="remove"
            @click="remove(row.path)"
          />
        </span>
      </li>
    </ul>
    <div v-if="current" class="swp-builder__settings" data-testid="builder-settings">
      <h3>{{ t(`builder.types.${current.type}`) }}</h3>
      <template v-for="field in fields" :key="field.key">
        <q-select
          v-if="field.kind === 'select'"
          dense
          outlined
          :model-value="value(field.key)"
          :options="field.options"
          :label="t(`builder.fields.${field.key}`)"
          :data-field="field.key"
          @update:model-value="change(field.key, $event)"
        />
        <q-checkbox
          v-else-if="field.kind === 'bool'"
          :model-value="Boolean(value(field.key))"
          :label="t(`builder.fields.${field.key}`)"
          :data-field="field.key"
          @update:model-value="change(field.key, $event)"
        />
        <q-input
          v-else-if="field.kind === 'number'"
          dense
          outlined
          type="number"
          :min="field.min"
          :max="field.max"
          :model-value="value(field.key) as number"
          :label="t(`builder.fields.${field.key}`)"
          :data-field="field.key"
          @update:model-value="change(field.key, Number($event))"
        />
        <q-input
          v-else
          dense
          outlined
          :type="field.kind === 'color' ? 'text' : 'text'"
          :model-value="String(value(field.key) ?? '')"
          :label="t(`builder.fields.${field.key}`)"
          :hint="field.kind === 'color' ? t('builder.colorHint') : undefined"
          :data-field="field.key"
          @update:model-value="change(field.key, String($event ?? ''))"
        />
      </template>
    </div>
  </div>
</template>
