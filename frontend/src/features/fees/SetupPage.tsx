import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Field, Input, Select, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { apiGet } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { fees, type Head, type Plan, rupeeInput, rupees, validRupees } from './api';

const asDate = (ymd: string) => `${ymd}T12:00:00+05:30`;

interface DraftInstalment {
  label: string;
  due_on: string;
  amounts: Record<string, string>;
}

interface DraftPlan {
  id: string | null;
  name: string;
  grades: string;
  instalments: DraftInstalment[];
  fineMode: 'none' | 'flat' | 'per_day';
  fineAmount: string;
  graceDays: string;
  fineCap: string;
  sibling: string;
}

const emptyPlan = (): DraftPlan => ({ id: null, name: '', grades: '', instalments: [{ label: '', due_on: '', amounts: {} }], fineMode: 'none', fineAmount: '', graceDays: '0', fineCap: '', sibling: '' });

function draftFrom(p: Plan): DraftPlan {
  const sibling = p.discounts.find((d) => d.condition === 'sibling' && d.kind === 'percent');

  return {
    id: p.id,
    name: p.name,
    grades: p.grades.join(', '),
    instalments: p.instalments.map((i) => ({ label: i.label, due_on: i.due_on, amounts: Object.fromEntries(i.lines.map((l) => [l.head_id, rupeeInput(l.amount_paise)])) })),
    fineMode: p.fine.mode,
    fineAmount: p.fine.amount_paise ? rupeeInput(p.fine.amount_paise) : '',
    graceDays: String(p.fine.grace_days ?? 0),
    fineCap: p.fine.cap_paise !== null ? rupeeInput(p.fine.cap_paise) : '',
    sibling: sibling ? `${Math.trunc(sibling.value / 100)}${sibling.value % 100 ? `.${String(sibling.value % 100).padStart(2, '0')}` : ''}` : '',
  };
}

/**
 * Fee set-up (Principal, Clerk, Chief): heads, categories and plans with
 * their instalments, late fine and sibling discount. Changing a plan
 * changes only instalments not raised yet. Dues are raised every night as
 * they come due; "Raise dues now" does it at once (for example at year start).
 */
export function SetupPage() {
  const { t, i18n } = useTranslation();
  const queryClient = useQueryClient();
  const heads = useQuery({ queryKey: ['fees', 'heads'], queryFn: apiGet<Head[]>('/fees/heads'), retry: false });
  const categories = useQuery({ queryKey: ['fees', 'categories'], queryFn: apiGet<Head[]>('/fees/categories'), retry: false });
  const plans = useQuery({ queryKey: ['fees', 'plans'], queryFn: apiGet<Plan[]>('/fees/plans'), retry: false });
  const [headName, setHeadName] = useState('');
  const [categoryName, setCategoryName] = useState('');
  const [draft, setDraft] = useState<DraftPlan | null>(null);
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const activeHeads = (heads.data ?? []).filter((h) => h.status === 'active');

  const act = async <T,>(work: () => Promise<T>, done: string | ((result: T) => string), after?: () => void) => {
    setBusy(true);
    setMessage(null);
    try {
      const result = await work();
      setMessage({ tone: 'success', text: typeof done === 'function' ? done(result) : done });
      after?.();
      void queryClient.invalidateQueries({ queryKey: ['fees'] });
    } catch (e) {
      setMessage({ tone: 'danger', text: errorText(t, e) });
    } finally {
      setBusy(false);
    }
  };

  const savePlan = () => {
    if (!draft) return;
    const body = {
      name: draft.name.trim(),
      grades: draft.grades.split(/[\s,]+/).filter(Boolean),
      instalments: draft.instalments.map((i) => ({
        label: i.label.trim(),
        due_on: i.due_on,
        lines: activeHeads.filter((h) => (i.amounts[h.id] ?? '').trim() !== '').map((h) => ({ head_id: h.id, amount: (i.amounts[h.id] ?? '').trim() })),
      })),
      fine: { mode: draft.fineMode, amount: draft.fineAmount || null, grace_days: Number(draft.graceDays || 0), cap: draft.fineCap || null },
      discounts: draft.sibling ? [{ label: t('fees.setup.siblingLabel'), condition: 'sibling', kind: 'percent', value: draft.sibling, head_ids: [] }] : [],
    };
    void act(() => (draft.id ? fees.updatePlan(draft.id, body) : fees.createPlan(body)), t('fees.setup.planSaved'), () => setDraft(null));
  };

  const amountsValid = draft?.instalments.every((i) => i.label.trim() && i.due_on && Object.values(i.amounts).every((v) => v.trim() === '' || validRupees(v)) && Object.values(i.amounts).some((v) => v.trim() !== ''));

  return (
    <div className="mx-auto flex max-w-5xl flex-col gap-4">
      <Link to="/fees" className="text-sm font-medium text-primary hover:underline">
        {t('fees.back')}
      </Link>
      <h1 className="text-2xl font-semibold tracking-tight">{t('fees.setup.title')}</h1>
      {message ? <Alert tone={message.tone}>{message.text}</Alert> : null}
      <div className="grid gap-4 md:grid-cols-2">
        <Card className="flex flex-col gap-3">
          <h2 className="text-lg font-semibold">{t('fees.setup.heads')}</h2>
          {heads.isPending ? <Spinner label={t('common.loading')} /> : null}
          <ul className="flex flex-wrap gap-2">
            {(heads.data ?? []).map((h) => (
              <li key={h.id} className="rounded-full border border-border px-3 py-1 text-sm">
                {h.name}
              </li>
            ))}
          </ul>
          <div className="flex flex-wrap items-end gap-2">
            <Field label={t('fees.setup.newHead')} className="min-w-0 flex-1">
              {(f) => <Input {...f} value={headName} maxLength={60} onChange={(e) => setHeadName(e.target.value)} />}
            </Field>
            <Button className="h-11" disabled={busy || headName.trim().length < 2} onClick={() => void act(() => fees.createHead(headName.trim()), t('fees.setup.added'), () => setHeadName(''))}>
              <Plus aria-hidden="true" />
              {t('fees.setup.add')}
            </Button>
          </div>
        </Card>
        <Card className="flex flex-col gap-3">
          <h2 className="text-lg font-semibold">{t('fees.setup.categories')}</h2>
          <ul className="flex flex-wrap gap-2">
            {(categories.data ?? []).map((c) => (
              <li key={c.id} className="rounded-full border border-border px-3 py-1 text-sm">
                {c.name}
              </li>
            ))}
          </ul>
          <div className="flex flex-wrap items-end gap-2">
            <Field label={t('fees.setup.newCategory')} className="min-w-0 flex-1">
              {(f) => <Input {...f} value={categoryName} maxLength={60} onChange={(e) => setCategoryName(e.target.value)} />}
            </Field>
            <Button className="h-11" disabled={busy || categoryName.trim().length < 2} onClick={() => void act(() => fees.createCategory(categoryName.trim()), t('fees.setup.added'), () => setCategoryName(''))}>
              <Plus aria-hidden="true" />
              {t('fees.setup.add')}
            </Button>
          </div>
        </Card>
      </div>

      <Card className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-lg font-semibold">{t('fees.setup.plans')}</h2>
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" className="h-11" disabled={busy} onClick={() => void act(() => fees.raise(), (r) => t('fees.setup.raised', r))}>
              {t('fees.setup.raise')}
            </Button>
            <Button className="h-11" disabled={busy || activeHeads.length === 0} onClick={() => setDraft(emptyPlan())}>
              <Plus aria-hidden="true" />
              {t('fees.setup.newPlan')}
            </Button>
          </div>
        </div>
        <p className="text-xs text-muted">{t('fees.setup.raiseNote')}</p>
        {plans.isPending ? <Spinner label={t('common.loading')} /> : null}
        {plans.error ? <Alert tone="danger">{errorText(t, plans.error)}</Alert> : null}
        {plans.data?.length === 0 ? <p className="text-sm text-muted">{t('fees.setup.noPlans')}</p> : null}
        <ul className="flex flex-col divide-y divide-border">
          {(plans.data ?? []).map((p) => (
            <li key={p.id} className="flex flex-wrap items-center gap-3 py-3">
              <span className="min-w-40 flex-1">
                <span className="block font-medium">{p.name}</span>
                <span className="block text-xs text-muted">
                  {t('fees.setup.planSummary', { grades: p.grades.join(', '), count: p.instalments.length, total: rupees(p.total_paise, i18n.language) })}
                </span>
              </span>
              <Button variant="outline" className="h-11" onClick={() => setDraft(draftFrom(p))}>
                {t('fees.setup.edit')}
              </Button>
            </li>
          ))}
        </ul>
      </Card>

      {draft ? (
        <Card className="flex flex-col gap-4">
          <h2 className="text-lg font-semibold">{draft.id ? t('fees.setup.editPlan') : t('fees.setup.newPlan')}</h2>
          {draft.id ? <Alert tone="info">{t('fees.setup.editNote')}</Alert> : null}
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('fees.setup.planName')}>{(f) => <Input {...f} value={draft.name} maxLength={80} onChange={(e) => setDraft({ ...draft, name: e.target.value })} />}</Field>
            <Field label={t('fees.setup.grades')} hint={t('fees.setup.gradesHint')}>
              {(f) => <Input {...f} value={draft.grades} onChange={(e) => setDraft({ ...draft, grades: e.target.value })} />}
            </Field>
          </div>
          <section className="flex flex-col gap-3">
            <h3 className="text-sm font-semibold">{t('fees.setup.instalments')}</h3>
            {draft.instalments.map((ins, n) => (
              <fieldset key={n} className="flex flex-col gap-2 rounded-input border border-border p-3">
                <legend className="px-1 text-xs text-muted">{t('fees.setup.instalmentN', { n: n + 1 })}</legend>
                <div className="grid gap-2 sm:grid-cols-[1fr_12rem_auto]">
                  <Field label={t('fees.setup.instalmentLabel')}>
                    {(f) => <Input {...f} value={ins.label} maxLength={40} onChange={(e) => setDraft({ ...draft, instalments: draft.instalments.map((x, i) => (i === n ? { ...x, label: e.target.value } : x)) })} />}
                  </Field>
                  <Field label={t('fees.setup.dueOn')}>
                    {(f) => <Input {...f} type="date" value={ins.due_on} onChange={(e) => setDraft({ ...draft, instalments: draft.instalments.map((x, i) => (i === n ? { ...x, due_on: e.target.value } : x)) })} />}
                  </Field>
                  <div className="flex items-end">
                    <Button
                      variant="ghost"
                      size="icon"
                      className="size-11"
                      aria-label={t('fees.setup.removeInstalment', { n: n + 1 })}
                      disabled={draft.instalments.length === 1}
                      onClick={() => setDraft({ ...draft, instalments: draft.instalments.filter((_, i) => i !== n) })}
                    >
                      <Trash2 aria-hidden="true" />
                    </Button>
                  </div>
                </div>
                <div className="grid gap-2 sm:grid-cols-3">
                  {activeHeads.map((h) => (
                    <Field key={h.id} label={h.name} error={ins.amounts[h.id] && !validRupees(ins.amounts[h.id] ?? '') ? t('fees.amountInvalid') : undefined}>
                      {(f) => (
                        <Input
                          {...f}
                          inputMode="decimal"
                          value={ins.amounts[h.id] ?? ''}
                          onChange={(e) => setDraft({ ...draft, instalments: draft.instalments.map((x, i) => (i === n ? { ...x, amounts: { ...x.amounts, [h.id]: e.target.value } } : x)) })}
                        />
                      )}
                    </Field>
                  ))}
                </div>
                {ins.due_on ? <p className="text-xs text-muted">{t('fees.dueOn', { date: formatDate(asDate(ins.due_on), i18n.language) })}</p> : null}
              </fieldset>
            ))}
            <div>
              <Button variant="outline" className="h-11" onClick={() => setDraft({ ...draft, instalments: [...draft.instalments, { label: '', due_on: '', amounts: {} }] })}>
                <Plus aria-hidden="true" />
                {t('fees.setup.addInstalment')}
              </Button>
            </div>
          </section>
          <section className="grid gap-3 sm:grid-cols-4">
            <Field label={t('fees.setup.fine')}>
              {(f) => (
                <Select {...f} value={draft.fineMode} onChange={(e) => setDraft({ ...draft, fineMode: e.target.value as DraftPlan['fineMode'] })}>
                  {(['none', 'flat', 'per_day'] as const).map((m) => (
                    <option key={m} value={m}>
                      {t(`fees.setup.fineModes.${m}`)}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
            {draft.fineMode !== 'none' ? (
              <>
                <Field label={t('fees.setup.fineAmount')}>{(f) => <Input {...f} inputMode="decimal" value={draft.fineAmount} onChange={(e) => setDraft({ ...draft, fineAmount: e.target.value })} />}</Field>
                <Field label={t('fees.setup.graceDays')}>{(f) => <Input {...f} inputMode="numeric" value={draft.graceDays} onChange={(e) => setDraft({ ...draft, graceDays: e.target.value.replace(/\D/g, '') })} />}</Field>
                <Field label={t('fees.setup.fineCap')} hint={t('fees.setup.optional')}>
                  {(f) => <Input {...f} inputMode="decimal" value={draft.fineCap} onChange={(e) => setDraft({ ...draft, fineCap: e.target.value })} />}
                </Field>
              </>
            ) : null}
          </section>
          <Field label={t('fees.setup.sibling')} hint={t('fees.setup.siblingHint')} className="max-w-xs">
            {(f) => <Input {...f} inputMode="decimal" value={draft.sibling} onChange={(e) => setDraft({ ...draft, sibling: e.target.value })} />}
          </Field>
          <div className="flex flex-wrap gap-2">
            <Button className="h-11" disabled={busy || draft.name.trim().length < 2 || draft.grades.trim() === '' || !amountsValid} onClick={savePlan}>
              {t('fees.setup.savePlan')}
            </Button>
            <Button variant="outline" className="h-11" onClick={() => setDraft(null)}>
              {t('common.cancel')}
            </Button>
          </div>
        </Card>
      ) : null}
    </div>
  );
}
