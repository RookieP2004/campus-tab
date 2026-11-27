import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronRight, Copy, KeyRound, Plus, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Dialog, Field, Input, Select, Spinner, Textarea } from '@/components/ui/form';
import { DeviceSessionsPanel } from '@/features/auth/DeviceSessionsPage';
import { errorText } from '@/features/auth/errors';
import { ApiError } from '@/lib/api';
import { type AmendTerms, type ConsoleSchool, type ConsoleSchoolDetails, type IssueTerms, type LicenceSummary, consoleAuth, consoleSchools } from './api';
import { StepUpPrompt } from './ConsoleAuthPages';
import { ClientCard, GrantsCard, LicenceHistoryCard, PaymentsCard, RenewDialog, SecurityCard, UsageCard } from './ConsoleSchoolExtras';

const schoolsKey = ['console', 'schools'] as const;

function StatusChip({ status }: { status: string }) {
  const tone: Record<string, string> = {
    active: 'bg-status-approved-bg text-status-approved-fg',
    pending: 'bg-status-pending-bg text-status-pending-fg',
    issued: 'bg-status-pending-bg text-status-pending-fg',
    revoked: 'bg-status-rejected-bg text-status-rejected-fg',
    suspended: 'bg-status-locked-bg text-status-locked-fg',
    grace: 'bg-status-pending-bg text-status-pending-fg',
    read_only: 'bg-status-locked-bg text-status-locked-fg',
    inactive: 'bg-status-draft-bg text-status-draft-fg',
  };
  const { t } = useTranslation();

  return <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${tone[status] ?? tone.inactive}`}>{t(`console.status.${status}`, { defaultValue: status })}</span>;
}

export function ConsoleSchoolsPage() {
  const { t } = useTranslation();
  const query = useQuery({ queryKey: schoolsKey, queryFn: consoleSchools.list });
  const [creating, setCreating] = useState(false);

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">{t('console.schools.title')}</h1>
        <Button onClick={() => setCreating(true)}>
          <Plus aria-hidden="true" />
          {t('console.schools.new')}
        </Button>
      </div>
      {query.isPending ? <Spinner label={t('common.loading')} /> : null}
      {query.isError ? <Alert tone="danger">{errorText(t, query.error)}</Alert> : null}
      {query.data?.length === 0 ? <Card>{t('console.schools.empty')}</Card> : null}
      {query.data && query.data.length > 0 ? (
        <ul className="flex flex-col divide-y divide-border rounded-card border border-border bg-surface">
          {query.data.map((s) => (
            <li key={s.id}>
              <Link to={`/console/schools/${s.code}`} className="flex items-center justify-between gap-3 p-4 hover:bg-surface-muted">
                <div className="min-w-0">
                  <p className="truncate font-medium">{s.name}</p>
                  <p className="font-mono text-xs text-muted">{s.code}</p>
                  <p className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted">
                    {s.licence ? (
                      <span>
                        {s.licence.plan} · {t('console.list.until', { date: s.licence.valid_to })}
                      </span>
                    ) : null}
                    <span>{t('console.list.students', { used: s.usage.students ?? '—', limit: s.usage.student_seats ?? '—' })}</span>
                    <span>{t('console.list.staff', { used: s.usage.staff ?? '—', limit: s.usage.staff_seats ?? '—' })}</span>
                    <span>{t('console.list.active', { week: s.usage.active_7d ?? '—', month: s.usage.active_30d ?? '—' })}</span>
                    {s.licence ? <span>{s.licence.modules.join(', ')}</span> : null}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  {s.open_alerts > 0 ? <span className="rounded-full bg-status-rejected-bg px-2 py-0.5 text-xs font-medium text-status-rejected-fg">{t('console.list.alerts', { count: s.open_alerts })}</span> : null}
                  <StatusChip status={s.status} />
                  {s.licence ? <StatusChip status={s.licence.status === 'active' ? s.licence.state : s.licence.status} /> : null}
                  <ChevronRight aria-hidden="true" className="size-4 text-muted rtl:rotate-180" />
                </div>
              </Link>
            </li>
          ))}
        </ul>
      ) : null}
      <CreateSchoolDialog open={creating} onClose={() => setCreating(false)} />
    </div>
  );
}

function CreateSchoolDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ code: '', name: '', email: '', phone: '' });
  const create = useMutation({
    mutationFn: () => consoleSchools.create({ code: form.code.trim().toUpperCase(), name: form.name.trim(), email: form.email.trim() || undefined, phone: form.phone.trim() || undefined } as never),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: schoolsKey });
      setForm({ code: '', name: '', email: '', phone: '' });
      onClose();
    },
  });
  const fieldErrors = create.error instanceof ApiError ? create.error.fieldErrors : {};

  return (
    <Dialog open={open} title={t('console.schools.new')} onClose={onClose}>
      <form
        className="flex flex-col gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          create.mutate();
        }}
        noValidate
      >
        {create.isError && Object.keys(fieldErrors).length === 0 ? <Alert tone="danger">{errorText(t, create.error)}</Alert> : null}
        <Field label={t('console.schools.code')} hint={t('console.schools.codeHint')} error={fieldErrors.code?.[0]}>
          {(p) => <Input {...p} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} className="font-mono uppercase" />}
        </Field>
        <Field label={t('console.schools.name')} error={fieldErrors.name?.[0]}>
          {(p) => <Input {...p} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />}
        </Field>
        <Field label={t('console.schools.email')} hint={t('console.schools.contactHint')} error={fieldErrors.email?.[0]}>
          {(p) => <Input {...p} type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />}
        </Field>
        <Field label={t('console.schools.phone')} error={fieldErrors.phone?.[0]}>
          {(p) => <Input {...p} type="tel" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} placeholder="+91…" />}
        </Field>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={create.isPending}>
            {t('console.schools.create')}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

export function ConsoleSchoolPage() {
  const { code = '' } = useParams();
  const { t } = useTranslation();
  const key = ['console', 'school', code] as const;
  const query = useQuery({ queryKey: key, queryFn: consoleSchools.show(code) });

  if (query.isPending) return <Spinner label={t('common.loading')} />;
  if (query.isError) return <Alert tone="danger">{errorText(t, query.error)}</Alert>;

  const school = query.data;

  return (
    <div className="flex flex-col gap-4">
      <Link to="/console" className="text-sm text-primary underline-offset-2 hover:underline">
        {t('console.schools.back')}
      </Link>
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">{school.name}</h1>
            <p className="font-mono text-sm text-muted">{school.code}</p>
          </div>
          <StatusChip status={school.status} />
        </div>
        <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-3">
          <div>
            <dt className="text-muted">{t('console.schools.contact')}</dt>
            <dd>{[school.contact.email, school.contact.phone].filter(Boolean).join(' · ') || '—'}</dd>
          </div>
          <div>
            <dt className="text-muted">{t('console.client.fields.package_name')}</dt>
            <dd>{school.client?.package_name ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-muted">{t('console.client.fields.paid_until')}</dt>
            <dd>{school.client?.paid_until ?? '—'}</dd>
          </div>
        </dl>
      </Card>
      <LicenceCard school={school} />
      <UsageCard school={school} />
      <SecurityCard security={school.security} alerts={school.alerts} />
      <ClientCard key={JSON.stringify(school.client_record)} school={school} />
      <PaymentsCard school={school} />
      <LicenceHistoryCard school={school} />
      <GrantsCard school={school} />
      {school.status === 'active' ? <ChiefRecoveryCard school={school} /> : null}
      <ConsoleAuditList school={school.code} />
    </div>
  );
}

function useOptions() {
  return useQuery({ queryKey: ['console', 'options'], queryFn: consoleSchools.options, staleTime: Infinity });
}

function LicenceCard({ school }: { school: ConsoleSchoolDetails }) {
  const { t } = useTranslation();
  const licence = school.licence;
  const [mode, setMode] = useState<'issue' | 'amend' | 'revoke' | 'renew' | null>(null);
  const [newKey, setNewKey] = useState<string | null>(null);

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold">{t('console.licence.title')}</h2>
        <div className="flex flex-wrap gap-2">
          {!licence || licence.status === 'revoked' ? (
            <Button size="sm" onClick={() => setMode('issue')}>
              <KeyRound aria-hidden="true" />
              {t('console.licence.issue')}
            </Button>
          ) : (
            <>
              <Button size="sm" onClick={() => setMode('renew')}>
                {t('console.licence.renew')}
              </Button>
              <Button size="sm" variant="outline" onClick={() => setMode('amend')}>
                {t('console.licence.amend')}
              </Button>
              <Button size="sm" variant="danger" onClick={() => setMode('revoke')}>
                {t('console.licence.revoke')}
              </Button>
            </>
          )}
        </div>
      </div>
      {licence ? (
        <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-3">
          <div>
            <dt className="text-muted">{t('console.licence.key')}</dt>
            <dd className="font-mono">CTAB-…-{licence.key_last4}</dd>
          </div>
          <div>
            <dt className="text-muted">{t('console.licence.status')}</dt>
            <dd className="flex gap-1">
              <StatusChip status={licence.status} />
              {licence.status === 'active' ? <StatusChip status={licence.state} /> : null}
            </dd>
          </div>
          <div>
            <dt className="text-muted">{t('console.licence.plan')}</dt>
            <dd>{licence.plan}</dd>
          </div>
          <div>
            <dt className="text-muted">{t('console.licence.seats')}</dt>
            <dd>{t('console.licence.seatsValue', { students: licence.seats.students, staff: licence.seats.staff })}</dd>
          </div>
          <div>
            <dt className="text-muted">{t('console.licence.valid')}</dt>
            <dd>
              {licence.valid_from} → {licence.valid_to}
            </dd>
          </div>
          <div>
            <dt className="text-muted">{t('console.licence.modules')}</dt>
            <dd>{licence.modules.join(', ') || '—'}</dd>
          </div>
        </dl>
      ) : (
        <p className="mt-3 text-sm text-muted">{t('console.licence.none')}</p>
      )}
      <LicenceTermsDialog
        open={mode === 'issue' || mode === 'amend'}
        school={school}
        licence={mode === 'amend' ? licence : null}
        onClose={() => setMode(null)}
        onIssued={setNewKey}
      />
      <RevokeDialog open={mode === 'revoke'} school={school} onClose={() => setMode(null)} />
      <RenewDialog open={mode === 'renew'} school={school} onClose={() => setMode(null)} />
      <Dialog open={newKey !== null} title={t('console.licence.keyTitle')} dismissible={false}>
        <Alert tone="warning">{t('console.licence.keyOnce')}</Alert>
        <div className="flex items-center gap-2">
          <code className="flex-1 rounded-input border border-border bg-surface-muted px-3 py-2 font-mono text-base select-all" data-testid="licence-key">
            {newKey}
          </code>
          <Button variant="outline" size="icon" aria-label={t('common.copy')} onClick={() => void navigator.clipboard?.writeText(newKey ?? '')}>
            <Copy aria-hidden="true" />
          </Button>
        </div>
        <p className="text-sm text-muted">{t('console.licence.keyNext', { school: school.code })}</p>
        <div className="flex justify-end">
          <Button onClick={() => setNewKey(null)}>{t('console.licence.keySaved')}</Button>
        </div>
      </Dialog>
    </Card>
  );
}

function LicenceTermsDialog({
  open,
  school,
  licence,
  onClose,
  onIssued,
}: {
  open: boolean;
  school: ConsoleSchool;
  licence: LicenceSummary | null;
  onClose: () => void;
  onIssued: (key: string) => void;
}) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const options = useOptions();
  const today = new Date().toISOString().slice(0, 10);
  const [terms, setTerms] = useState(() => ({
    plan: licence?.plan ?? 'standard',
    modules: licence?.modules ?? null,
    students: licence?.seats.students ?? 500,
    staff: licence?.seats.staff ?? 50,
    valid_to: licence?.valid_to ?? '',
  }));
  const modules = terms.modules ?? options.data?.modules ?? [];

  const save = useMutation({
    mutationFn: async () => {
      const body = { plan: terms.plan, modules, students: Number(terms.students), staff: Number(terms.staff), ...(terms.valid_to ? { valid_to: terms.valid_to } : {}) } as IssueTerms;
      if (licence) return { amended: await consoleSchools.amend(school.code, body as AmendTerms) };
      return { issued: await consoleSchools.issue(school.code, { ...body, valid_from: today }) };
    },
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: ['console'] });
      onClose();
      if ('issued' in result && result.issued) onIssued(result.issued.key);
    },
  });

  return (
    <Dialog open={open} title={licence ? t('console.licence.amend') : t('console.licence.issue')} onClose={onClose}>
      {options.isPending ? (
        <Spinner label={t('common.loading')} />
      ) : (
        <form
          className="flex flex-col gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            save.mutate();
          }}
          noValidate
        >
          {save.isError ? <Alert tone="danger">{errorText(t, save.error)}</Alert> : null}
          <Field label={t('console.licence.plan')}>
            {(p) => (
              <Select {...p} value={terms.plan} onChange={(e) => setTerms({ ...terms, plan: e.target.value })}>
                {options.data?.plans.map((plan) => (
                  <option key={plan} value={plan}>
                    {plan}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          <fieldset className="flex flex-col gap-2">
            <legend className="mb-1 text-sm font-medium">{t('console.licence.modules')}</legend>
            <div className="grid grid-cols-2 gap-2">
              {options.data?.modules.map((m) => (
                <label key={m} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    className="size-4"
                    checked={modules.includes(m)}
                    onChange={(e) => setTerms({ ...terms, modules: e.target.checked ? [...modules, m] : modules.filter((x) => x !== m) })}
                  />
                  {m}
                </label>
              ))}
            </div>
          </fieldset>
          <div className="grid grid-cols-2 gap-3">
            <Field label={t('console.licence.students')}>
              {(p) => <Input {...p} type="number" min={1} value={terms.students} onChange={(e) => setTerms({ ...terms, students: Number(e.target.value) })} />}
            </Field>
            <Field label={t('console.licence.staff')}>
              {(p) => <Input {...p} type="number" min={1} value={terms.staff} onChange={(e) => setTerms({ ...terms, staff: Number(e.target.value) })} />}
            </Field>
          </div>
          <Field label={t('console.licence.validTo')} hint={licence ? undefined : t('console.licence.validToHint')}>
            {(p) => <Input {...p} type="date" value={terms.valid_to} min={today} onChange={(e) => setTerms({ ...terms, valid_to: e.target.value })} />}
          </Field>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onClose}>
              {t('common.cancel')}
            </Button>
            <Button type="submit" disabled={save.isPending}>
              {licence ? t('common.save') : t('console.licence.issue')}
            </Button>
          </div>
        </form>
      )}
    </Dialog>
  );
}

function RevokeDialog({ open, school, onClose }: { open: boolean; school: ConsoleSchool; onClose: () => void }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [typed, setTyped] = useState('');
  const [reason, setReason] = useState('');
  const [needStepUp, setNeedStepUp] = useState(false);
  const revoke = useMutation({
    mutationFn: () => consoleSchools.revoke(school.code, { confirm_code: typed, reason }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['console'] });
      setTyped('');
      setReason('');
      onClose();
    },
    onError: (e) => {
      if (e instanceof ApiError && e.code === 'step_up_required') setNeedStepUp(true);
    },
  });

  return (
    <Dialog open={open} title={t('console.licence.revokeTitle', { school: school.name })} onClose={onClose}>
      {needStepUp ? (
        <StepUpPrompt
          onCancel={() => setNeedStepUp(false)}
          onDone={() => {
            setNeedStepUp(false);
            revoke.mutate();
          }}
        />
      ) : (
        <form
          className="flex flex-col gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            revoke.mutate();
          }}
          noValidate
        >
          <Alert tone="danger">{t('console.licence.revokeWarning')}</Alert>
          {revoke.isError && !(revoke.error instanceof ApiError && revoke.error.code === 'step_up_required') ? (
            <Alert tone="danger">{errorText(t, revoke.error)}</Alert>
          ) : null}
          <Field label={t('console.licence.reason')}>
            {(p) => <Textarea {...p} value={reason} onChange={(e) => setReason(e.target.value)} />}
          </Field>
          <Field label={t('console.licence.typeCode', { code: school.code })}>
            {(p) => <Input {...p} value={typed} onChange={(e) => setTyped(e.target.value)} autoComplete="off" className="font-mono uppercase" />}
          </Field>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onClose}>
              {t('common.cancel')}
            </Button>
            <Button type="submit" variant="danger" disabled={revoke.isPending || typed.trim().toUpperCase() !== school.code || reason.trim().length < 5}>
              {t('console.licence.revoke')}
            </Button>
          </div>
        </form>
      )}
    </Dialog>
  );
}

function ChiefRecoveryCard({ school }: { school: ConsoleSchool }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [stage, setStage] = useState<'idle' | 'stepUp' | 'code' | 'done'>('idle');
  const [pending, setPending] = useState<'start' | 'complete' | null>(null);
  const [sentTo, setSentTo] = useState('');
  const [chief, setChief] = useState('');
  const [code, setCode] = useState('');
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);

  const run = async (what: 'start' | 'complete') => {
    setError(null);
    try {
      if (what === 'start') {
        const result = await consoleSchools.recoveryStart(school.code);
        setChief(result.chief.name);
        setSentTo([result.sent_to.email, result.sent_to.phone].filter(Boolean).join(', '));
        setStage('code');
      } else {
        await consoleSchools.recoveryComplete(school.code, { code: code.trim(), reason: reason.trim() });
        setStage('done');
        await queryClient.invalidateQueries({ queryKey: ['console'] });
      }
    } catch (e) {
      if (e instanceof ApiError && e.code === 'step_up_required') {
        setPending(what);
        setStage('stepUp');
        return;
      }
      setError(errorText(t, e));
    }
  };

  return (
    <Card>
      <div className="flex items-start gap-3">
        <ShieldAlert aria-hidden="true" className="mt-0.5 size-5 text-warning" />
        <div className="flex min-w-0 flex-1 flex-col gap-3">
          <h2 className="text-lg font-semibold">{t('console.recovery.title')}</h2>
          <p className="text-sm text-muted">{t('console.recovery.intro')}</p>
          {error ? <Alert tone="danger">{error}</Alert> : null}
          {stage === 'idle' ? (
            <div>
              <Button variant="outline" onClick={() => void run('start')}>
                {t('console.recovery.start')}
              </Button>
            </div>
          ) : null}
          {stage === 'stepUp' ? (
            <StepUpPrompt
              onCancel={() => setStage(pending === 'complete' ? 'code' : 'idle')}
              onDone={() => {
                setStage(pending === 'complete' ? 'code' : 'idle');
                void run(pending ?? 'start');
              }}
            />
          ) : null}
          {stage === 'code' ? (
            <form
              className="flex flex-col gap-3"
              onSubmit={(e) => {
                e.preventDefault();
                void run('complete');
              }}
              noValidate
            >
              <Alert tone="info">{t('console.recovery.sent', { chief, to: sentTo })}</Alert>
              <Field label={t('console.recovery.code')}>
                {(p) => <Input {...p} value={code} onChange={(e) => setCode(e.target.value)} inputMode="numeric" maxLength={6} autoComplete="off" className="font-mono tracking-widest" />}
              </Field>
              <Field label={t('console.recovery.reason')} hint={t('console.recovery.reasonHint')}>
                {(p) => <Textarea {...p} value={reason} onChange={(e) => setReason(e.target.value)} />}
              </Field>
              <div>
                <Button type="submit" variant="danger" disabled={!/^\d{6}$/.test(code.trim()) || reason.trim().length < 20}>
                  {t('console.recovery.complete')}
                </Button>
              </div>
            </form>
          ) : null}
          {stage === 'done' ? <Alert tone="success">{t('console.recovery.done')}</Alert> : null}
        </div>
      </div>
    </Card>
  );
}

export function ConsoleAuditList({ school }: { school?: string }) {
  const { t, i18n } = useTranslation();
  const query = useQuery({ queryKey: ['console', 'audit', school ?? 'all'], queryFn: consoleSchools.audit(school) });

  return (
    <Card>
      <h2 className="text-lg font-semibold">{t('console.audit.title')}</h2>
      {query.isPending ? <Spinner label={t('common.loading')} className="mt-3" /> : null}
      {query.isError ? <Alert tone="danger">{errorText(t, query.error)}</Alert> : null}
      <ul className="mt-3 flex flex-col divide-y divide-border text-sm">
        {query.data?.map((e) => (
          <li key={e.id} className="flex flex-col gap-0.5 py-2 sm:flex-row sm:justify-between">
            <span>
              <span className="font-mono text-xs">{e.action}</span>
              {e.school_code && !school ? <span className="ms-2 font-mono text-xs text-muted">{e.school_code}</span> : null}
            </span>
            <span className="text-xs text-muted">
              {e.actor.name ?? e.actor.type} · {new Intl.DateTimeFormat(i18n.language, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(e.at))}
            </span>
          </li>
        ))}
      </ul>
    </Card>
  );
}

export function ConsoleAuditPage() {
  return <ConsoleAuditList />;
}

export function ConsoleSessionsPage() {
  const { t } = useTranslation();

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-2xl font-semibold tracking-tight">{t('sessions.title')}</h1>
      <DeviceSessionsPanel queryKey={['console', 'sessions']} list={consoleAuth.sessions} revoke={consoleAuth.revokeSession} />
    </div>
  );
}
