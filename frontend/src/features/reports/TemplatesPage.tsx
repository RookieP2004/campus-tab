import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowDown, ArrowUp, ImageUp, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Field, Input, Select, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { apiGet } from '@/lib/api';
import { apiGetBody } from '@/lib/requests';
import { type BlockType, reports, type Template, type TemplatePresets } from './api';

/**
 * Report card templates. A preset only fills a new template as a starting
 * point (always shown with "check against your board's latest format");
 * the Principal turns blocks on or off, puts them in order and uploads the
 * school's letterhead. The logo and name come from the school settings.
 */
export function TemplatesPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const templates = useQuery({ queryKey: ['reports', 'templates'], queryFn: apiGet<Template[]>('/report-templates'), retry: false });
  const presets = useQuery({ queryKey: ['reports', 'presets'], queryFn: apiGetBody<TemplatePresets>('/report-templates/presets'), retry: false });
  const [name, setName] = useState('');
  const [grades, setGrades] = useState('');
  const [preset, setPreset] = useState('');
  const [error, setError] = useState<string | null>(null);
  const chosen = presets.data?.data.find((p) => p.key === preset);

  const create = async () => {
    setError(null);
    try {
      await reports.createTemplate({ name: name.trim(), grades: grades.split(/[\s,]+/).filter(Boolean), preset: preset || null });
      setName('');
      setGrades('');
      setPreset('');
      void queryClient.invalidateQueries({ queryKey: ['reports', 'templates'] });
    } catch (e) {
      setError(errorText(t, e));
    }
  };

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4">
      <Link to="/report-cards" className="text-sm font-medium text-primary hover:underline">
        {t('reports.back')}
      </Link>
      <h1 className="text-2xl font-semibold tracking-tight">{t('reports.templates.title')}</h1>
      <p className="text-sm text-muted">{t('reports.templates.intro')}</p>
      <Card className="flex flex-col gap-3">
        <h2 className="text-lg font-semibold">{t('reports.templates.new')}</h2>
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label={t('reports.templates.name')}>{(f) => <Input {...f} value={name} onChange={(e) => setName(e.target.value)} maxLength={80} />}</Field>
          <Field label={t('reports.templates.grades')} hint={t('reports.templates.gradesHint')}>
            {(f) => <Input {...f} value={grades} onChange={(e) => setGrades(e.target.value)} />}
          </Field>
          <Field label={t('reports.templates.preset')}>
            {(f) => (
              <Select {...f} value={preset} onChange={(e) => setPreset(e.target.value)}>
                <option value="">{t('reports.templates.blank')}</option>
                {(presets.data?.data ?? []).map((p) => (
                  <option key={p.key} value={p.key}>
                    {t(`reports.presets.${p.key}`, { defaultValue: p.label })}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        </div>
        {chosen ? <Alert tone="warning">{t('reports.templates.presetNote')}</Alert> : null}
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <div>
          <Button className="h-11" disabled={name.trim().length < 2} onClick={() => void create()}>
            <Plus aria-hidden="true" />
            {t('reports.templates.create')}
          </Button>
        </div>
      </Card>
      {templates.isPending ? <Spinner label={t('common.loading')} /> : null}
      {templates.error ? <Alert tone="danger">{errorText(t, templates.error)}</Alert> : null}
      {(templates.data ?? []).map((tpl) => (
        <TemplateEditor key={`${tpl.id}-${tpl.updated_at ?? ''}`} template={tpl} allBlocks={presets.data?.blocks ?? []} />
      ))}
    </div>
  );
}

function TemplateEditor({ template, allBlocks }: { template: Template; allBlocks: BlockType[] }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [blocks, setBlocks] = useState<BlockType[]>(template.blocks.map((b) => b.type));
  const [grades, setGrades] = useState(template.grades.join(', '));
  const [message, setMessage] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  const unused = allBlocks.filter((b) => !blocks.includes(b));
  const move = (i: number, by: number) => {
    const next = [...blocks];
    const [b] = next.splice(i, 1);
    next.splice(i + by, 0, b as BlockType);
    setBlocks(next);
  };

  const act = async (work: () => Promise<Template>, done: string) => {
    setBusy(true);
    setMessage(null);
    try {
      await work();
      void queryClient.invalidateQueries({ queryKey: ['reports', 'templates'] });
      setMessage({ tone: 'success', text: done });
    } catch (e) {
      setMessage({ tone: 'danger', text: errorText(t, e) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card className="flex flex-col gap-3">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-lg font-semibold">{template.name}</h2>
        <p className="text-xs text-muted">{template.grades.length ? t('reports.templates.forClasses', { grades: template.grades.join(', ') }) : t('reports.templates.forAll')}</p>
      </div>
      <Field label={t('reports.templates.grades')} hint={t('reports.templates.gradesHint')}>
        {(f) => <Input {...f} value={grades} onChange={(e) => setGrades(e.target.value)} className="max-w-xs" />}
      </Field>
      <section className="flex flex-col gap-2">
        <h3 className="text-sm font-semibold">{t('reports.templates.blocks')}</h3>
        <ol className="flex flex-col gap-1">
          {blocks.map((b, i) => (
            <li key={b} className="flex min-h-11 items-center gap-2 rounded-input border border-border ps-3 pe-1">
              <span className="w-5 shrink-0 text-xs text-muted tabular-nums">{i + 1}</span>
              <span className="min-w-0 flex-1 text-sm [overflow-wrap:anywhere]">{t(`reports.blocks.${b}`)}</span>
              <span className="flex shrink-0">

              <Button variant="ghost" size="icon" className="size-11" aria-label={t('reports.templates.up', { block: t(`reports.blocks.${b}`) })} disabled={i === 0} onClick={() => move(i, -1)}>
                <ArrowUp aria-hidden="true" />
              </Button>
              <Button variant="ghost" size="icon" className="size-11" aria-label={t('reports.templates.down', { block: t(`reports.blocks.${b}`) })} disabled={i === blocks.length - 1} onClick={() => move(i, 1)}>
                <ArrowDown aria-hidden="true" />
              </Button>
              <Button variant="ghost" size="icon" className="size-11" aria-label={t('reports.templates.remove', { block: t(`reports.blocks.${b}`) })} disabled={blocks.length === 1} onClick={() => setBlocks(blocks.filter((x) => x !== b))}>
                <Trash2 aria-hidden="true" />
              </Button>
              </span>
            </li>
          ))}
        </ol>
        {unused.length ? (
          <div className="flex flex-wrap gap-2">
            {unused.map((b) => (
              <Button key={b} variant="outline" className="h-11" onClick={() => setBlocks([...blocks, b])}>
                <Plus aria-hidden="true" />
                {t(`reports.blocks.${b}`)}
              </Button>
            ))}
          </div>
        ) : null}
      </section>
      <section className="flex flex-wrap items-center gap-2">
        <h3 className="w-full text-sm font-semibold">{t('reports.templates.letterhead')}</h3>
        <p className="w-full text-xs text-muted">{template.has_letterhead ? t('reports.templates.letterheadSet') : t('reports.templates.letterheadNone')}</p>
        <label className="inline-flex h-11 cursor-pointer items-center gap-2 rounded-input border border-border px-4 text-sm font-medium focus-within:outline-2 focus-within:outline-ring hover:bg-surface-muted">
          <ImageUp aria-hidden="true" className="size-4" />
          {t('reports.templates.upload')}
          <input
            type="file"
            accept="image/png,image/jpeg"
            className="sr-only"
            disabled={busy}
            onChange={(e) => {
              const file = e.target.files?.[0];
              e.target.value = '';
              if (file) void act(() => reports.uploadLetterhead(template.id, file), t('reports.templates.uploaded'));
            }}
          />
        </label>
        {template.has_letterhead ? (
          <Button variant="outline" className="h-11" disabled={busy} onClick={() => void act(() => reports.removeLetterhead(template.id), t('reports.templates.removed'))}>
            {t('reports.templates.removeLetterhead')}
          </Button>
        ) : null}
      </section>
      {message ? <Alert tone={message.tone}>{message.text}</Alert> : null}
      <div>
        <Button
          className="h-11"
          disabled={busy}
          onClick={() =>
            void act(
              () => reports.updateTemplate(template.id, { grades: grades.split(/[\s,]+/).filter(Boolean), blocks: blocks.map((type) => ({ type, options: {} })) }),
              t('reports.templates.saved'),
            )
          }
        >
          {t('reports.templates.save')}
        </Button>
      </div>
    </Card>
  );
}
