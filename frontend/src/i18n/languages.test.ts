import { describe, expect, it } from 'vitest';
import { applyDocumentLanguage } from './index';
import { findLanguage, languages, shippedLanguages } from './languages';

const locales = import.meta.glob<Record<string, unknown>>('../locales/*/common.json', { eager: true, import: 'default' });
const localeFor = (code: string) => locales[`../locales/${code}/common.json`];

function keys(value: unknown, prefix = ''): string[] {
  if (value === null || typeof value !== 'object') {
    return [prefix];
  }

  return Object.entries(value).flatMap(([k, v]) => keys(v, prefix ? `${prefix}.${k}` : k));
}

describe('languages', () => {
  it('ships English and Hindi first', () => {
    expect(shippedLanguages.map((l) => l.code)).toEqual(['en', 'hi']);
  });

  it('falls back to English for unknown or unshipped languages', () => {
    expect(findLanguage('xx').code).toBe('en');
    expect(findLanguage('ur').code).toBe('en');
    expect(findLanguage(undefined).code).toBe('en');
  });

  it('marks Urdu as right-to-left', () => {
    expect(languages.find((l) => l.code === 'ur')?.dir).toBe('rtl');
  });

  it('sets lang and dir on <html>', () => {
    applyDocumentLanguage('hi');
    expect(document.documentElement.lang).toBe('hi');
    expect(document.documentElement.dir).toBe('ltr');
  });

  it('has a locale file for every shipped language with the same keys as English', () => {
    const english = keys(localeFor('en')).sort();

    for (const language of shippedLanguages) {
      expect(localeFor(language.code), `${language.code} locale file`).toBeDefined();
      expect(keys(localeFor(language.code)).sort(), `${language.code} keys`).toEqual(english);
    }
  });
});
