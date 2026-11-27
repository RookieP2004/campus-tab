import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Bell, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Dialog, Field, Input, Select, Spinner, Textarea } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { type ClientRecord, type ConsoleSchoolDetails, type SecurityWindows, consoleSchools } from './api';

/** "1.2 MB" style sizes. */
export function bytes(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—';
  const units = ['B', 'KB', 'MB', 'GB'];
  let value = n;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit++;
  }
  return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

function useWhen() {
  const { i18n } = useTranslation();
  return (iso: string | null | undefined) =>
    iso ? new Intl.DateTimeFormat(i18n.language, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso)) : '—';
}

function Stat({ label, value, warn = false }: { label: string; value: string; warn?: boolean }) {
  return (
    <div className="rounded-input border border-border p-3">
      <dt className="text-xs text-muted">{label}</dt>
      <dd className={`mt-1 text-lg font-semibold ${warn ? 'text-warning' : ''}`}>{value}</dd>
    </div>
  );
}

/** Usage numbers, worked out by the scheduled job inside the school. */
export function UsageCard({ school }: { school: ConsoleSchoolDetails }) {
  const { t } = useTranslation();
  const when = useWhen();
  const queryClient = useQueryClient();
  const refresh = useMutation({
    mutationFn: () => consoleSchools.refreshStats(school.code),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['console'] }),
  });
  const u = school.usage;
  const seats = (used: number | null, limit: number | null) => (used === null ? '—' : `${used} / ${limit ?? '—'}`);
  const near = (used: number | null, limit: number | null) => used !== null && limit !== null && limit > 0 && used >= Math.ceil(limit * 0.9);

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold">{t('console.usage.title')}</h2>
        <Button size="sm" variant="outline" onClick={() => refresh.mutate()} disabled={refresh.isPending}>
          <RefreshCw aria-hidden="true" />
          {t('console.usage.refresh')}
        </Button>
      </div>
      <p className="mt-1 text-xs text-muted">{u.computed_at ? t('console.usage.computed', { when: when(u.computed_at) }) : t('console.usage.notYet')}</p>
      {refresh.isError ? <Alert tone="danger" className="mt-3">{errorText(t, refresh.error)}</Alert> : null}
      <dl className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Stat label={t('console.usage.students')} value={seats(u.students, u.student_seats)} warn={near(u.students, u.student_seats)} />
        <Stat label={t('console.usage.staff')} value={seats(u.staff, u.staff_seats)} warn={near(u.staff, u.staff_seats)} />
        <Stat label={t('console.usage.active7')} value={u.active_7d === null ? '—' : String(u.active_7d)} />
        <Stat label={t('console.usage.active30')} value={u.active_30d === null ? '—' : String(u.active_30d)} />
        <Stat label={t('console.usage.lastActivity')} value={when(u.last_activity_at)} />
        <Stat label={t('console.usage.storage')} value={bytes(u.storage_bytes)} />
      </dl>
    </Card>
  );
}

const METRICS = ['failed_sign_ins', 'lockouts', 'password_resets', 'mfa_resets', 'chief_changes'] as const;
const WINDOWS = ['last_hour', '24h', '7d', '30d'] as const;

/** Security counts only; the details stay in the school's own audit log. */
export function SecurityCard({ security, alerts }: { security: SecurityWindows | null; alerts: ConsoleSchoolDetails['alerts'] }) {
  const { t } = useTranslation();
  const when = useWhen();

  return (
    <Card>
      <h2 className="text-lg font-semibold">{t('console.security.title')}</h2>
      <p className="mt-1 text-sm text-muted">{t('console.security.intro')}</p>
      {security === null ? (
        <p className="mt-3 text-sm text-muted">{t('console.usage.notYet')}</p>
      ) : (
        <div className="mt-3 overflow-x-auto">
          <table className="w-full min-w-[480px] text-sm">
            <thead>
              <tr className="text-start text-xs text-muted">
                <th className="py-2 text-start font-medium">{t('console.security.metric')}</th>
                {WINDOWS.map((w) => (
                  <th key={w} className="py-2 text-end font-medium">
                    {t(`console.security.windows.${w}`)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {METRICS.map((m) => (
                <tr key={m}>
                  <td className="py-2">{t(`console.security.metrics.${m}`)}</td>
                  {WINDOWS.map((w) => (
                    <td key={w} className="py-2 text-end tabular-nums">
                      {security[w]?.[m] ?? 0}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {alerts.length > 0 ? (
        <ul className="mt-4 flex flex-col gap-2">
          {alerts.map((a) => (
            <li key={a.id}>
              <Alert tone={a.acknowledged_at ? 'info' : 'warning'}>
                {a.message} <span className="text-xs text-muted">· {when(a.raised_at)}</span>
              </Alert>
            </li>
          ))}
        </ul>
      ) : null}
    </Card>
  );
}

const BILLING = ['monthly', 'quarterly', 'half_yearly', 'yearly'] as const;

export function ClientCard({ school }: { school: ConsoleSchoolDetails }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<ClientRecord>(school.client_record);
  const save = useMutation({
    mutationFn: () => consoleSchools.updateClient(school.code, { ...form, price: form.price === null || form.price === ('' as never) ? null : Number(form.price) }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['console'] });
      setEditing(false);
    },
  });
  const c = school.client_record;
  const field = (key: keyof ClientRecord, label: string, type = 'text') => (
    <Field label={label}>
      {(p) => <Input {...p} type={type} value={(form[key] ?? '') as string} onChange={(e) => setForm({ ...form, [key]: e.target.value || null })} />}
    </Field>
  );

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold">{t('console.client.title')}</h2>
        <Button
          size="sm"
          variant="outline"
          onClick={() => {
            setForm(school.client_record);
            setEditing(true);
          }}
        >
          {t('console.client.edit')}
        </Button>
      </div>
      <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-3">
        {(['contact_person', 'phone', 'email', 'address', 'package_name', 'price', 'billing_period', 'paid_until'] as const).map((k) => (
          <div key={k}>
            <dt className="text-muted">{t(`console.client.fields.${k}`)}</dt>
            <dd className="break-words">
              {k === 'billing_period' && c[k] ? t(`console.client.periods.${c[k]}`) : k === 'price' && c[k] !== null ? `₹${Number(c[k]).toLocaleString('en-IN')}` : (c[k] ?? '—')}
            </dd>
          </div>
        ))}
      </dl>
      <Dialog open={editing} title={t('console.client.title')} onClose={() => setEditing(false)}>
        <form
          className="flex max-h-[70vh] flex-col gap-3 overflow-y-auto"
          onSubmit={(e) => {
            e.preventDefault();
            save.mutate();
          }}
          noValidate
        >
          {save.isError ? <Alert tone="danger">{errorText(t, save.error)}</Alert> : null}
          {field('contact_person', t('console.client.fields.contact_person'))}
          {field('phone', t('console.client.fields.phone'), 'tel')}
          {field('email', t('console.client.fields.email'), 'email')}
          <Field label={t('console.client.fields.address')}>
            {(p) => <Textarea {...p} value={form.address ?? ''} onChange={(e) => setForm({ ...form, address: e.target.value || null })} />}
          </Field>
          {field('package_name', t('console.client.fields.package_name'))}
          {field('price', t('console.client.fields.price'), 'number')}
          <Field label={t('console.client.fields.billing_period')}>
            {(p) => (
              <Select {...p} value={form.billing_period ?? ''} onChange={(e) => setForm({ ...form, billing_period: (e.target.value || null) as ClientRecord['billing_period'] })}>
                <option value="">—</option>
                {BILLING.map((b) => (
                  <option key={b} value={b}>
                    {t(`console.client.periods.${b}`)}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          {field('paid_until', t('console.client.fields.paid_until'), 'date')}
          <Field label={t('console.client.fields.notes')}>
            {(p) => <Textarea {...p} value={form.notes ?? ''} onChange={(e) => setForm({ ...form, notes: e.target.value || null })} />}
          </Field>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setEditing(false)}>
              {t('common.cancel')}
            </Button>
            <Button type="submit" disabled={save.isPending}>
              {t('common.save')}
            </Button>
          </div>
        </form>
      </Dialog>
    </Card>
  );
}

const METHODS = ['bank_transfer', 'upi', 'cheque', 'cash', 'other'] as const;

export function PaymentsCard({ school }: { school: ConsoleSchoolDetails }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const today = new Date().toISOString().slice(0, 10);
  const empty = { amount: '', paid_on: today, method: 'bank_transfer', reference: '', period_from: '', period_to: '', note: '' };
  const [form, setForm] = useState(empty);
  const [open, setOpen] = useState(false);
  const add = useMutation({
    mutationFn: () =>
      consoleSchools.addPayment(school.code, {
        amount: Number(form.amount),
        paid_on: form.paid_on,
        method: form.method,
        reference: form.reference || null,
        period_from: form.period_from || null,
        period_to: form.period_to || null,
        note: form.note || null,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['console'] });
      setForm(empty);
      setOpen(false);
    },
  });

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold">{t('console.payments.title')}</h2>
        <Button size="sm" onClick={() => setOpen(true)}>
          {t('console.payments.add')}
        </Button>
      </div>
      <p className="mt-1 text-xs text-muted">{t('console.payments.appendOnly')}</p>
      {school.payments.length === 0 ? (
        <p className="mt-3 text-sm text-muted">{t('console.payments.none')}</p>
      ) : (
        <div className="mt-3 overflow-x-auto">
          <table className="w-full min-w-[560px] text-sm">
            <thead>
              <tr className="text-xs text-muted">
                <th className="py-2 text-start font-medium">{t('console.payments.paidOn')}</th>
                <th className="py-2 text-end font-medium">{t('console.payments.amount')}</th>
                <th className="py-2 text-start font-medium">{t('console.payments.method')}</th>
                <th className="py-2 text-start font-medium">{t('console.payments.reference')}</th>
                <th className="py-2 text-start font-medium">{t('console.payments.note')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {school.payments.map((p) => (
                <tr key={p.id}>
                  <td className="py-2">{p.paid_on}</td>
                  <td className={`py-2 text-end tabular-nums ${p.amount < 0 ? 'text-danger' : ''}`}>₹{p.amount.toLocaleString('en-IN')}</td>
                  <td className="py-2">{t(`console.payments.methods.${p.method}`)}</td>
                  <td className="py-2">{p.reference ?? '—'}</td>
                  <td className="py-2">{p.note ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Dialog open={open} title={t('console.payments.add')} onClose={() => setOpen(false)}>
        <form
          className="flex flex-col gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            add.mutate();
          }}
          noValidate
        >
          {add.isError ? <Alert tone="danger">{errorText(t, add.error)}</Alert> : null}
          <Field label={t('console.payments.amount')} hint={t('console.payments.amountHint')}>
            {(p) => <Input {...p} type="number" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />}
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label={t('console.payments.paidOn')}>
              {(p) => <Input {...p} type="date" max={today} value={form.paid_on} onChange={(e) => setForm({ ...form, paid_on: e.target.value })} />}
            </Field>
            <Field label={t('console.payments.method')}>
              {(p) => (
                <Select {...p} value={form.method} onChange={(e) => setForm({ ...form, method: e.target.value })}>
                  {METHODS.map((m) => (
                    <option key={m} value={m}>
                      {t(`console.payments.methods.${m}`)}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          </div>
          <Field label={t('console.payments.reference')}>
            {(p) => <Input {...p} value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} />}
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label={t('console.payments.periodFrom')}>
              {(p) => <Input {...p} type="date" value={form.period_from} onChange={(e) => setForm({ ...form, period_from: e.target.value })} />}
            </Field>
            <Field label={t('console.payments.periodTo')}>
              {(p) => <Input {...p} type="date" value={form.period_to} onChange={(e) => setForm({ ...form, period_to: e.target.value })} />}
            </Field>
          </div>
          <Field label={t('console.payments.note')}>
            {(p) => <Textarea {...p} value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} />}
          </Field>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
              {t('common.cancel')}
            </Button>
            <Button type="submit" disabled={add.isPending || form.amount === '' || Number(form.amount) === 0}>
              {t('common.save')}
            </Button>
          </div>
        </form>
      </Dialog>
    </Card>
  );
}

export function LicenceHistoryCard({ school }: { school: ConsoleSchoolDetails }) {
  const { t } = useTranslation();
  const when = useWhen();

  return (
    <Card>
      <h2 className="text-lg font-semibold">{t('console.history.title')}</h2>
      {school.licence_history.length === 0 ? (
        <p className="mt-3 text-sm text-muted">{t('console.history.none')}</p>
      ) : (
        <ul className="mt-3 flex flex-col divide-y divide-border text-sm">
          {school.licence_history.map((h, i) => (
            <li key={i} className="flex flex-col gap-0.5 py-2 sm:flex-row sm:justify-between">
              <span>
                <span className="font-medium">{t(`console.history.actions.${h.action}`, { defaultValue: h.action })}</span>
                {h.after?.valid_to ? <span className="ms-2 text-muted">→ {h.after.valid_to}</span> : null}
                {h.changed && h.changed.length > 0 ? <span className="ms-2 text-xs text-muted">({h.changed.join(', ')})</span> : null}
                {h.reason ? <span className="ms-2 text-xs text-muted">· {h.reason}</span> : null}
              </span>
              <span className="text-xs text-muted">
                {h.by ?? '—'} · {when(h.at)}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

export function GrantsCard({ school }: { school: ConsoleSchoolDetails }) {
  const { t } = useTranslation();
  const when = useWhen();

  return (
    <Card>
      <h2 className="text-lg font-semibold">{t('console.grants.title')}</h2>
      <p className="mt-1 text-sm text-muted">{t('console.grants.intro')}</p>
      {school.support_grants.length === 0 ? (
        <p className="mt-3 text-sm text-muted">{t('console.grants.none')}</p>
      ) : (
        <ul className="mt-3 flex flex-col divide-y divide-border text-sm">
          {school.support_grants.map((g) => (
            <li key={g.id} className="flex flex-col gap-0.5 py-2 sm:flex-row sm:justify-between">
              <span>
                <span className="font-medium">{g.agent_name}</span>
                <span className="ms-2 text-muted">{g.scope.join(', ')}</span>
                <span className="ms-2 text-xs text-muted">{t(g.read_only ? 'console.grants.readOnly' : 'console.grants.readWrite')}</span>
              </span>
              <span className="text-xs text-muted">
                {t(`console.grants.status.${g.status}`)} · {when(g.starts_at)} → {when(g.expires_at)}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

export function RenewDialog({ open, school, onClose }: { open: boolean; school: ConsoleSchoolDetails; onClose: () => void }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [validTo, setValidTo] = useState('');
  const renew = useMutation({
    mutationFn: () => consoleSchools.renew(school.code, validTo),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['console'] });
      setValidTo('');
      onClose();
    },
  });

  return (
    <Dialog open={open} title={t('console.licence.renew')} onClose={onClose}>
      <form
        className="flex flex-col gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          renew.mutate();
        }}
        noValidate
      >
        <p className="text-sm text-muted">{t('console.licence.renewIntro', { date: school.licence?.valid_to ?? '—' })}</p>
        {renew.isError ? <Alert tone="danger">{errorText(t, renew.error)}</Alert> : null}
        <Field label={t('console.licence.validTo')}>
          {(p) => <Input {...p} type="date" min={school.licence?.valid_to} value={validTo} onChange={(e) => setValidTo(e.target.value)} />}
        </Field>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={renew.isPending || validTo === ''}>
            {t('console.licence.renew')}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

export function ConsoleAlertsPage() {
  const { t } = useTranslation();
  const when = useWhen();
  const queryClient = useQueryClient();
  const [all, setAll] = useState(false);
  const alerts = useQuery({ queryKey: ['console', 'alerts', all], queryFn: consoleSchools.alerts(all), refetchInterval: 60_000 });
  const ack = useMutation({
    mutationFn: consoleSchools.acknowledge,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['console', 'alerts'] }),
  });

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
          <Bell aria-hidden="true" className="size-6" />
          {t('console.alerts.title')}
        </h1>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="size-4" checked={all} onChange={(e) => setAll(e.target.checked)} />
          {t('console.alerts.showAll')}
        </label>
      </div>
      <p className="text-sm text-muted">{t('console.alerts.intro')}</p>
      {alerts.isPending ? <Spinner label={t('common.loading')} /> : null}
      {alerts.isError ? <Alert tone="danger">{errorText(t, alerts.error)}</Alert> : null}
      {alerts.data?.length === 0 ? <Card>{t('console.alerts.none')}</Card> : null}
      <ul className="flex flex-col gap-2">
        {alerts.data?.map((a) => (
          <li key={a.id}>
            <Card className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p className="font-medium">{a.message}</p>
                <p className="text-xs text-muted">
                  <Link to={`/console/schools/${a.school_code}`} className="text-primary underline-offset-2 hover:underline">
                    {a.school_code}
                  </Link>{' '}
                  · {when(a.raised_at)}
                  {a.acknowledged_at ? ` · ${t('console.alerts.acknowledgedBy', { name: a.acknowledged_by ?? '—' })}` : ''}
                </p>
              </div>
              {a.acknowledged_at ? null : (
                <Button size="sm" variant="outline" onClick={() => ack.mutate(a.id)} disabled={ack.isPending}>
                  {t('console.alerts.acknowledge')}
                </Button>
              )}
            </Card>
          </li>
        ))}
      </ul>
    </div>
  );
}
