import { useQueryClient } from '@tanstack/react-query';
import { CalendarDays, ChevronDown, LayoutDashboard, LogOut, MonitorSmartphone, type LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { NavLink, Outlet, useNavigate } from 'react-router';
import { BrandLockup } from '@/components/brand/BrandLockup';
import { Button } from '@/components/ui/button';
import { signIn } from '@/features/auth/api';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { cn } from '@/lib/utils';
import { navItems } from './navItems';
import { LanguagePicker } from './LanguagePicker';
import { SchoolCard } from './SchoolCard';
import { ServerStatus } from './ServerStatus';
import { ThemePicker } from './ThemePicker';

/**
 * The signed-in shell: brand stack, navigation (only what the person's
 * permissions allow; the server checks every request anyway), theme and
 * language pickers, the account's devices and sign-out. Phase 3 completes
 * it (icon rail at 768-1023 px, bottom tabs under 768 px, search,
 * notifications, role switcher).
 * Layout follows docs/design/screens/{light,dark}/Sidebar.dc.html.
 */
export function AppShell() {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const items = navItems.filter((item) => !item.permission || me.permissions.some((p) => item.permission?.some((needed) => p.startsWith(needed))));

  const signOut = async () => {
    await signIn.logout().catch(() => undefined);
    queryClient.clear();
    navigate('/sign-in', { replace: true });
  };

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
              <NavItem to="/" end icon={LayoutDashboard} label={t('nav.home')} />
              {items.map((item) => (
                <NavItem key={item.to} to={item.to} icon={item.icon} label={t(item.label)} />
              ))}
              <NavItem to="/account/sessions" icon={MonitorSmartphone} label={t('nav.devices')} />
            </ul>
          </nav>
          <div className="mt-auto flex flex-col gap-2.5 border-t border-border pt-3.5">
            <p className="truncate px-1.5 text-sm font-medium" data-testid="signed-in-name">{me.name}</p>
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
              <Button variant="outline" size="sm" onClick={() => void signOut()}>
                <LogOut aria-hidden="true" />
                <span className="hidden sm:inline">{t('session.signOut')}</span>
              </Button>
            </div>
          </header>
          {/* Phones and tablets: the same links as a scrolling strip (bottom tabs arrive in Phase 3). */}
          <nav aria-label={t('a11y.mainNavigation')} className="border-b border-border bg-surface px-2 lg:hidden">
            <ul className="flex gap-1 overflow-x-auto py-1">
              <NavItem to="/" end icon={LayoutDashboard} label={t('nav.home')} />
              {items.map((item) => (
                <NavItem key={item.to} to={item.to} icon={item.icon} label={t(item.label)} />
              ))}
              <NavItem to="/account/sessions" icon={MonitorSmartphone} label={t('nav.devices')} />
            </ul>
          </nav>

          <main id="main" className="flex-1 px-4 py-6 lg:px-8">
            <Outlet />
          </main>
        </div>
      </div>
    </div>
  );
}

function NavItem({ to, end, icon: Icon, label }: { to: string; end?: boolean; icon: LucideIcon; label: string }) {
  return (
    <li className="shrink-0">
      <NavLink
        to={to}
        end={end}
        className={({ isActive }) =>
          cn(
            'flex h-10 items-center gap-3 rounded-lg px-2.5 text-sm whitespace-nowrap',
            isActive ? 'bg-primary-soft font-semibold text-primary' : 'text-fg hover:bg-surface-muted',
          )
        }
      >
        <Icon aria-hidden="true" className="size-[18px]" />
        {label}
      </NavLink>
    </li>
  );
}

/** Our logo and name, then the school's logo and name, then the year switcher. */
function BrandStack() {
  const { t } = useTranslation();
  const me = useCurrentUser();

  return (
    <div className="flex flex-col gap-3" data-testid="brand-stack">
      <BrandLockup className="px-1.5" />
      <SchoolCard code={me.school.code} />
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
