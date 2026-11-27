import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Alert, Card, Dialog, Field, Input, Select, Spinner } from '@/components/ui/form';
import { Switch } from '@/components/ui/switch';
import { errorText } from '@/features/auth/errors';
import { holds } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { formatDate } from '@/lib/format';
import { apiGetBody } from '@/lib/requests';
import { addDays, asDate, type Delegation, leave, type LeaveType, todayInIndia } from './api';

type Draft = Omit<LeaveType, 'id' | 'status'> & { id?: string; status?: LeaveType['status'] };

const blank: Draft = { code: '', name: '', quota: 12, sandwich: false, half_day: true, document_after_days: null };

/**
 * Leave settings for the Principal: the school's leave types (name, yearly
 * quota, the sandwich rule, half days, when a document is needed) and the
 * dated hand-over of leave decisions to a Vice Principal.
 */
export function LeaveSettingsPage() {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const queryClient = useQueryClient();
  const canTypes = holds(me, 'leave.manage_types');
  const canDelegate = holds(me, 'leave.delegate');
  const types = useQuery({ queryKey: ['leave', 'types', 'all'], queryFn: apiGetBody<{ data: LeaveType[] }>('/leave-types'), enabled: canTypes, retry: false });
  const delegations = useQuery({
    queryKey: ['leave', 'delegations'],
    queryFn: apiGetBody<{ data: Delegation[]; deputies: { id: string; name: string }[] }>('/leave/delegations'),
    enabled: canDelegate,
    retry: false,
  });
  const [editing, setEditing] = useState<Draft | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [deputy, setDeputy] = useState('');
  const [from, setFrom] = useState(todayInIndia());
  const [to, setTo] = useState(addDays(todayInIndia(), 6));
  const { i18n } = useTranslation();

  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['leave'] });
  const run = async (action: () => Promise<unknown>) => {
    setError(null);
    try {
      await action();
      refresh();
      return true;
    } catch (e) {
      setError(errorText(t, e));
      return false;
    }
  };

  const saveType = async () => {
    if (!editing) return;
    const body = { code: editing.code.trim().toUpperCase(), name: editing.name.trim(), quota: editing.quota, sandwich: editing.sandwich, half_day: editing.half_day, document_after_days: editing.document_after_days };
    const ok = await run(() => (editing.id ? leave.updateType(editing.id, body) : leave.createType(body)));
    if (ok) setEditing(null);
  };

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-5">
      <h1 className="text-2xl font-semibold tracking-tight">{t('leave.settings.title')}</h1>
      {error && !editing ? <Alert tone="danger">{error}</Alert> : null}

      {canTypes ? (
        <Card className="flex flex-col gap-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-lg font-semibold">{t('leave.settings.types')}</h2>
            <div className="flex flex-wrap gap-2">
              <Button variant="outline" className="h-11" onClick={() => void run(() => leave.starterTypes())}>
                {t('leave.settings.starter')}
              </Button>
              <Button className="h-11" onClick={() => setEditing({ ...blank })}>
                <Plus aria-hidden="true" />
                {t('leave.settings.addType')}
              </Button>
            </div>
          </div>
          <p className="text-sm text-muted">{t('leave.settings.typesHint')}</p>
          {types.isPending ? <Spinner label={t('common.loading')} /> : null}
          <ul className="flex flex-col divide-y divide-border">
            {(types.data?.data ?? []).map((ty) => (
              <li key={ty.id} className="flex flex-wrap items-center gap-3 py-3">
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-semibold">
                    {ty.name} <span className="font-normal text-muted">({ty.code})</span>
                    {ty.status === 'archived' ? <span className="ms-2 text-xs text-muted">{t('leave.settings.archived')}</span> : null}
                  </p>
                  <p className="text-xs text-muted">
                    {[
                      ty.quota === null ? t('leave.settings.noLimit') : t('leave.settings.quota', { count: ty.quota }),
                      ty.sandwich ? t('leave.settings.sandwichOn') : t('leave.settings.sandwichOff'),
                      ty.half_day ? t('leave.settings.halfDays') : null,
                      ty.document_after_days !== null ? t('leave.settings.documentAfter', { count: ty.document_after_days }) : null,
                    ]
                      .filter(Boolean)
                      .join(' · ')}
                  </p>
                </div>
                <Button variant="outline" size="sm" className="h-11" onClick={() => setEditing({ ...ty })}>
                  <Pencil aria-hidden="true" />
                  {t('leave.settings.edit')}
                  <span className="sr-only">: {ty.name}</span>
                </Button>
              </li>
            ))}
          </ul>
        </Card>
      ) : null}

      {canDelegate ? (
        <Card className="flex flex-col gap-3">
          <h2 className="text-lg font-semibold">{t('leave.settings.delegation')}</h2>
          <p className="text-sm text-muted">{t('leave.settings.delegationHint')}</p>
          {delegations.data && delegations.data.deputies.length === 0 ? <Alert tone="info">{t('leave.settings.noDeputies')}</Alert> : null}
          {delegations.data && delegations.data.deputies.length > 0 ? (
            <div className="grid gap-3 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-end">
              <Field label={t('leave.settings.deputy')}>
                {(f) => (
                  <Select {...f} value={deputy} onChange={(e) => setDeputy(e.target.value)}>
                    <option value="">{t('leave.settings.chooseDeputy')}</option>
                    {delegations.data.deputies.map((d) => (
                      <option key={d.id} value={d.id}>
                        {d.name}
                      </option>
                    ))}
                  </Select>
                )}
              </Field>
              <Field label={t('leave.from')}>{(f) => <Input {...f} type="date" value={from} onChange={(e) => setFrom(e.target.value)} />}</Field>
              <Field label={t('leave.to')}>{(f) => <Input {...f} type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />}</Field>
              <Button className="h-11" disabled={deputy === ''} onClick={() => void run(() => leave.delegate({ delegate_user_id: deputy, from, to }))}>
                {t('leave.settings.delegate')}
              </Button>
            </div>
          ) : null}
          <ul className="flex flex-col divide-y divide-border">
            {(delegations.data?.data ?? []).map((d) => (
              <li key={d.id} className="flex flex-wrap items-center gap-3 py-3">
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-semibold">{d.delegate}</p>
                  <p className="text-xs text-muted">
                    {t('leave.range', { from: formatDate(asDate(d.from), i18n.language), to: formatDate(asDate(d.to), i18n.language) })} · {t(`leave.settings.state.${d.state}`)}
                  </p>
                </div>
                {d.state === 'active' || d.state === 'upcoming' ? (
                  <Button variant="outline" size="sm" className="h-11" onClick={() => void run(() => leave.endDelegation(d.id))}>
                    {t('leave.settings.end')}
                    <span className="sr-only">: {d.delegate}</span>
                  </Button>
                ) : null}
              </li>
            ))}
          </ul>
        </Card>
      ) : null}

      <Dialog open={editing !== null} title={editing?.id ? t('leave.settings.editType') : t('leave.settings.addType')} onClose={() => setEditing(null)}>
        {editing ? (
          <div className="flex flex-col gap-4">
            <div className="grid grid-cols-[1fr_2fr] gap-3">
              <Field label={t('leave.settings.code')}>
                {(f) => <Input {...f} value={editing.code} disabled={Boolean(editing.id)} maxLength={10} onChange={(e) => setEditing({ ...editing, code: e.target.value.toUpperCase() })} />}
              </Field>
              <Field label={t('leave.settings.name')}>{(f) => <Input {...f} value={editing.name} maxLength={60} onChange={(e) => setEditing({ ...editing, name: e.target.value })} />}</Field>
            </div>
            <Field label={t('leave.settings.quotaLabel')} hint={t('leave.settings.quotaHint')}>
              {(f) => (
                <Input {...f} type="number" min={0} max={366} value={editing.quota ?? ''} onChange={(e) => setEditing({ ...editing, quota: e.target.value === '' ? null : Number(e.target.value) })} />
              )}
            </Field>
            <Field label={t('leave.settings.documentLabel')} hint={t('leave.settings.documentHint')}>
              {(f) => (
                <Input
                  {...f}
                  type="number"
                  min={0}
                  max={60}
                  value={editing.document_after_days ?? ''}
                  onChange={(e) => setEditing({ ...editing, document_after_days: e.target.value === '' ? null : Number(e.target.value) })}
                />
              )}
            </Field>
            <Switch label={t('leave.settings.sandwichLabel')} hint={t('leave.settings.sandwichHint')} checked={editing.sandwich} onChange={(v) => setEditing({ ...editing, sandwich: v })} />
            <Switch label={t('leave.settings.halfDayLabel')} checked={editing.half_day} onChange={(v) => setEditing({ ...editing, half_day: v })} />
            {editing.id ? (
              <Switch label={t('leave.settings.activeLabel')} checked={editing.status !== 'archived'} onChange={(v) => setEditing({ ...editing, status: v ? 'active' : 'archived' })} />
            ) : null}
            {error ? <Alert tone="danger">{error}</Alert> : null}
            <div className="flex justify-end gap-2">
              <Button variant="outline" className="h-11" onClick={() => setEditing(null)}>
                {t('common.cancel')}
              </Button>
              <Button
                className="h-11"
                disabled={editing.name.trim().length < 2 || editing.code.trim() === ''}
                onClick={() =>
                  void (editing.id
                    ? run(() => leave.updateType(editing.id as string, { name: editing.name, quota: editing.quota, sandwich: editing.sandwich, half_day: editing.half_day, document_after_days: editing.document_after_days, status: editing.status })).then(
                        (ok) => ok && setEditing(null),
                      )
                    : saveType())
                }
              >
                {t('common.save')}
              </Button>
            </div>
          </div>
        ) : null}
      </Dialog>
    </div>
  );
}
