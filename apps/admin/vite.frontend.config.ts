import { resolve } from 'node:path';
import { defineConfig } from 'vite';
import { swfScope } from './tools/postcssScope';

/**
 * Frontend runtime for published forms: one self-contained IIFE plus a stylesheet.
 * Output: assets/frontend/form.js and form.css.
 */
export default defineConfig({
  css: { postcss: { plugins: [swfScope({ scope: '.swf', filePatterns: ['/node_modules/tom-select/'] })] } },
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
