import { EmptyState, StatusChip } from '@campus-tab/ui';
import { useQuery } from '@tanstack/react-query';
import { FileDown, FileText } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Alert, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { apiGet } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { openCardPdf, type StudentCards } from './api';

/**
 * A family's view of report cards: published versions only, newest first.
 * A replaced version stays listed (and downloadable) with "replaced", so a
 * card never changes silently.
 */
export function FamilyCards({ studentId }: { studentId: string | null }) {
  const { t, i18n } = useTranslation();
  const cards = useQuery({
    queryKey: ['reports', 'student', studentId ?? 'mine'],
    queryFn: apiGet<StudentCards>(studentId ? `/report-cards/students/${studentId}` : '/report-cards/mine'),
    retry: false,
  });
  const [error, setError] = useState<string | null>(null);

  if (cards.isPending) return <Spinner label={t('common.loading')} />;
  if (cards.error || !cards.data) return <Alert tone="danger">{errorText(t, cards.error)}</Alert>;
  if (cards.data.cards.length === 0) return <EmptyState icon={<FileText className="size-5" />} title={t('reports.family.none')} />;

  return (
    <div className="flex flex-col gap-2">
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <ul className="flex flex-col gap-2">
        {cards.data.cards.map((c) => (
          <li key={c.id} className="flex flex-wrap items-center gap-3 rounded-card border border-border bg-surface p-4">
            <div className="min-w-40 flex-1">
              <p className="text-sm font-semibold">
                {t('reports.family.cardFor', { year: c.year.name })} · {t('reports.versionN', { n: c.version })}
              </p>
              <p className="text-xs text-muted">
                {c.section.label}
                {c.issued_at ? ` · ${t('reports.issuedOn', { date: formatDate(c.issued_at, i18n.language) })}` : ''}
                {c.snapshot.overall ? ` · ${t(`reports.result.${c.snapshot.overall.result}`)}` : ''}
              </p>
            </div>
            <StatusChip status={c.current ? 'published' : 'returned'} label={c.current ? t('reports.verify.currentShort') : t('reports.status.replaced')} />
            {c.can.download ? (
              <Button variant="outline" className="h-11" onClick={() => void openCardPdf(c.id).catch((e: unknown) => setError(errorText(t, e)))}>
                <FileDown aria-hidden="true" />
                {t('reports.card.pdf')}
              </Button>
            ) : null}
          </li>
        ))}
      </ul>
    </div>
  );
}
