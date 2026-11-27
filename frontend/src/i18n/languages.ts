/**
 * Every language Campus TAB is structured for. English is the default for
 * everyone; each person can pick another at sign-up or later in settings,
 * and the choice changes only their own screens.
 *
 * To ship a language: add src/locales/<code>/common.json (copy en), then set
 * `shipped: true` here. Right-to-left scripts (Urdu) flip the layout through
 * the `dir` attribute on <html>; use logical CSS (ms-*, me-*, ps-*, pe-*,
 * start-*, end-*) instead of left/right so screens mirror correctly.
 */
export type TextDirection = 'ltr' | 'rtl';

export interface Language {
  /** BCP 47 code, also the folder name under src/locales/. */
  code: string;
  /** English name, for admins and logs. */
  name: string;
  /** Name in its own script, shown in the language picker. */
  nativeName: string;
  dir: TextDirection;
  /** ISO 15924 script, used to pick the Noto font family. */
  script: 'Latn' | 'Deva' | 'Beng' | 'Gujr' | 'Guru' | 'Knda' | 'Mlym' | 'Orya' | 'Taml' | 'Telu' | 'Arab';
  /** True once its translation files are complete and reviewed. */
  shipped: boolean;
}

export const DEFAULT_LANGUAGE = 'en';

export const languages: readonly Language[] = [
  { code: 'en', name: 'English', nativeName: 'English', dir: 'ltr', script: 'Latn', shipped: true },
  { code: 'hi', name: 'Hindi', nativeName: 'हिन्दी', dir: 'ltr', script: 'Deva', shipped: true },
  { code: 'bn', name: 'Bengali', nativeName: 'বাংলা', dir: 'ltr', script: 'Beng', shipped: false },
  { code: 'mr', name: 'Marathi', nativeName: 'मराठी', dir: 'ltr', script: 'Deva', shipped: false },
  { code: 'gu', name: 'Gujarati', nativeName: 'ગુજરાતી', dir: 'ltr', script: 'Gujr', shipped: false },
  { code: 'pa', name: 'Punjabi', nativeName: 'ਪੰਜਾਬੀ', dir: 'ltr', script: 'Guru', shipped: false },
  { code: 'ta', name: 'Tamil', nativeName: 'தமிழ்', dir: 'ltr', script: 'Taml', shipped: false },
  { code: 'te', name: 'Telugu', nativeName: 'తెలుగు', dir: 'ltr', script: 'Telu', shipped: false },
  { code: 'kn', name: 'Kannada', nativeName: 'ಕನ್ನಡ', dir: 'ltr', script: 'Knda', shipped: false },
  { code: 'ml', name: 'Malayalam', nativeName: 'മലയാളം', dir: 'ltr', script: 'Mlym', shipped: false },
  { code: 'or', name: 'Odia', nativeName: 'ଓଡ଼ିଆ', dir: 'ltr', script: 'Orya', shipped: false },
  { code: 'ur', name: 'Urdu', nativeName: 'اردو', dir: 'rtl', script: 'Arab', shipped: false },
];

export const shippedLanguages = languages.filter((l) => l.shipped);

export function findLanguage(code: string | undefined): Language {
  const fallback = languages.find((l) => l.code === DEFAULT_LANGUAGE) as Language;

  return languages.find((l) => l.code === code && l.shipped) ?? fallback;
}
