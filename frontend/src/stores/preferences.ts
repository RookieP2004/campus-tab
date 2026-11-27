import type { ThemeChoice } from '@campus-tab/ui';
import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';
import { DEFAULT_LANGUAGE } from '@/i18n/languages';

/**
 * Per-person display preferences: language and theme.
 * Defaults: English and Match device.
 *
 * Phase 0 keeps them in this browser (localStorage key
 * "campus-tab:preferences", also read by the inline script in index.html).
 * Phase 1 saves them on the signed-in user's account and loads them from
 * GET /me, so they follow the person to every device.
 */
interface PreferencesState {
  language: string;
  theme: ThemeChoice;
  setLanguage: (language: string) => void;
  setTheme: (theme: ThemeChoice) => void;
}

export const PREFERENCES_STORAGE_KEY = 'campus-tab:preferences';

export const usePreferences = create<PreferencesState>()(
  persist(
    (set) => ({
      language: DEFAULT_LANGUAGE,
      theme: 'system',
      setLanguage: (language) => set({ language }),
      setTheme: (theme) => set({ theme }),
    }),
    {
      name: PREFERENCES_STORAGE_KEY,
      storage: createJSONStorage(() => localStorage),
      partialize: ({ language, theme }) => ({ language, theme }),
    },
  ),
);
