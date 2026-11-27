import i18n from 'i18next';
import resourcesToBackend from 'i18next-resources-to-backend';
import { initReactI18next } from 'react-i18next';
import en from '@/locales/en/common.json';
import { DEFAULT_LANGUAGE, findLanguage } from './languages';

/**
 * i18next setup. English is bundled so the first screen never waits; every
 * other language is a separate chunk loaded on first use, so shipping all
 * regional languages does not grow the first download.
 */
const lazyLocales = import.meta.glob<{ default: Record<string, unknown> }>(['../locales/*/*.json', '!../locales/en/*.json']);

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
    resources: { en: { common: en } },
    partialBundledLanguages: true,
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
