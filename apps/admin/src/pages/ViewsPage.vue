<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { api } from '@/api/endpoints';
import { ApiError } from '@/api/client';
import type { ViewSummary } from '@/api/types';
import { useNotify } from '@/composables/useNotify';
import { useCatalog } from '@/stores/catalog';

const { t } = useI18n();
const notify = useNotify();
const views = ref<ViewSummary[]>([]);
const loading = ref(true);
const catalog = useCatalog();

/** Readable name of an entry source ("Posts"); the technical ID stays visible as a tooltip. */
const sourceLabel = (id: string | null): string =>
  id === null ? '–' : (catalog.source(id)?.label ?? id);

async function load(): Promise<void> {
  loading.value = true;
  try {
    views.value = (await api.views.list()).views;
    // The source names are a nicety: without them the technical IDs are shown.
    await catalog.load().catch(() => undefined);
  } catch {
    notify.failure(t('app.loadFailed'));
  } finally {
    loading.value = false;
  }
}

async function copyShortcode(id: number): Promise<void> {
  try {
    await navigator.clipboard.writeText(`[sw_postlist id="${id}"]`);
    notify.success(t('views.shortcodeCopied'));
  } catch {
    notify.failure(t('app.saveFailed'));
  }
}

async function duplicate(view: ViewSummary): Promise<void> {
  try {
    await api.views.duplicate(view.id);
    notify.success(t('views.duplicated'));
    await load();
  } catch (error) {
    notify.failure(error instanceof ApiError ? error.message : t('app.saveFailed'));
  }
}

async function trash(view: ViewSummary): Promise<void> {
  if (!window.confirm(t('app.confirmTrash'))) return;
  try {
    await api.views.trash(view.id);
    notify.success(t('views.trashed'));
    await load();
  } catch (error) {
    notify.failure(error instanceof ApiError ? error.message : t('app.saveFailed'));
  }
}

onMounted(load);
</script>

<template>
  <section class="swp-page">
    <PageHeader :title="t('views.title')">
      <q-btn
        color="primary"
        no-caps
        :label="t('views.new')"
        to="/views/new"
        data-testid="new-view"
      />
    </PageHeader>
    <q-linear-progress v-if="loading" indeterminate />
    <p v-else-if="views.length === 0" class="swp-muted">{{ t('views.empty') }}</p>
    <div v-else class="swp-table-wrap">
      <table class="swp-table" data-testid="views-table">
        <thead>
          <tr>
            <th>{{ t('views.columns.title') }}</th>
            <th>{{ t('views.columns.status') }}</th>
            <th>{{ t('views.columns.shortcode') }}</th>
            <th>{{ t('views.columns.source') }} · {{ t('views.columns.layout') }}</th>
            <th>
              <span class="swp-sr-only">{{ t('app.edit') }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="view in views" :key="view.id">
            <td>
              <router-link :to="`/views/${view.id}`">{{ view.title }}</router-link>
            </td>
            <td>{{ t(`views.status.${view.status}`) }}</td>
            <td class="swp-shortcode-cell">
              <code>[sw_postlist id="{{ view.id }}"]</code>
              <q-btn
                flat
                round
                dense
                size="sm"
                icon="r_content_copy"
                :aria-label="t('views.copyShortcode')"
                :data-testid="`copy-shortcode-${view.id}`"
                @click="copyShortcode(view.id)"
              >
                <q-tooltip>{{ t('views.copyShortcode') }}</q-tooltip>
              </q-btn>
            </td>
            <td :title="`${t('views.columns.modified')}: ${view.modified.slice(0, 10)}`">
              <span :title="view.source ?? undefined">{{ sourceLabel(view.source) }}</span>
              <span class="swp-muted"> · {{ view.layout }}</span>
            </td>
            <td class="swp-table__actions">
              <q-btn
                flat
                round
                dense
                icon="r_edit"
                :to="`/views/${view.id}`"
                :aria-label="t('app.edit')"
                :data-testid="`edit-view-${view.id}`"
              >
                <q-tooltip>{{ t('app.edit') }}</q-tooltip>
              </q-btn>
              <q-btn
                flat
                round
                dense
                icon="r_library_add"
                :aria-label="t('views.duplicate')"
                :data-testid="`duplicate-view-${view.id}`"
                @click="duplicate(view)"
              >
                <q-tooltip>{{ t('views.duplicate') }}</q-tooltip>
              </q-btn>
              <q-btn
                flat
                round
                dense
                color="negative"
                icon="r_delete"
                :aria-label="t('app.delete')"
                @click="trash(view)"
              >
                <q-tooltip>{{ t('app.delete') }}</q-tooltip>
              </q-btn>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
