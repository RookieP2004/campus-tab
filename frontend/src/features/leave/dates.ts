import { useTranslation } from 'react-i18next';
import { formatDate } from '@/lib/format';
import { asDate } from './api';

/** "3 Dec 2026" or "3 Dec 2026 – 4 Dec 2026", plus the half day. */
export function useLeaveDates() {
  const { t, i18n } = useTranslation();
  return (from: string, to: string, halfDay?: string | null) => {
    const a = formatDate(asDate(from), i18n.language);
    const span = from === to ? a : t('leave.range', { from: a, to: formatDate(asDate(to), i18n.language) });
    return halfDay ? `${span} · ${t(`leave.halfDay.${halfDay}`)}` : span;
  };
}
