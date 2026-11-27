import { send } from '@/lib/requests';

/**
 * Tests and marks. The server decides what each person may do with a paper
 * (`can`) and refuses anything else; the screens show exactly that. Marks
 * entry is online only: a save that fails stays on screen until it goes
 * through, and nothing is queued on the device.
 */

export type PaperStatus = 'draft' | 'open' | 'submitted' | 'verified' | 'locked';

export interface PaperActions {
  enter: boolean;
  open: boolean;
  submit: boolean;
  verify: boolean;
  send_back: boolean;
  lock: boolean;
  publish: boolean;
  correct: boolean;
}

export interface AssessmentInfo {
  id: string;
  kind: 'exam' | 'class_test';
  type: string;
  term: string | null;
  name: string;
  component: string | null;
  from: string;
  to: string;
  entry_closes_at: string | null;
  created_by: string | null;
}

export interface AssessmentListItem extends AssessmentInfo {
  papers: number;
  by_status: Partial<Record<PaperStatus, number>>;
  published: number;
}

export interface PaperSummary {
  id: string;
  assessment_id: string;
  section_id: string;
  section: string;
  subject_id: string;
  subject: string;
  max_marks: number;
  date: string | null;
  status: PaperStatus;
  published: boolean;
  class_test: boolean;
  entered: number;
  students: number;
  can: PaperActions;
}

export interface AssessmentDetail extends AssessmentInfo {
  papers: PaperSummary[];
}

export interface MarkRow {
  student_id: string;
  name: string;
  roll_no: number | null;
  admission_no: string;
  value: number | null;
  code: 'AB' | 'ML' | null;
  remark: string | null;
}

export interface PaperDetail extends PaperSummary {
  version: number;
  assessment: AssessmentInfo | null;
  rows: MarkRow[];
  returned_note: string | null;
  locked: { by: string | null; at: string | null } | null;
  published_at: string | null;
  pending_corrections: string[];
  history: { action: string; by: string | null; note: string | null; at: string }[];
}

export interface Completion {
  assessment: AssessmentInfo;
  subjects: string[];
  sections: { section_id: string; section: string; cells: Record<string, { paper_id: string; status: PaperStatus; published: boolean; entered: number; students: number }> }[];
}

export interface Mark {
  value: number | null;
  code: string | null;
}

export interface Correction {
  id: string;
  paper_id: string;
  assessment_id: string;
  student_id: string;
  student: string;
  label: string;
  old: Mark;
  new: Mark;
  reason: string;
  requested_by: string | null;
  requested_by_role: string | null;
  requested_at: string | null;
  status: 'pending' | 'approved' | 'rejected';
  decided_by: string | null;
  decision_reason: string | null;
  decided_at: string | null;
  self_approved: boolean;
  own: boolean;
  can: { decide: boolean };
}

export interface Scheme {
  id: string;
  name: string;
  grades: string[];
  terms: string[];
  components: { key: string; name: string; max: number; weight: number }[];
  grade_scale: { grade: string; min: number }[];
  rules: { pass_percent: number; rounding: string; best_of: number | null; grace_marks: number };
  status: 'draft' | 'active';
  preset: string | null;
  preset_label: string | null;
  preset_note: string | null;
}

export interface Preset {
  key: string;
  label: string;
  note: string;
}

export interface StudentResults {
  student: { id: string; name: string };
  results: { paper_id: string; assessment: string | null; type: string | null; term: string | null; subject: string; max_marks: number; value: number | null; code: string | null; date: string | null; published_at: string | null }[];
}

/** What a cell holds: a number, AB or ML, or empty. */
export function parseCell(raw: string, max: number): { ok: true; mark: Mark } | { ok: false; reason: 'invalid' | 'above' } {
  const text = raw.trim().toUpperCase();
  if (text === '') return { ok: true, mark: { value: null, code: null } };
  if (text === 'AB' || text === 'ML') return { ok: true, mark: { value: null, code: text } };
  const n = Number(text);
  if (!Number.isFinite(n) || n < 0 || Math.abs(n * 2 - Math.round(n * 2)) > 1e-9) return { ok: false, reason: 'invalid' };
  if (n > max) return { ok: false, reason: 'above' };
  return { ok: true, mark: { value: n, code: null } };
}

export function cellText(mark: Mark): string {
  if (mark.code) return mark.code;
  return mark.value === null ? '' : String(mark.value);
}

const once = () => ({ 'Idempotency-Key': `marks-${typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : String(Date.now() + Math.random())}` });

export type PaperMove = 'open' | 'submit' | 'verify' | 'send-back' | 'lock' | 'publish';

export const marks = {
  createAssessment: (body: Record<string, unknown>) => send<AssessmentDetail>('POST', '/assessments', { body, headers: once() }),
  assessmentMove: (id: string, move: 'open' | 'lock' | 'publish') => send<AssessmentDetail>('POST', `/assessments/{id}/${move}`, { path: { id }, headers: once() }),
  save: (id: string, version: number, changes: Record<string, { value: number | null; code: string | null; remark?: string | null }>) =>
    send<PaperDetail>('PUT', '/papers/{id}/marks', { path: { id }, body: { marks: changes }, headers: { 'If-Match': `"v${version}"` } }),
  move: (id: string, move: PaperMove, body: Record<string, unknown> = {}) => send<PaperDetail>('POST', `/papers/{id}/${move}`, { path: { id }, body, headers: once() }),
  raiseCorrection: (body: { paper_id: string; student_id: string; value: number | null; code: string | null; reason: string }) =>
    send<Correction>('POST', '/corrections', { body, headers: once() }),
  approve: (id: string, reason: string) => send<Correction>('POST', '/corrections/{id}/approve', { path: { id }, body: { reason }, headers: once() }),
  reject: (id: string, reason: string) => send<Correction>('POST', '/corrections/{id}/reject', { path: { id }, body: { reason }, headers: once() }),
  createScheme: (body: { name: string; grades: string[]; preset?: string | null }) => send<Scheme>('POST', '/assessment-schemes', { body, headers: once() }),
  updateScheme: (id: string, body: Partial<Scheme>) => send<Scheme>('PUT', '/assessment-schemes/{id}', { path: { id }, body }),
};

/** The chip each paper state shows (published wins once it is out). */
export function paperChip(p: { status: PaperStatus; published: boolean }): 'draft' | 'pending' | 'verified' | 'locked' | 'published' | 'approved' {
  if (p.published) return 'published';
  return ({ draft: 'draft', open: 'pending', submitted: 'approved', verified: 'verified', locked: 'locked' } as const)[p.status];
}
