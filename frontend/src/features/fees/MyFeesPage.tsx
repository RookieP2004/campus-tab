import { EmptyState, StatusChip } from '@campus-tab/ui';
import { useQueries, useQuery, useQueryClient } from '@tanstack/react-query';
import { CreditCard, Receipt as ReceiptIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Dialog, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { scopeOf } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import type { StudentProfile } from '@/features/people/api';
import { apiGet } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { type Checkout, demandChip, fees, rupees, type StudentFees, sumPaise } from './api';

const asDate = (ymd: string) => `${ymd}T12:00:00+05:30`;

interface RazorpayWindow {
  Razorpay?: new (options: Record<string, unknown>) => { open: () => void; on: (event: string, cb: () => void) => void };
}

function loadScript(src: string): Promise<void> {
  return new Promise((resolve, reject) => {
    if (document.querySelector(`script[src="${src}"]`)) return resolve();
    const s = document.createElement('script');
    s.src = src;
    s.async = true;
    s.onload = () => resolve();
    s.onerror = () => reject(new Error('checkout script'));
    document.head.appendChild(s);
  });
}

/**
 * A parent's fees: each child's dues with Pay now, what is coming, and every
 * receipt. The amount to pay is worked out by the server from the chosen
 * dues; the gateway's own page takes the card or UPI details. The dues turn
 * Paid when the gateway's webhook arrives; nobody has to do anything.
 */
export function MyFeesPage() {
  const { t, i18n } = useTranslation();
  const me = useCurrentUser();
  const queryClient = useQueryClient();
  const children = scopeOf(me).children;
  const [params, setParams] = useSearchParams();
  const chosen = params.get('student') ?? children[0] ?? null;
  const names = useQueries({ queries: children.map((id) => ({ queryKey: ['students', id], queryFn: apiGet<StudentProfile>(`/students/${id}`), retry: false })) });
  const data = useQuery({ queryKey: ['fees', 'student', chosen], queryFn: apiGet<StudentFees>(`/students/${chosen}/fees`), enabled: chosen !== null, retry: false });
  const [selected, setSelected] = useState<Record<string, boolean>>({});
  const [checkout, setCheckout] = useState<Checkout | null>(null);
  const [waiting, setWaiting] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const order = useQuery({
    queryKey: ['fees', 'order', waiting],
    queryFn: apiGet<{ id: string; status: string; receipt_no: string | null }>(`/fees/checkout/${waiting}`),
    enabled: waiting !== null,
    refetchInterval: (q) => (q.state.data && q.state.data.status !== 'created' ? false : 2000),
  });
  const settled = order.data && order.data.status !== 'created';
  useEffect(() => {
    if (settled) void queryClient.invalidateQueries({ queryKey: ['fees', 'student'] });
  }, [settled, queryClient]);

  if (chosen === null) return <EmptyState icon={<CreditCard className="size-5" />} title={t('fees.my.noChildren')} />;

  const open = (data.data?.demands ?? []).filter((d) => d.balance_paise > 0);
  const picked = open.filter((d) => selected[d.id] ?? true);
  const total = sumPaise(picked.map((d) => d.balance_paise));

  const pay = async () => {
    setBusy(true);
    setError(null);
    try {
      const c = await fees.checkout(chosen, picked.map((d) => d.id));
      if (c.provider === 'fake') {
        setCheckout(c);
      } else if (c.script) {
        await loadScript(c.script);
        const Razorpay = (window as unknown as RazorpayWindow).Razorpay;
        if (!Razorpay) throw new Error('checkout script');
        const rz = new Razorpay({
          key: c.key_id,
          order_id: c.order_id,
          amount: c.amount_paise,
          currency: c.currency,
          name: c.school,
          handler: () => setWaiting(c.id),
          modal: { ondismiss: () => setBusy(false) },
        });
        rz.open();
      }
    } catch (e) {
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  const fake = async (outcome: 'paid' | 'failed') => {
    if (!checkout) return;
    setBusy(true);
    try {
      await fees.fakePay(checkout.id, outcome);
      setWaiting(checkout.id);
      setCheckout(null);
    } catch (e) {
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4">
      <h1 className="text-2xl font-semibold tracking-tight">{t('fees.my.title')}</h1>
      {children.length > 1 ? (
        <div role="tablist" aria-label={t('tests.results.child')} className="flex flex-wrap gap-2">
          {children.map((c, i) => (
            <button
              key={c}
              type="button"
              role="tab"
              aria-selected={c === chosen}
              onClick={() => setParams({ student: c }, { replace: true })}
              className={`h-11 rounded-full border px-4 text-sm font-medium focus-visible:outline-2 focus-visible:outline-ring ${c === chosen ? 'border-primary bg-primary-soft text-primary' : 'border-border'}`}
            >
              {names[i]?.data?.name ?? t('tests.results.childN', { n: i + 1 })}
            </button>
          ))}
        </div>
      ) : null}
      {data.isPending ? <Spinner label={t('common.loading')} /> : null}
      {data.error ? <Alert tone="danger">{errorText(t, data.error)}</Alert> : null}
      {waiting && !settled ? (
        <Alert tone="info">
          <span aria-live="polite">{t('fees.my.waiting')}</span>
        </Alert>
      ) : null}
      {order.data?.status === 'paid' ? (
        <Alert tone="success">{t('fees.my.paid', { number: order.data.receipt_no ?? '' })}</Alert>
      ) : null}
      {order.data && ['failed', 'mismatch'].includes(order.data.status) ? <Alert tone="warning">{t(`fees.my.${order.data.status}`)}</Alert> : null}
      {error ? <Alert tone="danger">{error}</Alert> : null}
      {data.data ? (
        <>
          <Card className="flex flex-col gap-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <h2 className="text-lg font-semibold">{t('fees.my.dues', { name: data.data.student.name })}</h2>
              <p className="text-sm text-muted">{data.data.year?.name}</p>
            </div>
            {open.length === 0 ? <p className="text-sm text-muted">{t('fees.my.nothingDue')}</p> : null}
            <ul className="flex flex-col divide-y divide-border">
              {open.map((d) => (
                <li key={d.id} className="flex flex-wrap items-center gap-3 py-3">
                  {data.data.can.pay_online ? (
                    <input
                      type="checkbox"
                      className="size-5 accent-primary"
                      checked={selected[d.id] ?? true}
                      onChange={(e) => setSelected({ ...selected, [d.id]: e.target.checked })}
                      aria-label={t('fees.my.choose', { label: d.label })}
                    />
                  ) : null}
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
            {data.data.can.pay_online && open.length > 0 ? (
              <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3">
                <p className="text-sm">{t('fees.my.selected', { amount: rupees(total, i18n.language) })}</p>
                <Button className="h-11" disabled={busy || total <= 0 || (waiting !== null && !settled)} onClick={() => void pay()}>
                  <CreditCard aria-hidden="true" />
                  {t('fees.my.payNow', { amount: rupees(total, i18n.language) })}
                </Button>
              </div>
            ) : null}
          </Card>
          {data.data.upcoming.length ? (
            <Card className="flex flex-col gap-2">
              <h2 className="text-lg font-semibold">{t('fees.my.upcoming')}</h2>
              <ul className="flex flex-col gap-1 text-sm">
                {data.data.upcoming.map((u) => (
                  <li key={u.label + u.due_on} className="flex justify-between gap-2">
                    <span>
                      {u.label} · {formatDate(asDate(u.due_on), i18n.language)}
                    </span>
                    <span className="tabular-nums">{rupees(u.total_paise, i18n.language)}</span>
                  </li>
                ))}
              </ul>
            </Card>
          ) : null}
          <Card className="flex flex-col gap-2">
            <h2 className="text-lg font-semibold">{t('fees.my.receipts')}</h2>
            {data.data.receipts.length === 0 ? <p className="text-sm text-muted">{t('fees.my.noReceipts')}</p> : null}
            <ul className="flex flex-col divide-y divide-border">
              {data.data.receipts.map((r) => (
                <li key={r.id}>
                  <Link to={`/fees/receipts/${r.id}`} className="flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm hover:text-primary focus-visible:outline-2 focus-visible:outline-ring">
                    <ReceiptIcon aria-hidden="true" className="size-4 text-muted" />
                    <span className="min-w-40 flex-1">
                      {r.number} · {formatDate(asDate(r.issued_on), i18n.language)} · {t(`fees.modes.${r.mode}`)}
                    </span>
                    {r.status !== 'valid' ? <StatusChip status="returned" label={t(`fees.receipt.status.${r.status}`)} /> : null}
                    <span className="tabular-nums">{rupees(r.amount_paise, i18n.language)}</span>
                  </Link>
                </li>
              ))}
            </ul>
          </Card>
        </>
      ) : null}

      <Dialog open={checkout !== null} title={t('fees.fake.title')} onClose={() => setCheckout(null)}>
        <p className="text-sm">{t('fees.fake.body', { amount: rupees(checkout?.amount_paise ?? 0, i18n.language), school: checkout?.school ?? '' })}</p>
        <Alert tone="warning">{t('fees.fake.note')}</Alert>
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="outline" className="h-11" disabled={busy} onClick={() => void fake('failed')}>
            {t('fees.fake.fail')}
          </Button>
          <Button className="h-11" disabled={busy} onClick={() => void fake('paid')}>
            {t('fees.fake.pay')}
          </Button>
        </div>
      </Dialog>
    </div>
  );
}
