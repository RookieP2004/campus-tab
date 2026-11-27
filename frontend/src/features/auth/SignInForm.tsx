import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { z } from 'zod';
import { Alert, Field, Input } from '@/components/ui/form';
import { Button } from '@/components/ui/button';
import { ApiError } from '@/lib/api';
import { type SignInResult, signIn } from './api';
import { errorText } from './errors';

type Completed = Exclude<SignInResult, { mfa_required: true }>;

/**
 * Password sign-in, then the MFA step (authenticator code or a recovery
 * code). Used by the sign-in page and by the overlay shown when a session
 * ends over an open screen.
 */
export function SignInForm({
  schoolCode,
  onSignedIn,
  initialLogin,
  lockLogin = false,
}: {
  schoolCode: string;
  onSignedIn: (result: Completed) => void;
  initialLogin?: string;
  lockLogin?: boolean;
}) {
  const { t } = useTranslation();
  const [challenge, setChallenge] = useState<string | null>(null);

  if (challenge) {
    return <MfaStep challenge={challenge} onSignedIn={onSignedIn} onRestart={() => setChallenge(null)} />;
  }

  return (
    <PasswordStep
      schoolCode={schoolCode}
      initialLogin={initialLogin}
      lockLogin={lockLogin}
      onResult={(result) => (result.mfa_required ? setChallenge(result.challenge) : onSignedIn(result))}
      submitLabel={t('auth.signIn.submit')}
    />
  );
}

function PasswordStep({
  schoolCode,
  initialLogin,
  lockLogin,
  onResult,
  submitLabel,
}: {
  schoolCode: string;
  initialLogin?: string | undefined;
  lockLogin: boolean;
  onResult: (result: SignInResult) => void;
  submitLabel: string;
}) {
  const { t } = useTranslation();
  const schema = z.object({
    login: z.string().trim().min(1, t('auth.errors.loginRequired')),
    password: z.string().min(1, t('auth.errors.passwordRequired')),
  });
  const [error, setError] = useState<string | null>(null);
  const form = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema), defaultValues: { login: initialLogin ?? '', password: '' } });

  const submit = form.handleSubmit(async (values) => {
    setError(null);
    try {
      onResult(await signIn.password({ school_code: schoolCode, login: values.login, password: values.password }));
    } catch (e) {
      form.setValue('password', '');
      setError(errorText(t, e));
    }
  });

  return (
    <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <Field label={t('auth.signIn.login')} hint={t('auth.signIn.loginHint')} error={form.formState.errors.login?.message}>
        {(props) => <Input {...props} {...form.register('login')} autoComplete="username" readOnly={lockLogin} autoCapitalize="none" />}
      </Field>
      <Field label={t('auth.signIn.password')} error={form.formState.errors.password?.message}>
        {(props) => <Input {...props} {...form.register('password')} type="password" autoComplete="current-password" />}
      </Field>
      <Button type="submit" disabled={form.formState.isSubmitting}>
        {form.formState.isSubmitting ? t('common.working') : submitLabel}
      </Button>
    </form>
  );
}

export function MfaStep({
  challenge,
  onSignedIn,
  onRestart,
  verify = signIn.mfa,
}: {
  challenge: string;
  onSignedIn: (result: Completed) => void;
  onRestart: () => void;
  verify?: (body: { challenge: string; code: string }) => Promise<SignInResult>;
}) {
  const { t } = useTranslation();
  const [useRecovery, setUseRecovery] = useState(false);
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (code.trim() === '') {
      setError(t(useRecovery ? 'auth.errors.recoveryRequired' : 'auth.errors.codeRequired'));
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const result = await verify({ challenge, code: code.trim() });
      if (!result.mfa_required) onSignedIn(result);
    } catch (e) {
      setCode('');
      if (e instanceof ApiError && (e.code === 'mfa_challenge_expired' || e.status === 401)) {
        onRestart();
        return;
      }
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
      <p className="text-sm text-muted">{t(useRecovery ? 'auth.mfa.recoveryIntro' : 'auth.mfa.intro')}</p>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <Field label={t(useRecovery ? 'auth.mfa.recoveryCode' : 'auth.mfa.code')}>
        {(props) => (
          <Input
            {...props}
            value={code}
            onChange={(e) => setCode(e.target.value)}
            autoComplete="one-time-code"
            inputMode={useRecovery ? 'text' : 'numeric'}
            maxLength={useRecovery ? 11 : 6}
            autoFocus
            className="font-mono tracking-widest"
          />
        )}
      </Field>
      <Button type="submit" disabled={busy}>
        {busy ? t('common.working') : t('auth.mfa.submit')}
      </Button>
      <div className="flex flex-wrap justify-between gap-2 text-sm">
        <button
          type="button"
          className="text-primary underline-offset-2 hover:underline"
          onClick={() => {
            setUseRecovery((v) => !v);
            setCode('');
            setError(null);
          }}
        >
          {t(useRecovery ? 'auth.mfa.useApp' : 'auth.mfa.useRecovery')}
        </button>
        <button type="button" className="text-muted underline-offset-2 hover:underline" onClick={onRestart}>
          {t('auth.mfa.startAgain')}
        </button>
      </div>
    </form>
  );
}
