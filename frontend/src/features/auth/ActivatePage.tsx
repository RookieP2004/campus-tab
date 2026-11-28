import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { z } from 'zod';
import { Button } from '@/components/ui/button';
import { Alert, Field, Input } from '@/components/ui/form';
import { usePreferences } from '@/stores/preferences';
import { useSchoolCode } from '@/stores/school';
import { useSessionState } from '@/stores/session';
import { activation } from './api';
import { AuthLayout } from './AuthLayout';
import { errorText, fieldError } from './errors';

type Step = { step: 'key' } | { step: 'chief'; key: string; school: { code: string; name: string }; sentTo: string };

/**
 * Activating a school with its licence key: the key, then the code sent to
 * the school's registered email and phone, then the Chief Admin's own
 * details and password. Two-step sign-in is set up straight after.
 */
export function ActivatePage() {
  const { t } = useTranslation();
  const [state, setState] = useState<Step>({ step: 'key' });

  return (
    <AuthLayout title={t('auth.activate.title')} subtitle={state.step === 'key' ? t('auth.activate.subtitle') : t('auth.activate.chiefSubtitle', { school: state.school.name })}>
      {state.step === 'key' ? (
        <KeyStep
          onSent={(key, school, sentTo) => setState({ step: 'chief', key, school, sentTo })}
        />
      ) : (
        <ChiefStep keyValue={state.key} school={state.school} sentTo={state.sentTo} />
      )}
      <div className="mt-6 border-t border-border pt-4 text-sm">
        <Link to="/school" className="text-primary underline-offset-2 hover:underline">
          {t('auth.activate.haveAccount')}
        </Link>
      </div>
    </AuthLayout>
  );
}

function KeyStep({ onSent }: { onSent: (key: string, school: { code: string; name: string }, sentTo: string) => void }) {
  const { t } = useTranslation();
  const [key, setKey] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    const normalised = key.trim().toUpperCase().replace(/\s+/g, '');
    if (!/^CTAB(-[A-Z0-9]{4}){4}$/.test(normalised)) {
      setError(t('auth.apiErrors.licence_key_invalid'));
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const result = await activation.requestOtp(normalised);
      onSent(normalised, result.school, [result.sent_to.email, result.sent_to.phone].filter(Boolean).join(', '));
    } catch (e) {
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <Field label={t('auth.activate.key')} hint={t('auth.activate.keyHint')}>
        {(props) => (
          <Input {...props} value={key} onChange={(e) => setKey(e.target.value.toUpperCase())} placeholder="CTAB-XXXX-XXXX-XXXX-XXXX" autoComplete="off" className="font-mono uppercase" />
        )}
      </Field>
      <Button type="submit" disabled={busy}>
        {busy ? t('common.working') : t('auth.activate.sendCode')}
      </Button>
    </form>
  );
}

function ChiefStep({ keyValue, school, sentTo }: { keyValue: string; school: { code: string; name: string }; sentTo: string }) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const setCode = useSchoolCode((s) => s.setCode);
  const resume = useSessionState((s) => s.resume);
  const schema = z
    .object({
      otp: z.string().regex(/^\d{6}$/, t('auth.errors.codeRequired')),
      name: z.string().trim().min(2, t('auth.activate.nameRequired')),
      email: z.email(t('auth.activate.emailInvalid')),
      phone: z.string().regex(/^\+?\d{10,15}$/, t('auth.otp.phoneInvalid')),
      password: z.string().min(12, t('auth.password.tooShort')),
      password_confirmation: z.string(),
    })
    .refine((v) => v.password === v.password_confirmation, { message: t('auth.password.mismatch'), path: ['password_confirmation'] });
  type Values = z.infer<typeof schema>;
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { otp: '', name: '', email: '', phone: '', password: '', password_confirmation: '' },
  });
  const [error, setError] = useState<string | null>(null);

  const submit = form.handleSubmit(async (v) => {
    setError(null);
    try {
      await activation.activate({
        key: keyValue,
        otp: v.otp,
        chief: {
          name: v.name,
          email: v.email,
          phone: v.phone,
          password: v.password,
          password_confirmation: v.password_confirmation,
          // The language picked on this page becomes the Chief's own.
          language: usePreferences.getState().language,
        },
      });
      setCode(school.code);
      resume();
      navigate('/mfa/enrol', { replace: true });
    } catch (e) {
      const pw = fieldError(e, 'chief.password');
      if (pw) form.setError('password', { message: pw });
      else setError(errorText(t, e));
    }
  });

  const err = form.formState.errors;

  return (
    <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
      <Alert tone="info">{t('auth.activate.codeSent', { to: sentTo })}</Alert>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <Field label={t('auth.activate.code')} error={err.otp?.message}>
        {(props) => <Input {...props} {...form.register('otp')} inputMode="numeric" maxLength={6} autoComplete="one-time-code" className="font-mono tracking-widest" />}
      </Field>
      <Field label={t('auth.activate.chiefName')} error={err.name?.message}>
        {(props) => <Input {...props} {...form.register('name')} autoComplete="name" />}
      </Field>
      <Field label={t('auth.activate.chiefEmail')} error={err.email?.message}>
        {(props) => <Input {...props} {...form.register('email')} type="email" autoComplete="email" />}
      </Field>
      <Field label={t('auth.activate.chiefPhone')} error={err.phone?.message}>
        {(props) => <Input {...props} {...form.register('phone')} type="tel" autoComplete="tel" placeholder="+91…" />}
      </Field>
      <Field label={t('auth.password.new')} hint={t('auth.password.rules')} error={err.password?.message}>
        {(props) => <Input {...props} {...form.register('password')} type="password" autoComplete="new-password" />}
      </Field>
      <Field label={t('auth.password.confirm')} error={err.password_confirmation?.message}>
        {(props) => <Input {...props} {...form.register('password_confirmation')} type="password" autoComplete="new-password" />}
      </Field>
      <Button type="submit" disabled={form.formState.isSubmitting}>
        {form.formState.isSubmitting ? t('common.working') : t('auth.activate.submit')}
      </Button>
    </form>
  );
}
