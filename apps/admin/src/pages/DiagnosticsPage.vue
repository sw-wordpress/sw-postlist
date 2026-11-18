<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { api } from '@/api/endpoints';
import type { DiagnosticsPayload } from '@/api/types';
import { useNotify } from '@/composables/useNotify';

const { t } = useI18n();
const notify = useNotify();
const data = ref<DiagnosticsPayload>({ registrations: [], log: [] });
const loading = ref(true);

onMounted(async () => {
  try {
    data.value = await api.system.diagnostics();
  } catch {
    notify.failure(t('app.loadFailed'));
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <section class="swp-page">
    <PageHeader :title="t('diagnostics.title')" />
    <q-linear-progress v-if="loading" indeterminate />
    <template v-else>
      <h2>{{ t('diagnostics.registrations') }}</h2>
      <p v-if="data.registrations.length === 0" class="swp-muted">
        {{ t('diagnostics.emptyRegistrations') }}
      </p>
      <table v-else class="swp-table" data-testid="registrations-table">
        <thead>
          <tr>
            <th>{{ t('diagnostics.columns.code') }}</th>
            <th>{{ t('diagnostics.columns.message') }}</th>
            <th>Source</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(entry, index) in data.registrations" :key="index">
            <td>
              <code>{{ entry.code }}</code>
            </td>
            <td>{{ entry.message }}</td>
            <td>
              <code>{{ entry.source }}</code>
            </td>
          </tr>
        </tbody>
      </table>
      <h2>{{ t('diagnostics.log') }}</h2>
      <p v-if="data.log.length === 0" class="swp-muted">{{ t('diagnostics.emptyLog') }}</p>
      <table v-else class="swp-table" data-testid="log-table">
        <thead>
          <tr>
            <th>{{ t('diagnostics.columns.time') }}</th>
            <th>{{ t('diagnostics.columns.code') }}</th>
            <th>{{ t('diagnostics.columns.message') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(entry, index) in [...data.log].reverse()" :key="index">
            <td>{{ entry.time }}</td>
            <td>
              <code>{{ entry.code }}</code>
            </td>
            <td>{{ entry.message }}</td>
          </tr>
        </tbody>
      </table>
    </template>
  </section>
</template>
