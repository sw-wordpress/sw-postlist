<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { api } from '@/api/endpoints';
import { ApiError } from '@/api/client';
import type { TemplateSummary } from '@/api/types';
import { useNotify } from '@/composables/useNotify';

const { t } = useI18n();
const notify = useNotify();
const templates = ref<TemplateSummary[]>([]);
const loading = ref(true);

async function load(): Promise<void> {
  loading.value = true;
  try {
    templates.value = (await api.templates.list()).templates;
  } catch {
    notify.failure(t('app.loadFailed'));
  } finally {
    loading.value = false;
  }
}

async function duplicate(template: TemplateSummary): Promise<void> {
  try {
    await api.templates.duplicate(template.id);
    notify.success(t('templates.duplicated'));
    await load();
  } catch (error) {
    notify.failure(error instanceof ApiError ? error.message : t('app.saveFailed'));
  }
}

async function trash(template: TemplateSummary): Promise<void> {
  if (template.used_by > 0) {
    notify.failure(t('templates.trashBlocked'));
    return;
  }
  if (!window.confirm(t('app.confirmTrash'))) return;
  try {
    await api.templates.trash(template.id);
    notify.success(t('templates.trashed'));
    await load();
  } catch (error) {
    notify.failure(
      error instanceof ApiError && error.code === 'template_in_use'
        ? t('templates.trashBlocked')
        : t('app.saveFailed'),
    );
  }
}

onMounted(load);
</script>

<template>
  <section class="swp-page">
    <PageHeader :title="t('templates.title')">
      <q-btn
        color="primary"
        no-caps
        :label="t('templates.newEntry')"
        to="/templates/new?role=entry"
        data-testid="new-entry-template"
      />
      <q-btn
        outline
        no-caps
        :label="t('templates.newContainer')"
        to="/templates/new?role=container"
      />
    </PageHeader>
    <q-linear-progress v-if="loading" indeterminate />
    <p v-else-if="templates.length === 0" class="swp-muted">{{ t('templates.empty') }}</p>
    <div v-else class="swp-table-wrap">
      <table class="swp-table" data-testid="templates-table">
        <thead>
          <tr>
            <th>{{ t('templates.columns.title') }}</th>
            <th>{{ t('templates.columns.role') }}</th>
            <th>{{ t('templates.columns.usedBy') }}</th>
            <th>{{ t('templates.columns.modified') }}</th>
            <th>
              <span class="swp-sr-only">{{ t('app.edit') }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="template in templates" :key="template.id">
            <td>
              <router-link :to="`/templates/${template.id}`">{{ template.title }}</router-link>
            </td>
            <td>{{ template.role === '' ? '' : t(`templates.roles.${template.role}`) }}</td>
            <td>{{ t('templates.usedByCount', { count: template.used_by }) }}</td>
            <td>{{ template.modified.slice(0, 10) }}</td>
            <td class="swp-table__actions">
              <q-btn
                flat
                round
                dense
                icon="r_edit"
                :to="`/templates/${template.id}`"
                :aria-label="t('app.edit')"
                :data-testid="`edit-template-${template.id}`"
              >
                <q-tooltip>{{ t('app.edit') }}</q-tooltip>
              </q-btn>
              <q-btn
                flat
                round
                dense
                icon="r_library_add"
                :aria-label="t('templates.duplicate')"
                :data-testid="`duplicate-template-${template.id}`"
                @click="duplicate(template)"
              >
                <q-tooltip>{{ t('templates.duplicate') }}</q-tooltip>
              </q-btn>
              <q-btn
                flat
                round
                dense
                color="negative"
                icon="r_delete"
                :aria-label="t('app.delete')"
                @click="trash(template)"
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
