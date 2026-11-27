import { useEffect } from 'react';
import { usePreferences } from '@/stores/preferences';
import i18n, { applyDocumentLanguage } from './index';
import { findLanguage } from './languages';

/** Switches i18next (and <html lang/dir>) to the saved language. */
export function useLanguageEffect(): void {
  const code = usePreferences((s) => s.language);

  useEffect(() => {
    const language = findLanguage(code);

    if (i18n.language !== language.code) {
      void i18n.changeLanguage(language.code);
    } else {
      applyDocumentLanguage(language.code);
    }
  }, [code]);
}
