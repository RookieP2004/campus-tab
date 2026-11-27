import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { Link, Navigate, useParams, useSearchParams } from 'react-router';
import { z } from 'zod';
import { Button } from '@/components/ui/button';
import { Alert, Field, Input } from '@/components/ui/form';
import { usePreferences } from '@/stores/preferences';
import { useSchoolCode } from '@/stores/school';
import { signIn, usePublicConfig } from './api';
import { AuthLayout } from './AuthLayout';
import { errorText, fieldError } from './errors';

/** Forgot password: the same answer whether or not the account exists. */
export function ForgotPasswordPage() {
  const { t } = useTranslation();
  const code = useSchoolCode((s) => s.code);
  const { data: school } = usePublicConfig(code);
  const [login, setLogin] = useState('');
  const [done, setDone] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  if (!code) return <Navigate to="/school" replace />;

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (login.trim() === '') {
      setError(t('auth.errors.loginRequired'));
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await signIn.forgot({ school_code: code, login: login.trim() });
      setDone(true);
    } catch (e) {
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <AuthLayout school={school ?? null} title={t('auth.forgot.title')} subtitle={t('auth.forgot.subtitle')}>
      {done ? (
        <Alert tone="success">{t('auth.forgot.sent')}</Alert>
      ) : (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <Field label={t('auth.signIn.login')} hint={t('auth.signIn.loginHint')}>
            {(props) => <Input {...props} value={login} onChange={(e) => setLogin(e.target.value)} autoComplete="username" />}
          </Field>
          <Button type="submit" disabled={busy}>
            {busy ? t('common.working') : t('auth.forgot.submit')}
          </Button>
        </form>
      )}
      <div className="mt-6 border-t border-border pt-4 text-sm">
        <Link to="/sign-in" className="text-primary underline-offset-2 hover:underline">
          {t('auth.forgot.back')}
        </Link>
      </div>
    </AuthLayout>
  );
}

/**
 * Set a new password from a one-time link: /password/set (new account) or
 * /password/reset (forgot, or sent by the Principal), both with ?school=&token=.
 */
export function SetPasswordPage() {
  const { purpose } = useParams();
  const [params] = useSearchParams();
  const school = params.get('school') ?? '';
  const token = params.get('token') ?? '';
  const { data: config } = usePublicConfig(school || null);
  const { t } = useTranslation();
  const setCode = useSchoolCode((s) => s.setCode);
  const reset = purpose === 'reset';

  return (
    <AuthLayout
      school={config ?? null}
      title={t(reset ? 'auth.password.resetTitle' : 'auth.password.setTitle')}
      subtitle={t('auth.password.rules')}
    >
      {!school || !token ? (
        <Alert tone="danger">{t('auth.apiErrors.password_link_invalid')}</Alert>
      ) : (
        <NewPasswordForm
          submit={(password, confirmation) =>
            signIn.setPassword({
              school_code: school,
              token,
              password,
              password_confirmation: confirmation,
              // The language picked on this page becomes the person's own.
              language: usePreferences.getState().language,
            })
          }
          onDone={() => setCode(school)}
          doneLink="/sign-in"
        />
      )}
    </AuthLayout>
  );
}

/** Choose a password twice. Shared by school and console pages. */
export function NewPasswordForm({
  submit,
  onDone,
  doneLink,
}: {
  submit: (password: string, confirmation: string) => Promise<unknown>;
  onDone?: () => void;
  doneLink: string;
}) {
  const { t } = useTranslation();
  const schema = z
    .object({
      password: z.string().min(10, t('auth.password.tooShort')),
      password_confirmation: z.string(),
    })
    .refine((v) => v.password === v.password_confirmation, { message: t('auth.password.mismatch'), path: ['password_confirmation'] });
  const form = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema), defaultValues: { password: '', password_confirmation: '' } });
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  const onSubmit = form.handleSubmit(async (values) => {
    setError(null);
    try {
      await submit(values.password, values.password_confirmation);
      form.reset();
      setDone(true);
      onDone?.();
    } catch (e) {
      const passwordError = fieldError(e, 'password');
      if (passwordError) form.setError('password', { message: passwordError });
      else setError(errorText(t, e));
    }
  });

  if (done) {
    return (
      <div className="flex flex-col gap-4">
        <Alert tone="success">{t('auth.password.done')}</Alert>
        <Button asChild>
          <Link to={doneLink}>{t('auth.signIn.submit')}</Link>
        </Button>
      </div>
    );
  }

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <Field label={t('auth.password.new')} error={form.formState.errors.password?.message}>
        {(props) => <Input {...props} {...form.register('password')} type="password" autoComplete="new-password" />}
      </Field>
      <Field label={t('auth.password.confirm')} error={form.formState.errors.password_confirmation?.message}>
        {(props) => <Input {...props} {...form.register('password_confirmation')} type="password" autoComplete="new-password" />}
      </Field>
      <Button type="submit" disabled={form.formState.isSubmitting}>
        {form.formState.isSubmitting ? t('common.working') : t('auth.password.save')}
      </Button>
    </form>
  );
}
