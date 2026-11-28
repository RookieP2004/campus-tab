import { StatusChip } from '@campus-tab/ui';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Printer } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Dialog, Field, Spinner, Textarea } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { holds } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { apiGet } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { fees, type ReceiptDetail, rupees } from './api';

const asDate = (ymd: string) => `${ymd}T12:00:00+05:30`;

/**
 * One receipt, ready to print (only the receipt prints). A cancelled or
 * bounced receipt keeps its number and says so. The office can ask for a
 * cancellation; the counter clears or bounces a cheque.
 */
export function ReceiptPage() {
  const { t, i18n } = useTranslation();
  const { id = '' } = useParams();
  const [params] = useSearchParams();
  const me = useCurrentUser();
  const queryClient = useQueryClient();
  const receipt = useQuery({ queryKey: ['fees', 'receipt', id], queryFn: apiGet<ReceiptDetail>(`/receipts/${id}`), retry: false });
  const [dialog, setDialog] = useState<null | 'cancel' | 'bounce'>(null);
  const [reason, setReason] = useState('');
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const printNow = params.get('print') === '1' && receipt.data !== undefined;

  useEffect(() => {
    if (printNow) {
      const timer = setTimeout(() => window.print(), 300);

      return () => clearTimeout(timer);
    }
  }, [printNow]);

  if (receipt.isPending) return <Spinner label={t('common.loading')} />;
  if (receipt.error || !receipt.data) return <Alert tone="danger">{errorText(t, receipt.error)}</Alert>;
  const r = receipt.data;

  const act = async (work: () => Promise<unknown>, done: string) => {
    setBusy(true);
    setMessage(null);
    try {
      await work();
      setMessage({ tone: 'success', text: done });
      setDialog(null);
      setReason('');
      void queryClient.invalidateQueries({ queryKey: ['fees'] });
    } catch (e) {
      setMessage({ tone: 'danger', text: errorText(t, e) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-4">
      <style>{'@media print { body * { visibility: hidden !important; } #receipt, #receipt * { visibility: visible !important; } #receipt { position: absolute; inset: 0; border: 0; } }'}</style>
      <Link to={holds(me, 'fees.dues_view', 'school') ? `/fees/students/${r.student.id}` : '/fees'} className="text-sm font-medium text-primary hover:underline">
        {t('fees.back')}
      </Link>
      {message ? <Alert tone={message.tone}>{message.text}</Alert> : null}
      {r.cancellation_pending && !message ? <Alert tone="info">{t('fees.receipt.cancelPending')}</Alert> : null}
      <Card id="receipt" className="flex flex-col gap-4">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p className="text-lg font-semibold">{me.school.name}</p>
            <h1 className="text-sm text-muted">{t('fees.receipt.title')}</h1>
          </div>
          <div className="sm:text-end">
            <p className="font-mono text-sm font-semibold">{r.number}</p>
            <p className="text-xs text-muted">{formatDate(asDate(r.issued_on), i18n.language)}</p>
          </div>
        </div>
        {r.status !== 'valid' ? <StatusChip status="returned" label={t(`fees.receipt.status.${r.status}`)} className="w-fit" /> : null}
        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
          <dt className="text-muted">{t('fees.receipt.student')}</dt>
          <dd className="font-medium">
            {r.student.name}
            {r.section ? ` · ${r.section}` : ''}
          </dd>
          <dt className="text-muted">{t('fees.receipt.mode')}</dt>
          <dd>
            {t(`fees.modes.${r.mode}`)}
            {r.reference ? ` · ${r.reference}` : ''}
            {r.bank ? ` · ${r.bank}` : ''}
          </dd>
          {r.issued_by ? (
            <>
              <dt className="text-muted">{t('fees.receipt.by')}</dt>
              <dd>{r.issued_by.name}</dd>
            </>
          ) : null}
        </dl>
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border text-xs text-muted">
              <th scope="col" className="py-1 text-start font-medium">{t('fees.receipt.for')}</th>
              <th scope="col" className="py-1 text-end font-medium">{t('fees.receipt.amount')}</th>
            </tr>
          </thead>
          <tbody>
            {r.lines.map((l, i) => (
              <tr key={i} className="border-b border-border">
                <td className="py-1">
                  {l.label} · {l.head}
                </td>
                <td className="py-1 text-end tabular-nums">{rupees(l.amount_paise, i18n.language)}</td>
              </tr>
            ))}
            <tr>
              <th scope="row" className="py-2 text-start">{t('fees.receipt.total')}</th>
              <td className="py-2 text-end text-lg font-semibold tabular-nums">{rupees(r.amount_paise, i18n.language)}</td>
            </tr>
          </tbody>
        </table>
        {r.payment_status === 'pending_clearance' ? <p className="text-xs text-muted">{t('fees.receipt.pending')}</p> : null}
      </Card>
      <div className="flex flex-wrap gap-2">
        <Button variant="outline" className="h-11" onClick={() => window.print()}>
          <Printer aria-hidden="true" />
          {t('fees.counter.print')}
        </Button>
        {r.can.clear ? (
          <>
            <Button variant="outline" className="h-11" disabled={busy} onClick={() => void act(() => fees.clear(r.payment_id), t('fees.receipt.cleared'))}>
              {t('fees.receipt.clear')}
            </Button>
            <Button variant="outline" className="h-11" disabled={busy} onClick={() => setDialog('bounce')}>
              {t('fees.receipt.bounce')}
            </Button>
          </>
        ) : null}
        {r.can.cancel ? (
          <Button variant="outline" className="h-11" disabled={busy} onClick={() => setDialog('cancel')}>
            {t('fees.receipt.cancel')}
          </Button>
        ) : null}
      </div>
      <Dialog open={dialog !== null} title={dialog === 'bounce' ? t('fees.receipt.bounceTitle') : t('fees.receipt.cancelTitle')} onClose={() => setDialog(null)}>
        <p className="text-sm">{dialog === 'bounce' ? t('fees.receipt.bounceBody') : t('fees.receipt.cancelBody')}</p>
        <Field label={t('fees.adjustments.reason')} hint={t('fees.reasonHint')}>
          {(f) => <Textarea {...f} rows={3} maxLength={300} value={reason} onChange={(e) => setReason(e.target.value)} />}
        </Field>
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="outline" className="h-11" onClick={() => setDialog(null)}>
            {t('common.cancel')}
          </Button>
          <Button
            className="h-11"
            disabled={busy || reason.trim().length < 10}
            onClick={() =>
              void (dialog === 'bounce'
                ? act(() => fees.bounce(r.payment_id, reason.trim()), t('fees.receipt.bounced'))
                : act(() => fees.cancelReceipt(r.id, reason.trim()), t('fees.receipt.cancelAsked')))
            }
          >
            {dialog === 'bounce' ? t('fees.receipt.bounceConfirm') : t('fees.receipt.cancelConfirm')}
          </Button>
        </div>
      </Dialog>
    </div>
  );
}
