import { useQueryClient } from '@tanstack/react-query';
import { Bell, Building2, History, LogOut, MonitorSmartphone } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Navigate, NavLink, Outlet, useNavigate } from 'react-router';
import { BrandLockup } from '@/components/brand/BrandLockup';
import { Button } from '@/components/ui/button';
import { Dialog, Spinner } from '@/components/ui/form';
import { isSignedOutError } from '@/features/auth/api';
import { IdleWarning } from '@/features/auth/RequireSession';
import { LanguagePicker } from '@/features/shell/LanguagePicker';
import { ThemePicker } from '@/features/shell/ThemePicker';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';
import { useActivity } from '@/stores/activity';
import { useSessionState } from '@/stores/session';
import { consoleAuth, useConsoleMe } from './api';
import { ConsoleSignInForm } from './ConsoleAuthPages';

/**
 * The platform console frame (our team only): its own sign-in, the same
 * idle rules as school users, and sign-in again over the page when the
 * session ends, so nothing typed is lost.
 */
export function ConsoleLayout() {
  const { t } = useTranslation();
  const me = useConsoleMe();
  const ended = useSessionState((s) => s.ended);
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  if (me.isPending) {
    return (
      <div className="flex min-h-dvh items-center justify-center bg-bg">
        <Spinner label={t('common.loading')} />
      </div>
    );
  }

  const admin = me.data;

  if (!admin) return <Navigate to="/console/sign-in" replace />;
  if (me.data?.session.mfa_enrolment_only || me.data?.mfa.enabled === false) return <Navigate to="/console/enrol" replace />;

  const signOut = async () => {
    await consoleAuth.logout().catch(() => undefined);
    queryClient.clear();
    navigate('/console/sign-in', { replace: true });
  };

  const links = [
    { to: '/console', label: t('console.nav.schools'), icon: Building2, end: true },
    { to: '/console/alerts', label: t('console.nav.alerts'), icon: Bell, end: false },
    { to: '/console/audit', label: t('console.nav.audit'), icon: History, end: false },
    { to: '/console/sessions', label: t('console.nav.sessions'), icon: MonitorSmartphone, end: false },
  ];

  return (
    <div className="min-h-dvh bg-bg text-fg">
      <header className="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-surface px-4 py-3 lg:px-8">
        <div className="flex items-center gap-3" data-testid="brand-stack">
          <BrandLockup size="compact" />
          <span className="rounded-full bg-primary-soft px-2.5 py-1 text-xs font-semibold text-primary">{t('console.badge')}</span>
        </div>
        <div className="flex items-center gap-2">
          <span className="hidden text-sm text-muted sm:inline">{admin.name}</span>
          <LanguagePicker />
          <ThemePicker />
          <Button variant="outline" size="sm" onClick={() => void signOut()}>
            <LogOut aria-hidden="true" />
            {t('session.signOut')}
          </Button>
        </div>
      </header>
      <nav aria-label={t('a11y.mainNavigation')} className="border-b border-border bg-surface px-2 lg:px-6">
        <ul className="flex gap-1 relative overflow-x-auto">
          {links.map(({ to, label, icon: Icon, end }) => (
            <li key={to}>
              <NavLink
                to={to}
                end={end}
                className={({ isActive }) =>
                  cn('flex h-11 items-center gap-2 border-b-2 px-3 text-sm whitespace-nowrap', isActive ? 'border-primary font-semibold text-primary' : 'border-transparent text-fg hover:text-primary')
                }
              >
                <Icon aria-hidden="true" className="size-4" />
                {label}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
      <main id="main" className="mx-auto max-w-6xl px-4 py-6 lg:px-8">
        <Outlet />
      </main>
      <IdleWarning idleMinutes={admin.session.idle_minutes} stay={() => void api.GET('/platform/me')} signOut={signOut} />
      <Dialog open={ended || (me.isError && isSignedOutError(me.error))} dismissible={false} title={t('session.ended.title')}>
        <p className="text-sm text-muted">{t('session.ended.body')}</p>
        <ConsoleSignInForm
          initialEmail={admin.email}
          onDone={async (enrolOnly) => {
            useActivity.getState().touch();
            useSessionState.getState().resume();
            if (enrolOnly) navigate('/console/enrol', { replace: true });
            await queryClient.invalidateQueries();
          }}
        />
        <p className="text-xs text-muted">{t('session.ended.kept')}</p>
      </Dialog>
    </div>
  );
}
