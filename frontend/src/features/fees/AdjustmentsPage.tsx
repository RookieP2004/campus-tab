import { EmptyState, StatusChip } from '@campus-tab/ui';
import { useQuery } from '@tanstack/react-query';
import { ClipboardCheck } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { Alert, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { apiGet } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { type Adjustment, rupees } from './api';

/** Rebates, waivers and other adjustments: waiting, approved, turned down. */
export function AdjustmentsPage() {
  const { t, i18n } = useTranslation();
  const [params, setParams] = useSearchParams();
  const status = (params.get('status') ?? 'proposed') as Adjustment['status'];
  const list = useQuery({ queryKey: ['fees', 'adjustments', status], queryFn: apiGet<Adjustment[]>('/fees/adjustments', { status }), retry: false });

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4">
      <Link to="/fees" className="text-sm font-medium text-primary hover:underline">
        {t('fees.back')}
      </Link>
      <h1 className="text-2xl font-semibold tracking-tight">{t('fees.adjustments.title')}</h1>
      <p className="text-sm text-muted">{t('fees.adjustments.intro')}</p>
      <div role="tablist" aria-label={t('fees.adjustments.title')} className="flex gap-1 border-b border-border">
        {(['proposed', 'approved', 'rejected'] as const).map((k) => (
          <button
            key={k}
            type="button"
            role="tab"
            aria-selected={status === k}
            onClick={() => setParams({ status: k }, { replace: true })}
            className={`-mb-px h-11 border-b-2 px-4 text-sm font-medium focus-visible:outline-2 focus-visible:outline-ring ${status === k ? 'border-primary text-primary' : 'border-transparent text-muted'}`}
          >
            {t(`fees.adjustments.status.${k}`)}
          </button>
        ))}
      </div>
      {list.isPending ? <Spinner label={t('common.loading')} /> : null}
      {list.error ? <Alert tone="danger">{errorText(t, list.error)}</Alert> : null}
      {list.data && list.data.length === 0 ? <EmptyState icon={<ClipboardCheck className="size-5" />} title={t('fees.adjustments.none')} /> : null}
      <ul className="flex flex-col gap-2">
        {(list.data ?? []).map((a) => (
          <li key={a.id}>
            <Link
              to={`/approvals?item=fees:adjustment:${a.id}`}
              className="flex min-h-16 flex-wrap items-center gap-3 rounded-card border border-border bg-surface p-4 hover:border-primary focus-visible:outline-2 focus-visible:outline-ring"
            >
              <span className="min-w-40 flex-1">
                <span className="block font-semibold">{a.student.name}</span>
                <span className="block text-xs text-muted">
                  {t(`fees.kinds.${a.kind}`)} · {t('fees.adjustments.proposedBy', { name: a.proposed_by?.name ?? '' })} · {formatDateTime(a.at, i18n.language)}
                </span>
              </span>
              <span className="font-semibold tabular-nums">{rupees(a.amount_paise, i18n.language)}</span>
              <StatusChip status={a.status === 'proposed' ? 'pending' : a.status} label={t(`fees.adjustments.status.${a.status}`)} />
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}
