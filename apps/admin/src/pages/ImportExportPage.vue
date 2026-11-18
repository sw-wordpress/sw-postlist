<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { api } from '@/api/endpoints';
import { ApiError } from '@/api/client';
import type { PackageReport, PresetPayload, TemplateSummary, ViewSummary } from '@/api/types';
import IssueList from '@/components/IssueList.vue';
import type { DisplayIssue } from '@/components/IssueList.vue';
import { useNotify } from '@/composables/useNotify';

/** Largest package the server accepts (PackageService::MAX_BYTES). */
const MAX_BYTES = 2 * 1024 * 1024;

const { t } = useI18n();
const notify = useNotify();
const views = ref<ViewSummary[]>([]);
const templates = ref<TemplateSummary[]>([]);
const presets = ref<PresetPayload[]>([]);
const loading = ref(true);
const selectedViews = ref<number[]>([]);
const selectedTemplates = ref<number[]>([]);
const selectedPresets = ref<string[]>([]);
const exporting = ref(false);
const importing = ref(false);
const pending = ref<unknown>(null);
const report = ref<PackageReport | null>(null);
const done = ref<PackageReport | null>(null);
const fileError = ref('');

const nothingSelected = computed(
  () =>
    selectedViews.value.length + selectedTemplates.value.length + selectedPresets.value.length ===
    0,
);
const importIssues = computed<DisplayIssue[]>(() =>
  (report.value?.errors ?? []).map((error) => ({
    code: error.code,
    where: error.path,
    message: error.message,
  })),
);

async function load(): Promise<void> {
  loading.value = true;
  try {
    const [v, tp, p] = await Promise.all([
      api.views.list(),
      api.templates.list(),
      api.presets.list(),
    ]);
    views.value = v.views;
    templates.value = tp.templates;
    // Only presets of the site can be moved: the bundled ones exist everywhere.
    presets.value = p.presets.filter((preset) => preset.origin !== 'bundled');
  } catch {
    notify.failure(t('app.loadFailed'));
  } finally {
    loading.value = false;
  }
}

function download(content: unknown): void {
  const blob = new Blob([`${JSON.stringify(content, null, 2)}\n`], { type: 'application/json' });
  const link = window.document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = `postlist-export-${new Date().toISOString().slice(0, 10)}.json`;
  link.click();
  URL.revokeObjectURL(link.href);
}

async function runExport(): Promise<void> {
  exporting.value = true;
  try {
    const result = await api.packages.export({
      views: selectedViews.value,
      templates: selectedTemplates.value,
      presets: selectedPresets.value,
    });
    download(result.package);
    notify.success(t('importExport.exported'));
  } catch (error) {
    notify.failure(error instanceof ApiError ? error.message : t('app.saveFailed'));
  } finally {
    exporting.value = false;
  }
}

async function check(pkg: unknown): Promise<void> {
  importing.value = true;
  try {
    report.value = await api.packages.import(pkg, true);
    pending.value = pkg;
  } catch (error) {
    if (error instanceof ApiError && Array.isArray(error.payload.errors)) {
      report.value = error.payload as unknown as PackageReport;
      pending.value = null;
    } else {
      notify.failure(error instanceof ApiError ? error.message : t('app.saveFailed'));
    }
  } finally {
    importing.value = false;
  }
}

function readText(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(new SyntaxError('unreadable'));
    reader.readAsText(file);
  });
}

async function chooseFile(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0];
  report.value = null;
  done.value = null;
  pending.value = null;
  fileError.value = '';
  if (!file) return;
  if (file.size > MAX_BYTES) {
    fileError.value = t('importExport.tooLarge');
    return;
  }
  try {
    await check(JSON.parse(await readText(file)));
  } catch (error) {
    if (error instanceof SyntaxError) fileError.value = t('importExport.notJson');
    else throw error;
  }
}

async function runImport(): Promise<void> {
  if (pending.value === null) return;
  importing.value = true;
  try {
    done.value = await api.packages.import(pending.value, false);
    report.value = null;
    pending.value = null;
    notify.success(t('importExport.imported'));
    await load();
  } catch (error) {
    if (error instanceof ApiError && Array.isArray(error.payload.errors)) {
      report.value = error.payload as unknown as PackageReport;
    } else {
      notify.failure(error instanceof ApiError ? error.message : t('app.saveFailed'));
    }
  } finally {
    importing.value = false;
  }
}

onMounted(load);
</script>

<template>
  <section class="swp-page">
    <PageHeader :title="t('importExport.title')" />
    <q-linear-progress v-if="loading" indeterminate />
    <template v-else>
      <section aria-labelledby="swp-export-title" class="swp-card-section">
        <h2 id="swp-export-title">{{ t('importExport.exportTitle') }}</h2>
        <p class="swp-muted">{{ t('importExport.exportHint') }}</p>
        <fieldset class="swp-fieldset" data-testid="export-views">
          <legend>{{ t('nav.views') }}</legend>
          <p v-if="views.length === 0" class="swp-muted">{{ t('views.empty') }}</p>
          <q-checkbox
            v-for="view in views"
            :key="view.id"
            v-model="selectedViews"
            :val="view.id"
            :label="view.title"
            :data-testid="`export-view-${view.id}`"
          />
        </fieldset>
        <fieldset class="swp-fieldset" data-testid="export-templates">
          <legend>{{ t('nav.templates') }}</legend>
          <p class="swp-muted">{{ t('importExport.templatesHint') }}</p>
          <q-checkbox
            v-for="template in templates"
            :key="template.id"
            v-model="selectedTemplates"
            :val="template.id"
            :label="template.title"
            :data-testid="`export-template-${template.id}`"
          />
        </fieldset>
        <fieldset v-if="presets.length > 0" class="swp-fieldset" data-testid="export-presets">
          <legend>{{ t('nav.presets') }}</legend>
          <q-checkbox
            v-for="preset in presets"
            :key="preset.id"
            v-model="selectedPresets"
            :val="preset.id"
            :label="preset.title"
            :data-testid="`export-preset-${preset.id}`"
          />
        </fieldset>
        <q-btn
          color="primary"
          no-caps
          :label="t('importExport.export')"
          :disable="nothingSelected"
          :loading="exporting"
          data-testid="run-export"
          @click="runExport"
        />
      </section>

      <section aria-labelledby="swp-import-title" class="swp-card-section">
        <h2 id="swp-import-title">{{ t('importExport.importTitle') }}</h2>
        <p class="swp-muted">{{ t('importExport.importHint') }}</p>
        <label class="swp-file">
          <span>{{ t('importExport.file') }}</span>
          <input
            type="file"
            accept="application/json,.json"
            data-testid="import-file"
            @change="chooseFile"
          />
        </label>
        <p v-if="fileError" role="alert" class="swp-error" data-testid="import-file-error">
          {{ fileError }}
        </p>
        <IssueList :issues="importIssues" :title="t('importExport.problems')" />
        <div v-if="report?.ok" class="swp-summary" role="status" data-testid="import-summary">
          <p>
            {{
              t('importExport.summary', {
                views: report.counts.views,
                templates: report.counts.templates,
                presets: report.counts.presets,
              })
            }}
          </p>
          <p class="swp-muted">{{ t('importExport.summaryHint') }}</p>
          <q-btn
            color="primary"
            no-caps
            :label="t('importExport.import')"
            :loading="importing"
            data-testid="run-import"
            @click="runImport"
          />
        </div>
        <div v-if="done" class="swp-summary" role="status" data-testid="import-done">
          <p>
            {{
              t('importExport.done', {
                views: done.created.views.length,
                templates: done.created.templates.length,
                presets: done.created.presets.length,
              })
            }}
          </p>
          <ul v-if="done.renamed.length > 0" data-testid="import-renamed">
            <li v-for="entry in done.renamed" :key="entry">{{ entry }}</li>
          </ul>
          <p v-if="done.created.views.length > 0">
            <router-link :to="`/views/${done.created.views[0]}`">{{
              t('importExport.openFirst')
            }}</router-link>
          </p>
        </div>
      </section>
    </template>
  </section>
</template>
