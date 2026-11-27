import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Check, ChevronRight, Plus, Upload, UserX, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Dialog, Field, Input, Spinner, Textarea } from '@/components/ui/form';
import { errorText, fieldError } from '@/features/auth/errors';
import { holds } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { apiGet } from '@/lib/api';
import { cn } from '@/lib/utils';
import { type PasswordSetup, peopleKeys, staff, type StaffProfile, type StaffRow, useStaffList } from './api';
import { Checkbox, PageHeader, StatusChip } from './ui';

/** Roles a school can give staff. The server refuses any the person may not give. */
const STAFF_ROLES = ['principal', 'vice_principal', 'clerk', 'class_teacher', 'subject_teacher', 'staff', 'fee_collector'] as const;

export function StaffListPage() {
  const { t } = useTranslation();
  const me = useCurrentUser();
  // ?status=draft (the dashboard's approvals card) and ?new=1 (quick create) open straight on that view.
  const [params] = useSearchParams();
  const initial = params.get('status');
  const [filter, setFilter] = useState<'' | 'draft' | 'active' | 'inactive'>(initial === 'draft' || initial === 'active' || initial === 'inactive' ? initial : '');
  const list = useStaffList(filter);
  const [adding, setAdding] = useState(params.get('new') === '1');
  const [setup, setSetup] = useState<PasswordSetup | null>(null);
  const can = list.data?.can;

  const filters = [
    { id: '', label: t('staff.filter.all') },
    { id: 'draft', label: t('staff.filter.drafts') },
    { id: 'active', label: t('staff.filter.active') },
    { id: 'inactive', label: t('staff.filter.inactive') },
  ] as const;

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={t('staff.title')}
        actions={
          <>
            {holds(me, 'imports.staff') ? (
              <Button variant="outline" asChild>
                <Link to="/imports/staff">
                  <Upload aria-hidden="true" />
                  {t('staff.import')}
                </Link>
              </Button>
            ) : null}
            {can && (can.create || can.draft) ? (
              <Button onClick={() => setAdding(true)}>
                <Plus aria-hidden="true" />
                {can.create ? t('staff.add') : t('staff.draftForPrincipal')}
              </Button>
            ) : null}
          </>
        }
      />

      <div role="group" aria-label={t('staff.filter.label')} className="flex flex-wrap gap-2">
        {filters.map((f) => (
          <Button key={f.id} size="sm" variant={filter === f.id ? 'primary' : 'outline'} aria-pressed={filter === f.id} onClick={() => setFilter(f.id)}>
            {f.label}
          </Button>
        ))}
      </div>

      {list.isPending ? <Spinner label={t('common.loading')} /> : null}
      {list.isError ? <Alert tone="danger">{errorText(t, list.error)}</Alert> : null}
      {list.data?.data.length === 0 ? <Card>{filter === 'draft' ? t('staff.noDrafts') : t('staff.empty')}</Card> : null}
      {list.data && list.data.data.length > 0 ? (
        <ul className="flex flex-col divide-y divide-border rounded-card border border-border bg-surface">
          {list.data.data.map((s) => (
            <StaffListItem key={s.id} row={s} canApprove={can?.approve === true} onApproved={setSetup} />
          ))}
        </ul>
      ) : null}

      {adding && can ? <AddStaffDialog canCreate={can.create} onClose={() => setAdding(false)} onCreated={setSetup} /> : null}
      <SetupCodeDialog setup={setup} onClose={() => setSetup(null)} />
    </div>
  );
}

function StaffListItem({ row, canApprove, onApproved }: { row: StaffRow; canApprove: boolean; onApproved: (setup: PasswordSetup | null) => void }) {
  const { t } = useTranslation();
  const [rejecting, setRejecting] = useState(false);
  const queryClient = useQueryClient();
  const approve = useMutation({
    mutationFn: () => staff.approve(row.id),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: peopleKeys.staff });
      onApproved(result.password_setup);
    },
  });

  return (
    <li className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between">
      <Link to={`/staff/${row.id}`} className="flex min-w-0 flex-1 items-center justify-between gap-3 hover:text-primary">
        <div className="min-w-0">
          <p className="truncate font-medium">{row.name}</p>
          <p className="truncate text-xs text-muted">
            {[row.designation, row.employee_code, (row.roles.length > 0 ? row.roles : (row.requested_roles ?? [])).map((r) => t(`roles.${r}`, { defaultValue: r })).join(', ')]
              .filter(Boolean)
              .join(' · ')}
          </p>
          {row.status === 'draft' && row.drafted_by ? <p className="text-xs text-muted">{t('staff.draftedBy', { name: row.drafted_by.name })}</p> : null}
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <StatusChip status={row.status === 'draft' ? 'pending' : row.status} label={t(`staff.status.${row.status}`, { defaultValue: row.status })} />
          <ChevronRight aria-hidden="true" className="size-4 text-muted rtl:rotate-180" />
        </div>
      </Link>
      {row.status === 'draft' && canApprove ? (
        <div className="flex flex-wrap gap-2 sm:ms-3">
          <Button size="sm" disabled={approve.isPending} onClick={() => approve.mutate()}>
            <Check aria-hidden="true" />
            {t('staff.approve')}
          </Button>
          <Button size="sm" variant="outline" onClick={() => setRejecting(true)}>
            <X aria-hidden="true" />
            {t('staff.reject')}
          </Button>
        </div>
      ) : null}
      {approve.isError ? <Alert tone="danger">{errorText(t, approve.error)}</Alert> : null}
      {rejecting ? <RejectDialog row={row} onClose={() => setRejecting(false)} /> : null}
    </li>
  );
}

function RejectDialog({ row, onClose }: { row: StaffRow; onClose: () => void }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [reason, setReason] = useState('');
  const reject = useMutation({
    mutationFn: () => staff.reject(row.id, reason.trim()),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: peopleKeys.staff });
      onClose();
    },
  });

  return (
    <Dialog open title={t('staff.rejectTitle', { name: row.name })} onClose={onClose}>
      <form
        className="flex flex-col gap-3"
        noValidate
        onSubmit={(e) => {
          e.preventDefault();
          reject.mutate();
        }}
      >
        {reject.isError ? <Alert tone="danger">{errorText(t, reject.error)}</Alert> : null}
        <Field label={t('staff.rejectReason')} error={fieldError(reject.error, 'reason')}>
          {(p) => <Textarea {...p} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={300} />}
        </Field>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" variant="danger" disabled={reject.isPending || reason.trim().length < 3}>
            {t('staff.reject')}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

/**
 * Add staff. With staff.create the account is made at once; with only
 * staff.draft the request waits for the Principal.
 */
export function AddStaffDialog({ canCreate, onClose, onCreated }: { canCreate: boolean; onClose: () => void; onCreated: (setup: PasswordSetup | null) => void }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ name: '', email: '', phone: '', designation: '' });
  const [roles, setRoles] = useState<string[]>(['subject_teacher']);
  const create = useMutation({
    mutationFn: () =>
      staff.create({
        name: form.name.trim(),
        email: form.email.trim() || undefined,
        phone: form.phone.trim() || undefined,
        designation: form.designation.trim() || undefined,
        roles,
      }),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: peopleKeys.staff });
      onClose();
      onCreated(result.password_setup);
    },
  });
  const title = canCreate ? t('staff.add') : t('staff.draftForPrincipal');

  return (
    <Dialog open title={title} onClose={onClose} className="max-w-lg">
      <form
        className="flex flex-col gap-3"
        noValidate
        onSubmit={(e) => {
          e.preventDefault();
          create.mutate();
        }}
      >
        {!canCreate ? <Alert tone="info">{t('staff.draftExplain')}</Alert> : null}
        {create.isError ? <Alert tone="danger">{errorText(t, create.error)}</Alert> : null}
        <Field label={t('staff.form.name')} error={fieldError(create.error, 'name')}>
          {(p) => <Input {...p} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />}
        </Field>
        <Field label={t('staff.form.email')} error={fieldError(create.error, 'email')}>
          {(p) => <Input {...p} type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />}
        </Field>
        <Field label={t('staff.form.phone')} hint={t('staff.form.contactHint')} error={fieldError(create.error, 'phone')}>
          {(p) => <Input {...p} type="tel" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} placeholder="+91…" />}
        </Field>
        <Field label={t('staff.form.designation')}>
          {(p) => <Input {...p} value={form.designation} onChange={(e) => setForm({ ...form, designation: e.target.value })} />}
        </Field>
        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 text-sm font-medium">{t('staff.form.roles')}</legend>
          <div className="grid gap-2 sm:grid-cols-2">
            {STAFF_ROLES.map((r) => (
              <Checkbox
                key={r}
                label={t(`roles.${r}`)}
                checked={roles.includes(r)}
                onChange={(e) => setRoles(e.target.checked ? [...roles, r] : roles.filter((x) => x !== r))}
              />
            ))}
          </div>
        </fieldset>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={create.isPending || form.name.trim().length < 2}>
            {canCreate ? t('staff.form.create') : t('staff.form.sendDraft')}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

/** A password setup code, shown once to hand to the person. */
export function SetupCodeDialog({ setup, onClose }: { setup: PasswordSetup | null; onClose: () => void }) {
  const { t } = useTranslation();
  const open = setup !== null && setup.channel === 'code' && Boolean(setup.code);

  return (
    <Dialog open={open} title={t('staff.setupCode.title')} onClose={onClose} dismissible={false}>
      <Alert tone="warning">{t('staff.setupCode.warning')}</Alert>
      <p className="rounded-input border border-border bg-surface-muted p-3 text-center font-mono text-2xl tracking-widest" data-testid="setup-code">
        {setup?.code}
      </p>
      {setup?.expires_at ? <p className="text-xs text-muted">{t('staff.setupCode.expires', { at: new Date(setup.expires_at).toLocaleString() })}</p> : null}
      <div className="flex justify-end">
        <Button onClick={onClose}>{t('staff.setupCode.done')}</Button>
      </div>
    </Dialog>
  );
}

export function StaffProfilePage() {
  const { id = '' } = useParams();
  const { t } = useTranslation();
  const me = useCurrentUser();
  const queryClient = useQueryClient();
  const query = useQuery({ queryKey: peopleKeys.staffOne(id), queryFn: apiGet<StaffProfile>(`/staff/${id}`) });
  const [confirming, setConfirming] = useState(false);
  const [setup, setSetup] = useState<PasswordSetup | null>(null);
  const deactivate = useMutation({
    mutationFn: () => staff.deactivate(id),
    onSuccess: async (profile) => {
      queryClient.setQueryData(peopleKeys.staffOne(id), profile);
      await queryClient.invalidateQueries({ queryKey: peopleKeys.staff });
      setConfirming(false);
    },
  });
  const approve = useMutation({
    mutationFn: () => staff.approve(id),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: peopleKeys.staff });
      setSetup(result.password_setup);
    },
  });

  if (query.isPending) return <Spinner label={t('common.loading')} />;
  if (query.isError) return <Alert tone="danger">{errorText(t, query.error)}</Alert>;

  const s = query.data;
  const roleNames = (s.roles.length > 0 ? s.roles : (s.requested_roles ?? [])).map((r) => t(`roles.${r}`, { defaultValue: r }));

  return (
    <div className="flex flex-col gap-4">
      <Link to="/staff" className="inline-flex items-center gap-1.5 text-sm text-primary underline-offset-2 hover:underline">
        <ArrowLeft aria-hidden="true" className="size-4 rtl:rotate-180" />
        {t('staff.back')}
      </Link>
      <PageHeader
        title={s.name}
        intro={[s.designation, s.employee_code].filter(Boolean).join(' · ')}
        actions={
          <>
            <StatusChip status={s.status === 'draft' ? 'pending' : s.status} label={t(`staff.status.${s.status}`, { defaultValue: s.status })} />
            {s.status === 'draft' && holds(me, 'staff.approve') ? (
              <Button size="sm" disabled={approve.isPending} onClick={() => approve.mutate()}>
                <Check aria-hidden="true" />
                {t('staff.approve')}
              </Button>
            ) : null}
            {s.status === 'active' && holds(me, 'staff.deactivate') ? (
              <Button size="sm" variant="danger" onClick={() => setConfirming(true)}>
                <UserX aria-hidden="true" />
                {t('staff.deactivate')}
              </Button>
            ) : null}
          </>
        }
      />
      {approve.isError ? <Alert tone="danger">{errorText(t, approve.error)}</Alert> : null}

      <Card>
        <dl className="grid gap-4 text-sm sm:grid-cols-2">
          <ProfileItem label={t('staff.form.email')} value={s.email} />
          <ProfileItem label={t('staff.form.phone')} value={s.phone} />
          <ProfileItem label={t('staff.profile.type')} value={s.type ? t(`staff.type.${s.type}`) : null} />
          <ProfileItem label={t('staff.profile.joined')} value={s.joined_on} />
          <ProfileItem label={s.roles.length > 0 ? t('staff.form.roles') : t('staff.profile.requestedRoles')} value={roleNames.join(', ')} />
          <ProfileItem
            label={t('staff.profile.account')}
            value={s.account ? `${t(`staff.account.${s.account.status}`, { defaultValue: s.account.status })} · ${s.account.mfa ? t('staff.profile.mfaOn') : t('staff.profile.mfaOff')}` : t('staff.profile.noAccount')}
          />
          {s.wings && s.wings.length > 0 ? <ProfileItem label={t('staff.profile.wings')} value={s.wings.join(', ')} /> : null}
        </dl>
      </Card>

      <div className="grid gap-4 md:grid-cols-2">
        <Card>
          <h2 className="mb-2 font-semibold">{t('staff.profile.classTeacherOf')}</h2>
          {s.class_teacher_of.length === 0 ? <p className="text-sm text-muted">{t('common.none')}</p> : <p className="text-sm">{s.class_teacher_of.join(', ')}</p>}
        </Card>
        <Card>
          <h2 className="mb-2 font-semibold">{t('staff.profile.teaches')}</h2>
          {s.teaches.length === 0 ? (
            <p className="text-sm text-muted">{t('common.none')}</p>
          ) : (
            <ul className="flex flex-col gap-1 text-sm">
              {s.teaches.map((a) => (
                <li key={a.id}>
                  {a.subject_name} · {a.section_label}
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      <Dialog open={confirming} title={t('staff.deactivateTitle', { name: s.name })} onClose={() => setConfirming(false)}>
        <p className="text-sm text-muted">{t('staff.deactivateBody')}</p>
        {deactivate.isError ? <Alert tone="danger">{errorText(t, deactivate.error)}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={() => setConfirming(false)}>
            {t('common.cancel')}
          </Button>
          <Button variant="danger" disabled={deactivate.isPending} onClick={() => deactivate.mutate()}>
            {t('staff.deactivate')}
          </Button>
        </div>
      </Dialog>
      <SetupCodeDialog setup={setup} onClose={() => setSetup(null)} />
    </div>
  );
}

function ProfileItem({ label, value }: { label: string; value: string | null | undefined }) {
  return (
    <div>
      <dt className="text-muted">{label}</dt>
      <dd className={cn('font-medium', !value && 'text-muted')}>{value || '—'}</dd>
    </div>
  );
}
