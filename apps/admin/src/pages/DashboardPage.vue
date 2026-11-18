<script setup lang="ts">
import PageHeader from '@/components/ui/PageHeader.vue';
import StatTile from '@/components/ui/StatTile.vue';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { api } from '@/api/endpoints';
import { useNotify } from '@/composables/useNotify';

const { t } = useI18n();
const notify = useNotify();
const counts = ref({ views: 0, templates: 0, problems: 0 });
const loading = ref(true);

onMounted(async () => {
  try {
    const [views, templates, diagnostics] = await Promise.all([
      api.views.list(),
      api.templates.list(),
      api.system.diagnostics(),
    ]);
    counts.value = {
      views: views.views.length,
      templates: templates.templates.length,
      problems: diagnostics.registrations.length,
    };
  } catch {
    notify.failure(t('app.loadFailed'));
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <section class="swp-page">
    <PageHeader :title="t('nav.dashboard')" :subtitle="t('dashboard.intro')" />
    <q-linear-progress v-if="loading" indeterminate />
    <div v-else class="swp-cards">
      <router-link to="/views" class="swp-stat-link" data-testid="stat-views">
        <StatTile :label="t('dashboard.views')" :value="String(counts.views)" icon="r_view_quilt" />
      </router-link>
      <router-link to="/templates" class="swp-stat-link" data-testid="stat-templates">
        <StatTile
          :label="t('dashboard.templates')"
          :value="String(counts.templates)"
          icon="r_code"
        />
      </router-link>
      <router-link to="/diagnostics" class="swp-stat-link" data-testid="stat-problems">
        <StatTile
          :label="t('dashboard.problems')"
          :value="String(counts.problems)"
          icon="r_monitor_heart"
          :tone="counts.problems > 0 ? 'warning' : 'success'"
        />
      </router-link>
    </div>
    <p v-if="!loading && counts.problems === 0" class="swp-muted">{{ t('dashboard.allClear') }}</p>
    <div class="swp-actions">
      <q-btn color="primary" no-caps :label="t('dashboard.newView')" to="/views/new" />
      <q-btn outline no-caps :label="t('dashboard.newTemplate')" to="/templates/new" />
    </div>
  </section>
</template>
