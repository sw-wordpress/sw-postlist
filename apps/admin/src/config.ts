import type { SwpConfig } from '@/types/config';

let cached: SwpConfig | null = null;

function str(value: unknown, fallback: string): string {
  return typeof value === 'string' ? value : fallback;
}

function withSlash(value: string): string {
  return value === '' || value.endsWith('/') ? value : `${value}/`;
}

/** Validate/normalise the PHP-provided config. Throws if the REST root is missing. */
export function normaliseConfig(raw: Partial<SwpConfig> | undefined): SwpConfig {
  if (!raw || typeof raw.restRoot !== 'string' || raw.restRoot === '') {
    throw new Error('window.swpostlistConfig.restRoot is missing');
  }
  return {
    restRoot: withSlash(raw.restRoot),
    nonce: str(raw.nonce, ''),
    locale: str(raw.locale, 'en_US'),
    version: str(raw.version, ''),
    adminUrl: str(raw.adminUrl, ''),
    siteUrl: str(raw.siteUrl, ''),
    pluginUrl: withSlash(str(raw.pluginUrl, '')),
  };
}

export function getConfig(): SwpConfig {
  if (cached === null) {
    cached = normaliseConfig(window.swpostlistConfig);
  }
  return cached;
}

/** For tests. */
export function resetConfigForTests(): void {
  cached = null;
}

/** Only German and English are shipped. */
export const UI_LOCALES = ['en', 'de'] as const;
export type UiLocale = (typeof UI_LOCALES)[number];

/** German if the WordPress locale starts with `de` (de_DE, de_CH_informal …), otherwise English. */
export function resolveUiLocale(locale: string): UiLocale {
  return locale.toLowerCase().startsWith('de') ? 'de' : 'en';
}
