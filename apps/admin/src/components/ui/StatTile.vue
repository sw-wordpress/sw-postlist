<script setup lang="ts">
withDefaults(
  defineProps<{
    label: string;
    value?: string;
    hint?: string;
    icon?: string;
    loading?: boolean;
    tone?: 'default' | 'success' | 'warning' | 'danger';
  }>(),
  { value: undefined, hint: undefined, icon: undefined, loading: false, tone: 'default' },
);
</script>

<template>
  <div class="swp-stat swp-stat-tile" :class="`swp-stat--${tone}`">
    <div class="swp-stat__top">
      <span class="swp-stat__label">{{ label }}</span>
      <q-icon v-if="icon" :name="icon" size="20px" class="swp-stat__icon" aria-hidden="true" />
    </div>
    <template v-if="loading">
      <q-skeleton type="text" width="60%" height="32px" />
      <q-skeleton type="text" width="40%" />
    </template>
    <template v-else>
      <div class="swp-stat__value swp-num">
        <slot
          ><strong>{{ value ?? '–' }}</strong></slot
        >
      </div>
      <div v-if="hint || $slots.hint" class="swp-stat__hint">
        <slot name="hint">{{ hint }}</slot>
      </div>
    </template>
  </div>
</template>

<style scoped>
.swp-stat {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 18px 20px;
  background: var(--swp-surface);
  border: 1px solid var(--swp-border);
  border-radius: var(--swp-radius);
  box-shadow: var(--swp-shadow-1);
  min-width: 0;
}
.swp-stat__top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}
.swp-stat__label {
  font-size: 13px;
  font-weight: 600;
  color: var(--swp-muted);
}
.swp-stat__icon {
  color: var(--swp-primary);
}
.swp-stat__value {
  font-size: 26px;
  font-weight: 700;
  line-height: 1.2;
  letter-spacing: -0.01em;
  overflow-wrap: anywhere;
}
.swp-stat__hint {
  font-size: 13px;
  color: var(--swp-muted);
}
.swp-stat--success .swp-stat__icon {
  color: var(--swp-success);
}
.swp-stat--warning .swp-stat__icon {
  color: var(--swp-warning);
}
.swp-stat--danger .swp-stat__icon {
  color: var(--swp-danger);
}
</style>
