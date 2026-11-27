import { School } from 'lucide-react';
import { useTranslation } from 'react-i18next';

/**
 * Second row of the brand stack: the school's logo and name. Phase 1 fills
 * it from GET /public/tenants/{code}/config; until then it shows a neutral
 * placeholder so the layout is already final.
 */
export function SchoolCard() {
  const { t } = useTranslation();

  return (
    <div className="flex items-center gap-2.5 rounded-[10px] border border-border p-2.5" data-testid="school-card">
      <div className="flex size-[34px] shrink-0 items-center justify-center rounded-full bg-surface-muted text-muted">
        <School aria-hidden="true" className="size-4" />
      </div>
      <div className="flex min-w-0 flex-col">
        <span className="truncate text-[13px] leading-snug font-semibold text-fg">{t('school.placeholderName')}</span>
        <span className="text-xs text-muted">{t('school.placeholderDetail')}</span>
      </div>
    </div>
  );
}
