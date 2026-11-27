import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, KeyRound, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Dialog, Field, Input, Select, Spinner } from '@/components/ui/form';
import { errorText, fieldError } from '@/features/auth/errors';
import { apiGet } from '@/lib/api';
import { cn } from '@/lib/utils';
import {
  type CustomColumn,
  formatCustomValue,
  type Guardian,
  type GuardianInput,
  localLabel,
  peopleKeys,
  type StudentPatch,
  type StudentProfile,
  students,
} from './api';
import { Checkbox, CustomValueInput, PageHeader, StatusChip } from './ui';

type Tab = 'details' | 'guardians' | 'custom' | 'ids';

export function StudentProfilePage() {
  const { id = '' } = useParams();
  const { t } = useTranslation();
  const query = useQuery({ queryKey: peopleKeys.student(id), queryFn: apiGet<StudentProfile>(`/students/${id}`) });
  const [tab, setTab] = useState<Tab>('details');

  if (query.isPending) return <Spinner label={t('common.loading')} />;
  if (query.isError) return <Alert tone="danger">{errorText(t, query.error)}</Alert>;

  const student = query.data;
  const tabs: { id: Tab; label: string }[] = [
    { id: 'details', label: t('students.profile.tabs.details') },
    { id: 'guardians', label: t('students.profile.tabs.guardians') },
    { id: 'custom', label: t('students.profile.tabs.custom') },
    { id: 'ids', label: t('students.profile.tabs.ids') },
  ];

  return (
    <div className="flex flex-col gap-4">
      <Link to="/students" className="inline-flex items-center gap-1.5 text-sm text-primary underline-offset-2 hover:underline">
        <ArrowLeft aria-hidden="true" className="size-4 rtl:rotate-180" />
        {t('students.profile.back')}
      </Link>
      <PageHeader
        title={student.name}
        intro={[student.admission_no, student.section_label, student.roll_no ? t('students.profile.rollShort', { roll: student.roll_no }) : null].filter(Boolean).join(' · ')}
        actions={<StatusChip status={student.status === 'active' ? 'active' : 'inactive'} label={t(`students.status.${student.status}`, { defaultValue: student.status })} />}
      />

      <div role="tablist" aria-label={t('students.profile.tabsLabel')} className="flex gap-1 overflow-x-auto border-b border-border">
        {tabs.map((item) => (
          <button
            key={item.id}
            type="button"
            role="tab"
            id={`tab-${item.id}`}
            aria-selected={tab === item.id}
            aria-controls={`panel-${item.id}`}
            onClick={() => setTab(item.id)}
            className={cn(
              '-mb-px shrink-0 border-b-2 px-3 py-2 text-sm whitespace-nowrap',
              tab === item.id ? 'border-primary font-semibold text-primary' : 'border-transparent text-muted hover:text-fg',
            )}
          >
            {item.label}
          </button>
        ))}
      </div>

      <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`}>
        {tab === 'details' ? <DetailsTab student={student} /> : null}
        {tab === 'guardians' ? <GuardiansTab student={student} /> : null}
        {tab === 'custom' ? <CustomTab student={student} /> : null}
        {tab === 'ids' ? <IdsTab student={student} /> : null}
      </div>
    </div>
  );
}

/** Saves a change to the student and refreshes the profile and lists. */
function useStudentUpdate(id: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (body: StudentPatch) => students.update(id, body),
    onSuccess: async (profile) => {
      queryClient.setQueryData(peopleKeys.student(id), profile);
      await queryClient.invalidateQueries({ queryKey: ['students', 'list'] });
    },
  });
}

function DetailsTab({ student }: { student: StudentProfile }) {
  const { t } = useTranslation();
  const save = useStudentUpdate(student.id);
  const [form, setForm] = useState({
    name: student.name,
    dob: student.dob ?? '',
    gender: student.gender ?? 'female',
    roll_no: student.roll_no === null ? '' : String(student.roll_no),
  });

  if (!student.can.edit) {
    return (
      <Card>
        <dl className="grid gap-4 text-sm sm:grid-cols-2">
          <Detail label={t('students.form.name')} value={student.name} />
          <Detail label={t('students.form.admissionNo')} value={student.admission_no} />
          <Detail label={t('students.form.dob')} value={student.dob ?? '—'} />
          <Detail label={t('students.form.gender')} value={student.gender ? t(`students.gender.${student.gender}`) : '—'} />
          <Detail label={t('students.form.section')} value={student.section_label ?? '—'} />
          <Detail label={t('students.form.roll')} value={student.roll_no ?? '—'} />
          {student.login_name ? <Detail label={t('students.profile.loginName')} value={student.login_name} /> : null}
        </dl>
        <p className="mt-4 text-xs text-muted">{t('students.profile.readOnly')}</p>
      </Card>
    );
  }

  const submit = () => {
    // Only what changed: a class teacher may change the roll number and columns only.
    const body: StudentPatch = {};
    if (form.name.trim() !== student.name) body.name = form.name.trim();
    if (form.dob !== (student.dob ?? '')) body.dob = form.dob;
    if (form.gender !== student.gender) body.gender = form.gender;
    const roll = form.roll_no === '' ? null : Number(form.roll_no);
    if (roll !== student.roll_no) body.roll_no = roll;
    if (Object.keys(body).length > 0) save.mutate(body);
  };

  return (
    <Card>
      <form
        className="grid gap-4 sm:grid-cols-2"
        noValidate
        onSubmit={(e) => {
          e.preventDefault();
          submit();
        }}
      >
        {save.isError && !fieldError(save.error, 'name') ? <Alert tone="danger" className="sm:col-span-2">{errorText(t, save.error)}</Alert> : null}
        {save.isSuccess ? <Alert tone="success" className="sm:col-span-2">{t('students.profile.saved')}</Alert> : null}
        <Field label={t('students.form.name')} error={fieldError(save.error, 'name')}>
          {(p) => <Input {...p} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />}
        </Field>
        <Field label={t('students.form.admissionNo')} hint={t('students.profile.admissionFixed')}>
          {(p) => <Input {...p} value={student.admission_no} disabled />}
        </Field>
        <Field label={t('students.form.dob')} error={fieldError(save.error, 'dob')}>
          {(p) => <Input {...p} type="date" value={form.dob} onChange={(e) => setForm({ ...form, dob: e.target.value })} />}
        </Field>
        <Field label={t('students.form.gender')}>
          {(p) => (
            <Select {...p} value={form.gender} onChange={(e) => setForm({ ...form, gender: e.target.value as typeof form.gender })}>
              <option value="female">{t('students.gender.female')}</option>
              <option value="male">{t('students.gender.male')}</option>
              <option value="other">{t('students.gender.other')}</option>
            </Select>
          )}
        </Field>
        <Field label={t('students.form.section')}>{(p) => <Input {...p} value={student.section_label ?? ''} disabled />}</Field>
        <Field label={t('students.form.roll')} error={fieldError(save.error, 'roll_no')}>
          {(p) => <Input {...p} type="number" min={1} max={999} inputMode="numeric" value={form.roll_no} onChange={(e) => setForm({ ...form, roll_no: e.target.value })} />}
        </Field>
        <p className="text-xs text-muted sm:col-span-2">{t('students.profile.classTeacherNote')}</p>
        <div className="flex justify-end sm:col-span-2">
          <Button type="submit" disabled={save.isPending}>
            {t('common.save')}
          </Button>
        </div>
      </form>
    </Card>
  );
}

function Detail({ label, value }: { label: string; value: string | number }) {
  return (
    <div>
      <dt className="text-muted">{label}</dt>
      <dd className="font-medium">{value}</dd>
    </div>
  );
}

function GuardiansTab({ student }: { student: StudentProfile }) {
  const { t } = useTranslation();
  const [adding, setAdding] = useState(false);
  const [editing, setEditing] = useState<Guardian | null>(null);
  const can = student.can.manage_guardians;

  return (
    <div className="flex flex-col gap-3">
      <Alert tone="info">{t('students.guardians.parentLoginIntro')}</Alert>
      {student.guardians.length === 0 ? <Card>{t('students.guardians.empty')}</Card> : null}
      {student.guardians.map((g) => (
        <GuardianCard key={g.id} student={student} guardian={g} canManage={can} onEdit={() => setEditing(g)} />
      ))}
      {can ? (
        <div>
          <Button variant="outline" onClick={() => setAdding(true)}>
            <Plus aria-hidden="true" />
            {t('students.guardians.add')}
          </Button>
        </div>
      ) : null}
      {adding ? <GuardianDialog key="new" open studentId={student.id} onClose={() => setAdding(false)} /> : null}
      {editing ? <GuardianDialog key={editing.id} open studentId={student.id} guardian={editing} onClose={() => setEditing(null)} /> : null}
    </div>
  );
}

function GuardianCard({ student, guardian, canManage, onEdit }: { student: StudentProfile; guardian: Guardian; canManage: boolean; onEdit: () => void }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const toggle = useMutation({
    mutationFn: () => (guardian.parent_login ? students.disableParentLogin(guardian.id) : students.enableParentLogin(guardian.id)),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: peopleKeys.student(student.id) }),
  });
  const siblings = guardian.student_ids.length - 1;

  return (
    <Card className="flex flex-col gap-3">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="font-medium">{guardian.name}</p>
          <p className="text-sm text-muted">{[guardian.relation, guardian.phone, guardian.email].filter(Boolean).join(' · ') || '—'}</p>
          {siblings > 0 ? <p className="mt-1 text-xs text-muted">{t('students.guardians.siblings', { count: siblings })}</p> : null}
        </div>
        <StatusChip status={guardian.parent_login ? 'active' : 'inactive'} label={guardian.parent_login ? t('students.guardians.loginOn') : t('students.guardians.loginOff')} />
      </div>
      {toggle.isError ? <Alert tone="danger">{errorText(t, toggle.error)}</Alert> : null}
      {canManage ? (
        <div className="flex flex-wrap gap-2">
          <Button size="sm" variant="outline" onClick={onEdit}>
            <Pencil aria-hidden="true" />
            {t('students.guardians.edit')}
          </Button>
          <Button size="sm" variant={guardian.parent_login ? 'outline' : 'primary'} disabled={toggle.isPending} onClick={() => toggle.mutate()}>
            <KeyRound aria-hidden="true" />
            {guardian.parent_login ? t('students.guardians.turnOff') : t('students.guardians.turnOn')}
          </Button>
        </div>
      ) : null}
    </Card>
  );
}

function GuardianDialog({ open, studentId, guardian, onClose }: { open: boolean; studentId: string; guardian?: Guardian | null; onClose: () => void }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [form, setForm] = useState<GuardianInput>(() => ({
    name: guardian?.name ?? '',
    relation: guardian?.relation ?? '',
    phone: guardian?.phone ?? '',
    email: guardian?.email ?? '',
  }));

  const save = useMutation({
    mutationFn: (): Promise<unknown> => {
      const body: GuardianInput = {
        name: form.name.trim(),
        relation: form.relation?.trim() || null,
        phone: form.phone?.trim() || null,
        email: form.email?.trim() || null,
      };
      return guardian ? students.updateGuardian(guardian.id, body) : students.addGuardian(studentId, body);
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: peopleKeys.student(studentId) });
      onClose();
    },
  });

  return (
    <Dialog open={open} title={guardian ? t('students.guardians.edit') : t('students.guardians.add')} onClose={onClose}>
      <form
        className="flex flex-col gap-3"
        noValidate
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        {save.isError ? <Alert tone="danger">{errorText(t, save.error)}</Alert> : null}
        <Field label={t('students.guardians.name')} error={fieldError(save.error, 'name')}>
          {(p) => <Input {...p} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />}
        </Field>
        <Field label={t('students.guardians.relation')}>
          {(p) => <Input {...p} value={form.relation ?? ''} onChange={(e) => setForm({ ...form, relation: e.target.value })} placeholder={t('students.guardians.relationHint')} />}
        </Field>
        <Field label={t('students.guardians.phone')} hint={t('students.guardians.phoneHint')} error={fieldError(save.error, 'phone')}>
          {(p) => <Input {...p} type="tel" value={form.phone ?? ''} onChange={(e) => setForm({ ...form, phone: e.target.value })} placeholder="+91…" />}
        </Field>
        <Field label={t('students.guardians.email')} error={fieldError(save.error, 'email')}>
          {(p) => <Input {...p} type="email" value={form.email ?? ''} onChange={(e) => setForm({ ...form, email: e.target.value })} />}
        </Field>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={save.isPending || form.name.trim() === ''}>
            {t('common.save')}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

const MASKED = '••••';

function CustomTab({ student }: { student: StudentProfile }) {
  const { t, i18n } = useTranslation();
  const save = useStudentUpdate(student.id);
  const fillable = student.columns.filter((c) => c.can_fill);
  const readOnly = student.columns.filter((c) => !c.can_fill);
  const [values, setValues] = useState<Record<string, unknown>>(() =>
    Object.fromEntries(student.columns.map((c) => [c.key, student.custom[c.key] === MASKED ? null : student.custom[c.key]])),
  );
  const [changed, setChanged] = useState<Set<string>>(new Set());

  if (student.columns.length === 0) return <Card>{t('students.custom.empty')}</Card>;

  const set = (column: CustomColumn, value: unknown) => {
    setValues({ ...values, [column.key]: value });
    setChanged(new Set(changed).add(column.key));
  };

  return (
    <Card>
      <form
        className="flex flex-col gap-4"
        noValidate
        onSubmit={(e) => {
          e.preventDefault();
          const custom = Object.fromEntries([...changed].map((key) => [key, values[key] ?? null]));
          if (Object.keys(custom).length > 0) save.mutate({ custom }, { onSuccess: () => setChanged(new Set()) });
        }}
      >
        {save.isError ? <Alert tone="danger">{errorText(t, save.error)}</Alert> : null}
        {save.isSuccess ? <Alert tone="success">{t('students.profile.saved')}</Alert> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          {fillable.map((column) => (
            <Field
              key={column.key}
              label={localLabel(column.label, i18n.language)}
              hint={column.sensitive && student.custom[column.key] === MASKED ? t('students.custom.sensitiveHint') : undefined}
            >
              {(p) => <CustomValueInput {...p} column={column} value={values[column.key]} onChange={(v) => set(column, v)} />}
            </Field>
          ))}
        </div>
        {readOnly.length > 0 ? (
          <dl className="grid gap-3 border-t border-border pt-4 text-sm sm:grid-cols-2">
            {readOnly.map((column) => (
              <Detail key={column.key} label={localLabel(column.label, i18n.language)} value={formatCustomValue(student.custom[column.key], t('common.yes'), t('common.no'))} />
            ))}
          </dl>
        ) : null}
        {fillable.length > 0 ? (
          <div className="flex justify-end">
            <Button type="submit" disabled={save.isPending || changed.size === 0}>
              {t('common.save')}
            </Button>
          </div>
        ) : (
          <p className="text-xs text-muted">{t('students.custom.noneFillable')}</p>
        )}
      </form>
    </Card>
  );
}

const OTHER_CONSENTS = ['photo_public', 'third_party_sharing'] as const;

function IdsTab({ student }: { student: StudentProfile }) {
  const { t } = useTranslation();
  const save = useStudentUpdate(student.id);
  const given = (purpose: string) => student.consents[purpose]?.given === true;
  const [form, setForm] = useState({
    pen: student.ids?.pen ?? '',
    apaar: student.ids?.apaar ?? '',
    aadhaar_last4: student.ids?.aadhaar_last4 ?? '',
    consents: Object.fromEntries(['pen', 'apaar', ...OTHER_CONSENTS].map((p) => [p, given(p)])) as Record<string, boolean>,
  });

  if (student.ids === null || !student.can.edit) {
    return (
      <Card className="flex flex-col gap-3">
        <p className="text-sm text-muted">{t('students.ids.officeOnly')}</p>
        <ConsentList student={student} />
      </Card>
    );
  }

  const consent = (purpose: string, value: boolean) => setForm({ ...form, consents: { ...form.consents, [purpose]: value } });

  return (
    <Card>
      <form
        className="flex flex-col gap-4"
        noValidate
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate({
            ids: { pen: form.pen.trim() || null, apaar: form.apaar.trim() || null, aadhaar_last4: form.aadhaar_last4 || null },
            consents: form.consents,
          });
        }}
      >
        {save.isError ? <Alert tone="danger">{errorText(t, save.error)}</Alert> : null}
        {save.isSuccess ? <Alert tone="success">{t('students.profile.saved')}</Alert> : null}
        <p className="text-sm text-muted">{t('students.ids.consentIntro')}</p>
        <IdFields
          values={form}
          onChange={(next) => setForm({ ...form, ...next })}
          consents={form.consents}
          onConsent={consent}
        />
        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 text-sm font-medium">{t('students.ids.otherConsents')}</legend>
          {OTHER_CONSENTS.map((p) => (
            <Checkbox key={p} label={t(`students.ids.consent.${p}`)} checked={form.consents[p] ?? false} onChange={(e) => consent(p, e.target.checked)} />
          ))}
        </fieldset>
        <ConsentList student={student} />
        <div className="flex justify-end">
          <Button type="submit" disabled={save.isPending}>
            {t('common.save')}
          </Button>
        </div>
      </form>
    </Card>
  );
}

/** PEN and APAAR with their consent boxes, and the Aadhaar last four digits. Shared with the admit form. */
export function IdFields({
  values,
  onChange,
  consents,
  onConsent,
}: {
  values: { pen: string; apaar: string; aadhaar_last4: string };
  onChange: (next: Partial<{ pen: string; apaar: string; aadhaar_last4: string }>) => void;
  consents: Record<string, boolean>;
  onConsent: (purpose: 'pen' | 'apaar', given: boolean) => void;
}) {
  const { t } = useTranslation();

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      {(['pen', 'apaar'] as const).map((kind) => (
        <div key={kind} className="flex flex-col gap-2">
          <Field label={t(`students.ids.${kind}`)} hint={t('students.ids.idHint')}>
            {(p) => (
              <Input
                {...p}
                value={values[kind]}
                maxLength={20}
                autoComplete="off"
                onChange={(e) => onChange({ [kind]: e.target.value.replace(/[^A-Za-z0-9]/g, '') })}
                disabled={!consents[kind]}
              />
            )}
          </Field>
          <Checkbox label={t(`students.ids.consent.${kind}`)} checked={consents[kind] ?? false} onChange={(e) => onConsent(kind, e.target.checked)} />
        </div>
      ))}
      <Field label={t('students.ids.aadhaarLast4')} hint={t('students.ids.aadhaarHint')} className="sm:col-span-2 sm:max-w-xs">
        {(p) => (
          <Input
            {...p}
            value={values.aadhaar_last4}
            inputMode="numeric"
            maxLength={4}
            pattern="[0-9]{4}"
            autoComplete="off"
            onChange={(e) => onChange({ aadhaar_last4: e.target.value.replace(/\D/g, '').slice(0, 4) })}
          />
        )}
      </Field>
    </div>
  );
}

function ConsentList({ student }: { student: StudentProfile }) {
  const { t } = useTranslation();
  const recorded = Object.entries(student.consents).filter(([, c]) => c !== null);
  if (recorded.length === 0) return <p className="text-xs text-muted">{t('students.ids.noConsents')}</p>;

  return (
    <ul className="flex flex-col gap-1 text-xs text-muted">
      {recorded.map(([purpose, c]) => (
        <li key={purpose}>
          {t('students.ids.consentRecord', {
            purpose: t(`students.ids.consent.${purpose}`, { defaultValue: purpose }),
            state: c?.given ? t('students.ids.given') : t('students.ids.withdrawn'),
            at: c?.at ?? '—',
            by: c?.recorded_by ?? '—',
          })}
        </li>
      ))}
    </ul>
  );
}
