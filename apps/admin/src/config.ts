import type { SwfConfig } from '@/types/config';

let cached: SwfConfig | null = null;

function str(value: unknown, fallback: string): string {
  return typeof value === 'string' ? value : fallback;
}

function root(value: string): string {
  return value.endsWith('/') ? value : `${value}/`;
}

/** Validate/normalise the PHP-provided config. Throws if the REST root is missing. */
export function normaliseConfig(raw: Partial<SwfConfig> | undefined): SwfConfig {
  if (!raw || typeof raw.restRoot !== 'string' || raw.restRoot === '') {
    throw new Error('window.swFormsConfig.restRoot is missing');
  }
  return {
    restRoot: root(raw.restRoot),
    wpRestRoot: root(str(raw.wpRestRoot, raw.restRoot.replace(/swforms\/v1\/?$/, 'wp/v2/'))),
    nonce: str(raw.nonce, ''),
    locale: str(raw.locale, 'en_US'),
    version: str(raw.version, ''),
    adminUrl: str(raw.adminUrl, ''),
    capabilities: Array.isArray(raw.capabilities)
      ? raw.capabilities.filter((c): c is string => typeof c === 'string')
      : [],
    siteUrl: str(raw.siteUrl, ''),
  };
}

export function getConfig(): SwfConfig {
  if (cached === null) cached = normaliseConfig(window.swFormsConfig);
  return cached;
}

/** For tests. */
export function setConfigForTests(config: SwfConfig | null): void {
  cached = config;
}

export const UI_LOCALES = ['en', 'de'] as const;
export type UiLocale = (typeof UI_LOCALES)[number];

/** German for every `de*` WordPress locale (de_DE, de_DE_formal, de_AT ...), English otherwise. */
export function resolveUiLocale(locale: string): UiLocale {
  return locale.toLowerCase().startsWith('de') ? 'de' : 'en';
}
