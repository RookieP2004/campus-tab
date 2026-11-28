import { useQuery } from '@tanstack/react-query';
import { apiGet } from '@/lib/api';
import { apiGetBody, send } from '@/lib/requests';

export type FieldType = 'text' | 'long_text' | 'number' | 'date' | 'dropdown' | 'multi_select' | 'yes_no' | 'file' | 'grade' | 'phone' | 'email';

/** A label in several languages; English is always there. */
export type Localised = { en: string } & Record<string, string | null | undefined>;

export interface FieldScope {
  level: 'school' | 'wing' | 'section' | 'subject_section';
  ref: string | null;
}

/** A custom column as GET /students returns it. */
export interface CustomColumn {
  key: string;
  label: Localised;
  type: FieldType;
  options: string[] | null;
  entity: 'student' | 'enrolment' | 'staff';
  scope: FieldScope;
  owner_level: number;
  sensitive: boolean;
  can_fill: boolean;
}

export interface StudentRow {
  id: string;
  admission_no: string;
  name: string;
  dob: string | null;
  gender: 'female' | 'male' | 'other' | null;
  status: string;
  section_id: string | null;
  section_label: string | null;
  roll_no: number | null;
  custom: Record<string, unknown>;
}

export interface StudentList {
  data: StudentRow[];
  columns: CustomColumn[];
  meta: { total: number; page: number; per_page: number };
}

export interface Guardian {
  id: string;
  name: string;
  relation: string | null;
  phone: string | null;
  email: string | null;
  student_ids: string[];
  parent_login: boolean;
}

export interface Consent {
  /** True only when the parent said yes from their own account (Phase 9). */
  given: boolean;
  at: string | null;
  recorded_by: string | null;
  by_parent?: boolean;
  /** An office tick from before Phase 9, waiting for the parent's own answer. */
  needs_parent?: boolean;
}

export interface StudentProfile extends StudentRow {
  ids: { pen: string | null; apaar: string | null; aadhaar_last4: string | null } | null;
  login_name: string | null;
  guardians: Guardian[];
  consents: Record<string, Consent | null>;
  columns: CustomColumn[];
  /** Decrypted sensitive values, sent only to people who may fill them; shown only after "Show". */
  sensitive_values?: Record<string, unknown>;
  can: { edit: boolean; manage_guardians: boolean; delete: boolean };
}

export interface Seats {
  limit: number;
  used: number;
  available: number;
  warning: boolean;
}

export interface GuardianInput {
  name: string;
  relation?: string | null;
  phone?: string | null;
  email?: string | null;
}

export interface AdmitInput {
  admission_no: string;
  name: string;
  dob: string;
  gender: 'female' | 'male' | 'other';
  section_id: string;
  roll_no?: number | null;
  guardians: GuardianInput[];
  ids?: { pen?: string | null; apaar?: string | null; aadhaar_last4?: string | null };
  consents?: Record<string, boolean>;
  confirm_not_duplicate?: boolean;
}

export interface StudentPatch {
  name?: string;
  dob?: string;
  gender?: 'female' | 'male' | 'other';
  roll_no?: number | null;
  ids?: { pen?: string | null; apaar?: string | null; aadhaar_last4?: string | null };
  consents?: Record<string, boolean>;
  custom?: Record<string, unknown>;
}

export interface SetupCodeSheet {
  section: { id: string; label: string };
  valid_hours: number;
  students: { student_id: string; name: string; roll_no: number | null; login_name: string; code: string | null; expires_at: string }[];
}

export type StaffStatus = 'draft' | 'active' | 'inactive' | 'rejected';

export interface StaffRow {
  id: string;
  employee_code: string | null;
  name: string;
  designation: string | null;
  type: 'teaching' | 'non_teaching' | null;
  email: string | null;
  phone: string | null;
  status: StaffStatus;
  joined_on: string | null;
  wings: string[] | null;
  roles: string[];
  account: { status: string; mfa: boolean } | null;
  requested_roles: string[] | null;
  drafted_by: { id: string; name: string } | null;
}

export interface StaffProfile extends StaffRow {
  class_teacher_of: string[];
  teaches: { id: string; section_label: string | null; subject_name: string | null }[];
  custom: Record<string, unknown> | null;
}

export interface StaffList {
  data: StaffRow[];
  can: { create: boolean; draft: boolean; approve: boolean };
}

/** How a new account's password is set. "code": shown once, here. */
export interface PasswordSetup {
  channel: 'code' | 'email' | 'sms' | string;
  to: string | null;
  code?: string;
  expires_at?: string;
}

export interface StaffInput {
  name: string;
  email?: string;
  phone?: string;
  designation?: string;
  roles: string[];
}

export type ImportEntity = 'students' | 'staff';
export type ImportStatus = 'queued' | 'validating' | 'validated' | 'committing' | 'committed' | 'failed';

export interface ImportSeats {
  limit: number;
  used: number;
  available: number;
  adding: number;
  over: number;
}

export interface ImportRow {
  row: number;
  status: 'ok' | 'error' | 'duplicate';
  label: string;
  messages: string[];
}

export interface ImportBatch {
  id: string;
  entity: ImportEntity;
  status: ImportStatus;
  file_name: string;
  progress: { done: number; total: number } | null;
  summary: {
    rows: number;
    ok: number;
    errors: number;
    duplicates: number;
    seats: ImportSeats | null;
    can_commit: boolean;
    messages: string[];
  } | null;
  rows: ImportRow[] | null;
  result: { created: number } | null;
  error: string | null;
}

export const peopleKeys = {
  students: ['students'] as const,
  studentList: (query: Record<string, unknown>) => ['students', 'list', query] as const,
  student: (id: string) => ['students', 'one', id] as const,
  seats: ['students', 'seats'] as const,
  staff: ['staff'] as const,
  staffList: (status: string) => ['staff', 'list', status] as const,
  staffOne: (id: string) => ['staff', 'one', id] as const,
  importBatch: (id: string) => ['imports', id] as const,
};

export function useSeats() {
  return useQuery({ queryKey: peopleKeys.seats, queryFn: apiGet<Seats>('/students/seats') });
}

export function useStaffList(status = '', enabled = true) {
  return useQuery({
    queryKey: peopleKeys.staffList(status),
    queryFn: apiGetBody<StaffList>('/staff', status ? { status } : undefined),
    enabled,
  });
}

export const students = {
  admit: (body: AdmitInput) => send<StudentProfile>('POST', '/students', { body }),
  update: (id: string, body: StudentPatch) => send<StudentProfile>('PATCH', '/students/{id}', { path: { id }, body }),
  addGuardian: (id: string, body: GuardianInput) => send<StudentProfile>('POST', '/students/{id}/guardians', { path: { id }, body }),
  updateGuardian: (id: string, body: GuardianInput) => send<Guardian>('PATCH', '/guardians/{id}', { path: { id }, body }),
  enableParentLogin: (id: string) => send<Guardian>('POST', '/guardians/{id}/parent-login', { path: { id } }),
  disableParentLogin: (id: string) => send<Guardian>('DELETE', '/guardians/{id}/parent-login', { path: { id } }),
  setupCodes: (sectionId: string) => send<SetupCodeSheet>('POST', '/sections/{id}/student-setup-codes', { path: { id: sectionId } }),
};

export const staff = {
  create: (body: StaffInput) => send<StaffProfile & { password_setup: PasswordSetup | null }>('POST', '/staff', { body }),
  approve: (id: string) => send<StaffProfile & { password_setup: PasswordSetup | null }>('POST', '/staff/{id}/approve', { path: { id } }),
  reject: (id: string, reason: string) => send('POST', '/staff/{id}/reject', { path: { id }, body: { reason } }),
  deactivate: (id: string) => send<StaffProfile>('POST', '/staff/{id}/deactivate', { path: { id } }),
};

export const imports = {
  upload: (entity: ImportEntity, file: File) => {
    const form = new FormData();
    form.append('file', file);
    return send<ImportBatch>('POST', '/imports/{entity}', { path: { entity }, body: form });
  },
  commit: (id: string) => send<ImportBatch>('POST', '/imports/{id}/commit', { path: { id } }),
};

/** How a stored custom value reads in a table cell. */
export function formatCustomValue(value: unknown, yes: string, no: string): string {
  if (value === null || value === undefined || value === '') return '—';
  if (Array.isArray(value)) return value.join(', ');
  if (typeof value === 'boolean') return value ? yes : no;
  return String(value);
}

/** A label in the reader's language, else English. */
export function localLabel(label: Localised | null | undefined, language: string): string {
  if (!label) return '';
  const own = label[language.split('-')[0] ?? 'en'];
  return typeof own === 'string' && own.trim() !== '' ? own : label.en;
}
