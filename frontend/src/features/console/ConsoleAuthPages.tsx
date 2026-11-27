import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Field, Input } from '@/components/ui/form';
import { AuthLayout } from '@/features/auth/AuthLayout';
import { errorText } from '@/features/auth/errors';
import { MfaEnrolPanel } from '@/features/auth/MfaEnrolPanel';
import { NewPasswordForm } from '@/features/auth/PasswordPages';
import { MfaStep } from '@/features/auth/SignInForm';
import { useSessionState } from '@/stores/session';
import { consoleAuth, consoleMeKey } from './api';

/** Console sign-in form: email and password, then the authenticator code. */
export function ConsoleSignInForm({ initialEmail, onDone }: { initialEmail?: string; onDone: (enrolOnly: boolean) => void }) {
  const { t } = useTranslation();
  const [email, setEmail] = useState(initialEmail ?? '');
  const [password, setPassword] = useState('');
  const [challenge, setChallenge] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  if (challenge) {
    return (
      <MfaStep
        challenge={challenge}
        verify={consoleAuth.verify as never}
        onRestart={() => setChallenge(null)}
        onSignedIn={(result) => onDone(result.mfa_enrolment_required)}
      />
    );
  }

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!email.trim() || !password) {
      setError(t('auth.errors.passwordRequired'));
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const result = await consoleAuth.login({ email: email.trim(), password });
      setPassword('');
      if ('challenge' in result && result.mfa_required) setChallenge(result.challenge);
      else onDone(Boolean((result as { mfa_enrolment_required?: boolean }).mfa_enrolment_required));
    } catch (e) {
      setPassword('');
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <Field label={t('console.signIn.email')}>
        {(props) => <Input {...props} value={email} onChange={(e) => setEmail(e.target.value)} type="email" autoComplete="username" readOnly={Boolean(initialEmail)} />}
      </Field>
      <Field label={t('auth.signIn.password')}>
        {(props) => <Input {...props} value={password} onChange={(e) => setPassword(e.target.value)} type="password" autoComplete="current-password" />}
      </Field>
      <Button type="submit" disabled={busy}>
        {busy ? t('common.working') : t('auth.signIn.submit')}
      </Button>
    </form>
  );
}

export function ConsoleSignInPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const resume = useSessionState((s) => s.resume);

  return (
    <AuthLayout title={t('console.signIn.title')} subtitle={t('console.signIn.subtitle')}>
      <ConsoleSignInForm
        onDone={async (enrolOnly) => {
          resume();
          await queryClient.invalidateQueries({ queryKey: consoleMeKey });
          navigate(enrolOnly ? '/console/enrol' : '/console', { replace: true });
        }}
      />
    </AuthLayout>
  );
}

export function ConsoleEnrolPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  return (
    <AuthLayout title={t('auth.enrol.title')} subtitle={t('console.enrolSubtitle')}>
      <MfaEnrolPanel
        start={consoleAuth.enrolStart}
        confirm={consoleAuth.enrolConfirm}
        accountLabel="Campus TAB platform console"
        onFinished={async () => {
          await queryClient.invalidateQueries({ queryKey: consoleMeKey });
          navigate('/console', { replace: true });
        }}
      />
    </AuthLayout>
  );
}

export function ConsoleSetPasswordPage() {
  const { t } = useTranslation();
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';

  return (
    <AuthLayout title={t('auth.password.setTitle')} subtitle={t('auth.password.rules')}>
      {token ? (
        <NewPasswordForm
          submit={(password, confirmation) => consoleAuth.setPassword({ token, password, password_confirmation: confirmation })}
          doneLink="/console/sign-in"
        />
      ) : (
        <Alert tone="danger">{t('auth.apiErrors.password_link_invalid')}</Alert>
      )}
    </AuthLayout>
  );
}

/** Asks for a fresh authenticator code before a sensitive console action. */
export function StepUpPrompt({ onDone, onCancel }: { onDone: () => void; onCancel: () => void }) {
  const { t } = useTranslation();
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await consoleAuth.stepUp(code.trim());
      onDone();
    } catch (e) {
      setCode('');
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
      <p className="text-sm text-muted">{t('stepUp.body')}</p>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <Field label={t('auth.mfa.code')}>
        {(props) => <Input {...props} value={code} onChange={(e) => setCode(e.target.value)} inputMode="numeric" maxLength={6} autoFocus autoComplete="one-time-code" className="font-mono tracking-widest" />}
      </Field>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onCancel}>
          {t('common.cancel')}
        </Button>
        <Button type="submit" disabled={busy || !/^\d{6}$/.test(code.trim())}>
          {t('stepUp.confirm')}
        </Button>
      </div>
    </form>
  );
}
