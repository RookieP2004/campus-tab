import type { ResolvedTheme, ThemeChoice } from '@campus-tab/ui';
import { useEffect } from 'react';
import { usePreferences } from '@/stores/preferences';

const DARK_QUERY = '(prefers-color-scheme: dark)';

export function resolveTheme(choice: ThemeChoice, prefersDark: boolean): ResolvedTheme {
  if (choice === 'system') {
    return prefersDark ? 'dark' : 'light';
  }

  return choice;
}

/**
 * Writes data-theme="light" | "dark" on <html> from the saved choice.
 * With "Match device" it also follows the device when that changes.
 */
export function useThemeEffect(): void {
  const choice = usePreferences((s) => s.theme);

  useEffect(() => {
    const media = window.matchMedia(DARK_QUERY);
    const apply = () => {
      document.documentElement.dataset.theme = resolveTheme(choice, media.matches);
    };

    apply();

    if (choice !== 'system') {
      return undefined;
    }

    media.addEventListener('change', apply);

    return () => media.removeEventListener('change', apply);
  }, [choice]);
}
