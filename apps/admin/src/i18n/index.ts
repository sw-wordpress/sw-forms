import { createI18n } from 'vue-i18n';
import { resolveUiLocale } from '@/config';
import de from './de';
import en from './en';

/** Compile-time checked message keys for `t()`. */
export type MessageSchema = typeof en;
declare module 'vue-i18n' {
  // eslint-disable-next-line @typescript-eslint/no-empty-object-type
  export interface DefineLocaleMessage extends MessageSchema {}
}

export function createAppI18n(wpLocale: string) {
  return createI18n({
    legacy: false,
    locale: resolveUiLocale(wpLocale),
    fallbackLocale: 'en',
    messages: { de, en },
    missingWarn: import.meta.env.DEV,
    fallbackWarn: false,
  });
}
