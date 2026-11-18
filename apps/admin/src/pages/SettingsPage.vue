<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { api } from '@/api/endpoints';
import type { SourceInfo } from '@/api/types';
import { fromRows, invalidNames, toRows } from '@/documents/maps';
import type { MapRow } from '@/documents/maps';
import { useNotify } from '@/composables/useNotify';

const { t } = useI18n();
const notify = useNotify();
const sources = ref<SourceInfo[]>([]);
const keys = ref<Record<string, string>>({});
const maps = ref<MapRow[]>([]);
const loading = ref(true);
const saving = ref(false);

onMounted(async () => {
  try {
    const [list, settings] = await Promise.all([api.system.sources(), api.system.settings()]);
    sources.value = list.sources;
    keys.value = Object.fromEntries(
      list.sources.map((source) => [source.id, (settings.meta_keys[source.id] ?? []).join(', ')]),
    );
    maps.value = toRows(settings.maps);
  } catch {
    notify.failure(t('app.loadFailed'));
  } finally {
    loading.value = false;
  }
});

function addMap(): void {
  maps.value.push({ name: '', hide: '', labels: '', prefix: '' });
}

async function save(): Promise<void> {
  if (invalidNames(maps.value).length > 0) {
    notify.failure(t('settings.mapNameInvalid'));
    return;
  }
  saving.value = true;
  try {
    const payload = Object.fromEntries(
      Object.entries(keys.value).map(([source, text]) => [
        source,
        text
          .split(',')
          .map((key) => key.trim())
          .filter((key) => key !== ''),
      ]),
    );
    const saved = await api.system.saveSettings(payload, fromRows(maps.value));
    keys.value = Object.fromEntries(
      sources.value.map((source) => [source.id, (saved.meta_keys[source.id] ?? []).join(', ')]),
    );
    maps.value = toRows(saved.maps);
    notify.success(t('settings.saved'));
  } catch {
    notify.failure(t('app.saveFailed'));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <section class="swp-page">
    <PageHeader :title="t('settings.title')" />
    <q-linear-progress v-if="loading" indeterminate />
    <form v-else class="swp-form" @submit.prevent="save">
      <h2>{{ t('settings.metaKeys') }}</h2>
      <p class="swp-muted">{{ t('settings.metaKeysHint') }}</p>
      <q-input
        v-for="source in sources"
        :key="source.id"
        v-model="keys[source.id]"
        outlined
        dense
        :label="t('settings.metaKeysFor', { source: source.label })"
        :data-testid="`meta-keys-${source.id}`"
      />
      <h2>{{ t('settings.maps') }}</h2>
      <p class="swp-muted">{{ t('settings.mapsHint') }}</p>
      <p v-if="maps.length === 0" class="swp-muted" data-testid="maps-empty">
        {{ t('settings.mapsNone') }}
      </p>
      <section
        v-for="(map, index) in maps"
        :key="index"
        class="swp-filter-row"
        :data-testid="`map-${index}`"
        :aria-label="t('settings.map')"
      >
        <header class="swp-filter-row__head">
          <q-input
            v-model="map.name"
            outlined
            dense
            maxlength="40"
            :label="t('settings.mapName')"
            :hint="t('settings.mapNameHint')"
            :data-testid="`map-name-${index}`"
          />
          <q-btn
            flat
            round
            dense
            icon="r_delete"
            :aria-label="t('settings.mapRemove')"
            :data-testid="`map-remove-${index}`"
            @click="maps.splice(index, 1)"
          >
            <q-tooltip>{{ t('settings.mapRemove') }}</q-tooltip>
          </q-btn>
        </header>
        <q-input
          v-model="map.hide"
          outlined
          dense
          type="textarea"
          autogrow
          :label="t('settings.mapHide')"
          :hint="t('settings.mapHideHint')"
          :data-testid="`map-hide-${index}`"
        />
        <q-input
          v-model="map.labels"
          outlined
          dense
          type="textarea"
          autogrow
          :label="t('settings.mapLabels')"
          :hint="t('settings.mapLabelsHint')"
          :data-testid="`map-labels-${index}`"
        />
        <q-input
          v-model="map.prefix"
          outlined
          dense
          maxlength="50"
          :label="t('settings.mapPrefix')"
          :hint="t('settings.mapPrefixHint')"
          :data-testid="`map-prefix-${index}`"
        />
      </section>
      <div>
        <q-btn
          outline
          dense
          no-caps
          icon="r_add"
          :label="t('settings.mapAdd')"
          data-testid="map-add"
          @click="addMap"
        />
      </div>
      <q-btn
        type="submit"
        color="primary"
        no-caps
        :label="t('app.save')"
        :loading="saving"
        data-testid="save-settings"
      />
    </form>
  </section>
</template>
