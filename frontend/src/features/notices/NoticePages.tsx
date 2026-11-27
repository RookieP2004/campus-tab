import { zodResolver } from '@hookform/resolvers/zod';
import { ApprovalTimeline, EmptyState } from '@campus-tab/ui';
import { useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Download, Megaphone, Paperclip, Plus, X } from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router';
import { z } from 'zod';
import { Button, buttonVariants } from '@/components/ui/button';
import { Alert, Card, Field, Input, Spinner, Textarea } from '@/components/ui/form';
import { allSections, useClasses } from '@/features/academic/api';
import { errorText } from '@/features/auth/errors';
import { holds, scopeOf } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { formatBytes, formatDate, formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
  ATTACHMENT_MAX_BYTES,
  ATTACHMENT_TYPES,
  ATTACHMENTS_PER_NOTICE,
  attachmentProblem,
  AUDIENCE_ROLES,
  type AudienceType,
  type Notice,
  noticeKeys,
  notices,
  useNotice,
  useNotices,
  useReceipts,
} from './api';

function BackLink() {
  const { t } = useTranslation();
  return (
    <Link to="/notices" className="inline-flex min-h-11 items-center gap-1.5 self-start text-sm text-primary underline-offset-2 hover:underline">
      <ArrowLeft aria-hidden="true" className="size-4 rtl:rotate-180" />
      {t('notices.back')}
    </Link>
  );
}

/** Notices this person may read, newest first, with an unread marker. */
export function NoticeListPage() {
  const { t, i18n } = useTranslation();
  const me = useCurrentUser();
  const { data, isPending, error } = useNotices();
  const list = Array.isArray(data) ? data : [];

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">{t('notices.title')}</h1>
        {holds(me, 'notices.send') ? (
          <Link to="/notices/new" className={buttonVariants()}>
            <Plus aria-hidden="true" />
            {t('notices.new')}
          </Link>
        ) : null}
      </div>
      {isPending ? <Spinner label={t('common.loading')} /> : null}
      {error ? <Alert tone="danger">{errorText(t, error)}</Alert> : null}
      {data && list.length === 0 ? <EmptyState icon={<Megaphone className="size-5" />} title={t('notices.empty')} /> : null}
      {list.length > 0 ? (
        <ul className="flex flex-col gap-2">
          {list.map((n) => (
            <li key={n.id}>
              <Link
                to={`/notices/${n.id}`}
                className="flex min-h-11 items-start gap-3 rounded-card border border-border bg-surface p-4 hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
              >
                <span aria-hidden="true" className={cn('mt-1.5 size-2.5 shrink-0 rounded-full', n.read ? 'bg-transparent' : 'bg-primary-solid')} />
                <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                  <span className={cn('text-base text-fg', !n.read && 'font-semibold')}>
                    {n.title}
                    {!n.read ? <span className="sr-only"> ({t('notices.unread')})</span> : null}
                  </span>
                  <span className="text-xs text-muted">
                    {[n.sent_by, formatDate(n.published_at, i18n.language)].filter(Boolean).join(' · ')}
                  </span>
                  <span className="line-clamp-2 text-sm text-muted">{n.body}</span>
                </span>
                {n.attachments.length > 0 ? (
                  <span className="inline-flex shrink-0 items-center gap-1 text-xs text-muted">
                    <Paperclip aria-hidden="true" className="size-3.5" />
                    <span className="sr-only">{t('notices.attachmentCount', { count: n.attachments.length })}</span>
                    <span aria-hidden="true">{n.attachments.length}</span>
                  </span>
                ) : null}
              </Link>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}

/**
 * Send a notice. Only for people holding notices.send. Whole-school and
 * role audiences only for notices.send:school; a class or subject teacher
 * sends to their own sections. The server decides in the end
 * (notice_audience_forbidden).
 */
export function NoticeComposerPage() {
  const { t } = useTranslation();
  const me = useCurrentUser();

  if (!holds(me, 'notices.send')) {
    return (
      <div className="mx-auto flex max-w-3xl flex-col gap-4">
        <BackLink />
        <Alert tone="warning">{t('notices.cannotSend')}</Alert>
      </div>
    );
  }

  return <NoticeComposer />;
}

function NoticeComposer() {
  const { t, i18n } = useTranslation();
  const me = useCurrentUser();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const fileInput = useRef<HTMLInputElement>(null);
  const fileId = useId();
  const schoolWide = holds(me, 'notices.send', 'school');
  const wingWide = holds(me, 'notices.send', 'wing');
  const types: AudienceType[] = schoolWide ? ['school', 'role', 'class', 'section'] : wingWide ? ['class', 'section'] : ['section'];
  const { data: classes } = useClasses(holds(me, 'academic.view'));
  const [files, setFiles] = useState<File[]>([]);
  const [fileError, setFileError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const schema = z
    .object({
      title: z.string().trim().min(3, t('notices.form.titleRequired')).max(150, t('notices.form.titleTooLong')),
      body: z.string().trim().min(1, t('notices.form.bodyRequired')).max(5000, t('notices.form.bodyTooLong')),
      type: z.enum(['school', 'role', 'class', 'section']),
      refs: z.array(z.string()),
    })
    .refine((v) => v.type === 'school' || v.refs.length > 0, { message: t('notices.form.refsRequired'), path: ['refs'] });
  type Values = z.infer<typeof schema>;
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { title: '', body: '', type: types[0], refs: [] } });
  const type = useWatch({ control: form.control, name: 'type' });
  const refs = useWatch({ control: form.control, name: 'refs' });

  const scope = scopeOf(me);
  const options = useMemo(() => {
    const list = Array.isArray(classes) ? classes : [];
    const own = new Set([...scope.class_sections, ...scope.subject_sections.map((k) => k.split(':')[0] ?? '')]);
    const inWing = (wing: string | null) => wing !== null && scope.wings.includes(wing);
    const visibleClasses = schoolWide ? list : list.filter((c) => inWing(c.wing));
    const sections = allSections(list).filter((s) => schoolWide || (wingWide && inWing(s.wing ?? list.find((c) => c.id === s.class_id)?.wing ?? null)) || own.has(s.id));
    return {
      role: AUDIENCE_ROLES.map((r) => ({ value: r, label: t(`roles.${r}`) })),
      class: visibleClasses.map((c) => ({ value: c.id, label: c.name })),
      section: sections.map((s) => ({ value: s.id, label: s.label })),
      school: [] as { value: string; label: string }[],
    };
  }, [classes, schoolWide, wingWide, scope.class_sections, scope.subject_sections, scope.wings, t]);

  const addFiles = (chosen: FileList | null) => {
    setFileError(null);
    if (!chosen) return;
    const next = [...files];
    for (const file of Array.from(chosen)) {
      const problem = attachmentProblem(file);
      if (problem) {
        setFileError(t(`notices.form.file_${problem}`, { name: file.name }));
        continue;
      }
      if (next.length >= ATTACHMENTS_PER_NOTICE) {
        setFileError(t('notices.form.file_count', { count: ATTACHMENTS_PER_NOTICE }));
        break;
      }
      next.push(file);
    }
    setFiles(next);
    if (fileInput.current) fileInput.current.value = '';
  };

  const submit = form.handleSubmit(async (v) => {
    setError(null);
    try {
      const notice = await notices.send({ title: v.title.trim(), body: v.body.trim(), audience: { type: v.type, refs: v.type === 'school' ? [] : v.refs }, attachments: files });
      await queryClient.invalidateQueries({ queryKey: noticeKeys.list });
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
      navigate(`/notices/${notice.id}`, { replace: true });
    } catch (e) {
      setError(errorText(t, e));
    }
  });

  const err = form.formState.errors;
  const choices = options[type];

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4">
      <BackLink />
      <h1 className="text-2xl font-semibold tracking-tight">{t('notices.newTitle')}</h1>
      <Card>
        <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <Field label={t('notices.form.title')} error={err.title?.message}>
            {(p) => <Input {...p} {...form.register('title')} maxLength={150} />}
          </Field>
          <Field label={t('notices.form.body')} error={err.body?.message}>
            {(p) => <Textarea {...p} {...form.register('body')} rows={6} maxLength={5000} />}
          </Field>

          <fieldset className="flex flex-col gap-2">
            <legend className="mb-1 text-sm font-medium">{t('notices.form.audience')}</legend>
            <div className="flex flex-wrap gap-2">
              {types.map((value) => (
                <label
                  key={value}
                  className={cn(
                    'flex min-h-11 cursor-pointer items-center gap-2 rounded-input border px-3 text-sm',
                    type === value ? 'border-primary bg-primary-soft text-fg' : 'border-border bg-surface',
                  )}
                >
                  <input
                    type="radio"
                    value={value}
                    checked={type === value}
                    onChange={() => {
                      form.setValue('type', value);
                      form.setValue('refs', []);
                    }}
                    className="size-4"
                  />
                  {t(`notices.audience.${value}`)}
                </label>
              ))}
            </div>
          </fieldset>

          {type !== 'school' ? (
            <fieldset className="flex flex-col gap-2" aria-describedby={err.refs ? 'refs-error' : undefined}>
              <legend className="mb-1 text-sm font-medium">{t(`notices.form.pick.${type}`)}</legend>
              {choices.length === 0 ? <p className="text-sm text-muted">{t('notices.form.noChoices')}</p> : null}
              <div className="grid gap-1 sm:grid-cols-2">
                {choices.map((c) => (
                  <label key={c.value} className="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-input px-2 text-sm hover:bg-surface-muted">
                    <input
                      type="checkbox"
                      checked={refs.includes(c.value)}
                      onChange={(e) =>
                        form.setValue('refs', e.target.checked ? [...refs, c.value] : refs.filter((r) => r !== c.value), { shouldValidate: form.formState.isSubmitted })
                      }
                      className="size-4"
                    />
                    {c.label}
                  </label>
                ))}
              </div>
              {err.refs ? (
                <p id="refs-error" role="alert" className="text-sm text-danger">
                  {err.refs.message}
                </p>
              ) : null}
            </fieldset>
          ) : null}

          <div className="flex flex-col gap-2">
            <label htmlFor={fileId} className="text-sm font-medium">
              {t('notices.form.attachments')}
            </label>
            <input
              ref={fileInput}
              id={fileId}
              type="file"
              multiple
              accept={ATTACHMENT_TYPES.map((x) => `.${x}`).join(',')}
              aria-describedby={`${fileId}-hint`}
              onChange={(e) => addFiles(e.target.files)}
              className="min-h-11 text-sm file:me-3 file:h-11 file:rounded-input file:border file:border-border file:bg-surface file:px-3 file:text-fg"
            />
            <p id={`${fileId}-hint`} className="text-xs text-muted">
              {t('notices.form.attachmentsHint', { count: ATTACHMENTS_PER_NOTICE, size: formatBytes(ATTACHMENT_MAX_BYTES, i18n.language) })}
            </p>
            {fileError ? (
              <p role="alert" className="text-sm text-danger">
                {fileError}
              </p>
            ) : null}
            {files.length > 0 ? (
              <ul className="flex flex-col gap-1">
                {files.map((file, index) => (
                  <li key={`${file.name}:${index}`} className="flex min-h-11 items-center gap-2 rounded-input bg-bg px-2 text-sm">
                    <Paperclip aria-hidden="true" className="size-4 text-muted" />
                    <span className="min-w-0 flex-1 truncate">{file.name}</span>
                    <span className="text-xs text-muted">{formatBytes(file.size, i18n.language)}</span>
                    <Button type="button" variant="ghost" size="icon" aria-label={t('notices.form.removeFile', { name: file.name })} onClick={() => setFiles(files.filter((_, i) => i !== index))}>
                      <X aria-hidden="true" />
                    </Button>
                  </li>
                ))}
              </ul>
            ) : null}
          </div>

          <div className="flex justify-end">
            <Button type="submit" disabled={form.formState.isSubmitting}>
              <Megaphone aria-hidden="true" />
              {form.formState.isSubmitting ? t('common.working') : t('notices.form.send')}
            </Button>
          </div>
        </form>
      </Card>
    </div>
  );
}

/** One notice: opening it marks it read. Attachments open through a fresh signed link each time. */
export function NoticeDetailPage() {
  const { t, i18n } = useTranslation();
  const { id = '' } = useParams();
  const me = useCurrentUser();
  const queryClient = useQueryClient();
  const { data: notice, isPending, error } = useNotice(id);
  const [linkError, setLinkError] = useState<string | null>(null);
  const showReceipts = Boolean(notice && (notice.sent_by_id === me.id || holds(me, 'notices.view', 'school')));

  useEffect(() => {
    if (!notice) return;
    // It is read now: the list, the bell and the dashboard catch up.
    queryClient.setQueryData<Notice[]>(noticeKeys.list, (old) => (Array.isArray(old) ? old.map((n) => (n.id === notice.id ? { ...n, read: true } : n)) : old));
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
  }, [notice, queryClient]);

  const openAttachment = async (attachmentId: string) => {
    setLinkError(null);
    // Opened before the request so the browser does not block it as a pop-up.
    const win = window.open('', '_blank');
    try {
      const { url } = await notices.attachmentLink(id, attachmentId);
      if (win) {
        win.opener = null;
        win.location.href = url;
      } else {
        window.location.assign(url);
      }
    } catch (e) {
      win?.close();
      setLinkError(errorText(t, e));
    }
  };

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4">
      <BackLink />
      {isPending ? <Spinner label={t('common.loading')} /> : null}
      {error ? <Alert tone="danger">{errorText(t, error)}</Alert> : null}
      {notice ? (
        <>
          <article className="flex flex-col gap-4 rounded-card border border-border bg-surface p-5 sm:p-6" aria-labelledby="notice-title">
            <header className="flex flex-col gap-1">
              <h1 id="notice-title" className="text-2xl font-semibold tracking-tight">
                {notice.title}
              </h1>
              <p className="text-sm text-muted">
                {[notice.sent_by, formatDateTime(notice.published_at, i18n.language), audienceText(t, notice)].filter(Boolean).join(' · ')}
              </p>
            </header>
            <div className="text-base leading-relaxed whitespace-pre-wrap">{notice.body}</div>
            {notice.attachments.length > 0 ? (
              <section aria-labelledby="notice-files" className="flex flex-col gap-2 border-t border-border pt-4">
                <h2 id="notice-files" className="text-sm font-semibold">
                  {t('notices.attachments')}
                </h2>
                {linkError ? <Alert tone="danger">{linkError}</Alert> : null}
                <ul className="flex flex-col gap-1">
                  {notice.attachments.map((a) => (
                    <li key={a.id}>
                      <button
                        type="button"
                        onClick={() => void openAttachment(a.id)}
                        className="flex min-h-11 w-full items-center gap-2.5 rounded-input border border-border px-3 text-start text-sm hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring"
                      >
                        <Download aria-hidden="true" className="size-4 text-muted" />
                        <span className="min-w-0 flex-1 truncate">{a.name}</span>
                        <span className="text-xs text-muted">{formatBytes(a.size, i18n.language)}</span>
                        <span className="sr-only">{t('notices.openFile')}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              </section>
            ) : null}
          </article>
          {showReceipts ? <ReceiptsPanel notice={notice} /> : null}
        </>
      ) : null}
    </div>
  );
}

function audienceText(t: ReturnType<typeof useTranslation>['t'], notice: Notice): string {
  const { type, refs } = notice.audience;
  if (type === 'school') return t('notices.audience.school');
  if (type === 'role') return refs.map((r) => t(`roles.${r}`, { defaultValue: r })).join(', ');
  return t(`notices.audience.${type}Count`, { count: refs.length });
}

/** Who read it: for the sender, and for those who read notices school-wide. */
function ReceiptsPanel({ notice }: { notice: Notice }) {
  const { t, i18n } = useTranslation();
  const { data, isPending, error } = useReceipts(notice.id, true);

  return (
    <section aria-labelledby="receipts-title" className="flex flex-col gap-4 rounded-card border border-border bg-surface p-5">
      <h2 id="receipts-title" className="text-base font-semibold">
        {t('notices.receipts.title')}
      </h2>
      {isPending ? <Spinner label={t('common.loading')} /> : null}
      {error ? <Alert tone="danger">{errorText(t, error)}</Alert> : null}
      {data ? (
        <>
          <ApprovalTimeline
            label={t('notices.receipts.timeline')}
            steps={[
              {
                id: 'sent',
                label: t('notices.receipts.sent'),
                status: 'published',
                statusLabel: t('status.published'),
                who: notice.sent_by,
                when: formatDateTime(notice.published_at, i18n.language),
                whenIso: notice.published_at,
              },
              {
                id: 'read',
                label: t('notices.receipts.readBy', { read: data.read_count, total: data.recipient_count }),
                status: data.recipient_count > 0 && data.read_count >= data.recipient_count ? 'approved' : 'pending',
                statusLabel: data.recipient_count > 0 && data.read_count >= data.recipient_count ? t('notices.receipts.allRead') : t('status.pending'),
              },
            ]}
          />
          {data.readers.length === 0 ? (
            <p className="text-sm text-muted">{t('notices.receipts.none')}</p>
          ) : (
            <ul className="flex flex-col divide-y divide-border text-sm">
              {data.readers.map((r, index) => (
                <li key={`${r.name}:${index}`} className="flex items-center justify-between gap-3 py-2">
                  <span>{r.name}</span>
                  <time dateTime={r.read_at} className="text-xs text-muted">
                    {formatDateTime(r.read_at, i18n.language)}
                  </time>
                </li>
              ))}
            </ul>
          )}
        </>
      ) : null}
    </section>
  );
}
