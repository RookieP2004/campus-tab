import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Alert, Card, Select } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { rupees } from '@/features/fees/api';
import { StepUpField } from '@/features/fees/StepUpField';
import { isStepUpRequest, useStepUp } from '@/features/fees/useStepUp';
import { type DuesChoice, type PromotionPreview, type PromotionTarget, reports } from './api';

/**
 * The wizard's unpaid-dues step. Every student with a fee balance for the
 * closing year is listed; each balance is carried forward (the default) or
 * left in the closed year. The Principal approves the whole list once, with
 * a fresh authenticator code. The year cannot close until every balance is
 * decided.
 */
export function DuesStep({
  preview,
  target,
  onSettled,
}: {
  preview: PromotionPreview;
  target: PromotionTarget;
  onSettled: (yearId: string, message: string) => void;
}) {
  const { t, i18n } = useTranslation();
  const stepUp = useStepUp();
  const [choices, setChoices] = useState<Record<string, DuesChoice>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const dues = preview.dues;
  const open = dues.students.filter((s) => s.status === 'open');
  const leaving = open.filter((s) => choices[s.id] === 'leave');

  if (dues.students.length === 0) {
    return (
      <Card className="flex flex-col gap-2">
        <h2 className="text-lg font-semibold">{t('reports.promotion.dues.title')}</h2>
        <p className="text-sm text-muted">{t('reports.promotion.dues.none')}</p>
      </Card>
    );
  }

  const approve = async () => {
    setBusy(true);
    setError(null);
    try {
      const decisions = Object.fromEntries(open.map((s) => [s.id, choices[s.id] ?? 'carry']));
      const result = await stepUp.run(() => reports.carryForward(target, decisions, dues.digest));
      onSettled(
        result.to.id,
        t('reports.promotion.dues.done', {
          carried: result.carried,
          carriedAmount: rupees(result.carried_paise, i18n.language),
          left: result.left,
          to: result.to.name,
        }),
      );
    } catch (e) {
      if (!isStepUpRequest(e)) setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card className="flex flex-col gap-3">
      <h2 className="text-lg font-semibold">{t('reports.promotion.dues.title')}</h2>
      <p className="text-sm">{t('reports.promotion.dues.intro', { count: dues.students.length, total: rupees(dues.total_paise, i18n.language), year: preview.from.name })}</p>
      <ul className="flex flex-col divide-y divide-border">
        {dues.students.map((s) => (
          <li key={s.id} className="flex flex-wrap items-center gap-2 py-2">
            <span className="min-w-0 flex-1 basis-40 text-sm">
              {s.name}
              {s.section ? <span className="ms-2 text-xs text-muted">{t('reports.sectionName', { label: s.section })}</span> : null}
            </span>
            <span className="text-sm font-medium tabular-nums">{rupees(s.balance_paise, i18n.language)}</span>
            {s.status === 'left' ? (
              <span className="rounded-full bg-surface-muted px-2 py-1 text-xs">{t('reports.promotion.dues.leftDone', { year: preview.from.name })}</span>
            ) : (
              <Select
                aria-label={t('reports.promotion.dues.choiceFor', { name: s.name })}
                value={choices[s.id] ?? 'carry'}
                disabled={busy}
                onChange={(e) => setChoices({ ...choices, [s.id]: e.target.value as DuesChoice })}
                className="h-11 w-full sm:w-52"
              >
                <option value="carry">{t('reports.promotion.dues.carry', { year: preview.to.name })}</option>
                <option value="leave">{t('reports.promotion.dues.leave', { year: preview.from.name })}</option>
              </Select>
            )}
          </li>
        ))}
      </ul>
      {open.length > 0 ? (
        <>
          {leaving.length > 0 ? <Alert tone="warning">{t('reports.promotion.dues.leaveWarning', { count: leaving.length, year: preview.from.name })}</Alert> : null}
          <StepUpField state={stepUp} />
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <div>
            <Button className="h-11" disabled={busy || !stepUp.ready} onClick={() => void approve()}>
              {stepUp.needed ? t('reports.promotion.dues.confirmApprove') : t('reports.promotion.dues.approve', { count: open.length })}
            </Button>
          </div>
        </>
      ) : (
        <Alert tone="success">{t('reports.promotion.dues.allDecided')}</Alert>
      )}
    </Card>
  );
}
