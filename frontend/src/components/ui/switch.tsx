import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** An on/off switch with its label (a checkbox with role="switch"). */
export function Switch({ label, hint, checked, onChange, disabled = false }: { label: string; hint?: ReactNode; disabled?: boolean; checked: boolean; onChange: (checked: boolean) => void }) {
  return (
    <label className="flex min-h-11 cursor-pointer items-center justify-between has-disabled:cursor-not-allowed has-disabled:opacity-60 gap-4 rounded-input px-1 py-1.5">
      <span className="flex flex-col">
        <span className="text-sm text-fg">{label}</span>
        {hint ? <span className="text-xs text-muted">{hint}</span> : null}
      </span>
      <span className="relative inline-flex shrink-0">
        <input type="checkbox" role="switch" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} className="peer sr-only" />
        <span
          aria-hidden="true"
          className={cn(
            'flex h-7 w-12 items-center rounded-full border border-border p-0.5 transition-colors duration-(--ct-motion) peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-ring',
            checked ? 'bg-primary-solid' : 'bg-surface-muted',
          )}
        >
          <span className={cn('size-5 rounded-full bg-surface shadow transition-transform duration-(--ct-motion)', checked && 'translate-x-5 rtl:-translate-x-5')} />
        </span>
      </span>
    </label>
  );
}
