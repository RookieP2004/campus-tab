import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, ImageUp, Plus, Trash2, Upload, UserPlus, X } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Field, Input, Select, Spinner } from '@/components/ui/form';
import { academicKeys, allSections, structure, useAssignments, useClasses, useSubjects, useYears } from '@/features/academic/api';
import { errorText, fieldError } from '@/features/auth/errors';
import { holds } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { peopleKeys, type PasswordSetup, useStaffList } from '@/features/people/api';
import { ImportFlow } from '@/features/people/ImportPage';
import { AddStaffDialog, SetupCodeDialog } from '@/features/people/StaffPages';
import { Checkbox, PageHeader, StatusChip, TableFrame, td, th } from '@/features/people/ui';
import { apiGet } from '@/lib/api';
import { send } from '@/lib/requests';
import { cn } from '@/lib/utils';

const STEPS = ['profile', 'year', 'classes', 'subjects', 'staff', 'students'] as const;
type Step = (typeof STEPS)[number];

/**
 * The school's first-time setup. Every step saves through the API and reads
 * the current state each time it opens, so it can be left and resumed.
 */
export function SetupWizardPage() {
  const { t } = useTranslation();
  const [params, setParams] = useSearchParams();
  const index = Math.min(Math.max(Number(params.get('step') ?? 1) || 1, 1), STEPS.length) - 1;
  const step: Step = STEPS[index] ?? 'profile';

  const go = (i: number) => {
    const next = new URLSearchParams();
    next.set('step', String(i + 1));
    setParams(next);
  };

  return (
    <div className="flex flex-col gap-4">
      <PageHeader title={t('setup.title')} intro={t('setup.intro')} />

      <nav aria-label={t('setup.stepsLabel')}>
        <ol className="flex gap-2 relative overflow-x-auto pb-1">
          {STEPS.map((s, i) => (
            <li key={s} className="shrink-0">
              <button
                type="button"
                onClick={() => go(i)}
                aria-current={i === index ? 'step' : undefined}
                className={cn(
                  'flex h-10 items-center gap-2 rounded-full border px-3 text-sm whitespace-nowrap',
                  i === index ? 'border-primary bg-primary-soft font-semibold text-primary' : 'border-border bg-surface text-fg hover:bg-surface-muted',
                )}
              >
                <span
                  className={cn(
                    'flex size-6 items-center justify-center rounded-full text-xs',
                    i < index ? 'bg-primary-solid text-on-solid' : i === index ? 'bg-primary-solid text-on-solid' : 'bg-surface-muted text-muted',
                  )}
                >
                  {i < index ? <Check aria-hidden="true" className="size-3.5" /> : i + 1}
                </span>
                {t(`setup.steps.${s}`)}
              </button>
            </li>
          ))}
        </ol>
      </nav>

      {step === 'profile' ? <ProfileStep /> : null}
      {step === 'year' ? <YearStep /> : null}
      {step === 'classes' ? <ClassesStep /> : null}
      {step === 'subjects' ? <SubjectsStep /> : null}
      {step === 'staff' ? <StaffStep /> : null}
      {step === 'students' ? (
        <ImportFlow
          entity="students"
          batchId={params.get('batch')}
          onBatch={(id) => {
            const next = new URLSearchParams(params);
            if (id) next.set('batch', id);
            else next.delete('batch');
            setParams(next, { replace: true });
          }}
        />
      ) : null}

      <div className="flex justify-between gap-2">
        <Button variant="outline" disabled={index === 0} onClick={() => go(index - 1)}>
          {t('common.back')}
        </Button>
        {index < STEPS.length - 1 ? (
          <Button onClick={() => go(index + 1)}>{t('common.next')}</Button>
        ) : (
          <Button asChild>
            <Link to="/students">{t('setup.finish')}</Link>
          </Button>
        )}
      </div>
    </div>
  );
}

function StepCard({ title, intro, children }: { title: string; intro?: string; children: ReactNode }) {
  return (
    <Card className="flex flex-col gap-4">
      <div>
        <h2 className="text-lg font-semibold">{title}</h2>
        {intro ? <p className="mt-1 text-sm text-muted">{intro}</p> : null}
      </div>
      {children}
    </Card>
  );
}

interface SchoolConfig {
  version: number;
  body: {
    school: { name: string; short_name: string; logo_url?: string | null; board: string; affiliation_no?: string | null };
    branding: { accent: string };
  } & Record<string, unknown>;
}

const BOARDS = ['CBSE', 'ICSE', 'STATE', 'IB', 'CAMBRIDGE', 'OTHER'];
const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

function ProfileStep() {
  const { t } = useTranslation();
  const config = useQuery({ queryKey: ['config'], queryFn: apiGet<SchoolConfig>('/config'), retry: false });

  if (config.isPending) return <Spinner label={t('common.loading')} />;
  if (config.isError) {
    return (
      <StepCard title={t('setup.profile.title')}>
        <Alert tone="info">{t('setup.profile.noAccess')}</Alert>
      </StepCard>
    );
  }

  return <ProfileForm key={config.data.version} config={config.data} />;
}

function ProfileForm({ config }: { config: SchoolConfig }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const school = config.body.school;
  const [form, setForm] = useState({
    name: school.name,
    short_name: school.short_name,
    board: school.board,
    affiliation_no: school.affiliation_no ?? '',
    accent: config.body.branding.accent,
  });
  const [logo, setLogo] = useState<File | null>(null);
  const [logoProblem, setLogoProblem] = useState<string | null>(null);

  const refresh = async () => {
    await queryClient.invalidateQueries({ queryKey: ['config'] });
    await queryClient.invalidateQueries({ queryKey: ['public-config'] });
  };

  const save = useMutation({
    mutationFn: () =>
      send('PUT', '/config', {
        // The whole body goes back, with only the school profile changed.
        body: {
          body: {
            ...config.body,
            school: { ...school, name: form.name.trim(), short_name: form.short_name.trim(), board: form.board, affiliation_no: form.affiliation_no.trim() || null },
            branding: { ...config.body.branding, accent: form.accent },
          },
          reason: 'Setup: school profile',
        },
        headers: { 'If-Match': `"v${config.version}"` },
      }),
    onSuccess: refresh,
  });

  const upload = useMutation({
    mutationFn: (file: File) => {
      const data = new FormData();
      data.append('file', file);
      return send<{ logo_url: string; version: number }>('POST', '/config/logo', { body: data });
    },
    onSuccess: async () => {
      setLogo(null);
      await refresh();
    },
  });

  const pickLogo = (file: File | null) => {
    setLogoProblem(null);
    setLogo(null);
    if (!file) return;
    if (!LOGO_TYPES.includes(file.type)) setLogoProblem(t('setup.profile.logoType'));
    else if (file.size > 512 * 1024) setLogoProblem(t('setup.profile.logoSize'));
    else setLogo(file);
  };

  return (
    <StepCard title={t('setup.profile.title')} intro={t('setup.profile.intro')}>
      <form
        className="grid gap-4 sm:grid-cols-2"
        noValidate
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        {save.isError ? <Alert tone="danger" className="sm:col-span-2">{errorText(t, save.error)}</Alert> : null}
        {save.isSuccess ? <Alert tone="success" className="sm:col-span-2">{t('setup.saved')}</Alert> : null}
        <Field label={t('setup.profile.name')} error={fieldError(save.error, 'body.school.name')}>
          {(p) => <Input {...p} value={form.name} maxLength={120} onChange={(e) => setForm({ ...form, name: e.target.value })} />}
        </Field>
        <Field label={t('setup.profile.shortName')}>
          {(p) => <Input {...p} value={form.short_name} maxLength={20} onChange={(e) => setForm({ ...form, short_name: e.target.value })} />}
        </Field>
        <Field label={t('setup.profile.board')}>
          {(p) => (
            <Select {...p} value={form.board} onChange={(e) => setForm({ ...form, board: e.target.value })}>
              {BOARDS.map((b) => (
                <option key={b} value={b}>
                  {t(`setup.profile.boards.${b}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t('setup.profile.affiliation')}>
          {(p) => <Input {...p} value={form.affiliation_no} maxLength={40} onChange={(e) => setForm({ ...form, affiliation_no: e.target.value })} />}
        </Field>
        <Field label={t('setup.profile.accent')} hint={t('setup.profile.accentHint')}>
          {(p) => (
            <div className="flex items-center gap-2">
              <input
                type="color"
                aria-label={t('setup.profile.accent')}
                value={form.accent}
                onChange={(e) => setForm({ ...form, accent: e.target.value.toUpperCase() })}
                className="h-11 w-14 shrink-0 rounded-input border border-border bg-surface"
              />
              <Input {...p} value={form.accent} maxLength={7} className="font-mono" onChange={(e) => setForm({ ...form, accent: e.target.value })} />
            </div>
          )}
        </Field>
        <div className="flex items-end justify-end sm:col-span-2">
          <Button type="submit" disabled={save.isPending}>
            {t('common.save')}
          </Button>
        </div>
      </form>

      <div className="flex flex-col gap-3 border-t border-border pt-4">
        <h3 className="font-medium">{t('setup.profile.logo')}</h3>
        {school.logo_url ? <img src={school.logo_url} alt={t('setup.profile.logoAlt')} className="size-16 rounded-card border border-border object-contain" /> : null}
        <form
          className="flex flex-col gap-3 sm:flex-row sm:items-end"
          onSubmit={(e) => {
            e.preventDefault();
            if (logo) upload.mutate(logo);
          }}
        >
          <Field label={t('setup.profile.logoFile')} hint={t('setup.profile.logoHint')} error={logoProblem ?? undefined} className="flex-1">
            {(p) => <Input {...p} type="file" accept="image/png,image/jpeg,image/webp" className="py-2" onChange={(e) => pickLogo(e.target.files?.[0] ?? null)} />}
          </Field>
          <Button type="submit" variant="outline" disabled={!logo || upload.isPending}>
            <ImageUp aria-hidden="true" />
            {t('setup.profile.upload')}
          </Button>
        </form>
        {upload.isError ? <Alert tone="danger">{errorText(t, upload.error)}</Alert> : null}
        {upload.isSuccess ? <Alert tone="success">{t('setup.profile.logoSaved')}</Alert> : null}
      </div>
    </StepCard>
  );
}

function YearStep() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const years = useYears();
  const [form, setForm] = useState({ name: '', starts_on: '', ends_on: '', make_current: true });
  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['academic'] });
  const create = useMutation({
    mutationFn: () => structure.createYear({ ...form, name: form.name.trim() }),
    onSuccess: async () => {
      setForm({ name: '', starts_on: '', ends_on: '', make_current: false });
      await invalidate();
    },
  });
  const makeCurrent = useMutation({ mutationFn: structure.makeCurrent, onSuccess: invalidate });

  return (
    <StepCard title={t('setup.year.title')} intro={t('setup.year.intro')}>
      {years.isPending ? <Spinner label={t('common.loading')} /> : null}
      {years.isError ? <Alert tone="danger">{errorText(t, years.error)}</Alert> : null}
      {years.data && years.data.length > 0 ? (
        <ul className="flex flex-col divide-y divide-border rounded-card border border-border">
          {years.data.map((y) => (
            <li key={y.id} className="flex flex-wrap items-center justify-between gap-2 p-3">
              <div>
                <p className="font-medium">{y.name}</p>
                <p className="text-xs text-muted">
                  {y.starts_on} – {y.ends_on}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <StatusChip status={y.status === 'current' ? 'active' : 'inactive'} label={t(`setup.year.status.${y.status}`)} />
                {y.status === 'planned' ? (
                  <Button size="sm" variant="outline" disabled={makeCurrent.isPending} onClick={() => makeCurrent.mutate(y.id)}>
                    {t('setup.year.makeCurrent')}
                  </Button>
                ) : null}
              </div>
            </li>
          ))}
        </ul>
      ) : null}
      {makeCurrent.isError ? <Alert tone="danger">{errorText(t, makeCurrent.error)}</Alert> : null}
      <form
        className="grid gap-3 sm:grid-cols-3"
        noValidate
        onSubmit={(e) => {
          e.preventDefault();
          create.mutate();
        }}
      >
        {create.isError ? <Alert tone="danger" className="sm:col-span-3">{errorText(t, create.error)}</Alert> : null}
        <Field label={t('setup.year.name')} hint={t('setup.year.nameHint')} error={fieldError(create.error, 'name')}>
          {(p) => <Input {...p} value={form.name} placeholder="2026-27" onChange={(e) => setForm({ ...form, name: e.target.value })} />}
        </Field>
        <Field label={t('setup.year.startsOn')} error={fieldError(create.error, 'starts_on')}>
          {(p) => <Input {...p} type="date" value={form.starts_on} onChange={(e) => setForm({ ...form, starts_on: e.target.value })} />}
        </Field>
        <Field label={t('setup.year.endsOn')} error={fieldError(create.error, 'ends_on')}>
          {(p) => <Input {...p} type="date" value={form.ends_on} onChange={(e) => setForm({ ...form, ends_on: e.target.value })} />}
        </Field>
        <Checkbox
          className="sm:col-span-2"
          label={t('setup.year.makeCurrentNew')}
          checked={form.make_current}
          onChange={(e) => setForm({ ...form, make_current: e.target.checked })}
        />
        <div className="flex justify-end">
          <Button type="submit" disabled={create.isPending || !form.name || !form.starts_on || !form.ends_on}>
            <Plus aria-hidden="true" />
            {t('setup.year.add')}
          </Button>
        </div>
      </form>
    </StepCard>
  );
}

function ClassesStep() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const classes = useClasses();
  const [form, setForm] = useState({ grade: '', name: '', wing: '', sections: 'A' });
  const [newSection, setNewSection] = useState<Record<string, string>>({});
  const invalidate = () => queryClient.invalidateQueries({ queryKey: academicKeys.classes });
  const create = useMutation({
    mutationFn: () =>
      structure.createClass({
        grade: form.grade.trim(),
        name: form.name.trim() || t('setup.classes.defaultName', { grade: form.grade.trim() }),
        ...(form.wing.trim() ? { wing: form.wing.trim() } : {}),
        sections: form.sections
          .split(/[,\s]+/)
          .map((s) => s.trim().toUpperCase())
          .filter(Boolean),
      }),
    onSuccess: async () => {
      setForm({ grade: '', name: '', wing: form.wing, sections: 'A' });
      await invalidate();
    },
  });
  const addSection = useMutation({
    mutationFn: (classId: string) => structure.createSection({ class_id: classId, name: (newSection[classId] ?? '').trim().toUpperCase() }),
    onSuccess: async (_, classId) => {
      setNewSection({ ...newSection, [classId]: '' });
      await invalidate();
    },
  });
  const removeSection = useMutation({ mutationFn: structure.deleteSection, onSuccess: invalidate });

  return (
    <StepCard title={t('setup.classes.title')} intro={t('setup.classes.intro')}>
      {classes.isPending ? <Spinner label={t('common.loading')} /> : null}
      {classes.isError ? <Alert tone="danger">{errorText(t, classes.error)}</Alert> : null}
      {addSection.isError ? <Alert tone="danger">{errorText(t, addSection.error)}</Alert> : null}
      {removeSection.isError ? <Alert tone="danger">{errorText(t, removeSection.error)}</Alert> : null}
      {classes.data && classes.data.length > 0 ? (
        <ul className="flex flex-col divide-y divide-border rounded-card border border-border">
          {classes.data.map((c) => (
            <li key={c.id} className="flex flex-col gap-2 p-3">
              <p className="font-medium">
                {c.name}
                {c.wing ? <span className="ms-2 text-xs text-muted">{c.wing}</span> : null}
              </p>
              <div className="flex flex-wrap items-center gap-2">
                {c.sections.map((s) => (
                  <span key={s.id} className="inline-flex items-center gap-1 rounded-full border border-border bg-surface-muted py-0.5 ps-2.5 pe-1 text-sm">
                    {s.label}
                    <button
                      type="button"
                      className="flex size-6 items-center justify-center rounded-full text-muted hover:bg-surface hover:text-danger"
                      aria-label={t('setup.classes.removeSection', { section: s.label })}
                      onClick={() => removeSection.mutate(s.id)}
                    >
                      <X aria-hidden="true" className="size-3.5" />
                    </button>
                  </span>
                ))}
                <form
                  className="flex items-center gap-1"
                  onSubmit={(e) => {
                    e.preventDefault();
                    if ((newSection[c.id] ?? '').trim()) addSection.mutate(c.id);
                  }}
                >
                  <Input
                    className="h-9 w-20"
                    aria-label={t('setup.classes.newSection', { class: c.name })}
                    placeholder={t('setup.classes.sectionPlaceholder')}
                    maxLength={10}
                    value={newSection[c.id] ?? ''}
                    onChange={(e) => setNewSection({ ...newSection, [c.id]: e.target.value })}
                  />
                  <Button type="submit" size="sm" variant="outline" aria-label={t('setup.classes.addSection', { class: c.name })}>
                    <Plus aria-hidden="true" />
                  </Button>
                </form>
              </div>
            </li>
          ))}
        </ul>
      ) : null}
      <form
        className="grid gap-3 sm:grid-cols-4"
        noValidate
        onSubmit={(e) => {
          e.preventDefault();
          create.mutate();
        }}
      >
        {create.isError ? <Alert tone="danger" className="sm:col-span-4">{errorText(t, create.error)}</Alert> : null}
        <Field label={t('setup.classes.grade')} hint={t('setup.classes.gradeHint')}>
          {(p) => <Input {...p} value={form.grade} maxLength={20} onChange={(e) => setForm({ ...form, grade: e.target.value })} />}
        </Field>
        <Field label={t('setup.classes.name')} hint={t('customFields.form.optional')}>
          {(p) => <Input {...p} value={form.name} maxLength={60} onChange={(e) => setForm({ ...form, name: e.target.value })} />}
        </Field>
        <Field label={t('setup.classes.wing')} hint={t('customFields.form.optional')}>
          {(p) => <Input {...p} value={form.wing} maxLength={40} onChange={(e) => setForm({ ...form, wing: e.target.value })} />}
        </Field>
        <Field label={t('setup.classes.sections')} hint={t('setup.classes.sectionsHint')}>
          {(p) => <Input {...p} value={form.sections} onChange={(e) => setForm({ ...form, sections: e.target.value })} />}
        </Field>
        <div className="flex justify-end sm:col-span-4">
          <Button type="submit" disabled={create.isPending || !form.grade.trim()}>
            <Plus aria-hidden="true" />
            {t('setup.classes.add')}
          </Button>
        </div>
      </form>
    </StepCard>
  );
}

function SubjectsStep() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const subjects = useSubjects();
  const [form, setForm] = useState({ code: '', name: '', kind: 'scholastic' as 'scholastic' | 'co_scholastic', elective: false });
  const create = useMutation({
    mutationFn: () => structure.createSubject({ ...form, code: form.code.trim().toUpperCase(), name: form.name.trim() }),
    onSuccess: async () => {
      setForm({ code: '', name: '', kind: form.kind, elective: false });
      await queryClient.invalidateQueries({ queryKey: academicKeys.subjects });
    },
  });

  return (
    <StepCard title={t('setup.subjects.title')} intro={t('setup.subjects.intro')}>
      {subjects.isPending ? <Spinner label={t('common.loading')} /> : null}
      {subjects.isError ? <Alert tone="danger">{errorText(t, subjects.error)}</Alert> : null}
      {subjects.data && subjects.data.length > 0 ? (
        <TableFrame>
          <thead>
            <tr>
              <th scope="col" className={th}>{t('setup.subjects.code')}</th>
              <th scope="col" className={th}>{t('setup.subjects.name')}</th>
              <th scope="col" className={th}>{t('setup.subjects.kind')}</th>
              <th scope="col" className={th}>{t('setup.subjects.elective')}</th>
            </tr>
          </thead>
          <tbody>
            {subjects.data.map((s) => (
              <tr key={s.id}>
                <td className={`${td} font-mono`}>{s.code}</td>
                <td className={td}>{s.name}</td>
                <td className={td}>{t(`setup.subjects.kinds.${s.kind}`)}</td>
                <td className={td}>{s.elective ? t('common.yes') : t('common.no')}</td>
              </tr>
            ))}
          </tbody>
        </TableFrame>
      ) : null}
      <form
        className="grid gap-3 sm:grid-cols-4"
        noValidate
        onSubmit={(e) => {
          e.preventDefault();
          create.mutate();
        }}
      >
        {create.isError ? <Alert tone="danger" className="sm:col-span-4">{errorText(t, create.error)}</Alert> : null}
        <Field label={t('setup.subjects.code')} error={fieldError(create.error, 'code')}>
          {(p) => <Input {...p} value={form.code} maxLength={20} className="font-mono uppercase" onChange={(e) => setForm({ ...form, code: e.target.value })} />}
        </Field>
        <Field label={t('setup.subjects.name')} error={fieldError(create.error, 'name')}>
          {(p) => <Input {...p} value={form.name} maxLength={80} onChange={(e) => setForm({ ...form, name: e.target.value })} />}
        </Field>
        <Field label={t('setup.subjects.kind')}>
          {(p) => (
            <Select {...p} value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value as typeof form.kind })}>
              <option value="scholastic">{t('setup.subjects.kinds.scholastic')}</option>
              <option value="co_scholastic">{t('setup.subjects.kinds.co_scholastic')}</option>
            </Select>
          )}
        </Field>
        <div className="flex items-end">
          <Checkbox label={t('setup.subjects.elective')} checked={form.elective} onChange={(e) => setForm({ ...form, elective: e.target.checked })} />
        </div>
        <div className="flex justify-end sm:col-span-4">
          <Button type="submit" disabled={create.isPending || !form.code.trim() || !form.name.trim()}>
            <Plus aria-hidden="true" />
            {t('setup.subjects.add')}
          </Button>
        </div>
      </form>
    </StepCard>
  );
}

function StaffStep() {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const queryClient = useQueryClient();
  const teachers = useStaffList('active');
  const classes = useClasses();
  const subjects = useSubjects();
  const assignments = useAssignments();
  const sections = allSections(classes.data);
  const [adding, setAdding] = useState(false);
  const [setup, setSetup] = useState<PasswordSetup | null>(null);
  const [pick, setPick] = useState({ section_id: '', subject_id: '', teacher_id: '' });
  const can = teachers.data?.can;

  const classTeacher = useMutation({
    mutationFn: ({ id, teacher }: { id: string; teacher: string }) => structure.updateSection(id, { class_teacher_id: teacher || null }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: academicKeys.classes }),
  });
  const assign = useMutation({
    mutationFn: () => structure.assign(pick),
    onSuccess: async () => {
      setPick({ ...pick, subject_id: '', teacher_id: '' });
      await queryClient.invalidateQueries({ queryKey: academicKeys.assignments });
    },
  });
  const unassign = useMutation({
    mutationFn: structure.unassign,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: academicKeys.assignments }),
  });

  const teacherOptions = (teachers.data?.data ?? []).map((s) => (
    <option key={s.id} value={s.id}>
      {s.name}
    </option>
  ));

  return (
    <>
      <StepCard title={t('setup.staff.title')} intro={t('setup.staff.intro')}>
        <div className="flex flex-wrap gap-2">
          {can && (can.create || can.draft) ? (
            <Button onClick={() => setAdding(true)}>
              <UserPlus aria-hidden="true" />
              {can.create ? t('staff.add') : t('staff.draftForPrincipal')}
            </Button>
          ) : null}
          {holds(me, 'imports.staff') ? (
            <Button variant="outline" asChild>
              <Link to="/imports/staff">
                <Upload aria-hidden="true" />
                {t('staff.import')}
              </Link>
            </Button>
          ) : null}
        </div>
        {teachers.data ? <p className="text-sm text-muted">{t('setup.staff.count', { count: teachers.data.data.length })}</p> : null}
      </StepCard>

      <StepCard title={t('setup.staff.classTeachers')} intro={t('setup.staff.classTeachersIntro')}>
        {classTeacher.isError ? <Alert tone="danger">{errorText(t, classTeacher.error)}</Alert> : null}
        {sections.length === 0 ? <p className="text-sm text-muted">{t('setup.staff.noSections')}</p> : null}
        {sections.length > 0 ? (
          <TableFrame>
            <thead>
              <tr>
                <th scope="col" className={th}>{t('students.columns.section')}</th>
                <th scope="col" className={th}>{t('setup.staff.classTeacher')}</th>
              </tr>
            </thead>
            <tbody>
              {sections.map((s) => (
                <tr key={s.id}>
                  <td className={td}>{s.label}</td>
                  <td className={td}>
                    <Select
                      className="h-9 min-w-48"
                      aria-label={t('setup.staff.classTeacherFor', { section: s.label })}
                      value={s.class_teacher_id ?? ''}
                      onChange={(e) => classTeacher.mutate({ id: s.id, teacher: e.target.value })}
                    >
                      <option value="">{t('common.none')}</option>
                      {teacherOptions}
                    </Select>
                  </td>
                </tr>
              ))}
            </tbody>
          </TableFrame>
        ) : null}
      </StepCard>

      <StepCard title={t('setup.staff.subjectTeachers')} intro={t('setup.staff.subjectTeachersIntro')}>
        <form
          className="grid gap-3 sm:grid-cols-4 sm:items-end"
          noValidate
          onSubmit={(e) => {
            e.preventDefault();
            assign.mutate();
          }}
        >
          {assign.isError ? <Alert tone="danger" className="sm:col-span-4">{errorText(t, assign.error)}</Alert> : null}
          <Field label={t('students.columns.section')}>
            {(p) => (
              <Select {...p} value={pick.section_id} onChange={(e) => setPick({ ...pick, section_id: e.target.value })}>
                <option value="">{t('students.form.chooseSection')}</option>
                {sections.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <Field label={t('setup.staff.subject')}>
            {(p) => (
              <Select {...p} value={pick.subject_id} onChange={(e) => setPick({ ...pick, subject_id: e.target.value })}>
                <option value="">{t('setup.staff.chooseSubject')}</option>
                {(subjects.data ?? []).map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <Field label={t('setup.staff.teacher')}>
            {(p) => (
              <Select {...p} value={pick.teacher_id} onChange={(e) => setPick({ ...pick, teacher_id: e.target.value })}>
                <option value="">{t('setup.staff.chooseTeacher')}</option>
                {teacherOptions}
              </Select>
            )}
          </Field>
          <Button type="submit" disabled={assign.isPending || !pick.section_id || !pick.subject_id || !pick.teacher_id}>
            <Plus aria-hidden="true" />
            {t('setup.staff.assign')}
          </Button>
        </form>
        {unassign.isError ? <Alert tone="danger">{errorText(t, unassign.error)}</Alert> : null}
        {assignments.data && assignments.data.length > 0 ? (
          <TableFrame>
            <thead>
              <tr>
                <th scope="col" className={th}>{t('students.columns.section')}</th>
                <th scope="col" className={th}>{t('setup.staff.subject')}</th>
                <th scope="col" className={th}>{t('setup.staff.teacher')}</th>
                <th scope="col" className={th}>
                  <span className="sr-only">{t('customFields.table.actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {assignments.data.map((a) => (
                <tr key={a.id}>
                  <td className={td}>{a.section_label}</td>
                  <td className={td}>{a.subject_name}</td>
                  <td className={td}>{a.teacher_name}</td>
                  <td className={td}>
                    <Button size="sm" variant="ghost" aria-label={t('setup.staff.remove')} onClick={() => unassign.mutate(a.id)}>
                      <Trash2 aria-hidden="true" />
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </TableFrame>
        ) : null}
      </StepCard>

      {adding && can ? (
        <AddStaffDialog
          canCreate={can.create}
          onClose={() => setAdding(false)}
          onCreated={async (s) => {
            setSetup(s);
            await queryClient.invalidateQueries({ queryKey: peopleKeys.staff });
          }}
        />
      ) : null}
      <SetupCodeDialog setup={setup} onClose={() => setSetup(null)} />
    </>
  );
}
