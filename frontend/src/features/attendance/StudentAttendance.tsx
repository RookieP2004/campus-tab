import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type AttendanceCode, useAttendanceSettings, useStudentAttendance } from './api';
import { ALL_CODES, codeTone, monthOf } from './codes';

/**
 * One student's attendance for a month and their % for the year: the
 * "Attendance" tab of the student profile, which is also what a parent
 * sees for their child. The server decides who may see it.
 */
export function StudentAttendance({ studentId }: { studentId: string }) {
  const { t, i18n } = useTranslation();
  const monthId = useId();
  const settings = useAttendanceSettings();
  const [chosen, setChosen] = useState<string | null>(null);
  const month = chosen ?? (settings.data ? monthOf(settings.data.today) : undefined);
  const { data, isPending, error } = useStudentAttendance(studentId, month);

  const counts = ALL_CODES.reduce<Record<AttendanceCode, number>>((acc, code) => ({ ...acc, [code]: data?.days.filter((d) => d.status === code).length ?? 0 }), {
    P: 0,
    A: 0,
    L: 0,
    HD: 0,
    LV: 0,
    H: 0,
  });
  const below = data && data.percent !== null && data.percent < data.threshold;

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <label htmlFor={monthId} className="text-sm font-medium">
          {t('attendance.month')}
        </label>
        <input
          id={monthId}
          type="month"
          value={month ?? ''}
          onChange={(e) => e.target.value && setChosen(e.target.value)}
          className="h-11 w-48 rounded-input border border-border bg-surface px-3 text-sm text-fg focus-visible:outline-2 focus-visible:outline-ring"
        />
      </div>
      {isPending && month ? <Spinner label={t('common.loading')} /> : null}
      {settings.error || error ? <Alert tone="danger">{errorText(t, error ?? settings.error)}</Alert> : null}
      {data ? (
        <>
          <div className="flex flex-wrap items-baseline gap-3">
            <p className={cn('text-3xl font-bold tabular-nums', below ? 'text-danger' : 'text-fg')}>{data.percent === null ? '—' : `${formatNumber(data.percent, i18n.language)}%`}</p>
            <p className="text-sm text-muted">{t('attendance.student.yearPercent')}</p>
          </div>
          {below ? <Alert tone="warning">{t('attendance.student.below', { threshold: data.threshold })}</Alert> : null}
          <div className="flex flex-wrap gap-1.5">
            {(['P', 'A', 'L', 'HD', 'LV', 'H'] as const)
              .filter((c) => counts[c] > 0)
              .map((c) => (
                <span key={c} className={cn('rounded-full px-2.5 py-1 text-xs font-semibold', codeTone[c])}>
                  {t(`attendance.counts.${c}`, { n: counts[c] })}
                </span>
              ))}
          </div>
          {data.days.length === 0 ? (
            <p className="text-sm text-muted">{t('attendance.student.noDays')}</p>
          ) : (
            <ul className="grid grid-cols-[repeat(auto-fill,minmax(7.5rem,1fr))] gap-1.5">
              {data.days.map((d) => (
                <li key={d.date} className="flex items-center justify-between gap-2 rounded-input border border-border bg-surface px-2.5 py-2 text-sm">
                  <span className="tabular-nums">{new Intl.DateTimeFormat(i18n.language, { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(`${d.date}T00:00:00Z`))}</span>
                  {d.status ? (
                    <span className={cn('rounded-full px-2 py-0.5 text-xs font-bold', codeTone[d.status])}>
                      {t(`attendance.codes.${d.status}`)}
                      {d.late_entry ? <span className="sr-only"> ({t('attendance.lateEntry')})</span> : null}
                      {d.late_entry ? <span aria-hidden="true">*</span> : null}
                    </span>
                  ) : (
                    <span className="text-xs text-muted">—</span>
                  )}
                </li>
              ))}
            </ul>
          )}
          {data.days.some((d) => d.late_entry) ? <p className="text-xs text-muted">{t('attendance.register.lateLegend')}</p> : null}
        </>
      ) : null}
    </div>
  );
}
