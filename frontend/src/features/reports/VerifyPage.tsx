import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { useParams } from 'react-router';
import { Alert, Spinner } from '@/components/ui/form';
import { AuthLayout } from '@/features/auth/AuthLayout';
import { errorText } from '@/features/auth/errors';
import { ApiError, apiGet } from '@/lib/api';
import { formatDate } from '@/lib/format';
import type { Verification } from './api';

/**
 * The public page a report card's QR code opens. No sign-in. It shows only
 * the school, the student's name, the class, the year, the version, the
 * issue date and whether that version is current or replaced: no marks,
 * no photo, nothing else.
 */
export function VerifyPage() {
  const { t, i18n } = useTranslation();
  const { school = '', code = '' } = useParams();
  const result = useQuery({
    queryKey: ['verify', school, code],
    queryFn: apiGet<Verification>(`/public/verify/${encodeURIComponent(school)}/${encodeURIComponent(code)}`),
    retry: false,
  });

  return (
    <AuthLayout title={t('reports.verify.title')} subtitle={t('reports.verify.subtitle')}>
      {result.isPending ? <Spinner label={t('common.loading')} /> : null}
      {result.error ? (
        <Alert tone="danger">{result.error instanceof ApiError && result.error.status === 404 ? t('reports.verify.notFound') : errorText(t, result.error)}</Alert>
      ) : null}
      {result.data ? (
        <div className="flex flex-col gap-4">
          {result.data.status === 'current' ? (
            <Alert tone="success">{t('reports.verify.current')}</Alert>
          ) : (
            <Alert tone="warning">{t('reports.verify.replaced')}</Alert>
          )}
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt className="text-muted">{t('reports.verify.school')}</dt>
            <dd className="font-medium">{result.data.school}</dd>
            <dt className="text-muted">{t('reports.verify.student')}</dt>
            <dd className="font-medium">{result.data.student}</dd>
            <dt className="text-muted">{t('reports.verify.class')}</dt>
            <dd className="font-medium">{result.data.class}</dd>
            <dt className="text-muted">{t('reports.verify.year')}</dt>
            <dd className="font-medium">{result.data.year}</dd>
            <dt className="text-muted">{t('reports.verify.version')}</dt>
            <dd className="font-medium">{result.data.version}</dd>
            <dt className="text-muted">{t('reports.verify.issued')}</dt>
            <dd className="font-medium">{result.data.issued_on ? formatDate(`${result.data.issued_on}T12:00:00+05:30`, i18n.language) : '–'}</dd>
          </dl>
          <p className="text-xs text-muted">{t('reports.verify.note')}</p>
        </div>
      ) : null}
    </AuthLayout>
  );
}
