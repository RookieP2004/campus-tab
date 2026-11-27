import { StatusChip } from '@campus-tab/ui';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Info, Lock, Send, Upload } from 'lucide-react';
import { type ClipboardEvent, type KeyboardEvent, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Dialog, Field, Input, Spinner, Textarea } from '@/components/ui/form';
import { useOnline } from '@/features/attendance/useOnline';
import { ApiError } from '@/lib/api';
import { errorText } from '@/features/auth/errors';
import { formatDate, formatDateTime } from '@/lib/format';
import { apiGet } from '@/lib/api';
import { cellText, type MarkRow, marks, type PaperDetail, type PaperMove, paperChip, parseCell } from './api';

type Draft = { text?: string; remark?: string };
type SaveState = { kind: 'idle' } | { kind: 'saving' } | { kind: 'saved'; at: Date } | { kind: 'error'; message: string } | { kind: 'conflict' };

const AUTOSAVE_MS = 1200;

const asDate = (ymd: string) => `${ymd}T12:00:00+05:30`;

/**
 * The markbook for one paper (Markbook.dc.html): a spreadsheet on desktop,
 * one card per student on phones. Tab moves across, Enter and the arrow
 * keys move down and up, and a column pasted from Excel fills down from the
 * cell. Codes AB and ML; anything above the maximum is refused before it is
 * sent. Changes save on their own after a pause, with If-Match: if someone
 * else saved meanwhile the server answers 412, their marks are shown and
 * yours stay highlighted to save again. Online only.
 */
export function MarkbookPage() {
  const { t, i18n } = useTranslation();
  const { id = '' } = useParams();
  const queryClient = useQueryClient();
  const online = useOnline();
  const key = useMemo(() => ['marks', 'paper', id], [id]);
  const paper = useQuery({ queryKey: key, queryFn: apiGet<PaperDetail>(`/papers/${id}`), retry: false });
  const [drafts, setDrafts] = useState<Record<string, Draft>>({});
  const [save, setSave] = useState<SaveState>({ kind: 'idle' });
  const [action, setAction] = useState<{ move: PaperMove | 'correct'; row?: MarkRow } | null>(null);
  const [note, setNote] = useState('');
  const [newMark, setNewMark] = useState('');
  const [actionError, setActionError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const timer = useRef<number | undefined>(undefined);
  const inputs = useRef<Map<string, HTMLInputElement>>(new Map());

  const data = paper.data;
  const max = data?.max_marks ?? 0;
  const editable = Boolean(data?.can.enter) && online;

  const textOf = useCallback((row: MarkRow) => drafts[row.student_id]?.text ?? cellText(row), [drafts]);
  const remarkOf = useCallback((row: MarkRow) => drafts[row.student_id]?.remark ?? row.remark ?? '', [drafts]);

  const checks = useMemo(() => {
    const out: Record<string, ReturnType<typeof parseCell>> = {};
    for (const row of data?.rows ?? []) out[row.student_id] = parseCell(textOf(row), max);
    return out;
  }, [data, textOf, max]);

  const errors = Object.values(checks).filter((c) => !c.ok).length;
  const dirty = Object.keys(drafts).length;

  const flush = useCallback(async () => {
    if (!data || dirty === 0) return;
    const changes: Record<string, { value: number | null; code: string | null; remark: string | null }> = {};
    for (const [studentId, draft] of Object.entries(drafts)) {
      const row = data.rows.find((r) => r.student_id === studentId);
      if (!row) continue;
      const check = parseCell(draft.text ?? cellText(row), max);
      if (!check.ok) continue;
      changes[studentId] = { ...check.mark, remark: (draft.remark ?? row.remark ?? '').trim() || null };
    }
    if (Object.keys(changes).length === 0) return;

    setSave({ kind: 'saving' });
    try {
      const updated = await marks.save(data.id, data.version, changes);
      queryClient.setQueryData(key, updated);
      // Keep only cells typed after this save started, or still invalid.
      setDrafts((current) => {
        const next: Record<string, Draft> = {};
        for (const [studentId, draft] of Object.entries(current)) {
          const sent = changes[studentId];
          const row = updated.rows.find((r) => r.student_id === studentId);
          if (!sent || !row) {
            next[studentId] = draft;
            continue;
          }
          const sameText = (draft.text ?? cellText(row)).trim().toUpperCase() === cellText(row);
          const sameRemark = (draft.remark ?? row.remark ?? '').trim() === (row.remark ?? '');
          if (!sameText || !sameRemark) next[studentId] = draft;
        }
        return next;
      });
      setSave({ kind: 'saved', at: new Date() });
    } catch (e) {
      if (e instanceof ApiError && e.code === 'paper_conflict') {
        setSave({ kind: 'conflict' });
        void queryClient.invalidateQueries({ queryKey: key });
      } else {
        setSave({ kind: 'error', message: errorText(t, e) });
      }
    }
  }, [data, drafts, dirty, max, queryClient, key, t]);

  // Autosave after a pause, never while there is a conflict to look at.
  useEffect(() => {
    window.clearTimeout(timer.current);
    if (dirty > 0 && editable && save.kind !== 'conflict' && save.kind !== 'saving') {
      timer.current = window.setTimeout(() => void flush(), AUTOSAVE_MS);
    }
    return () => window.clearTimeout(timer.current);
  }, [drafts, dirty, editable, save.kind, flush]);

  const setText = (studentId: string, text: string) => setDrafts((d) => ({ ...d, [studentId]: { ...d[studentId], text } }));
  const setRemark = (studentId: string, remark: string) => setDrafts((d) => ({ ...d, [studentId]: { ...d[studentId], remark } }));

  const rows = useMemo(() => data?.rows ?? [], [data]);
  const focusRow = (index: number, field: 'mark' | 'remark' = 'mark') => {
    const target = rows[index];
    if (target) inputs.current.get(`${field}:${target.student_id}`)?.focus();
  };

  const onKey = (event: KeyboardEvent<HTMLInputElement>, index: number, field: 'mark' | 'remark') => {
    if (event.key === 'Enter' || event.key === 'ArrowDown') {
      event.preventDefault();
      focusRow(index + 1, field);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      focusRow(index - 1, field);
    }
  };

  // A column pasted from Excel fills down from this cell (a second column goes into the remarks).
  const onPaste = (event: ClipboardEvent<HTMLInputElement>, index: number) => {
    const text = event.clipboardData.getData('text');
    if (!text.includes('\n') && !text.includes('\t')) return;
    event.preventDefault();
    const lines = text.replace(/\r/g, '').split('\n').filter((l, i, all) => !(i === all.length - 1 && l === ''));
    setDrafts((d) => {
      const next = { ...d };
      lines.forEach((line, offset) => {
        const row = rows[index + offset];
        if (!row) return;
        const [mark = '', remark] = line.split('\t');
        next[row.student_id] = { ...next[row.student_id], text: mark.trim(), ...(remark !== undefined ? { remark: remark.trim() } : {}) };
      });
      return next;
    });
  };

  const stats = useMemo(() => {
    let entered = 0;
    let absent = 0;
    const numbers: number[] = [];
    for (const row of rows) {
      const check = checks[row.student_id];
      if (!check?.ok) continue;
      if (check.mark.code) {
        entered++;
        absent++;
      } else if (check.mark.value !== null) {
        entered++;
        numbers.push(check.mark.value);
      }
    }
    const average = numbers.length ? numbers.reduce((a, b) => a + b, 0) / numbers.length : null;
    return { entered, absent, average, highest: numbers.length ? Math.max(...numbers) : null };
  }, [rows, checks]);

  const runMove = async (move: PaperMove, body: Record<string, unknown> = {}) => {
    if (!data) return;
    setActionError(null);
    try {
      const updated = await marks.move(data.id, move, body);
      queryClient.setQueryData(key, updated);
      void queryClient.invalidateQueries({ queryKey: ['marks'] });
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
      setAction(null);
      setNote('');
      setDone(t(`tests.done.${move}`));
    } catch (e) {
      setActionError(errorText(t, e));
    }
  };

  const raise = async () => {
    if (!data || !action?.row) return;
    const check = parseCell(newMark, max);
    if (!check.ok) return;
    setActionError(null);
    try {
      await marks.raiseCorrection({ paper_id: data.id, student_id: action.row.student_id, ...check.mark, reason: note.trim() });
      void queryClient.invalidateQueries({ queryKey: key });
      setAction(null);
      setNote('');
      setNewMark('');
      setDone(t('tests.correction.sent'));
    } catch (e) {
      setActionError(errorText(t, e));
    }
  };

  if (paper.isPending) return <Spinner label={t('common.loading')} />;
  if (paper.error || !data) return <Alert tone="danger">{errorText(t, paper.error)}</Alert>;

  const title = `${data.section} · ${data.subject}${data.assessment ? ` · ${data.assessment.name}` : ''}`;
  const markLabel = t('tests.markbook.marksOutOf', { max });
  const cellClass = (bad: boolean) =>
    `h-11 w-24 rounded-input border px-2 text-center text-base tabular-nums ${bad ? 'border-danger bg-danger/10' : 'border-border bg-surface'} focus-visible:outline-2 focus-visible:outline-ring disabled:bg-surface-muted disabled:text-muted`;

  const saveLine =
    !online ? (
      <span className="text-warning">{t('tests.markbook.offline')}</span>
    ) : save.kind === 'saving' ? (
      t('tests.markbook.saving')
    ) : save.kind === 'saved' ? (
      t('tests.markbook.savedAt', { time: formatDateTime(save.at.toISOString(), i18n.language) })
    ) : dirty > 0 ? (
      t('tests.markbook.unsaved', { count: dirty })
    ) : (
      t('tests.markbook.allSaved')
    );

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-4">
      <nav aria-label={t('tests.breadcrumb')} className="flex flex-wrap items-center gap-1 text-sm text-muted">
        <Link to="/tests" className="text-primary hover:underline">
          {t('nav.tests')}
        </Link>
        <span aria-hidden="true">/</span>
        {data.assessment ? (
          <Link to={`/tests/${data.assessment.id}`} className="text-primary hover:underline">
            {data.assessment.name}
          </Link>
        ) : null}
        <span aria-hidden="true">/</span>
        <span>
          {data.section} {data.subject}
        </span>
      </nav>

      <div className="flex flex-wrap items-center gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
        <StatusChip status={paperChip(data)} label={t(`tests.chip.${paperChip(data)}`)} />
        <span role="status" aria-live="polite" className="ms-auto text-sm text-muted">
          {saveLine}
        </span>
      </div>

      <p className="flex flex-wrap gap-x-5 gap-y-1 text-sm text-muted">
        <span>
          <strong className="text-fg">{t('tests.maxMarks')}</strong> {max}
        </span>
        {data.date ? (
          <span>
            <strong className="text-fg">{t('tests.date')}</strong> {formatDate(asDate(data.date), i18n.language)}
          </span>
        ) : null}
        {data.assessment?.entry_closes_at && data.status === 'open' ? (
          <span>
            <strong className="text-fg">{t('tests.closes')}</strong> {formatDateTime(data.assessment.entry_closes_at, i18n.language)}
          </span>
        ) : null}
        <span>
          <strong className="text-fg">{t('tests.codes')}</strong> {t('tests.codesHint')}
        </span>
      </p>

      {done ? <Alert tone="success">{done}</Alert> : null}
      {data.returned_note && data.status === 'open' ? <Alert tone="warning">{t('tests.markbook.returned', { note: data.returned_note })}</Alert> : null}
      {save.kind === 'conflict' ? (
        <Alert tone="warning">
          <p>{t('tests.markbook.conflict')}</p>
          <Button className="mt-2 h-11" onClick={() => void flush()}>
            {t('tests.markbook.saveMine')}
          </Button>
        </Alert>
      ) : null}
      {save.kind === 'error' ? <Alert tone="danger">{save.message}</Alert> : null}
      {data.status === 'locked' ? (
        <Alert tone="info">
          <Lock aria-hidden="true" className="me-1 inline size-4" />
          {t('tests.markbook.locked', { name: data.locked?.by ?? '' })}
        </Alert>
      ) : null}

      <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        {[
          [t('tests.stats.entered'), t('tests.stats.enteredOf', { count: stats.entered, total: rows.length })],
          [t('tests.stats.average'), stats.average === null ? '–' : stats.average.toFixed(1)],
          [t('tests.stats.highest'), stats.highest === null ? '–' : String(stats.highest)],
          [t('tests.stats.absent'), String(stats.absent)],
        ].map(([label, value]) => (
          <div key={label} className="rounded-card border border-border bg-surface p-3">
            <dt className="text-xs text-muted">{label}</dt>
            <dd className="text-xl font-semibold tabular-nums">{value}</dd>
          </div>
        ))}
      </dl>

      {/* Desktop and tablet: the spreadsheet. */}
      <div className="relative hidden overflow-x-auto rounded-card border border-border bg-surface sm:block">
        <table className="w-full text-sm">
          <caption className="sr-only">{title}</caption>
          <thead className="bg-surface-muted text-xs text-muted">
            <tr>
              <th scope="col" className="w-16 px-3 py-2 text-start font-medium">{t('tests.markbook.roll')}</th>
              <th scope="col" className="px-3 py-2 text-start font-medium">{t('tests.markbook.student')}</th>
              <th scope="col" className="px-3 py-2 text-start font-medium">{markLabel}</th>
              <th scope="col" className="px-3 py-2 text-start font-medium">{t('tests.markbook.remark')}</th>
              {data.can.correct ? <th scope="col" className="px-3 py-2 text-start font-medium"><span className="sr-only">{t('tests.correction.ask')}</span></th> : null}
            </tr>
          </thead>
          <tbody>
            {rows.map((row, index) => {
              const check = checks[row.student_id];
              const bad = check ? !check.ok : false;
              const pending = data.pending_corrections.includes(row.student_id);
              return (
                <tr key={row.student_id} className={`border-t border-border ${drafts[row.student_id] ? 'bg-primary-soft/40' : ''}`}>
                  <td className="px-3 py-1.5 tabular-nums">{row.roll_no ?? '–'}</td>
                  <td className="px-3 py-1.5">{row.name}</td>
                  <td className="px-3 py-1.5">
                    <input
                      ref={(el) => {
                        if (el) inputs.current.set(`mark:${row.student_id}`, el);
                      }}
                      type="text"
                      inputMode="decimal"
                      autoComplete="off"
                      aria-label={t('tests.markbook.markFor', { name: row.name })}
                      aria-invalid={bad}
                      aria-describedby={bad ? `err-${row.student_id}` : undefined}
                      value={textOf(row)}
                      disabled={!editable}
                      onChange={(e) => setText(row.student_id, e.target.value)}
                      onKeyDown={(e) => onKey(e, index, 'mark')}
                      onPaste={(e) => onPaste(e, index)}
                      className={cellClass(bad)}
                    />
                    {bad && check && !check.ok ? (
                      <p id={`err-${row.student_id}`} className="mt-1 text-xs text-danger">
                        {check.reason === 'above' ? t('tests.markbook.above', { max }) : t('tests.markbook.invalid', { max })}
                      </p>
                    ) : null}
                  </td>
                  <td className="px-3 py-1.5">
                    <input
                      ref={(el) => {
                        if (el) inputs.current.set(`remark:${row.student_id}`, el);
                      }}
                      type="text"
                      aria-label={t('tests.markbook.remarkFor', { name: row.name })}
                      value={remarkOf(row)}
                      disabled={!editable}
                      maxLength={200}
                      placeholder={editable ? t('tests.markbook.optional') : ''}
                      onChange={(e) => setRemark(row.student_id, e.target.value)}
                      onKeyDown={(e) => onKey(e, index, 'remark')}
                      className="h-11 w-full min-w-40 rounded-input border border-border bg-surface px-2 text-sm focus-visible:outline-2 focus-visible:outline-ring disabled:border-transparent disabled:bg-transparent"
                    />
                  </td>
                  {data.can.correct ? (
                    <td className="px-3 py-1.5 text-end">
                      {pending ? (
                        <span className="text-xs text-muted">{t('tests.correction.waiting')}</span>
                      ) : (
                        <Button size="sm" variant="outline" className="h-11" onClick={() => setAction({ move: 'correct', row })}>
                          {t('tests.correction.ask')}
                          <span className="sr-only">: {row.name}</span>
                        </Button>
                      )}
                    </td>
                  ) : null}
                </tr>
              );
            })}
          </tbody>
        </table>
        <p className="border-t border-border px-3 py-2 text-xs text-muted">{t('tests.markbook.keys')}</p>
      </div>

      {/* Phones: one card per student, with a number keypad. */}
      <ul className="flex flex-col gap-2 sm:hidden">
        {rows.map((row) => {
          const check = checks[row.student_id];
          const bad = check ? !check.ok : false;
          return (
            <li key={row.student_id} className={`flex flex-col gap-2 rounded-card border bg-surface p-3 ${drafts[row.student_id] ? 'border-primary' : 'border-border'}`}>
              <div className="flex items-center gap-2">
                <span className="w-8 shrink-0 text-sm text-muted tabular-nums">{row.roll_no ?? '–'}</span>
                <span className="min-w-0 flex-1 text-sm font-medium break-words">{row.name}</span>
                <input
                  type="text"
                  inputMode="decimal"
                  autoComplete="off"
                  aria-label={t('tests.markbook.markFor', { name: row.name })}
                  aria-invalid={bad}
                  value={textOf(row)}
                  disabled={!editable}
                  onChange={(e) => setText(row.student_id, e.target.value)}
                  className={cellClass(bad)}
                />
              </div>
              {bad && check && !check.ok ? <p className="text-xs text-danger">{check.reason === 'above' ? t('tests.markbook.above', { max }) : t('tests.markbook.invalid', { max })}</p> : null}
              {editable || row.remark ? (
                <input
                  type="text"
                  aria-label={t('tests.markbook.remarkFor', { name: row.name })}
                  value={remarkOf(row)}
                  disabled={!editable}
                  maxLength={200}
                  placeholder={t('tests.markbook.remarkOptional')}
                  onChange={(e) => setRemark(row.student_id, e.target.value)}
                  className="h-11 rounded-input border border-border bg-surface px-2 text-sm focus-visible:outline-2 focus-visible:outline-ring"
                />
              ) : null}
              {data.can.correct && !data.pending_corrections.includes(row.student_id) ? (
                <Button size="sm" variant="outline" className="h-11" onClick={() => setAction({ move: 'correct', row })}>
                  {t('tests.correction.ask')}
                </Button>
              ) : null}
            </li>
          );
        })}
      </ul>

      <div className="sticky bottom-16 z-10 flex flex-wrap items-center gap-2 rounded-card border border-border bg-surface p-3 shadow-sm md:bottom-2">
        {data.can.enter ? (
          <Button variant="outline" className="h-11" disabled={!editable || dirty === 0 || save.kind === 'saving'} onClick={() => void flush()}>
            <Upload aria-hidden="true" />
            {t('tests.markbook.saveNow')}
          </Button>
        ) : null}
        {data.can.submit ? (
          <Button className="h-11" disabled={!online || errors > 0 || dirty > 0 || stats.entered < rows.length} onClick={() => void runMove('submit')}>
            <Send aria-hidden="true" />
            {errors > 0 ? t('tests.markbook.fix', { count: errors }) : stats.entered < rows.length ? t('tests.markbook.missing', { count: rows.length - stats.entered }) : t('tests.actions.submit')}
          </Button>
        ) : null}
        {data.can.verify ? (
          <Button className="h-11" onClick={() => void runMove('verify')}>
            {t('tests.actions.verify')}
          </Button>
        ) : null}
        {data.can.send_back ? (
          <Button variant="outline" className="h-11" onClick={() => setAction({ move: 'send-back' })}>
            {t('tests.actions.sendBack')}
          </Button>
        ) : null}
        {data.can.lock ? (
          <Button className="h-11" onClick={() => setAction({ move: 'lock' })}>
            <Lock aria-hidden="true" />
            {t('tests.actions.lock')}
          </Button>
        ) : null}
        {data.can.publish ? (
          <Button className="h-11" onClick={() => setAction({ move: 'publish' })}>
            {t('tests.actions.publish')}
          </Button>
        ) : null}
        {data.can.open ? (
          <Button className="h-11" onClick={() => void runMove('open')}>
            {t('tests.actions.open')}
          </Button>
        ) : null}
        {actionError && !action ? <Alert tone="danger" className="w-full">{actionError}</Alert> : null}
      </div>

      <p className="flex gap-2 rounded-card border border-border bg-surface p-3 text-sm text-muted">
        <Info aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
        {t('tests.markbook.flow')}
      </p>

      {data.history.length > 0 ? (
        <details className="rounded-card border border-border bg-surface p-3 text-sm">
          <summary className="cursor-pointer font-medium">{t('tests.history')}</summary>
          <ol className="mt-2 flex flex-col gap-1">
            {data.history.map((h, i) => (
              <li key={`${h.at}-${i}`} className="text-muted">
                {t(`tests.historyActions.${h.action}`, { name: h.by ?? '' })} · {formatDateTime(h.at, i18n.language)}
                {h.note ? ` · “${h.note}”` : ''}
              </li>
            ))}
          </ol>
        </details>
      ) : null}

      <Dialog
        open={action !== null}
        title={action?.move === 'correct' ? t('tests.correction.title', { name: action.row?.name ?? '' }) : action ? t(`tests.confirm.${action.move}`) : ''}
        onClose={() => setAction(null)}
      >
        <div className="flex flex-col gap-4">
          {action?.move === 'correct' && action.row ? (
            <>
              <p className="text-sm">{t('tests.correction.current', { mark: cellText(action.row) || '–' })}</p>
              <Field label={t('tests.correction.newMark', { max })}>{(p) => <Input {...p} inputMode="decimal" value={newMark} onChange={(e) => setNewMark(e.target.value)} />}</Field>
              <Field label={t('tests.correction.reason')} hint={t('tests.correction.hint')}>
                {(p) => <Textarea {...p} rows={3} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} />}
              </Field>
            </>
          ) : null}
          {action?.move === 'send-back' ? (
            <Field label={t('tests.sendBackNote')}>{(p) => <Textarea {...p} rows={3} maxLength={300} value={note} onChange={(e) => setNote(e.target.value)} />}</Field>
          ) : null}
          {action?.move === 'lock' ? <p className="text-sm">{t('tests.lockHint')}</p> : null}
          {action?.move === 'publish' ? <p className="text-sm">{t('tests.publishHint')}</p> : null}
          {actionError ? <Alert tone="danger">{actionError}</Alert> : null}
          <div className="flex justify-end gap-2">
            <Button variant="outline" className="h-11" onClick={() => setAction(null)}>
              {t('common.cancel')}
            </Button>
            {action?.move === 'correct' ? (
              <Button className="h-11" disabled={!parseCell(newMark, max).ok || newMark.trim() === '' || note.trim().length < 10} onClick={() => void raise()}>
                {t('tests.correction.send')}
              </Button>
            ) : (
              <Button
                className="h-11"
                disabled={action?.move === 'send-back' && note.trim().length < 3}
                onClick={() => {
                  if (action && action.move !== 'correct') void runMove(action.move, action.move === 'send-back' ? { note: note.trim() } : {});
                }}
              >
                {action ? t(`tests.actions.${action.move === 'send-back' ? 'sendBack' : action.move}`) : ''}
              </Button>
            )}
          </div>
        </div>
      </Dialog>
    </div>
  );
}
