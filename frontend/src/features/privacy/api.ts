import { downloadFile, send } from '@/lib/requests';

export interface PrivacyNotice {
  id: string;
  version: number | null;
  status: 'draft' | 'published' | 'replaced';
  title: string;
  body: string;
  summary_of_changes: string | null;
  published_at: string | null;
  published_by: string | null;
}

export interface Grievance {
  name: string;
  role?: string | null;
  email: string;
  phone?: string | null;
  address?: string | null;
}

export interface ConsentAnswer {
  given: boolean;
  at: string;
  version: string;
}

export interface ParentConsents {
  notice: { version: number; title: string } | null;
  purposes: Record<string, string>;
  children: { id: string; name: string; consents: Record<string, ConsentAnswer | null> }[];
}

export type RequestType = 'access' | 'correction' | 'erasure';
export type RequestStatus = 'received' | 'in_progress' | 'completed' | 'rejected';

export interface DataRequest {
  id: string;
  type: RequestType;
  status: RequestStatus;
  subject_type: 'self' | 'student';
  student_id: string | null;
  subject_name: string | null;
  details: string;
  due_on: string;
  late: boolean;
  outcome: string | null;
  completed_at: string | null;
  created_at: string | null;
  requested_by: { id: string; name: string; role: string } | null;
  handled_by: string | null;
  history: { at: string; by: string; note: string }[];
}

export interface MyPrivacy {
  notice: PrivacyNotice | null;
  grievance: Grievance | null;
  consents: ParentConsents | null;
  requests: DataRequest[];
  can: { notices: boolean; requests: boolean; retention: boolean; incidents: boolean };
}

export interface RetentionRun {
  id: string;
  kind: string;
  label: string;
  status: 'dry_run' | 'confirmed' | 'expired';
  days: number;
  cutoff: string;
  count: number;
  oldest: string | null;
  newest: string | null;
  made_by: string | null;
  made_at: string | null;
  confirmed_by: string | null;
  confirmed_at: string | null;
  deleted: number | null;
}

export interface RetentionView {
  rules: { kind: string; label: string; days: number }[];
  runs: RetentionRun[];
  never: string[];
}

export interface Incident {
  id: string;
  title: string;
  detected_at: string;
  severity: 'low' | 'medium' | 'high';
  status: 'open' | 'contained' | 'closed';
  description: string;
  data_affected: string[];
  people_affected: number | null;
  board_notified_at: string | null;
  people_notified_at: string | null;
  board_deadline: string;
  board_overdue: boolean;
  reported_by: string | null;
  timeline: { at: string; by: string; note: string }[];
}

const key = () => ({ 'Idempotency-Key': crypto.randomUUID() });

export const privacy = {
  answer: (body: { student_id: string; purpose: string; given: boolean; notice_version: number }) =>
    send<ParentConsents>('POST', '/privacy/consents', { body, headers: key() }),
  ask: (body: { type: RequestType; student_id?: string | null; details: string }) => send<DataRequest>('POST', '/privacy/requests', { body, headers: key() }),
  saveDraft: (body: { title: string; body: string; summary_of_changes?: string | null }) => send<PrivacyNotice>('PUT', '/privacy/notices/draft', { body }),
  publish: () => send<PrivacyNotice>('POST', '/privacy/notices/publish', { headers: key() }),
  move: (id: string, body: { status: 'in_progress' | 'completed' | 'rejected'; outcome?: string }) =>
    send<DataRequest>('POST', '/privacy/requests/{id}/move', { path: { id }, body, headers: key() }),
  copy: (id: string, name: string) => downloadFile(`/privacy/requests/${id}/copy`, name),
  erase: (id: string, confirm: string) => send<DataRequest>('POST', '/privacy/requests/{id}/erase', { path: { id }, body: { confirm }, headers: key() }),
  dryRun: (kind: string) => send<RetentionRun>('POST', '/privacy/retention/{kind}/dry-run', { path: { kind }, headers: key() }),
  confirm: (id: string) => send<RetentionRun>('POST', '/privacy/retention/runs/{id}/confirm', { path: { id }, headers: key() }),
  report: (body: { title: string; detected_at: string; severity: Incident['severity']; description: string; data_affected?: string[]; people_affected?: number | null }) =>
    send<Incident>('POST', '/privacy/incidents', { body, headers: key() }),
  addEntry: (id: string, body: { note: string; status?: Incident['status']; board_notified?: boolean; people_notified?: boolean }) =>
    send<Incident>('POST', '/privacy/incidents/{id}/entries', { path: { id }, body, headers: key() }),
};

/** The query key of GET /privacy (both privacy pages share it). */
export const myPrivacyKey = ['privacy', 'mine'] as const;
