import { brand, logoSizes } from '@campus-tab/ui';
import { useTranslation } from 'react-i18next';
import { cn } from '@/lib/utils';

interface BrandLockupProps {
  /** "sidebar" = 40 px mark, "compact" = 28 px for the phone header. */
  size?: 'sidebar' | 'compact';
  className?: string;
}

/**
 * The Campus TAB mark and wordmark: "Campus" in Inter Medium, "TAB" in Inter
 * ExtraBold in the primary blue; gap = 30% of the mark's height. The blue
 * tile is kept in both themes. The product name is never translated.
 */
export function BrandLockup({ size = 'sidebar', className }: BrandLockupProps) {
  const { t } = useTranslation();
  const mark = size === 'sidebar' ? logoSizes.sidebar : logoSizes.phoneHeader;

  return (
    <div className={cn('flex items-center', className)} style={{ gap: Math.round(mark * 0.3) }}>
      <img src="/logo-mark.svg" width={mark} height={mark} alt={t('brand.logoAlt')} className="shrink-0" />
      <span
        className={cn('leading-none tracking-tight text-fg', size === 'sidebar' ? 'text-xl' : 'text-base')}
        lang="en"
        dir="ltr"
      >
        <span className="font-medium">Campus </span>
        <span className="font-extrabold text-primary">TAB</span>
        <span className="sr-only"> — {brand.name}</span>
      </span>
    </div>
  );
}
