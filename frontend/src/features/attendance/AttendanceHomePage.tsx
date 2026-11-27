import { EmptyState, StatusChip } from '@campus-tab/ui';
import { CalendarDays, ChevronRight, ClipboardList, Clock, IdCard, LineChart } from 'lucide-react';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Alert, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { holds } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { formatNumber } from '@/lib/format';
import { useAttendanceSettings, useDailySummary } from './api';

/**
 * Attendance home: the sections this person may mark or see for a date
 * (the server's daily summary lists exactly those), plus links to the
 * other attendance screens they may open.
 */
export function AttendanceHomePage() {
  const { t, i18n } = useTranslation();
  const me = useCurrentUser();
  const [params, setParams] = useSearchParams();
  const dateId = useId();
  const canView = holds(me, 'attendance.view') || holds(me, 'attendance.mark');
  const settings = useAttendanceSettings();
  const date = params.get('date') ?? settings.data?.today;
  const summary = useDailySummary(date, holds(me, 'attendance.view'));

  const links = [
    holds(me, 'attendance.view') ? { to: `/attendance/summary${date ? `?date=${date}` : ''}`, label: 'attendance.summary.title', icon: LineChart } : null,
    holds(me, 'staff_attendance.mark') ? { to: '/attendance/me', label: 'nav.myAttendance', icon: Clock } : null,
    holds(me, 'staff_attendance.manage') || holds(me, 'staff_attendance.enter') || holds(me, 'staff_attendance.view', 'school') ? { to: '/attendance/staff', label: 'nav.staffAttendance', icon: IdCard } : null,
    holds(me, 'calendar.view') ? { to: '/calendar', label: 'nav.calendar', icon: CalendarDays } : null,
  ].filter((l): l is NonNullable<typeof l> => l !== null);

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4">
      <h1 className="text-2xl font-semibold tracking-tight">{t('nav.attendance')}</h1>

      {canView && settings.data ? (
        <div className="flex flex-col gap-1">
          <label htmlFor={dateId} className="text-sm font-medium">
            {t('attendance.date')}
          </label>
          <input
            id={dateId}
            type="date"
            value={date ?? ''}
            max={settings.data.today}
            onChange={(e) => e.target.value && setParams({ date: e.target.value }, { replace: true })}
            className="h-11 w-48 rounded-input border border-border bg-surface px-3 text-sm text-fg focus-visible:outline-2 focus-visible:outline-ring"
          />
        </div>
      ) : null}

      {settings.isPending || (summary.isPending && summary.fetchStatus !== 'idle') ? <Spinner label={t('common.loading')} /> : null}
      {summary.error ? <Alert tone="danger">{errorText(t, summary.error)}</Alert> : null}

      {summary.data ? (
        summary.data.sections.length === 0 ? (
          <EmptyState icon={<ClipboardList className="size-5" />} title={t('attendance.home.noSections')} />
        ) : (
          <section aria-labelledby="att-sections">
            <h2 id="att-sections" className="mb-2 text-base font-semibold">
              {t('attendance.home.sections')}
            </h2>
            <ul className="flex flex-col gap-2">
              {summary.data.sections.map((s) => (
                <li key={s.section_id}>
                  <Link
                    to={`/attendance/${s.section_id}?date=${summary.data.date}`}
                    className="flex min-h-14 items-center gap-3 rounded-card border border-border bg-surface px-4 py-2 hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
                  >
                    <span className="min-w-0 flex-1">
                      <span className="block text-base font-semibold">{s.section}</span>
                      <span className="block text-xs text-muted">
                        {s.marked
                          ? t('attendance.home.sectionLine', { absent: s.counts.A, percent: s.percent === null ? '—' : formatNumber(s.percent, i18n.language) })
                          : t('attendance.home.notMarkedLine')}
                      </span>
                    </span>
                    {s.late_entry ? <StatusChip status="pending" label={t('attendance.lateEntry')} /> : null}
                    <StatusChip status={s.marked ? 'approved' : 'draft'} label={t(s.marked ? 'attendance.marked' : 'attendance.notMarked')} />
                    <ChevronRight aria-hidden="true" className="size-4 shrink-0 text-muted rtl:rotate-180" />
                  </Link>
                </li>
              ))}
            </ul>
          </section>
        )
      ) : null}

      {links.length > 0 ? (
        <nav aria-label={t('attendance.home.more')} className="flex flex-col gap-2">
          {links.map((l) => (
            <Link key={l.to} to={l.to} className="flex min-h-12 items-center gap-3 rounded-card border border-border bg-surface px-4 text-sm font-medium hover:bg-surface-muted">
              <l.icon aria-hidden="true" className="size-5 text-muted" />
              <span className="flex-1">{t(l.label)}</span>
              <ChevronRight aria-hidden="true" className="size-4 text-muted rtl:rotate-180" />
            </Link>
          ))}
        </nav>
      ) : null}
    </div>
  );
}
