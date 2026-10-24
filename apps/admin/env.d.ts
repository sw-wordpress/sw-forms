/// <reference types="vite/client" />

import type { SwfConfig } from './src/types/config';

declare global {
  interface Window {
    swFormsConfig?: SwfConfig;
  }
}

declare module '*.vue' {
  import type { DefineComponent } from 'vue';
  const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>;
  export default component;
}
