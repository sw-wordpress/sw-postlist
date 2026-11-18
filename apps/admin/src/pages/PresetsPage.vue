<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { api } from '@/api/endpoints';
import { ApiError } from '@/api/client';
import type { PresetPayload } from '@/api/types';
import { sampleMarkup } from '@/documents/sample';
import DevicePreview from '@/components/DevicePreview.vue';
import { useNotify } from '@/composables/useNotify';
import { useCatalog } from '@/stores/catalog';

const { t } = useI18n();
const router = useRouter();
const notify = useNotify();
const catalog = useCatalog();

const presets = ref<PresetPayload[]>([]);
const loading = ref(true);
const source = ref('');
const previewing = ref<string | null>(null);

const sourceOptions = computed(() =>
  catalog.sources.map((entry) => ({ value: entry.id, label: `${entry.label} (${entry.id})` })),
);
const previewing_ = computed(
  () => presets.value.find((entry) => entry.id === previewing.value) ?? null,
);

/** The preview shows the card with sample values; images become a neutral placeholder box. */
const sample = computed(() => {
  const preset = previewing_.value;
  if (preset === null) return { html: '', css: '' };
  return {
    html: `<div class="swp-preset-sample">${sampleMarkup(preset.entry.html)}</div>`,
    css: `${preset.entry.css}.swp-sample-image{display:flex;align-items:center;justify-content:center;aspect-ratio:16/9;width:100%;max-width:420px;background:#eef0f7;color:#64748b;border-radius:8px}`,
  };
});

async function load(): Promise<void> {
  loading.value = true;
  try {
    await catalog.load(true);
    source.value = catalog.sources[0]?.id ?? '';
    presets.value = (await api.presets.list()).presets;
  } catch {
    notify.failure(t('app.loadFailed'));
  } finally {
    loading.value = false;
  }
}

async function apply(preset: PresetPayload): Promise<void> {
  try {
    await api.presets.apply(preset.id, source.value);
    notify.success(t('presets.applied'));
    await router.push('/templates');
  } catch (error) {
    notify.failure(error instanceof ApiError ? error.message : t('app.saveFailed'));
  }
}

async function reset(preset: PresetPayload): Promise<void> {
  if (!window.confirm(t('app.confirmTrash'))) return;
  try {
    await api.presets.reset(preset.id);
    notify.success(t('presets.resetDone'));
    await load();
  } catch (error) {
    notify.failure(error instanceof ApiError ? error.message : t('app.saveFailed'));
  }
}

/** Presets are plain JSON documents: export hands the browser a file. */
function exportPreset(preset: PresetPayload): void {
  const { origin: _origin, overrides: _overrides, can_reset: _canReset, ...document } = preset;
  const blob = new Blob([`${JSON.stringify(document, null, 2)}\n`], { type: 'application/json' });
  const link = window.document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = `postlist-preset-${preset.id}.json`;
  link.click();
  URL.revokeObjectURL(link.href);
}

onMounted(load);
</script>

<template>
  <section class="swp-page">
    <PageHeader :title="t('presets.title')" :subtitle="t('presets.intro')" />
    <q-linear-progress v-if="loading" indeterminate />
    <template v-else>
      <q-select
        v-model="source"
        outlined
        dense
        emit-value
        map-options
        :options="sourceOptions"
        :label="t('presets.source')"
        class="swp-narrow"
        data-testid="preset-source"
      />
      <p v-if="presets.length === 0" class="swp-muted">{{ t('presets.empty') }}</p>
      <div class="swp-cards swp-cards--presets">
        <article
          v-for="preset in presets"
          :key="preset.id"
          class="swp-preset"
          :data-preset="preset.id"
        >
          <header class="swp-preset__head">
            <h2>{{ preset.title }}</h2>
            <span class="swp-tag">{{ t(`presets.origins.${preset.origin}`) }}</span>
          </header>
          <p class="swp-preset__text">{{ preset.description }}</p>
          <p class="swp-muted swp-preset__meta">
            <span v-if="preset.overrides" class="swp-tag">{{ t('presets.overrides') }}</span>
            {{ t('presets.layoutHint', { layout: String(preset.layout.type ?? '') }) }}
          </p>
          <footer class="swp-preset__actions">
            <q-btn
              unelevated
              no-caps
              dense
              color="primary"
              icon="r_check"
              :label="t('presets.apply')"
              data-action="apply"
              @click="apply(preset)"
            />
            <span class="swp-spacer" />
            <q-btn
              flat
              round
              dense
              icon="r_visibility"
              :aria-label="t('presets.preview')"
              :aria-pressed="previewing === preset.id"
              data-action="preview"
              @click="previewing = previewing === preset.id ? null : preset.id"
            >
              <q-tooltip>{{ t('presets.preview') }}</q-tooltip>
            </q-btn>
            <q-btn
              flat
              round
              dense
              icon="r_download"
              :aria-label="t('presets.exportJson')"
              data-action="export"
              @click="exportPreset(preset)"
            >
              <q-tooltip>{{ t('presets.exportJson') }}</q-tooltip>
            </q-btn>
            <q-btn
              v-if="preset.can_reset"
              flat
              round
              dense
              color="negative"
              :icon="preset.overrides ? 'r_restart_alt' : 'r_delete'"
              :aria-label="preset.overrides ? t('presets.reset') : t('presets.deleteUser')"
              data-action="reset"
              @click="reset(preset)"
            >
              <q-tooltip>{{
                preset.overrides ? t('presets.reset') : t('presets.deleteUser')
              }}</q-tooltip>
            </q-btn>
          </footer>
        </article>
      </div>
      <DevicePreview
        v-if="previewing_"
        :html="sample.html"
        :css="sample.css"
        :loading="false"
        :error="null"
      />
    </template>
  </section>
</template>
