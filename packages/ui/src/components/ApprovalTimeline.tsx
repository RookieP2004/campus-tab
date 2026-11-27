import type { Status } from '../index';
import { cx } from './cx';
import { StatusChip } from './StatusChip';

export interface ApprovalStep {
  id: string;
  /** What the step is, like "Verified by the clerk". */
  label: string;
  status: Status;
  statusLabel: string;
  /** Who acted, already formatted. */
  who?: string | null;
  /** When, already formatted for the reader's language. */
  when?: string | null;
  /** Machine-readable time for <time dateTime>. */
  whenIso?: string | null;
}

export interface ApprovalTimelineProps {
  steps: ApprovalStep[];
  /** Accessible name, like "Approval steps". */
  label: string;
  className?: string;
}

/** The steps of a request in order: each with its state, who and when. */
export function ApprovalTimeline({ steps, label, className }: ApprovalTimelineProps) {
  return (
    <ol aria-label={label} className={cx('flex flex-col', className)}>
      {steps.map((step, index) => (
        <li key={step.id} className="relative flex gap-3 pb-4 last:pb-0">
          {index < steps.length - 1 ? <span aria-hidden="true" className="absolute start-[7px] top-5 bottom-0 w-px bg-border" /> : null}
          <span aria-hidden="true" className="mt-1 size-[15px] shrink-0 rounded-full border-2 border-primary bg-surface" />
          <div className="flex min-w-0 flex-1 flex-col gap-1">
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-sm font-medium text-fg">{step.label}</span>
              <StatusChip status={step.status} label={step.statusLabel} />
            </div>
            {step.who || step.when ? (
              <p className="text-xs text-muted">
                {step.who}
                {step.who && step.when ? ' · ' : null}
                {step.when ? <time dateTime={step.whenIso ?? undefined}>{step.when}</time> : null}
              </p>
            ) : null}
          </div>
        </li>
      ))}
    </ol>
  );
}
