import type { ComponentProps, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Input, Select, Textarea } from '@/components/ui/form';
import { cn } from '@/lib/utils';
import type { CustomColumn } from './api';

const chipTones: Record<string, string> = {
  active: 'bg-status-approved-bg text-status-approved-fg',
  ok: 'bg-status-approved-bg text-status-approved-fg',
  committed: 'bg-status-approved-bg text-status-approved-fg',
  draft: 'bg-status-pending-bg text-status-pending-fg',
  pending: 'bg-status-pending-bg text-status-pending-fg',
  duplicate: 'bg-status-returned-bg text-status-returned-fg',
  error: 'bg-status-rejected-bg text-status-rejected-fg',
  rejected: 'bg-status-rejected-bg text-status-rejected-fg',
  failed: 'bg-status-rejected-bg text-status-rejected-fg',
  inactive: 'bg-status-draft-bg text-status-draft-fg',
  archived: 'bg-status-draft-bg text-status-draft-fg',
  locked: 'bg-status-locked-bg text-status-locked-fg',
};

/** A small status pill, coloured with the theme's status tokens. */
export function StatusChip({ status, label }: { status: string; label?: string }) {
  const { t } = useTranslation();

  return (
    <span className={cn('inline-flex rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap', chipTones[status] ?? chipTones.inactive)}>
      {label ?? t(`people.status.${status}`, { defaultValue: status })}
    </span>
  );
}

export function PageHeader({ title, intro, actions }: { title: ReactNode; intro?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
        {intro ? <p className="mt-1 max-w-2xl text-sm text-muted">{intro}</p> : null}
      </div>
      {actions ? <div className="flex flex-wrap gap-2">{actions}</div> : null}
    </div>
  );
}

/** A table that scrolls sideways inside its card on a phone. */
export function TableFrame({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div className={cn('overflow-x-auto rounded-card border border-border bg-surface', className)}>
      <table className="w-full min-w-max border-collapse text-sm">{children}</table>
    </div>
  );
}

export const th = 'border-b border-border bg-surface-muted px-3 py-2 text-start text-xs font-semibold text-muted';
export const td = 'border-b border-border px-3 py-2 align-top';

export function Checkbox({ label, className, ...props }: Omit<ComponentProps<'input'>, 'type'> & { label: ReactNode }) {
  return (
    <label className={cn('flex items-start gap-2 text-sm text-fg', className)}>
      <input type="checkbox" className="mt-0.5 size-4 shrink-0 accent-primary" {...props} />
      <span>{label}</span>
    </label>
  );
}

/** One input for a custom column, by its type. */
export function CustomValueInput({
  column,
  value,
  onChange,
  id,
  disabled,
  ...aria
}: {
  column: CustomColumn;
  value: unknown;
  onChange: (value: unknown) => void;
  id: string;
  disabled?: boolean;
  'aria-invalid'?: boolean;
  'aria-describedby'?: string | undefined;
}) {
  const { t } = useTranslation();
  const text = value === null || value === undefined ? '' : String(value);
  const common = { id, disabled, ...aria };

  switch (column.type) {
    case 'long_text':
      return <Textarea {...common} value={text} onChange={(e) => onChange(e.target.value)} />;
    case 'number':
      return <Input {...common} type="number" inputMode="decimal" value={text} onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))} />;
    case 'date':
      return <Input {...common} type="date" value={text} onChange={(e) => onChange(e.target.value || null)} />;
    case 'phone':
      return <Input {...common} type="tel" value={text} onChange={(e) => onChange(e.target.value)} />;
    case 'email':
      return <Input {...common} type="email" value={text} onChange={(e) => onChange(e.target.value)} />;
    case 'dropdown':
      return (
        <Select {...common} value={text} onChange={(e) => onChange(e.target.value || null)}>
          <option value="">{t('common.none')}</option>
          {(column.options ?? []).map((o) => (
            <option key={o} value={o}>
              {o}
            </option>
          ))}
        </Select>
      );
    case 'yes_no':
      return (
        <Select {...common} value={value === true ? 'yes' : value === false ? 'no' : ''} onChange={(e) => onChange(e.target.value === '' ? null : e.target.value === 'yes')}>
          <option value="">{t('common.none')}</option>
          <option value="yes">{t('common.yes')}</option>
          <option value="no">{t('common.no')}</option>
        </Select>
      );
    case 'multi_select': {
      const chosen = Array.isArray(value) ? (value as string[]) : [];
      return (
        <fieldset id={id} className="flex flex-wrap gap-x-4 gap-y-2 rounded-input border border-border p-3" disabled={disabled}>
          {(column.options ?? []).map((o) => (
            <Checkbox
              key={o}
              label={o}
              checked={chosen.includes(o)}
              onChange={(e) => onChange(e.target.checked ? [...chosen, o] : chosen.filter((c) => c !== o))}
            />
          ))}
        </fieldset>
      );
    }
    default:
      return <Input {...common} value={text} onChange={(e) => onChange(e.target.value)} />;
  }
}
