<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { getConfig } from '@/config';

const props = defineProps<{ html: string; css: string; loading: boolean; error: string | null }>();
const { t } = useI18n();

const DEVICES = { desktop: 1024, tablet: 768, mobile: 375 } as const;
type Device = keyof typeof DEVICES;
const device = ref<Device>('desktop');
const deviceNames = Object.keys(DEVICES) as Device[];

/**
 * The preview runs in a sandboxed frame (no scripts) with the frontend stylesheet, so theme and admin styles cannot
 * interfere and the template output is judged as visitors will see it.
 */
const document_ = computed(() => {
  const stylesheet = `${getConfig().pluginUrl}assets/css/frontend.css`;
  return `<!doctype html><html><head><meta charset="utf-8"><base target="_blank"><link rel="stylesheet" href="${stylesheet}"><style>body{margin:12px;font-family:system-ui,sans-serif}${props.css}</style></head><body>${props.html}</body></html>`;
});
</script>

<template>
  <section class="swp-preview" :aria-label="t('preview.title')">
    <div class="swp-preview__bar" role="group" :aria-label="t('preview.device')">
      <q-btn
        v-for="name in deviceNames"
        :key="name"
        :label="t(`preview.devices.${name}`)"
        :color="device === name ? 'primary' : undefined"
        :outline="device !== name"
        size="sm"
        no-caps
        :aria-pressed="device === name"
        :data-device="name"
        @click="device = name"
      />
      <span class="swp-preview__width">{{ DEVICES[device] }} px</span>
    </div>
    <q-banner v-if="error" class="swp-banner" role="alert">{{
      t('preview.error', { code: error })
    }}</q-banner>
    <q-linear-progress v-if="loading" indeterminate />
    <div class="swp-preview__frame" :style="{ maxWidth: `${DEVICES[device]}px` }">
      <iframe
        :srcdoc="document_"
        sandbox=""
        :title="t('preview.title')"
        data-testid="preview-frame"
        loading="lazy"
      />
    </div>
  </section>
</template>
