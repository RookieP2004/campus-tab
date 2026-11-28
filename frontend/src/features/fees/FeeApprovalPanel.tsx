import { StatusChip } from '@campus-tab/ui';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Field, Spinner, Textarea } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { apiGet } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { type Adjustment, type Cancellation, type Closing, fees, rupees } from './api';
import { ConfirmClosing } from './ClosingsPage';
import { StepUpField } from './StepUpField';
import { isStepUpRequest, useStepUp } from './useStepUp';

/**
 * A fee item in the approvals inbox: "adjustment:<id>", "cancellation:<id>"
 * or "closing:<id>". Approving an adjustment or a cancellation asks for a
 * fresh authenticator code; the server refuses anyone approving their own.
 */
export function FeeApprovalPanel({ id }: { id: string }) {
  const [type, recordId = ''] = id.split(':');
  if (type === 'adjustment') return <AdjustmentDecision id={recordId} />;
  if (type === 'cancellation') return <CancellationDecision id={recordId} />;
  if (type === 'closing') return <ClosingDecision id={recordId} />;

  return null;
}

function useDecision() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const stepUp = useStepUp();
  const [reason, setReason] = useState('');
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  const act = async (work: () => Promise<unknown>, done: string, sensitive = false) => {
    setBusy(true);
    setMessage(null);
    try {
      await (sensitive ? stepUp.run(work) : work());
      setMessage({ tone: 'success', text: done });
      void queryClient.invalidateQueries({ queryKey: ['fees'] });
      void queryClient.invalidateQueries({ queryKey: ['approvals'] });
    } catch (e) {
      if (!(sensitive && isStepUpRequest(e))) setMessage({ tone: 'danger', text: errorText(t, e) });
    } finally {
      setBusy(false);
    }
  };

  return { stepUp, reason, setReason, message, busy, act };
}

function AdjustmentDecision({ id }: { id: string }) {
  const { t, i18n } = useTranslation();
  const a = useQuery({ queryKey: ['fees', 'adjustment', id], queryFn: apiGet<Adjustment>(`/fees/adjustments/${id}`), retry: false });
  const d = useDecision();
  if (a.isPending) return <Spinner label={t('common.loading')} />;
  if (a.error || !a.data) return <Alert tone="danger">{errorText(t, a.error)}</Alert>;
  const x = a.data;

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start gap-2">
        <div className="min-w-40 flex-1">
          <h2 className="text-lg font-semibold">{x.student.name}</h2>
          <p className="text-sm text-muted">{t(`fees.kinds.${x.kind}`)}</p>
        </div>
        <StatusChip status={x.status === 'proposed' ? 'pending' : x.status} label={t(`fees.adjustments.status.${x.status}`)} />
      </div>
      <p className="text-3xl font-semibold tabular-nums">{rupees(x.amount_paise, i18n.language)}</p>
      <p className="text-sm">“{x.reason}”</p>
      <p className="text-xs text-muted">
        {t('fees.adjustments.proposedBy', { name: x.proposed_by?.name ?? '' })} · {formatDateTime(x.at, i18n.language)}
      </p>
      <Link to={`/fees/students/${x.student.id}`} className="w-fit text-sm font-medium text-primary hover:underline">
        {t('fees.counter.openLedger')}
      </Link>
      {x.own && x.status === 'proposed' ? <Alert tone="info">{t('fees.adjustments.ownNote')}</Alert> : null}
      {x.decided_by ? (
        <p className="text-sm text-muted">
          {t(`fees.adjustments.decided.${x.status}`, { name: x.decided_by.name })}
          {x.decision_reason ? ` · “${x.decision_reason}”` : ''}
        </p>
      ) : null}
      {d.message ? <Alert tone={d.message.tone}>{d.message.text}</Alert> : null}
      {x.can?.decide ? (
        <div className="flex flex-col gap-3">
          <Field label={t('fees.decision.reason')}>{(f) => <Textarea {...f} rows={2} maxLength={500} value={d.reason} onChange={(e) => d.setReason(e.target.value)} />}</Field>
          <StepUpField state={d.stepUp} />
          <div className="flex flex-wrap gap-2">
            <Button className="h-11" disabled={d.busy || !d.stepUp.ready} onClick={() => void d.act(() => fees.approveAdjustment(x.id, d.reason.trim()), t('fees.decision.approved'), true)}>
              {d.stepUp.needed ? t('fees.decision.confirmApprove') : t('fees.decision.approve')}
            </Button>
            <Button variant="outline" className="h-11" disabled={d.busy || d.reason.trim().length < 5} onClick={() => void d.act(() => fees.rejectAdjustment(x.id, d.reason.trim()), t('fees.decision.rejected'))}>
              {t('fees.decision.reject')}
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  );
}

function CancellationDecision({ id }: { id: string }) {
  const { t, i18n } = useTranslation();
  const c = useQuery({ queryKey: ['fees', 'cancellation', id], queryFn: apiGet<Cancellation>(`/fees/cancellations/${id}`), retry: false });
  const d = useDecision();
  if (c.isPending) return <Spinner label={t('common.loading')} />;
  if (c.error || !c.data) return <Alert tone="danger">{errorText(t, c.error)}</Alert>;
  const x = c.data;

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start gap-2">
        <div className="min-w-40 flex-1">
          <h2 className="text-lg font-semibold">{t('fees.cancellation.title', { number: x.receipt_no })}</h2>
          <p className="text-sm text-muted">{x.student.name}</p>
        </div>
        <StatusChip status={['requested', 'proposed'].includes(x.status) ? 'pending' : x.status === 'approved' ? 'approved' : 'rejected'} label={t(`fees.cancellation.status.${x.status}`)} />
      </div>
      <p className="text-3xl font-semibold tabular-nums">{rupees(x.amount_paise, i18n.language)}</p>
      <p className="text-sm">“{x.reason}”</p>
      <p className="text-xs text-muted">
        {t('fees.cancellation.askedBy', { name: x.requested_by?.name ?? '' })}
        {x.proposed_by && x.proposed_by.id !== x.requested_by?.id ? ` · ${t('fees.cancellation.forwardedBy', { name: x.proposed_by.name })}` : ''}
      </p>
      <Link to={`/fees/receipts/${x.receipt_id}`} className="w-fit text-sm font-medium text-primary hover:underline">
        {t('fees.cancellation.openReceipt')}
      </Link>
      <p className="text-xs text-muted">{t('fees.cancellation.effect')}</p>
      {d.message ? <Alert tone={d.message.tone}>{d.message.text}</Alert> : null}
      {x.can.approve || x.can.forward || x.can.reject ? (
        <div className="flex flex-col gap-3">
          <Field label={t('fees.decision.reason')} hint={t('fees.reasonHint')}>
            {(f) => <Textarea {...f} rows={2} maxLength={500} value={d.reason} onChange={(e) => d.setReason(e.target.value)} />}
          </Field>
          <StepUpField state={d.stepUp} />
          <div className="flex flex-wrap gap-2">
            {x.can.approve ? (
              <Button className="h-11" disabled={d.busy || d.reason.trim().length < 10 || !d.stepUp.ready} onClick={() => void d.act(() => fees.approveCancellation(x.id, d.reason.trim()), t('fees.decision.approved'), true)}>
                {d.stepUp.needed ? t('fees.decision.confirmApprove') : t('fees.decision.approve')}
              </Button>
            ) : null}
            {x.can.forward ? (
              <Button variant="outline" className="h-11" disabled={d.busy} onClick={() => void d.act(() => fees.forwardCancellation(x.id), t('fees.cancellation.forwarded'))}>
                {t('fees.cancellation.forward')}
              </Button>
            ) : null}
            {x.can.reject ? (
              <Button variant="outline" className="h-11" disabled={d.busy || d.reason.trim().length < 10} onClick={() => void d.act(() => fees.rejectCancellation(x.id, d.reason.trim()), t('fees.decision.rejected'))}>
                {t('fees.decision.reject')}
              </Button>
            ) : null}
          </div>
        </div>
      ) : null}
    </div>
  );
}

function ClosingDecision({ id }: { id: string }) {
  const { t } = useTranslation();
  const c = useQuery({ queryKey: ['fees', 'closing', id], queryFn: apiGet<Closing & { can: { confirm: boolean } }>(`/fees/closings/${id}`), retry: false });
  if (c.isPending) return <Spinner label={t('common.loading')} />;
  if (c.error || !c.data) return <Alert tone="danger">{errorText(t, c.error)}</Alert>;

  return (
    <div className="flex flex-col gap-3">
      <h2 className="text-lg font-semibold">{t('fees.closings.confirmTitle')}</h2>
      {c.data.can.confirm ? <ConfirmClosing closing={c.data} /> : <p className="text-sm text-muted">{t(`fees.closings.status.${c.data.status}`)}</p>}
    </div>
  );
}
