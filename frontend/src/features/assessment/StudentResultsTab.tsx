import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Alert, Spinner } from '@/components/ui/form';
import { errorText } from '@/features/auth/errors';
import { apiGet } from '@/lib/api';
import { formatDate } from '@/lib/format';
import type { StudentResults } from './api';

/** A student's published marks on their profile (also a parent's view of a child). Unpublished papers never come back from the server. */
export function StudentResultsTab({ studentId }: { studentId: string }) {
  const { t, i18n } = useTranslation();
  const results = useQuery({ queryKey: ['marks', 'results', studentId], queryFn: apiGet<StudentResults>(`/results/students/${studentId}`), retry: false });

  if (results.isPending) return <Spinner label={t('common.loading')} />;
  if (results.error) return <Alert tone="danger">{errorText(t, results.error)}</Alert>;
  if (results.data.results.length === 0) return <p className="text-sm text-muted">{t('tests.results.none')}</p>;

  return (
    <div className="relative overflow-x-auto rounded-card border border-border">
      <table className="w-full text-sm">
        <caption className="sr-only">{t('tests.results.title')}</caption>
        <thead className="bg-surface-muted text-xs text-muted">
          <tr>
            <th scope="col" className="px-3 py-2 text-start font-medium">{t('tests.results.assessment')}</th>
            <th scope="col" className="px-3 py-2 text-start font-medium">{t('tests.results.subject')}</th>
            <th scope="col" className="px-3 py-2 text-end font-medium">{t('tests.results.marks')}</th>
          </tr>
        </thead>
        <tbody>
          {results.data.results.map((r) => (
            <tr key={r.paper_id} className="border-t border-border">
              <td className="px-3 py-2">
                {r.assessment}
                {r.date ? <span className="block text-xs text-muted">{formatDate(`${r.date}T12:00:00+05:30`, i18n.language)}</span> : null}
              </td>
              <td className="px-3 py-2">{r.subject}</td>
              <td className="px-3 py-2 text-end tabular-nums">{r.code ? t(`tests.codeNames.${r.code}`) : `${r.value ?? '–'} / ${r.max_marks}`}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
