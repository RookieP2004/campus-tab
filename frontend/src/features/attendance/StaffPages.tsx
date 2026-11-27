import { EmptyState, StatusChip } from '@campus-tab/ui';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, LogIn, LogOut, MapPin, QrCode, Upload, UserCog } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Dialog, Field, Input, Select, Spinner, Textarea } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { holds } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { apiGet } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
  attendanceKeys,
  isNetworkFailure,
  type StaffImport,
  staffAttendance,
  type StaffRecord,
  useAttendanceSettings,
  useMyStaffAttendance,
  useStaffDay,
} from './api';
import { codeTone, monthOf } from './codes';

const STAFF_CODES: StaffRecord['status'][] = ['P', 'A', 'L', 'HD', 'LV'];

function Back() {
  const { t } = useTranslation();
  return (
    <Link to="/attendance" className="inline-flex min-h-11 items-center gap-1.5 self-start text-sm text-primary underline-offset-2 hover:underline">
      <ArrowLeft aria-hidden="true" className="size-4 rtl:rotate-180" />
      {t('attendance.backHome')}
    </Link>
  );
}

function CodePill({ code }: { code: StaffRecord['status'] }) {
  const { t } = useTranslation();
  return <span className={cn('inline-flex h-6 items-center rounded-full px-2.5 text-xs font-bold', codeTone[code])}>{t(`attendance.codes.${code}`)}</span>;
}

/**
 * The browser's location, asked for once, only when the school has a
 * geofence. It is sent with the check-in and never kept on the device.
 */
function currentPosition(): Promise<{ lat: number; lng: number }> {
  return new Promise((resolve, reject) => {
    if (!('geolocation' in navigator)) return reject(new Error('unavailable'));
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude }),
      (e) => reject(e),
      { enableHighAccuracy: true, timeout: 15_000, maximumAge: 0 },
    );
  });
}

/** My attendance: check in and out (app button or the office QR code), and my month. */
export function MyAttendancePage() {
  const { t, i18n } = useTranslation();
  const queryClient = useQueryClient();
  const settings = useAttendanceSettings();
  const month = settings.data ? monthOf(settings.data.today) : undefined;
  const mine = useMyStaffAttendance(month);
  const codeId = useId();
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<StaffRecord | null>(null);
  const data = mine.data;
  const today = result ?? data?.today ?? null;
  const next: 'in' | 'out' | null = !today?.in ? 'in' : !today.out ? 'out' : null;

  const act = async (method: 'app' | 'qr') => {
    if (!next || !data) return;
    setError(null);
    const body: { method: 'app' | 'qr'; lat?: number; lng?: number; qr?: string } = { method };
    if (method === 'qr') body.qr = code.trim();
    setBusy(true);
    try {
      if (data.geofence) {
        try {
          Object.assign(body, await currentPosition());
        } catch {
          setError(t('attendance.me.locationNeeded'));
          return;
        }
      }
      const record = await (next === 'in' ? staffAttendance.checkIn(body) : staffAttendance.checkOut(body));
      setResult(record);
      setCode('');
      void queryClient.invalidateQueries({ queryKey: ['attendance', 'staff', 'me'] });
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    } catch (e) {
      setError(isNetworkFailure(e) ? t('attendance.me.offline') : errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-4">
      <Back />
      <h1 className="text-2xl font-semibold tracking-tight">{t('nav.myAttendance')}</h1>
      {mine.isPending || settings.isPending ? <Spinner label={t('common.loading')} /> : null}
      {mine.error ? <Alert tone="danger">{errorText(t, mine.error)}</Alert> : null}
      {data && !data.is_staff ? <Alert tone="info">{t('attendance.me.notStaff')}</Alert> : null}

      {data?.is_staff ? (
        <>
          <Card className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-2 text-sm">
              {today ? <CodePill code={today.status} /> : <StatusChip status="draft" label={t('attendance.me.notIn')} />}
              {today?.in ? <span>{t('attendance.me.inAt', { time: today.in })}</span> : null}
              {today?.out ? <span>{t('attendance.me.outAt', { time: today.out })}</span> : null}
              {today?.late ? <StatusChip status="pending" label={t('attendance.me.late', { time: data.late_after })} /> : null}
            </div>
            {error ? <Alert tone="danger">{error}</Alert> : null}
            {result && result.inside_area !== null ? (
              <Alert tone={result.inside_area ? 'success' : 'warning'}>
                <span className="flex items-center gap-2">
                  <MapPin aria-hidden="true" className="size-4" />
                  {t(result.inside_area ? 'attendance.me.inside' : 'attendance.me.outside')}
                </span>
              </Alert>
            ) : null}
            {next ? (
              <Button className="h-14 text-base" disabled={busy} onClick={() => void act('app')}>
                {next === 'in' ? <LogIn aria-hidden="true" /> : <LogOut aria-hidden="true" />}
                {busy ? t('common.working') : t(next === 'in' ? 'attendance.me.checkIn' : 'attendance.me.checkOut')}
              </Button>
            ) : (
              <p className="text-sm font-medium text-success">{t('attendance.me.done')}</p>
            )}
            {data.geofence && next ? <p className="text-xs text-muted">{t('attendance.me.geofenceHint')}</p> : null}
            {next ? (
              <details className="rounded-input border border-border p-3">
                <summary className="flex min-h-11 cursor-pointer items-center gap-2 text-sm font-medium">
                  <QrCode aria-hidden="true" className="size-4 text-muted" />
                  {t('attendance.me.qrTitle')}
                </summary>
                <form
                  className="mt-2 flex flex-col gap-2"
                  onSubmit={(e) => {
                    e.preventDefault();
                    if (code.trim()) void act('qr');
                  }}
                >
                  <label htmlFor={codeId} className="text-sm font-medium">
                    {t('attendance.me.qrCode')}
                  </label>
                  <Input id={codeId} value={code} onChange={(e) => setCode(e.target.value)} autoComplete="off" spellCheck={false} className="font-mono" />
                  <p className="text-xs text-muted">{t('attendance.me.qrHint')}</p>
                  <Button type="submit" variant="outline" className="self-start" disabled={busy || !code.trim()}>
                    {t(next === 'in' ? 'attendance.me.checkInCode' : 'attendance.me.checkOutCode')}
                  </Button>
                </form>
              </details>
            ) : null}
          </Card>

          <section aria-labelledby="my-month" className="flex flex-col gap-2">
            <h2 id="my-month" className="text-lg font-semibold">
              {t('attendance.me.month')}
            </h2>
            {data.days.length === 0 ? (
              <p className="text-sm text-muted">{t('attendance.me.noDays')}</p>
            ) : (
              <ul className="flex flex-col divide-y divide-border rounded-card border border-border bg-surface">
                {data.days.map((d) => (
                  <li key={d.id} className="flex min-h-11 flex-wrap items-center gap-3 px-4 py-2 text-sm">
                    <span className="w-28 font-medium">{formatDate(`${d.date}T12:00:00+05:30`, i18n.language)}</span>
                    <CodePill code={d.status} />
                    <span className="text-muted">{[d.in, d.out].filter(Boolean).join(' – ')}</span>
                    {d.late ? <StatusChip status="pending" label={t('attendance.staff.late')} /> : null}
                    {d.source ? <span className="ms-auto text-xs text-muted">{t(`attendance.sources.${d.source}`, { defaultValue: d.source })}</span> : null}
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      ) : null}
    </div>
  );
}

/**
 * The office's view of a day: every staff member the server lists, with
 * their record, manual entry (for someone below you, never yourself: the
 * server says so) and the biometric import. Rows are never hidden here.
 */
export function StaffAttendancePage() {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const [params, setParams] = useSearchParams();
  const dateId = useId();
  const settings = useAttendanceSettings();
  const date = params.get('date') ?? settings.data?.today;
  const day = useStaffDay(date);
  const [entering, setEntering] = useState<{ staffId: string; name: string } | null>(null);
  const canEnter = holds(me, 'staff_attendance.enter');
  const canManage = holds(me, 'staff_attendance.manage');

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4">
      <Back />
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">{t('nav.staffAttendance')}</h1>
        {canManage ? (
          <Link to="/attendance/staff/qr" className="inline-flex h-11 items-center gap-2 rounded-input border border-border bg-surface px-4 text-sm font-medium hover:bg-surface-muted">
            <QrCode aria-hidden="true" className="size-4" />
            {t('attendance.qr.open')}
          </Link>
        ) : null}
      </div>
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
      {day.isPending ? <Spinner label={t('common.loading')} /> : null}
      {day.error ? <Alert tone="danger">{errorText(t, day.error)}</Alert> : null}
      {day.data && day.data.staff.length === 0 ? <EmptyState title={t('attendance.staff.none')} /> : null}
      {day.data && day.data.staff.length > 0 ? (
        <ul className="flex flex-col divide-y divide-border rounded-card border border-border bg-surface" aria-label={t('attendance.staff.list')}>
          {day.data.staff.map((s) => (
            <li key={s.staff_id} className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
              <div className="min-w-40 flex-1">
                <p className="font-semibold">{s.name}</p>
                <p className="text-xs text-muted">{[s.designation, ...s.roles.map((r) => t(`roles.${r}`, { defaultValue: r }))].filter(Boolean).join(' · ')}</p>
              </div>
              {s.record ? (
                <div className="flex flex-wrap items-center gap-2">
                  <CodePill code={s.record.status} />
                  {s.record.in || s.record.out ? <span className="tabular-nums">{[s.record.in, s.record.out].filter(Boolean).join(' – ')}</span> : null}
                  {s.record.late ? <StatusChip status="pending" label={t('attendance.staff.late')} /> : null}
                  {s.record.source ? <span className="text-xs text-muted">{t(`attendance.sources.${s.record.source}`, { defaultValue: s.record.source })}</span> : null}
                  {s.record.entered_by ? <span className="text-xs text-muted">{t('attendance.staff.enteredBy', { name: s.record.entered_by })}</span> : null}
                </div>
              ) : (
                <StatusChip status="draft" label={t('attendance.staff.noRecord')} />
              )}
              {canEnter ? (
                <Button variant="outline" size="sm" className="h-11" onClick={() => setEntering({ staffId: s.staff_id, name: s.name })}>
                  <UserCog aria-hidden="true" />
                  {t('attendance.staff.manual')}
                  <span className="sr-only">: {s.name}</span>
                </Button>
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}

      {canManage ? <BiometricImport /> : null}

      {entering && date ? <ManualEntryDialog staff={entering} date={date} onClose={() => setEntering(null)} /> : null}
    </div>
  );
}

function ManualEntryDialog({ staff, date, onClose }: { staff: { staffId: string; name: string }; date: string; onClose: () => void }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ date, status: 'P' as StaffRecord['status'], in: '', out: '', reason: '' });
  const [error, setError] = useState<string | null>(null);
  const [reasonError, setReasonError] = useState<string | undefined>();
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setError(null);
    setReasonError(undefined);
    if (form.reason.trim().length < 5) {
      setReasonError(t('attendance.staff.reasonRequired'));
      return;
    }
    setBusy(true);
    try {
      await staffAttendance.manual(staff.staffId, {
        date: form.date,
        status: form.status,
        ...(form.in ? { in: form.in } : {}),
        ...(form.out ? { out: form.out } : {}),
        reason: form.reason.trim(),
      });
      void queryClient.invalidateQueries({ queryKey: ['attendance', 'staff'] });
      onClose();
    } catch (e) {
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open title={t('attendance.staff.manualTitle', { name: staff.name })} onClose={onClose}>
      <form
        className="flex flex-col gap-3"
        noValidate
        onSubmit={(e) => {
          e.preventDefault();
          void submit();
        }}
      >
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Field label={t('attendance.date')}>{(p) => <Input {...p} type="date" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} />}</Field>
        <Field label={t('attendance.staff.status')}>
          {(p) => (
            <Select {...p} value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value as StaffRecord['status'] })}>
              {STAFF_CODES.map((c) => (
                <option key={c} value={c}>
                  {t(`attendance.codes.${c}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label={t('attendance.staff.in')}>{(p) => <Input {...p} type="time" value={form.in} onChange={(e) => setForm({ ...form, in: e.target.value })} />}</Field>
          <Field label={t('attendance.staff.out')}>{(p) => <Input {...p} type="time" value={form.out} onChange={(e) => setForm({ ...form, out: e.target.value })} />}</Field>
        </div>
        <Field label={t('attendance.staff.reason')} hint={t('attendance.staff.reasonHint')} error={reasonError}>
          {(p) => <Textarea {...p} rows={2} maxLength={300} value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} />}
        </Field>
        <div className="flex flex-wrap justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={busy}>
            {busy ? t('common.working') : t('common.save')}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

/** A biometric device's CSV (employee_code, date, in, out): dry run, then write. */
function BiometricImport() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const id = useId();
  const [file, setFile] = useState<File | null>(null);
  const [result, setResult] = useState<(StaffImport & { committed: boolean }) | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const run = async (commit: boolean) => {
    if (!file) return;
    setError(null);
    setBusy(true);
    try {
      const next = await staffAttendance.import(file, commit);
      setResult({ ...next, committed: commit });
      if (commit) void queryClient.invalidateQueries({ queryKey: ['attendance', 'staff'] });
    } catch (e) {
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  const problems = result?.rows.filter((r) => r.status === 'error') ?? [];

  return (
    <Card className="flex flex-col gap-3">
      <h2 className="flex items-center gap-2 text-lg font-semibold">
        <Upload aria-hidden="true" className="size-4 text-muted" />
        {t('attendance.biometric.title')}
      </h2>
      <p className="text-sm text-muted">{t('attendance.biometric.intro')}</p>
      <div className="flex flex-col gap-1">
        <label htmlFor={id} className="text-sm font-medium">
          {t('attendance.biometric.file')}
        </label>
        <input
          id={id}
          type="file"
          accept=".csv,.xlsx,text/csv"
          onChange={(e) => {
            setFile(e.target.files?.[0] ?? null);
            setResult(null);
          }}
          className="min-h-11 text-sm file:me-3 file:h-11 file:rounded-input file:border file:border-border file:bg-surface file:px-3 file:text-fg"
        />
      </div>
      <div className="flex flex-wrap gap-2">
        <Button variant="outline" disabled={!file || busy} onClick={() => void run(false)}>
          {t('attendance.excel.check')}
        </Button>
        {result && !result.committed && result.errors === 0 && result.ok > 0 ? (
          <Button disabled={busy} onClick={() => void run(true)}>
            {t('attendance.biometric.commit', { count: result.ok })}
          </Button>
        ) : null}
      </div>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      {result ? (
        <div role="status" className="flex flex-col gap-2 text-sm">
          <p>{t('attendance.biometric.counts', { ok: result.ok, errors: result.errors })}</p>
          {result.committed ? <Alert tone="success">{t('attendance.biometric.written', { count: result.written })}</Alert> : null}
          {problems.length > 0 ? (
            <ul className="flex flex-col gap-1">
              {problems.map((r) => (
                <li key={r.row} className="text-danger">
                  {t('attendance.biometric.rowProblem', { row: r.row, label: r.label, messages: r.messages.join(' ') })}
                </li>
              ))}
            </ul>
          ) : null}
        </div>
      ) : null}
    </Card>
  );
}

/**
 * The office QR screen: the current code, refreshed a little before it
 * expires. Each refresh is a background request (the screen sends it on
 * its own), so this screen never keeps a session alive by itself.
 */
export function StaffQrPage() {
  const { t, i18n } = useTranslation();
  const qr = useQuery({
    queryKey: attendanceKeys.qr,
    queryFn: apiGet<{ token: string; expires_at: string }>('/attendance/staff/qr'),
    meta: { background: true },
    retry: false,
    refetchIntervalInBackground: true,
    refetchInterval: (query) => {
      const expires = query.state.data?.expires_at;
      return expires ? Math.max(5_000, Date.parse(expires) - Date.now() - 15_000) : 60_000;
    },
  });

  return (
    <div className="mx-auto flex max-w-xl flex-col items-center gap-4 text-center">
      <div className="self-start">
        <Link to="/attendance/staff" className="inline-flex min-h-11 items-center gap-1.5 text-sm text-primary underline-offset-2 hover:underline">
          <ArrowLeft aria-hidden="true" className="size-4 rtl:rotate-180" />
          {t('nav.staffAttendance')}
        </Link>
      </div>
      <h1 className="text-2xl font-semibold tracking-tight">{t('attendance.qr.title')}</h1>
      <p className="text-sm text-muted">{t('attendance.qr.intro')}</p>
      {qr.isPending ? <Spinner label={t('common.loading')} /> : null}
      {qr.error ? <Alert tone="danger">{errorText(t, qr.error)}</Alert> : null}
      {qr.data ? (
        <>
          {/* White background in both themes so every camera can read it. */}
          <div className="rounded-card bg-white p-4">
            <QRCodeSVG value={qr.data.token} size={260} level="M" title={t('attendance.qr.alt')} />
          </div>
          <code className="rounded-input border border-border bg-surface-muted px-3 py-2 font-mono text-sm break-all select-all">{qr.data.token}</code>
          <p className="text-xs text-muted" aria-live="polite">
            {t('attendance.qr.refreshes', { time: formatDateTime(qr.data.expires_at, i18n.language) })}
          </p>
        </>
      ) : null}
    </div>
  );
}
