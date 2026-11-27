import type { paths } from '@campus-tab/api';
import { useQuery } from '@tanstack/react-query';
import { api, apiGet, unwrap } from '@/lib/api';
import type { DeviceSession, SignInResult } from '@/features/auth/api';

type Json<P extends keyof paths, M extends keyof paths[P]> = paths[P][M] extends { requestBody?: { content: { 'application/json': infer B } } } ? B : never;
export type IssueTerms = Json<'/platform/schools/{code}/licences', 'post'>;
export type AmendTerms = Json<'/platform/schools/{code}/licence', 'patch'>;

export interface ConsoleMe {
  id: string;
  name: string;
  email: string;
  mfa: { enabled: boolean; required: boolean };
  session: { idle_minutes: number; ends_at: string | null; mfa_enrolment_only: boolean };
}

export interface LicenceSummary {
  id: string;
  key_last4: string;
  plan: string;
  modules: string[];
  seats: { students: number; staff: number };
  valid_from: string;
  valid_to: string;
  status: 'issued' | 'active' | 'revoked';
  state: string;
  activated_at: string | null;
  revoked_at: string | null;
}

export interface ConsoleSchool {
  id: string;
  code: string;
  name: string;
  status: 'pending' | 'active' | 'suspended';
  contact: { email: string | null; phone: string | null };
  licence: LicenceSummary | null;
  usage: {
    students: number | null;
    student_seats: number | null;
    staff: number | null;
    staff_seats: number | null;
    active_7d: number | null;
    active_30d: number | null;
    last_activity_at: string | null;
    storage_bytes: number | null;
    computed_at: string | null;
  };
  client: { package_name: string | null; paid_until: string | null; billing_period: string | null } | null;
  open_alerts: number;
  created_at: string | null;
}

export interface ClientRecord {
  contact_person: string | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  package_name: string | null;
  price: number | null;
  billing_period: 'monthly' | 'quarterly' | 'half_yearly' | 'yearly' | null;
  paid_until: string | null;
  notes: string | null;
}

export interface Payment {
  id: string;
  amount: number;
  paid_on: string;
  method: string;
  reference: string | null;
  period_from: string | null;
  period_to: string | null;
  note: string | null;
  recorded_by: string | null;
  at: string;
}

export interface ConsoleAlert {
  id: string;
  school_code: string;
  metric: string;
  count: number;
  baseline: number;
  message: string;
  raised_at: string;
  acknowledged_at: string | null;
  acknowledged_by: string | null;
}

/** Counts per window (last_hour, 24h, 7d, 30d) per metric. No names. */
export type SecurityWindows = Record<string, Record<string, number>>;

export interface ConsoleSchoolDetails extends ConsoleSchool {
  client_record: ClientRecord;
  payments: Payment[];
  licence_history: {
    action: string;
    at: string;
    by: string | null;
    changed: string[] | null;
    reason: string | null;
    before: Record<string, unknown> | null;
    after: { valid_to?: string } & Record<string, unknown> | null;
  }[];
  support_grants: { id: string; agent_name: string; agent_email: string; scope: string[]; read_only: boolean; starts_at: string; expires_at: string; status: string }[];
  security: SecurityWindows | null;
  alerts: ConsoleAlert[];
}

export interface ConsoleAuditEntry {
  id: string;
  at: string;
  actor: { type: string; id: string | null; name: string | null };
  action: string;
  school_code: string | null;
  entity: string | null;
  meta: Record<string, unknown>;
  ip: string | null;
}

export const consoleMeKey = ['console', 'me'] as const;

export function useConsoleMe() {
  return useQuery({
    queryKey: consoleMeKey,
    retry: false,
    // A 401 is an error, so the last admin stays on screen under the sign-in overlay.
    queryFn: apiGet<ConsoleMe>('/platform/me'),
  });
}

export const consoleAuth = {
  login: (body: { email: string; password: string }) =>
    unwrap<SignInResult | { admin: { id: string; name: string }; mfa_enrolment_required: boolean }>(api.POST('/platform/auth/login', { body })),
  verify: (body: { challenge: string; code: string }) =>
    unwrap<{ admin: { id: string; name: string }; mfa_enrolment_required: boolean }>(api.POST('/platform/auth/mfa/verify', { body })),
  setPassword: (body: { token: string; password: string; password_confirmation: string }) =>
    unwrap<{ message: string }>(api.POST('/platform/auth/password/set', { body })),
  logout: () => unwrap(api.POST('/platform/auth/logout')),
  stepUp: (code: string) => unwrap<{ stepped_up_until: string }>(api.POST('/platform/auth/step-up', { body: { code } })),
  enrolStart: () => unwrap<{ secret: string; otpauth_url: string }>(api.POST('/platform/me/mfa/enrol')),
  enrolConfirm: (code: string) => unwrap<{ recovery_codes: string[] }>(api.POST('/platform/me/mfa/enrol/confirm', { body: { code } })),
  sessions: apiGet<DeviceSession[]>('/platform/me/sessions'),
  revokeSession: (id: string) => unwrap(api.DELETE('/platform/me/sessions/{id}', { params: { path: { id } } })),
};

export const consoleSchools = {
  options: apiGet<{ plans: string[]; modules: string[]; grace_days: number }>('/platform/options'),
  list: apiGet<ConsoleSchool[]>('/platform/schools'),
  show: (code: string) => apiGet<ConsoleSchoolDetails>(`/platform/schools/${encodeURIComponent(code)}`),
  create: (body: { code: string; name: string; email?: string; phone?: string }) =>
    unwrap<ConsoleSchool>(api.POST('/platform/schools', { body })),
  issue: (code: string, body: IssueTerms) =>
    unwrap<{ key: string; licence: LicenceSummary; activation_needed: boolean }>(
      api.POST('/platform/schools/{code}/licences', { params: { path: { code } }, body }),
    ),
  amend: (code: string, body: AmendTerms) =>
    unwrap<LicenceSummary>(api.PATCH('/platform/schools/{code}/licence', { params: { path: { code } }, body })),
  revoke: (code: string, body: { confirm_code: string; reason: string }) =>
    unwrap<LicenceSummary>(api.POST('/platform/schools/{code}/licence/revoke', { params: { path: { code } }, body })),
  recoveryStart: (code: string) =>
    unwrap<{ chief: { id: string; name: string }; sent_to: { email: string | null; phone: string | null }; expires_in: number }>(
      api.POST('/platform/schools/{code}/chief-recovery', { params: { path: { code } } }),
    ),
  recoveryComplete: (code: string, body: { code: string; reason: string }) =>
    unwrap<{ mfa_reset: boolean; message: string }>(
      api.POST('/platform/schools/{code}/chief-recovery/complete', { params: { path: { code } }, body }),
    ),
  renew: (code: string, validTo: string) =>
    unwrap<LicenceSummary>(api.POST('/platform/schools/{code}/licence/renew', { params: { path: { code } }, body: { valid_to: validTo } })),
  updateClient: (code: string, body: ClientRecord) =>
    unwrap<ClientRecord>(api.PUT('/platform/schools/{code}/client', { params: { path: { code } }, body: body as never })),
  addPayment: (code: string, body: Omit<Payment, 'id' | 'recorded_by' | 'at'>) =>
    unwrap<Payment>(api.POST('/platform/schools/{code}/payments', { params: { path: { code } }, body: body as never })),
  refreshStats: (code: string) => unwrap<ConsoleSchoolDetails>(api.POST('/platform/schools/{code}/stats/refresh', { params: { path: { code } } })),
  alerts: (all: boolean) => apiGet<ConsoleAlert[]>('/platform/alerts', all ? { all: 1 } : undefined),
  acknowledge: (id: string) => unwrap<ConsoleAlert>(api.POST('/platform/alerts/{id}/acknowledge', { params: { path: { id } } })),
  audit: (school?: string) => apiGet<ConsoleAuditEntry[]>('/platform/audit-logs', school ? { school } : undefined),
};
