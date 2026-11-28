import { zodResolver } from '@hookform/resolvers/zod';
import { useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { z } from 'zod';
import { Button } from '@/components/ui/button';
import { Alert, Card, Field, Input, Select, Spinner } from '@/components/ui/form';
import { allSections, useClasses } from '@/features/academic/api';
import { errorText } from '@/features/auth/errors';
import { ApiError } from '@/lib/api';
import { type AdmitInput, type GuardianInput, peopleKeys, students, useSeats } from './api';
import { IdFields } from './StudentProfilePage';
import { PageHeader } from './ui';

export function AdmitStudentPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const classes = useClasses();
  const sections = allSections(classes.data);
  const seats = useSeats();
  const [secondGuardian, setSecondGuardian] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [pending, setPending] = useState<AdmitInput | null>(null);
  const [busy, setBusy] = useState(false);

  const optionalPhone = z.string().trim().regex(/^(\+?\d{10,15})?$/, t('students.form.phoneInvalid'));
  const optionalEmail = z.union([z.literal(''), z.email(t('students.form.emailInvalid'))]);
  const schema = z.object({
    admission_no: z.string().trim().min(1, t('students.form.required')).max(30),
    name: z.string().trim().min(2, t('students.form.nameRequired')).max(120),
    dob: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, t('students.form.dobRequired')),
    gender: z.enum(['female', 'male', 'other'], { message: t('students.form.required') }),
    section_id: z.string().min(1, t('students.form.sectionRequired')),
    roll_no: z.string().regex(/^(\d{1,3})?$/, t('students.form.rollInvalid')),
    g1_name: z.string().trim().min(1, t('students.form.guardianRequired')).max(120),
    g1_relation: z.string().trim().max(30),
    g1_phone: optionalPhone,
    g1_email: optionalEmail,
    g2_name: z.string().trim().max(120),
    g2_relation: z.string().trim().max(30),
    g2_phone: optionalPhone,
    g2_email: optionalEmail,
    pen: z.string().trim().max(20),
    apaar: z.string().trim().max(20),
    aadhaar_last4: z.string().regex(/^(\d{4})?$/, t('students.ids.aadhaarInvalid')),
  });
  type Values = z.infer<typeof schema>;

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      admission_no: '',
      name: '',
      dob: '',
      gender: 'female',
      section_id: '',
      roll_no: '',
      g1_name: '',
      g1_relation: '',
      g1_phone: '',
      g1_email: '',
      g2_name: '',
      g2_relation: '',
      g2_phone: '',
      g2_email: '',
      pen: '',
      apaar: '',
      aadhaar_last4: '',
    },
  });
  const err = form.formState.errors;
  const [pen, apaar, aadhaarLast4] = useWatch({ control: form.control, name: ['pen', 'apaar', 'aadhaar_last4'] });

  const admit = async (body: AdmitInput) => {
    setBusy(true);
    setError(null);
    try {
      const student = await students.admit(body);
      await queryClient.invalidateQueries({ queryKey: peopleKeys.students });
      navigate(`/students/${student.id}`, { replace: true });
    } catch (e) {
      if (e instanceof ApiError && e.code === 'possible_duplicate') setPending(body);
      else setPending(null);
      if (e instanceof ApiError && e.code === 'validation_failed') {
        for (const [field, messages] of Object.entries(e.fieldErrors)) {
          if (field in form.getValues() && messages[0]) form.setError(field as keyof Values, { message: messages[0] });
        }
      }
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  const submit = form.handleSubmit(async (v) => {
    const guardian = (prefix: 'g1' | 'g2'): GuardianInput => ({
      name: v[`${prefix}_name`],
      relation: v[`${prefix}_relation`] || null,
      phone: v[`${prefix}_phone`] || null,
      email: v[`${prefix}_email`] || null,
    });
    const guardians = [guardian('g1')];
    if (secondGuardian && v.g2_name) guardians.push(guardian('g2'));
    // PEN and APAAR come later, after the parent consents from their own account (Phase 9).
    const ids: NonNullable<AdmitInput['ids']> = {};
    if (v.aadhaar_last4) ids.aadhaar_last4 = v.aadhaar_last4;

    await admit({
      admission_no: v.admission_no,
      name: v.name,
      dob: v.dob,
      gender: v.gender,
      section_id: v.section_id,
      roll_no: v.roll_no ? Number(v.roll_no) : null,
      guardians,
      ids,
    });
  });

  const apiError = error instanceof ApiError ? error : null;
  const fieldErrorsShown = apiError?.code === 'validation_failed' && Object.keys(apiError.fieldErrors).some((f) => f in form.getValues());

  return (
    <div className="flex flex-col gap-4">
      <Link to="/students" className="inline-flex items-center gap-1.5 text-sm text-primary underline-offset-2 hover:underline">
        <ArrowLeft aria-hidden="true" className="size-4 rtl:rotate-180" />
        {t('students.profile.back')}
      </Link>
      <PageHeader title={t('students.admitTitle')} intro={t('students.admitIntro')} />

      {seats.data ? (
        <Alert tone={seats.data.warning ? 'warning' : 'info'}>
          {t('students.seats', { used: seats.data.used, limit: seats.data.limit, available: seats.data.available })}
          {seats.data.warning ? ` ${t('students.seatsWarning')}` : ''}
        </Alert>
      ) : null}

      {apiError?.code === 'seat_limit_reached' ? (
        <div role="alert" className="rounded-card border-2 border-danger bg-surface p-4">
          <p className="font-semibold text-danger">{t('students.seatLimitTitle')}</p>
          <p className="mt-1 text-fg">{apiError.message || t('auth.apiErrors.seat_limit_reached')}</p>
        </div>
      ) : null}

      {apiError?.code === 'possible_duplicate' && pending ? (
        <Alert tone="warning">
          <p>{t('students.duplicate.body', { admission: String(apiError.details.admission_no ?? '') })}</p>
          <div className="mt-3 flex flex-wrap gap-2">
            {typeof apiError.details.student_id === 'string' ? (
              <Button size="sm" variant="outline" asChild>
                <Link to={`/students/${apiError.details.student_id}`}>{t('students.duplicate.open')}</Link>
              </Button>
            ) : null}
            <Button size="sm" disabled={busy} onClick={() => void admit({ ...pending, confirm_not_duplicate: true })}>
              {t('students.duplicate.different')}
            </Button>
          </div>
        </Alert>
      ) : null}

      {error && apiError?.code !== 'seat_limit_reached' && apiError?.code !== 'possible_duplicate' && !fieldErrorsShown ? <Alert tone="danger">{errorText(t, error)}</Alert> : null}

      <form onSubmit={submit} noValidate className="flex flex-col gap-4">
        <Card className="grid gap-4 sm:grid-cols-2">
          <h2 className="text-lg font-semibold sm:col-span-2">{t('students.form.studentSection')}</h2>
          <Field label={t('students.form.admissionNo')} error={err.admission_no?.message}>
            {(p) => <Input {...p} {...form.register('admission_no')} autoComplete="off" />}
          </Field>
          <Field label={t('students.form.name')} error={err.name?.message}>
            {(p) => <Input {...p} {...form.register('name')} autoComplete="off" />}
          </Field>
          <Field label={t('students.form.dob')} error={err.dob?.message}>
            {(p) => <Input {...p} type="date" {...form.register('dob')} />}
          </Field>
          <Field label={t('students.form.gender')} error={err.gender?.message}>
            {(p) => (
              <Select {...p} {...form.register('gender')}>
                <option value="female">{t('students.gender.female')}</option>
                <option value="male">{t('students.gender.male')}</option>
                <option value="other">{t('students.gender.other')}</option>
              </Select>
            )}
          </Field>
          <Field label={t('students.form.section')} error={err.section_id?.message}>
            {(p) => (
              <Select {...p} {...form.register('section_id')}>
                <option value="">{classes.isPending ? t('common.loading') : t('students.form.chooseSection')}</option>
                {sections.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <Field label={t('students.form.roll')} error={err.roll_no?.message}>
            {(p) => <Input {...p} inputMode="numeric" {...form.register('roll_no')} />}
          </Field>
        </Card>

        <Card className="flex flex-col gap-4">
          <h2 className="text-lg font-semibold">{t('students.form.guardianSection')}</h2>
          <p className="text-sm text-muted">{t('students.form.guardianIntro')}</p>
          <GuardianFields prefix="g1" form={form} errors={err} />
          {secondGuardian ? (
            <>
              <div className="flex items-center justify-between gap-2 border-t border-border pt-4">
                <h3 className="font-medium">{t('students.form.secondGuardian')}</h3>
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  onClick={() => {
                    setSecondGuardian(false);
                    form.setValue('g2_name', '');
                  }}
                >
                  <Trash2 aria-hidden="true" />
                  {t('students.form.removeGuardian')}
                </Button>
              </div>
              <GuardianFields prefix="g2" form={form} errors={err} />
            </>
          ) : (
            <div>
              <Button type="button" size="sm" variant="outline" onClick={() => setSecondGuardian(true)}>
                <Plus aria-hidden="true" />
                {t('students.form.addSecondGuardian')}
              </Button>
            </div>
          )}
        </Card>

        <Card className="flex flex-col gap-4">
          <h2 className="text-lg font-semibold">{t('students.form.idsSection')}</h2>
          <p className="text-sm text-muted">{t('students.ids.consentIntro')}</p>
          <IdFields
            values={{ pen, apaar, aadhaar_last4: aadhaarLast4 }}
            onChange={(next) => {
              for (const [key, value] of Object.entries(next)) form.setValue(key as 'pen' | 'apaar' | 'aadhaar_last4', value ?? '', { shouldValidate: key === 'aadhaar_last4' });
            }}
            parentConsent={{ pen: false, apaar: false }}
          />
          {err.aadhaar_last4?.message ? <p className="text-sm text-danger">{err.aadhaar_last4.message}</p> : null}
        </Card>

        <div className="flex flex-wrap justify-end gap-2">
          <Button type="button" variant="outline" asChild>
            <Link to="/students">{t('common.cancel')}</Link>
          </Button>
          <Button type="submit" disabled={busy}>
            {busy ? <Spinner label={t('common.working')} className="text-on-solid" /> : t('students.form.submit')}
          </Button>
        </div>
      </form>
    </div>
  );
}

type GuardianForm = ReturnType<typeof useForm<{
  g1_name: string;
  g1_relation: string;
  g1_phone: string;
  g1_email: string;
  g2_name: string;
  g2_relation: string;
  g2_phone: string;
  g2_email: string;
}>>;

function GuardianFields({ prefix, form, errors }: { prefix: 'g1' | 'g2'; form: unknown; errors: Partial<Record<string, { message?: string }>> }) {
  const { t } = useTranslation();
  const register = (form as GuardianForm).register;

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <Field label={t('students.guardians.name')} error={errors[`${prefix}_name`]?.message}>
        {(p) => <Input {...p} {...register(`${prefix}_name`)} autoComplete="off" />}
      </Field>
      <Field label={t('students.guardians.relation')}>
        {(p) => <Input {...p} {...register(`${prefix}_relation`)} placeholder={t('students.guardians.relationHint')} />}
      </Field>
      <Field label={t('students.guardians.phone')} hint={t('students.guardians.phoneHint')} error={errors[`${prefix}_phone`]?.message}>
        {(p) => <Input {...p} type="tel" {...register(`${prefix}_phone`)} placeholder="+91…" />}
      </Field>
      <Field label={t('students.guardians.email')} error={errors[`${prefix}_email`]?.message}>
        {(p) => <Input {...p} type="email" {...register(`${prefix}_email`)} />}
      </Field>
    </div>
  );
}
