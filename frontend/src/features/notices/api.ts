import { useQuery } from '@tanstack/react-query';
import { apiGet } from '@/lib/api';
import { send } from '@/lib/requests';

export type AudienceType = 'school' | 'class' | 'section' | 'role';

export interface NoticeAttachment {
  id: string;
  name: string;
  mime: string;
  size: number;
}

export interface Notice {
  id: string;
  title: string;
  body: string;
  audience: { type: AudienceType; refs: string[] };
  sent_by: string | null;
  sent_by_id: string | null;
  published_at: string;
  attachments: NoticeAttachment[];
  recipient_count: number;
  read: boolean;
}

export interface NoticeReceipts {
  recipient_count: number;
  read_count: number;
  readers: { name: string; read_at: string }[];
}

/** Mirrors config/communication.php: the server checks again. */
export const ATTACHMENT_TYPES = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'docx', 'xlsx'] as const;
export const ATTACHMENT_MAX_BYTES = 5120 * 1024;
export const ATTACHMENTS_PER_NOTICE = 5;

/** Roles a school-wide sender may address. */
export const AUDIENCE_ROLES = ['principal', 'vice_principal', 'clerk', 'class_teacher', 'subject_teacher', 'staff', 'parent', 'student', 'fee_collector'] as const;

export const noticeKeys = {
  list: ['notices'] as const,
  one: (id: string) => ['notices', id] as const,
  receipts: (id: string) => ['notices', id, 'receipts'] as const,
};

/** The list refetches on focus; a refetch is a background request. */
export function useNotices() {
  return useQuery({ queryKey: noticeKeys.list, queryFn: apiGet<Notice[]>('/notices'), refetchOnWindowFocus: true });
}

/** Opening a notice marks it read for this person. */
export function useNotice(id: string) {
  return useQuery({ queryKey: noticeKeys.one(id), queryFn: apiGet<Notice>(`/notices/${id}`), retry: false });
}

export function useReceipts(id: string, enabled: boolean) {
  return useQuery({ queryKey: noticeKeys.receipts(id), queryFn: apiGet<NoticeReceipts>(`/notices/${id}/receipts`), enabled, retry: false });
}

/** Why a file cannot be attached, or null when it is fine. */
export function attachmentProblem(file: File): 'type' | 'size' | null {
  const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
  if (!(ATTACHMENT_TYPES as readonly string[]).includes(extension)) return 'type';
  if (file.size > ATTACHMENT_MAX_BYTES) return 'size';
  return null;
}

export const notices = {
  send: (input: { title: string; body: string; audience: { type: AudienceType; refs: string[] }; attachments: File[] }) => {
    const form = new FormData();
    form.append('title', input.title);
    form.append('body', input.body);
    form.append('audience[type]', input.audience.type);
    for (const ref of input.audience.refs) form.append('audience[refs][]', ref);
    for (const file of input.attachments) form.append('attachments[]', file);
    return send<Notice>('POST', '/notices', { body: form });
  },
  /** A short-lived signed link: fetched fresh on every click, never kept. */
  attachmentLink: (id: string, attachment: string) =>
    send<{ url: string; expires_in: number }>('POST', '/notices/{id}/attachments/{attachment}/link', { path: { id, attachment } }),
};
