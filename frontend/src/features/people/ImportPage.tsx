import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Download, FileSpreadsheet, Upload } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, Navigate, useParams, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Alert, Card, Field, Input, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { apiGet } from '@/lib/api';
import { downloadFile } from '@/lib/requests';
import { type ImportBatch, type ImportEntity, type ImportStatus, imports, peopleKeys } from './api';
import { PageHeader, StatusChip, TableFrame, td, th } from './ui';

const RUNNING: ImportStatus[] = ['queued', 'validating', 'committing'];

export function ImportPage() {
  const { entity = '' } = useParams();
  const { t } = useTranslation();
  const [params, setParams] = useSearchParams();

  if (entity !== 'students' && entity !== 'staff') return <Navigate to="/" replace />;

  const back = entity === 'students' ? '/students' : '/staff';

  return (
    <div className="flex flex-col gap-4">
      <Link to={back} className="inline-flex items-center gap-1.5 text-sm text-primary underline-offset-2 hover:underline">
        <ArrowLeft aria-hidden="true" className="size-4 rtl:rotate-180" />
        {entity === 'students' ? t('students.profile.back') : t('staff.back')}
      </Link>
      <PageHeader title={t(`imports.title.${entity}`)} intro={t('imports.intro')} />
      <ImportFlow
        entity={entity}
        batchId={params.get('batch')}
        onBatch={(id) => {
          const next = new URLSearchParams(params);
          if (id) next.set('batch', id);
          else next.delete('batch');
          setParams(next, { replace: true });
        }}
      />
    </div>
  );
}

/**
 * Template, upload, the dry-run check (polled), then the summary with the
 * seat check first, and the import. Also embedded in the setup wizard.
 */
export function ImportFlow({ entity, batchId, onBatch }: { entity: ImportEntity; batchId: string | null; onBatch: (id: string | null) => void }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [file, setFile] = useState<File | null>(null);
  const template = useMutation({ mutationFn: () => downloadFile(`/imports/${entity}/template`, `campus-tab-${entity}-template.xlsx`) });
  const upload = useMutation({
    mutationFn: (f: File) => imports.upload(entity, f),
    onSuccess: (batch) => {
      queryClient.setQueryData(peopleKeys.importBatch(batch.id), batch);
      onBatch(batch.id);
    },
  });

  return (
    <div className="flex flex-col gap-4">
      <Card className="flex flex-col gap-3">
        <h2 className="flex items-center gap-2 text-lg font-semibold">
          <span className="flex size-7 items-center justify-center rounded-full bg-primary-soft text-sm text-primary">1</span>
          {t('imports.step.template')}
        </h2>
        <p className="text-sm text-muted">{t(`imports.templateHint.${entity}`)}</p>
        {template.isError ? <Alert tone="danger">{errorText(t, template.error)}</Alert> : null}
        <div>
          <Button variant="outline" disabled={template.isPending} onClick={() => template.mutate()}>
            <Download aria-hidden="true" />
            {t('imports.downloadTemplate')}
          </Button>
        </div>
      </Card>

      <Card className="flex flex-col gap-3">
        <h2 className="flex items-center gap-2 text-lg font-semibold">
          <span className="flex size-7 items-center justify-center rounded-full bg-primary-soft text-sm text-primary">2</span>
          {t('imports.step.upload')}
        </h2>
        <form
          className="flex flex-col gap-3 sm:flex-row sm:items-end"
          onSubmit={(e) => {
            e.preventDefault();
            if (file) upload.mutate(file);
          }}
        >
          <Field label={t('imports.file')} hint={t('imports.fileHint')} className="flex-1">
            {(p) => (
              <Input
                {...p}
                type="file"
                accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
                className="py-2"
                onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              />
            )}
          </Field>
          <Button type="submit" disabled={!file || upload.isPending}>
            <Upload aria-hidden="true" />
            {t('imports.upload')}
          </Button>
        </form>
        {upload.isError ? <Alert tone="danger">{errorText(t, upload.error)}</Alert> : null}
      </Card>

      {batchId ? <BatchView id={batchId} entity={entity} onRestart={() => onBatch(null)} /> : null}
    </div>
  );
}

function BatchView({ id, entity, onRestart }: { id: string; entity: ImportEntity; onRestart: () => void }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const key = peopleKeys.importBatch(id);
  const query = useQuery({
    queryKey: key,
    queryFn: apiGet<ImportBatch>(`/imports/${id}`),
    // Polled while the check or import runs; apiGet sends these as background requests.
    refetchInterval: (q) => (q.state.data && RUNNING.includes(q.state.data.status) ? 1000 : false),
  });
  const [filter, setFilter] = useState<'all' | 'problems'>('all');
  const report = useMutation({ mutationFn: () => downloadFile(`/imports/${id}/report`, `campus-tab-${entity}-import-report.xlsx`) });
  const commit = useMutation({
    mutationFn: () => imports.commit(id),
    onSuccess: (batch) => queryClient.setQueryData(key, batch),
  });

  if (query.isPending) return <Spinner label={t('common.loading')} />;
  if (query.isError) return <Alert tone="danger">{errorText(t, query.error)}</Alert>;

  const batch = query.data;
  const summary = batch.summary;
  const running = RUNNING.includes(batch.status);
  const done = batch.progress?.done ?? 0;
  const total = batch.progress?.total ?? 0;
  const percent = total > 0 ? Math.round((done / total) * 100) : 0;
  const rows = (batch.rows ?? []).filter((r) => filter === 'all' || r.status !== 'ok');
  const seats = summary?.seats ?? null;
  const seatMessage = seats && seats.over > 0 ? summary?.messages[0] : null;
  const otherMessages = (summary?.messages ?? []).slice(seatMessage ? 1 : 0);

  return (
    <Card className="flex flex-col gap-4">
      <h2 className="flex items-center gap-2 text-lg font-semibold">
        <span className="flex size-7 items-center justify-center rounded-full bg-primary-soft text-sm text-primary">3</span>
        {t('imports.step.check')}
      </h2>
      <p className="flex items-center gap-2 text-sm text-muted">
        <FileSpreadsheet aria-hidden="true" className="size-4" />
        {batch.file_name}
        <StatusChip status={batch.status === 'validated' ? 'ok' : batch.status} label={t(`imports.status.${batch.status}`)} />
      </p>

      {running ? (
        <div className="flex flex-col gap-2">
          <p className="text-sm">{batch.status === 'committing' ? t('imports.committing') : t('imports.checking')}</p>
          <div
            role="progressbar"
            aria-label={t('imports.progress')}
            aria-valuemin={0}
            aria-valuemax={total || 100}
            aria-valuenow={done}
            className="h-2.5 overflow-hidden rounded-full bg-surface-muted"
          >
            <div className="h-full rounded-full bg-primary-solid transition-[width]" style={{ width: `${total > 0 ? percent : 5}%` }} />
          </div>
          <p className="text-xs text-muted">{total > 0 ? t('imports.progressCount', { done, total }) : t('imports.queued')}</p>
        </div>
      ) : null}

      {batch.status === 'failed' ? <Alert tone="danger">{batch.error ?? t('imports.failed')}</Alert> : null}

      {batch.status === 'committed' ? (
        <Alert tone="success">
          <p className="font-medium">{t('imports.created', { count: batch.result?.created ?? 0 })}</p>
          <Link to={entity === 'students' ? '/students' : '/staff'} className="text-primary underline-offset-2 hover:underline">
            {t(`imports.backToList.${entity}`)}
          </Link>
        </Alert>
      ) : null}

      {summary && !running ? (
        <>
          {seats ? (
            <div
              data-testid="seat-check"
              role={seats.over > 0 ? 'alert' : 'status'}
              className={seats.over > 0 ? 'rounded-card border-2 border-danger bg-surface p-4' : 'rounded-card border border-border bg-surface-muted p-4'}
            >
              <p className={seats.over > 0 ? 'font-semibold text-danger' : 'font-semibold'}>{t('imports.seatCheck')}</p>
              {seatMessage ? <p className="mt-1">{seatMessage}</p> : null}
              <p className="mt-1 text-sm text-muted">
                {t('imports.seatNumbers', { limit: seats.limit, used: seats.used, adding: seats.adding, available: seats.available })}
              </p>
            </div>
          ) : null}

          <dl className="grid grid-cols-3 gap-3 text-center">
            <Count label={t('imports.counts.ok')} value={summary.ok} tone="text-success" />
            <Count label={t('imports.counts.errors')} value={summary.errors} tone="text-danger" />
            <Count label={t('imports.counts.duplicates')} value={summary.duplicates} tone="text-warning" />
          </dl>

          {otherMessages.length > 0 ? (
            <ul className="flex list-disc flex-col gap-1 ps-5 text-sm">
              {otherMessages.map((m) => (
                <li key={m}>{m}</li>
              ))}
            </ul>
          ) : null}

          {(batch.rows ?? []).length > 0 ? (
            <div className="flex flex-col gap-2">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div role="group" aria-label={t('imports.filterLabel')} className="flex gap-2">
                  <Button size="sm" variant={filter === 'all' ? 'primary' : 'outline'} aria-pressed={filter === 'all'} onClick={() => setFilter('all')}>
                    {t('imports.filter.all')}
                  </Button>
                  <Button size="sm" variant={filter === 'problems' ? 'primary' : 'outline'} aria-pressed={filter === 'problems'} onClick={() => setFilter('problems')}>
                    {t('imports.filter.problems')}
                  </Button>
                </div>
                <Button size="sm" variant="outline" disabled={report.isPending} onClick={() => report.mutate()}>
                  <Download aria-hidden="true" />
                  {t('imports.downloadReport')}
                </Button>
              </div>
              {report.isError ? <Alert tone="danger">{errorText(t, report.error)}</Alert> : null}
              <TableFrame>
                <thead>
                  <tr>
                    <th scope="col" className={th}>
                      {t('imports.table.row')}
                    </th>
                    <th scope="col" className={th}>
                      {t('imports.table.result')}
                    </th>
                    <th scope="col" className={th}>
                      {t('imports.table.record')}
                    </th>
                    <th scope="col" className={th}>
                      {t('imports.table.messages')}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.row}>
                      <td className={td}>{r.row}</td>
                      <td className={td}>
                        <StatusChip status={r.status} label={t(`imports.rowStatus.${r.status}`)} />
                      </td>
                      <td className={td}>{r.label}</td>
                      <td className={`${td} max-w-md whitespace-normal`}>{r.messages.join(' ') || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </TableFrame>
            </div>
          ) : null}

          {commit.isError ? <Alert tone="danger">{errorText(t, commit.error)}</Alert> : null}

          {batch.status === 'validated' ? (
            <div className="flex flex-wrap justify-end gap-2">
              <Button variant="outline" onClick={onRestart}>
                {t('imports.uploadAgain')}
              </Button>
              <Button disabled={!summary.can_commit || commit.isPending} onClick={() => commit.mutate()}>
                {t('imports.commit', { count: summary.ok })}
              </Button>
            </div>
          ) : null}
        </>
      ) : null}
    </Card>
  );
}

function Count({ label, value, tone }: { label: string; value: number; tone: string }) {
  return (
    <div className="rounded-card border border-border p-3">
      <dt className="text-xs text-muted">{label}</dt>
      <dd className={`text-2xl font-semibold ${tone}`}>{value}</dd>
    </div>
  );
}
