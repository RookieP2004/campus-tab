import { EmptyState, StatusChip } from '@campus-tab/ui';
import { useQueries, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronRight, ClipboardList, FileCheck2, Plus, Settings2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Field, Input, Select, Spinner } from '@/components/ui/form';
import { allSections, useAssignments, useClasses, useSubjects } from '@/features/academic/api';
import { errorText } from '@/features/auth/errors';
import { holds, scopeOf } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import type { StudentProfile } from '@/features/people/api';
import { apiGet } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import { apiGetBody } from '@/lib/requests';
import { FamilyCards } from '@/features/reports/FamilyCards';
import { type AssessmentDetail, type AssessmentListItem, type Completion, type Correction, cellText, marks, paperChip, type StudentResults } from './api';

const asDate = (ymd: string) => `${ymd}T12:00:00+05:30`;

/** This year's exams and class tests with papers I may see. */
export function TestsHomePage() {
  const { t, i18n } = useTranslation();
  const me = useCurrentUser();
  const list = useQuery({ queryKey: ['marks', 'assessments'], queryFn: apiGet<AssessmentListItem[]>('/assessments'), retry: false });
  const canCreate = holds(me, 'exams.schedule') || holds(me, 'tests.create');
  const canScheme = holds(me, 'assessment.scheme') || holds(me, 'assessment.setup');
  const canCorrections = holds(me, 'marks.correction_request') || holds(me, 'marks.correction_approve') || holds(me, 'audit.view', 'school');

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">{t('nav.tests')}</h1>
        <div className="flex flex-wrap gap-2">
          {canCorrections ? (
            <Button asChild variant="outline" className="h-11">
              <Link to="/tests/corrections">
                <FileCheck2 aria-hidden="true" />
                {t('tests.correction.listTitle')}
              </Link>
            </Button>
          ) : null}
          {canScheme ? (
            <Button asChild variant="outline" className="h-11">
              <Link to="/tests/schemes">
                <Settings2 aria-hidden="true" />
                {t('tests.scheme.title')}
              </Link>
            </Button>
          ) : null}
          {canCreate ? (
            <Button asChild className="h-11">
              <Link to="/tests/new">
                <Plus aria-hidden="true" />
                {holds(me, 'exams.schedule') ? t('tests.new.titleExam') : t('tests.new.titleTest')}
              </Link>
            </Button>
          ) : null}
        </div>
      </div>
      {list.isPending ? <Spinner label={t('common.loading')} /> : null}
      {list.error ? <Alert tone="danger">{errorText(t, list.error)}</Alert> : null}
      {list.data && list.data.length === 0 ? <EmptyState icon={<ClipboardList className="size-5" />} title={t('tests.empty')} /> : null}
      <ul className="flex flex-col gap-2">
        {(list.data ?? []).map((a) => (
          <li key={a.id}>
            <Link to={`/tests/${a.id}`} className="flex items-center gap-3 rounded-card border border-border bg-surface p-4 hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring">
              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold break-words">
                  {a.name}
                  <span className="ms-2 text-xs font-normal text-muted">{t(`tests.types.${a.type}`)}{a.kind === 'class_test' ? ` · ${t('tests.classTest')}` : ''}</span>
                </p>
                <p className="text-xs text-muted">
                  {formatDate(asDate(a.from), i18n.language)} · {t('tests.paperCount', { count: a.papers })}
                  {Object.entries(a.by_status).map(([s, n]) => ` · ${t(`tests.paperStatus.${s}`)} ${n}`)}
                  {a.published > 0 ? ` · ${t('tests.paperStatus.published')} ${a.published}` : ''}
                </p>
              </div>
              <ChevronRight aria-hidden="true" className="size-4 shrink-0 text-muted rtl:rotate-180" />
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}

/**
 * Schedule a school exam (Principal, Clerk) or set a class test (a teacher,
 * only for their own classes and subjects; the server checks again).
 */
export function NewAssessmentPage() {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const canExam = holds(me, 'exams.schedule');
  const schoolWide = holds(me, 'tests.create', 'school') || canExam;
  const classes = useClasses();
  const subjects = useSubjects();
  const assignments = useAssignments(!schoolWide);
  const [kind, setKind] = useState<'exam' | 'class_test'>(canExam ? 'exam' : 'class_test');
  const [type, setType] = useState(canExam ? 'periodic' : 'weekly');
  const [name, setName] = useState('');
  const [from, setFrom] = useState(new Date().toISOString().slice(0, 10));
  const [to, setTo] = useState(new Date().toISOString().slice(0, 10));
  const [max, setMax] = useState('40');
  const [picked, setPicked] = useState<Set<string>>(new Set());
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const scope = scopeOf(me);

  // Teachers choose from their own subject-sections (and the subjects of the class they teach); the office from every section and subject.
  const options = useMemo(() => {
    const sections = allSections(classes.data);
    const subs = subjects.data ?? [];
    if (schoolWide) return sections.flatMap((s) => subs.map((sub) => ({ key: `${s.id}:${sub.id}`, label: `${s.label} · ${sub.name}` })));
    const mine = new Set(scope.subject_sections);
    const own = (assignments.data ?? []).filter((a) => mine.has(`${a.section_id}:${a.subject_id}`) || scope.class_sections.includes(a.section_id));
    return own.map((a) => ({ key: `${a.section_id}:${a.subject_id}`, label: `${a.section_label ?? ''} · ${a.subject_name ?? ''}` }));
  }, [classes.data, subjects.data, assignments.data, schoolWide, scope.subject_sections, scope.class_sections]);

  const toggle = (key: string) =>
    setPicked((p) => {
      const next = new Set(p);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });

  const create = async () => {
    setBusy(true);
    setError(null);
    try {
      const created = await marks.createAssessment({
        kind,
        type,
        name: name.trim(),
        from,
        to: to < from ? from : to,
        papers: [...picked].map((k) => {
          const [section_id, subject_id] = k.split(':');
          return { section_id, subject_id, max_marks: Number(max) };
        }),
      });
      void queryClient.invalidateQueries({ queryKey: ['marks'] });
      void navigate(`/tests/${created.id}`, { replace: true });
    } catch (e) {
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  const types = kind === 'class_test' ? ['weekly', 'monthly'] : ['periodic', 'half_yearly', 'annual', 'practical', 'project', 'internal', 'weekly', 'monthly'];

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-4">
      <h1 className="text-2xl font-semibold tracking-tight">{kind === 'exam' ? t('tests.new.titleExam') : t('tests.new.titleTest')}</h1>
      <Card className="flex flex-col gap-4">
        {canExam && holds(me, 'tests.create') ? (
          <Field label={t('tests.new.kind')}>
            {(f) => (
              <Select
                {...f}
                value={kind}
                onChange={(e) => {
                  const k = e.target.value as 'exam' | 'class_test';
                  setKind(k);
                  setType(k === 'exam' ? 'periodic' : 'weekly');
                }}
              >
                <option value="exam">{t('tests.new.exam')}</option>
                <option value="class_test">{t('tests.classTest')}</option>
              </Select>
            )}
          </Field>
        ) : null}
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t('tests.new.name')}>{(f) => <Input {...f} value={name} maxLength={80} onChange={(e) => setName(e.target.value)} />}</Field>
          <Field label={t('tests.new.type')}>
            {(f) => (
              <Select {...f} value={type} onChange={(e) => setType(e.target.value)}>
                {types.map((x) => (
                  <option key={x} value={x}>
                    {t(`tests.types.${x}`)}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        </div>
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label={t('leave.from')}>{(f) => <Input {...f} type="date" value={from} onChange={(e) => setFrom(e.target.value)} />}</Field>
          <Field label={t('leave.to')}>{(f) => <Input {...f} type="date" value={to < from ? from : to} min={from} onChange={(e) => setTo(e.target.value)} />}</Field>
          <Field label={t('tests.maxMarks')}>{(f) => <Input {...f} type="number" min={1} max={1000} value={max} onChange={(e) => setMax(e.target.value)} />}</Field>
        </div>
        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 text-sm font-medium">{t('tests.new.papers')}</legend>
          {options.length === 0 ? <p className="text-sm text-muted">{t('tests.new.noPapers')}</p> : null}
          <div className="grid max-h-72 gap-1 overflow-y-auto sm:grid-cols-2">
            {options.map((o) => (
              <label key={o.key} className="flex min-h-11 items-center gap-2 rounded-input px-2 text-sm hover:bg-surface-muted">
                <input type="checkbox" className="size-4" checked={picked.has(o.key)} onChange={() => toggle(o.key)} />
                {o.label}
              </label>
            ))}
          </div>
        </fieldset>
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button asChild variant="outline" className="h-11">
            <Link to="/tests">{t('common.cancel')}</Link>
          </Button>
          <Button className="h-11" disabled={busy || name.trim().length < 2 || picked.size === 0 || !(Number(max) > 0)} onClick={() => void create()}>
            {t('tests.new.create')}
          </Button>
        </div>
      </Card>
    </div>
  );
}

/** One assessment: my papers with their states, bulk lock and publish, and the completion grid. */
export function AssessmentPage() {
  const { t, i18n } = useTranslation();
  const { id = '' } = useParams();
  const queryClient = useQueryClient();
  const detail = useQuery({ queryKey: ['marks', 'assessment', id], queryFn: apiGet<AssessmentDetail>(`/assessments/${id}`), retry: false });
  const grid = useQuery({ queryKey: ['marks', 'completion', id], queryFn: apiGet<Completion>(`/assessments/${id}/completion`), retry: false });
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const papers = detail.data?.papers ?? [];

  const bulk = async (move: 'open' | 'lock' | 'publish') => {
    setError(null);
    try {
      await marks.assessmentMove(id, move);
      setDone(t(`tests.done.${move}All`));
      void queryClient.invalidateQueries({ queryKey: ['marks'] });
    } catch (e) {
      setError(errorText(t, e));
    }
  };

  if (detail.isPending) return <Spinner label={t('common.loading')} />;
  if (detail.error || !detail.data) return <Alert tone="danger">{errorText(t, detail.error)}</Alert>;
  const a = detail.data;

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-4">
      <Link to="/tests" className="text-sm font-medium text-primary hover:underline">
        {t('tests.back')}
      </Link>
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold tracking-tight">{a.name}</h1>
        <p className="text-sm text-muted">
          {t(`tests.types.${a.type}`)}
          {a.kind === 'class_test' ? ` · ${t('tests.classTest')}` : ''}
          {a.term ? ` · ${a.term}` : ''} · {formatDate(asDate(a.from), i18n.language)}
          {a.to !== a.from ? ` – ${formatDate(asDate(a.to), i18n.language)}` : ''}
          {a.entry_closes_at ? ` · ${t('tests.closes')} ${formatDateTime(a.entry_closes_at, i18n.language)}` : ''}
        </p>
      </div>
      {done ? <Alert tone="success">{done}</Alert> : null}
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <div className="flex flex-wrap gap-2">
        {papers.some((p) => p.can.open) ? (
          <Button className="h-11" onClick={() => void bulk('open')}>
            {t('tests.actions.openAll')}
          </Button>
        ) : null}
        {papers.some((p) => p.can.lock) ? (
          <Button className="h-11" onClick={() => void bulk('lock')}>
            {t('tests.actions.lockAll')}
          </Button>
        ) : null}
        {papers.some((p) => p.can.publish) ? (
          <Button className="h-11" onClick={() => void bulk('publish')}>
            {t('tests.actions.publishAll')}
          </Button>
        ) : null}
      </div>

      <ul className="flex flex-col gap-2">
        {papers.map((p) => (
          <li key={p.id}>
            <Link to={`/tests/papers/${p.id}`} className="flex flex-wrap items-center gap-3 rounded-card border border-border bg-surface p-4 hover:bg-surface-muted focus-visible:outline-2 focus-visible:outline-ring">
              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold">
                  {p.section} · {p.subject}
                </p>
                <p className="text-xs text-muted">
                  {t('tests.enteredOf', { entered: p.entered, total: p.students })} · {t('tests.outOf', { max: p.max_marks })}
                </p>
              </div>
              <StatusChip status={paperChip(p)} label={t(`tests.chip.${paperChip(p)}`)} />
              <ChevronRight aria-hidden="true" className="size-4 text-muted rtl:rotate-180" />
            </Link>
          </li>
        ))}
      </ul>

      {grid.data && grid.data.sections.length > 0 ? (
        <section className="flex flex-col gap-2">
          <h2 className="text-lg font-semibold">{t('tests.completion')}</h2>
          <div tabIndex={0} role="region" aria-label={t('tests.completion')} className="relative overflow-x-auto rounded-card border border-border bg-surface focus-visible:outline-2 focus-visible:outline-ring">
            <table className="w-full text-sm">
              <thead className="bg-surface-muted text-xs text-muted">
                <tr>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('tests.section')}</th>
                  {grid.data.subjects.map((s) => (
                    <th key={s} scope="col" className="px-3 py-2 text-start font-medium">{s}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {grid.data.sections.map((row) => (
                  <tr key={row.section_id} className="border-t border-border">
                    <th scope="row" className="px-3 py-2 text-start font-medium">{row.section}</th>
                    {grid.data.subjects.map((s) => {
                      const cell = row.cells[s];
                      return (
                        <td key={s} className="px-3 py-2">
                          {cell ? (
                            <Link to={`/tests/papers/${cell.paper_id}`} className="flex flex-col gap-1 hover:underline">
                              <StatusChip status={paperChip(cell)} label={t(`tests.chip.${paperChip(cell)}`)} />
                              <span className="text-xs text-muted">{t('tests.enteredOf', { entered: cell.entered, total: cell.students })}</span>
                            </Link>
                          ) : (
                            <span className="text-muted">–</span>
                          )}
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      ) : null}
    </div>
  );
}

/** Corrections I may see; the Chief can filter to the self-approved ones. */
export function CorrectionsPage() {
  const { t, i18n } = useTranslation();
  const [params, setParams] = useSearchParams();
  const self = params.get('self_approved') === '1';
  const list = useQuery({
    queryKey: ['marks', 'corrections', self],
    queryFn: apiGetBody<{ data: Correction[] }>('/corrections', self ? { self_approved: 1 } : {}),
    retry: false,
  });

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4">
      <Link to="/tests" className="text-sm font-medium text-primary hover:underline">
        {t('tests.back')}
      </Link>
      <h1 className="text-2xl font-semibold tracking-tight">{t('tests.correction.listTitle')}</h1>
      <p className="text-sm text-muted">{t('tests.correction.rule')}</p>
      <label className="flex min-h-11 items-center gap-2 text-sm">
        <input type="checkbox" className="size-4" checked={self} onChange={(e) => setParams(e.target.checked ? { self_approved: '1' } : {}, { replace: true })} />
        {t('tests.correction.onlySelf')}
      </label>
      {list.isPending ? <Spinner label={t('common.loading')} /> : null}
      {list.error ? <Alert tone="danger">{errorText(t, list.error)}</Alert> : null}
      {list.data && list.data.data.length === 0 ? <EmptyState icon={<FileCheck2 className="size-5" />} title={t('tests.correction.none')} /> : null}
      <ul className="flex flex-col gap-2">
        {(list.data?.data ?? []).map((c) => (
          <li key={c.id} className="flex flex-col gap-1 rounded-card border border-border bg-surface p-4">
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-sm font-semibold">
                {c.label} · {c.student}
              </span>
              <span className="text-sm tabular-nums">
                {cellText(c.old) || '–'} → {cellText(c.new) || '–'}
              </span>
              {c.self_approved ? <StatusChip status="returned" label={t('approvals.flags.self_approved')} /> : null}
              <StatusChip status={c.status === 'pending' ? 'pending' : c.status} label={t(`tests.correction.status.${c.status}`)} className="ms-auto" />
            </div>
            <p className="text-sm">“{c.reason}”</p>
            <p className="text-xs text-muted">
              {t('tests.correction.askedBy', { name: c.requested_by ?? '' })} · {formatDateTime(c.requested_at, i18n.language)}
              {c.decided_by ? ` · ${t(`tests.correction.decidedBy.${c.status}`, { name: c.decided_by })} · ${formatDateTime(c.decided_at, i18n.language)}` : ''}
            </p>
            {c.decision_reason ? <p className="text-sm text-muted">“{c.decision_reason}”</p> : null}
          </li>
        ))}
      </ul>
    </div>
  );
}

/** Published marks only: a parent's children, or a student's own. */
export function ResultsPage() {
  const { t, i18n } = useTranslation();
  const me = useCurrentUser();
  const children = scopeOf(me).children;
  const [params, setParams] = useSearchParams();
  const chosen = params.get('student') ?? children[0] ?? null;
  const tab = params.get('tab') === 'cards' ? 'cards' : 'marks';
  const setTab = (next: 'marks' | 'cards') => setParams({ ...(chosen ? { student: chosen } : {}), ...(next === 'cards' ? { tab: 'cards' } : {}) }, { replace: true });
  const names = useQueries({ queries: children.map((id) => ({ queryKey: ['students', id], queryFn: apiGet<StudentProfile>(`/students/${id}`), retry: false })) });
  const results = useQuery({
    queryKey: ['marks', 'results', chosen ?? 'mine'],
    queryFn: apiGet<StudentResults>(chosen ? `/results/students/${chosen}` : '/results/mine'),
    retry: false,
    enabled: tab === 'marks',
  });

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4">
      <h1 className="text-2xl font-semibold tracking-tight">{t('tests.results.title')}</h1>
      {children.length > 1 ? (
        <div role="tablist" aria-label={t('tests.results.child')} className="flex flex-wrap gap-2">
          {children.map((c, i) => (
            <button
              key={c}
              type="button"
              role="tab"
              aria-selected={c === chosen}
              onClick={() => setParams({ student: c, ...(tab === 'cards' ? { tab: 'cards' } : {}) }, { replace: true })}
              className={`h-11 rounded-full border px-4 text-sm font-medium focus-visible:outline-2 focus-visible:outline-ring ${c === chosen ? 'border-primary bg-primary-soft text-primary' : 'border-border'}`}
            >
              {names[i]?.data?.name ?? t('tests.results.childN', { n: i + 1 })}
            </button>
          ))}
        </div>
      ) : null}
      <div role="tablist" aria-label={t('tests.results.view')} className="flex gap-1 border-b border-border">
        {(['marks', 'cards'] as const).map((k) => (
          <button
            key={k}
            type="button"
            role="tab"
            aria-selected={tab === k}
            onClick={() => setTab(k)}
            className={`-mb-px h-11 border-b-2 px-4 text-sm font-medium focus-visible:outline-2 focus-visible:outline-ring ${tab === k ? 'border-primary text-primary' : 'border-transparent text-muted'}`}
          >
            {t(`tests.results.tabs.${k}`)}
          </button>
        ))}
      </div>
      {tab === 'cards' ? <FamilyCards studentId={chosen} /> : null}
      {tab === 'marks' && results.isPending ? <Spinner label={t('common.loading')} /> : null}
      {tab === 'marks' && results.error ? <Alert tone="danger">{errorText(t, results.error)}</Alert> : null}
      {tab === 'marks' && results.data ? (
        <>
          <p className="text-sm text-muted">{t('tests.results.for', { name: results.data.student.name })}</p>
          {results.data.results.length === 0 ? <EmptyState icon={<ClipboardList className="size-5" />} title={t('tests.results.none')} /> : null}
          <ul className="flex flex-col gap-2">
            {results.data.results.map((r) => (
              <li key={r.paper_id} className="flex items-center gap-3 rounded-card border border-border bg-surface p-4">
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-semibold">{r.subject}</p>
                  <p className="text-xs text-muted">
                    {r.assessment}
                    {r.date ? ` · ${formatDate(asDate(r.date), i18n.language)}` : ''}
                  </p>
                </div>
                {r.updated_at ? (
                  <span className="rounded-full bg-status-pending-bg px-2 py-0.5 text-xs font-medium text-status-pending-fg" title={t('tests.results.updatedOn', { date: formatDate(asDate(r.updated_at.slice(0, 10)), i18n.language) })}>
                    {t('tests.results.updated')}
                  </span>
                ) : null}
                <p className="text-lg font-semibold tabular-nums">
                  {r.code ? t(`tests.codeNames.${r.code}`) : `${r.value ?? '–'} / ${r.max_marks}`}
                </p>
              </li>
            ))}
          </ul>
        </>
      ) : null}
    </div>
  );
}
