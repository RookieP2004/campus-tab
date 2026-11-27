import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { BrandLockup } from '@/components/brand/BrandLockup';
import { LanguagePicker } from '@/features/shell/LanguagePicker';
import { SchoolBadge } from '@/features/shell/SchoolCard';
import { ThemePicker } from '@/features/shell/ThemePicker';
import type { PublicConfig } from './api';

/**
 * The frame of every screen before sign-in. Brand stack top-left: our logo
 * and name, then the school's logo and name once a school is chosen. Theme
 * and language pickers top-right. One column on phones, centred card on
 * desktop.
 */
export function AuthLayout({
  school,
  title,
  subtitle,
  children,
  wide = false,
}: {
  school?: PublicConfig | null;
  title: ReactNode;
  subtitle?: ReactNode;
  children: ReactNode;
  wide?: boolean;
}) {
  const { t } = useTranslation();

  return (
    <div className="flex min-h-dvh flex-col bg-bg text-fg">
      {school ? <div aria-hidden="true" className="h-1 w-full" style={{ backgroundColor: school.accent }} /> : null}
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-50 focus:rounded-input focus:bg-surface focus:px-4 focus:py-2"
      >
        {t('a11y.skipToContent')}
      </a>
      <header className="flex flex-wrap items-start justify-between gap-3 px-4 pt-4 sm:px-6 sm:pt-5">
        <div className="flex min-w-0 flex-col gap-2.5" data-testid="brand-stack">
          <BrandLockup size="compact" className="sm:hidden" />
          <BrandLockup className="hidden sm:flex" />
          {school ? <SchoolBadge config={school} compact /> : null}
        </div>
        <div className="flex items-center gap-2">
          <LanguagePicker />
          <ThemePicker />
        </div>
      </header>
      <main id="main" className="flex flex-1 items-start justify-center px-4 py-8 sm:items-center sm:px-6">
        <div className={wide ? 'w-full max-w-2xl' : 'w-full max-w-md'}>
          <div className="rounded-card border border-border bg-surface p-5 shadow-sm sm:p-8">
            <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
            {subtitle ? <p className="mt-2 text-sm text-muted">{subtitle}</p> : null}
            <div className="mt-6">{children}</div>
          </div>
        </div>
      </main>
    </div>
  );
}
