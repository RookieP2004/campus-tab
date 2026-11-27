import { EmptyState, type Status, StatusChip } from '@campus-tab/ui';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarDays, Check, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Dialog, Field, Input, Select, Spinner, Textarea } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { formatDate } from '@/lib/format';
import { apiGetBody } from '@/lib/requests';
import { calendar, type CalendarEntry, type CalendarList, monthRange } from './api';

const statusChip: Record<CalendarEntry['status'], Status> = { pending: 'pending', approved: 'approved', rejected: 'rejected' };

function thisMonth(): string {
  return new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', timeZone: 'Asia/Kolkata' }).format(new Date()).slice(0, 7);
}

/**
 * The holiday calendar for a month. The server decides what each person
 * sees (pending entries only for those who keep or approve it) and what
 * they may do (`can`); the screen shows exactly that.
 */
export function CalendarPage() {
  const { t, i18n } = useTranslation();
  const queryClient = useQueryClient();
  const [params, setParams] = useSearchParams();
  const monthId = useId();
  const month = params.get('month') ?? thisMonth();
  const { from, to } = monthRange(month);
  const list = useQuery({ queryKey: ['calendar', from, to], queryFn: apiGetBody<CalendarList>('/calendar', { from, to }), retry: false, refetchOnWindowFocus: true });
  const [editing, setEditing] = useState<CalendarEntry | 'new' | null>(null);
  const [rejecting, setRejecting] = useState<CalendarEntry | null>(null);
  const [error, setError] = useState<string | null>(null);
  const can = list.data?.can ?? { manage: false, approve: false };
  const entries = list.data?.data ?? [];

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['calendar'] });
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
  };

  const run = async (action: () => Promise<unknown>) => {
    setError(null);
    try {
      await action();
      refresh();
    } catch (e) {
      setError(errorText(t, e));
    }
  };

  const span = (e: CalendarEntry) =>
    e.end_date && e.end_date !== e.date
      ? t('calendar.range', { from: formatDate(`${e.date}T12:00:00+05:30`, i18n.language), to: formatDate(`${e.end_date}T12:00:00+05:30`, i18n.language) })
      : formatDate(`${e.date}T12:00:00+05:30`, i18n.language);

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">{t('nav.calendar')}</h1>
        {can.manage ? (
          <Button onClick={() => setEditing('new')}>
            <Plus aria-hidden="true" />
            {t('calendar.add')}
          </Button>
        ) : null}
      </div>
      <div className="flex flex-col gap-1">
        <label htmlFor={monthId} className="text-sm font-medium">
          {t('attendance.month')}
        </label>
        <input
          id={monthId}
          type="month"
          value={month}
          onChange={(e) => e.target.value && setParams({ month: e.target.value }, { replace: true })}
          className="h-11 w-48 rounded-input border border-border bg-surface px-3 text-sm text-fg focus-visible:outline-2 focus-visible:outline-ring"
        />
      </div>
      {can.manage && !can.approve ? <p className="text-sm text-muted">{t('calendar.manageHint')}</p> : null}
      {list.isPending ? <Spinner label={t('common.loading')} /> : null}
      {list.error ? <Alert tone="danger">{errorText(t, list.error)}</Alert> : null}
      {error ? <Alert tone="danger">{error}</Alert> : null}
      {list.data && entries.length === 0 ? <EmptyState icon={<CalendarDays className="size-5" />} title={t('calendar.empty')} /> : null}

      {entries.length > 0 ? (
        <ul className="flex flex-col gap-2">
          {entries.map((e) => (
            <li key={e.id} className="flex flex-col gap-2 rounded-card border border-border bg-surface p-4">
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm font-semibold">{span(e)}</span>
                <span className="text-xs text-muted">{t(`calendar.types.${e.type}`)}</span>
                <StatusChip status={statusChip[e.status]} label={t(`calendar.status.${e.status}`)} className="ms-auto" />
              </div>
              <p className="text-base">{e.title}</p>
              <p className="text-xs text-muted">
                {[
                  typeof e.created_by === 'string' ? t('calendar.addedBy', { name: e.created_by }) : null,
                  typeof e.decided_by === 'string' ? t(e.status === 'rejected' ? 'calendar.rejectedBy' : 'calendar.approvedBy', { name: e.decided_by }) : null,
                  e.decision_note,
                ]
                  .filter(Boolean)
                  .join(' · ')}
              </p>
              {e.status === 'pending' && (can.manage || can.approve) ? (
                <div className="flex flex-wrap gap-2">
                  {can.approve ? (
                    <>
                      <Button size="sm" className="h-11" onClick={() => void run(() => calendar.approve(e.id))}>
                        <Check aria-hidden="true" />
                        {t('calendar.approve')}
                        <span className="sr-only">: {e.title}</span>
                      </Button>
                      <Button size="sm" variant="outline" className="h-11" onClick={() => setRejecting(e)}>
                        <X aria-hidden="true" />
                        {t('calendar.reject')}
                        <span className="sr-only">: {e.title}</span>
                      </Button>
                    </>
                  ) : null}
                  {can.manage ? (
                    <>
                      <Button size="sm" variant="outline" className="h-11" onClick={() => setEditing(e)}>
                        <Pencil aria-hidden="true" />
                        {t('calendar.edit')}
                        <span className="sr-only">: {e.title}</span>
                      </Button>
                      <Button size="sm" variant="ghost" className="h-11 text-danger" onClick={() => void run(() => calendar.remove(e.id))}>
                        <Trash2 aria-hidden="true" />
                        {t('calendar.delete')}
                        <span className="sr-only">: {e.title}</span>
                      </Button>
                    </>
                  ) : null}
                </div>
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}

      {editing ? <EntryDialog entry={editing === 'new' ? null : editing} month={month} onClose={() => setEditing(null)} onSaved={refresh} /> : null}
      {rejecting ? (
        <RejectDialog
          entry={rejecting}
          onClose={() => setRejecting(null)}
          onReject={async (note) => {
            await calendar.reject(rejecting.id, note || undefined);
            setRejecting(null);
            refresh();
          }}
        />
      ) : null}
    </div>
  );
}

function EntryDialog({ entry, month, onClose, onSaved }: { entry: CalendarEntry | null; month: string; onClose: () => void; onSaved: () => void }) {
  const { t } = useTranslation();
  const [form, setForm] = useState({
    date: entry?.date ?? `${month}-01`,
    end_date: entry?.end_date && entry.end_date !== entry.date ? entry.end_date : '',
    type: entry?.type ?? ('holiday' as CalendarEntry['type']),
    title: entry?.title ?? '',
  });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    setError(null);
    if (form.title.trim().length < 2) return setError(t('calendar.titleRequired'));
    if (form.end_date && form.end_date < form.date) return setError(t('calendar.endBeforeStart'));
    setBusy(true);
    try {
      const body = { date: form.date, type: form.type, title: form.title.trim(), ...(form.end_date ? { end_date: form.end_date } : {}) };
      if (entry) await calendar.update(entry.id, body);
      else await calendar.create(body);
      onSaved();
      onClose();
    } catch (e) {
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open title={t(entry ? 'calendar.editTitle' : 'calendar.addTitle')} onClose={onClose}>
      <form
        className="flex flex-col gap-3"
        noValidate
        onSubmit={(e) => {
          e.preventDefault();
          void submit();
        }}
      >
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Field label={t('calendar.title')}>{(p) => <Input {...p} value={form.title} maxLength={120} onChange={(e) => setForm({ ...form, title: e.target.value })} />}</Field>
        <Field label={t('calendar.type')}>
          {(p) => (
            <Select {...p} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value as CalendarEntry['type'] })}>
              <option value="holiday">{t('calendar.types.holiday')}</option>
              <option value="event">{t('calendar.types.event')}</option>
            </Select>
          )}
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label={t('calendar.from')}>{(p) => <Input {...p} type="date" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} />}</Field>
          <Field label={t('calendar.to')} hint={t('calendar.toHint')}>
            {(p) => <Input {...p} type="date" value={form.end_date} min={form.date} onChange={(e) => setForm({ ...form, end_date: e.target.value })} />}
          </Field>
        </div>
        <p className="text-xs text-muted">{t('calendar.pendingNote')}</p>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={busy}>
            {busy ? t('common.working') : t('common.save')}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

function RejectDialog({ entry, onClose, onReject }: { entry: CalendarEntry; onClose: () => void; onReject: (note: string) => Promise<void> }) {
  const { t } = useTranslation();
  const [note, setNote] = useState('');
  const [error, setError] = useState<string | null>(null);

  return (
    <Dialog open title={t('calendar.rejectTitle', { title: entry.title })} onClose={onClose}>
      <form
        className="flex flex-col gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          onReject(note.trim()).catch((err: unknown) => setError(errorText(t, err)));
        }}
      >
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Field label={t('calendar.note')}>{(p) => <Textarea {...p} rows={2} maxLength={300} value={note} onChange={(e) => setNote(e.target.value)} />}</Field>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" variant="danger">
            {t('calendar.reject')}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
