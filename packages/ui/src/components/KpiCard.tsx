import type { ReactNode } from 'react';
import { cx } from './cx';

export interface KpiCardProps {
  label: string;
  value: ReactNode;
  detail?: ReactNode;
  /** 0 to 100: draws a thin progress bar under the number. */
  progress?: number;
  /** A link or button, rendered by the app (the package has no router). */
  action?: ReactNode;
  className?: string;
}

/** A key number with its label, an optional line of detail and a bar. */
export function KpiCard({ label, value, detail, progress, action, className }: KpiCardProps) {
  const bar = typeof progress === 'number' ? Math.max(0, Math.min(100, progress)) : null;

  return (
    <div className={cx('flex flex-col gap-1.5 rounded-card border border-border bg-surface p-4', className)}>
      <span className="text-[13px] font-medium text-muted">{label}</span>
      <span className="text-2xl font-bold tracking-tight text-fg tabular-nums">{value}</span>
      {detail ? <span className="text-xs text-muted">{detail}</span> : null}
      {bar !== null ? (
        <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-surface-muted" aria-hidden="true">
          <div className="h-full rounded-full bg-primary-solid" style={{ width: `${bar}%` }} />
        </div>
      ) : null}
      {action ? <div className="mt-1">{action}</div> : null}
    </div>
  );
}
