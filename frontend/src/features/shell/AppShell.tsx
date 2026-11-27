import { CalendarDays, ChevronDown, LayoutDashboard } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { NavLink, Outlet } from 'react-router';
import { BrandLockup } from '@/components/brand/BrandLockup';
import { cn } from '@/lib/utils';
import { LanguagePicker } from './LanguagePicker';
import { SchoolCard } from './SchoolCard';
import { ServerStatus } from './ServerStatus';
import { ThemePicker } from './ThemePicker';

/**
 * Phase 0 shell: brand stack, one navigation item, theme and language
 * pickers and an empty content area. Phase 3 completes it (icon rail at
 * 768-1023 px, bottom tabs under 768 px, search, notifications, profile).
 * Layout follows docs/design/screens/{light,dark}/Sidebar.dc.html.
 */
export function AppShell() {
  const { t } = useTranslation();

  return (
    <div className="min-h-dvh bg-bg text-fg">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-50 focus:rounded-input focus:bg-surface focus:px-4 focus:py-2 focus:shadow"
      >
        {t('a11y.skipToContent')}
      </a>

      <div className="flex min-h-dvh">
        {/* Desktop sidebar (1024 px and up) */}
        <aside className="hidden w-62 shrink-0 flex-col gap-5 border-e border-border bg-surface px-3.5 py-5 lg:flex">
          <BrandStack />
          <nav aria-label={t('a11y.mainNavigation')}>
            <ul className="flex flex-col gap-0.5">
              <li>
                <NavLink
                  to="/"
                  end
                  className={({ isActive }) =>
                    cn(
                      'flex h-10 items-center gap-3 rounded-lg px-2.5 text-sm',
                      isActive ? 'bg-primary-soft font-semibold text-primary' : 'text-fg hover:bg-surface-muted',
                    )
                  }
                >
                  <LayoutDashboard aria-hidden="true" className="size-[18px]" />
                  {t('nav.home')}
                </NavLink>
              </li>
            </ul>
          </nav>
          <div className="mt-auto flex flex-col gap-2.5 border-t border-border pt-3.5">
            <ServerStatus />
          </div>
        </aside>

        <div className="flex min-w-0 flex-1 flex-col">
          {/* Top bar; on phones it also carries the compact brand stack. */}
          <header className="flex items-center justify-between gap-3 border-b border-border bg-surface px-4 py-2 lg:justify-end">
            <BrandLockup size="compact" className="lg:hidden" />
            <div className="flex items-center gap-2">
              <LanguagePicker />
              <ThemePicker />
            </div>
          </header>

          <main id="main" className="flex-1 px-4 py-6 lg:px-8">
            <Outlet />
          </main>
        </div>
      </div>
    </div>
  );
}

/** Our logo and name, then the school's logo and name, then the year switcher. */
function BrandStack() {
  const { t } = useTranslation();

  return (
    <div className="flex flex-col gap-3" data-testid="brand-stack">
      <BrandLockup className="px-1.5" />
      <SchoolCard />
      <button
        type="button"
        disabled
        className="flex h-9 items-center justify-between rounded-lg border border-border bg-bg px-2.5 text-[13px] font-medium text-muted"
      >
        <span className="flex items-center gap-2">
          <CalendarDays aria-hidden="true" className="size-3.5" />
          {t('school.yearPlaceholder')}
        </span>
        <ChevronDown aria-hidden="true" className="size-3.5" />
      </button>
    </div>
  );
}
