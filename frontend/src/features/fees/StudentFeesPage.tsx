import { StatusChip } from '@campus-tab/ui';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Receipt as ReceiptIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Field, Input, Select, Spinner, Textarea } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { apiGet } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import { type AdjustmentKind, adjustmentKinds, demandChip, fees, rupees, type StudentFees, validRupees } from './api';

const asDate = (ymd: string) => `${ymd}T12:00:00+05:30`;

/**
 * A student's fee ledger for the year: entries are only added, never edited;
 * a mistake is undone by a reversal entry. Running balance, who did each
 * entry and why. The office can propose an adjustment here.
 */
export function StudentFeesPage() {
  const { t, i18n } = useTranslation();
  const { id = '' } = useParams();
  const data = useQuery({ queryKey: ['fees', 'student', id], queryFn: apiGet<StudentFees>(`/students/${id}/fees`), retry: false });

  if (data.isPending) return <Spinner label={t('common.loading')} />;
  if (data.error || !data.data) return <Alert tone="danger">{errorText(t, data.error)}</Alert>;
  const s = data.data;

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-4">
      <Link to={s.can.collect ? `/fees/counter?student=${s.student.id}` : '/fees'} className="text-sm font-medium text-primary hover:underline">
        {s.can.collect ? t('fees.toCounter') : t('fees.back')}
      </Link>
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h1 className="text-2xl font-semibold tracking-tight">{s.student.name}</h1>
        <p className="text-sm text-muted">
          {s.year?.name} · {t('fees.counter.balance', { amount: rupees(s.balance_paise, i18n.language) })}
        </p>
      </div>
      <Card className="flex flex-col gap-2">
        <h2 className="text-lg font-semibold">{t('fees.student.instalments')}</h2>
        <ul className="flex flex-col divide-y divide-border">
          {s.demands.map((d) => (
            <li key={d.id} className="flex flex-wrap items-center gap-3 py-2 text-sm">
              <span className="min-w-40 flex-1">
                <span className="font-medium">{d.label}</span>
                <span className="text-muted"> · {formatDate(asDate(d.due_on), i18n.language)}</span>
              </span>
              <span className="text-xs text-muted tabular-nums">
                {t('fees.student.paidOf', { paid: rupees(d.paid_paise + d.adjusted_paise, i18n.language), total: rupees(d.due_paise + d.fine_paise, i18n.language) })}
              </span>
              <StatusChip status={demandChip(d.status)} label={t(`fees.status.${d.status}`)} />
            </li>
          ))}
        </ul>
      </Card>
      <LedgerTable data={s} />
      <Card className="flex flex-col gap-2">
        <h2 className="text-lg font-semibold">{t('fees.my.receipts')}</h2>
        {s.receipts.length === 0 ? <p className="text-sm text-muted">{t('fees.my.noReceipts')}</p> : null}
        <ul className="flex flex-col divide-y divide-border">
          {s.receipts.map((r) => (
            <li key={r.id}>
              <Link to={`/fees/receipts/${r.id}`} className="flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm hover:text-primary focus-visible:outline-2 focus-visible:outline-ring">
                <ReceiptIcon aria-hidden="true" className="size-4 text-muted" />
                <span className="min-w-40 flex-1">
                  {r.number} · {t(`fees.modes.${r.mode}`)}
                  {r.issued_by ? ` · ${r.issued_by.name}` : ''}
                </span>
                {r.status !== 'valid' ? <StatusChip status="returned" label={t(`fees.receipt.status.${r.status}`)} /> : null}
                <span className="tabular-nums">{rupees(r.amount_paise, i18n.language)}</span>
              </Link>
            </li>
          ))}
        </ul>
      </Card>
      {s.can.adjust ? <ProposeAdjustment data={s} /> : null}
      {s.adjustments.length ? (
        <Card className="flex flex-col gap-2">
          <h2 className="text-lg font-semibold">{t('fees.adjustments.title')}</h2>
          <ul className="flex flex-col divide-y divide-border">
            {s.adjustments.map((a) => (
              <li key={a.id} className="flex flex-wrap items-center gap-2 py-2 text-sm">
                <span className="min-w-40 flex-1">
                  {t(`fees.kinds.${a.kind}`)} · {rupees(a.amount_paise, i18n.language)} · “{a.reason}”
                </span>
                <StatusChip status={a.status === 'proposed' ? 'pending' : a.status} label={t(`fees.adjustments.status.${a.status}`)} />
              </li>
            ))}
          </ul>
        </Card>
      ) : null}
    </div>
  );
}

/** The year's ledger with a running balance (entries are only added). */
export function LedgerTable({ data }: { data: StudentFees }) {
  const { t, i18n } = useTranslation();
  const heads = new Map(data.demands.flatMap((d) => d.lines.map((l) => [l.head_id, l.head] as const)));
  const instalments = new Map(data.demands.map((d) => [d.id, d.label] as const));
  const rows = data.ledger.reduce<(StudentFees['ledger'][number] & { balance: number })[]>((out, e) => [...out, { ...e, balance: (out.at(-1)?.balance ?? 0) + e.amount_paise }], []);

  return (
    <Card className="flex flex-col gap-2">
      <h2 className="text-lg font-semibold">{t('fees.ledger.title', { year: data.year?.name ?? '' })}</h2>
      <p className="text-xs text-muted">{t('fees.ledger.note')}</p>
      <div tabIndex={0} role="region" aria-label={t('fees.ledger.title', { year: data.year?.name ?? '' })} className="overflow-x-auto focus-visible:outline-2 focus-visible:outline-ring">
        <table className="w-full min-w-[46rem] text-sm">
          <thead>
            <tr className="border-b border-border text-start text-xs text-muted">
              <th scope="col" className="py-2 pe-2 text-start font-medium">{t('fees.ledger.date')}</th>
              <th scope="col" className="py-2 pe-2 text-start font-medium">{t('fees.ledger.entry')}</th>
              <th scope="col" className="py-2 pe-2 text-start font-medium">{t('fees.ledger.details')}</th>
              <th scope="col" className="py-2 pe-2 text-end font-medium">{t('fees.ledger.added')}</th>
              <th scope="col" className="py-2 pe-2 text-end font-medium">{t('fees.ledger.reduced')}</th>
              <th scope="col" className="py-2 text-end font-medium">{t('fees.ledger.balance')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((e) => (
              <tr key={e.id} className="border-b border-border last:border-0">
                <td className="py-2 pe-2 whitespace-nowrap">{e.at ? formatDateTime(e.at, i18n.language) : '–'}</td>
                <td className="py-2 pe-2">{t(`fees.ledger.types.${e.type}`)}</td>
                <td className="py-2 pe-2 text-muted">
                  {[e.type === 'payment' ? (e.demand_id ? instalments.get(e.demand_id) : null) : e.reason, e.head_id ? heads.get(e.head_id) : null, e.receipt_no && !e.reason?.includes(e.receipt_no) ? e.receipt_no : null, e.adjustment_kind ? t(`fees.kinds.${e.adjustment_kind}`) : null, e.by?.name].filter(Boolean).join(' · ')}
                </td>
                <td className="py-2 pe-2 text-end tabular-nums">{e.amount_paise > 0 ? rupees(e.amount_paise, i18n.language) : '—'}</td>
                <td className="py-2 pe-2 text-end tabular-nums">{e.amount_paise < 0 ? rupees(-e.amount_paise, i18n.language) : '—'}</td>
                <td className="py-2 text-end tabular-nums">{rupees(e.balance, i18n.language)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Card>
  );
}

function ProposeAdjustment({ data }: { data: StudentFees }) {
  const { t, i18n } = useTranslation();
  const queryClient = useQueryClient();
  const [kind, setKind] = useState<AdjustmentKind>('waiver');
  const [demand, setDemand] = useState('');
  const [amount, setAmount] = useState('');
  const [percent, setPercent] = useState('');
  const [reason, setReason] = useState('');
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const usePercent = kind === 'rebate' && percent !== '';

  const submit = async () => {
    setBusy(true);
    setMessage(null);
    try {
      await fees.propose({
        student_id: data.student.id,
        kind,
        demand_id: demand || null,
        amount: usePercent ? undefined : amount,
        percent: usePercent ? percent : undefined,
        reason: reason.trim(),
      });
      setAmount('');
      setPercent('');
      setReason('');
      setMessage({ tone: 'success', text: t('fees.adjustments.proposed') });
      void queryClient.invalidateQueries({ queryKey: ['fees'] });
    } catch (e) {
      setMessage({ tone: 'danger', text: errorText(t, e) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card className="flex flex-col gap-3">
      <h2 className="text-lg font-semibold">{t('fees.adjustments.propose')}</h2>
      <p className="text-xs text-muted">{t('fees.adjustments.proposeNote')}</p>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t('fees.adjustments.kind')}>
          {(f) => (
            <Select {...f} value={kind} onChange={(e) => setKind(e.target.value as AdjustmentKind)}>
              {adjustmentKinds.map((k) => (
                <option key={k} value={k}>
                  {t(`fees.kinds.${k}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t('fees.adjustments.instalment')}>
          {(f) => (
            <Select {...f} value={demand} onChange={(e) => setDemand(e.target.value)}>
              <option value="">{t('fees.adjustments.anyInstalment')}</option>
              {data.demands.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.label} · {rupees(d.balance_paise, i18n.language)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t('fees.adjustments.amount')} error={amount && !validRupees(amount) ? t('fees.amountInvalid') : undefined}>
          {(f) => <Input {...f} inputMode="decimal" value={amount} disabled={usePercent} onChange={(e) => setAmount(e.target.value)} />}
        </Field>
        {kind === 'rebate' ? (
          <Field label={t('fees.adjustments.percent')} hint={t('fees.adjustments.percentHint')}>
            {(f) => <Input {...f} inputMode="decimal" value={percent} onChange={(e) => setPercent(e.target.value)} />}
          </Field>
        ) : null}
      </div>
      <Field label={t('fees.adjustments.reason')} hint={t('fees.reasonHint')}>
        {(f) => <Textarea {...f} rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />}
      </Field>
      {message ? <Alert tone={message.tone}>{message.text}</Alert> : null}
      <div>
        <Button className="h-11" disabled={busy || reason.trim().length < 10 || (!usePercent && !validRupees(amount))} onClick={() => void submit()}>
          {t('fees.adjustments.send')}
        </Button>
      </div>
    </Card>
  );
}
