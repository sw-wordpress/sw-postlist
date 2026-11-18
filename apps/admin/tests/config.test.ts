import { afterEach, describe, expect, it } from 'vitest';
import { getConfig, normaliseConfig, resetConfigForTests, resolveUiLocale } from '@/config';

describe('config', () => {
  afterEach(() => {
    resetConfigForTests();
    delete window.swpostlistConfig;
  });

  it('normalises values and adds trailing slashes', () => {
    const config = normaliseConfig({
      restRoot: 'https://x.test/wp-json/sw-postlist/v1',
      pluginUrl: 'https://x.test/p',
    });
    expect(config.restRoot).toBe('https://x.test/wp-json/sw-postlist/v1/');
    expect(config.pluginUrl).toBe('https://x.test/p/');
    expect(config.locale).toBe('en_US');
  });

  it('rejects a missing REST root', () => {
    expect(() => normaliseConfig(undefined)).toThrow();
    expect(() => normaliseConfig({ restRoot: '' })).toThrow();
  });

  it('caches the window config', () => {
    window.swpostlistConfig = {
      restRoot: 'https://x.test/r/',
      nonce: 'n',
      locale: 'de_DE',
      version: '1',
      adminUrl: '',
      siteUrl: '',
      pluginUrl: '',
    };
    expect(getConfig().nonce).toBe('n');
    window.swpostlistConfig = undefined;
    expect(getConfig().nonce).toBe('n');
  });

  it('resolves the UI locale', () => {
    expect(resolveUiLocale('de_CH_informal')).toBe('de');
    expect(resolveUiLocale('fr_FR')).toBe('en');
  });
});
