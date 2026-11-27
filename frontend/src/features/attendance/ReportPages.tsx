import { EmptyState, StatusChip } from '@campus-tab/ui';
import { ArrowLeft, Printer } from 'lucide-react';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Field, Select, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { useAttendanceSettings, useBelowThreshold, useDailySummary, useMonthlyRegister } from './api';
import { codeTone, monthOf } from './codes';

function BackToAttendance() {
  const { t } = useTranslation();
  return (
    <Link to="/attendance" className="inline-flex min-h-11 items-center gap-1.5 self-start text-sm text-primary underline-offset-2 hover:underline print:hidden">
      <ArrowLeft aria-hidden="true" className="size-4 rtl:rotate-180" />
      {t('attendance.backHome')}
    </Link>
  );
}

const pct = (value: number | null | undefined, language: string) => (value === null || value === undefined ? '—' : `${formatNumber(value, language)}%`);

/** The daily summary: every section the person may see, marked or not, with %; the staff line; students below the threshold. */
export function AttendanceSummaryPage() {
  const { t, i18n } = useTranslation();
  const [params, setParams] = useSearchParams();
  const dateId = useId();
  const settings = useAttendanceSettings();
  const date = params.get('date') ?? settings.data?.today;
  const summary = useDailySummary(date);
  const below = useBelowThreshold();
  const data = summary.data;

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-4">
      <BackToAttendance />
      <h1 className="text-2xl font-semibold tracking-tight">{t('attendance.summary.title')}</h1>
      {settings.data ? (
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
      {summary.isPending ? <Spinner label={t('common.loading')} /> : null}
      {summary.error ? <Alert tone="danger">{errorText(t, summary.error)}</Alert> : null}

      {data ? (
        <>
          <div className="grid gap-3 sm:grid-cols-3">
            <Card className="p-4">
              <p className="text-sm text-muted">{t('attendance.summary.present')}</p>
              <p className="text-2xl font-bold tabular-nums">{pct(data.totals.percent, i18n.language)}</p>
              <p className="text-xs text-muted">{t('attendance.summary.presentOf', { present: formatNumber(data.totals.present, i18n.language), students: formatNumber(data.totals.students, i18n.language) })}</p>
            </Card>
            <Card className="p-4">
              <p className="text-sm text-muted">{t('attendance.summary.sectionsMarked')}</p>
              <p className="text-2xl font-bold tabular-nums">
                {data.totals.marked_sections} / {data.totals.sections}
              </p>
              <p className="text-xs text-muted">{t('attendance.summary.absentCount', { count: data.totals.absent })}</p>
            </Card>
            {data.staff ? (
              <Card className="p-4">
                <p className="text-sm text-muted">{t('attendance.summary.staff')}</p>
                <p className="text-2xl font-bold tabular-nums">
                  {data.staff.present} / {data.staff.total}
                </p>
                <p className="text-xs text-muted">{t('attendance.summary.staffLine', { late: data.staff.late, notIn: data.staff.not_in })}</p>
              </Card>
            ) : null}
          </div>

          <Card className="overflow-x-auto p-0">
            <table className="w-full min-w-[640px] text-sm">
              <caption className="sr-only">{t('attendance.summary.tableCaption', { date: data.date })}</caption>
              <thead>
                <tr className="border-b border-border text-xs text-muted">
                  <th scope="col" className="px-4 py-2 text-start font-medium">{t('attendance.summary.section')}</th>
                  <th scope="col" className="px-2 py-2 text-start font-medium">{t('attendance.summary.state')}</th>
                  <th scope="col" className="px-2 py-2 text-end font-medium">{t('attendance.codes.P')}</th>
                  <th scope="col" className="px-2 py-2 text-end font-medium">{t('attendance.codes.A')}</th>
                  <th scope="col" className="px-2 py-2 text-end font-medium">{t('attendance.codes.L')}</th>
                  <th scope="col" className="px-2 py-2 text-end font-medium">{t('attendance.codes.LV')}</th>
                  <th scope="col" className="px-2 py-2 text-end font-medium">%</th>
                  <th scope="col" className="px-4 py-2 text-end font-medium">
                    <span className="sr-only">{t('attendance.summary.links')}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {data.sections.map((s) => (
                  <tr key={s.section_id} className="border-b border-border last:border-0">
                    <th scope="row" className="px-4 py-2 text-start font-semibold">
                      {s.section}
                    </th>
                    <td className="px-2 py-2">
                      <span className="flex flex-wrap gap-1">
                        <StatusChip status={s.marked ? 'approved' : 'draft'} label={t(s.marked ? 'attendance.marked' : 'attendance.notMarked')} />
                        {s.late_entry ? <StatusChip status="pending" label={t('attendance.lateEntry')} /> : null}
                      </span>
                    </td>
                    <td className="px-2 py-2 text-end tabular-nums">{s.counts.P}</td>
                    <td className="px-2 py-2 text-end tabular-nums">{s.counts.A}</td>
                    <td className="px-2 py-2 text-end tabular-nums">{s.counts.L}</td>
                    <td className="px-2 py-2 text-end tabular-nums">{s.counts.LV}</td>
                    <td className="px-2 py-2 text-end font-semibold tabular-nums">{pct(s.percent, i18n.language)}</td>
                    <td className="px-4 py-2 text-end whitespace-nowrap">
                      <Link to={`/attendance/${s.section_id}?date=${data.date}`} className="inline-flex min-h-11 items-center px-1 text-primary underline-offset-2 hover:underline">
                        {t('attendance.summary.open')}
                        <span className="sr-only"> {s.section}</span>
                      </Link>{' '}
                      <Link to={`/attendance/register?section_id=${s.section_id}&month=${monthOf(data.date)}`} className="inline-flex min-h-11 items-center px-1 text-primary underline-offset-2 hover:underline">
                        {t('attendance.summary.register')}
                        <span className="sr-only"> {s.section}</span>
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>
        </>
      ) : null}

      <section aria-labelledby="below-title" className="flex flex-col gap-2">
        <h2 id="below-title" className="text-lg font-semibold">
          {t('attendance.below.title', { threshold: below.data?.threshold ?? settings.data?.min_percent_alert ?? 75 })}
        </h2>
        {below.error ? <Alert tone="danger">{errorText(t, below.error)}</Alert> : null}
        {below.data && below.data.students.length === 0 ? <p className="text-sm text-muted">{t('attendance.below.none')}</p> : null}
        {below.data && below.data.students.length > 0 ? (
          <ul className="flex flex-col divide-y divide-border rounded-card border border-border bg-surface">
            {below.data.students.map((s) => (
              <li key={s.id} className="flex min-h-11 items-center gap-3 px-4 py-2 text-sm">
                <Link to={`/students/${s.id}?tab=attendance`} className="min-w-0 flex-1 font-medium text-fg underline-offset-2 hover:underline">
                  {s.name}
                </Link>
                <span className="text-muted">{s.section}</span>
                <span className="font-semibold text-danger tabular-nums">{pct(s.percent, i18n.language)}</span>
              </li>
            ))}
          </ul>
        ) : null}
      </section>
    </div>
  );
}

/** The monthly register: students × days, the code each day, late-entry days marked, and %. Printable. */
export function AttendanceRegisterPage() {
  const { t, i18n } = useTranslation();
  const [params, setParams] = useSearchParams();
  const monthId = useId();
  const settings = useAttendanceSettings();
  const sectionId = params.get('section_id') ?? '';
  const month = params.get('month') ?? (settings.data ? monthOf(settings.data.today) : undefined);
  const summary = useDailySummary(settings.data?.today, !sectionId);
  const reg = useMonthlyRegister(sectionId, month);
  const data = reg.data;
  const late = new Set(data?.late_entries ?? []);

  const update = (next: Record<string, string>) => setParams({ ...Object.fromEntries(params), ...next }, { replace: true });

  return (
    <div className="flex flex-col gap-4">
      <BackToAttendance />
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">
          {data ? t('attendance.register.titleFor', { section: data.section.label, month: formatMonth(data.month, i18n.language) }) : t('attendance.register.title')}
        </h1>
        <div className="flex flex-wrap items-end gap-2 print:hidden">
          {!sectionId && summary.data ? (
            <Field label={t('attendance.summary.section')} className="w-44">
              {(p) => (
                <Select {...p} value="" onChange={(e) => update({ section_id: e.target.value })}>
                  <option value="">{t('attendance.register.chooseSection')}</option>
                  {summary.data.sections.map((s) => (
                    <option key={s.section_id} value={s.section_id}>
                      {s.section}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          ) : null}
          <div className="flex flex-col gap-1">
            <label htmlFor={monthId} className="text-sm font-medium">
              {t('attendance.month')}
            </label>
            <input
              id={monthId}
              type="month"
              value={month ?? ''}
              onChange={(e) => e.target.value && update({ month: e.target.value })}
              className="h-11 rounded-input border border-border bg-surface px-3 text-sm text-fg focus-visible:outline-2 focus-visible:outline-ring"
            />
          </div>
          <Button variant="outline" disabled={!data} onClick={() => window.print()}>
            <Printer aria-hidden="true" />
            {t('attendance.register.print')}
          </Button>
        </div>
      </div>

      {reg.isPending && sectionId ? <Spinner label={t('common.loading')} /> : null}
      {reg.error ? <Alert tone="danger">{errorText(t, reg.error)}</Alert> : null}
      {data && data.students.length === 0 ? <EmptyState title={t('attendance.register.empty')} /> : null}

      {data && data.students.length > 0 ? (
        <div className="overflow-x-auto rounded-card border border-border bg-surface print:overflow-visible print:border-0">
          <table className="register-grid w-full border-collapse text-xs">
            <caption className="sr-only">{t('attendance.register.caption')}</caption>
            <thead>
              <tr className="border-b border-border">
                <th scope="col" className="sticky start-0 z-[1] bg-surface px-2 py-1.5 text-start font-medium">{t('attendance.register.roll')}</th>
                <th scope="col" className="sticky start-10 z-[1] min-w-40 bg-surface px-2 py-1.5 text-start font-medium">{t('attendance.register.name')}</th>
                {data.dates.map((d) => (
                  <th key={d} scope="col" className={cn('min-w-7 px-0.5 py-1.5 text-center font-medium tabular-nums', late.has(d) && 'text-warning')}>
                    {Number(d.slice(8))}
                    {late.has(d) ? (
                      <>
                        <span aria-hidden="true">*</span>
                        <span className="sr-only"> ({t('attendance.lateEntry')})</span>
                      </>
                    ) : null}
                  </th>
                ))}
                <th scope="col" className="px-2 py-1.5 text-end font-medium">%</th>
              </tr>
            </thead>
            <tbody>
              {data.students.map((s) => (
                <tr key={s.id} className="border-b border-border last:border-0">
                  <td className="sticky start-0 bg-surface px-2 py-1 tabular-nums">{s.roll_no ?? ''}</td>
                  <th scope="row" className="sticky start-10 bg-surface px-2 py-1 text-start font-medium whitespace-nowrap">
                    {s.name}
                  </th>
                  {data.dates.map((d) => {
                    const code = s.marks[d] ?? null;
                    return (
                      <td key={d} className="px-0.5 py-1 text-center">
                        {code ? (
                          <span className={cn('inline-block min-w-6 rounded px-0.5 font-semibold', codeTone[code])} title={t(`attendance.codes.${code}`)}>
                            {code}
                          </span>
                        ) : (
                          <span className="text-muted" aria-label={t('attendance.register.noMark')}>
                            ·
                          </span>
                        )}
                      </td>
                    );
                  })}
                  <td className={cn('px-2 py-1 text-end font-semibold tabular-nums', s.percent !== null && s.percent < (settings.data?.min_percent_alert ?? 75) && 'text-danger')}>
                    {pct(s.percent, i18n.language)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {data ? (
        <p className="text-xs text-muted">
          {t('attendance.register.legend')}
          {late.size > 0 ? ` ${t('attendance.register.lateLegend')}` : ''}
        </p>
      ) : null}
    </div>
  );
}

function formatMonth(month: string, language: string): string {
  return new Intl.DateTimeFormat(language, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${month}-01T00:00:00Z`));
}
