import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { quasar, transformAssetUrls } from '@quasar/vite-plugin';
import { swfScope } from './tools/postcssScope.ts';

const outDir = resolve(import.meta.dirname, '../../assets/admin');

/** Admin SPA build. The frontend runtime has its own config (vite.frontend.config.ts). */
export default defineConfig({
  base: './',
  plugins: [vue({ template: { transformAssetUrls } }), quasar()],
  resolve: {
    alias: {
      '@': resolve(import.meta.dirname, 'src'),
      '@shared': resolve(import.meta.dirname, 'shared'),
    },
  },
  css: { postcss: { plugins: [swfScope()] } },
  server: { port: 5174, host: '127.0.0.1' },
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
        // Keep side effects in the tiny entry only: WordPress versions app.js with a
        // query string, while lazy chunks import shared modules without that query.
        codeSplitting: { groups: [{ name: 'bootstrap', test: /[\\/]src[\\/]bootstrap\.ts$/ }] },
        entryFileNames: 'app.js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: (info) => {
          const names = info.names ?? (info.name ? [info.name] : []);
          return names.some((n) => n.endsWith('.css')) ? 'app.css' : 'assets/[name]-[hash][extname]';
        },
      },
    },
  },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.ts'],
    // Mounting real Quasar views under parallel V8 coverage can exceed the 5 s default on CI.
    testTimeout: 20_000,
    css: false,
    coverage: {
      provider: 'v8',
      allowExternal: true,
      reporter: ['text', 'clover', 'lcov', 'json-summary'],
      reportsDirectory: 'coverage',
      include: ['src/**/*.{ts,vue}', 'shared/**/*.ts', 'frontend/**/*.ts', '../../assets/js/sw-forms-updates.js'],
      exclude: ['**/*.d.ts'],
      thresholds: {
        'frontend/main.ts': { lines: 85 },
        'frontend/timePicker.ts': { lines: 95 },
        'frontend/imagePreviews.ts': { lines: 95 },
        'frontend/imageCrop.ts': { lines: 95 },
        'frontend/mediaFiles.ts': { lines: 95 },
        'frontend/mediaInputs.ts': { lines: 95 },
        'frontend/recordingControl.ts': { lines: 95 },
        'frontend/signaturePad.ts': { lines: 95 },
        'frontend/fileValues.ts': { lines: 100 },
        'src/api/client.ts': { lines: 95 },
        'src/features/entries/EntryFields.vue': { lines: 100 },
        'src/features/forms/templateBrowser.ts': { lines: 95 },
        'src/features/forms/TemplateDetail.vue': { lines: 95 },
        'src/features/forms/FormLibrary.vue': { lines: 85 },
      },
    },
    server: { deps: { inline: ['quasar'] } },
  },
});
