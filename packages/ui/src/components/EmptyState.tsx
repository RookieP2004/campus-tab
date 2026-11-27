import type { ReactNode } from 'react';
import { cx } from './cx';

export interface EmptyStateProps {
  title: string;
  body?: ReactNode;
  /** A decorative icon (hidden from screen readers). */
  icon?: ReactNode;
  action?: ReactNode;
  className?: string;
}

/** What a screen or card shows when there is nothing in it yet. */
export function EmptyState({ title, body, icon, action, className }: EmptyStateProps) {
  return (
    <div className={cx('flex flex-col items-center gap-2 rounded-card border border-dashed border-border bg-surface px-6 py-10 text-center', className)}>
      {icon ? (
        <div aria-hidden="true" className="flex size-11 items-center justify-center rounded-full bg-surface-muted text-muted">
          {icon}
        </div>
      ) : null}
      <p className="text-base font-semibold text-fg">{title}</p>
      {body ? <div className="max-w-md text-sm text-muted">{body}</div> : null}
      {action ? <div className="mt-2">{action}</div> : null}
    </div>
  );
}
