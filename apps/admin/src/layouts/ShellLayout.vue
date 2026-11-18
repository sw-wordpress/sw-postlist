<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { getConfig } from '@/config';
import { NAV_ITEMS } from '@/router';
import { useAppStore } from '@/stores/app';

const { t } = useI18n();
const route = useRoute();
const app = useAppStore();
const config = getConfig();
const main = ref<HTMLElement | null>(null);

function focusMain(): void {
  main.value?.focus();
}

const transitionKey = computed(() => String(route.name ?? ''));
</script>

<template>
  <div class="swp-shell" :class="{ 'swp-shell--collapsed': app.railCollapsed }">
    <a href="#swp-main" class="swp-skip" @click.prevent="focusMain">{{ t('app.skipToContent') }}</a>

    <aside class="swp-rail">
      <div class="swp-rail__brand">
        <span class="swp-rail__logo" aria-hidden="true"
          ><q-icon name="r_view_quilt" size="22px"
        /></span>
        <span class="swp-rail__name">
          <strong>Postlist</strong>
          <small v-if="config.version" class="swp-num">v{{ config.version }}</small>
        </span>
      </div>

      <nav class="swp-rail__nav" :aria-label="t('app.mainNavigation')">
        <ul>
          <li v-for="item in NAV_ITEMS" :key="item.name">
            <router-link
              :to="item.to"
              class="swp-nav-link swp-nav__item"
              :active-class="item.to === '/' ? '' : 'swp-nav-link--active'"
              exact-active-class="swp-nav-link--active"
            >
              <q-icon :name="item.icon" size="22px" aria-hidden="true" />
              <span class="swp-nav-link__label swp-nav__label">{{ t(item.labelKey) }}</span>
              <q-tooltip
                v-if="app.railCollapsed"
                anchor="center right"
                self="center left"
                :offset="[8, 0]"
                >{{ t(item.labelKey) }}</q-tooltip
              >
            </router-link>
          </li>
        </ul>
      </nav>

      <div class="swp-rail__tools">
        <q-btn
          flat
          round
          dense
          :icon="app.isDark ? 'r_light_mode' : 'r_dark_mode'"
          :aria-label="app.isDark ? t('app.switchToLight') : t('app.switchToDark')"
          :aria-pressed="app.isDark"
          @click="app.toggleTheme()"
        >
          <q-tooltip>{{ app.isDark ? t('app.switchToLight') : t('app.switchToDark') }}</q-tooltip>
        </q-btn>
        <q-btn
          class="swp-rail__collapse"
          flat
          round
          dense
          :icon="
            app.railCollapsed ? 'r_keyboard_double_arrow_right' : 'r_keyboard_double_arrow_left'
          "
          :aria-label="app.railCollapsed ? t('app.expandNav') : t('app.collapseNav')"
          :aria-expanded="!app.railCollapsed"
          @click="app.railCollapsed = !app.railCollapsed"
        />
      </div>
    </aside>

    <main id="swp-main" ref="main" class="swp-main" tabindex="-1">
      <router-view v-slot="{ Component }">
        <transition name="swp-fade" mode="out-in">
          <component :is="Component" :key="transitionKey" />
        </transition>
      </router-view>
    </main>

    <div class="swp-sr-only" role="status" aria-live="polite" aria-atomic="true">
      {{ app.announcement }}
    </div>
  </div>
</template>

<style scoped>
.swp-shell {
  --rail-w: 244px;
  --bar-h: var(--wp-admin--admin-bar--height, 32px);
  display: grid;
  grid-template-columns: var(--rail-w) minmax(0, 1fr);
  min-height: calc(100vh - var(--bar-h));
  background: var(--swp-bg);
  position: relative;
}
.swp-shell--collapsed {
  --rail-w: 76px;
}
.swp-skip {
  position: absolute;
  left: 12px;
  top: 10px;
  z-index: 100;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
  padding: 8px 14px;
  border-radius: 8px;
  background: var(--swp-primary);
  color: #fff !important;
  font-weight: 600;
}
.swp-skip:focus {
  width: auto;
  height: auto;
  overflow: visible;
  clip-path: none;
}
.swp-rail {
  position: sticky;
  top: var(--bar-h);
  align-self: start;
  height: calc(100vh - var(--bar-h));
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 18px 14px;
  background: var(--swp-rail-bg);
  border-right: 1px solid var(--swp-border);
  transition: width 0.2s ease;
}
.swp-rail__brand {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 4px 8px 16px;
  min-width: 0;
}
.swp-rail__logo {
  display: grid;
  place-items: center;
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: 12px;
  color: #fff;
  background: var(--swp-gradient);
  box-shadow: 0 6px 16px rgba(79, 70, 229, 0.35);
}
.swp-rail__name {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
  min-width: 0;
}
.swp-rail__name strong {
  font-size: 16px;
  letter-spacing: -0.01em;
}
.swp-rail__name small {
  color: var(--swp-muted);
  font-size: 12px;
}
.swp-rail__nav {
  flex: 1;
  overflow-y: auto;
}
.swp-rail__nav ul {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.swp-nav-link {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 12px;
  color: var(--swp-muted) !important;
  font-weight: 600;
  text-decoration: none !important;
  transition:
    background-color 0.15s ease,
    color 0.15s ease;
}
.swp-nav-link:hover {
  background: var(--swp-surface-3);
  color: var(--swp-text) !important;
}
.swp-nav-link--active {
  background: var(--swp-primary-bg);
  color: var(--swp-primary-text) !important;
}
.swp-nav-link--active::before {
  content: '';
  position: absolute;
  left: -14px;
  top: 10px;
  bottom: 10px;
  width: 4px;
  border-radius: 0 4px 4px 0;
  background: var(--swp-gradient);
}
.swp-nav-link__badge {
  margin-left: auto;
  min-width: 22px;
  padding: 0 7px;
  border-radius: 999px;
  text-align: center;
  font-size: 12px;
  line-height: 22px;
  color: #fff;
  background: var(--swp-gradient);
}
.swp-rail__tools {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 4px 0;
  border-top: 1px solid var(--swp-border);
  color: var(--swp-muted);
}
.swp-shell--collapsed .swp-rail__name,
.swp-shell--collapsed .swp-nav-link__label {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
}
.swp-shell--collapsed .swp-nav-link {
  justify-content: center;
}
.swp-shell--collapsed .swp-nav-link__badge {
  position: absolute;
  top: 2px;
  right: 2px;
  margin: 0;
  min-width: 18px;
  line-height: 18px;
  font-size: 11px;
  padding: 0 5px;
}
.swp-shell--collapsed .swp-rail__tools {
  flex-direction: column;
  gap: 4px;
}
.swp-shell--collapsed .swp-rail__brand {
  justify-content: center;
  padding-inline: 0;
}
.swp-main {
  min-width: 0;
  padding: 28px clamp(16px, 3vw, 40px) 48px;
  outline: none;
}
.swp-main > * {
  max-width: 1320px;
  margin-inline: auto;
}

@media (max-width: 860px) {
  .swp-shell,
  .swp-shell--collapsed {
    grid-template-columns: minmax(0, 1fr);
    grid-template-rows: auto 1fr;
  }
  .swp-rail {
    position: sticky;
    z-index: 20;
    height: auto;
    flex-direction: row;
    align-items: center;
    gap: 6px;
    padding: 8px 10px;
    border-right: 0;
    border-bottom: 1px solid var(--swp-border);
  }
  .swp-rail__brand {
    padding: 0;
  }
  .swp-rail__name {
    display: none;
  }
  .swp-rail__nav {
    flex: 1;
    min-width: 0;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: thin;
  }
  .swp-rail__nav ul {
    flex-direction: row;
  }
  .swp-nav-link {
    flex-direction: column;
    gap: 2px;
    padding: 6px 10px;
    font-size: 11px;
  }
  .swp-shell--collapsed .swp-nav-link__label {
    position: static;
    width: auto;
    height: auto;
    clip: auto;
  }
  .swp-nav-link--active::before {
    left: 10px;
    right: 10px;
    top: auto;
    bottom: 0;
    width: auto;
    height: 3px;
    border-radius: 3px 3px 0 0;
  }
  .swp-nav-link__badge {
    position: absolute;
    top: 0;
    right: 2px;
    margin: 0;
    min-width: 18px;
    line-height: 18px;
    font-size: 11px;
    padding: 0 5px;
  }
  .swp-rail__tools {
    border-top: 0;
    padding: 0;
    flex-direction: row;
  }
  .swp-rail__collapse {
    display: none;
  }
  .swp-main {
    padding-top: 20px;
  }
}
</style>
