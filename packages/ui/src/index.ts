/**
 * @campus-tab/ui — shared design tokens, brand constants and the shared
 * React components (Phase 3). CSS lives in tokens.css and theme.css. The
 * components take every string as a prop: the package has no i18n.
 */

export const brand = {
  name: 'Campus TAB',
  /** Primary blue used for "TAB" in the wordmark and the logo tile. */
  primary: '#1D4ED8',
  /** Outline colour on the white tile used on dark photos or prints. */
  ink: '#0F172A',
} as const;

/** The eight status chips. Their colours never change meaning. */
export const statuses = [
  'draft',
  'pending',
  'verified',
  'approved',
  'returned',
  'rejected',
  'locked',
  'published',
] as const;

export type Status = (typeof statuses)[number];

export type ThemeChoice = 'light' | 'dark' | 'system';
export type ResolvedTheme = 'light' | 'dark';

/** Sizes from "Logo and brand mark": sidebar, phone header, favicon. */
export const logoSizes = { sidebar: 40, phoneHeader: 28, favicon: 16 } as const;

export { StatusChip, type StatusChipProps } from './components/StatusChip';
export { KpiCard, type KpiCardProps } from './components/KpiCard';
export { TodoList, type TodoItem, type TodoListProps } from './components/TodoList';
export { EmptyState, type EmptyStateProps } from './components/EmptyState';
export { TypedConfirmDialog, type TypedConfirmDialogProps } from './components/TypedConfirmDialog';
export { ApprovalTimeline, type ApprovalStep, type ApprovalTimelineProps } from './components/ApprovalTimeline';
