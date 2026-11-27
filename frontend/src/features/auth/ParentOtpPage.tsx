import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, Navigate, useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Field, Input } from '@/components/ui/form';
import { useSchoolCode } from '@/stores/school';
import { useSessionState } from '@/stores/session';
import { meKey, signIn, usePublicConfig } from './api';
import { AuthLayout } from './AuthLayout';
import { errorText } from './errors';

/** Parents sign in with their phone and a code sent by SMS. */
export function ParentOtpPage() {
  const { t } = useTranslation();
  const code = useSchoolCode((s) => s.code);
  const { data: school } = usePublicConfig(code);

  if (!code) return <Navigate to="/school" replace />;

  return (
    <AuthLayout school={school ?? null} title={t('auth.otp.title')} subtitle={t('auth.otp.subtitle')}>
      <ParentOtpForm schoolCode={code} />
      <div className="mt-6 border-t border-border pt-4 text-sm">
        <Link to="/sign-in" className="text-primary underline-offset-2 hover:underline">
          {t('auth.otp.passwordLink')}
        </Link>
      </div>
    </AuthLayout>
  );
}

export function ParentOtpForm({ schoolCode, onDone }: { schoolCode: string; onDone?: () => void }) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const resume = useSessionState((s) => s.resume);
  const [phone, setPhone] = useState('');
  const [otp, setOtp] = useState('');
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const normalisedPhone = () => {
    const digits = phone.replace(/[\s-]/g, '');
    return /^\d{10}$/.test(digits) ? `+91${digits}` : digits;
  };

  const request = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!/^\+?\d{10,15}$/.test(normalisedPhone())) {
      setError(t('auth.otp.phoneInvalid'));
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await signIn.requestOtp({ school_code: schoolCode, phone: normalisedPhone() });
      setSent(true);
    } catch (e) {
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  const verify = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const result = await signIn.verifyOtp({ school_code: schoolCode, phone: normalisedPhone(), code: otp.trim() });
      if (!result.mfa_required) {
        resume();
        await queryClient.invalidateQueries({ queryKey: meKey });
        if (onDone) onDone();
        else navigate('/', { replace: true });
      }
    } catch (e) {
      setOtp('');
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  return sent ? (
    <form onSubmit={verify} className="flex flex-col gap-4" noValidate>
      <Alert tone="info">{t('auth.otp.sent')}</Alert>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <Field label={t('auth.otp.code')}>
        {(props) => (
          <Input {...props} value={otp} onChange={(e) => setOtp(e.target.value)} inputMode="numeric" maxLength={6} autoComplete="one-time-code" autoFocus className="font-mono tracking-widest" />
        )}
      </Field>
      <Button type="submit" disabled={busy || otp.trim().length !== 6}>
        {busy ? t('common.working') : t('auth.otp.verify')}
      </Button>
      <button type="button" className="text-sm text-primary underline-offset-2 hover:underline" onClick={() => setSent(false)}>
        {t('auth.otp.again')}
      </button>
    </form>
  ) : (
    <form onSubmit={request} className="flex flex-col gap-4" noValidate>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <Field label={t('auth.otp.phone')} hint={t('auth.otp.phoneHint')}>
        {(props) => <Input {...props} value={phone} onChange={(e) => setPhone(e.target.value)} type="tel" inputMode="tel" autoComplete="tel" />}
      </Field>
      <Button type="submit" disabled={busy}>
        {busy ? t('common.working') : t('auth.otp.send')}
      </Button>
    </form>
  );
}
