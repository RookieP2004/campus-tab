import type { AttendanceCode } from './api';

/**
 * Colours for the attendance codes, from the status tokens so the meaning
 * matches everywhere: present green, absent red, late amber, half-day blue,
 * leave violet, holiday slate. The code's word is always written next to it.
 */
export const codeTone: Record<AttendanceCode, string> = {
  P: 'bg-status-approved-bg text-status-approved-fg',
  A: 'bg-status-rejected-bg text-status-rejected-fg',
  L: 'bg-status-pending-bg text-status-pending-fg',
  HD: 'bg-status-verified-bg text-status-verified-fg',
  LV: 'bg-status-returned-bg text-status-returned-fg',
  H: 'bg-status-locked-bg text-status-locked-fg',
};

export const ALL_CODES: AttendanceCode[] = ['P', 'A', 'L', 'HD', 'LV', 'H'];

/** Today's month as YYYY-MM from a YYYY-MM-DD date. */
export const monthOf = (date: string) => date.slice(0, 7);
