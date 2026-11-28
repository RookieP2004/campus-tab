import { StatusChip } from '@campus-tab/ui';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Field, Input, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { holds } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { apiGet } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import { type Closing, type ClosingsView, fees, rupees, validRupees } from './api';

const asDate = (ymd: string) => `${ymd}T12:00:00+05:30`;

/**
 * Day-end closing. A collector closes their own day; the Clerk or the
 * Principal confirms the cash handed over (never their own closing, and a
 * Clerk's only by the Principal: the server decides who sees which).
 */
export function ClosingsPage() {
  const { t, i18n } = useTranslation();
  const me = useCurrentUser();
  const queryClient = useQueryClient();
  const data = useQuery({ queryKey: ['fees', 'closings'], queryFn: apiGet<ClosingsView>('/fees/closings'), retry: false });
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  const close = async () => {
    setBusy(true);
    setMessage(null);
    try {
      await fees.closeDay();
      setMessage({ tone: 'success', text: t('fees.closings.closed') });
      void queryClient.invalidateQueries({ queryKey: ['fees'] });
    } catch (e) {
      setMessage({ tone: 'danger', text: errorText(t, e) });
    } finally {
      setBusy(false);
    }
  };

  if (data.isPending) return <Spinner label={t('common.loading')} />;
  if (data.error || !data.data) return <Alert tone="danger">{errorText(t, data.error)}</Alert>;
  const d = data.data;

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4">
      <Link to="/fees" className="text-sm font-medium text-primary hover:underline">
        {t('fees.back')}
      </Link>
      <h1 className="text-2xl font-semibold tracking-tight">{t('fees.closings.title')}</h1>
      {message ? <Alert tone={message.tone}>{message.text}</Alert> : null}
      {holds(me, 'fees.collect') ? (
        <Card className="flex flex-col gap-3">
          <h2 className="text-lg font-semibold">{t('fees.closings.myDay')}</h2>
          <p className="text-sm">{t('fees.counter.today', { count: d.open.count, amount: rupees(d.open.total_paise, i18n.language) })}</p>
          <Totals totals={d.open.totals} />
          <div>
            <Button className="h-11" disabled={busy || d.open.count === 0} onClick={() => void close()}>
              {t('fees.closings.closeMyDay')}
            </Button>
          </div>
          <p className="text-xs text-muted">{t('fees.closings.note')}</p>
        </Card>
      ) : null}
      {d.to_confirm.length ? (
        <Card className="flex flex-col gap-3">
          <h2 className="text-lg font-semibold">{t('fees.closings.toConfirm')}</h2>
          <ul className="flex flex-col gap-3">
            {d.to_confirm.map((c) => (
              <li key={c.id}>
                <ConfirmClosing closing={c} />
              </li>
            ))}
          </ul>
        </Card>
      ) : null}
      {d.mine.length ? (
        <Card className="flex flex-col gap-2">
          <h2 className="text-lg font-semibold">{t('fees.closings.mine')}</h2>
          <ul className="flex flex-col divide-y divide-border">
            {d.mine.map((c) => (
              <li key={c.id} className="flex flex-wrap items-center gap-2 py-2 text-sm">
                <span className="min-w-40 flex-1">
                  {formatDate(asDate(c.date), i18n.language)} · {t('fees.closings.count', { count: c.count })} · {rupees(c.total_paise, i18n.language)}
                </span>
                <StatusChip status={c.status === 'confirmed' ? 'approved' : 'pending'} label={t(`fees.closings.status.${c.status}`)} />
                {c.confirmed_by ? <span className="w-full text-xs text-muted">{t('fees.closings.confirmedBy', { name: c.confirmed_by.name, date: formatDateTime(c.confirmed_at, i18n.language) })}</span> : null}
              </li>
            ))}
          </ul>
        </Card>
      ) : null}
    </div>
  );
}

function Totals({ totals }: { totals: Record<string, number> }) {
  const { t, i18n } = useTranslation();
  const entries = Object.entries(totals);
  if (entries.length === 0) return null;

  return (
    <dl className="grid grid-cols-2 gap-2 sm:grid-cols-3">
      {entries.map(([mode, paise]) => (
        <div key={mode} className="rounded-input bg-surface-muted p-3">
          <dt className="text-xs text-muted">{t(`fees.modes.${mode}`)}</dt>
          <dd className="font-semibold tabular-nums">{rupees(paise, i18n.language)}</dd>
        </div>
      ))}
    </dl>
  );
}

export function ConfirmClosing({ closing, onDone }: { closing: Closing; onDone?: () => void }) {
  const { t, i18n } = useTranslation();
  const queryClient = useQueryClient();
  const noCash = closing.cash_paise === 0;
  const [counted, setCounted] = useState(noCash ? '0' : '');
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  const confirm = async () => {
    setBusy(true);
    setMessage(null);
    try {
      await fees.confirmClosing(closing.id, counted);
      setMessage({ tone: 'success', text: t('fees.closings.confirmed') });
      void queryClient.invalidateQueries({ queryKey: ['fees'] });
      void queryClient.invalidateQueries({ queryKey: ['approvals'] });
      onDone?.();
    } catch (e) {
      setMessage({ tone: 'danger', text: errorText(t, e) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex flex-col gap-2 rounded-input border border-border p-3">
      <p className="text-sm font-medium">
        {closing.collector.name} · {formatDate(asDate(closing.date), i18n.language)} · {t('fees.closings.count', { count: closing.count })}
      </p>
      <Totals totals={closing.totals} />
      {noCash ? (
        <p className="text-sm text-muted">{t('fees.closings.noCash')}</p>
      ) : (
        <Field label={t('fees.closings.counted')} hint={t('fees.closings.countedHint', { amount: rupees(closing.cash_paise, i18n.language) })}>
          {(f) => <Input {...f} inputMode="decimal" value={counted} onChange={(e) => setCounted(e.target.value)} className="w-40 tabular-nums" />}
        </Field>
      )}
      {message ? <Alert tone={message.tone}>{message.text}</Alert> : null}
      <div>
        <Button className="h-11" disabled={busy || !validRupees(counted)} onClick={() => void confirm()}>
          {noCash ? t('fees.closings.confirmNoCash') : t('fees.closings.confirm')}
        </Button>
      </div>
    </div>
  );
}
