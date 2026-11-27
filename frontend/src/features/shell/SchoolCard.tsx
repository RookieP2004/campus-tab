import { School } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { type PublicConfig, usePublicConfig } from '@/features/auth/api';
import { useSchoolCode } from '@/stores/school';

/**
 * Second row of the brand stack: the school's logo and name, from the
 * public config (GET /public/tenants/{code}/config). Until a school is
 * chosen it shows a neutral placeholder, so the layout never jumps.
 */
export function SchoolCard({ code, compact = false }: { code?: string | null; compact?: boolean }) {
  const remembered = useSchoolCode((s) => s.code);
  const { data } = usePublicConfig(code ?? remembered);

  return <SchoolBadge config={data ?? null} compact={compact} />;
}

export function SchoolBadge({ config, compact = false }: { config: PublicConfig | null; compact?: boolean }) {
  const { t } = useTranslation();

  return (
    <div
      className="flex min-w-0 max-w-full items-center gap-2.5 rounded-[10px] border border-border p-2.5"
      style={config ? { borderInlineStartColor: config.accent, borderInlineStartWidth: 3 } : undefined}
      data-testid="school-card"
    >
      {config?.logo_url ? (
        <img src={config.logo_url} alt="" className="size-[34px] shrink-0 rounded-full object-cover" />
      ) : (
        <div className="flex size-[34px] shrink-0 items-center justify-center rounded-full bg-surface-muted text-muted">
          <School aria-hidden="true" className="size-4" />
        </div>
      )}
      <div className="flex min-w-0 flex-col">
        <span className="truncate text-[13px] leading-snug font-semibold text-fg">{config?.name ?? t('school.placeholderName')}</span>
        {compact ? null : <span className="text-xs text-muted">{config ? config.code : t('school.placeholderDetail')}</span>}
      </div>
    </div>
  );
}
