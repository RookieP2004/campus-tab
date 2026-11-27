import { StatusChip } from '@campus-tab/ui';
import { useQueryClient } from '@tanstack/react-query';
import { Check, CornerUpLeft, FileText, Paperclip, Undo2, X } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Alert, Dialog, Field, Input, Textarea } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { formatDate, formatDateTime } from '@/lib/format';
import { asDate, leave, type LeaveDetail, type LeaveMove, leaveStatusChip, type TimelineStep } from './api';
import { useLeaveDates } from './dates';

export function LeaveStatusChip({ status }: { status: LeaveDetail['status'] }) {
  const { t } = useTranslation();
  return <StatusChip status={leaveStatusChip[status]} label={t(`leave.status.${status}`)} />;
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex min-w-0 flex-col gap-0.5 rounded-input bg-surface-muted p-3">
      <dt className="text-xs font-medium text-muted">{label}</dt>
      <dd className="text-sm font-semibold text-fg">{children}</dd>
    </div>
  );
}

/** One step of the request: done (with who, when and remark), waiting now, or still to come. */
function Step({ step, last }: { step: TimelineStep; last: boolean }) {
  const { t, i18n } = useTranslation();
  const tone =
    step.state === 'current'
      ? 'border-warning bg-surface'
      : step.state === 'upcoming'
        ? 'border-border bg-surface'
        : step.action?.startsWith('rejected') || step.action === 'cancelled'
          ? 'border-danger-solid bg-danger-solid'
          : step.action === 'returned'
            ? 'border-warning-solid bg-warning-solid'
            : 'border-primary-solid bg-primary-solid';
  const label =
    step.state === 'done'
      ? t(`leave.steps.${step.action ?? 'submitted'}`, { name: step.by ?? '', stage: t(`leave.stages.${step.stage}`) })
      : t(step.state === 'current' ? 'leave.steps.waitingFor' : 'leave.steps.then', { stage: t(`leave.stages.${step.stage}`) });

  return (
    <li className="relative flex gap-3 pb-4 last:pb-0">
      {!last ? <span aria-hidden="true" className="absolute start-[7px] top-5 bottom-0 w-px bg-border" /> : null}
      <span aria-hidden="true" className={`mt-1 size-[15px] shrink-0 rounded-full border-2 ${tone}`} />
      <div className="flex min-w-0 flex-1 flex-col gap-0.5">
        <span className={`text-sm ${step.state === 'upcoming' ? 'text-muted' : 'font-medium text-fg'}`}>{label}</span>
        {step.at ? (
          <span className="text-xs text-muted">
            <time dateTime={step.at}>{formatDateTime(step.at, i18n.language)}</time>
            {step.from && step.to ? ` · ${t('leave.changedDates', { from: formatDate(asDate(step.from), i18n.language), to: formatDate(asDate(step.to), i18n.language) })}` : null}
            {step.restored_days !== null ? ` · ${t('leave.restoredDays', { count: step.restored_days })}` : null}
          </span>
        ) : null}
        {step.remark ? <p className="text-sm text-fg">“{step.remark}”</p> : null}
      </div>
    </li>
  );
}

type Pending = { move: LeaveMove; needsText: boolean; title: string; label: string; dates?: boolean } | null;

/**
 * A leave request in full: facts, reason, documents, the live timeline, the
 * periods that need cover, and the moves this person may make.
 */
export function LeaveRequestView({ request, onChanged }: { request: LeaveDetail; onChanged?: (r: LeaveDetail) => void }) {
  const { t, i18n } = useTranslation();
  const queryClient = useQueryClient();
  const dates = useLeaveDates();
  const [pending, setPending] = useState<Pending>(null);
  const [text, setText] = useState('');
  const [from, setFrom] = useState(request.from);
  const [to, setTo] = useState(request.to);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<string | null>(null);
  const can = request.can;
  const name = request.applicant.name;

  const refresh = (updated: LeaveDetail) => {
    queryClient.setQueryData(['leave', 'request', updated.id], updated);
    void queryClient.invalidateQueries({ queryKey: ['leave'] });
    void queryClient.invalidateQueries({ queryKey: ['approvals'] });
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    onChanged?.(updated);
  };

  const run = async (move: LeaveMove, body: Record<string, unknown> = {}) => {
    setBusy(true);
    setError(null);
    try {
      const updated = await leave.move(request.id, move, body);
      setPending(null);
      setText('');
      setDone(t(`leave.done.${move}`, { name }));
      refresh(updated);
    } catch (e) {
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  const open = (p: NonNullable<Pending>) => {
    setError(null);
    setText('');
    setFrom(request.from);
    setTo(request.to);
    setPending(p);
  };

  const confirm = () => {
    if (!pending) return;
    const key = pending.move === 'cancel-request' ? 'reason' : 'remark';
    const body: Record<string, unknown> = text.trim() ? { [key]: text.trim() } : {};
    if (pending.dates) Object.assign(body, { from, to });
    void run(pending.move, body);
  };

  // A fresh short-lived signed link on every click, never kept. The tab is
  // opened before the request so the browser does not block it.
  const openDocument = async (doc: string) => {
    setError(null);
    const win = window.open('', '_blank');
    try {
      const { url } = await leave.documentLink(request.id, doc);
      if (win) {
        win.opener = null;
        win.location.href = url;
      } else {
        window.location.assign(url);
      }
    } catch (e) {
      win?.close();
      setError(errorText(t, e));
    }
  };

  const attach = async (file: File | undefined) => {
    if (!file) return;
    setError(null);
    try {
      refresh(await leave.attach(request.id, file));
    } catch (e) {
      setError(errorText(t, e));
    }
  };

  // While a request is pending its days are already held, so "available" is the balance after it.
  const balance = request.balance;
  const held = request.status === 'submitted' || request.status === 'verified';
  const balanceText =
    balance && balance.available !== null
      ? held
        ? t('leave.balanceAfter', { available: balance.available + request.days, quota: balance.quota, after: balance.available })
        : t('leave.balanceNow', { available: balance.available, quota: balance.quota })
      : null;

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-wrap items-start gap-3">
        <span aria-hidden="true" className="flex size-11 shrink-0 items-center justify-center rounded-full bg-primary-soft text-sm font-bold text-primary">
          {name
            .split(/\s+/)
            .filter((w) => /^[\p{L}]/u.test(w))
            .slice(0, 2)
            .map((w) => w[0])
            .join('')}
        </span>
        <div className="min-w-0 flex-1">
          <h2 className="text-lg font-semibold break-words">{name}</h2>
          <p className="text-sm text-muted">
            {request.kind === 'student' ? t('leave.studentOf', { section: request.applicant.section ?? '', parent: request.applied_by ?? '' }) : (request.applicant.designation ?? '')}
          </p>
        </div>
        <LeaveStatusChip status={request.status} />
      </div>

      {done ? <Alert tone="success">{done}</Alert> : null}
      {request.fast_tracked ? <Alert tone="info">{t('leave.fastTracked')}</Alert> : null}
      {request.principal_sees ? <Alert tone="info">{t('leave.longStudentLeave')}</Alert> : null}

      <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <Fact label={t('leave.type')}>{request.kind === 'student' ? t('leave.studentLeave') : request.type_name}</Fact>
        <Fact label={t('leave.dates')}>{dates(request.approved?.from ?? request.from, request.approved?.to ?? request.to, request.half_day)}</Fact>
        <Fact label={t('leave.days')}>{request.approved?.days ?? request.days}</Fact>
        {balance && balanceText ? <Fact label={t('leave.balanceOf', { type: balance.type_name })}>{balanceText}</Fact> : null}
      </dl>

      {request.reason ? (
        <section className="flex flex-col gap-1">
          <h3 className="text-xs font-semibold tracking-wide text-muted uppercase">{t('leave.reason')}</h3>
          <p className="text-sm whitespace-pre-line text-fg">{request.reason}</p>
        </section>
      ) : null}

      {request.documents.length > 0 || can.attach ? (
        <section className="flex flex-col gap-2">
          <h3 className="text-xs font-semibold tracking-wide text-muted uppercase">{t('leave.documents')}</h3>
          <ul className="flex flex-wrap gap-2">
            {request.documents.map((d) => (
              <li key={d.id}>
                <Button variant="outline" size="sm" className="h-11" onClick={() => void openDocument(d.id)}>
                  <FileText aria-hidden="true" />
                  {d.name}
                </Button>
              </li>
            ))}
          </ul>
          {can.attach ? (
            <label className="inline-flex h-11 w-fit cursor-pointer items-center gap-2 rounded-input border border-border px-3 text-sm font-medium text-fg hover:bg-surface-muted focus-within:outline-2 focus-within:outline-ring">
              <Paperclip aria-hidden="true" className="size-4" />
              {t('leave.attach')}
              <input type="file" accept=".pdf,.png,.jpg,.jpeg,.webp" className="sr-only" onChange={(e) => void attach(e.target.files?.[0])} />
            </label>
          ) : null}
          <p className="text-xs text-muted">{t('leave.privateHint')}</p>
        </section>
      ) : null}

      <section className="flex flex-col gap-2">
        <h3 className="text-xs font-semibold tracking-wide text-muted uppercase">{t('leave.timeline')}</h3>
        <ol aria-label={t('leave.timeline')} className="flex flex-col">
          {request.timeline.map((s, i) => (
            <Step key={`${s.stage}-${s.action ?? s.state}-${i}`} step={s} last={i === request.timeline.length - 1} />
          ))}
        </ol>
      </section>

      {request.cancel_request ? (
        <Alert tone="warning">
          {t('leave.cancelAsked', { name: request.cancel_request.by ?? name })}
          {request.cancel_request.reason ? ` “${request.cancel_request.reason}”` : null}
        </Alert>
      ) : null}

      {request.cover_needed.length > 0 ? (
        <section className="flex flex-col gap-2">
          <h3 className="text-xs font-semibold tracking-wide text-muted uppercase">{t('leave.cover.title')}</h3>
          <div tabIndex={0} role="region" aria-label={t('leave.cover.title')} className="relative overflow-x-auto rounded-input border border-border focus-visible:outline-2 focus-visible:outline-ring">
            <table className="w-full text-sm">
              <thead className="bg-surface-muted text-start text-xs text-muted">
                <tr>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('leave.cover.day')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('leave.cover.class')}</th>
                  <th scope="col" className="px-3 py-2 text-start font-medium">{t('leave.cover.what')}</th>
                </tr>
              </thead>
              <tbody>
                {request.cover_needed.map((row, i) => (
                  <tr key={`${row.date}-${row.section_id}-${row.subject ?? 'register'}-${i}`} className="border-t border-border">
                    <td className="px-3 py-2 whitespace-nowrap">{formatDate(asDate(row.date), i18n.language)}</td>
                    <td className="px-3 py-2">{row.section}</td>
                    <td className="px-3 py-2">{row.kind === 'register' ? t('leave.cover.register') : row.subject}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="text-xs text-muted">{t('leave.cover.hint')}</p>
        </section>
      ) : null}

      {error && !pending ? <Alert tone="danger">{error}</Alert> : null}

      <div className="flex flex-wrap gap-2">
        {can.approve ? (
          <>
            <Button className="h-11" disabled={busy} onClick={() => open({ move: 'approve', needsText: false, title: t('leave.actions.approveTitle', { name }), label: t('leave.actions.approve') })}>
              <Check aria-hidden="true" />
              {can.fast_track ? t('leave.actions.approveFast') : t('leave.actions.approve')}
            </Button>
            <Button
              variant="outline"
              className="h-11"
              disabled={busy}
              onClick={() => open({ move: 'approve', needsText: false, dates: true, title: t('leave.actions.otherDatesTitle', { name }), label: t('leave.actions.approve') })}
            >
              {t('leave.actions.otherDates')}
            </Button>
          </>
        ) : null}
        {can.verify ? (
          <Button className="h-11" disabled={busy} onClick={() => open({ move: 'verify', needsText: false, title: t('leave.actions.verifyTitle', { name }), label: t('leave.actions.verify') })}>
            <Check aria-hidden="true" />
            {t('leave.actions.verify')}
          </Button>
        ) : null}
        {can.return ? (
          <Button variant="outline" className="h-11" disabled={busy} onClick={() => open({ move: 'return', needsText: true, title: t('leave.actions.returnTitle', { name }), label: t('leave.actions.return') })}>
            <CornerUpLeft aria-hidden="true" />
            {t('leave.actions.return')}
          </Button>
        ) : null}
        {can.reject ? (
          <Button variant="outline" className="h-11 text-danger" disabled={busy} onClick={() => open({ move: 'reject', needsText: true, title: t('leave.actions.rejectTitle', { name }), label: t('leave.actions.reject') })}>
            <X aria-hidden="true" />
            {t('leave.actions.reject')}
          </Button>
        ) : null}
        {can.submit ? (
          <Button className="h-11" disabled={busy} onClick={() => void run('submit')}>
            {t('leave.actions.submit')}
          </Button>
        ) : null}
        {can.withdraw ? (
          <Button variant="outline" className="h-11" disabled={busy} onClick={() => open({ move: 'withdraw', needsText: false, title: t('leave.actions.withdrawTitle'), label: t('leave.actions.withdraw') })}>
            <Undo2 aria-hidden="true" />
            {t('leave.actions.withdraw')}
          </Button>
        ) : null}
        {can.request_cancel ? (
          <Button variant="outline" className="h-11" disabled={busy} onClick={() => open({ move: 'cancel-request', needsText: true, title: t('leave.actions.requestCancelTitle'), label: t('leave.actions.requestCancel') })}>
            {t('leave.actions.requestCancel')}
          </Button>
        ) : null}
        {can.cancel ? (
          <Button
            variant="outline"
            className="h-11 text-danger"
            disabled={busy}
            onClick={() => open({ move: 'cancel', needsText: !request.cancel_request, title: t('leave.actions.cancelTitle', { name }), label: t('leave.actions.cancel') })}
          >
            {t('leave.actions.cancel')}
          </Button>
        ) : null}
        {can.keep ? (
          <Button variant="outline" className="h-11" disabled={busy} onClick={() => open({ move: 'keep', needsText: true, title: t('leave.actions.keepTitle'), label: t('leave.actions.keep') })}>
            {t('leave.actions.keep')}
          </Button>
        ) : null}
      </div>

      <Dialog open={pending !== null} title={pending?.title ?? ''} onClose={() => setPending(null)}>
        <div className="flex flex-col gap-4">
          {pending?.dates ? (
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={t('leave.from')}>{(p) => <Input {...p} type="date" value={from} onChange={(e) => setFrom(e.target.value)} />}</Field>
              <Field label={t('leave.to')}>{(p) => <Input {...p} type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />}</Field>
            </div>
          ) : null}
          {pending?.move !== 'withdraw' ? (
            <Field label={pending?.needsText ? t('leave.remarkRequired') : t('leave.remarkOptional')} hint={pending?.move === 'reject' ? t('leave.rejectHint') : undefined}>
              {(p) => <Textarea {...p} rows={3} value={text} maxLength={500} onChange={(e) => setText(e.target.value)} />}
            </Field>
          ) : (
            <p className="text-sm">{t('leave.withdrawHint')}</p>
          )}
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <div className="flex flex-wrap justify-end gap-2">
            <Button variant="outline" className="h-11" onClick={() => setPending(null)}>
              {t('common.cancel')}
            </Button>
            <Button className="h-11" disabled={busy || (pending?.needsText === true && text.trim().length < 3)} onClick={confirm}>
              {pending?.label}
            </Button>
          </div>
        </div>
      </Dialog>
    </div>
  );
}
