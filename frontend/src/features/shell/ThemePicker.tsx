import type { ThemeChoice } from '@campus-tab/ui';
import { Monitor, Moon, Sun } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';
import { usePreferences } from '@/stores/preferences';

const options: { value: ThemeChoice; icon: typeof Sun }[] = [
  { value: 'light', icon: Sun },
  { value: 'dark', icon: Moon },
  { value: 'system', icon: Monitor },
];

/** Light / Dark / Match device. Saved per person (see stores/preferences.ts). */
export function ThemePicker({ className }: { className?: string }) {
  const { t } = useTranslation();
  const theme = usePreferences((s) => s.theme);
  const setTheme = usePreferences((s) => s.setTheme);

  return (
    <div role="radiogroup" aria-label={t('theme.label')} className={cn('inline-flex rounded-input border border-border bg-surface p-0.5', className)}>
      {options.map(({ value, icon: Icon }) => {
        const selected = theme === value;

        return (
          <button
            key={value}
            type="button"
            role="radio"
            aria-checked={selected}
            aria-label={t(`theme.${value}`)}
            title={t(`theme.${value}`)}
            onClick={() => setTheme(value)}
            className={cn(
              'inline-flex size-10 items-center justify-center rounded-[5px] text-muted transition-colors duration-(--ct-motion) focus-visible:outline-2 focus-visible:outline-ring',
              selected ? 'bg-primary-soft text-primary' : 'hover:bg-surface-muted hover:text-fg',
            )}
          >
            <Icon aria-hidden="true" className="size-4" />
          </button>
        );
      })}
    </div>
  );
}
