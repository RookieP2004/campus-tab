import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Dialog, Field, Input, Select } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { type PromotionAction, type PromotionPreview, type PromotionTarget, reports } from './api';

/**
 * The year-end promotion wizard. Step 1 names the new year; step 2 is the
 * full preview (every student and what happens to them, with what rolls
 * forward); the Principal changes any student, the preview refreshes, and
 * only then applies it, in one go. After that the old year is read-only.
 */
export function PromotionPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [target, setTarget] = useState<PromotionTarget>({ name: '', starts_on: '', ends_on: '' });
  const [exceptions, setExceptions] = useState<Record<string, PromotionAction>>({});
  const [preview, setPreview] = useState<PromotionPreview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirm, setConfirm] = useState(false);

  const load = async (next: Record<string, PromotionAction> = exceptions) => {
    setBusy(true);
    setError(null);
    try {
      setPreview(await reports.previewPromotion(target, next));
    } catch (e) {
      setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  const change = (id: string, action: PromotionAction) => {
    const next = { ...exceptions, [id]: action };
    setExceptions(next);
    void load(next);
  };

  const apply = async () => {
    if (!preview) return;
    setBusy(true);
    setError(null);
    try {
      const result = await reports.applyPromotion(target, exceptions, preview.digest);
      setDone(t('reports.promotion.done', { from: result.from.name, to: result.to.name }));
      setPreview(null);
      setConfirm(false);
      void queryClient.invalidateQueries();
    } catch (e) {
      setError(errorText(t, e));
      setConfirm(false);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4">
      <Link to="/report-cards" className="text-sm font-medium text-primary hover:underline">
        {t('reports.back')}
      </Link>
      <h1 className="text-2xl font-semibold tracking-tight">{t('reports.promotion.title')}</h1>
      <p className="text-sm text-muted">{t('reports.promotion.intro')}</p>
      {done ? <Alert tone="success">{done}</Alert> : null}
      <Card className="flex flex-col gap-3">
        <h2 className="text-lg font-semibold">{t('reports.promotion.step1')}</h2>
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label={t('reports.promotion.name')} hint={t('reports.promotion.nameHint')}>
            {(f) => <Input {...f} value={target.name ?? ''} maxLength={20} onChange={(e) => setTarget({ ...target, name: e.target.value })} />}
          </Field>
          <Field label={t('reports.promotion.starts')}>{(f) => <Input {...f} type="date" value={target.starts_on ?? ''} onChange={(e) => setTarget({ ...target, starts_on: e.target.value })} />}</Field>
          <Field label={t('reports.promotion.ends')}>{(f) => <Input {...f} type="date" value={target.ends_on ?? ''} onChange={(e) => setTarget({ ...target, ends_on: e.target.value })} />}</Field>
        </div>
        <div>
          <Button className="h-11" disabled={busy || !target.name || !target.starts_on || !target.ends_on} onClick={() => void load()}>
            {t('reports.promotion.preview')}
          </Button>
        </div>
      </Card>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      {preview ? (
        <>
          <Card className="flex flex-col gap-3">
            <h2 className="text-lg font-semibold">{t('reports.promotion.step2', { from: preview.from.name, to: preview.to.name })}</h2>
            <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
              {(['promote', 'detain', 'leave', 'graduate'] as const).map((a) => (
                <div key={a} className="rounded-input bg-surface-muted p-3">
                  <dt className="text-xs text-muted">{t(`reports.promotion.actions.${a}`)}</dt>
                  <dd className="text-2xl font-semibold tabular-nums">{preview.counts[a]}</dd>
                </div>
              ))}
            </dl>
            <p className="text-sm">{t('reports.promotion.rollForward', preview.roll_forward)}</p>
            {preview.roll_forward.new_classes?.length ? <p className="text-sm">{t('reports.promotion.newClasses', { list: preview.roll_forward.new_classes.map((g) => t('reports.promotion.classN', { grade: g })).join(', ') })}</p> : null}
            {preview.roll_forward.new_sections.length ? <p className="text-sm">{t('reports.promotion.newSections', { list: preview.roll_forward.new_sections.join(', ') })}</p> : null}
            <Alert tone="warning">{t('reports.promotion.warning', { year: preview.from.name })}</Alert>
            <div>
              <Button className="h-11" disabled={busy} onClick={() => setConfirm(true)}>
                {t('reports.promotion.apply')}
              </Button>
            </div>
          </Card>
          {preview.sections.map((s) => (
            <Card key={s.section_id} className="flex flex-col gap-2">
              <h3 className="font-semibold">
                {t('reports.sectionName', { label: s.section })}
                <span className="ms-2 text-xs font-normal text-muted">{s.next_class ? t('reports.promotion.nextClass', { grade: s.next_class }) : t('reports.promotion.lastClass')}</span>
              </h3>
              <ul className="flex flex-col divide-y divide-border">
                {s.students.map((st) => (
                  <li key={st.id} className="flex flex-wrap items-center gap-2 py-2">
                    <span className="min-w-40 flex-1 text-sm">
                      {st.roll_no ? `${st.roll_no}. ` : ''}
                      {st.name}
                      {st.result === 'fail' ? <span className="ms-2 text-xs text-status-rejected-fg">{t('reports.result.fail')}</span> : null}
                    </span>
                    <span className="text-xs text-muted">{st.to_section ? `→ ${st.to_section}` : ''}</span>
                    <Select
                      aria-label={t('reports.promotion.actionFor', { name: st.name })}
                      value={st.action}
                      disabled={busy}
                      onChange={(e) => change(st.id, e.target.value as PromotionAction)}
                      className="h-11 w-40"
                    >
                      {(s.next_class ? (['promote', 'detain', 'leave'] as const) : (['graduate', 'detain', 'leave'] as const)).map((a) => (
                        <option key={a} value={a}>
                          {t(`reports.promotion.actions.${a}`)}
                        </option>
                      ))}
                    </Select>
                  </li>
                ))}
              </ul>
            </Card>
          ))}
        </>
      ) : null}
      <Dialog open={confirm} title={t('reports.promotion.confirmTitle')} onClose={() => setConfirm(false)}>
        <p className="text-sm">{t('reports.promotion.confirmBody', { from: preview?.from.name ?? '', to: preview?.to.name ?? '' })}</p>
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="outline" className="h-11" onClick={() => setConfirm(false)}>
            {t('common.cancel')}
          </Button>
          <Button className="h-11" disabled={busy} onClick={() => void apply()}>
            {t('reports.promotion.confirm')}
          </Button>
        </div>
      </Dialog>
    </div>
  );
}
