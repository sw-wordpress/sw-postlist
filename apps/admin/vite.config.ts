import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { quasar, transformAssetUrls } from '@quasar/vite-plugin';
import { swpScope } from './tools/postcssScope.ts';

const outDir = resolve(import.meta.dirname, '../../assets/admin');

/**
 * Dev server: `SWP_WP_ORIGIN=https://my-site.test npm run dev` proxies `/wp-json` to a real WordPress.
 * Production output goes to ../../assets/admin.
 */
const wpOrigin = process.env.SWP_WP_ORIGIN;

export default defineConfig(() => ({
  base: './',
  plugins: [vue({ template: { transformAssetUrls } }), quasar()],
  resolve: { alias: { '@': resolve(import.meta.dirname, 'src') } },
  css: { postcss: { plugins: [swpScope()] } },
  server: {
    port: 5174,
    host: '127.0.0.1',
    proxy: wpOrigin
      ? { '/wp-json': { target: wpOrigin, changeOrigin: true, secure: false } }
      : undefined,
  },
  build: {
    outDir,
    emptyOutDir: true,
    manifest: true,
    cssCodeSplit: false,
    sourcemap: false,
    target: 'es2020',
    modulePreload: { polyfill: false },
    chunkSizeWarningLimit: 900,
    rolldownOptions: {
      input: { app: resolve(import.meta.dirname, 'src/main.ts') },
      output: {
        entryFileNames: 'app.js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: (info) => {
          const names = info.names ?? (info.name ? [info.name] : []);
          return names.some((n) => n.endsWith('.css'))
            ? 'app.css'
            : 'assets/[name]-[hash][extname]';
        },
      },
    },
  },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.ts'],
    css: false,
    setupFiles: ['tests/setup.ts'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'text-summary', 'lcov'],
      reportsDirectory: 'coverage',
      allowExternal: true,
      include: [
        'src/**/*.{ts,js,vue}',
        'tools/**/*.ts',
        '../../blocks/**/*.js',
        '../../assets/js/**/*.js',
      ],
      exclude: ['**/*.d.ts', 'src/types/**'],
      // scripts/check-coverage.mjs additionally enforces lines > 80 % exactly.
      thresholds: { lines: 80.01 },
    },
    server: { deps: { inline: ['quasar'] } },
  },
}));
