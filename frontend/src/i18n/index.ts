import i18n from 'i18next';
import resourcesToBackend from 'i18next-resources-to-backend';
import { initReactI18next } from 'react-i18next';
import { DEFAULT_LANGUAGE, findLanguage } from './languages';

/**
 * i18next setup. Every language, English included, is its own chunk loaded
 * on demand: the app waits for the person's language (and English, the
 * fallback) before the first render, so the main bundle does not grow as
 * each phase adds strings, and a Hindi reader never downloads English text
 * they do not need beyond the fallback.
 */
const lazyLocales = import.meta.glob<{ default: Record<string, unknown> }>('../locales/*/*.json');

export const i18nReady = i18n
  .use(initReactI18next)
  .use(
    resourcesToBackend((language: string, namespace: string) => {
      const loader = lazyLocales[`../locales/${language}/${namespace}.json`];

      return loader ? loader().then((m) => m.default) : Promise.resolve({});
    }),
  )
  .init({
    lng: DEFAULT_LANGUAGE,
    fallbackLng: DEFAULT_LANGUAGE,
    supportedLngs: false,
    ns: ['common'],
    defaultNS: 'common',
    interpolation: { escapeValue: false },
    returnNull: false,
  });

/** Keeps <html lang> and <html dir> in step with the chosen language. */
export function applyDocumentLanguage(code: string): void {
  const language = findLanguage(code);
  document.documentElement.lang = language.code;
  document.documentElement.dir = language.dir;
}

i18n.on('languageChanged', applyDocumentLanguage);

export default i18n;
