import { resolve } from 'node:path';
import { defineConfig } from 'vite';

/**
 * Frontend runtime for published forms: one small, dependency free IIFE plus a stylesheet.
 * Output: assets/frontend/form.js and form.css.
 */
export default defineConfig({
  build: {
    outDir: resolve(__dirname, '../../assets/frontend'),
    emptyOutDir: true,
    target: 'es2019',
    sourcemap: false,
    cssCodeSplit: false,
    lib: {
      entry: resolve(__dirname, 'frontend/main.ts'),
      name: 'SWForms',
      formats: ['iife'],
      fileName: () => 'form.js',
    },
    rollupOptions: {
      output: { assetFileNames: () => 'form.css' },
    },
  },
});
