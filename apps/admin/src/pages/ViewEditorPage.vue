<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/endpoints';
import { ApiError } from '@/api/client';
import type { PreviewPayload } from '@/api/types';
import DevicePreview from '@/components/DevicePreview.vue';
import FilterEditor from '@/components/FilterEditor.vue';
import IssueList from '@/components/IssueList.vue';
import type { DisplayIssue } from '@/components/IssueList.vue';
import { useNotify } from '@/composables/useNotify';
import { emptyView, parseIds } from '@/documents/defaults';
import {
  CONTEXT_MODES,
  META_COMPARES,
  ORDERBY,
  PAGINATION_MODES,
  TERM_OPERATORS,
} from '@/documents/types';
import type { DeviceValues, FilterConfig, ViewDocument } from '@/documents/types';
import { validateViewDocument } from '@/documents/validate';
import { useCatalog } from '@/stores/catalog';

const { t, te } = useI18n();
const route = useRoute();
const router = useRouter();
const notify = useNotify();
const catalog = useCatalog();

const DEVICES = ['desktop', 'tablet', 'mobile'] as const;
const viewId = computed(() => (route.params.id === undefined ? null : Number(route.params.id)));

const loading = ref(true);
const saving = ref(false);
const tab = ref('general');
const status = ref<'publish' | 'draft'>('publish');
const serverIssues = ref<DisplayIssue[]>([]);

const title = ref('');
const source = ref('');
const taxonomies = ref<Array<{ taxonomy: string; terms: string; operator: string }>>([]);
const authors = ref('');
const dateAfter = ref('');
const dateBefore = ref('');
const meta = ref<Array<{ key: string; value: string; compare: string }>>([]);
const include = ref('');
const exclude = ref('');
const perPage = ref(10);
const offset = ref(0);
const orderby = ref('date');
const order = ref('desc');
const orderMetaKey = ref('');
const context = ref('none');
const layoutType = ref('grid');
const columns = reactive<Record<string, number>>({ desktop: 3, tablet: 2, mobile: 1 });
const gap = reactive<Record<string, number>>({ desktop: 24, tablet: 16, mobile: 12 });
const fontSize = reactive<Record<string, number>>({ desktop: 100, tablet: 100, mobile: 100 });
const hidden = reactive<Record<string, boolean>>({ desktop: false, tablet: false, mobile: false });
const aspect = ref('auto');
const accent = ref('');
const slides = reactive<Record<string, number>>({ desktop: 3, tablet: 2, mobile: 1 });
const autoplay = ref(false);
const interval = ref(6000);
const loop = ref(false);
const arrows = ref(true);
const dots = ref(true);
const paginationMode = ref('classic');
const filters = ref<FilterConfig[]>([]);
const containerTemplate = ref<number | null>(null);
const entryTemplate = ref<number | null>(null);

const preview = ref<PreviewPayload | null>(null);
const previewLoading = ref(false);

function fill(document: ViewDocument): void {
  title.value = document.title;
  source.value = document.source;
  taxonomies.value = document.query.taxonomies.map((filter) => ({
    taxonomy: filter.taxonomy,
    terms: filter.terms.join(', '),
    operator: filter.operator,
  }));
  authors.value = document.query.authors.join(', ');
  dateAfter.value = document.query.date.after ?? '';
  dateBefore.value = document.query.date.before ?? '';
  meta.value = document.query.meta.map((filter) => ({ ...filter }));
  include.value = document.query.include.join(', ');
  exclude.value = document.query.exclude.join(', ');
  perPage.value = document.query.per_page;
  offset.value = document.query.offset;
  orderby.value = document.query.orderby;
  order.value = document.query.order;
  orderMetaKey.value = document.query.order_meta_key ?? '';
  context.value = document.query.context;
  layoutType.value = document.layout.type;
  for (const device of DEVICES) {
    columns[device] = document.layout.columns?.[device] ?? columns[device] ?? 1;
    gap[device] = document.layout.gap?.[device] ?? gap[device] ?? 0;
    fontSize[device] = document.layout.font_size?.[device] ?? 100;
    hidden[device] = document.layout.hidden?.[device] ?? false;
  }
  aspect.value = document.layout.aspect_ratio ?? 'auto';
  accent.value = document.layout.accent ?? '';
  for (const device of DEVICES) {
    slides[device] =
      (document.layout.slides as DeviceValues | undefined)?.[device] ?? slides[device] ?? 1;
  }
  autoplay.value = document.layout.autoplay === true;
  interval.value = typeof document.layout.interval === 'number' ? document.layout.interval : 6000;
  loop.value = document.layout.loop === true;
  arrows.value = document.layout.arrows !== false;
  dots.value = document.layout.dots !== false;
  paginationMode.value = document.pagination.mode;
  filters.value = (document.filters ?? []).map((config) => ({ ...config }));
  containerTemplate.value = document.templates.container;
  entryTemplate.value = document.templates.entry;
}

/** The document as the form currently describes it (what is validated, previewed and saved). */
function buildDocument(): ViewDocument {
  const base = emptyView(source.value);
  return {
    ...base,
    title: title.value,
    query: {
      taxonomies: taxonomies.value.map((row) => ({
        taxonomy: row.taxonomy,
        terms: parseIds(row.terms),
        operator: row.operator as ViewDocument['query']['taxonomies'][number]['operator'],
      })),
      authors: parseIds(authors.value),
      date: {
        after: dateAfter.value === '' ? null : dateAfter.value,
        before: dateBefore.value === '' ? null : dateBefore.value,
      },
      meta: meta.value.map((row) => ({
        key: row.key,
        value: row.value,
        compare: row.compare as ViewDocument['query']['meta'][number]['compare'],
      })),
      include: parseIds(include.value),
      exclude: parseIds(exclude.value),
      per_page: Number(perPage.value),
      offset: Number(offset.value),
      orderby: orderby.value as ViewDocument['query']['orderby'],
      order: order.value as 'asc' | 'desc',
      order_meta_key: orderMetaKey.value === '' ? null : orderMetaKey.value,
      context: context.value as ViewDocument['query']['context'],
    },
    layout: {
      type: layoutType.value,
      columns: { ...columns },
      gap: { ...gap },
      font_size: { ...fontSize },
      hidden: { ...hidden },
      aspect_ratio: aspect.value as NonNullable<ViewDocument['layout']['aspect_ratio']>,
      accent: accent.value === '' ? null : accent.value,
      ...(layoutType.value === 'slider'
        ? {
            slides: { ...slides },
            autoplay: autoplay.value,
            interval: Number(interval.value),
            loop: loop.value,
            arrows: arrows.value,
            dots: dots.value,
          }
        : {}),
    },
    pagination: { mode: paginationMode.value as ViewDocument['pagination']['mode'] },
    templates: { container: containerTemplate.value, entry: entryTemplate.value },
    ...(filters.value.length > 0
      ? { filters: filters.value.map((config) => ({ ...config })) }
      : {}),
  };
}

const clientIssues = computed<DisplayIssue[]>(() =>
  validateViewDocument(
    buildDocument() as unknown as Record<string, unknown>,
    catalog.layouts,
    catalog.filterTypes,
  ).map((issue) => ({
    code: issue.code,
    where: issue.path,
  })),
);
const issues = computed<DisplayIssue[]>(() => [...clientIssues.value, ...serverIssues.value]);
const sourceInfo = computed(() => catalog.source(source.value));
const taxonomyOptions = computed(() => sourceInfo.value?.fields.taxonomies ?? []);
const containerOptions = computed(() => catalog.templatesFor('container'));
const entryOptions = computed(() => catalog.templatesFor('entry'));

onMounted(async () => {
  try {
    await catalog.load(true);
    if (viewId.value === null) {
      fill(emptyView(catalog.sources[0]?.id ?? 'post:post'));
    } else {
      const saved = await api.views.get(viewId.value);
      status.value = saved.status;
      fill(saved.document);
    }
  } catch {
    notify.failure(t('app.loadFailed'));
  } finally {
    loading.value = false;
  }
});

watch(source, () => {
  // Taxonomy filters only make sense for the taxonomies of the chosen source.
  taxonomies.value = taxonomies.value.filter((row) => taxonomyOptions.value.includes(row.taxonomy));
});

async function loadPreview(): Promise<void> {
  if (clientIssues.value.length > 0) return;
  previewLoading.value = true;
  try {
    preview.value = await api.views.preview(buildDocument());
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

watch(tab, (value) => {
  if (value === 'preview') void loadPreview();
});

async function save(target: 'publish' | 'draft'): Promise<void> {
  serverIssues.value = [];
  if (clientIssues.value.length > 0) {
    tab.value = 'general';
    return;
  }
  saving.value = true;
  try {
    const document = buildDocument();
    const saved =
      viewId.value === null
        ? await api.views.create(document, target)
        : await api.views.update(viewId.value, document, target);
    status.value = saved.status;
    notify.success(t('app.saved'));
    if (viewId.value === null) await router.replace(`/views/${saved.id}`);
  } catch (error) {
    if (error instanceof ApiError) {
      const rows = (error.payload.errors ?? []) as Array<{
        code: string;
        path?: string;
        message?: string;
      }>;
      serverIssues.value =
        rows.length > 0
          ? rows.map((row) => ({ code: row.code, where: row.path, message: row.message }))
          : [{ code: error.code, message: error.message }];
    } else {
      notify.failure(t('app.saveFailed'));
    }
  } finally {
    saving.value = false;
  }
}

function addTaxonomy(): void {
  taxonomies.value.push({ taxonomy: taxonomyOptions.value[0] ?? '', terms: '', operator: 'in' });
}
function addMeta(): void {
  meta.value.push({ key: '', value: '', compare: '=' });
}

const sourceOptions = computed(() =>
  catalog.sources.map((entry) => ({ value: entry.id, label: `${entry.label} (${entry.id})` })),
);
const templateChoices = (list: Array<{ id: number; title: string }>) => [
  { value: null, label: t('views.template.builtin') },
  ...list.map((entry) => ({ value: entry.id, label: entry.title })),
];
</script>

<template>
  <section class="swp-page">
    <PageHeader :title="viewId === null ? t('views.new') : t('views.editTitle')">
      <q-btn
        outline
        no-caps
        :label="t('views.general.saveDraft')"
        :loading="saving"
        data-testid="save-draft"
        @click="save('draft')"
      />
      <q-btn
        color="primary"
        no-caps
        :label="
          viewId === null || status === 'draft'
            ? t('views.general.publish')
            : t('views.general.update')
        "
        :loading="saving"
        data-testid="publish-view"
        @click="save('publish')"
      />
    </PageHeader>
    <q-linear-progress v-if="loading" indeterminate />
    <template v-else>
      <IssueList :issues="issues" :title="t('views.errors')" />
      <q-tabs v-model="tab" dense align="left" no-caps class="swp-tabs">
        <q-tab
          v-for="name in [
            'general',
            'query',
            'layout',
            'template',
            'filters',
            'pagination',
            'preview',
          ]"
          :key="name"
          :name="name"
          :label="t(`views.tabs.${name}`)"
          :data-testid="`tab-${name}`"
        />
      </q-tabs>
      <q-tab-panels v-model="tab" animated class="swp-panels">
        <q-tab-panel name="general" class="swp-form">
          <q-input
            v-model="title"
            outlined
            dense
            :label="t('views.general.name')"
            data-testid="view-title"
          />
          <p v-if="viewId !== null" class="swp-muted">
            {{ t('views.general.shortcodeHint') }} <code>[sw_postlist id="{{ viewId }}"]</code>
          </p>
        </q-tab-panel>

        <q-tab-panel name="query" class="swp-form">
          <q-select
            v-model="source"
            outlined
            dense
            emit-value
            map-options
            :options="sourceOptions"
            :label="t('views.query.source')"
            :hint="t('views.query.sourceHint')"
            data-testid="view-source"
          />
          <h3>{{ t('views.query.taxonomies') }}</h3>
          <div v-for="(row, index) in taxonomies" :key="index" class="swp-row-fields">
            <q-select
              v-model="row.taxonomy"
              outlined
              dense
              :options="taxonomyOptions"
              :label="t('views.query.taxonomy')"
            />
            <q-input v-model="row.terms" outlined dense :label="t('views.query.terms')" />
            <q-select
              v-model="row.operator"
              outlined
              dense
              emit-value
              map-options
              :options="
                TERM_OPERATORS.map((value) => ({
                  value,
                  label: t(`views.query.operators.${value}`),
                }))
              "
              :label="t('views.query.operator')"
            />
            <q-btn
              flat
              dense
              no-caps
              color="negative"
              :label="t('views.query.remove')"
              @click="taxonomies.splice(index, 1)"
            />
          </div>
          <q-btn
            flat
            no-caps
            :label="t('views.query.addTaxonomy')"
            :disable="taxonomyOptions.length === 0"
            @click="addTaxonomy"
          />
          <q-input v-model="authors" outlined dense :label="t('views.query.authors')" />
          <div class="swp-row-fields">
            <q-input
              v-model="dateAfter"
              outlined
              dense
              type="date"
              :label="t('views.query.dateAfter')"
            />
            <q-input
              v-model="dateBefore"
              outlined
              dense
              type="date"
              :label="t('views.query.dateBefore')"
            />
          </div>
          <h3>{{ t('views.query.meta') }}</h3>
          <div v-for="(row, index) in meta" :key="index" class="swp-row-fields">
            <q-input v-model="row.key" outlined dense :label="t('views.query.metaKey')" />
            <q-select
              v-model="row.compare"
              outlined
              dense
              :options="[...META_COMPARES]"
              :label="t('views.query.metaCompare')"
            />
            <q-input v-model="row.value" outlined dense :label="t('views.query.metaValue')" />
            <q-btn
              flat
              dense
              no-caps
              color="negative"
              :label="t('views.query.remove')"
              @click="meta.splice(index, 1)"
            />
          </div>
          <q-btn flat no-caps :label="t('views.query.addMeta')" @click="addMeta" />
          <q-input v-model="include" outlined dense :label="t('views.query.include')" />
          <q-input v-model="exclude" outlined dense :label="t('views.query.exclude')" />
          <div class="swp-row-fields">
            <q-input
              v-model.number="perPage"
              outlined
              dense
              type="number"
              min="1"
              max="100"
              :label="t('views.query.perPage')"
              data-testid="view-per-page"
            />
            <q-input
              v-model.number="offset"
              outlined
              dense
              type="number"
              min="0"
              :label="t('views.query.offset')"
            />
          </div>
          <div class="swp-row-fields">
            <q-select
              v-model="orderby"
              outlined
              dense
              emit-value
              map-options
              :options="
                ORDERBY.map((value) => ({ value, label: t(`views.query.orderbyOptions.${value}`) }))
              "
              :label="t('views.query.orderby')"
            />
            <q-select
              v-model="order"
              outlined
              dense
              emit-value
              map-options
              :options="
                (['asc', 'desc'] as const).map((value) => ({
                  value,
                  label: t(`views.query.orders.${value}`),
                }))
              "
              :label="t('views.query.order')"
            />
            <q-input
              v-if="orderby.startsWith('meta_')"
              v-model="orderMetaKey"
              outlined
              dense
              :label="t('views.query.orderMetaKey')"
            />
          </div>
          <q-select
            v-model="context"
            outlined
            dense
            emit-value
            map-options
            :options="
              CONTEXT_MODES.map((value) => ({ value, label: t(`views.query.contexts.${value}`) }))
            "
            :label="t('views.query.context')"
          />
        </q-tab-panel>

        <q-tab-panel name="layout" class="swp-form">
          <q-select
            v-model="layoutType"
            outlined
            dense
            emit-value
            map-options
            :options="
              catalog.layouts.map(({ id: value, label }) => ({
                value,
                label: te(`views.layout.types.${value}`) ? t(`views.layout.types.${value}`) : label,
              }))
            "
            :label="t('views.layout.type')"
            data-testid="view-layout"
          />
          <table class="swp-table swp-table--compact">
            <thead>
              <tr>
                <th />
                <th v-for="device in DEVICES" :key="device">
                  {{ t(`preview.devices.${device}`) }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="layoutType !== 'list'">
                <th scope="row">{{ t('views.layout.columns') }}</th>
                <td v-for="device in DEVICES" :key="device">
                  <q-input
                    v-model.number="columns[device]"
                    dense
                    outlined
                    type="number"
                    min="1"
                    max="6"
                    :aria-label="`${t('views.layout.columns')} ${t(`preview.devices.${device}`)}`"
                  />
                </td>
              </tr>
              <tr>
                <th scope="row">{{ t('views.layout.gap') }}</th>
                <td v-for="device in DEVICES" :key="device">
                  <q-input
                    v-model.number="gap[device]"
                    dense
                    outlined
                    type="number"
                    min="0"
                    max="64"
                    :aria-label="`${t('views.layout.gap')} ${t(`preview.devices.${device}`)}`"
                  />
                </td>
              </tr>
              <tr>
                <th scope="row">{{ t('views.layout.fontSize') }}</th>
                <td v-for="device in DEVICES" :key="device">
                  <q-input
                    v-model.number="fontSize[device]"
                    dense
                    outlined
                    type="number"
                    min="50"
                    max="200"
                    :aria-label="`${t('views.layout.fontSize')} ${t(`preview.devices.${device}`)}`"
                  />
                </td>
              </tr>
              <tr>
                <th scope="row">{{ t('views.layout.hidden') }}</th>
                <td v-for="device in DEVICES" :key="device">
                  <q-checkbox
                    v-model="hidden[device]"
                    :aria-label="`${t('views.layout.hidden')} ${t(`preview.devices.${device}`)}`"
                  />
                </td>
              </tr>
              <tr v-if="layoutType === 'slider'">
                <th scope="row">{{ t('views.layout.slides') }}</th>
                <td v-for="device in DEVICES" :key="device">
                  <q-input
                    v-model.number="slides[device]"
                    type="number"
                    outlined
                    dense
                    min="1"
                    max="6"
                    :aria-label="`${t('views.layout.slides')} ${t(`preview.devices.${device}`)}`"
                  />
                </td>
              </tr>
            </tbody>
          </table>
          <div v-if="layoutType === 'slider'" class="swp-form" data-testid="slider-options">
            <q-toggle v-model="autoplay" :label="t('views.layout.autoplay')" />
            <q-input
              v-if="autoplay"
              v-model.number="interval"
              type="number"
              outlined
              dense
              min="2000"
              max="20000"
              step="500"
              :label="t('views.layout.interval')"
            />
            <q-toggle v-model="loop" :label="t('views.layout.loop')" />
            <q-toggle v-model="arrows" :label="t('views.layout.arrows')" />
            <q-toggle v-model="dots" :label="t('views.layout.dots')" />
          </div>
          <q-select
            v-model="aspect"
            outlined
            dense
            emit-value
            map-options
            :options="
              ['auto', '1/1', '4/3', '3/2', '16/9', '2/1'].map((value) => ({
                value,
                label: value === 'auto' ? t('views.layout.aspectAuto') : value.replace('/', ':'),
              }))
            "
            :label="t('views.layout.aspect')"
          />
          <q-input
            v-model="accent"
            outlined
            dense
            :label="t('views.layout.accent')"
            :hint="t('views.layout.accentHint')"
          />
        </q-tab-panel>

        <q-tab-panel name="template" class="swp-form">
          <p class="swp-muted">{{ t('views.template.hint') }}</p>
          <q-select
            v-model="containerTemplate"
            outlined
            dense
            emit-value
            map-options
            :options="templateChoices(containerOptions)"
            :label="t('views.template.container')"
            data-testid="view-container-template"
          />
          <q-select
            v-model="entryTemplate"
            outlined
            dense
            emit-value
            map-options
            :options="templateChoices(entryOptions)"
            :label="t('views.template.entry')"
            data-testid="view-entry-template"
          />
          <q-btn
            v-if="entryTemplate !== null"
            flat
            no-caps
            :label="t('views.template.openTemplate')"
            :to="`/templates/${entryTemplate}`"
          />
        </q-tab-panel>

        <q-tab-panel name="filters" class="swp-form">
          <FilterEditor v-model="filters" :taxonomies="taxonomyOptions" />
        </q-tab-panel>

        <q-tab-panel name="pagination" class="swp-form">
          <q-select
            v-model="paginationMode"
            outlined
            dense
            emit-value
            map-options
            :options="
              PAGINATION_MODES.map((value) => ({
                value,
                label: t(`views.pagination.modes.${value}`),
              }))
            "
            :label="t('views.pagination.mode')"
            data-testid="view-pagination"
          />
          <p class="swp-muted">{{ t('views.pagination.hint') }}</p>
        </q-tab-panel>

        <q-tab-panel name="preview">
          <q-btn
            outline
            no-caps
            :label="t('views.preview.refresh')"
            :disable="clientIssues.length > 0"
            data-testid="refresh-preview"
            @click="loadPreview"
          />
          <p v-if="preview && preview.total != null" class="swp-muted">
            {{ t('views.preview.total', { count: preview.total }) }}
          </p>
          <DevicePreview
            :html="preview?.html ?? ''"
            :css="preview?.css ?? ''"
            :loading="previewLoading"
            :error="preview?.error ?? null"
          />
        </q-tab-panel>
      </q-tab-panels>
    </template>
  </section>
</template>
