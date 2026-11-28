import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Field, Select, Spinner, Textarea } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { apiGet } from '@/lib/api';
import { type MyPrivacy, type RequestType, myPrivacyKey, privacy } from './api';



/**
 * Everyone's own privacy page (DPDP): the school's current privacy notice
 * and whom to contact; for a parent, a yes or no per purpose for each child
 * (the only consent that counts, given here from their own account); and
 * the person's data requests with their due dates and outcomes.
 */
export function PrivacyPage() {
  const { t } = useTranslation();
  const query = useQuery({ queryKey: myPrivacyKey, queryFn: apiGet<MyPrivacy>('/privacy') });

  if (query.isPending) return <Spinner label={t('common.loading')} />;
  if (query.isError || !query.data) return <Alert tone="danger">{errorText(t, query.error)}</Alert>;
  const data = query.data;
  const office = data.can.notices || data.can.requests || data.can.retention || data.can.incidents;

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-4">
      <h1 className="text-2xl font-semibold tracking-tight">{t('privacy.title')}</h1>
      <p className="text-sm text-muted">{t('privacy.intro')}</p>
      {office ? (
        <div>
          <Link to="/privacy/office" className="text-sm font-medium text-primary hover:underline">
            {t('privacy.office.link')}
          </Link>
        </div>
      ) : null}
      <NoticeCard data={data} />
      {data.consents ? <ConsentsCard data={data} /> : null}
      <RequestsCard data={data} />
      <p className="text-xs text-muted">{t('privacy.noTracking')}</p>
    </div>
  );
}

function NoticeCard({ data }: { data: MyPrivacy }) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const g = data.grievance;

  return (
    <Card className="flex flex-col gap-3">
      <h2 className="text-lg font-semibold">{t('privacy.notice.title')}</h2>
      {data.notice ? (
        <>
          <p className="text-sm">
            <strong>{data.notice.title}</strong>{' '}
            <span className="text-muted">{t('privacy.notice.version', { version: data.notice.version, date: data.notice.published_at?.slice(0, 10) ?? '' })}</span>
          </p>
          {open ? (
            <div className="flex flex-col gap-2 text-sm">
              {data.notice.body.split(/\n{2,}/).map((p, i) => (
                <p key={i} className="whitespace-pre-line">
                  {p}
                </p>
              ))}
            </div>
          ) : null}
          <div>
            <Button variant="outline" className="h-11" onClick={() => setOpen(!open)} aria-expanded={open}>
              {open ? t('privacy.notice.hide') : t('privacy.notice.read')}
            </Button>
          </div>
        </>
      ) : (
        <p className="text-sm text-muted">{t('privacy.notice.none')}</p>
      )}
      <h3 className="font-semibold">{t('privacy.grievance.title')}</h3>
      {g ? (
        <dl className="grid gap-1 text-sm sm:grid-cols-[10rem_1fr]">
          <dt className="text-muted">{t('privacy.grievance.name')}</dt>
          <dd>
            {g.name}
            {g.role ? `, ${g.role}` : ''}
          </dd>
          <dt className="text-muted">{t('privacy.grievance.email')}</dt>
          <dd>
            <a className="text-primary hover:underline" href={`mailto:${g.email}`}>
              {g.email}
            </a>
          </dd>
          {g.phone ? (
            <>
              <dt className="text-muted">{t('privacy.grievance.phone')}</dt>
              <dd>{g.phone}</dd>
            </>
          ) : null}
        </dl>
      ) : (
        <p className="text-sm text-muted">{t('privacy.grievance.none')}</p>
      )}
    </Card>
  );
}

function ConsentsCard({ data }: { data: MyPrivacy }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const consents = data.consents;
  if (!consents) return null;

  const answer = async (studentId: string, purpose: string, given: boolean) => {
    if (!consents.notice) return;
    setBusy(`${studentId}:${purpose}`);
    setError(null);
    try {
      await privacy.answer({ student_id: studentId, purpose, given, notice_version: consents.notice.version });
      await client.invalidateQueries({ queryKey: myPrivacyKey });
    } catch (e) {
      setError(errorText(t, e));
    } finally {
      setBusy(null);
    }
  };

  return (
    <Card className="flex flex-col gap-3">
      <h2 className="text-lg font-semibold">{t('privacy.consents.title')}</h2>
      <p className="text-sm text-muted">{t('privacy.consents.intro')}</p>
      {!consents.notice ? <Alert tone="info">{t('privacy.consents.noNotice')}</Alert> : null}
      {error ? <Alert tone="danger">{error}</Alert> : null}
      {consents.children.length === 0 ? <p className="text-sm text-muted">{t('privacy.consents.noChildren')}</p> : null}
      {consents.children.map((child) => (
        <section key={child.id} className="flex flex-col gap-2" aria-labelledby={`consent-${child.id}`}>
          <h3 id={`consent-${child.id}`} className="font-semibold">
            {child.name}
          </h3>
          <ul className="flex flex-col divide-y divide-border">
            {Object.entries(consents.purposes).map(([purpose, label]) => {
              const current = child.consents[purpose];
              const value = current ? (current.given ? 'yes' : 'no') : '';
              return (
                <li key={purpose} className="flex flex-wrap items-center gap-2 py-2">
                  <span className="min-w-0 flex-1 basis-56 text-sm">
                    {t(`privacy.purposes.${purpose}`, { defaultValue: label })}
                    {current ? <span className="block text-xs text-muted">{t('privacy.consents.answered', { date: current.at.slice(0, 10), version: current.version })}</span> : null}
                  </span>
                  <Select
                    aria-label={t('privacy.consents.answerFor', { purpose: t(`privacy.purposes.${purpose}`, { defaultValue: label }), name: child.name })}
                    className="h-11 w-full sm:w-40"
                    value={value}
                    disabled={!consents.notice || busy === `${child.id}:${purpose}`}
                    onChange={(e) => void answer(child.id, purpose, e.target.value === 'yes')}
                  >
                    {value === '' ? <option value="">{t('privacy.consents.notAnswered')}</option> : null}
                    <option value="yes">{t('privacy.consents.yes')}</option>
                    <option value="no">{t('privacy.consents.no')}</option>
                  </Select>
                </li>
              );
            })}
          </ul>
        </section>
      ))}
    </Card>
  );
}

function RequestsCard({ data }: { data: MyPrivacy }) {
  const { t } = useTranslation();
  const client = useQueryClient();
  const [type, setType] = useState<RequestType>('access');
  const [about, setAbout] = useState('');
  const [details, setDetails] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const children = data.consents?.children ?? [];

  const submit = async () => {
    setBusy(true);
    setError(null);
    setDone(null);
    try {
      const r = await privacy.ask({ type, student_id: about || null, details: details.trim() });
      setDone(t('privacy.requests.sent', { date: r.due_on }));
      setDetails('');
      await client.invalidateQueries({ queryKey: myPrivacyKey });
    } catch (e) {
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card className="flex flex-col gap-3">
      <h2 className="text-lg font-semibold">{t('privacy.requests.title')}</h2>
      <p className="text-sm text-muted">{t('privacy.requests.intro')}</p>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t('privacy.requests.type')}>
          {(f) => (
            <Select {...f} className="h-11" value={type} onChange={(e) => setType(e.target.value as RequestType)}>
              {(['access', 'correction', 'erasure'] as const).map((k) => (
                <option key={k} value={k}>
                  {t(`privacy.requests.types.${k}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        {children.length > 0 ? (
          <Field label={t('privacy.requests.about')}>
            {(f) => (
              <Select {...f} className="h-11" value={about} onChange={(e) => setAbout(e.target.value)}>
                <option value="">{t('privacy.requests.me')}</option>
                {children.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        ) : null}
      </div>
      <Field label={t('privacy.requests.details')} hint={t('privacy.requests.detailsHint')}>
        {(f) => <Textarea {...f} rows={3} maxLength={2000} value={details} onChange={(e) => setDetails(e.target.value)} />}
      </Field>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      {done ? <Alert tone="success">{done}</Alert> : null}
      <div>
        <Button className="h-11" disabled={busy || details.trim().length < 10} onClick={() => void submit()}>
          {t('privacy.requests.send')}
        </Button>
      </div>
      {data.requests.length > 0 ? (
        <ul className="flex flex-col divide-y divide-border">
          {data.requests.map((r) => (
            <li key={r.id} className="flex flex-col gap-1 py-2 text-sm">
              <span className="font-medium">
                {t(`privacy.requests.types.${r.type}`)} · {r.subject_name}
              </span>
              <span className="text-muted">
                {t(`privacy.requests.status.${r.status}`)} · {t('privacy.requests.due', { date: r.due_on })}
              </span>
              {r.outcome ? <span>{r.outcome}</span> : null}
            </li>
          ))}
        </ul>
      ) : null}
    </Card>
  );
}
