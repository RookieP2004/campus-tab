import { EmptyState } from '@campus-tab/ui';
import { useQueries, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarDays, ChevronRight, ClipboardCheck, Plane, Plus, Settings2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Field, Input, Select, Spinner, Textarea } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { holds, scopeOf } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import type { StudentProfile } from '@/features/people/api';
import { apiGet } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { apiGetBody } from '@/lib/requests';
import { addDays, asDate, type AwayEntry, type Balance, leave, type LeaveDetail, type LeavePreview, type LeaveSummary, type LeaveType, todayInIndia } from './api';
import { useLeaveDates } from './dates';
import { LeaveRequestView, LeaveStatusChip } from './LeaveRequestView';

const APPROVER = ['leave.verify', 'leave.decide', 'leave.decide_principal', 'leave.fast_track', 'student_leave.approve'];

function RequestList({ rows, empty }: { rows: LeaveSummary[]; empty: string }) {
  const { t } = useTranslation();
  const dates = useLeaveDates();
  if (rows.length === 0) return <p className="text-sm text-muted">{empty}</p>;

  return (
    <ul className="flex flex-col gap-2">
      {rows.map((r) => (
        <li key={r.id}>
          <Link
            to={`/leave/requests/${r.id}`}
            className="flex items-center gap-3 rounded-card border border-border bg-surface p-4 hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
          >
            <div className="min-w-0 flex-1">
              <p className="text-sm font-semibold break-words">{r.kind === 'student' ? `${r.applicant.name} · ${t('leave.studentLeave')}` : r.type_name}</p>
              <p className="text-xs text-muted">
                {dates(r.from, r.to, r.half_day)} · {t('leave.dayCount', { count: r.days })}
              </p>
            </div>
            <LeaveStatusChip status={r.status} />
            <ChevronRight aria-hidden="true" className="size-4 shrink-0 text-muted rtl:rotate-180" />
          </Link>
        </li>
      ))}
    </ul>
  );
}

function BalanceCards({ balances }: { balances: Balance[] }) {
  const { t } = useTranslation();
  const limited = balances.filter((b) => b.quota !== null);
  if (limited.length === 0) return null;

  return (
    <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3">
      {limited.map((b) => (
        <li key={b.type_code} className="flex flex-col gap-1 rounded-card border border-border bg-surface p-4">
          <span className="text-xs font-medium text-muted">{b.type_name}</span>
          <span className="text-2xl font-semibold">
            {b.available}
            <span className="text-sm font-normal text-muted"> / {b.quota}</span>
          </span>
          {b.reserved > 0 ? <span className="text-xs text-muted">{t('leave.held', { count: b.reserved })}</span> : null}
        </li>
      ))}
    </ul>
  );
}

/**
 * Leave home: my balances and my requests, a parent's requests for their
 * children, and links to the other leave screens this person may open.
 */
export function LeaveHomePage() {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const staff = holds(me, 'leave.apply');
  const parent = holds(me, 'student_leave.apply');
  const mine = useQuery({ queryKey: ['leave', 'list', 'mine'], queryFn: apiGet<LeaveSummary[]>('/leave-requests', { box: 'mine' }), retry: false });
  const balances = useQuery({ queryKey: ['leave', 'balances'], queryFn: apiGet<Balance[]>('/leave/balances/me'), enabled: staff, retry: false });
  const history = useQuery({
    queryKey: ['leave', 'list', 'history'],
    queryFn: apiGet<LeaveSummary[]>('/leave-requests', { box: 'history' }),
    enabled: APPROVER.some((a) => holds(me, a)),
    retry: false,
  });

  const links = [
    APPROVER.some((a) => holds(me, a)) ? { to: '/approvals?tab=leave', label: 'nav.approvals', icon: ClipboardCheck } : null,
    holds(me, 'leave.view') ? { to: '/leave/calendar', label: 'leave.calendar.title', icon: CalendarDays } : null,
    holds(me, 'leave.manage_types') || holds(me, 'leave.delegate') ? { to: '/leave/settings', label: 'leave.settings.title', icon: Settings2 } : null,
  ].filter((l): l is NonNullable<typeof l> => l !== null);

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">{t('nav.leave')}</h1>
        <div className="flex flex-wrap gap-2">
          {staff ? (
            <Button asChild className="h-11">
              <Link to="/leave/apply">
                <Plus aria-hidden="true" />
                {t('leave.apply.title')}
              </Link>
            </Button>
          ) : null}
          {parent ? (
            <Button asChild variant={staff ? 'outline' : 'primary'} className="h-11">
              <Link to="/leave/child">
                <Plus aria-hidden="true" />
                {t('leave.child.title')}
              </Link>
            </Button>
          ) : null}
        </div>
      </div>

      {balances.data ? (
        <section className="flex flex-col gap-2">
          <h2 className="text-sm font-semibold">{t('leave.myBalance')}</h2>
          <BalanceCards balances={balances.data} />
        </section>
      ) : null}

      <section className="flex flex-col gap-2">
        <h2 className="text-sm font-semibold">{parent && !staff ? t('leave.childRequests') : t('leave.myRequests')}</h2>
        {mine.isPending ? <Spinner label={t('common.loading')} /> : null}
        {mine.error ? <Alert tone="danger">{errorText(t, mine.error)}</Alert> : null}
        {mine.data ? <RequestList rows={mine.data} empty={t('leave.noRequests')} /> : null}
      </section>

      {history.data && history.data.length > 0 ? (
        <section className="flex flex-col gap-2">
          <h2 className="text-sm font-semibold">{t('leave.decided')}</h2>
          <RequestList rows={history.data.slice(0, 20)} empty="" />
        </section>
      ) : null}

      {links.length > 0 ? (
        <nav aria-label={t('leave.more')} className="grid gap-2 sm:grid-cols-3">
          {links.map((l) => (
            <Link key={l.to} to={l.to} className="flex h-14 items-center gap-3 rounded-card border border-border bg-surface px-4 text-sm font-medium hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring">
              <l.icon aria-hidden="true" className="size-5 text-primary" />
              <span className="flex-1">{t(l.label)}</span>
              <ChevronRight aria-hidden="true" className="size-4 text-muted rtl:rotate-180" />
            </Link>
          ))}
        </nav>
      ) : null}
    </div>
  );
}

/**
 * Apply for my own leave. The days, the balance after and whether a
 * document is needed are worked out live by the server as the dates change.
 */
export function ApplyLeavePage() {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [params] = useSearchParams();
  const today = todayInIndia();
  const types = useQuery({ queryKey: ['leave', 'types'], queryFn: apiGet<LeaveType[]>('/leave-types'), retry: false });
  const [type, setType] = useState(params.get('type') ?? '');
  const [from, setFrom] = useState(addDays(today, 1));
  const [toChosen, setTo] = useState(addDays(today, 1));
  const to = toChosen < from ? from : toChosen;
  const [half, setHalf] = useState<'' | 'first' | 'second'>('');
  const [reason, setReason] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const active = (types.data ?? []).filter((x) => x.status === 'active');
  const chosen = active.find((x) => x.code === type) ?? active[0];
  const code = chosen?.code ?? '';

  const halfDay = chosen?.half_day && from === to && half !== '' ? half : null;
  const preview = useQuery({
    queryKey: ['leave', 'preview', code, from, to, halfDay],
    queryFn: apiGet<LeavePreview>('/leave-requests/preview', { type_code: code, from, to, half_day: halfDay ?? undefined }),
    enabled: code !== '' && from !== '' && to !== '' && to >= from,
    retry: false,
  });
  const p = preview.data;
  const short = p && p.available_after !== null && p.available_after < 0;

  const submit = async () => {
    setError(null);
    setBusy(true);
    try {
      let created = await leave.apply({ type_code: code, from, to, half_day: halfDay, reason: reason.trim(), submit: file === null });
      if (file) {
        created = await leave.attach(created.id, file);
        created = await leave.move(created.id, 'submit');
      }
      void queryClient.invalidateQueries({ queryKey: ['leave'] });
      void navigate(`/leave/requests/${created.id}`, { replace: true });
    } catch (e) {
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-4">
      <h1 className="text-2xl font-semibold tracking-tight">{t('leave.apply.title')}</h1>
      {types.isPending ? <Spinner label={t('common.loading')} /> : null}
      {types.error ? <Alert tone="danger">{errorText(t, types.error)}</Alert> : null}
      {types.data && active.length === 0 ? <Alert tone="warning">{t('leave.apply.noTypes')}</Alert> : null}

      {active.length > 0 ? (
        <Card className="flex flex-col gap-4">
          <Field label={t('leave.type')}>
            {(f) => (
              <Select {...f} value={code} onChange={(e) => setType(e.target.value)}>
                {active.map((x) => (
                  <option key={x.code} value={x.code}>
                    {x.name}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('leave.from')}>{(f) => <Input {...f} type="date" value={from} onChange={(e) => setFrom(e.target.value)} />}</Field>
            <Field label={t('leave.to')}>{(f) => <Input {...f} type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />}</Field>
          </div>
          {chosen?.half_day && from === to ? (
            <Field label={t('leave.halfDay.label')}>
              {(f) => (
                <Select {...f} value={half} onChange={(e) => setHalf(e.target.value as '' | 'first' | 'second')}>
                  <option value="">{t('leave.halfDay.full')}</option>
                  <option value="first">{t('leave.halfDay.first')}</option>
                  <option value="second">{t('leave.halfDay.second')}</option>
                </Select>
              )}
            </Field>
          ) : null}

          <div aria-live="polite" className="flex flex-col gap-2">
            {preview.error ? <Alert tone="warning">{errorText(t, preview.error)}</Alert> : null}
            {p ? (
              <div className="flex flex-col gap-1 rounded-input bg-surface-muted p-3 text-sm">
                <p className="font-semibold">
                  {t('leave.dayCount', { count: p.days })}
                  {p.balance && p.balance.available !== null ? ` · ${t('leave.balanceAfter', { available: p.balance.available, quota: p.balance.quota, after: p.available_after })}` : null}
                </p>
                {p.off.length > 0 ? (
                  <p className="text-muted">
                    {p.sandwich ? t('leave.apply.sandwichOn') : t('leave.apply.sandwichOff')}{' '}
                    {p.off.map((o) => formatDate(asDate(o.date), i18n.language)).join(', ')}
                  </p>
                ) : null}
                <p className="text-muted">{t('leave.apply.goesTo', { chain: p.chain.map((s) => t(`leave.stages.${s}`)).join(' → ') })}</p>
              </div>
            ) : null}
            {short ? <Alert tone="danger">{t('leave.apply.notEnough')}</Alert> : null}
          </div>

          <Field label={t('leave.reason')} hint={t('leave.privateHint')}>
            {(f) => <Textarea {...f} rows={3} maxLength={1000} value={reason} onChange={(e) => setReason(e.target.value)} />}
          </Field>
          <Field label={p?.document_required ? t('leave.apply.documentRequired') : t('leave.apply.documentOptional')}>
            {(f) => <Input {...f} type="file" accept=".pdf,.png,.jpg,.jpeg,.webp" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />}
          </Field>
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <div className="flex flex-wrap justify-end gap-2">
            <Button variant="outline" className="h-11" asChild>
              <Link to="/leave">{t('common.cancel')}</Link>
            </Button>
            <Button className="h-11" disabled={busy || reason.trim().length < 5 || !p || short || (p.document_required && !file)} onClick={() => void submit()}>
              {busy ? t('common.working') : t('leave.apply.send')}
            </Button>
          </div>
        </Card>
      ) : null}
    </div>
  );
}

/** A parent applies for one of their children; the class teacher decides. */
export function ChildLeavePage() {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [params] = useSearchParams();
  const children = scopeOf(me).children;
  const profiles = useQueries({
    queries: children.map((id) => ({ queryKey: ['students', id], queryFn: apiGet<StudentProfile>(`/students/${id}`), retry: false })),
  });
  const today = todayInIndia();
  const [child, setChild] = useState(params.get('student') ?? children[0] ?? '');
  const [from, setFrom] = useState(today);
  const [to, setTo] = useState(today);
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      const created = await leave.applyForChild({ student_id: child, from, to: to < from ? from : to, reason: reason.trim() });
      void queryClient.invalidateQueries({ queryKey: ['leave'] });
      void navigate(`/leave/requests/${created.id}`, { replace: true });
    } catch (e) {
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-4">
      <h1 className="text-2xl font-semibold tracking-tight">{t('leave.child.title')}</h1>
      {children.length === 0 ? <EmptyState icon={<Plane className="size-5" />} title={t('leave.child.none')} /> : null}
      {children.length > 0 ? (
        <Card className="flex flex-col gap-4">
          <Field label={t('leave.child.who')}>
            {(f) => (
              <Select {...f} value={child} onChange={(e) => setChild(e.target.value)}>
                {children.map((id, i) => (
                  <option key={id} value={id}>
                    {profiles[i]?.data?.name ?? t('common.loading')}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('leave.from')}>{(f) => <Input {...f} type="date" value={from} onChange={(e) => setFrom(e.target.value)} />}</Field>
            <Field label={t('leave.to')}>{(f) => <Input {...f} type="date" value={to < from ? from : to} min={from} onChange={(e) => setTo(e.target.value)} />}</Field>
          </div>
          <Field label={t('leave.reason')} hint={t('leave.child.hint')}>
            {(f) => <Textarea {...f} rows={3} maxLength={1000} value={reason} onChange={(e) => setReason(e.target.value)} />}
          </Field>
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <div className="flex justify-end">
            <Button className="h-11" disabled={busy || reason.trim().length < 5 || child === ''} onClick={() => void submit()}>
              {busy ? t('common.working') : t('leave.apply.send')}
            </Button>
          </div>
        </Card>
      ) : null}
    </div>
  );
}

/** One request, for its applicant or someone in its chain (anyone else gets "not found"). */
export function LeaveRequestPage() {
  const { t } = useTranslation();
  const { id = '' } = useParams();
  const request = useQuery({ queryKey: ['leave', 'request', id], queryFn: apiGet<LeaveDetail>(`/leave-requests/${id}`), retry: false, refetchOnWindowFocus: true });

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4">
      <Link to="/leave" className="text-sm font-medium text-primary hover:underline">
        {t('leave.back')}
      </Link>
      <h1 className="text-2xl font-semibold tracking-tight">{t('leave.request')}</h1>
      {request.isPending ? <Spinner label={t('common.loading')} /> : null}
      {request.error ? <Alert tone="danger">{errorText(t, request.error)}</Alert> : null}
      {request.data ? (
        <Card>
          <LeaveRequestView request={request.data} />
        </Card>
      ) : null}
    </div>
  );
}

/** Who is away, by day: names only (the type and reason stay private). */
export function LeaveCalendarPage() {
  const { t, i18n } = useTranslation();
  const me = useCurrentUser();
  const [params, setParams] = useSearchParams();
  const today = todayInIndia();
  const from = params.get('from') ?? today;
  const to = addDays(from, 13);
  const away = useQuery({ queryKey: ['leave', 'calendar', from, to], queryFn: apiGet<AwayEntry[]>('/leave/calendar', { from, to }), retry: false });
  const canCover = holds(me, 'leave.verify') || holds(me, 'leave.decide') || holds(me, 'leave.decide_principal');
  const cover = useQuery({
    queryKey: ['leave', 'cover', from, to],
    queryFn: apiGetBody<{ data: { date: string; section: string; subject: string | null; kind: string; teacher: string }[] }>('/leave/cover', { from, to }),
    enabled: canCover,
    retry: false,
  });

  const days = useMemo(() => Array.from({ length: 14 }, (_, i) => addDays(from, i)), [from]);
  const byDay = (d: string) => (away.data ?? []).filter((a) => a.from <= d && a.to >= d);

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4">
      <h1 className="text-2xl font-semibold tracking-tight">{t('leave.calendar.title')}</h1>
      <p className="text-sm text-muted">{t('leave.calendar.hint')}</p>
      <div className="flex flex-wrap gap-2">
        <Button variant="outline" className="h-11" onClick={() => setParams({ from: addDays(from, -14) }, { replace: true })}>
          {t('common.previous')}
        </Button>
        <Button variant="outline" className="h-11" onClick={() => setParams({}, { replace: true })}>
          {t('leave.calendar.today')}
        </Button>
        <Button variant="outline" className="h-11" onClick={() => setParams({ from: addDays(from, 14) }, { replace: true })}>
          {t('common.next')}
        </Button>
      </div>
      {away.isPending ? <Spinner label={t('common.loading')} /> : null}
      {away.error ? <Alert tone="danger">{errorText(t, away.error)}</Alert> : null}
      <ul className="flex flex-col gap-2">
        {days.map((d) => {
          const people = byDay(d);
          return (
            <li key={d} className={`flex flex-col gap-1 rounded-card border border-border bg-surface p-3 sm:flex-row sm:items-center sm:gap-4 ${d === today ? 'border-primary' : ''}`}>
              <span className="w-40 shrink-0 text-sm font-semibold">
                {new Intl.DateTimeFormat(i18n.language, { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'Asia/Kolkata' }).format(new Date(asDate(d)))}
              </span>
              {people.length === 0 ? (
                <span className="text-sm text-muted">{t('leave.calendar.nobody')}</span>
              ) : (
                <span className="flex flex-wrap gap-1.5">
                  {people.map((p, i) =>
                    p.request_id ? (
                      <Link key={`${p.name}-${i}`} to={`/leave/requests/${p.request_id}`} className="rounded-full bg-primary-soft px-2.5 py-1 text-xs font-medium text-primary hover:underline">
                        {p.name}
                      </Link>
                    ) : (
                      <span key={`${p.name}-${i}`} className="rounded-full bg-surface-muted px-2.5 py-1 text-xs font-medium text-fg">
                        {p.name}
                      </span>
                    ),
                  )}
                </span>
              )}
            </li>
          );
        })}
      </ul>

      {canCover && cover.data && cover.data.data.length > 0 ? (
        <section className="flex flex-col gap-2">
          <h2 className="text-sm font-semibold">{t('leave.cover.title')}</h2>
          <div tabIndex={0} role="region" aria-label={t('leave.cover.title')} className="relative overflow-x-auto rounded-input border border-border focus-visible:outline-2 focus-visible:outline-ring">
            <table className="w-full text-sm">
              <thead className="bg-surface-muted text-xs text-muted">
                <tr>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('leave.cover.day')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('leave.cover.class')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('leave.cover.what')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('leave.cover.teacher')}</th>
                </tr>
              </thead>
              <tbody>
                {cover.data.data.map((row, i) => (
                  <tr key={`${row.date}-${row.section}-${i}`} className="border-t border-border">
                    <td className="px-3 py-2 whitespace-nowrap">{formatDate(asDate(row.date), i18n.language)}</td>
                    <td className="px-3 py-2">{row.section}</td>
                    <td className="px-3 py-2">{row.kind === 'register' ? t('leave.cover.register') : row.subject}</td>
                    <td className="px-3 py-2">{row.teacher}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      ) : null}
    </div>
  );
}
