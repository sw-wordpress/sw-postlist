<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/endpoints';
import { ApiError } from '@/api/client';
import { toSignatures } from '@/api/convert';
import type { PreviewPayload } from '@/api/types';
import { createTemplateCompletion, toDiagnostics } from '@/components/editorCompletion';
import BuilderPanel from '@/components/BuilderPanel.vue';
import DevicePreview from '@/components/DevicePreview.vue';
import IssueList from '@/components/IssueList.vue';
import type { DisplayIssue } from '@/components/IssueList.vue';
import { useNotify } from '@/composables/useNotify';
import { defaultModel, generate, isGeneratedFrom, parseModel } from '@/builder/model';
import type { BuilderModel } from '@/builder/model';
import { emptyTemplate } from '@/documents/defaults';
import type { TemplateDocument, TemplateRole } from '@/documents/types';
import { compileTemplate, postlistProfile } from '@/template-engine';
import type {
  CompileContext,
  FormatterSignature,
  PlaceholderDefinition,
  TemplateIssue,
} from '@/template-engine';
import { useCatalog } from '@/stores/catalog';

// CodeMirror is loaded on demand: it is only needed on this page.
const CodeEditor = defineAsyncComponent(() => import('@/components/CodeEditor.vue'));

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const notify = useNotify();
const catalog = useCatalog();

const templateId = computed(() => (route.params.id === undefined ? null : Number(route.params.id)));
const loading = ref(true);
const saving = ref(false);
const title = ref('');
const role = ref<TemplateRole>('entry');
const html = ref('');
const css = ref('');
const source = ref('');
const usedBy = ref<number[]>([]);
const editorTab = ref<'html' | 'css' | 'builder'>('html');
const builderModel = ref<BuilderModel | null>(null);
const builderNotice = ref<'handwritten' | 'edited' | null>(null);
const definitions = ref<PlaceholderDefinition[]>([]);
const signatures = ref<Record<string, FormatterSignature>>({});
const serverIssues = ref<TemplateIssue[]>([]);
const saveIssues = ref<DisplayIssue[]>([]);
const preview = ref<PreviewPayload | null>(null);
const previewLoading = ref(false);
const published = ref(false);
const saveFailed = ref(false);
const htmlEditor = ref<{ insert: (text: string) => void; refresh: () => void } | null>(null);
const cssEditor = ref<{ refresh: () => void } | null>(null);

const context = computed<CompileContext>(() => ({
  profile: postlistProfile(),
  find: (name) => definitions.value.find((entry) => entry.name === name) ?? null,
  formatters: signatures.value,
  role: role.value,
}));

const compiled = computed(() => compileTemplate(html.value, css.value, context.value, 'draft'));
const localIssues = computed(() => (definitions.value.length === 0 ? [] : compiled.value.errors));
/** Server findings are authoritative; the local compiler gives instant feedback while they are pending. */
const templateIssues = computed(() =>
  serverIssues.value.length > 0 ? serverIssues.value : localIssues.value,
);
const displayIssues = computed<DisplayIssue[]>(() => [
  ...templateIssues.value.map((issue) => ({
    code: issue.code,
    where: `${issue.line}:${issue.column}`,
    message: issue.message,
  })),
  ...saveIssues.value,
]);

const completion = createTemplateCompletion(() => ({
  definitions: definitions.value,
  signatures: signatures.value,
  role: role.value,
}));
const htmlDiagnostics = (doc: Parameters<typeof toDiagnostics>[1]) =>
  toDiagnostics(
    templateIssues.value.filter((issue) => issue.code !== 'invalid_css'),
    doc,
    (issue) => issue.message,
  );

watch(templateIssues, () => {
  htmlEditor.value?.refresh();
  cssEditor.value?.refresh();
});

function currentDocument(): TemplateDocument {
  return { ...emptyTemplate(role.value), title: title.value, html: html.value, css: css.value };
}

async function loadPlaceholders(): Promise<void> {
  if (source.value === '') return;
  try {
    const payload = await api.system.placeholders(source.value, role.value);
    definitions.value = payload.placeholders;
    signatures.value = toSignatures(payload.formatters);
  } catch {
    notify.failure(t('app.loadFailed'));
  }
}

onMounted(async () => {
  try {
    await catalog.load(true);
    source.value = catalog.sources[0]?.id ?? '';
    if (templateId.value === null) {
      const requested = route.query.role === 'container' ? 'container' : 'entry';
      const starter = emptyTemplate(requested);
      role.value = requested;
      html.value = starter.html;
    } else {
      const saved = await api.templates.get(templateId.value);
      title.value = saved.document.title;
      role.value = saved.document.role;
      html.value = saved.document.html;
      css.value = saved.document.css;
      usedBy.value = saved.used_by ?? [];
    }
    await loadPlaceholders();
    void refreshPreview();
  } catch {
    notify.failure(t('app.loadFailed'));
  } finally {
    loading.value = false;
  }
});

watch(source, () => void loadPlaceholders());

let timer: ReturnType<typeof setTimeout> | undefined;
watch([html, css], () => {
  published.value = false;
  saveFailed.value = false;
  saveIssues.value = [];
  clearTimeout(timer);
  timer = setTimeout(() => void validateOnServer(), 500);
});
onBeforeUnmount(() => clearTimeout(timer));

async function validateOnServer(): Promise<void> {
  if (source.value === '' || definitions.value.length === 0) return;
  try {
    const result = await api.templates.validate(currentDocument(), source.value);
    serverIssues.value = result.errors;
  } catch {
    serverIssues.value = [];
  }
}

async function refreshPreview(): Promise<void> {
  if (source.value === '') return;
  previewLoading.value = true;
  try {
    preview.value = await api.templates.preview(currentDocument(), source.value);
  } catch (error) {
    preview.value = {
      html: '',
      css: '',
      count: 0,
      error: error instanceof ApiError ? error.code : 'request_failed',
    };
  } finally {
    previewLoading.value = false;
  }
}

async function publish(): Promise<void> {
  saveIssues.value = [];
  saveFailed.value = false;
  saving.value = true;
  try {
    const saved =
      templateId.value === null
        ? await api.templates.create(currentDocument(), source.value)
        : await api.templates.update(templateId.value, currentDocument(), source.value);
    published.value = true;
    notify.success(t('templates.editor.published'));
    catalog.loaded = false;
    if (templateId.value === null) await router.replace(`/templates/${saved.id}`);
    else void refreshPreview();
  } catch (error) {
    if (error instanceof ApiError) {
      saveFailed.value = true;
      const rows = (error.payload.errors ?? []) as Array<{
        code: string;
        path?: string;
        message?: string;
      }>;
      const sources = (error.payload.sources ?? {}) as Record<string, TemplateIssue[]>;
      const flattened = Object.values(sources).flat();
      if (flattened.length > 0) serverIssues.value = flattened;
      saveIssues.value = [
        ...rows.map((row) => ({ code: row.code, where: row.path, message: row.message })),
        ...(rows.length === 0 && flattened.length === 0
          ? [{ code: error.code, message: error.message }]
          : []),
      ];
    } else {
      notify.failure(t('app.saveFailed'));
    }
  } finally {
    saving.value = false;
  }
}

/** The code becomes the builder's output; hand written code is never replaced without an explicit choice. */
function startBuilder(model: BuilderModel): void {
  builderModel.value = model;
  builderNotice.value = null;
  const generated = generate(model);
  html.value = generated.html;
  css.value = generated.css;
}

function openBuilder(): void {
  const embedded = parseModel(html.value);
  if (embedded !== null && isGeneratedFrom(html.value, css.value, embedded)) {
    builderModel.value = embedded;
    builderNotice.value = null;
  } else if (html.value.trim() === '') {
    startBuilder(defaultModel());
  } else {
    builderModel.value = embedded;
    builderNotice.value = embedded === null ? 'handwritten' : 'edited';
  }
}

watch(editorTab, (tab) => {
  if (tab === 'builder') openBuilder();
});

function keepCode(): void {
  builderNotice.value = null;
  editorTab.value = 'html';
}

async function saveAsPreset(): Promise<void> {
  const id = window.prompt(t('templates.editor.presetId'), 'my-preset');
  if (id === null || id === '') return;
  const model = parseModel(html.value);
  try {
    await api.presets.save({
      schema_version: 1,
      id,
      title: title.value === '' ? id : title.value,
      description: '',
      layout: { type: 'grid' },
      container: {
        html: '<div class="swp-grid">{{view.entries}}</div>{{view.navigation}}',
        css: '',
      },
      entry: { html: html.value, css: css.value },
      builder:
        model !== null && isGeneratedFrom(html.value, css.value, model)
          ? (model as unknown as Record<string, unknown>)
          : null,
    });
    notify.success(t('templates.editor.presetSaved'));
  } catch (error) {
    notify.failure(error instanceof ApiError ? error.message : t('app.saveFailed'));
  }
}

const sourceOptions = computed(() =>
  catalog.sources.map((entry) => ({ value: entry.id, label: `${entry.label} (${entry.id})` })),
);
const sortedFormatters = computed(() =>
  Object.entries(signatures.value).sort(([a], [b]) => a.localeCompare(b)),
);

function insertPlaceholder(name: string): void {
  htmlEditor.value?.insert(`{{${name}}}`);
}
function insertFormatter(name: string): void {
  htmlEditor.value?.insert(`|${name}`);
}
</script>

<template>
  <section class="swp-page">
    <PageHeader :title="title === '' ? t('templates.title') : title">
      <q-btn
        v-if="role === 'entry'"
        outline
        no-caps
        :label="t('templates.editor.saveAsPreset')"
        data-testid="save-as-preset"
        @click="saveAsPreset"
      />
      <q-btn
        color="primary"
        no-caps
        :label="templateId === null ? t('templates.editor.publish') : t('templates.editor.update')"
        :loading="saving"
        data-testid="publish-template"
        @click="publish"
      />
    </PageHeader>
    <q-linear-progress v-if="loading" indeterminate />
    <template v-else>
      <p v-if="usedBy.length > 0" class="swp-muted">
        {{ t('templates.editor.sharedHint', { count: usedBy.length }) }}
      </p>
      <div class="swp-row-fields">
        <q-input
          v-model="title"
          outlined
          dense
          :label="t('templates.editor.name')"
          data-testid="template-title"
        />
        <q-select
          :model-value="role"
          outlined
          dense
          disable
          :options="['entry', 'container']"
          :label="t('templates.editor.role')"
        />
        <q-select
          v-model="source"
          outlined
          dense
          emit-value
          map-options
          :options="sourceOptions"
          :label="t('templates.editor.source')"
          data-testid="template-source"
        />
      </div>
      <IssueList :issues="displayIssues" :title="t('templates.editor.problems')" />
      <p v-if="saveFailed" class="swp-muted">
        {{ t('templates.editor.keepLastValid') }}
      </p>
      <p
        v-else-if="displayIssues.length === 0 && definitions.length > 0"
        class="swp-ok"
        data-testid="template-valid"
      >
        {{ t('templates.editor.valid') }}
      </p>
      <div class="swp-editor-layout">
        <div class="swp-editor-main">
          <q-tabs v-model="editorTab" dense align="left" no-caps>
            <q-tab name="html" :label="t('templates.editor.html')" />
            <q-tab name="css" :label="t('templates.editor.css')" />
            <q-tab
              v-if="role === 'entry'"
              name="builder"
              :label="t('templates.editor.builderTab')"
              data-testid="tab-builder"
            />
          </q-tabs>
          <div v-show="editorTab === 'html'" data-testid="html-editor">
            <CodeEditor
              ref="htmlEditor"
              v-model="html"
              language="html"
              :label="t('templates.editor.html')"
              :completion="completion"
              :diagnostics="htmlDiagnostics"
            />
          </div>
          <div v-show="editorTab === 'css'" data-testid="css-editor">
            <CodeEditor
              ref="cssEditor"
              v-model="css"
              language="css"
              :label="t('templates.editor.css')"
            />
          </div>
          <div v-if="editorTab === 'builder'" data-testid="builder-tab">
            <p class="swp-muted">{{ t('builder.intro') }}</p>
            <div v-if="builderNotice" class="swp-issues" role="status" data-testid="builder-notice">
              <p>{{ t(`builder.${builderNotice}`) }}</p>
              <div class="swp-actions">
                <q-btn
                  color="primary"
                  no-caps
                  size="sm"
                  :label="builderNotice === 'edited' ? t('builder.restore') : t('builder.start')"
                  data-testid="builder-start"
                  @click="startBuilder(builderModel ?? defaultModel())"
                />
                <q-btn
                  outline
                  no-caps
                  size="sm"
                  :label="t('builder.keepCode')"
                  data-testid="builder-keep"
                  @click="keepCode"
                />
              </div>
            </div>
            <BuilderPanel
              v-else-if="builderModel"
              :model-value="builderModel"
              @update:model-value="startBuilder($event)"
            />
          </div>
        </div>
        <aside
          class="swp-panel"
          :aria-label="t('templates.editor.panel')"
          data-testid="placeholder-panel"
        >
          <h2>{{ t('templates.editor.panel') }}</h2>
          <p v-if="definitions.length === 0" class="swp-muted">
            {{ t('templates.editor.noPlaceholders') }}
          </p>
          <ul class="swp-placeholders">
            <li v-for="definition in definitions" :key="definition.name">
              <button
                type="button"
                class="swp-placeholder"
                :title="t('templates.editor.insert')"
                :data-placeholder="definition.name"
                @click="insertPlaceholder(definition.name)"
              >
                <code>{{ definition.name }}</code>
                <span class="swp-type">{{ t(`templates.editor.types.${definition.type}`) }}</span>
              </button>
              <small
                >{{ definition.description
                }}<template v-if="definition.example !== ''">
                  — <em>{{ definition.example }}</em></template
                ></small
              >
            </li>
          </ul>
          <h3>{{ t('templates.editor.formatters') }}</h3>
          <ul class="swp-placeholders">
            <li v-for="[name, signature] in sortedFormatters" :key="name">
              <button
                type="button"
                class="swp-placeholder"
                :data-formatter="name"
                @click="insertFormatter(name)"
              >
                <code
                  >|{{ name
                  }}{{
                    signature.params
                      .map((param) => ':' + param.name + (param.required ? '' : '?'))
                      .join('')
                  }}</code
                >
                <span class="swp-type"
                  >{{
                    signature.inputs.map((type) => t(`templates.editor.types.${type}`)).join(', ')
                  }}
                  → {{ t(`templates.editor.types.${signature.output}`) }}</span
                >
              </button>
              <small>{{ signature.help }}</small>
            </li>
          </ul>
        </aside>
      </div>
      <h2>{{ t('preview.title') }}</h2>
      <q-btn
        outline
        no-caps
        :label="t('templates.editor.previewRefresh')"
        data-testid="refresh-template-preview"
        @click="refreshPreview"
      />
      <DevicePreview
        :html="preview?.html ?? ''"
        :css="preview?.css ?? ''"
        :loading="previewLoading"
        :error="preview?.error ?? null"
      />
    </template>
  </section>
</template>
