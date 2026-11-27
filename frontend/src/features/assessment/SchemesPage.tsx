import { useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Field, Input, Select, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { apiGet } from '@/lib/api';
import { marks, type Preset, type Scheme } from './api';

/**
 * Assessment schemes. A board preset (for example CBSE-style) only fills a
 * new scheme as a starting point, always shown with "check against your
 * board's latest circular"; the Principal changes every part of it.
 */
export function SchemesPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const schemes = useQuery({ queryKey: ['marks', 'schemes'], queryFn: apiGet<Scheme[]>('/assessment-schemes'), retry: false });
  const presets = useQuery({ queryKey: ['marks', 'presets'], queryFn: apiGet<Preset[]>('/assessment-schemes/presets'), retry: false });
  const [name, setName] = useState('');
  const [grades, setGrades] = useState('');
  const [preset, setPreset] = useState('');
  const [error, setError] = useState<string | null>(null);
  const chosen = presets.data?.find((p) => p.key === preset);

  const create = async () => {
    setError(null);
    try {
      await marks.createScheme({ name: name.trim(), grades: grades.split(/[\s,]+/).filter(Boolean), preset: preset || null });
      setName('');
      setGrades('');
      void queryClient.invalidateQueries({ queryKey: ['marks', 'schemes'] });
    } catch (e) {
      setError(errorText(t, e));
    }
  };

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4">
      <Link to="/tests" className="text-sm font-medium text-primary hover:underline">
        {t('tests.back')}
      </Link>
      <h1 className="text-2xl font-semibold tracking-tight">{t('tests.scheme.title')}</h1>
      <Card className="flex flex-col gap-3">
        <h2 className="text-lg font-semibold">{t('tests.scheme.new')}</h2>
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label={t('tests.scheme.name')}>{(f) => <Input {...f} value={name} onChange={(e) => setName(e.target.value)} maxLength={80} />}</Field>
          <Field label={t('tests.scheme.grades')} hint={t('tests.scheme.gradesHint')}>
            {(f) => <Input {...f} value={grades} onChange={(e) => setGrades(e.target.value)} />}
          </Field>
          <Field label={t('tests.scheme.preset')}>
            {(f) => (
              <Select {...f} value={preset} onChange={(e) => setPreset(e.target.value)}>
                <option value="">{t('tests.scheme.blank')}</option>
                {(presets.data ?? []).map((p) => (
                  <option key={p.key} value={p.key}>
                    {p.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        </div>
        {chosen ? (
          <Alert tone="warning">
            <AlertTriangle aria-hidden="true" className="me-1 inline size-4" />
            {chosen.note}
          </Alert>
        ) : null}
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <div className="flex justify-end">
          <Button className="h-11" disabled={name.trim().length < 2 || grades.trim() === ''} onClick={() => void create()}>
            <Plus aria-hidden="true" />
            {t('tests.scheme.create')}
          </Button>
        </div>
      </Card>
      {schemes.isPending ? <Spinner label={t('common.loading')} /> : null}
      {(schemes.data ?? []).map((s) => (
        <SchemeEditor key={s.id} scheme={s} />
      ))}
    </div>
  );
}

function SchemeEditor({ scheme }: { scheme: Scheme }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [draft, setDraft] = useState<Scheme>(scheme);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const total = draft.components.reduce((sum, c) => sum + Number(c.weight || 0), 0);

  const save = async () => {
    setError(null);
    setSaved(false);
    try {
      const updated = await marks.updateScheme(scheme.id, {
        name: draft.name,
        terms: draft.terms.filter((x) => x.trim() !== ''),
        components: draft.components.map((c) => ({ ...c, max: Number(c.max), weight: Number(c.weight) })),
        grade_scale: draft.grade_scale.map((g) => ({ ...g, min: Number(g.min) })),
        rules: { ...draft.rules, pass_percent: Number(draft.rules.pass_percent), grace_marks: Number(draft.rules.grace_marks) },
        status: draft.status,
      });
      setDraft(updated);
      setSaved(true);
      void queryClient.invalidateQueries({ queryKey: ['marks', 'schemes'] });
    } catch (e) {
      setError(errorText(t, e));
    }
  };

  const setComponent = (i: number, patch: Partial<Scheme['components'][number]>) =>
    setDraft((d) => ({ ...d, components: d.components.map((c, n) => (n === i ? { ...c, ...patch } : c)) }));
  const setGrade = (i: number, patch: Partial<Scheme['grade_scale'][number]>) =>
    setDraft((d) => ({ ...d, grade_scale: d.grade_scale.map((g, n) => (n === i ? { ...g, ...patch } : g)) }));

  return (
    <Card className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <h2 className="text-lg font-semibold">{draft.name}</h2>
        <span className="text-sm text-muted">{t('tests.scheme.forGrades', { grades: draft.grades.join(', ') })}</span>
      </div>
      {draft.preset_note ? (
        <Alert tone="warning">
          {t('tests.scheme.fromPreset', { preset: draft.preset_label ?? '' })} {draft.preset_note}
        </Alert>
      ) : null}
      <Field label={t('tests.scheme.terms')} hint={t('tests.scheme.termsHint')}>
        {(f) => <Input {...f} value={draft.terms.join(', ')} onChange={(e) => setDraft({ ...draft, terms: e.target.value.split(',').map((x) => x.trim()) })} />}
      </Field>

      <fieldset className="flex flex-col gap-2">
        <legend className="text-sm font-semibold">{t('tests.scheme.components')}</legend>
        {draft.components.map((c, i) => (
          <div key={i} className="grid grid-cols-2 items-end gap-2 sm:grid-cols-[1fr_2fr_1fr_1fr_auto]">
            <Field label={t('tests.scheme.key')}>{(f) => <Input {...f} value={c.key} onChange={(e) => setComponent(i, { key: e.target.value.toLowerCase() })} />}</Field>
            <Field label={t('tests.scheme.componentName')}>{(f) => <Input {...f} value={c.name} onChange={(e) => setComponent(i, { name: e.target.value })} />}</Field>
            <Field label={t('tests.maxMarks')}>{(f) => <Input {...f} type="number" value={c.max} onChange={(e) => setComponent(i, { max: Number(e.target.value) })} />}</Field>
            <Field label={t('tests.scheme.weight')}>{(f) => <Input {...f} type="number" value={c.weight} onChange={(e) => setComponent(i, { weight: Number(e.target.value) })} />}</Field>
            <Button variant="ghost" className="h-11" onClick={() => setDraft({ ...draft, components: draft.components.filter((_, n) => n !== i) })}>
              <Trash2 aria-hidden="true" />
              <span className="sr-only">{t('tests.scheme.remove', { name: c.name })}</span>
            </Button>
          </div>
        ))}
        <div className="flex flex-wrap items-center gap-3">
          <Button variant="outline" className="h-11" onClick={() => setDraft({ ...draft, components: [...draft.components, { key: `c${draft.components.length + 1}`, name: '', max: 10, weight: 0 }] })}>
            <Plus aria-hidden="true" />
            {t('tests.scheme.addComponent')}
          </Button>
          <span className={`text-sm ${Math.abs(total - 100) > 0.01 && draft.components.length > 0 ? 'text-danger' : 'text-muted'}`}>{t('tests.scheme.total', { total })}</span>
        </div>
      </fieldset>

      <fieldset className="flex flex-col gap-2">
        <legend className="text-sm font-semibold">{t('tests.scheme.gradeScale')}</legend>
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
          {draft.grade_scale.map((g, i) => (
            <div key={i} className="flex items-end gap-1">
              <Field label={t('tests.scheme.grade')} className="w-20">{(f) => <Input {...f} value={g.grade} onChange={(e) => setGrade(i, { grade: e.target.value })} />}</Field>
              <Field label={t('tests.scheme.from')} className="flex-1">{(f) => <Input {...f} type="number" value={g.min} onChange={(e) => setGrade(i, { min: Number(e.target.value) })} />}</Field>
            </div>
          ))}
        </div>
        <Button variant="outline" className="h-11 w-fit" onClick={() => setDraft({ ...draft, grade_scale: [...draft.grade_scale, { grade: '', min: 0 }] })}>
          <Plus aria-hidden="true" />
          {t('tests.scheme.addGrade')}
        </Button>
      </fieldset>

      <div className="grid gap-3 sm:grid-cols-3">
        <Field label={t('tests.scheme.pass')}>
          {(f) => <Input {...f} type="number" value={draft.rules.pass_percent} onChange={(e) => setDraft({ ...draft, rules: { ...draft.rules, pass_percent: Number(e.target.value) } })} />}
        </Field>
        <Field label={t('tests.scheme.grace')}>
          {(f) => <Input {...f} type="number" value={draft.rules.grace_marks} onChange={(e) => setDraft({ ...draft, rules: { ...draft.rules, grace_marks: Number(e.target.value) } })} />}
        </Field>
        <Field label={t('tests.scheme.rounding')}>
          {(f) => (
            <Select {...f} value={draft.rules.rounding} onChange={(e) => setDraft({ ...draft, rules: { ...draft.rules, rounding: e.target.value } })}>
              {['half_up', 'up', 'down', 'none'].map((r) => (
                <option key={r} value={r}>
                  {t(`tests.scheme.roundings.${r}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
      </div>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      {saved ? <Alert tone="success">{t('tests.scheme.saved')}</Alert> : null}
      <div className="flex justify-end">
        <Button className="h-11" onClick={() => void save()}>
          {t('common.save')}
        </Button>
      </div>
    </Card>
  );
}
