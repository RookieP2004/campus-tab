import type { Status } from '../index';
import { cx } from './cx';

const tones: Record<Status, string> = {
  draft: 'bg-status-draft-bg text-status-draft-fg',
  pending: 'bg-status-pending-bg text-status-pending-fg',
  verified: 'bg-status-verified-bg text-status-verified-fg',
  approved: 'bg-status-approved-bg text-status-approved-fg',
  returned: 'bg-status-returned-bg text-status-returned-fg',
  rejected: 'bg-status-rejected-bg text-status-rejected-fg',
  locked: 'bg-status-locked-bg text-status-locked-fg',
  published: 'bg-status-published-bg text-status-published-fg',
};

/** A small shape per state, so the chip never relies on colour alone. */
const marks: Record<Status, string> = {
  draft: 'rounded-full border border-current bg-transparent',
  pending: 'rounded-full bg-current opacity-60',
  verified: 'rounded-full bg-current',
  approved: 'rounded-full bg-current',
  returned: 'rotate-45 bg-current',
  rejected: 'bg-current',
  locked: 'rounded-[1px] bg-current',
  published: 'rounded-full bg-current',
};

export interface StatusChipProps {
  status: Status;
  /** The visible, translated word for the state. Always shown. */
  label: string;
  className?: string;
}

/**
 * One of the eight status chips. Colours come from the theme tokens and
 * never change meaning; the label is always written out.
 */
export function StatusChip({ status, label, className }: StatusChipProps) {
  return (
    <span
      data-status={status}
      className={cx('inline-flex h-6 shrink-0 items-center gap-1.5 rounded-full px-2.5 text-xs font-semibold whitespace-nowrap', tones[status], className)}
    >
      <span aria-hidden="true" className={cx('size-1.5 shrink-0', marks[status])} />
      {label}
    </span>
  );
}
