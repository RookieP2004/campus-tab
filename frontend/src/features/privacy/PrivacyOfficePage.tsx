import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Dialog, Field, Input, Select, Spinner, Textarea } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { StepUpField } from '@/features/fees/StepUpField';
import { isStepUpRequest, useStepUp } from '@/features/fees/useStepUp';
import { apiGet } from '@/lib/api';
import { apiGetBody } from '@/lib/requests';
import { type DataRequest, type Incident, type MyPrivacy, type PrivacyNotice, type RetentionView, myPrivacyKey, privacy } from './api';


const tabs = ['requests', 'notices', 'retention', 'incidents'] as const;
type Tab = (typeof tabs)[number];

/**
 * The office side of privacy (DPDP): the data-requests inbox, the privacy
 * notice editor (versions), retention (a dry run first, deleting only after
 * the Chief confirms with a code) and the incident log of the breach
 * runbook. Tabs show only what the person may use; the server checks again.
 */
export function PrivacyOfficePage() {
  const { t } = useTranslation();
  const [params, setParams] = useSearchParams();
  const mine = useQuery({ queryKey: myPrivacyKey, queryFn: apiGet<MyPrivacy>('/privacy') });
  if (mine.isPending) return <Spinner label={t('common.loading')} />;
  const can = mine.data?.can;
  const allowed = tabs.filter((k) => can?.[k]);
  const tab: Tab = allowed.includes(params.get('tab') as Tab) ? (params.get('tab') as Tab) : (allowed[0] ?? 'requests');

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4">
      <Link to="/privacy" className="text-sm font-medium text-primary hover:underline">
        {t('privacy.office.back')}
      </Link>
      <h1 className="text-2xl font-semibold tracking-tight">{t('privacy.office.title')}</h1>
      <div role="tablist" aria-label={t('privacy.office.title')} className="flex flex-wrap gap-2">
        {allowed.map((k) => (
          <Button key={k} role="tab" aria-selected={tab === k} variant={tab === k ? 'primary' : 'outline'} className="h-11" onClick={() => setParams({ tab: k })}>
            {t(`privacy.office.tabs.${k}`)}
          </Button>
        ))}
      </div>
      {allowed.length === 0 ? <Alert tone="info">{t('privacy.office.nothing')}</Alert> : null}
      {tab === 'requests' && can?.requests ? <RequestsInbox canErase={can.retention} /> : null}
      {tab === 'notices' && can?.notices ? <NoticeEditor /> : null}
      {tab === 'retention' && can?.retention ? <RetentionPanel /> : null}
      {tab === 'incidents' && can?.incidents ? <IncidentLog /> : null}
    </div>
  );
}

function RequestsInbox({ canErase }: { canErase: boolean }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const query = useQuery({ queryKey: ['privacy', 'inbox'], queryFn: apiGetBody<{ data: DataRequest[]; meta: { open: number; late: number } }>('/privacy/requests') });
  const stepUp = useStepUp();
  const [outcome, setOutcome] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [erase, setErase] = useState<DataRequest | null>(null);
  const [confirmText, setConfirmText] = useState('');

  const run = async (work: () => Promise<unknown>, sensitive = false) => {
    setError(null);
    try {
      await (sensitive ? stepUp.run(work) : work());
      await client.invalidateQueries({ queryKey: ['privacy'] });
      return true;
    } catch (e) {
      if (!isStepUpRequest(e)) setError(errorText(t, e));
      return false;
    }
  };

  if (query.isPending) return <Spinner label={t('common.loading')} />;
  const list = query.data?.data ?? [];

  return (
    <Card className="flex flex-col gap-3">
      <h2 className="text-lg font-semibold">{t('privacy.office.inbox', { open: query.data?.meta.open ?? 0, late: query.data?.meta.late ?? 0 })}</h2>
      <StepUpField state={stepUp} />
      {error ? <Alert tone="danger">{error}</Alert> : null}
      {list.length === 0 ? <p className="text-sm text-muted">{t('privacy.office.noRequests')}</p> : null}
      <ul className="flex flex-col divide-y divide-border">
        {list.map((r) => {
          const open = r.status === 'received' || r.status === 'in_progress';
          return (
            <li key={r.id} className="flex flex-col gap-2 py-3">
              <div className="flex flex-wrap items-baseline gap-2">
                <span className="font-medium">
                  {t(`privacy.requests.types.${r.type}`)} · {r.subject_name}
                </span>
                <span className={`text-xs ${r.late ? 'font-semibold text-status-rejected-fg' : 'text-muted'}`}>
                  {r.late ? t('privacy.office.late', { date: r.due_on }) : t('privacy.requests.due', { date: r.due_on })}
                </span>
              </div>
              <p className="text-sm">{r.details}</p>
              <p className="text-xs text-muted">
                {t('privacy.office.from', { name: r.requested_by?.name ?? '', role: r.requested_by?.role ?? '' })} · {t(`privacy.requests.status.${r.status}`)}
                {r.handled_by ? ` · ${r.handled_by}` : ''}
              </p>
              {r.outcome ? <p className="text-sm">{r.outcome}</p> : null}
              {open ? (
                <>
                  <Field label={t('privacy.office.outcome')} hint={t('privacy.office.outcomeHint')}>
                    {(f) => <Textarea {...f} rows={2} value={outcome[r.id] ?? ''} onChange={(e) => setOutcome({ ...outcome, [r.id]: e.target.value })} />}
                  </Field>
                  <div className="flex flex-wrap gap-2">
                    {r.status === 'received' ? (
                      <Button variant="outline" className="h-11" onClick={() => void run(() => privacy.move(r.id, { status: 'in_progress' }))}>
                        {t('privacy.office.start')}
                      </Button>
                    ) : null}
                    {r.type === 'access' && r.student_id ? (
                      <Button variant="outline" className="h-11" disabled={!stepUp.ready} onClick={() => void run(() => privacy.copy(r.id, `data-copy-${r.id}.json`), true)}>
                        {t('privacy.office.copy')}
                      </Button>
                    ) : null}
                    {r.type === 'erasure' && r.student_id && canErase ? (
                      <Button variant="outline" className="h-11" onClick={() => setErase(r)}>
                        {t('privacy.office.erase')}
                      </Button>
                    ) : null}
                    <Button className="h-11" disabled={(outcome[r.id] ?? '').trim().length < 10} onClick={() => void run(() => privacy.move(r.id, { status: 'completed', outcome: outcome[r.id] }))}>
                      {t('privacy.office.complete')}
                    </Button>
                    <Button variant="outline" className="h-11" disabled={(outcome[r.id] ?? '').trim().length < 10} onClick={() => void run(() => privacy.move(r.id, { status: 'rejected', outcome: outcome[r.id] }))}>
                      {t('privacy.office.reject')}
                    </Button>
                  </div>
                </>
              ) : null}
            </li>
          );
        })}
      </ul>
      <Dialog open={erase !== null} title={t('privacy.office.eraseTitle')} onClose={() => setErase(null)}>
        <p className="text-sm">{t('privacy.office.eraseBody', { name: erase?.subject_name ?? '' })}</p>
        <Field label={t('privacy.office.eraseConfirm')}>{(f) => <Input {...f} value={confirmText} onChange={(e) => setConfirmText(e.target.value)} autoComplete="off" />}</Field>
        <StepUpField state={stepUp} />
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="outline" className="h-11" onClick={() => setErase(null)}>
            {t('common.cancel')}
          </Button>
          <Button
            className="h-11"
            disabled={confirmText.trim() === '' || !stepUp.ready}
            onClick={() => {
              if (erase) void run(() => privacy.erase(erase.id, confirmText.trim()), true).then((ok) => ok && setErase(null));
            }}
          >
            {t('privacy.office.eraseNow')}
          </Button>
        </div>
      </Dialog>
    </Card>
  );
}

function NoticeEditor() {
  const { t } = useTranslation();
  const client = useQueryClient();
  const query = useQuery({ queryKey: ['privacy', 'notices'], queryFn: apiGet<PrivacyNotice[]>('/privacy/notices') });
  const draft = query.data?.find((n) => n.status === 'draft');
  const current = query.data?.find((n) => n.status === 'published');
  const [title, setTitle] = useState<string | null>(null);
  const [body, setBody] = useState<string | null>(null);
  const [summary, setSummary] = useState<string | null>(null);
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [confirm, setConfirm] = useState(false);
  if (query.isPending) return <Spinner label={t('common.loading')} />;
  const base = draft ?? current;
  const value = { title: title ?? base?.title ?? '', body: body ?? base?.body ?? '', summary: summary ?? draft?.summary_of_changes ?? '' };

  const save = async () => {
    try {
      await privacy.saveDraft({ title: value.title, body: value.body, summary_of_changes: value.summary || null });
      setMessage({ tone: 'success', text: t('privacy.office.draftSaved') });
      await client.invalidateQueries({ queryKey: ['privacy'] });
    } catch (e) {
      setMessage({ tone: 'danger', text: errorText(t, e) });
    }
  };
  const publish = async () => {
    try {
      const n = await privacy.publish();
      setMessage({ tone: 'success', text: t('privacy.office.published', { version: n.version }) });
      setTitle(null);
      setBody(null);
      setSummary(null);
      await client.invalidateQueries({ queryKey: ['privacy'] });
    } catch (e) {
      setMessage({ tone: 'danger', text: errorText(t, e) });
    } finally {
      setConfirm(false);
    }
  };

  return (
    <Card className="flex flex-col gap-3">
      <h2 className="text-lg font-semibold">{t('privacy.office.noticeTitle')}</h2>
      <p className="text-sm text-muted">{current ? t('privacy.office.currentVersion', { version: current.version, date: current.published_at?.slice(0, 10) ?? '' }) : t('privacy.notice.none')}</p>
      <Alert tone="warning">{t('privacy.office.legalReview')}</Alert>
      <Field label={t('privacy.office.noticeName')}>{(f) => <Input {...f} maxLength={150} value={value.title} onChange={(e) => setTitle(e.target.value)} />}</Field>
      <Field label={t('privacy.office.noticeBody')} hint={t('privacy.office.noticeBodyHint')}>
        {(f) => <Textarea {...f} rows={12} value={value.body} onChange={(e) => setBody(e.target.value)} />}
      </Field>
      <Field label={t('privacy.office.changes')}>{(f) => <Input {...f} maxLength={500} value={value.summary} onChange={(e) => setSummary(e.target.value)} />}</Field>
      {message ? <Alert tone={message.tone}>{message.text}</Alert> : null}
      <div className="flex flex-wrap gap-2">
        <Button variant="outline" className="h-11" disabled={value.title.trim().length < 3 || value.body.trim().length < 50} onClick={() => void save()}>
          {t('privacy.office.saveDraft')}
        </Button>
        <Button className="h-11" disabled={!draft} onClick={() => setConfirm(true)}>
          {t('privacy.office.publish')}
        </Button>
      </div>
      <h3 className="font-semibold">{t('privacy.office.versions')}</h3>
      <ul className="flex flex-col divide-y divide-border text-sm">
        {(query.data ?? []).map((n) => (
          <li key={n.id} className="py-2">
            {n.version ? t('privacy.office.versionN', { version: n.version }) : t('privacy.office.draft')} · {t(`privacy.office.noticeStatus.${n.status}`)}
            {n.published_at ? ` · ${n.published_at.slice(0, 10)} · ${n.published_by ?? ''}` : ''}
            {n.summary_of_changes ? <span className="block text-xs text-muted">{n.summary_of_changes}</span> : null}
          </li>
        ))}
      </ul>
      <Dialog open={confirm} title={t('privacy.office.publishTitle')} onClose={() => setConfirm(false)}>
        <p className="text-sm">{t('privacy.office.publishBody')}</p>
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="outline" className="h-11" onClick={() => setConfirm(false)}>
            {t('common.cancel')}
          </Button>
          <Button className="h-11" onClick={() => void publish()}>
            {t('privacy.office.publish')}
          </Button>
        </div>
      </Dialog>
    </Card>
  );
}

function RetentionPanel() {
  const { t } = useTranslation();
  const client = useQueryClient();
  const query = useQuery({ queryKey: ['privacy', 'retention'], queryFn: apiGet<RetentionView>('/privacy/retention') });
  const stepUp = useStepUp();
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);
  if (query.isPending) return <Spinner label={t('common.loading')} />;
  const view = query.data;

  const act = async (work: () => Promise<{ deleted?: number | null; count?: number }>, sensitive: boolean) => {
    setError(null);
    setDone(null);
    try {
      const r = await (sensitive ? stepUp.run(work) : work());
      setDone(sensitive ? t('privacy.office.deleted', { count: r.deleted ?? 0 }) : t('privacy.office.listed', { count: r.count ?? 0 }));
      await client.invalidateQueries({ queryKey: ['privacy', 'retention'] });
    } catch (e) {
      if (!isStepUpRequest(e)) setError(errorText(t, e));
    }
  };

  return (
    <Card className="flex flex-col gap-3">
      <h2 className="text-lg font-semibold">{t('privacy.office.retentionTitle')}</h2>
      <p className="text-sm text-muted">{t('privacy.office.retentionIntro')}</p>
      <p className="text-sm">{t('privacy.office.never')}</p>
      <StepUpField state={stepUp} />
      {error ? <Alert tone="danger">{error}</Alert> : null}
      {done ? <Alert tone="success">{done}</Alert> : null}
      <ul className="flex flex-col divide-y divide-border">
        {view?.rules.map((rule) => {
          const run = view.runs.find((r) => r.kind === rule.kind && r.status === 'dry_run');
          return (
            <li key={rule.kind} className="flex flex-col gap-2 py-3">
              <span className="text-sm font-medium">
                {t(`privacy.retention.${rule.kind}`, { defaultValue: rule.label })} · {t('privacy.office.keptFor', { count: rule.days })}
              </span>
              {run ? (
                <p className="text-sm">{run.count > 0 ? t('privacy.office.dryRunResult', { count: run.count, oldest: run.oldest ?? '', newest: run.newest ?? '', date: run.made_at?.slice(0, 10) ?? '' }) : t('privacy.office.dryRunEmpty')}</p>
              ) : null}
              <div className="flex flex-wrap gap-2">
                <Button variant="outline" className="h-11" onClick={() => void act(() => privacy.dryRun(rule.kind), false)}>
                  {t('privacy.office.dryRun')}
                </Button>
                {run && run.count > 0 ? (
                  <Button className="h-11" disabled={!stepUp.ready} onClick={() => void act(() => privacy.confirm(run.id), true)}>
                    {t('privacy.office.confirmDelete', { count: run.count })}
                  </Button>
                ) : null}
              </div>
            </li>
          );
        })}
      </ul>
      <h3 className="font-semibold">{t('privacy.office.history')}</h3>
      <ul className="flex flex-col divide-y divide-border text-sm">
        {view?.runs
          .filter((r) => r.status === 'confirmed')
          .map((r) => (
            <li key={r.id} className="py-2">
              {t(`privacy.retention.${r.kind}`, { defaultValue: r.label })}: {t('privacy.office.deletedBy', { count: r.deleted ?? 0, name: r.confirmed_by ?? '', date: r.confirmed_at?.slice(0, 10) ?? '' })}
            </li>
          ))}
      </ul>
    </Card>
  );
}

function IncidentLog() {
  const { t } = useTranslation();
  const client = useQueryClient();
  const query = useQuery({ queryKey: ['privacy', 'incidents'], queryFn: apiGet<Incident[]>('/privacy/incidents') });
  const [form, setForm] = useState({ title: '', detected_at: '', severity: 'medium' as Incident['severity'], description: '', people_affected: '' });
  const [note, setNote] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  if (query.isPending) return <Spinner label={t('common.loading')} />;

  const act = async (work: () => Promise<unknown>) => {
    setError(null);
    try {
      await work();
      await client.invalidateQueries({ queryKey: ['privacy', 'incidents'] });
      return true;
    } catch (e) {
      setError(errorText(t, e));
      return false;
    }
  };

  return (
    <Card className="flex flex-col gap-3">
      <h2 className="text-lg font-semibold">{t('privacy.office.incidentsTitle')}</h2>
      <p className="text-sm text-muted">{t('privacy.office.incidentsIntro')}</p>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t('privacy.office.incidentName')}>{(f) => <Input {...f} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />}</Field>
        <Field label={t('privacy.office.detectedAt')}>{(f) => <Input {...f} type="datetime-local" value={form.detected_at} onChange={(e) => setForm({ ...form, detected_at: e.target.value })} />}</Field>
        <Field label={t('privacy.office.severity')}>
          {(f) => (
            <Select {...f} className="h-11" value={form.severity} onChange={(e) => setForm({ ...form, severity: e.target.value as Incident['severity'] })}>
              {(['low', 'medium', 'high'] as const).map((s) => (
                <option key={s} value={s}>
                  {t(`privacy.office.severities.${s}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t('privacy.office.peopleAffected')}>{(f) => <Input {...f} type="number" min={0} value={form.people_affected} onChange={(e) => setForm({ ...form, people_affected: e.target.value })} />}</Field>
      </div>
      <Field label={t('privacy.office.whatHappened')}>{(f) => <Textarea {...f} rows={3} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />}</Field>
      <div>
        <Button
          className="h-11"
          disabled={form.title.trim().length < 5 || !form.detected_at || form.description.trim().length < 10}
          onClick={() =>
            void act(() =>
              privacy.report({ title: form.title, detected_at: form.detected_at, severity: form.severity, description: form.description, people_affected: form.people_affected ? Number(form.people_affected) : null }),
            ).then((ok) => ok && setForm({ title: '', detected_at: '', severity: 'medium', description: '', people_affected: '' }))
          }
        >
          {t('privacy.office.record')}
        </Button>
      </div>
      <ul className="flex flex-col divide-y divide-border">
        {(query.data ?? []).map((i) => (
          <li key={i.id} className="flex flex-col gap-2 py-3 text-sm">
            <span className="font-medium">
              {i.title} · {t(`privacy.office.severities.${i.severity}`)} · {t(`privacy.office.incidentStatus.${i.status}`)}
            </span>
            {i.board_notified_at ? (
              <span className="text-xs text-muted">{t('privacy.office.boardTold', { date: i.board_notified_at.slice(0, 16).replace('T', ' ') })}</span>
            ) : (
              <span className={`text-xs ${i.board_overdue ? 'font-semibold text-status-rejected-fg' : 'text-muted'}`}>{t('privacy.office.boardDeadline', { date: i.board_deadline.slice(0, 16).replace('T', ' ') })}</span>
            )}
            <ol className="flex flex-col gap-1 border-s border-border ps-3">
              {i.timeline.map((e, n) => (
                <li key={n}>
                  <span className="text-xs text-muted">
                    {e.at.slice(0, 16).replace('T', ' ')} · {e.by}
                  </span>
                  <span className="block">{e.note}</span>
                </li>
              ))}
            </ol>
            {i.status !== 'closed' ? (
              <div className="flex flex-col gap-2">
                <Field label={t('privacy.office.nextStep')}>{(f) => <Input {...f} value={note[i.id] ?? ''} onChange={(e) => setNote({ ...note, [i.id]: e.target.value })} />}</Field>
                <div className="flex flex-wrap gap-2">
                  <Button variant="outline" className="h-11" disabled={(note[i.id] ?? '').trim().length < 3} onClick={() => void act(() => privacy.addEntry(i.id, { note: note[i.id] ?? '' })).then((ok) => ok && setNote({ ...note, [i.id]: '' }))}>
                    {t('privacy.office.addStep')}
                  </Button>
                  {!i.board_notified_at ? (
                    <Button variant="outline" className="h-11" disabled={(note[i.id] ?? '').trim().length < 3} onClick={() => void act(() => privacy.addEntry(i.id, { note: note[i.id] ?? '', board_notified: true }))}>
                      {t('privacy.office.markBoard')}
                    </Button>
                  ) : null}
                  {!i.people_notified_at ? (
                    <Button variant="outline" className="h-11" disabled={(note[i.id] ?? '').trim().length < 3} onClick={() => void act(() => privacy.addEntry(i.id, { note: note[i.id] ?? '', people_notified: true }))}>
                      {t('privacy.office.markPeople')}
                    </Button>
                  ) : null}
                  <Button variant="outline" className="h-11" disabled={(note[i.id] ?? '').trim().length < 3} onClick={() => void act(() => privacy.addEntry(i.id, { note: note[i.id] ?? '', status: 'closed' }))}>
                    {t('privacy.office.close')}
                  </Button>
                </div>
              </div>
            ) : null}
          </li>
        ))}
      </ul>
    </Card>
  );
}
