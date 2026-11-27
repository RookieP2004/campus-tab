import { Languages } from 'lucide-react';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { shippedLanguages } from '@/i18n/languages';
import { cn } from '@/lib/utils';
import { usePreferences } from '@/stores/preferences';

/** Picks this person's language. Lists shipped languages in their own script. */
export function LanguagePicker({ className }: { className?: string }) {
  const { t } = useTranslation();
  const id = useId();
  const language = usePreferences((s) => s.language);
  const setLanguage = usePreferences((s) => s.setLanguage);

  return (
    <div className={cn('relative inline-flex items-center', className)}>
      <label htmlFor={id} className="sr-only">
        {t('language.label')}
      </label>
      <Languages aria-hidden="true" className="pointer-events-none absolute start-3 size-4 text-muted" />
      <select
        id={id}
        value={language}
        onChange={(e) => setLanguage(e.target.value)}
        className="h-11 appearance-none rounded-input border border-border bg-surface ps-9 pe-4 text-sm text-fg focus-visible:outline-2 focus-visible:outline-ring"
      >
        {shippedLanguages.map((l) => (
          <option key={l.code} value={l.code} lang={l.code}>
            {l.nativeName}
          </option>
        ))}
      </select>
    </div>
  );
}
