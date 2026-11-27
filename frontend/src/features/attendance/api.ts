import { useQuery } from '@tanstack/react-query';
import { apiGet } from '@/lib/api';
import { send } from '@/lib/requests';

/** P A L HD the teacher sets; LV (approved leave) and H (holiday) are read-only. */
export type AttendanceCode = 'P' | 'A' | 'L' | 'HD' | 'LV' | 'H';
export type MarkableCode = 'P' | 'A' | 'L' | 'HD';
export const MARKABLE: MarkableCode[] = ['P', 'A', 'L', 'HD'];
export const isMarkable = (code: string): code is MarkableCode => (MARKABLE as string[]).includes(code);

export interface AttendanceSettings {
  student_mode: 'daily' | 'period';
  edit_window_hours: number;
  min_percent_alert: number;
  late_entry_days: number;
  school_starts_at: string;
  periods_per_day: number;
  staff: { late_after: string; geofence: { enabled: boolean } };
  today: string;
  timezone: string;
}

export interface DayRules {
  can_save: boolean;
  needs_reason: boolean;
  reason_kind: 'late_entry' | 'late_edit' | null;
  office_only: boolean;
  window_closes_at: string;
  late_entry_until: string;
  refusal: string | null;
  refusal_message: string | null;
}

export interface LateEntry {
  reason: string;
  entered_by: string | null;
  entered_at: string | null;
  has_photo: boolean;
  by_office: boolean;
}

export interface RegisterDay {
  section: { id: string; label: string };
  date: string;
  period: number;
  mode: 'daily' | 'period';
  holiday: { title: string } | null;
  marked: boolean;
  marked_by: string | null;
  marked_at: string | null;
  late_entry: LateEntry | null;
  source: string | null;
  day_id: string | null;
  rules: DayRules;
  students: { id: string; name: string; roll_no: number | null; admission_no: string; status: AttendanceCode }[];
}

export interface ImportRow {
  row: number;
  status: 'ok' | 'error';
  label: string;
  messages: string[];
}

export interface RegisterImport {
  rows: ImportRow[];
  ok: number;
  errors: number;
  absent: number;
  rules: DayRules;
  committed: boolean;
}

export interface DailySummary {
  date: string;
  sections: { section_id: string; section: string; marked: boolean; late_entry: boolean; counts: Record<AttendanceCode, number>; percent: number | null }[];
  totals: { students: number; present: number; absent: number; marked_sections: number; sections: number; percent: number | null };
  staff: { total: number; present: number; late: number; not_in: number } | null;
}

export interface MonthlyRegister {
  section: { id: string; label: string };
  month: string;
  dates: string[];
  late_entries: string[];
  students: { id: string; name: string; roll_no: number | null; admission_no: string; marks: Record<string, AttendanceCode | null>; percent: number | null }[];
}

export interface StudentAttendance {
  student: { id: string; name: string };
  month: string;
  days: { date: string; status: AttendanceCode | null; late_entry: boolean }[];
  percent: number | null;
  threshold: number;
}

export interface BelowThreshold {
  threshold: number;
  students: { id: string; name: string; section: string; section_id: string; percent: number; days: number }[];
}

export interface StaffRecord {
  id: string;
  staff_id: string;
  date: string;
  status: 'P' | 'A' | 'L' | 'HD' | 'LV';
  in: string | null;
  out: string | null;
  source: string | null;
  inside_area: boolean | null;
  late: boolean;
  entered_by: string | null;
  reason: string | null;
}

export interface MyStaffAttendance {
  is_staff: boolean;
  today: StaffRecord | null;
  late_after: string;
  geofence: boolean;
  days: StaffRecord[];
}

export interface StaffDay {
  date: string;
  staff: { staff_id: string; name: string; designation: string | null; roles: string[]; can_enter: boolean; record: StaffRecord | null }[];
}

export interface StaffImport {
  rows: ImportRow[];
  ok: number;
  errors: number;
  written: number;
}

export interface CalendarEntry {
  id: string;
  date: string;
  end_date: string;
  type: 'holiday' | 'event';
  title: string;
  status: 'pending' | 'approved' | 'rejected';
  created_by: { id: string; name: string } | string | null;
  decided_by: { id: string; name: string } | string | null;
  decision_note: string | null;
}

export interface CalendarList {
  data: CalendarEntry[];
  can: { manage: boolean; approve: boolean };
}

export const attendanceKeys = {
  settings: ['attendance', 'settings'] as const,
  day: (section: string, date: string, period: number) => ['attendance', 'day', section, date, period] as const,
  summary: (date: string) => ['attendance', 'summary', date] as const,
  register: (section: string, month: string) => ['attendance', 'register', section, month] as const,
  student: (id: string, month: string) => ['attendance', 'student', id, month] as const,
  below: (section?: string) => ['attendance', 'below', section ?? 'all'] as const,
  mine: (month: string) => ['attendance', 'staff', 'me', month] as const,
  staffDay: (date: string) => ['attendance', 'staff', 'day', date] as const,
  qr: ['attendance', 'staff', 'qr'] as const,
  calendar: (from: string, to: string) => ['calendar', from, to] as const,
};

export function useAttendanceSettings(enabled = true) {
  return useQuery({ queryKey: attendanceKeys.settings, queryFn: apiGet<AttendanceSettings>('/attendance/settings'), enabled, staleTime: 5 * 60_000, retry: false });
}

export function useRegisterDay(section: string, date: string | undefined, period: number) {
  return useQuery({
    queryKey: attendanceKeys.day(section, date ?? '', period),
    queryFn: apiGet<RegisterDay>(`/attendance/sections/${section}/days/${date}`, period > 0 ? { period } : undefined),
    enabled: Boolean(date),
    retry: false,
  });
}

export function useDailySummary(date: string | undefined, enabled = true) {
  return useQuery({ queryKey: attendanceKeys.summary(date ?? ''), queryFn: apiGet<DailySummary>('/attendance/summary', { date }), enabled: enabled && Boolean(date), retry: false, refetchOnWindowFocus: true });
}

export function useMonthlyRegister(section: string, month: string | undefined) {
  return useQuery({
    queryKey: attendanceKeys.register(section, month ?? ''),
    queryFn: apiGet<MonthlyRegister>('/attendance/register', { section_id: section, month }),
    enabled: Boolean(section && month),
    retry: false,
  });
}

export function useStudentAttendance(id: string, month: string | undefined) {
  return useQuery({ queryKey: attendanceKeys.student(id, month ?? ''), queryFn: apiGet<StudentAttendance>(`/attendance/students/${id}`, { month }), enabled: Boolean(month), retry: false });
}

export function useBelowThreshold(section?: string, enabled = true) {
  return useQuery({ queryKey: attendanceKeys.below(section), queryFn: apiGet<BelowThreshold>('/attendance/below-threshold', section ? { section_id: section } : undefined), enabled, retry: false });
}

export function useMyStaffAttendance(month: string | undefined) {
  return useQuery({ queryKey: attendanceKeys.mine(month ?? ''), queryFn: apiGet<MyStaffAttendance>('/attendance/staff/me', { month }), enabled: Boolean(month), retry: false });
}

export function useStaffDay(date: string | undefined) {
  return useQuery({ queryKey: attendanceKeys.staffDay(date ?? ''), queryFn: apiGet<StaffDay>('/attendance/staff', { date }), enabled: Boolean(date), retry: false, refetchOnWindowFocus: true });
}

export const register = {
  save: (section: string, date: string, body: { period?: number; statuses: Record<string, MarkableCode>; reason?: string }) =>
    send<RegisterDay>('PUT', '/attendance/sections/{id}/days/{date}', { path: { id: section, date }, body }),
  import: (section: string, date: string, input: { file: File; reason: string; period: number; commit: boolean }) => {
    const form = new FormData();
    form.append('file', input.file);
    if (input.reason) form.append('reason', input.reason);
    if (input.period > 0) form.append('period', String(input.period));
    form.append('commit', input.commit ? '1' : '0');
    return send<RegisterImport>('POST', '/attendance/sections/{id}/days/{date}/import', { path: { id: section, date }, body: form });
  },
  uploadPhoto: (dayId: string, photo: File) => {
    const form = new FormData();
    form.append('photo', photo);
    return send<{ has_photo: boolean }>('POST', '/attendance/days/{id}/photo', { path: { id: dayId }, body: form });
  },
  /** A short-lived signed link: fetched fresh on every click, never kept. */
  photoLink: (dayId: string) => send<{ url: string }>('POST', '/attendance/days/{id}/photo/link', { path: { id: dayId } }),
};

export const staffAttendance = {
  checkIn: (body: { method: 'app' | 'qr'; lat?: number; lng?: number; qr?: string }) => send<StaffRecord>('POST', '/attendance/staff/check-in', { body }),
  checkOut: (body: { method: 'app' | 'qr'; lat?: number; lng?: number; qr?: string }) => send<StaffRecord>('POST', '/attendance/staff/check-out', { body }),
  manual: (staffId: string, body: { date: string; status: StaffRecord['status']; in?: string; out?: string; reason: string }) =>
    send<StaffRecord>('POST', '/attendance/staff/{staffId}/manual', { path: { staffId }, body }),
  import: (file: File, commit: boolean) => {
    const form = new FormData();
    form.append('file', file);
    form.append('commit', commit ? '1' : '0');
    return send<StaffImport>('POST', '/attendance/staff/import', { body: form });
  },
};

export const calendar = {
  create: (body: { date: string; end_date?: string; type: CalendarEntry['type']; title: string }) => send<CalendarEntry>('POST', '/calendar', { body }),
  update: (id: string, body: Partial<{ date: string; end_date: string; type: CalendarEntry['type']; title: string }>) => send<CalendarEntry>('PATCH', '/calendar/{id}', { path: { id }, body }),
  remove: (id: string) => send('DELETE', '/calendar/{id}', { path: { id } }),
  approve: (id: string, note?: string) => send<CalendarEntry>('POST', '/calendar/{id}/approve', { path: { id }, body: note ? { note } : {} }),
  reject: (id: string, note?: string) => send<CalendarEntry>('POST', '/calendar/{id}/reject', { path: { id }, body: note ? { note } : {} }),
};

/** "2026-12" → first and last day. */
export function monthRange(month: string): { from: string; to: string } {
  const [y, m] = month.split('-').map(Number) as [number, number];
  const last = new Date(Date.UTC(y, m, 0)).getUTCDate();
  return { from: `${month}-01`, to: `${month}-${String(last).padStart(2, '0')}` };
}

/** The name of whoever did something; the API sends either a name or {id, name}. */
export function personName(value: CalendarEntry['created_by']): string | null {
  if (!value) return null;
  return typeof value === 'string' ? value : value.name;
}

/** Whether a failure was the network (no answer), not the server saying no. */
export function isNetworkFailure(error: unknown): boolean {
  return error instanceof TypeError || (error instanceof Error && error.name === 'TypeError');
}
