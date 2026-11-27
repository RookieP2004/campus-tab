import { CalendarDays } from 'lucide-react';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { BrandLockup } from '@/components/brand/BrandLockup';
import { useYears } from '@/features/academic/api';
import { useCurrentUser } from '@/features/auth/RequireSession';
import { holds } from '@/features/auth/permissions';
import { cn } from '@/lib/utils';
import { useViewPrefs } from '@/stores/view';
import { SchoolCard } from './SchoolCard';

/**
 * Top-left brand stack: our logo and name, then the school's logo and name
 * (from its public config), then the academic-year switcher.
 * "sidebar" is the desktop stack; "compact" is the phone and tablet header.
 */
export function BrandStack({ variant = 'sidebar' }: { variant?: 'sidebar' | 'compact' }) {
  const me = useCurrentUser();

  if (variant === 'compact') {
    return (
      <div className="flex min-w-0 flex-col gap-1.5" data-testid="brand-stack">
        <BrandLockup size="compact" />
        <div className="flex min-w-0 flex-wrap items-center gap-2">
          <SchoolCard code={me.school.code} compact />
          <YearSwitcher compact />
        </div>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-3" data-testid="brand-stack">
      <BrandLockup className="px-1.5" />
      <SchoolCard code={me.school.code} />
      <YearSwitcher />
    </div>
  );
}

/**
 * The academic year shown on screens. Only for people who may see the
 * structure (academic.view). Defaults to the current year; the choice is
 * kept per person in this browser.
 */
export function YearSwitcher({ compact = false }: { compact?: boolean }) {
  const { t } = useTranslation();
  const me = useCurrentUser();
  const id = useId();
  const allowed = holds(me, 'academic.view');
  const { data: years } = useYears(allowed);
  const chosen = useViewPrefs((s) => s.years[me.id]);
  const setYear = useViewPrefs((s) => s.setYear);

  if (!allowed) return null;

  const list = Array.isArray(years) ? years : [];
  const current = list.find((y) => y.status === 'current') ?? list[0];
  const value = list.some((y) => y.id === chosen) ? chosen : current?.id;

  return (
    <div className={cn('relative flex items-center', compact ? 'min-w-0' : 'w-full')}>
      <label htmlFor={id} className="sr-only">
        {t('school.year')}
      </label>
      <CalendarDays aria-hidden="true" className="pointer-events-none absolute start-2.5 size-3.5 text-muted" />
      <select
        id={id}
        value={value ?? ''}
        disabled={list.length === 0}
        onChange={(e) => setYear(me.id, e.target.value)}
        className={cn(
          'h-11 rounded-input border border-border bg-bg ps-8 pe-3 text-[13px] font-medium text-fg focus-visible:outline-2 focus-visible:outline-ring disabled:text-muted',
          compact ? 'max-w-40' : 'w-full',
        )}
      >
        {list.length === 0 ? <option value="">{t('school.yearPlaceholder')}</option> : null}
        {list.map((y) => (
          <option key={y.id} value={y.id}>
            {y.status === 'current' ? t('school.yearCurrent', { name: y.name }) : y.name}
          </option>
        ))}
      </select>
    </div>
  );
}
