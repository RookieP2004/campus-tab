import { send } from '@/lib/requests';

/**
 * Leave: types, requests, balances, the calendar, cover and delegation.
 * The server decides what each person sees (a request's reason and
 * documents only for the applicant and its chain) and what they may do
 * (`can`); the screens show exactly that.
 */

export type LeaveStatus = 'draft' | 'submitted' | 'returned' | 'verified' | 'approved' | 'rejected' | 'withdrawn' | 'cancelled';
export type LeaveStage = 'clerk' | 'vice_principal' | 'principal' | 'chief' | 'class_teacher';

export interface LeaveType {
  id: string;
  code: string;
  name: string;
  quota: number | null;
  sandwich: boolean;
  half_day: boolean;
  document_after_days: number | null;
  status: 'active' | 'archived';
}

export interface Balance {
  type_code: string;
  type_name: string;
  quota: number | null;
  reserved: number;
  taken: number;
  available: number | null;
}

export interface TimelineStep {
  stage: string;
  action: string | null;
  by: string | null;
  remark: string | null;
  at: string | null;
  from: string | null;
  to: string | null;
  restored_days: number | null;
  state: 'done' | 'current' | 'upcoming';
}

export interface CoverRow {
  date: string;
  section_id: string;
  section: string;
  subject: string | null;
  kind: 'register' | 'lesson';
  teacher?: string;
}

export interface LeaveSummary {
  id: string;
  kind: 'staff' | 'student';
  applicant: { name: string; designation: string | null; section: string | null; student_id: string | null };
  applied_by: string | null;
  type_code: string | null;
  type_name: string | null;
  from: string;
  to: string;
  half_day: 'first' | 'second' | null;
  days: number;
  status: LeaveStatus;
  stage: LeaveStage | null;
  chain: LeaveStage[];
  fast_tracked: boolean;
  principal_sees: boolean;
  cancel_requested: boolean;
  has_documents: boolean;
  last_step: { action: string; by: string | null; at: string; stage: string } | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface LeaveActions {
  edit: boolean;
  submit: boolean;
  withdraw: boolean;
  attach: boolean;
  request_cancel: boolean;
  verify: boolean;
  return: boolean;
  approve: boolean;
  reject: boolean;
  fast_track: boolean;
  cancel: boolean;
  keep: boolean;
}

export interface LeaveDetail extends LeaveSummary {
  reason: string | null;
  documents: { id: string; name: string; mime: string; size: number }[];
  timeline: TimelineStep[];
  cover_needed: CoverRow[];
  approved: { from: string; to: string; days: number; restored_days?: number } | null;
  cancel_request: { by: string | null; at: string | null; reason: string | null } | null;
  balance: Balance | null;
  can: LeaveActions;
}

export interface LeavePreview {
  days: number;
  counted: string[];
  off: { date: string; why: string }[];
  sandwich: boolean;
  document_required: boolean;
  balance: Balance | null;
  available_after: number | null;
  chain: LeaveStage[];
}

export interface AwayEntry {
  name: string;
  from: string;
  to: string;
  half_day: string | null;
  own: boolean;
  request_id: string | null;
}

export interface Delegation {
  id: string;
  delegate: string;
  delegate_user_id: string;
  by: string;
  from: string;
  to: string;
  ended_at: string | null;
  active: boolean;
  state: 'active' | 'upcoming' | 'over' | 'ended';
}

export interface ApprovalItem {
  kind: 'leave' | 'marks' | 'fees' | 'staff';
  id: string;
  title: string;
  detail: string | null;
  subtitle?: Record<string, unknown>;
  status: string;
  at: string | null;
  link: string;
  flags: string[];
}

export interface ApprovalInbox {
  data: ApprovalItem[];
  counts: Record<string, number>;
  total: number;
}

/** A fresh key per action, so a double tap or a retry never acts twice. */
export function idempotencyKey(): string {
  const random = typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  return `leave-${random}`;
}

const once = () => ({ 'Idempotency-Key': idempotencyKey() });

export type LeaveMove = 'submit' | 'withdraw' | 'verify' | 'return' | 'approve' | 'reject' | 'cancel' | 'keep' | 'cancel-request';

export const leave = {
  apply: (body: { type_code: string; from: string; to: string; half_day?: string | null; reason: string; submit?: boolean }) =>
    send<LeaveDetail>('POST', '/leave-requests', { body, headers: once() }),
  applyForChild: (body: { student_id: string; from: string; to: string; reason: string }) => send<LeaveDetail>('POST', '/leave-requests', { body, headers: once() }),
  edit: (id: string, body: Partial<{ type_code: string; from: string; to: string; half_day: string | null; reason: string }>) =>
    send<LeaveDetail>('PATCH', '/leave-requests/{id}', { path: { id }, body }),
  move: (id: string, move: LeaveMove, body: Record<string, unknown> = {}) =>
    send<LeaveDetail>('POST', `/leave-requests/{id}/${move}`, { path: { id }, body, headers: once() }),
  attach: (id: string, file: File) => {
    const form = new FormData();
    form.append('file', file);
    return send<LeaveDetail>('POST', '/leave-requests/{id}/documents', { path: { id }, body: form, headers: once() });
  },
  documentLink: (id: string, doc: string) => send<{ url: string }>('POST', '/leave-requests/{id}/documents/{doc}/link', { path: { id, doc } }),
  createType: (body: Omit<LeaveType, 'id' | 'status'>) => send<LeaveType>('POST', '/leave-types', { body, headers: once() }),
  updateType: (id: string, body: Partial<LeaveType>) => send<LeaveType>('PATCH', '/leave-types/{id}', { path: { id }, body }),
  starterTypes: () => send<LeaveType[]>('POST', '/leave-types/starter', { headers: once() }),
  delegate: (body: { delegate_user_id: string; from: string; to: string }) => send<Delegation>('POST', '/leave/delegations', { body, headers: once() }),
  endDelegation: (id: string) => send<Delegation>('POST', '/leave/delegations/{id}/end', { path: { id } }),
};

/** Dates arrive as Y-m-d; show them as dates in India time. */
export const asDate = (ymd: string) => `${ymd}T12:00:00+05:30`;

/** Today in India time, Y-m-d. */
export function todayInIndia(now: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'Asia/Kolkata' }).format(now);
}

export function addDays(ymd: string, days: number): string {
  const d = new Date(`${ymd}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

export const leaveStatusChip: Record<LeaveStatus, 'draft' | 'pending' | 'verified' | 'approved' | 'returned' | 'rejected'> = {
  draft: 'draft',
  submitted: 'pending',
  verified: 'verified',
  returned: 'returned',
  approved: 'approved',
  rejected: 'rejected',
  withdrawn: 'draft',
  cancelled: 'rejected',
};
