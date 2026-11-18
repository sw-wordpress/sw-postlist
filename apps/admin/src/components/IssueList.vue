<script setup lang="ts">
import { useI18n } from 'vue-i18n';

export interface DisplayIssue {
  code: string;
  where?: string;
  message?: string;
}

defineProps<{ issues: DisplayIssue[]; title: string }>();
const { t, te } = useI18n();

function label(issue: DisplayIssue): string {
  const key = `issues.${issue.code}`;
  return te(key) ? t(key) : (issue.message ?? issue.code);
}
</script>

<template>
  <section v-if="issues.length > 0" class="swp-issues" role="alert" :aria-label="title">
    <h3>{{ title }}</h3>
    <ul>
      <li v-for="(issue, index) in issues" :key="index">
        <code v-if="issue.where">{{ issue.where }}</code>
        {{ label(issue) }}
        <small>({{ issue.code }})</small>
      </li>
    </ul>
  </section>
</template>
