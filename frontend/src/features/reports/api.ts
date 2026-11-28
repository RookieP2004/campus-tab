import { env } from '@/lib/env';
import { send } from '@/lib/requests';

/**
 * Report cards and year-end. The server decides what each person may do
 * with a card (`can`) and what they see of it (families: the overall result
 * of published versions only; subject teachers: their own subjects); the
 * screens show exactly that.
 */

export type CardStatus = 'draft' | 'reviewed' | 'published' | 'replaced';

export interface ComponentRow {
  key: string;
  name: string;
  weight: string;
  obtained: string | null;
  max: string | null;
  papers: number;
}

export interface SubjectResult {
  subject_id: string;
  subject: string;
  terms: { term: string; components: ComponentRow[]; has_marks: boolean }[];
  percent: string | null;
  grade: string | null;
  passed: boolean;
  grace: string | null;
  incomplete: boolean;
}

export interface Overall {
  percent: string | null;
  grade: string | null;
  result: 'pass' | 'fail' | 'incomplete';
}

export interface HistoryEntry {
  action: string;
  by: { id: string; name: string } | null;
  note: string | null;
  at: string;
}

export interface ReportCard {
  id: string;
  student: { id: string; name: string };
  section: { id: string; label: string };
  year: { id: string; name: string };
  version: number;
  replaced_by_id?: string | null;
  /** The areas and grades a class teacher picks from; only for whoever may set them. */
  co_scholastic_options?: { areas: Record<string, string>; grades: string[] } | null;
  status: CardStatus;
  current: boolean;
  issued_at: string | null;
  snapshot: { subjects?: SubjectResult[]; overall?: Overall | null; ignored?: number; computed_at?: string };
  remarks: { class_teacher?: string | null; subjects?: Record<string, string> };
  co_scholastic: Record<string, string>;
  attendance: { present_halves: number; working: number } | null;
  reissue_reason: string | null;
  has_pdf: boolean;
  history: HistoryEntry[];
  can: { remark_class: boolean; remark_subjects: string[]; review: boolean; reissue: boolean; download: boolean };
}

export interface CardRun {
  id: string;
  kind: 'generate' | 'publish' | 'reissue';
  status: 'queued' | 'running' | 'done' | 'failed';
  total: number | null;
  done: number | null;
  error: string | null;
  at: string | null;
}

export interface SectionSummary {
  id: string;
  label: string;
  counts: Partial<Record<CardStatus, number>>;
}

export interface SectionCards {
  section: { id: string; label: string };
  papers: { locked: number; not_locked: number };
  cards: ReportCard[];
  run: CardRun | null;
  can: { generate: boolean; publish: boolean };
}

export interface StudentCards {
  student: { id: string; name: string };
  cards: ReportCard[];
}

export type BlockType = 'header' | 'student' | 'scholastic' | 'co_scholastic' | 'attendance' | 'descriptive' | 'remarks' | 'result' | 'signatures';

export interface Template {
  id: string;
  name: string;
  grades: string[];
  blocks: { type: BlockType; options: Record<string, unknown> }[];
  preset: string | null;
  has_letterhead: boolean;
  updated_at: string | null;
}

export interface TemplatePresets {
  data: { key: string; label: string; blocks: BlockType[]; note: string }[];
  blocks: BlockType[];
  co_scholastic: { areas: Record<string, string>; grades: string[] };
}

export type PromotionAction = 'promote' | 'detain' | 'leave' | 'graduate';

export interface PromotionTarget {
  to_year_id?: string | null;
  name?: string | null;
  starts_on?: string | null;
  ends_on?: string | null;
}

export interface PromotionPreview {
  from: { id: string; name: string };
  to: { id: string | null; name: string; starts_on: string; ends_on: string };
  counts: Record<PromotionAction, number>;
  roll_forward: { classes: number; sections: number; assignments: number; new_classes?: string[]; new_sections: string[] };
  sections: {
    section_id: string;
    section: string;
    next_class: string | null;
    students: { id: string; name: string; roll_no: number | null; action: PromotionAction; suggested: PromotionAction; result: string | null; to_section: string | null }[];
  }[];
  dues: PromotionDues;
  digest: string;
}

export type DuesChoice = 'carry' | 'leave';

export interface PromotionDues {
  students: { id: string; name: string; section: string | null; balance_paise: number; status: 'open' | 'left' }[];
  total_paise: number;
  unresolved: number;
  digest: string;
}

export interface Verification {
  school: string;
  student: string;
  class: string;
  year: string;
  version: number;
  issued_on: string | null;
  status: 'current' | 'replaced';
}

const key = () => ({ 'Idempotency-Key': crypto.randomUUID() });

export const reports = {
  generate: (sectionId: string) => send<CardRun>('POST', '/report-cards/sections/{id}/generate', { path: { id: sectionId }, headers: key() }),
  publish: (sectionId: string) => send<CardRun>('POST', '/report-cards/sections/{id}/publish', { path: { id: sectionId }, headers: key() }),
  review: (id: string) => send<ReportCard>('POST', '/report-cards/{id}/review', { path: { id }, headers: key() }),
  remarks: (id: string, body: { class_teacher?: string | null; co_scholastic?: Record<string, string | null>; subjects?: Record<string, string | null> }) =>
    send<ReportCard>('PUT', '/report-cards/{id}/remarks', { path: { id }, body }),
  reissue: (id: string, reason: string) => send<CardRun>('POST', '/report-cards/{id}/reissue', { path: { id }, body: { reason }, headers: key() }),
  link: (id: string) => send<{ url: string }>('POST', '/report-cards/{id}/link', { path: { id } }),
  createTemplate: (body: { name: string; grades: string[]; preset?: string | null }) => send<Template>('POST', '/report-templates', { body, headers: key() }),
  updateTemplate: (id: string, body: Partial<Pick<Template, 'name' | 'grades' | 'blocks'>>) => send<Template>('PUT', '/report-templates/{id}', { path: { id }, body }),
  uploadLetterhead: (id: string, file: File) => {
    const body = new FormData();
    body.append('file', file);

    return send<Template>('POST', '/report-templates/{id}/letterhead', { path: { id }, body });
  },
  removeLetterhead: (id: string) => send<Template>('DELETE', '/report-templates/{id}/letterhead', { path: { id } }),
  previewPromotion: (to: PromotionTarget, exceptions: Record<string, PromotionAction>) => send<PromotionPreview>('POST', '/promotions/preview', { body: { to, exceptions } }),
  carryForward: (to: PromotionTarget, decisions: Record<string, DuesChoice>, duesDigest: string) =>
    send<{ to: { id: string; name: string }; carried: number; left: number; carried_paise: number; left_paise: number }>('POST', '/promotions/carry-forward', {
      body: { to, decisions, dues_digest: duesDigest },
      headers: key(),
    }),
  applyPromotion: (to: PromotionTarget, exceptions: Record<string, PromotionAction>, digest: string) =>
    send<{ from: { name: string }; to: { name: string }; counts: Record<PromotionAction, number> }>('POST', '/promotions/apply', { body: { to, exceptions, digest }, headers: key() }),
};

/**
 * Opens a card's PDF: asks for a fresh signed link (the permission check
 * happens here) and opens it at once; the link stops working after a few
 * minutes.
 */
export async function openCardPdf(id: string): Promise<void> {
  const { url } = await reports.link(id);
  const apiOrigin = new URL(env.apiBaseUrl, location.origin);
  // The server returns a path that already starts with the API prefix.
  window.open(new URL(url.replace(/^\/api\/v1/, apiOrigin.pathname.replace(/\/$/, '')), apiOrigin.origin).toString(), '_blank', 'noopener');
}

/** Attendance as "present / working days", half-days kept. */
export function attendanceText(a: { present_halves: number; working: number } | null): string | null {
  if (!a || a.working === 0) return null;
  const present = a.present_halves / 2;

  return `${Number.isInteger(present) ? present : present.toFixed(1)} / ${a.working}`;
}

export const cardChip = (status: CardStatus) => (({ draft: 'draft', reviewed: 'verified', published: 'published', replaced: 'returned' }) as const)[status];
