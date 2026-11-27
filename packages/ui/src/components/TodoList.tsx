import type { ReactNode } from 'react';
import type { Status } from '../index';
import { cx } from './cx';
import { StatusChip } from './StatusChip';

export interface TodoItem {
  id: string;
  /** A short kind word shown as a tag, like "Leave" or "Staff". */
  tag?: string;
  title: ReactNode;
  detail?: ReactNode;
  status?: { status: Status; label: string };
  /** A link or button, like "Review". */
  action?: ReactNode;
}

export interface TodoListProps {
  items: TodoItem[];
  /** Shown when there is nothing to do. */
  emptyLabel: string;
  /** Accessible name of the list. */
  label?: string;
  className?: string;
}

/** The "waiting for you" list: one row per thing to decide. */
export function TodoList({ items, emptyLabel, label, className }: TodoListProps) {
  if (items.length === 0) return <p className={cx('py-2 text-sm text-muted', className)}>{emptyLabel}</p>;

  return (
    <ul aria-label={label} className={cx('flex flex-col divide-y divide-border', className)}>
      {items.map((item) => (
        <li key={item.id} className="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0 sm:flex-nowrap">
          {item.tag ? (
            <span className="inline-flex h-6 shrink-0 items-center rounded-input bg-surface-muted px-2 text-xs font-semibold text-muted">{item.tag}</span>
          ) : null}
          <div className="min-w-0 flex-1">
            <div className="text-sm font-medium text-fg">{item.title}</div>
            {item.detail ? <div className="text-xs text-muted">{item.detail}</div> : null}
          </div>
          {item.status ? <StatusChip status={item.status.status} label={item.status.label} /> : null}
          {item.action ? <div className="shrink-0">{item.action}</div> : null}
        </li>
      ))}
    </ul>
  );
}
