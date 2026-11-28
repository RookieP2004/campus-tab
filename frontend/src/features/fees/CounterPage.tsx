import { StatusChip } from '@campus-tab/ui';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Printer, Search, Wallet } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Field, Input, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { apiGet } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { type ClosingsView, demandChip, fees, type PaymentResult, rupeeInput, rupees, type StudentFees, sumPaise, typedPaise, validRupees } from './api';
import { LedgerTable } from './StudentFeesPage';

const asDate = (ymd: string) => `${ymd}T12:00:00+05:30`;
const modes = ['cash', 'cheque', 'dd', 'bank_transfer', 'upi_counter'] as const;

/**
 * The fee counter (Fee Collector duty, Clerk, Principal, Chief): find the
 * student, tick the dues, record how it was paid, collect and print. The
 * receipt number comes from the school's counter when the payment is saved;
 * numbers are in sequence and never reused.
 */
export function CounterPage() {
  const { t, i18n } = useTranslation();
  const queryClient = useQueryClient();
  const [params, setParams] = useSearchParams();
  const studentId = params.get('student');
  const [q, setQ] = useState('');
  const [search, setSearch] = useState('');
  const results = useQuery({
    queryKey: ['fees', 'search', search],
    queryFn: apiGet<{ id: string; name: string; admission_no: string; section_label: string | null }[]>('/fees/students', { q: search }),
    enabled: search.length >= 2,
    retry: false,
  });
  const today = useQuery({ queryKey: ['fees', 'closings'], queryFn: apiGet<ClosingsView>('/fees/closings'), retry: false });
  const data = useQuery({ queryKey: ['fees', 'student', studentId], queryFn: apiGet<StudentFees>(`/students/${studentId}/fees`), enabled: studentId !== null, retry: false });
  const [selected, setSelected] = useState<Record<string, boolean>>({});
  const [mode, setMode] = useState<(typeof modes)[number]>('cash');
  const [amount, setAmount] = useState<string | null>(null);
  const [reference, setReference] = useState('');
  const [bank, setBank] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<PaymentResult | null>(null);

  const open = (data.data?.demands ?? []).filter((d) => d.balance_paise > 0);
  const picked = open.filter((d) => selected[d.id] ?? true);
  const total = sumPaise(picked.map((d) => d.balance_paise));
  const typed = amount ?? (total > 0 ? rupeeInput(total) : '');
  const needsRef = mode !== 'cash';

  const choose = (id: string) => {
    setParams({ student: id }, { replace: true });
    setSelected({});
    setAmount(null);
    setDone(null);
    setError(null);
  };

  const collect = async () => {
    if (!studentId) return;
    setBusy(true);
    setError(null);
    try {
      const payment = await fees.collect({
        student_id: studentId,
        mode,
        amount: typed,
        reference: needsRef ? reference.trim() : undefined,
        bank: bank.trim() || undefined,
        demand_ids: picked.map((d) => d.id),
      });
      setDone(payment);
      setAmount(null);
      setReference('');
      setBank('');
      void queryClient.invalidateQueries({ queryKey: ['fees'] });
    } catch (e) {
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-4">
      <Link to="/fees" className="text-sm font-medium text-primary hover:underline">
        {t('fees.back')}
      </Link>
      {today.data ? (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-card border border-border bg-surface-muted px-4 py-3">
          <p className="flex items-center gap-2 text-sm">
            <Wallet aria-hidden="true" className="size-4 text-muted" />
            {t('fees.counter.today', { count: today.data.open.count, amount: rupees(today.data.open.total_paise, i18n.language) })}
          </p>
          <Button asChild variant="outline" className="h-11">
            <Link to="/fees/closings">{t('fees.closings.closeMyDay')}</Link>
          </Button>
        </div>
      ) : null}
      <h1 className="text-2xl font-semibold tracking-tight">{t('fees.counter.title')}</h1>
      <form
        role="search"
        className="flex flex-wrap items-end gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          setSearch(q.trim());
        }}
      >
        <Field label={t('fees.counter.find')} className="min-w-0 flex-1">
          {(f) => <Input {...f} value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('fees.counter.findHint')} />}
        </Field>
        <Button type="submit" className="h-11" disabled={q.trim().length < 2}>
          <Search aria-hidden="true" />
          {t('fees.counter.search')}
        </Button>
      </form>
      {results.data && search ? (
        <ul className="flex flex-col gap-1" aria-label={t('fees.counter.results')}>
          {results.data.length === 0 ? <li className="text-sm text-muted">{t('fees.counter.noMatch')}</li> : null}
          {results.data.map((s) => (
            <li key={s.id}>
              <button
                type="button"
                onClick={() => {
                  choose(s.id);
                  setSearch('');
                }}
                className="flex min-h-11 w-full items-center gap-3 rounded-input border border-border bg-surface px-3 text-start text-sm hover:border-primary focus-visible:outline-2 focus-visible:outline-ring"
              >
                <span className="min-w-0 flex-1 font-medium">{s.name}</span>
                <span className="text-xs text-muted">
                  {s.section_label ?? ''} · {s.admission_no}
                </span>
              </button>
            </li>
          ))}
        </ul>
      ) : null}

      {data.isPending && studentId ? <Spinner label={t('common.loading')} /> : null}
      {data.error ? <Alert tone="danger">{errorText(t, data.error)}</Alert> : null}
      {data.data ? (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
          <div className="flex min-w-0 flex-col gap-4">
            <Card className="flex flex-col gap-1">
              <h2 className="text-lg font-semibold">{data.data.student.name}</h2>
              <p className="text-sm text-muted">
                {data.data.student.admission_no} · {t('fees.counter.balance', { amount: rupees(data.data.balance_paise, i18n.language) })}
                {data.data.payments_pending ? ` · ${t('fees.counter.chequesPending', { count: data.data.payments_pending })}` : ''}
              </p>
              <Link to={`/fees/students/${data.data.student.id}`} className="w-fit text-sm font-medium text-primary hover:underline">
                {t('fees.counter.openLedger')}
              </Link>
            </Card>
            <Card className="flex flex-col gap-2">
              <h2 className="text-lg font-semibold">{t('fees.counter.dues')}</h2>
              {open.length === 0 ? <p className="text-sm text-muted">{t('fees.my.nothingDue')}</p> : null}
              <ul className="flex flex-col divide-y divide-border">
                {open.map((d) => (
                  <li key={d.id} className="flex flex-wrap items-center gap-3 py-3">
                    <input
                      type="checkbox"
                      className="size-5 accent-primary"
                      checked={selected[d.id] ?? true}
                      onChange={(e) => {
                        setSelected({ ...selected, [d.id]: e.target.checked });
                        setAmount(null);
                      }}
                      aria-label={t('fees.my.choose', { label: d.label })}
                    />
                    <div className="min-w-40 flex-1">
                      <p className="text-sm font-semibold">{d.label}</p>
                      <p className="text-xs text-muted">
                        {t('fees.dueOn', { date: formatDate(asDate(d.due_on), i18n.language) })}
                        {d.fine_paise > 0 ? ` · ${t('fees.fineIncluded', { amount: rupees(d.fine_paise, i18n.language) })}` : ''}
                      </p>
                    </div>
                    <StatusChip status={demandChip(d.status)} label={t(`fees.status.${d.status}`)} />
                    <span className="w-28 text-end font-semibold tabular-nums">{rupees(d.balance_paise, i18n.language)}</span>
                  </li>
                ))}
              </ul>
            </Card>
            <LedgerTable data={data.data} />
          </div>

          <Card className="flex h-fit flex-col gap-3">
            <h2 className="text-lg font-semibold">{t('fees.counter.take')}</h2>
            {done ? (
              <Alert tone="success">
                <span className="flex flex-col gap-2">
                  <span>{t('fees.counter.done', { number: done.receipt_no ?? '', amount: rupees(done.amount_paise, i18n.language), mode: t(`fees.modes.${done.mode}`) })}</span>
                  {done.receipt_id ? (
                    <Link to={`/fees/receipts/${done.receipt_id}?print=1`} className="inline-flex w-fit items-center gap-1 font-medium underline">
                      <Printer aria-hidden="true" className="size-4" />
                      {t('fees.counter.print')}
                    </Link>
                  ) : null}
                </span>
              </Alert>
            ) : null}
            <p className="text-sm">{t('fees.counter.selected', { amount: rupees(total, i18n.language) })}</p>
            <fieldset className="flex flex-col gap-2">
              <legend className="mb-1 text-sm font-medium">{t('fees.counter.paidBy')}</legend>
              <div className="flex flex-wrap gap-2">
                {modes.map((m) => (
                  <label key={m} className={`inline-flex h-11 cursor-pointer items-center rounded-full border px-4 text-sm font-medium focus-within:outline-2 focus-within:outline-ring ${mode === m ? 'border-primary bg-primary-soft text-primary' : 'border-border'}`}>
                    <input type="radio" name="mode" value={m} checked={mode === m} onChange={() => setMode(m)} className="sr-only" />
                    {t(`fees.modes.${m}`)}
                  </label>
                ))}
              </div>
            </fieldset>
            <Field label={t('fees.counter.amount')} hint={t('fees.counter.amountHint')} error={typed && !validRupees(typed) ? t('fees.amountInvalid') : undefined}>
              {(f) => <Input {...f} inputMode="decimal" value={typed} onChange={(e) => setAmount(e.target.value)} className="tabular-nums" />}
            </Field>
            {needsRef ? (
              <>
                <Field label={t(`fees.counter.reference.${mode}`)}>{(f) => <Input {...f} value={reference} maxLength={60} onChange={(e) => setReference(e.target.value)} />}</Field>
                {mode === 'cheque' || mode === 'dd' ? (
                  <>
                    <Field label={t('fees.counter.bank')}>{(f) => <Input {...f} value={bank} maxLength={60} onChange={(e) => setBank(e.target.value)} />}</Field>
                    <p className="text-xs text-muted">{t('fees.counter.chequeNote')}</p>
                  </>
                ) : null}
              </>
            ) : null}
            {error ? <Alert tone="danger">{error}</Alert> : null}
            <Button className="h-11" disabled={busy || picked.length === 0 || !validRupees(typed) || (needsRef && reference.trim() === '')} onClick={() => void collect()}>
              {t('fees.counter.collect', { amount: rupees(typedPaise(typed) ?? 0, i18n.language) })}
            </Button>
            <p className="text-xs text-muted">{t('fees.counter.numbers')}</p>
          </Card>
        </div>
      ) : null}
    </div>
  );
}
