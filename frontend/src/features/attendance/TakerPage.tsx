import { useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Camera, FileSpreadsheet, Image, MoreHorizontal, WifiOff } from 'lucide-react';
import { memo, useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Field, Select, Spinner, Textarea } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { ApiError } from '@/lib/api';
import { downloadFile } from '@/lib/requests';
import { formatDateTime, formatLongDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
  type AttendanceCode,
  attendanceKeys,
  isMarkable,
  isNetworkFailure,
  MARKABLE,
  type MarkableCode,
  register,
  type RegisterDay,
  type RegisterImport,
  useAttendanceSettings,
  useRegisterDay,
} from './api';
import { codeTone } from './codes';
import { useOnline } from './useOnline';

const PHOTO_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
const PHOTO_MAX_BYTES = 5 * 1024 * 1024;
const LONG_PRESS_MS = 500;

function formatTime(iso: string, language: string): string {
  return new Intl.DateTimeFormat(language, { timeStyle: 'short', timeZone: 'Asia/Kolkata' }).format(new Date(iso));
}

/**
 * Taking attendance (TeacherAttendance.dc.html). Everyone starts present;
 * one tap marks a student absent (and back), long-press or the "…" button
 * gives Late and Half-day. One request saves the whole register; there is
 * no confirmation for a normal save. What the person may do (the edit
 * window, late entries from the paper register, office-only changes)
 * comes from the server's `rules`. Marking is online only: nothing is
 * queued on the device.
 */
export function AttendanceTakerPage() {
  const { t, i18n } = useTranslation();
  const { sectionId = '' } = useParams();
  const [params, setParams] = useSearchParams();
  const settings = useAttendanceSettings();
  const periodMode = settings.data?.student_mode === 'period';
  const date = params.get('date') ?? settings.data?.today;
  const period = Number(params.get('period') ?? (periodMode ? 1 : 0)) || 0;
  const day = useRegisterDay(sectionId, settings.data ? date : undefined, period);
  const [justSaved, setJustSaved] = useState<{ at: string | null; absent: number } | null>(null);
  const dateId = useId();

  const choose = (next: { date?: string; period?: number }) => {
    const p = new URLSearchParams(params);
    if (next.date) p.set('date', next.date);
    if (next.period !== undefined) p.set('period', String(next.period));
    setJustSaved(null);
    setParams(p, { replace: true });
  };

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-3">
      <div className="flex items-center gap-2">
        <Link to="/attendance" aria-label={t('attendance.taker.back')} className="inline-flex size-11 shrink-0 items-center justify-center rounded-input text-fg hover:bg-surface-muted">
          <ArrowLeft aria-hidden="true" className="size-5 rtl:rotate-180" />
        </Link>
        <div className="min-w-0 flex-1">
          <h1 className="text-xl font-bold tracking-tight">{day.data ? t('attendance.taker.title', { section: day.data.section.label }) : t('nav.attendance')}</h1>
          {date ? <p className="text-sm text-muted">{formatLongDate(new Date(`${date}T12:00:00+05:30`), i18n.language)}</p> : null}
        </div>
      </div>

      {settings.data ? (
        <div className="flex flex-wrap items-end gap-2">
          <div className="flex flex-col gap-1">
            <label htmlFor={dateId} className="text-xs font-medium text-muted">
              {t('attendance.date')}
            </label>
            <input
              id={dateId}
              type="date"
              value={date ?? ''}
              max={settings.data.today}
              onChange={(e) => e.target.value && choose({ date: e.target.value })}
              className="h-11 rounded-input border border-border bg-surface px-3 text-sm text-fg focus-visible:outline-2 focus-visible:outline-ring"
            />
          </div>
          {periodMode ? (
            <Field label={t('attendance.period')} className="w-32">
              {(p) => (
                <Select {...p} value={period} onChange={(e) => choose({ period: Number(e.target.value) })}>
                  {Array.from({ length: settings.data?.periods_per_day ?? 8 }, (_, i) => i + 1).map((n) => (
                    <option key={n} value={n}>
                      {t('attendance.periodN', { n })}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          ) : null}
        </div>
      ) : null}

      {settings.isPending || day.isPending ? <Spinner label={t('common.loading')} /> : null}
      {settings.error ? <Alert tone="danger">{errorText(t, settings.error)}</Alert> : null}
      {day.error ? <Alert tone="danger">{isNetworkFailure(day.error) ? t('attendance.taker.offline', { days: settings.data?.late_entry_days ?? 7 }) : errorText(t, day.error)}</Alert> : null}

      {day.data ? (
        <Register
          key={`${day.data.date}:${day.data.period}:${day.data.marked_at ?? ''}`}
          day={day.data}
          lateEntryDays={settings.data?.late_entry_days ?? 7}
          justSaved={justSaved}
          onSaved={setJustSaved}
        />
      ) : null}
    </div>
  );
}

function Register({
  day,
  lateEntryDays,
  justSaved,
  onSaved,
}: {
  day: RegisterDay;
  lateEntryDays: number;
  justSaved: { at: string | null; absent: number } | null;
  onSaved: (saved: { at: string | null; absent: number }) => void;
}) {
  const { t, i18n } = useTranslation();
  const queryClient = useQueryClient();
  const online = useOnline();
  const reasonId = useId();
  const rules = day.rules;
  const editable = rules.can_save && !day.holiday;
  const [statuses, setStatuses] = useState<Record<string, AttendanceCode>>(() => Object.fromEntries(day.students.map((s) => [s.id, s.status])));
  const [menuFor, setMenuFor] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [reasonError, setReasonError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [offlineTried, setOfflineTried] = useState(false);
  const [saving, setSaving] = useState(false);
  // When this register was opened: enough to know whether today's window had already passed.
  const [openedAt] = useState(() => Date.now());
  const showReason = editable && rules.needs_reason;

  const counts = useMemo(() => {
    const c: Record<AttendanceCode, number> = { P: 0, A: 0, L: 0, HD: 0, LV: 0, H: 0 };
    for (const code of Object.values(statuses)) c[code] += 1;
    return c;
  }, [statuses]);

  const setStatus = useCallback((id: string, code: MarkableCode) => {
    setStatuses((old) => ({ ...old, [id]: code }));
    setMenuFor(null);
  }, []);
  const toggle = useCallback((id: string) => setStatuses((old) => ({ ...old, [id]: old[id] === 'P' ? 'A' : 'P' })), []);

  const save = async () => {
    setError(null);
    setReasonError(null);
    if (!navigator.onLine) {
      setOfflineTried(true);
      return;
    }
    if (showReason && reason.trim().length < 5) {
      setReasonError(t(rules.reason_kind === 'late_entry' ? 'attendance.taker.reasonLateEntry' : 'attendance.taker.reasonLateEdit'));
      document.getElementById(reasonId)?.focus();
      return;
    }
    const body: { period?: number; statuses: Record<string, MarkableCode>; reason?: string } = { statuses: {} };
    for (const [id, code] of Object.entries(statuses)) if (isMarkable(code)) body.statuses[id] = code;
    if (day.period > 0) body.period = day.period;
    if (showReason) body.reason = reason.trim();

    setSaving(true);
    try {
      const next = await register.save(day.section.id, day.date, body);
      onSaved({ at: next.marked_at, absent: Object.values(body.statuses).filter((s) => s === 'A').length });
      queryClient.setQueryData(attendanceKeys.day(day.section.id, day.date, day.period), next);
      void queryClient.invalidateQueries({ queryKey: ['attendance', 'summary'] });
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    } catch (e) {
      if (isNetworkFailure(e)) setOfflineTried(true);
      else if (e instanceof ApiError && e.code === 'reason_required') setReasonError(errorText(t, e));
      else setError(errorText(t, e));
    } finally {
      setSaving(false);
    }
  };

  const refusal = rules.refusal ? t(`attendance.refusals.${rules.refusal}`, { defaultValue: rules.refusal_message ?? '', days: lateEntryDays }) : null;
  const todayWindow = editable && !rules.needs_reason && rules.window_closes_at;
  // Marking a day for the first time after its edit window: allowed, but the next change goes through the office.
  const windowPassed = todayWindow && new Date(rules.window_closes_at as string).getTime() <= openedAt;

  return (
    <div className="flex flex-col gap-3">
      {!online || offlineTried ? (
        <Alert tone="warning">
          <span className="flex items-start gap-2">
            <WifiOff aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
            {t('attendance.taker.offline', { days: lateEntryDays })}
          </span>
        </Alert>
      ) : null}

      {day.holiday ? <Alert tone="info">{t('attendance.taker.holiday', { title: day.holiday.title })}</Alert> : null}

      {day.late_entry ? <LateEntryNote day={day} /> : null}

      {!day.holiday && rules.reason_kind === 'late_entry' && !day.marked ? (
        <Alert tone="warning">
          <p className="font-semibold">{t('attendance.taker.lateEntryTitle')}</p>
          <p>{t('attendance.taker.lateEntryBody', { date: formatLongDate(new Date(`${day.date}T12:00:00+05:30`), i18n.language) })}</p>
          {rules.office_only && editable ? <p>{t('attendance.taker.lateEntryOffice', { days: lateEntryDays })}</p> : null}
        </Alert>
      ) : null}

      {!day.holiday && rules.reason_kind === 'late_edit' && editable ? <Alert tone="warning">{t('attendance.taker.lateEditOffice')}</Alert> : null}

      {justSaved && !editable ? (
        <p role="status" className="flex min-h-11 items-center justify-center rounded-card bg-status-approved-bg px-3 text-center text-sm font-semibold text-status-approved-fg">
          {t('attendance.taker.saved', { time: justSaved.at ? formatTime(justSaved.at, i18n.language) : '', count: justSaved.absent })}
        </p>
      ) : null}

      {!editable && !day.holiday && refusal && !justSaved ? (
        <Alert tone="danger">
          <p>{refusal}</p>
          {rules.reason_kind === 'late_edit' || rules.office_only ? <p className="mt-1">{t('attendance.taker.askOffice')}</p> : null}
        </Alert>
      ) : null}

      {todayWindow ? (
        <p className="text-sm text-muted">{windowPassed ? t('attendance.taker.windowPassed') : t('attendance.taker.windowCloses', { time: formatTime(rules.window_closes_at, i18n.language) })}</p>
      ) : null}

      <div className="sticky top-[var(--shell-header,4.5rem)] z-10 -mx-1 flex flex-wrap gap-1.5 bg-bg px-1 py-1.5" aria-live="polite" data-testid="counts">
        {(['P', 'A', 'L', 'HD', 'LV'] as const).map((code) =>
          code === 'HD' && counts.HD === 0 ? null : (
            <span key={code} className={cn('rounded-full px-2.5 py-1 text-xs font-semibold', codeTone[code])}>
              {t(`attendance.counts.${code}`, { n: counts[code] })}
            </span>
          ),
        )}
        {counts.H > 0 ? <span className={cn('rounded-full px-2.5 py-1 text-xs font-semibold', codeTone.H)}>{t('attendance.counts.H', { n: counts.H })}</span> : null}
      </div>

      {editable ? <p className="text-sm text-muted">{t('attendance.taker.hint')}</p> : null}

      <ul className="flex flex-col gap-1.5" aria-label={t('attendance.taker.list')}>
        {day.students.map((s) => (
          <StudentRow
            key={s.id}
            id={s.id}
            name={s.name}
            roll={s.roll_no}
            status={statuses[s.id] ?? s.status}
            readOnly={!editable || s.status === 'LV' || s.status === 'H'}
            menuOpen={menuFor === s.id}
            onToggle={toggle}
            onMenu={setMenuFor}
            onChoose={setStatus}
          />
        ))}
      </ul>

      {editable ? (
        <div className="sticky bottom-[calc(3.5rem+env(safe-area-inset-bottom))] z-10 -mx-4 flex flex-col gap-2 border-t border-border bg-surface px-4 pt-2.5 pb-2 md:bottom-0 md:mx-0 md:rounded-card md:border">
          {showReason ? (
            <div className="flex flex-col gap-1">
              <label htmlFor={reasonId} className="text-sm font-medium">
                {t(rules.reason_kind === 'late_entry' ? 'attendance.taker.reasonLabelLateEntry' : 'attendance.taker.reasonLabelLateEdit')}
              </label>
              <Textarea
                id={reasonId}
                value={reason}
                rows={2}
                maxLength={500}
                aria-invalid={Boolean(reasonError)}
                aria-describedby={reasonError ? `${reasonId}-error` : undefined}
                onChange={(e) => setReason(e.target.value)}
                className="min-h-16"
              />
              {reasonError ? (
                <p id={`${reasonId}-error`} role="alert" className="text-sm text-danger">
                  {reasonError}
                </p>
              ) : null}
            </div>
          ) : null}
          {error ? <Alert tone="danger">{error}</Alert> : null}
          {justSaved ? (
            <p role="status" className="flex min-h-11 items-center justify-center rounded-card bg-status-approved-bg px-3 text-center text-sm font-semibold text-status-approved-fg">
              {t('attendance.taker.saved', { time: justSaved.at ? formatTime(justSaved.at, i18n.language) : '', count: justSaved.absent })}
            </p>
          ) : null}
          <Button className="h-12 rounded-[12px] text-[15px] font-semibold" disabled={saving} onClick={() => void save()}>
            {saving ? t('common.working') : t('attendance.taker.save', { count: day.students.length })}
          </Button>
        </div>
      ) : null}

      {day.marked && !justSaved && day.marked_at ? (
        <p className="text-sm text-muted">{t('attendance.taker.markedBy', { name: day.marked_by ?? '—', when: formatDateTime(day.marked_at, i18n.language) })}</p>
      ) : null}

      {day.late_entry && day.day_id && !day.late_entry.has_photo && (justSaved || editable) ? <PhotoUpload dayId={day.day_id} section={day.section.id} date={day.date} period={day.period} /> : null}

      {editable && !day.holiday ? <ExcelEntry day={day} reason={reason} needsReason={showReason} lateEntryDays={lateEntryDays} /> : null}
    </div>
  );
}

interface RowProps {
  id: string;
  name: string;
  roll: number | null;
  status: AttendanceCode;
  readOnly: boolean;
  menuOpen: boolean;
  onToggle: (id: string) => void;
  onMenu: (id: string | null) => void;
  onChoose: (id: string, code: MarkableCode) => void;
}

const StudentRow = memo(function StudentRow({ id, name, roll, status, readOnly, menuOpen, onToggle, onMenu, onChoose }: RowProps) {
  const { t } = useTranslation();
  const timer = useRef<number | null>(null);
  const longPressed = useRef(false);
  const label = t(`attendance.codes.${status}`);

  const clear = () => {
    if (timer.current !== null) window.clearTimeout(timer.current);
    timer.current = null;
  };

  useEffect(() => clear, []);

  return (
    <li className="flex flex-col gap-1">
      <div className="flex items-stretch gap-1.5">
        <button
          type="button"
          disabled={readOnly}
          data-status={status}
          onPointerDown={() => {
            if (readOnly) return;
            longPressed.current = false;
            clear();
            timer.current = window.setTimeout(() => {
              longPressed.current = true;
              onMenu(id);
            }, LONG_PRESS_MS);
          }}
          onPointerUp={clear}
          onPointerLeave={clear}
          onPointerCancel={clear}
          onContextMenu={(e) => {
            if (readOnly) return;
            e.preventDefault();
            onMenu(id);
          }}
          onClick={() => {
            if (longPressed.current) {
              longPressed.current = false;
              return;
            }
            onToggle(id);
          }}
          className="flex min-h-13 min-w-0 flex-1 touch-manipulation items-center gap-2.5 rounded-[10px] border border-border bg-surface px-3 text-start text-[15px] font-medium text-fg select-none focus-visible:outline-2 focus-visible:outline-ring disabled:cursor-default"
        >
          <span className="w-6 shrink-0 text-[13px] text-muted tabular-nums">{roll ?? ''}</span>
          <span className="min-w-0 flex-1 truncate">{name}</span>
          <span className={cn('min-w-[70px] shrink-0 rounded-full px-2.5 py-1 text-center text-xs font-bold', codeTone[status])}>{label}</span>
          <span className="sr-only">
            , {readOnly ? (status === 'LV' ? t('attendance.taker.onLeaveNote') : t('attendance.taker.readOnlyNote')) : t('attendance.taker.tapToChange')}
          </span>
        </button>
        {!readOnly ? (
          <button
            type="button"
            aria-label={t('attendance.taker.moreFor', { name })}
            aria-expanded={menuOpen}
            onClick={() => onMenu(menuOpen ? null : id)}
            className="inline-flex w-11 shrink-0 items-center justify-center rounded-[10px] border border-border bg-surface text-muted hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
          >
            <MoreHorizontal aria-hidden="true" className="size-5" />
          </button>
        ) : null}
      </div>
      {menuOpen && !readOnly ? (
        <div
          role="group"
          aria-label={t('attendance.taker.chooseFor', { name })}
          className="grid grid-cols-4 gap-1.5"
          onKeyDown={(e) => {
            if (e.key === 'Escape') onMenu(null);
          }}
        >
          {MARKABLE.map((code) => (
            <button
              key={code}
              type="button"
              aria-pressed={status === code}
              onClick={() => onChoose(id, code)}
              className={cn(
                'min-h-11 rounded-input border text-xs font-bold focus-visible:outline-2 focus-visible:outline-ring',
                status === code ? cn(codeTone[code], 'border-transparent') : 'border-border bg-surface text-fg hover:bg-surface-muted',
              )}
            >
              {t(`attendance.codes.${code}`)}
            </button>
          ))}
        </div>
      ) : null}
    </li>
  );
});

/** A day entered late from the paper register: the chip, the reason, who, and the photo. */
function LateEntryNote({ day }: { day: RegisterDay }) {
  const { t, i18n } = useTranslation();
  const [error, setError] = useState<string | null>(null);
  const late = day.late_entry;
  if (!late) return null;

  const openPhoto = async () => {
    setError(null);
    const win = window.open('', '_blank');
    try {
      const { url } = await register.photoLink(day.day_id ?? '');
      if (win) {
        win.opener = null;
        win.location.href = url;
      } else window.location.assign(url);
    } catch (e) {
      win?.close();
      setError(errorText(t, e));
    }
  };

  return (
    <div className="flex flex-col gap-1.5 rounded-card border border-border bg-surface p-3 text-sm">
      <div className="flex flex-wrap items-center gap-2">
        <span className="inline-flex h-6 items-center rounded-full bg-status-pending-bg px-2.5 text-xs font-semibold text-status-pending-fg">{t('attendance.lateEntry')}</span>
        <span className="text-muted">
          {t('attendance.taker.enteredBy', { name: late.entered_by ?? '—', when: formatDateTime(late.entered_at, i18n.language) })}
          {late.by_office ? ` · ${t('attendance.taker.byOffice')}` : ''}
        </span>
      </div>
      <p>
        <span className="font-medium">{t('attendance.taker.reason')}: </span>
        {late.reason}
      </p>
      {late.has_photo && day.day_id ? (
        <Button variant="outline" size="sm" className="h-11 self-start" onClick={() => void openPhoto()}>
          <Image aria-hidden="true" />
          {t('attendance.taker.viewPhoto')}
        </Button>
      ) : null}
      {error ? <Alert tone="danger">{error}</Alert> : null}
    </div>
  );
}

/** After a late entry is saved: an optional photo of the paper page. */
function PhotoUpload({ dayId, section, date, period }: { dayId: string; section: string; date: string; period: number }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const id = useId();
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const pick = async (file: File | null) => {
    setProblem(null);
    if (!file) return;
    if (!PHOTO_TYPES.includes(file.type)) return setProblem(t('attendance.photo.type'));
    if (file.size > PHOTO_MAX_BYTES) return setProblem(t('attendance.photo.size'));
    setBusy(true);
    try {
      await register.uploadPhoto(dayId, file);
      await queryClient.invalidateQueries({ queryKey: attendanceKeys.day(section, date, period) });
    } catch (e) {
      setProblem(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex flex-col gap-1.5 rounded-card border border-dashed border-border bg-surface p-3">
      <label htmlFor={id} className="flex items-center gap-2 text-sm font-medium">
        <Camera aria-hidden="true" className="size-4 text-muted" />
        {t('attendance.photo.label')}
      </label>
      <input
        id={id}
        type="file"
        accept="image/png,image/jpeg,image/webp"
        capture="environment"
        disabled={busy}
        aria-describedby={`${id}-hint`}
        onChange={(e) => void pick(e.target.files?.[0] ?? null)}
        className="min-h-11 text-sm file:me-3 file:h-11 file:rounded-input file:border file:border-border file:bg-surface file:px-3 file:text-fg"
      />
      <p id={`${id}-hint`} className="text-xs text-muted">
        {t('attendance.photo.hint')}
      </p>
      {busy ? <Spinner label={t('common.working')} /> : null}
      {problem ? (
        <p role="alert" className="text-sm text-danger">
          {problem}
        </p>
      ) : null}
    </div>
  );
}

/** The paper register by Excel: template, upload, dry-run check, then commit. */
function ExcelEntry({ day, reason, needsReason, lateEntryDays }: { day: RegisterDay; reason: string; needsReason: boolean; lateEntryDays: number }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const id = useId();
  const [file, setFile] = useState<File | null>(null);
  const [result, setResult] = useState<RegisterImport | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const run = async (commit: boolean) => {
    if (!file) return;
    setError(null);
    if (!navigator.onLine) return setError(t('attendance.taker.offline', { days: lateEntryDays }));
    if (commit && needsReason && reason.trim().length < 5) return setError(t('attendance.excel.reasonFirst'));
    setBusy(true);
    try {
      const next = await register.import(day.section.id, day.date, { file, reason: reason.trim(), period: day.period, commit });
      setResult(next);
      if (next.committed) {
        void queryClient.invalidateQueries({ queryKey: attendanceKeys.day(day.section.id, day.date, day.period) });
        void queryClient.invalidateQueries({ queryKey: ['attendance', 'summary'] });
      }
    } catch (e) {
      setError(isNetworkFailure(e) ? t('attendance.taker.offline', { days: lateEntryDays }) : errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  const problems = result?.rows.filter((r) => r.status === 'error') ?? [];

  return (
    <details className="rounded-card border border-border bg-surface p-3">
      <summary className="flex min-h-11 cursor-pointer items-center gap-2 text-sm font-semibold">
        <FileSpreadsheet aria-hidden="true" className="size-4 text-muted" />
        {t('attendance.excel.title')}
      </summary>
      <div className="mt-2 flex flex-col gap-3">
        <p className="text-sm text-muted">{t('attendance.excel.intro')}</p>
        <Button
          variant="outline"
          className="self-start"
          onClick={() => void downloadFile(`/attendance/sections/${day.section.id}/template?date=${day.date}`, `attendance-${day.section.label}-${day.date}.xlsx`).catch((e: unknown) => setError(errorText(t, e)))}
        >
          {t('attendance.excel.template')}
        </Button>
        <div className="flex flex-col gap-1">
          <label htmlFor={id} className="text-sm font-medium">
            {t('attendance.excel.file')}
          </label>
          <input
            id={id}
            type="file"
            accept=".xlsx,.csv"
            onChange={(e) => {
              setFile(e.target.files?.[0] ?? null);
              setResult(null);
            }}
            className="min-h-11 text-sm file:me-3 file:h-11 file:rounded-input file:border file:border-border file:bg-surface file:px-3 file:text-fg"
          />
          {needsReason ? <p className="text-xs text-muted">{t('attendance.excel.reasonHint')}</p> : null}
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" disabled={!file || busy} onClick={() => void run(false)}>
            {t('attendance.excel.check')}
          </Button>
          {result && !result.committed && result.errors === 0 ? (
            <Button disabled={busy} onClick={() => void run(true)}>
              {t('attendance.excel.commit', { count: result.ok })}
            </Button>
          ) : null}
        </div>
        {busy ? <Spinner label={t('common.working')} /> : null}
        {error ? <Alert tone="danger">{error}</Alert> : null}
        {result ? (
          <div className="flex flex-col gap-2 text-sm" role="status">
            <p>{t('attendance.excel.counts', { ok: result.ok, errors: result.errors, absent: result.absent })}</p>
            {result.committed ? <Alert tone="success">{t('attendance.excel.committed')}</Alert> : null}
            {problems.length > 0 ? (
              <table className="w-full text-start text-sm">
                <thead>
                  <tr className="border-b border-border text-xs text-muted">
                    <th className="py-1 text-start font-medium">{t('imports.table.row')}</th>
                    <th className="py-1 text-start font-medium">{t('imports.table.record')}</th>
                    <th className="py-1 text-start font-medium">{t('imports.table.messages')}</th>
                  </tr>
                </thead>
                <tbody>
                  {problems.map((r) => (
                    <tr key={r.row} className="border-b border-border align-top">
                      <td className="py-1.5 pe-2 tabular-nums">{r.row}</td>
                      <td className="py-1.5 pe-2">{r.label}</td>
                      <td className="py-1.5 text-danger">{r.messages.join(' ')}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : null}
          </div>
        ) : null}
      </div>
    </details>
  );
}
