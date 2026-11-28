import { useQuery } from '@tanstack/react-query';
import { FileSpreadsheet, FileText } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Field, Input, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { holds } from '@/features/auth/permissions';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { apiGet } from '@/lib/api';
import { downloadFile } from '@/lib/requests';
import { type Report, type ReportType, reportTypes, rupees } from './api';
import { StepUpField } from './StepUpField';
import { isStepUpRequest, useStepUp } from './useStepUp';

/**
 * Fee reports for the office (a Fee Collector sees their own daily
 * collections only). The Excel and PDF exports ask for a fresh authenticator code
 * and is written to the audit log.
 */
export function ReportsPage() {
  const { t, i18n } = useTranslation();
  const me = useCurrentUser();
  const own = !holds(me, 'fees.reports', 'school');
  const [params, setParams] = useSearchParams();
  const type = (reportTypes.includes(params.get('type') as ReportType) ? params.get('type') : 'daily') as ReportType;
  const [from, setFrom] = useState(params.get('from') ?? '');
  const [to, setTo] = useState(params.get('to') ?? '');
  const query = { from: params.get('from') ?? undefined, to: params.get('to') ?? undefined };
  const report = useQuery({ queryKey: ['fees', 'report', type, query], queryFn: apiGet<Report>(`/fees/reports/${type}`, query), retry: false });
  const stepUp = useStepUp();
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const types = own ? (['daily'] as ReportType[]) : reportTypes;

  const exportFile = async (format: 'xlsx' | 'pdf') => {
    setBusy(true);
    setError(null);
    const qs = new URLSearchParams([...(Object.entries(query).filter(([, v]) => v) as [string, string][]), ...(format === 'pdf' ? [['format', 'pdf'] as [string, string]] : [])]).toString();
    try {
      await stepUp.run(() => downloadFile(`/fees/reports/${type}/export${qs ? `?${qs}` : ''}`, `fees-${type}.${format}`));
    } catch (e) {
      if (!isStepUpRequest(e)) setError(errorText(t, e));
    } finally {
      setBusy(false);
    }
  };

  const cell = (col: Report['columns'][number], value: string | number | null) => {
    if (col.money) return rupees(typeof value === 'number' ? value : null, i18n.language);
    if (col.key === 'mode' && typeof value === 'string') return t(`fees.modes.${value}`, { defaultValue: value });
    if (col.key === 'kind' && typeof value === 'string') return t(`fees.kinds.${value}`, { defaultValue: value });
    if (col.key === 'status' && typeof value === 'string') return t(`fees.paymentStatus.${value}`, { defaultValue: value });
    if (col.key === 'defaulter') return value === 'yes' ? t('fees.reports.yes') : t('fees.reports.no');

    return value ?? '—';
  };

  return (
    <div className="mx-auto flex max-w-6xl flex-col gap-4">
      <Link to="/fees" className="text-sm font-medium text-primary hover:underline">
        {t('fees.back')}
      </Link>
      <h1 className="text-2xl font-semibold tracking-tight">{t('fees.reports.title')}</h1>
      <div role="tablist" aria-label={t('fees.reports.title')} className="flex flex-wrap gap-1 border-b border-border">
        {types.map((k) => (
          <button
            key={k}
            type="button"
            role="tab"
            aria-selected={type === k}
            onClick={() => setParams({ type: k, ...(query.from ? { from: query.from } : {}), ...(query.to ? { to: query.to } : {}) }, { replace: true })}
            className={`-mb-px h-11 border-b-2 px-4 text-sm font-medium focus-visible:outline-2 focus-visible:outline-ring ${type === k ? 'border-primary text-primary' : 'border-transparent text-muted'}`}
          >
            {t(`fees.reports.types.${k}`)}
          </button>
        ))}
      </div>
      <Card className="flex flex-col gap-3">
        {type !== 'dues' ? (
          <form
            className="flex flex-wrap items-end gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              setParams({ type, ...(from ? { from } : {}), ...(to ? { to } : {}) }, { replace: true });
            }}
          >
            <Field label={t('fees.reports.from')}>{(f) => <Input {...f} type="date" value={from} onChange={(e) => setFrom(e.target.value)} />}</Field>
            <Field label={t('fees.reports.to')}>{(f) => <Input {...f} type="date" value={to} onChange={(e) => setTo(e.target.value)} />}</Field>
            <Button type="submit" variant="outline" className="h-11">
              {t('fees.reports.show')}
            </Button>
          </form>
        ) : null}
        <StepUpField state={stepUp} />
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" className="h-11" disabled={busy || !stepUp.ready} onClick={() => void exportFile('xlsx')}>
            <FileSpreadsheet aria-hidden="true" />
            {t('fees.reports.export')}
          </Button>
          <Button variant="outline" className="h-11" disabled={busy || !stepUp.ready} onClick={() => void exportFile('pdf')}>
            <FileText aria-hidden="true" />
            {t('fees.reports.exportPdf')}
          </Button>
        </div>
        <p className="text-xs text-muted">{t('fees.reports.exportNote')}</p>
      </Card>
      {report.isPending ? <Spinner label={t('common.loading')} /> : null}
      {report.error ? <Alert tone="danger">{errorText(t, report.error)}</Alert> : null}
      {report.data ? (
        <Card tabIndex={0} role="region" aria-label={t(`fees.reports.types.${type}`)} className="overflow-x-auto focus-visible:outline-2 focus-visible:outline-ring">
          {report.data.rows.length === 0 ? (
            <p className="text-sm text-muted">{t('fees.reports.empty')}</p>
          ) : (
            <table className="w-full min-w-[40rem] text-sm">
              <caption className="sr-only">{t(`fees.reports.types.${type}`)}</caption>
              <thead>
                <tr className="border-b border-border text-xs text-muted">
                  {report.data.columns.map((c) => (
                    <th key={c.key} scope="col" className={`py-2 pe-3 font-medium ${c.money ? 'text-end' : 'text-start'}`}>
                      {t(`fees.reports.columns.${c.key}`)}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {report.data.rows.map((row, i) => (
                  <tr key={i} className="border-b border-border last:border-0">
                    {report.data.columns.map((c) => (
                      <td key={c.key} className={`py-2 pe-3 ${c.money ? 'text-end tabular-nums' : ''}`}>
                        {cell(c, row[c.key] ?? null)}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      ) : null}
    </div>
  );
}
