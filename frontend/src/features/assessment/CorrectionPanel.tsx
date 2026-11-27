import { StatusChip } from '@campus-tab/ui';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Field, Input, Spinner, Textarea } from '@/components/ui/form';
import { signIn } from '@/features/auth/api';
import { errorText } from '@/features/auth/errors';
import { ApiError, apiGet } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { cellText, type Correction, marks } from './api';

/**
 * A correction to a locked mark, in the Principal's approvals inbox.
 * Approving needs a fresh authenticator code (step-up) and a typed reason.
 * When the Principal raised it themself, approving is a separate step: the
 * code must be entered after it was raised, and it is shown to the Chief as
 * self-approved.
 */
export function CorrectionPanel({ id }: { id: string }) {
  const { t, i18n } = useTranslation();
  const queryClient = useQueryClient();
  const correction = useQuery({ queryKey: ['marks', 'correction', id], queryFn: apiGet<Correction>(`/corrections/${id}`), retry: false });
  const [reason, setReason] = useState('');
  const [code, setCode] = useState('');
  const [needCode, setNeedCode] = useState<null | { self: boolean }>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<string | null>(null);

  if (correction.isPending) return <Spinner label={t('common.loading')} />;
  if (correction.error || !correction.data) return <Alert tone="danger">{errorText(t, correction.error)}</Alert>;
  const c = correction.data;

  const after = (updated: Correction, message: string) => {
    queryClient.setQueryData(['marks', 'correction', id], updated);
    void queryClient.invalidateQueries({ queryKey: ['approvals'] });
    void queryClient.invalidateQueries({ queryKey: ['marks'] });
    setDone(message);
    setNeedCode(null);
    setCode('');
  };

  const approve = async () => {
    setBusy(true);
    setError(null);
    try {
      if (needCode) await signIn.stepUp(code.trim());
      after(await marks.approve(c.id, reason.trim()), t('tests.correction.approvedDone', { name: c.student }));
    } catch (e) {
      if (e instanceof ApiError && e.code === 'step_up_required') {
        setNeedCode({ self: Boolean((e.details as { self?: boolean } | undefined)?.self) });
        setCode('');
        if (needCode) setError(errorText(t, e));
      } else {
        setError(errorText(t, e));
      }
    } finally {
      setBusy(false);
    }
  };

  const reject = async () => {
    setBusy(true);
    setError(null);
    try {
      after(await marks.reject(c.id, reason.trim()), t('tests.correction.rejectedDone'));
    } catch (e) {
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  const enough = reason.trim().length >= 10;

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start gap-2">
        <div className="min-w-0 flex-1">
          <h2 className="text-lg font-semibold">{c.student}</h2>
          <p className="text-sm text-muted">{c.label}</p>
        </div>
        <StatusChip status={c.status === 'pending' ? 'pending' : c.status} label={t(`tests.correction.status.${c.status}`)} />
      </div>
      {done ? <Alert tone="success">{done}</Alert> : null}
      <dl className="grid grid-cols-2 gap-2">
        <div className="rounded-input bg-surface-muted p-3">
          <dt className="text-xs text-muted">{t('tests.correction.lockedMark')}</dt>
          <dd className="text-2xl font-semibold tabular-nums">{cellText(c.old) || '–'}</dd>
        </div>
        <div className="rounded-input bg-surface-muted p-3">
          <dt className="text-xs text-muted">{t('tests.correction.asked')}</dt>
          <dd className="text-2xl font-semibold tabular-nums">{cellText(c.new) || '–'}</dd>
        </div>
      </dl>
      <section className="flex flex-col gap-1">
        <h3 className="text-xs font-semibold tracking-wide text-muted uppercase">{t('tests.correction.reason')}</h3>
        <p className="text-sm">“{c.reason}”</p>
        <p className="text-xs text-muted">
          {t('tests.correction.askedBy', { name: c.requested_by ?? '' })}
          {c.requested_by_role ? ` (${t(`roles.${c.requested_by_role}`, { defaultValue: c.requested_by_role })})` : ''} · {formatDateTime(c.requested_at, i18n.language)}
        </p>
      </section>
      <p className="text-sm">
        <Link to={`/tests/papers/${c.paper_id}`} className="font-medium text-primary hover:underline">
          {t('tests.correction.openPaper')}
        </Link>
      </p>
      {c.own && c.status === 'pending' ? <Alert tone="warning">{t('tests.correction.ownWarning')}</Alert> : null}
      {c.status !== 'pending' ? (
        <p className="text-sm text-muted">
          {t(`tests.correction.decidedBy.${c.status}`, { name: c.decided_by ?? '' })} · {formatDateTime(c.decided_at, i18n.language)}
          {c.decision_reason ? ` · “${c.decision_reason}”` : ''}
          {c.self_approved ? ` · ${t('approvals.flags.self_approved')}` : ''}
        </p>
      ) : null}
      {c.can.decide ? (
        <div className="flex flex-col gap-3">
          <Field label={t('tests.correction.yourReason')} hint={t('tests.correction.yourReasonHint')}>
            {(p) => <Textarea {...p} rows={3} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />}
          </Field>
          {needCode ? (
            <div className="flex flex-col gap-2 rounded-input border border-border p-3">
              <p className="flex items-start gap-2 text-sm">
                <ShieldCheck aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-primary" />
                {needCode.self ? t('tests.correction.selfStepUp') : t('stepUp.body')}
              </p>
              <Field label={t('auth.mfa.code')}>
                {(p) => <Input {...p} value={code} onChange={(e) => setCode(e.target.value)} inputMode="numeric" maxLength={6} autoComplete="one-time-code" className="w-40 font-mono tracking-widest" />}
              </Field>
            </div>
          ) : null}
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <div className="flex flex-wrap gap-2">
            <Button className="h-11" disabled={busy || !enough || (needCode !== null && !/^\d{6}$/.test(code.trim()))} onClick={() => void approve()}>
              {needCode ? t('tests.correction.confirmApprove') : t('tests.correction.approve')}
            </Button>
            <Button variant="outline" className="h-11 text-danger" disabled={busy || !enough} onClick={() => void reject()}>
              {t('tests.correction.reject')}
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  );
}
