import { fileURLToPath, URL } from 'node:url';
import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { quasar, transformAssetUrls } from '@quasar/vite-plugin';
import { swfScope } from './tools/postcssScope';

/** Ship only the woff2 icon font (all supported browsers); drops the legacy woff. */
function woff2Only() {
  return {
    name: 'swf-woff2-only',
    enforce: 'pre' as const,
    transform(code: string, id: string) {
      if (!id.includes('material-icons-round.css')) return null;
      return code.replace(/,\s*url\([^)]*\.woff\d?\??[^)]*\)\s*format\((['"])woff\1\)/g, '');
    },
  };
}

const outDir = resolve(__dirname, '../../assets/admin');

/** Admin SPA build. The frontend runtime has its own config (vite.frontend.config.ts). */
export default defineConfig({
  base: './',
  plugins: [woff2Only(), vue({ template: { transformAssetUrls } }), quasar()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      '@shared': fileURLToPath(new URL('./shared', import.meta.url)),
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
    rollupOptions: {
      input: { app: resolve(__dirname, 'src/main.ts') },
      output: {
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
    css: false,
    coverage: {
      provider: 'v8',
      reporter: ['text', 'clover', 'lcov', 'json-summary'],
      reportsDirectory: 'coverage',
      include: ['src/**/*.{ts,vue}', 'shared/**/*.ts', 'frontend/**/*.ts'],
      exclude: ['**/*.d.ts'],
      thresholds: {
        'frontend/main.ts': { lines: 85 },
        'frontend/imagePreviews.ts': { lines: 95 },
        'frontend/imageCrop.ts': { lines: 95 },
        'frontend/mediaFiles.ts': { lines: 95 },
        'frontend/mediaInputs.ts': { lines: 95 },
        'frontend/recordingControl.ts': { lines: 95 },
        'frontend/signaturePad.ts': { lines: 95 },
        'frontend/fileValues.ts': { lines: 100 },
        'src/api/client.ts': { lines: 95 },
        'src/features/entries/EntryFields.vue': { lines: 100 },
      },
    },
    server: { deps: { inline: ['quasar'] } },
  },
});
