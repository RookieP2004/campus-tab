import { Download, Printer } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Alert, Field, Input, Spinner } from '@/components/ui/form';
import { errorText } from './errors';

/**
 * Setting up two-step sign-in: a QR code for the authenticator app (drawn in
 * the browser, so the secret never goes to another service), the key to
 * type in by hand, a code to confirm, then the recovery codes, shown once,
 * with download and print.
 */
export function MfaEnrolPanel({
  start,
  confirm,
  onFinished,
  accountLabel,
}: {
  start: () => Promise<{ secret: string; otpauth_url: string }>;
  confirm: (code: string) => Promise<{ recovery_codes: string[] }>;
  onFinished: () => void;
  accountLabel: string;
}) {
  const { t } = useTranslation();
  const [setup, setSetup] = useState<{ secret: string; otpauth_url: string } | null>(null);
  const [codes, setCodes] = useState<string[] | null>(null);
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const started = useRef(false);

  useEffect(() => {
    if (started.current) return;
    started.current = true;
    start()
      .then(setSetup)
      .catch((e: unknown) => setError(errorText(t, e)));
  }, [start, t]);

  if (codes) return <RecoveryCodes codes={codes} accountLabel={accountLabel} onFinished={onFinished} />;

  if (!setup) return error ? <Alert tone="danger">{error}</Alert> : <Spinner label={t('common.loading')} />;

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      setCodes((await confirm(code.trim())).recovery_codes);
    } catch (e) {
      setCode('');
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  const grouped = setup.secret.match(/.{1,4}/g)?.join(' ') ?? setup.secret;

  return (
    <div className="flex flex-col gap-5">
      <ol className="flex list-decimal flex-col gap-1 ps-5 text-sm text-muted">
        <li>{t('auth.enrol.step1')}</li>
        <li>{t('auth.enrol.step2')}</li>
        <li>{t('auth.enrol.step3')}</li>
      </ol>
      <div className="flex flex-col items-center gap-3 sm:flex-row sm:items-start">
        {/* White background in both themes so every camera can read it. */}
        <div className="rounded-card bg-white p-3">
          <QRCodeSVG value={setup.otpauth_url} size={168} level="M" title={t('auth.enrol.qrAlt')} />
        </div>
        <div className="flex min-w-0 flex-col gap-1.5">
          <span className="text-sm font-medium">{t('auth.enrol.keyLabel')}</span>
          <code className="rounded-input border border-border bg-surface-muted px-3 py-2 font-mono text-sm break-all select-all" data-testid="mfa-secret">
            {grouped}
          </code>
          <span className="text-xs text-muted">{t('auth.enrol.keyHint')}</span>
        </div>
      </div>
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Field label={t('auth.mfa.code')}>
          {(props) => (
            <Input {...props} value={code} onChange={(e) => setCode(e.target.value)} inputMode="numeric" maxLength={6} autoComplete="one-time-code" className="font-mono tracking-widest" />
          )}
        </Field>
        <Button type="submit" disabled={busy || !/^\d{6}$/.test(code.trim())}>
          {busy ? t('common.working') : t('auth.enrol.confirm')}
        </Button>
      </form>
    </div>
  );
}

function RecoveryCodes({ codes, accountLabel, onFinished }: { codes: string[]; accountLabel: string; onFinished: () => void }) {
  const { t } = useTranslation();
  const [saved, setSaved] = useState(false);
  const text = [t('auth.enrol.fileHeading', { account: accountLabel }), '', ...codes, '', t('auth.enrol.fileNote')].join('\n');

  const download = () => {
    const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'campus-tab-recovery-codes.txt';
    link.click();
    URL.revokeObjectURL(url);
  };

  const print = () => {
    const frame = document.createElement('iframe');
    frame.style.position = 'fixed';
    frame.style.width = '0';
    frame.style.height = '0';
    frame.style.border = '0';
    document.body.appendChild(frame);
    const doc = frame.contentDocument;
    if (doc) {
      const pre = doc.createElement('pre');
      pre.textContent = text;
      pre.style.font = '14px monospace';
      doc.body.appendChild(pre);
      frame.contentWindow?.print();
    }
    setTimeout(() => frame.remove(), 1000);
  };

  return (
    <div className="flex flex-col gap-4">
      <Alert tone="warning">{t('auth.enrol.codesIntro')}</Alert>
      <ul className="grid grid-cols-2 gap-2 rounded-card border border-border bg-surface-muted p-3 font-mono text-sm" data-testid="recovery-codes">
        {codes.map((c) => (
          <li key={c}>{c}</li>
        ))}
      </ul>
      <div className="flex flex-wrap gap-2">
        <Button type="button" variant="outline" onClick={download}>
          <Download aria-hidden="true" />
          {t('auth.enrol.download')}
        </Button>
        <Button type="button" variant="outline" onClick={print}>
          <Printer aria-hidden="true" />
          {t('auth.enrol.print')}
        </Button>
      </div>
      <label className="flex items-start gap-2 text-sm">
        <input type="checkbox" className="mt-1 size-4" checked={saved} onChange={(e) => setSaved(e.target.checked)} />
        {t('auth.enrol.savedConfirm')}
      </label>
      <Button type="button" disabled={!saved} onClick={onFinished}>
        {t('auth.enrol.finish')}
      </Button>
    </div>
  );
}
