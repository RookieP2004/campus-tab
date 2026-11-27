import { useQueryClient } from '@tanstack/react-query';
import { createContext, type ReactNode, useContext, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Navigate, useLocation, useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Dialog, Spinner } from '@/components/ui/form';
import { ApiError, api } from '@/lib/api';
import { useActivity } from '@/stores/activity';
import { usePreferences } from '@/stores/preferences';
import { useSchoolCode } from '@/stores/school';
import { useSessionState } from '@/stores/session';
import { isSignedOutError, type Me, signIn, useMe } from './api';
import { ParentOtpForm } from './ParentOtpPage';
import { SignInForm } from './SignInForm';

const MeContext = createContext<Me | null>(null);

/** The signed-in school user. Only inside <RequireSession>. */
export function useCurrentUser(): Me {
  const me = useContext(MeContext);
  if (!me) throw new Error('useCurrentUser outside RequireSession');
  return me;
}

/**
 * Guards every signed-in screen. Not signed in: go to sign-in. Signed in:
 * render the screen, warn a minute before an idle sign-out, and when the
 * session ends over an open screen, show sign-in again on top of it. The
 * screen stays mounted underneath, so unsaved input is kept.
 */
export function RequireSession({ children }: { children: ReactNode }) {
  const { t } = useTranslation();
  const location = useLocation();
  const me = useMe();
  const ended = useSessionState((s) => s.ended);
  const code = useSchoolCode((s) => s.code);
  const setCode = useSchoolCode((s) => s.setCode);

  useEffect(() => {
    if (me.data && me.data.school.code !== code) setCode(me.data.school.code);
  }, [me.data, code, setCode]);

  usePreferenceSync(me.data ?? null);

  if (me.isPending) {
    return (
      <div className="flex min-h-dvh items-center justify-center bg-bg">
        <Spinner label={t('common.loading')} />
      </div>
    );
  }

  const user = me.data;

  if (!user) {
    if (me.error instanceof ApiError && me.error.code === 'mfa_enrolment_required') return <Navigate to="/mfa/enrol" replace />;
    if (me.isError && !isSignedOutError(me.error)) {
      return (
        <div className="flex min-h-dvh items-center justify-center bg-bg p-4">
          <Alert tone="danger">{t('auth.apiErrors.network')}</Alert>
        </div>
      );
    }
    return <Navigate to={code ? '/sign-in' : '/school'} replace state={{ from: location.pathname + location.search }} />;
  }

  return (
    <MeContext.Provider value={user}>
      {children}
      <IdleWarning idleMinutes={user.session.idle_minutes} />
      <SessionEndedOverlay open={ended || (me.isError && isSignedOutError(me.error))} user={user} />
    </MeContext.Provider>
  );
}

/** Loads the person's language and theme from their account, and saves changes back. */
function usePreferenceSync(me: Me | null) {
  const loadedFor = useRef<string | null>(null);
  const language = usePreferences((s) => s.language);
  const theme = usePreferences((s) => s.theme);

  useEffect(() => {
    if (!me || loadedFor.current === me.id) return;
    loadedFor.current = me.id;
    usePreferences.setState({ language: me.language, theme: me.theme });
  }, [me]);

  useEffect(() => {
    if (!me || loadedFor.current !== me.id) return;
    if (language === me.language && theme === me.theme) return;
    void api.PATCH('/me/preferences', { body: { language: language as Me['language'] as never, theme } });
  }, [language, theme, me]);
}

/**
 * One minute before the idle limit (counted from the last real request, as
 * the server counts), offer to stay signed in. Background requests never
 * move this clock.
 */
export function IdleWarning({
  idleMinutes,
  stay = () => void api.GET('/me'),
  signOut,
}: {
  idleMinutes: number;
  /** A real request that counts as use. */
  stay?: () => void;
  signOut?: () => Promise<void>;
}) {
  const { t } = useTranslation();
  const last = useActivity((s) => s.lastRealRequestAt);
  const ended = useSessionState((s) => s.ended);
  const end = useSessionState((s) => s.end);
  const [now, setNow] = useState(() => Date.now());
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const defaultSignOut = async () => {
    await signIn.logout().catch(() => undefined);
    queryClient.clear();
    navigate('/sign-in', { replace: true });
  };

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);

  const remaining = Math.ceil((last + idleMinutes * 60_000 - now) / 1000);

  useEffect(() => {
    if (!ended && remaining <= 0) end('session_expired');
  }, [remaining, ended, end]);

  const open = !ended && remaining > 0 && remaining <= 60;

  return (
    <Dialog open={open} title={t('session.idle.title')} onClose={stay}>
      <p className="text-sm text-muted" aria-live="polite">
        {t('session.idle.body', { seconds: remaining })}
      </p>
      <div className="flex flex-wrap justify-end gap-2">
        <Button variant="outline" onClick={() => void (signOut ?? defaultSignOut)()}>
          {t('session.signOut')}
        </Button>
        {/* A real request: it counts as use and resets the idle time. */}
        <Button onClick={stay}>{t('session.idle.stay')}</Button>
      </div>
    </Dialog>
  );
}

function SessionEndedOverlay({ open, user }: { open: boolean; user: Me }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const resume = useSessionState((s) => s.resume);
  const reason = useSessionState((s) => s.reason);
  const touch = useActivity((s) => s.touch);
  const parentOnly = user.roles.length > 0 && user.roles.every((r) => r === 'parent');

  const done = async (userId?: string) => {
    if (userId && userId !== user.id) {
      // Someone else signed in: nothing of the previous person stays on screen.
      queryClient.clear();
      resume();
      navigate('/', { replace: true });
      return;
    }
    touch();
    resume();
    await queryClient.invalidateQueries();
  };

  return (
    <Dialog open={open} dismissible={false} title={t('session.ended.title')}>
      <p className="text-sm text-muted">{t(reason === 'session_revoked' ? 'session.ended.revoked' : 'session.ended.body')}</p>
      {parentOnly ? (
        <ParentOtpForm schoolCode={user.school.code} onDone={() => void done()} />
      ) : (
        <SignInForm
          schoolCode={user.school.code}
          initialLogin={user.email ?? user.phone ?? ''}
          onSignedIn={(result) => {
            if (result.mfa_enrolment_required) {
              resume();
              navigate('/mfa/enrol', { replace: true });
              return;
            }
            void done(result.user.id);
          }}
        />
      )}
      <p className="text-xs text-muted">{t('session.ended.kept')}</p>
    </Dialog>
  );
}

