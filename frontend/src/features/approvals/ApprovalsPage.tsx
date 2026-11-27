import { EmptyState } from '@campus-tab/ui';
import { useQuery } from '@tanstack/react-query';
import { ChevronLeft, Inbox } from 'lucide-react';
import { type ComponentType, lazy, Suspense } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Alert, Card, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { holds } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { addDays, type ApprovalInbox, type ApprovalItem, asDate, type AwayEntry, type LeaveDetail, todayInIndia } from '@/features/leave/api';
import { useLeaveDates } from '@/features/leave/dates';
import { LeaveRequestView } from '@/features/leave/LeaveRequestView';
import { apiGet } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import { apiGetBody } from '@/lib/requests';

/**
 * Other kinds of approval (mark corrections in Phase 6, fees in Phase 8)
 * register a detail panel here, loaded only when one is opened.
 */
const detailPanels: Partial<Record<ApprovalItem['kind'], ComponentType<{ id: string }>>> = {
  marks: lazy(() => import('@/features/assessment/CorrectionPanel').then((m) => ({ default: m.CorrectionPanel }))),
};

function LeaveDetailPanel({ id }: { id: string }) {
  const { t } = useTranslation();
  const request = useQuery({ queryKey: ['leave', 'request', id], queryFn: apiGet<LeaveDetail>(`/leave-requests/${id}`), retry: false });
  if (request.isPending) return <Spinner label={t('common.loading')} />;
  if (request.error) return <Alert tone="danger">{errorText(t, request.error)}</Alert>;
  return <LeaveRequestView request={request.data} />;
}

function ItemLine({ item }: { item: ApprovalItem }) {
  const { t } = useTranslation();
  const dates = useLeaveDates();
  const s = (item.subtitle ?? {}) as Record<string, string | number | boolean | null>;

  if (item.kind === 'leave') {
    const parts = [
      dates(String(s.from), String(s.to)),
      t('leave.dayCount', { count: Number(s.days) }),
      s.student ? t('leave.studentOfShort', { section: s.section ?? '' }) : null,
      s.last_action ? t(`leave.lastStep.${String(s.last_action)}`, { name: s.last_by ?? '', stage: t(`leave.stages.${String(s.last_stage)}`) }) : null,
    ];
    return <>{parts.filter(Boolean).join(' · ')}</>;
  }

  if (item.kind === 'marks') {
    const old = s.old as { value: number | null; code: string | null } | undefined;
    const next = s.new as { value: number | null; code: string | null } | undefined;
    const show = (m?: { value: number | null; code: string | null }) => (m ? (m.code ?? (m.value === null ? '–' : String(m.value))) : '–');
    return <>{t('approvals.marksLine', { old: show(old), new: show(next), name: String(s.by ?? '') })}</>;
  }

  return <>{item.detail ?? ''}</>;
}

/**
 * The approvals inbox: everything waiting on me (leave now; mark
 * corrections and fee adjustments as their phases arrive), oldest first.
 * The Principal also sees who is away this week. On phones the list and the
 * open item are separate views.
 */
export function ApprovalsPage() {
  const { t, i18n } = useTranslation();
  const me = useCurrentUser();
  const [params, setParams] = useSearchParams();
  const requested = params.get('tab') ?? 'all';
  const item = params.get('item');
  const inbox = useQuery({ queryKey: ['approvals', 'inbox'], queryFn: apiGetBody<ApprovalInbox>('/approvals/inbox'), retry: false, refetchOnWindowFocus: true });
  const today = todayInIndia();
  const away = useQuery({
    queryKey: ['leave', 'calendar', today, addDays(today, 6)],
    queryFn: apiGet<AwayEntry[]>('/leave/calendar', { from: today, to: addDays(today, 6) }),
    enabled: holds(me, 'leave.view') && (holds(me, 'leave.decide') || holds(me, 'leave.verify')),
    retry: false,
  });

  const counts = inbox.data?.counts ?? {};
  const kinds = Object.keys(counts).filter((k) => (counts[k] ?? 0) > 0);
  // A tab with nothing left in it (everything decided) falls back to All.
  const tab = requested !== 'all' && inbox.data && !kinds.includes(requested) ? 'all' : requested;
  const items = (inbox.data?.data ?? []).filter((i) => tab === 'all' || i.kind === tab);
  const [kind, id] = item ? (item.split(':') as [ApprovalItem['kind'], string]) : [null, null];
  const open = (next: string | null) => {
    const p = new URLSearchParams(params);
    if (next) p.set('item', next);
    else p.delete('item');
    setParams(p, { replace: false });
  };
  const Panel = kind && kind !== 'leave' ? detailPanels[kind] : undefined;

  return (
    <div className="mx-auto flex max-w-6xl flex-col gap-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{t('nav.approvals')}</h1>
          <p className="text-sm text-muted">{t('approvals.intro')}</p>
        </div>
        <div role="tablist" aria-label={t('approvals.filter')} className="flex flex-wrap gap-1 rounded-input bg-surface-muted p-1">
          {['all', ...kinds].map((k) => (
            <button
              key={k}
              type="button"
              role="tab"
              aria-selected={tab === k}
              onClick={() => setParams(k === 'all' ? {} : { tab: k }, { replace: true })}
              className={`h-10 rounded-input px-3 text-sm font-medium focus-visible:outline-2 focus-visible:outline-ring ${tab === k ? 'bg-surface text-fg shadow-sm' : 'text-muted hover:text-fg'}`}
            >
              {t(`approvals.kinds.${k}`)} {k === 'all' ? (inbox.data?.total ?? 0) : (counts[k] ?? 0)}
            </button>
          ))}
        </div>
      </div>

      {inbox.isPending ? <Spinner label={t('common.loading')} /> : null}
      {inbox.error ? <Alert tone="danger">{errorText(t, inbox.error)}</Alert> : null}

      <div className="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
        <div className={`flex flex-col gap-4 ${item ? 'hidden lg:flex' : ''}`}>
          {inbox.data && items.length === 0 ? <EmptyState icon={<Inbox className="size-5" />} title={t('approvals.empty')} /> : null}
          {items.length > 0 ? (
            <ul className="flex flex-col gap-2">
              {items.map((i) => {
                const key = `${i.kind}:${i.id}`;
                return (
                  <li key={key}>
                    <button
                      type="button"
                      aria-current={item === key ? 'true' : undefined}
                      onClick={() => open(key)}
                      className={`flex w-full flex-col gap-1 rounded-card border bg-surface p-4 text-start hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring ${item === key ? 'border-primary' : 'border-border'}`}
                    >
                      <span className="flex items-center justify-between gap-2 text-xs">
                        <span className="font-semibold tracking-wide text-primary uppercase">{t(`approvals.kinds.${i.kind}`)}</span>
                        {i.at ? <time dateTime={i.at} className="text-muted">{formatDateTime(i.at, i18n.language)}</time> : null}
                      </span>
                      <span className="text-sm font-semibold break-words text-fg">{i.title}</span>
                      <span className="text-xs text-muted">
                        <ItemLine item={i} />
                      </span>
                      {i.flags.length > 0 ? (
                        <span className="flex flex-wrap gap-1">
                          {i.flags.map((f) => (
                            <span key={f} className="rounded-full bg-status-pending-bg px-2 py-0.5 text-xs font-medium text-status-pending-fg">
                              {t(`approvals.flags.${f}`)}
                            </span>
                          ))}
                        </span>
                      ) : null}
                    </button>
                  </li>
                );
              })}
            </ul>
          ) : null}

          {away.data ? (
            <Card className="flex flex-col gap-2 p-4 sm:p-4">
              <h2 className="text-sm font-semibold">{t('approvals.awayThisWeek')}</h2>
              {away.data.length === 0 ? <p className="text-sm text-muted">{t('leave.calendar.nobody')}</p> : null}
              <ul className="flex flex-col gap-1 text-sm">
                {away.data.map((a, n) => (
                  <li key={`${a.name}-${n}`} className="flex justify-between gap-2">
                    <span className="truncate">{a.name}</span>
                    <span className="shrink-0 text-muted">
                      {formatDate(asDate(a.from), i18n.language)}
                      {a.to !== a.from ? ` – ${formatDate(asDate(a.to), i18n.language)}` : ''}
                    </span>
                  </li>
                ))}
              </ul>
              <Link to="/leave/calendar" className="text-sm font-medium text-primary hover:underline">
                {t('approvals.openCalendar')}
              </Link>
            </Card>
          ) : null}
        </div>

        <div className={item ? '' : 'hidden lg:block'}>
          {item && id ? (
            <Card className="flex flex-col gap-4">
              <button type="button" onClick={() => open(null)} className="inline-flex h-11 w-fit items-center gap-1 text-sm font-medium text-primary lg:hidden">
                <ChevronLeft aria-hidden="true" className="size-4 rtl:rotate-180" />
                {t('approvals.backToList')}
              </button>
              {kind === 'leave' ? <LeaveDetailPanel id={id} /> : null}
              {Panel ? (
                <Suspense fallback={<Spinner label={t('common.loading')} />}>
                  <Panel id={id} />
                </Suspense>
              ) : null}
            </Card>
          ) : (
            <Card className="hidden text-sm text-muted lg:block">{t('approvals.choose')}</Card>
          )}
        </div>
      </div>
    </div>
  );
}
