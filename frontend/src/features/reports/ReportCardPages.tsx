import { EmptyState, StatusChip } from '@campus-tab/ui';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronRight, FileDown, FileText, LayoutTemplate, RefreshCw, Send, Sparkles, TrendingUp } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Dialog, Field, Select, Spinner, Textarea } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { holds } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { apiGet } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import { attendanceText, cardChip, type CardRun, openCardPdf, type ReportCard, reports, type SectionCards, type SectionSummary } from './api';

/** The sections whose report cards I make, remark on or read. */
export function ReportCardsHomePage() {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const sections = useQuery({ queryKey: ['reports', 'sections'], queryFn: apiGet<SectionSummary[]>('/report-cards/sections'), retry: false });

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">{t('reports.title')}</h1>
        <div className="flex flex-wrap gap-2">
          {holds(me, 'report_templates.manage') ? (
            <Button asChild variant="outline" className="h-11">
              <Link to="/report-cards/templates">
                <LayoutTemplate aria-hidden="true" />
                {t('reports.templates.title')}
              </Link>
            </Button>
          ) : null}
          {holds(me, 'year.promote') ? (
            <Button asChild variant="outline" className="h-11">
              <Link to="/year-end">
                <TrendingUp aria-hidden="true" />
                {t('reports.promotion.title')}
              </Link>
            </Button>
          ) : null}
        </div>
      </div>
      <p className="text-sm text-muted">{t('reports.intro')}</p>
      {sections.isPending ? <Spinner label={t('common.loading')} /> : null}
      {sections.error ? <Alert tone="danger">{errorText(t, sections.error)}</Alert> : null}
      {sections.data && sections.data.length === 0 ? <EmptyState icon={<FileText className="size-5" />} title={t('reports.noSections')} /> : null}
      <ul className="grid gap-2 sm:grid-cols-2">
        {(sections.data ?? []).map((s) => (
          <li key={s.id}>
            <Link to={`/report-cards/sections/${s.id}`} className="flex min-h-16 items-center gap-3 rounded-card border border-border bg-surface p-4 hover:border-primary focus-visible:outline-2 focus-visible:outline-ring">
              <div className="min-w-0 flex-1">
                <p className="font-semibold">{t('reports.sectionName', { label: s.label })}</p>
                <p className="text-xs text-muted">
                  {(['draft', 'reviewed', 'published'] as const)
                    .filter((k) => s.counts[k])
                    .map((k) => t(`reports.count.${k}`, { count: s.counts[k] }))
                    .join(' · ') || t('reports.noCardsYet')}
                </p>
              </div>
              <ChevronRight aria-hidden="true" className="size-4 text-muted rtl:rotate-180" />
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}

/** Follows a background run until it ends, then refreshes the section. */
function useRun(run: CardRun | null) {
  const queryClient = useQueryClient();
  const active = run && (run.status === 'queued' || run.status === 'running') ? run.id : null;
  const live = useQuery({ queryKey: ['reports', 'run', active], queryFn: apiGet<CardRun>(`/report-cards/runs/${active}`), enabled: active !== null, refetchInterval: 2000 });
  const finished = live.data && (live.data.status === 'done' || live.data.status === 'failed');

  useEffect(() => {
    if (finished) void queryClient.invalidateQueries({ queryKey: ['reports'] });
  }, [finished, queryClient]);

  return live.data ?? run;
}

/** One section: generate from locked papers, review each card, publish. */
export function SectionCardsPage() {
  const { t, i18n } = useTranslation();
  const { id = '' } = useParams();
  const queryClient = useQueryClient();
  const data = useQuery({ queryKey: ['reports', 'section', id], queryFn: apiGet<SectionCards>(`/report-cards/sections/${id}`), retry: false });
  const run = useRun(data.data?.run ?? null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const start = async (action: 'generate' | 'publish') => {
    setBusy(true);
    setError(null);
    try {
      const started = await reports[action](id);
      queryClient.setQueryData<SectionCards>(['reports', 'section', id], (old) => (old ? { ...old, run: started } : old));
    } catch (e) {
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  if (data.isPending) return <Spinner label={t('common.loading')} />;
  if (data.error || !data.data) return <Alert tone="danger">{errorText(t, data.error)}</Alert>;
  const s = data.data;
  const running = run && (run.status === 'queued' || run.status === 'running');
  const reviewed = s.cards.filter((c) => c.status === 'reviewed').length;

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4">
      <Link to="/report-cards" className="text-sm font-medium text-primary hover:underline">
        {t('reports.back')}
      </Link>
      <h1 className="text-2xl font-semibold tracking-tight">{t('reports.sectionName', { label: s.section.label })}</h1>
      <Card className="flex flex-col gap-3">
        <p className="text-sm">{t('reports.papers', { locked: s.papers.locked, count: s.papers.locked })}</p>
        {s.papers.not_locked > 0 ? <Alert tone="warning">{t('reports.notLocked', { count: s.papers.not_locked })}</Alert> : null}
        {running ? (
          <div className="flex flex-col gap-2" aria-live="polite">
            <p className="text-sm font-medium">{t(`reports.run.${run.kind}`)}</p>
            <progress className="h-2 w-full accent-primary" max={run.total || undefined} value={run.total ? (run.done ?? 0) : undefined} aria-label={t(`reports.run.${run.kind}`)} />
            <p className="text-xs text-muted">{run.total ? t('reports.run.progress', { done: run.done ?? 0, total: run.total }) : t('reports.run.starting')}</p>
          </div>
        ) : null}
        {run?.status === 'failed' ? <Alert tone="danger">{run.error ?? t('reports.run.failed')}</Alert> : null}
        {run?.status === 'done' && !running ? (
          <p className="text-xs text-muted">{t(`reports.run.finished.${run.kind}`, { date: run.at ? formatDateTime(run.at, i18n.language) : '' })}</p>
        ) : null}
        {error ? <Alert tone="danger">{error}</Alert> : null}
        {s.can.generate ? (
          <div className="flex flex-wrap gap-2">
            <Button className="h-11" variant="outline" disabled={busy || Boolean(running)} onClick={() => void start('generate')}>
              <RefreshCw aria-hidden="true" />
              {s.cards.length ? t('reports.regenerate') : t('reports.generate')}
            </Button>
            <Button className="h-11" disabled={busy || Boolean(running) || reviewed === 0} onClick={() => void start('publish')}>
              <Send aria-hidden="true" />
              {t('reports.publish', { count: reviewed })}
            </Button>
          </div>
        ) : null}
        {s.can.generate ? <p className="text-xs text-muted">{t('reports.generateHint')}</p> : null}
      </Card>
      {s.cards.length === 0 ? <EmptyState icon={<FileText className="size-5" />} title={t('reports.noCardsYet')} /> : null}
      <ul className="flex flex-col gap-2">
        {s.cards.map((c) => (
          <li key={c.id}>
            <Link to={`/report-cards/${c.id}`} className="flex min-h-16 items-center gap-3 rounded-card border border-border bg-surface p-4 hover:border-primary focus-visible:outline-2 focus-visible:outline-ring">
              <div className="min-w-0 flex-1">
                <p className="truncate font-semibold">{c.student.name}</p>
                <p className="text-xs text-muted">
                  {c.snapshot.overall?.percent != null ? `${c.snapshot.overall.percent}% · ${c.snapshot.overall.grade ?? '–'} · ` : ''}
                  {c.snapshot.overall ? t(`reports.result.${c.snapshot.overall.result}`) : ''}
                  {c.version > 1 ? ` · ${t('reports.versionN', { n: c.version })}` : ''}
                </p>
              </div>
              <StatusChip status={cardChip(c.status)} label={t(`reports.status.${c.status}`)} />
              <ChevronRight aria-hidden="true" className="size-4 text-muted rtl:rotate-180" />
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}

/** One card: the computed result, remarks, review, PDF and re-issue. */
export function ReportCardPage() {
  const { t } = useTranslation();
  const { id = '' } = useParams();
  const card = useQuery({ queryKey: ['reports', 'card', id], queryFn: apiGet<ReportCard>(`/report-cards/${id}`), retry: false });

  if (card.isPending) return <Spinner label={t('common.loading')} />;
  if (card.error || !card.data) return <Alert tone="danger">{errorText(t, card.error)}</Alert>;

  // Remounts when the card changes on the server, so the form starts from it.
  return <CardDetail key={`${card.data.id}-${card.data.status}`} card={card.data} />;
}

function CardDetail({ card: c }: { card: ReportCard }) {
  const { t, i18n } = useTranslation();
  const id = c.id;
  const queryClient = useQueryClient();
  const [classRemark, setClassRemark] = useState<string>(c.remarks.class_teacher ?? '');
  const [subjectRemarks, setSubjectRemarks] = useState<Record<string, string>>(c.remarks.subjects ?? {});
  const [co, setCo] = useState<Record<string, string>>(c.co_scholastic ?? {});
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [reissueOpen, setReissueOpen] = useState(false);
  const [reason, setReason] = useState('');
  const editable = c.can.remark_class || c.can.remark_subjects.length > 0;
  const subjects = c.snapshot.subjects ?? [];
  const areas = c.co_scholastic_options?.areas ?? {};

  const run = async (work: () => Promise<unknown>, done: string) => {
    setBusy(true);
    setMessage(null);
    try {
      const result = await work();
      if (result && typeof result === 'object' && 'snapshot' in result) queryClient.setQueryData(['reports', 'card', id], result);
      void queryClient.invalidateQueries({ queryKey: ['reports'] });
      setMessage({ tone: 'success', text: done });

      return true;
    } catch (e) {
      setMessage({ tone: 'danger', text: errorText(t, e) });

      return false;
    } finally {
      setBusy(false);
    }
  };

  const save = () => {
    const body: Parameters<typeof reports.remarks>[1] = {};
    if (c.can.remark_class) {
      body.class_teacher = classRemark;
      body.co_scholastic = Object.fromEntries(Object.keys(areas).map((k) => [k, co[k] ?? null]));
    }
    if (c.can.remark_subjects.length) body.subjects = Object.fromEntries(c.can.remark_subjects.map((sid) => [sid, subjectRemarks[sid] ?? '']));
    void run(() => reports.remarks(c.id, body), c.status === 'reviewed' ? t('reports.card.savedBackToDraft') : t('reports.card.saved'));
  };

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4">
      <Link to={`/report-cards/sections/${c.section.id}`} className="text-sm font-medium text-primary hover:underline">
        {t('reports.backToSection', { label: c.section.label })}
      </Link>
      <div className="flex flex-wrap items-start gap-3">
        <div className="min-w-40 flex-1">
          <h1 className="text-2xl font-semibold tracking-tight">{c.student.name}</h1>
          <p className="text-sm text-muted">
            {c.section.label} · {c.year.name} · {t('reports.versionN', { n: c.version })}
            {c.issued_at ? ` · ${t('reports.issuedOn', { date: formatDate(c.issued_at, i18n.language) })}` : ''}
          </p>
        </div>
        <StatusChip status={cardChip(c.status)} label={t(`reports.status.${c.status}`)} />
      </div>
      {message ? <Alert tone={message.tone}>{message.text}</Alert> : null}
      {c.status === 'replaced' && c.replaced_by_id ? (
        <Alert tone="warning">
          <span className="flex flex-wrap gap-x-2">
            {t('reports.card.replacedNote')}
            <Link to={`/report-cards/${c.replaced_by_id}`} className="font-medium text-primary underline">
              {t('reports.card.openCurrent')}
            </Link>
          </span>
        </Alert>
      ) : null}
      {c.reissue_reason ? <Alert tone="info">{t('reports.card.reissuedBecause', { reason: c.reissue_reason })}</Alert> : null}

      {c.snapshot.overall ? (
        <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
          <div className="rounded-input bg-surface-muted p-3">
            <dt className="text-xs text-muted">{t('reports.card.overall')}</dt>
            <dd className="text-2xl font-semibold tabular-nums">{c.snapshot.overall.percent != null ? `${c.snapshot.overall.percent}%` : '–'}</dd>
          </div>
          <div className="rounded-input bg-surface-muted p-3">
            <dt className="text-xs text-muted">{t('reports.card.grade')}</dt>
            <dd className="text-2xl font-semibold">{c.snapshot.overall.grade ?? '–'}</dd>
          </div>
          <div className="rounded-input bg-surface-muted p-3">
            <dt className="text-xs text-muted">{t('reports.card.result')}</dt>
            <dd className="text-lg font-semibold">{t(`reports.result.${c.snapshot.overall.result}`)}</dd>
          </div>
          <div className="rounded-input bg-surface-muted p-3">
            <dt className="text-xs text-muted">{t('reports.card.attendance')}</dt>
            <dd className="text-lg font-semibold tabular-nums">{attendanceText(c.attendance) ?? '–'}</dd>
          </div>
        </dl>
      ) : null}

      <Card className="flex flex-col gap-3">
        <h2 className="text-lg font-semibold">{t('reports.card.scholastic')}</h2>
        {subjects.length === 0 ? <p className="text-sm text-muted">{t('reports.card.noMarks')}</p> : null}
        <ul className="flex flex-col divide-y divide-border">
          {subjects.map((s) => (
            <li key={s.subject_id} className="flex flex-col gap-2 py-3">
              <div className="flex flex-wrap items-center gap-2">
                <p className="min-w-32 flex-1 break-words font-medium">{s.subject}</p>
                {s.grace ? <span className="text-xs text-muted">{t('reports.card.grace', { amount: s.grace })}</span> : null}
                {s.incomplete ? <span className="text-xs text-status-pending-fg">{t('reports.card.incomplete')}</span> : null}
                <span className="ms-auto flex items-center gap-2">
                  <span className="font-semibold tabular-nums">{s.percent != null ? `${s.percent}%` : '–'}</span>
                  <span className="w-10 text-end font-semibold">{s.grade ?? '–'}</span>
                </span>
              </div>
              <p className="text-xs text-muted">
                {s.terms
                  .flatMap((term) => term.components.filter((comp) => comp.max).map((comp) => `${s.terms.length > 1 ? `${term.term}: ` : ''}${comp.name} ${comp.obtained}/${comp.max}`))
                  .join(' · ')}
              </p>
              {c.can.remark_subjects.includes(s.subject_id) ? (
                <Field label={t('reports.card.subjectRemark', { subject: s.subject })}>
                  {(p) => <Textarea {...p} rows={2} maxLength={400} value={subjectRemarks[s.subject_id] ?? ''} onChange={(e) => setSubjectRemarks({ ...subjectRemarks, [s.subject_id]: e.target.value })} />}
                </Field>
              ) : c.remarks.subjects?.[s.subject_id] ? (
                <p className="text-sm">“{c.remarks.subjects[s.subject_id]}”</p>
              ) : null}
            </li>
          ))}
        </ul>
      </Card>

      {c.can.remark_class || c.remarks.class_teacher || Object.keys(c.co_scholastic).length ? (
        <Card className="flex flex-col gap-3">
          <h2 className="text-lg font-semibold">{t('reports.card.classTeacher')}</h2>
          {c.can.remark_class ? (
            <>
              <div className="grid gap-3 sm:grid-cols-2">
                {Object.entries(areas).map(([area, label]) => (
                  <Field key={area} label={t(`reports.areas.${area}`, { defaultValue: label })}>
                    {(p) => (
                      <Select {...p} value={co[area] ?? ''} onChange={(e) => setCo({ ...co, [area]: e.target.value })}>
                        <option value="">{t('reports.card.noGrade')}</option>
                        {(c.co_scholastic_options?.grades ?? []).map((g) => (
                          <option key={g} value={g}>
                            {g}
                          </option>
                        ))}
                      </Select>
                    )}
                  </Field>
                ))}
              </div>
              <Field label={t('reports.card.classRemark')}>
                {(p) => <Textarea {...p} rows={3} maxLength={400} value={classRemark} onChange={(e) => setClassRemark(e.target.value)} />}
              </Field>
            </>
          ) : (
            <>
              {Object.keys(c.co_scholastic).length ? (
                <p className="text-sm">
                  {Object.entries(c.co_scholastic)
                    .map(([area, g]) => `${t(`reports.areas.${area}`, { defaultValue: area })}: ${g}`)
                    .join(' · ')}
                </p>
              ) : null}
              {c.remarks.class_teacher ? <p className="text-sm">“{c.remarks.class_teacher}”</p> : null}
            </>
          )}
        </Card>
      ) : null}

      <div className="flex flex-wrap gap-2">
        {editable ? (
          <Button className="h-11" disabled={busy} onClick={save}>
            {t('reports.card.save')}
          </Button>
        ) : null}
        {c.can.review ? (
          <Button className="h-11" variant="outline" disabled={busy} onClick={() => void run(() => reports.review(c.id), t('reports.card.reviewedDone'))}>
            <Sparkles aria-hidden="true" />
            {t('reports.card.review')}
          </Button>
        ) : null}
        {c.can.download ? (
          <Button className="h-11" variant="outline" disabled={busy} onClick={() => void openCardPdf(c.id).catch((e: unknown) => setMessage({ tone: 'danger', text: errorText(t, e) }))}>
            <FileDown aria-hidden="true" />
            {t('reports.card.pdf')}
          </Button>
        ) : null}
        {c.can.reissue ? (
          <Button className="h-11" variant="outline" disabled={busy} onClick={() => setReissueOpen(true)}>
            <RefreshCw aria-hidden="true" />
            {t('reports.card.reissue')}
          </Button>
        ) : null}
      </div>

      {c.history.length ? (
        <Card className="flex flex-col gap-2">
          <h2 className="text-lg font-semibold">{t('reports.card.history')}</h2>
          <ol className="flex flex-col gap-1 text-sm">
            {c.history.map((h, i) => (
              <li key={i}>
                {t(`reports.history.${h.action}`, { defaultValue: h.action })}
                {h.by ? ` · ${h.by.name}` : ''} · {formatDateTime(h.at, i18n.language)}
                {h.note ? <span className="text-muted"> · “{h.note}”</span> : null}
              </li>
            ))}
          </ol>
        </Card>
      ) : null}

      <Dialog open={reissueOpen} title={t('reports.card.reissueTitle')} onClose={() => setReissueOpen(false)}>
        <p className="text-sm">{t('reports.card.reissueBody')}</p>
        <Field label={t('reports.card.reissueReason')} hint={t('reports.card.reissueHint')}>
          {(p) => <Textarea {...p} rows={3} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />}
        </Field>
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="outline" className="h-11" onClick={() => setReissueOpen(false)}>
            {t('common.cancel')}
          </Button>
          <Button
            className="h-11"
            disabled={busy || reason.trim().length < 10}
            onClick={() =>
              void run(() => reports.reissue(c.id, reason.trim()), t('reports.card.reissueStarted')).then((ok) => {
                if (ok) {
                  setReissueOpen(false);
                  setReason('');
                }
              })
            }
          >
            {t('reports.card.reissueConfirm')}
          </Button>
        </div>
      </Dialog>
    </div>
  );
}
