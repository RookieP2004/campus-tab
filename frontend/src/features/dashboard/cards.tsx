import { KpiCard, type Status, StatusChip, TodoList } from '@campus-tab/ui';
import { AlertTriangle, ArrowRight, Megaphone, Sparkles } from 'lucide-react';
import { type ReactNode, useId } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { buttonVariants } from '@/components/ui/button';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { useViewPrefs } from '@/stores/view';
import type { DashboardCard } from './api';

type Data = Record<string, unknown>;

const asArray = (value: unknown): Data[] => (Array.isArray(value) ? (value.filter((v) => v && typeof v === 'object') as Data[]) : []);
const asString = (value: unknown): string | null => (typeof value === 'string' && value !== '' ? value : null);
const asNumber = (value: unknown): number | null => (typeof value === 'number' && Number.isFinite(value) ? value : null);

/** Status words from the server mapped onto the eight chips. */
const chipFor: Record<string, Status> = {
  draft: 'draft',
  pending: 'pending',
  queued: 'pending',
  validating: 'pending',
  committing: 'pending',
  validated: 'verified',
  verified: 'verified',
  approved: 'approved',
  committed: 'approved',
  active: 'approved',
  returned: 'returned',
  rejected: 'rejected',
  failed: 'rejected',
  locked: 'locked',
  published: 'published',
  grace: 'pending',
  read_only: 'locked',
  expired: 'rejected',
  revoked: 'rejected',
  suspended: 'locked',
};

/** The card's translated title; the "new module" banner names the module in the reader's language. */
function useCardTitle(card: DashboardCard): string {
  const { t } = useTranslation();
  const params: Record<string, unknown> = { ...card.params };
  if (typeof params.module === 'string') params.module = t(`modules.${params.module}`, { defaultValue: params.module });
  return t(card.title, { ...params, defaultValue: card.title });
}

/**
 * One dashboard card, chosen by `kind`. A kind this version does not know
 * still renders, as a plain card with its title and link: nothing the
 * server sends is ever dropped.
 */
export function CardView({ card, featured = false }: { card: DashboardCard; featured?: boolean }) {
  switch (card.kind) {
    case 'banner':
      return <BannerCard card={card} />;
    case 'licence':
      return (
        <Frame card={card} featured={featured}>
          <LicenceBody data={card.data} />
        </Frame>
      );
    case 'todo':
      return (
        <Frame card={card} featured={featured}>
          <TodoBody card={card} />
        </Frame>
      );
    case 'action':
      return (
        <Frame card={card} featured={featured}>
          <ActionBody card={card} />
        </Frame>
      );
    case 'kpi':
      return (
        <Frame card={card} featured={featured}>
          <KpiBody data={card.data} />
        </Frame>
      );
    case 'list':
      return (
        <Frame card={card} featured={featured}>
          <ListBody data={card.data} />
        </Frame>
      );
    case 'children':
      return (
        <Frame card={card} featured={featured}>
          <ChildrenBody data={card.data} />
        </Frame>
      );
    case 'notices':
      return (
        <Frame card={card} featured={featured}>
          <NoticesBody data={card.data} />
        </Frame>
      );
    default:
      return <Frame card={card} featured={featured} />;
  }
}

function Frame({ card, featured, children }: { card: DashboardCard; featured: boolean; children?: ReactNode }) {
  const { t } = useTranslation();
  const id = useId();
  const title = useCardTitle(card);

  return (
    <section
      aria-labelledby={id}
      data-card-id={card.id}
      data-card-kind={card.kind}
      className={cn('flex min-w-0 flex-col gap-4 rounded-card border border-border bg-surface p-5', featured && 'border-primary/40 p-6 shadow-sm')}
    >
      <div className="flex items-start justify-between gap-3">
        <h2 id={id} className={cn('font-semibold text-fg', featured ? 'text-lg' : 'text-base')}>
          {title}
        </h2>
        {card.link ? (
          <Link to={card.link} className="inline-flex min-h-11 shrink-0 items-center gap-1 text-sm font-medium text-primary underline-offset-2 hover:underline">
            {t('dashboard.open')}
            <span className="sr-only">: {title}</span>
            <ArrowRight aria-hidden="true" className="size-4 rtl:rotate-180" />
          </Link>
        ) : null}
      </div>
      {children}
    </section>
  );
}

function BannerCard({ card }: { card: DashboardCard }) {
  const { t } = useTranslation();
  const title = useCardTitle(card);

  return (
    <section
      aria-label={title}
      data-card-id={card.id}
      data-card-kind={card.kind}
      role="status"
      className="col-span-full flex flex-wrap items-center gap-3 rounded-card border border-info/40 bg-surface p-4"
    >
      <Sparkles aria-hidden="true" className="size-5 shrink-0 text-info" />
      <p className="min-w-0 flex-1 text-sm font-medium text-fg">{title}</p>
      {card.link ? (
        <Link to={card.link} className={buttonVariants({ variant: 'outline' })}>
          {t('dashboard.banner.action')}
        </Link>
      ) : null}
    </section>
  );
}

function SeatKpi({ label, seats }: { label: string; seats: unknown }) {
  const { t, i18n } = useTranslation();
  const s = (seats && typeof seats === 'object' ? seats : {}) as Data;
  const used = asNumber(s.used) ?? 0;
  const limit = asNumber(s.limit) ?? 0;

  return (
    <KpiCard
      label={label}
      value={formatNumber(used, i18n.language)}
      detail={
        <>
          {t('dashboard.licence.seatsOf', { limit: formatNumber(limit, i18n.language), available: formatNumber(asNumber(s.available) ?? 0, i18n.language) })}
          {s.warning === true ? <span className="ms-1 font-semibold text-warning">· {t('dashboard.licence.nearlyFull')}</span> : null}
        </>
      }
      progress={limit > 0 ? (used / limit) * 100 : undefined}
      className="bg-bg"
    />
  );
}

function LicenceBody({ data }: { data: Data }) {
  const { t, i18n } = useTranslation();
  const state = asString(data.state) ?? 'active';

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
        <StatusChip status={chipFor[state] ?? 'draft'} label={t(`dashboard.licence.states.${state}`, { defaultValue: state })} />
        {asString(data.plan) ? <span>{t('dashboard.licence.plan', { plan: data.plan })}</span> : null}
        {asString(data.valid_to) ? <span className="text-muted">{t('dashboard.licence.validTo', { date: formatDate(asString(data.valid_to), i18n.language) })}</span> : null}
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <SeatKpi label={t('dashboard.licence.studentSeats')} seats={data.students} />
        <SeatKpi label={t('dashboard.licence.staffSeats')} seats={data.staff} />
      </div>
    </div>
  );
}

function TodoBody({ card }: { card: DashboardCard }) {
  const { t, i18n } = useTranslation();
  const count = asNumber(card.data.count) ?? 0;
  const items = asArray(card.data.items);

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted">{t('dashboard.todo.count', { count })}</p>
      <TodoList
        label={useCardTitle(card)}
        emptyLabel={t('dashboard.todo.empty')}
        items={items.map((item, index) => {
          const by = asString(item.by);
          const at = asString(item.at);
          const kind = asString(item.kind) ?? 'other';
          return {
            id: `${index}:${asString(item.link) ?? ''}`,
            tag: t(`dashboard.todo.kinds.${kind}`, { defaultValue: kind }),
            title: asString(item.label) ?? '',
            detail: [isoDay(asString(item.detail), i18n.language), by ? t('dashboard.by', { name: by }) : null, at ? formatDateTime(at, i18n.language) : null].filter(Boolean).join(' · '),
            status: { status: 'pending' as const, label: t('status.pending') },
            action: asString(item.link) ? (
              <Link to={asString(item.link) as string} className={buttonVariants({ variant: 'outline', size: 'sm', className: 'h-11' })}>
                {t('dashboard.todo.review')}
                <span className="sr-only">: {asString(item.label)}</span>
              </Link>
            ) : undefined,
          };
        })}
      />
    </div>
  );
}

/** Today's attendance (attendance_today): % present, sections marked, staff in, and sections not marked yet. */
function AttendanceTodayBody({ data }: { data: Data }) {
  const { t, i18n } = useTranslation();
  const totals = (data.totals && typeof data.totals === 'object' ? data.totals : {}) as Data;
  const staff = data.staff && typeof data.staff === 'object' ? (data.staff as Data) : null;
  const unmarked = Array.isArray(data.unmarked) ? data.unmarked.filter((s): s is string => typeof s === 'string') : [];
  const percent = asNumber(totals.percent);

  return (
    <div className="flex flex-col gap-3">
      <div className="grid grid-cols-2 gap-3">
        <KpiCard
          label={t('dashboard.attendance.present')}
          value={percent === null ? '—' : `${formatNumber(percent, i18n.language)}%`}
          detail={t('dashboard.attendance.presentOf', { present: formatNumber(asNumber(totals.present) ?? 0, i18n.language), students: formatNumber(asNumber(totals.students) ?? 0, i18n.language) })}
          progress={percent ?? undefined}
          className="bg-bg"
        />
        <KpiCard
          label={t('dashboard.attendance.marked')}
          value={`${asNumber(totals.marked_sections) ?? 0} / ${asNumber(totals.sections) ?? 0}`}
          detail={t('dashboard.attendance.absent', { count: asNumber(totals.absent) ?? 0 })}
          className="bg-bg"
        />
        {staff ? (
          <KpiCard
            label={t('dashboard.attendance.staff')}
            value={`${asNumber(staff.present) ?? 0} / ${asNumber(staff.total) ?? 0}`}
            detail={t('dashboard.attendance.staffLine', { late: asNumber(staff.late) ?? 0, notIn: asNumber(staff.not_in) ?? 0 })}
            className="col-span-2 bg-bg"
          />
        ) : null}
      </div>
      {unmarked.length > 0 ? <p className="text-sm text-muted">{t('dashboard.attendance.unmarked', { sections: unmarked.join(', ') })}</p> : null}
    </div>
  );
}

/**
 * An action card: one big button for the role's daily job, "Take
 * attendance" for a class teacher's section or "Check in" for staff. The
 * button opens the card's screen; the card never acts by itself.
 */
function ActionBody({ card }: { card: DashboardCard }) {
  const { t } = useTranslation();
  const data = card.data;
  const holiday = asString(data.holiday);
  const isCheckIn = 'checked_in' in data;
  let label: string;
  let line: string | null = null;

  if (isCheckIn) {
    const checkedIn = data.checked_in === true;
    label = t(!checkedIn ? 'dashboard.action.checkIn' : data.out ? 'dashboard.action.seeMyDay' : 'dashboard.action.checkOut');
    line = checkedIn
      ? [t('dashboard.action.inAt', { time: asString(data.in) ?? '' }), asString(data.out) ? t('dashboard.action.outAt', { time: asString(data.out) }) : null, data.late === true ? t('dashboard.action.late') : null].filter(Boolean).join(' · ')
      : asString(data.late_after)
        ? t('dashboard.action.lateAfter', { time: asString(data.late_after) })
        : null;
  } else if ('marked' in data || 'section_id' in data) {
    label = t(holiday ? 'dashboard.action.openRegister' : data.marked === true ? (data.editable === false ? 'dashboard.action.viewAttendance' : 'dashboard.action.changeAttendance') : 'dashboard.action.takeAttendance');
    line = holiday
      ? t('dashboard.action.holiday', { title: holiday })
      : data.marked === true
        ? t('dashboard.action.marked', { count: asNumber(data.absent) ?? 0 })
        : t('dashboard.action.notMarked');
  } else {
    label = t('dashboard.open');
  }

  return (
    <div className="flex flex-col gap-2">
      {line ? <p className="text-sm text-muted">{line}</p> : null}
      {card.link ? (
        <Link to={card.link} className={buttonVariants({ className: 'h-14 w-full text-base font-semibold' })}>
          {label}
        </Link>
      ) : null}
    </div>
  );
}

function KpiBody({ data }: { data: Data }) {
  const { t, i18n } = useTranslation();
  if (data.totals && typeof data.totals === 'object') return <AttendanceTodayBody data={data} />;
  const numbers = Object.entries(data).filter(([, v]) => asNumber(v) !== null) as [string, number][];
  const texts = Object.entries(data).filter(([, v]) => asString(v) !== null) as [string, string][];
  const byRole = data.by_role && typeof data.by_role === 'object' ? (Object.entries(data.by_role as Data).filter(([, v]) => asNumber(v) !== null) as [string, number][]) : [];

  return (
    <div className="flex flex-col gap-3">
      {numbers.length > 0 ? (
        <div className="grid grid-cols-2 gap-3">
          {numbers.map(([key, value]) => (
            <KpiCard key={key} label={t(`dashboard.fields.${key}`, { defaultValue: key })} value={formatNumber(value, i18n.language)} className="bg-bg" />
          ))}
        </div>
      ) : null}
      {texts.length > 0 ? (
        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
          {texts.map(([key, value]) => (
            <div key={key} className="contents">
              <dt className="text-muted">{t(`dashboard.fields.${key}`, { defaultValue: key })}</dt>
              <dd className="font-medium">{value}</dd>
            </div>
          ))}
        </dl>
      ) : null}
      {byRole.length > 0 ? (
        <ul className="flex flex-col divide-y divide-border text-sm">
          {byRole.map(([role, count]) => (
            <li key={role} className="flex items-center justify-between py-2">
              <span>{t(`roles.${role}`, { defaultValue: role })}</span>
              <span className="font-semibold tabular-nums">{formatNumber(count, i18n.language)}</span>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}

/** A bare "2027-01-26" becomes a readable date; anything else is shown as it is. */
function isoDay(value: string | null, language: string): string | null {
  return value && /^\d{4}-\d{2}-\d{2}$/.test(value) ? formatDate(value, language) : value;
}

/** "sign_in.blocked" → "Sign in blocked": a readable fallback for an audit code with no label yet. */
function humanise(code: string): string {
  const words = code.replace(/[._]+/g, ' ').trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
}

function ListBody({ data }: { data: Data }) {
  const { t, i18n } = useTranslation();
  const items = asArray(data.items);
  const strength = asNumber(data.strength);
  const thisMonth = asNumber(data.this_month);

  return (
    <div className="flex flex-col gap-3">
      {strength !== null ? <KpiCard label={t('dashboard.list.strength')} value={formatNumber(strength, i18n.language)} className="bg-bg" /> : null}
      {thisMonth !== null ? <p className="text-sm text-muted">{t('dashboard.list.thisMonth', { count: thisMonth })}</p> : null}
      {strength !== null ? <h3 className="text-sm font-semibold">{t('dashboard.list.birthdays')}</h3> : null}
      {items.length === 0 ? (
        <p className="text-sm text-muted">{t('dashboard.list.empty')}</p>
      ) : (
        <ul className="flex flex-col divide-y divide-border">
          {items.map((item, index) => {
            const link = asString(item.link);
            const status = asString(item.status);
            const by = asString(item.by);
            const at = asString(item.at);
            const audit = item.audit === true;
            const rawLabel = asString(item.label) ?? '';
            const rawDetail = asString(item.detail);
            const labelKey = asString(item.label_key);
            const shownLabel = labelKey ? t(labelKey, { defaultValue: rawLabel }) : audit ? t(`auditLog.actions.${rawLabel.replace(/\./g, '__')}`, { defaultValue: humanise(rawLabel) }) : rawLabel;
            const shownDetail = audit && rawDetail ? t(`auditLog.entities.${rawDetail}`, { defaultValue: humanise(rawDetail) }) : isoDay(rawDetail, i18n.language);
            const detail = [shownDetail, by ? t('dashboard.by', { name: by }) : null, at ? formatDateTime(at, i18n.language) : null].filter(Boolean).join(' · ');
            const label = (
              <span className="flex items-center gap-1.5 text-sm font-medium">
                {item.tone === 'warning' ? (
                  <>
                    <AlertTriangle aria-hidden="true" className="size-4 shrink-0 text-warning" />
                    <span className="sr-only">{t('dashboard.list.warning')}: </span>
                  </>
                ) : null}
                {shownLabel}
              </span>
            );
            return (
              <li key={index} className="flex min-h-11 items-center gap-3 py-2">
                <div className="min-w-0 flex-1">
                  {link ? (
                    <Link to={link} className="text-fg underline-offset-2 hover:underline">
                      {label}
                    </Link>
                  ) : (
                    label
                  )}
                  {detail ? <p className="text-xs text-muted">{detail}</p> : null}
                </div>
                {status ? <StatusChip status={chipFor[status] ?? 'draft'} label={t(`people.status.${status}`, { defaultValue: t(`imports.status.${status}`, { defaultValue: status }) })} /> : null}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

/** A parent's child switcher, at the top of their home (ParentHome.dc.html). */
function ChildrenBody({ data }: { data: Data }) {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const chosen = useViewPrefs((s) => s.children[me.id]);
  const setChild = useViewPrefs((s) => s.setChild);
  const items = asArray(data.items);
  const current = items.find((c) => c.id === chosen) ?? items[0];

  if (items.length === 0) return <p className="text-sm text-muted">{t('dashboard.children.none')}</p>;

  return (
    <div className="flex flex-col gap-4">
      <div role="radiogroup" aria-label={t('dashboard.children.switcher')} className="flex gap-2 relative overflow-x-auto pb-1">
        {items.map((child) => {
          const selected = child === current;
          const name = asString(child.label) ?? '';
          return (
            <button
              key={String(child.id)}
              type="button"
              role="radio"
              aria-checked={selected}
              onClick={() => setChild(me.id, String(child.id))}
              className={cn(
                'flex min-h-11 shrink-0 items-center gap-2 rounded-full border px-3 text-sm font-medium focus-visible:outline-2 focus-visible:outline-ring',
                selected ? 'border-primary bg-primary-soft text-primary' : 'border-border bg-surface text-fg hover:bg-surface-muted',
              )}
            >
              <span aria-hidden="true" className="flex size-7 items-center justify-center rounded-full bg-surface-muted text-xs font-bold text-fg">
                {name.slice(0, 1).toUpperCase()}
              </span>
              {name}
            </button>
          );
        })}
      </div>
      {current ? (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-card bg-bg p-4">
          <div>
            <p className="text-base font-semibold">{asString(current.label)}</p>
            {asString(current.detail) ? <p className="text-sm text-muted">{t('dashboard.children.section', { section: current.detail })}</p> : null}
          </div>
          {asString(current.link) ? (
            <div className="flex flex-wrap gap-2">
              <Link to={`${asString(current.link) as string}?tab=attendance`} className={buttonVariants({ variant: 'outline' })}>
                {t('dashboard.children.attendance')}
              </Link>
              <Link to={asString(current.link) as string} className={buttonVariants({ variant: 'outline' })}>
                {t('dashboard.children.profile')}
              </Link>
            </div>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

function NoticesBody({ data }: { data: Data }) {
  const { t, i18n } = useTranslation();
  const items = asArray(data.items);
  const unread = asNumber(data.unread) ?? 0;

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted">{t('dashboard.notices.unread', { count: unread })}</p>
      {items.length === 0 ? (
        <p className="text-sm text-muted">{t('notices.empty')}</p>
      ) : (
        <ul className="flex flex-col divide-y divide-border">
          {items.map((item) => (
            <li key={String(item.id)} className="flex min-h-11 items-start gap-2.5 py-2">
              <span aria-hidden="true" className={cn('mt-1.5 size-2 shrink-0 rounded-full', item.read ? 'bg-transparent' : 'bg-primary-solid')} />
              <div className="min-w-0">
                <Link to={asString(item.link) ?? '/notices'} className={cn('text-sm text-fg underline-offset-2 hover:underline', !item.read && 'font-semibold')}>
                  {asString(item.label)}
                  {!item.read ? <span className="sr-only"> ({t('notices.unread')})</span> : null}
                </Link>
                <p className="text-xs text-muted">
                  {[asString(item.by), formatDate(asString(item.at), i18n.language)].filter(Boolean).join(' · ')}
                </p>
              </div>
            </li>
          ))}
        </ul>
      )}
      {data.can_send === true ? (
        <Link to="/notices/new" className={buttonVariants({ variant: 'outline', className: 'self-start' })}>
          <Megaphone aria-hidden="true" />
          {t('notices.new')}
        </Link>
      ) : null}
    </div>
  );
}
