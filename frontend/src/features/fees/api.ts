import { send } from '@/lib/requests';

/**
 * Fees. Money is whole paise from the server (fields end in _paise). The
 * screen shows rupees and sends typed amounts back as rupee strings
 * ("1250.50"), which the server parses exactly; nothing here does money
 * arithmetic in floating point.
 */

export type DemandStatus = 'paid' | 'part_paid' | 'overdue' | 'due';

export interface Demand {
  id: string;
  label: string;
  due_on: string;
  lines: { head_id: string; head: string; amount_paise: number }[];
  discounts: { label: string; head_id: string; amount_paise: number }[];
  total_paise: number;
  due_paise: number;
  fine_paise: number;
  fine_posted: boolean;
  paid_paise: number;
  adjusted_paise: number;
  balance_paise: number;
  status: DemandStatus;
}

export interface LedgerEntry {
  id: string;
  type: 'demand' | 'fine' | 'payment' | 'adjustment' | 'reversal';
  amount_paise: number;
  demand_id: string | null;
  head_id: string | null;
  receipt_no: string | null;
  adjustment_kind: string | null;
  reverses_id: string | null;
  reason: string | null;
  by: { id: string; name: string } | null;
  at: string | null;
}

export interface ReceiptSummary {
  id: string;
  number: string;
  student: { id: string; name: string };
  section: string | null;
  amount_paise: number;
  mode: string;
  lines: { label: string; head: string; amount_paise: number }[];
  status: 'valid' | 'cancelled' | 'bounced';
  issued_on: string;
  issued_by: { id: string; name: string } | null;
  payment_id: string;
}

export interface ReceiptDetail extends ReceiptSummary {
  reference: string | null;
  bank: string | null;
  payment_status: string | null;
  cancellation_pending?: boolean;
  can: { cancel: boolean; clear: boolean };
}

export interface Adjustment {
  id: string;
  student: { id: string; name: string };
  kind: AdjustmentKind;
  demand_id: string | null;
  amount_paise: number;
  percent_bp: number | null;
  reason: string;
  status: 'proposed' | 'approved' | 'rejected';
  proposed_by: { id: string; name: string } | null;
  decided_by: { id: string; name: string } | null;
  decision_reason: string | null;
  decided_at: string | null;
  at: string | null;
  own?: boolean;
  can?: { decide: boolean };
}

export type AdjustmentKind = 'rebate' | 'waiver' | 'fine_waiver' | 'refund' | 'scholarship' | 'carry_forward';
export const adjustmentKinds: AdjustmentKind[] = ['rebate', 'waiver', 'fine_waiver', 'refund', 'scholarship', 'carry_forward'];

export interface StudentFees {
  student: { id: string; name: string; admission_no?: string };
  year: { id: string; name: string } | null;
  balance_paise: number;
  demands: Demand[];
  upcoming: { label: string; due_on: string; total_paise: number }[];
  ledger: LedgerEntry[];
  receipts: ReceiptSummary[];
  payments_pending: number;
  adjustments: Adjustment[];
  can: { collect: boolean; adjust: boolean; pay_online: boolean; setup: boolean };
}

export interface PaymentResult {
  id: string;
  mode: string;
  amount_paise: number;
  status: string;
  receipt_id: string | null;
  receipt_no: string | null;
  received_on: string;
  reference: string | null;
  collector: { id: string; name: string } | null;
}

export interface Closing {
  id: string;
  collector: { id: string | null; name: string | null };
  date: string;
  totals: Record<string, number>;
  total_paise: number;
  cash_paise: number;
  counted_cash_paise: number | null;
  count: number;
  status: 'submitted' | 'confirmed';
  confirmed_by: { id: string; name: string } | null;
  confirmed_at: string | null;
  note: string | null;
}

export interface ClosingsView {
  open: { count: number; totals: Record<string, number>; total_paise: number; payments: PaymentResult[] };
  mine: Closing[];
  to_confirm: Closing[];
}

export interface Cancellation {
  id: string;
  receipt_id: string;
  receipt_no: string;
  student: { id: string; name: string };
  amount_paise: number;
  reason: string;
  status: 'requested' | 'proposed' | 'approved' | 'rejected';
  requested_by: { id: string; name: string } | null;
  proposed_by: { id: string; name: string } | null;
  decided_by: { id: string; name: string } | null;
  decision_reason: string | null;
  decided_at: string | null;
  at: string | null;
  own: boolean;
  can: { approve: boolean; forward: boolean; reject: boolean };
}

export interface Head {
  id: string;
  name: string;
  code: string;
  status: string;
}

export interface Plan {
  id: string;
  name: string;
  grades: string[];
  category_ids: string[];
  instalments: { key: string; label: string; due_on: string; lines: { head_id: string; amount_paise: number }[] }[];
  fine: { mode: 'none' | 'flat' | 'per_day'; amount_paise: number; grace_days: number; cap_paise: number | null };
  discounts: { key: string; label: string; condition: 'sibling' | 'always'; kind: 'percent' | 'flat'; value: number; head_ids: string[] }[];
  status: string;
  total_paise: number;
}

export interface GatewayView {
  configured: boolean;
  provider: 'razorpay' | 'fake' | null;
  key_id: string | null;
  status: 'active' | 'off' | null;
  has_secret?: boolean;
  has_webhook_secret?: boolean;
  updated_by: string | null;
  updated_at: string | null;
  drivers: string[];
}

export interface Checkout {
  id: string;
  provider: 'razorpay' | 'fake';
  order_id: string;
  amount_paise: number;
  currency: string;
  school: string;
  status: string;
  key_id: string;
  fake?: boolean;
  script?: string;
}

export type ReportType = 'daily' | 'dues' | 'heads' | 'adjustments' | 'cheques';
export const reportTypes: ReportType[] = ['daily', 'dues', 'heads', 'adjustments', 'cheques'];

export interface Report {
  type: ReportType;
  columns: { key: string; money?: boolean }[];
  rows: Record<string, string | number | null>[];
}

export interface Reconciliation {
  id: string;
  date: string;
  provider: string;
  matched: number;
  mismatches: { gateway_payment_id: string; order_id: string | null; reason: string; ours_paise: number | null; theirs_paise: number | null }[];
  status: 'ok' | 'mismatch' | 'failed';
  error: string | null;
}

const key = () => ({ 'Idempotency-Key': crypto.randomUUID() });

export const fees = {
  collect: (body: { student_id: string; mode: string; amount: string; reference?: string; bank?: string; demand_ids?: string[] }) =>
    send<PaymentResult>('POST', '/fees/payments', { body, headers: key() }),
  clear: (id: string) => send<PaymentResult>('POST', '/fees/payments/{id}/clear', { path: { id }, headers: key() }),
  bounce: (id: string, reason: string) => send<PaymentResult>('POST', '/fees/payments/{id}/bounce', { path: { id }, body: { reason }, headers: key() }),
  closeDay: (note?: string) => send<Closing>('POST', '/fees/closings', { body: { note: note || null }, headers: key() }),
  confirmClosing: (id: string, countedCash: string, note?: string) =>
    send<Closing>('POST', '/fees/closings/{id}/confirm', { path: { id }, body: { counted_cash: countedCash, note: note || null }, headers: key() }),
  propose: (body: { student_id: string; kind: AdjustmentKind; demand_id?: string | null; amount?: string; percent?: string; reason: string }) =>
    send<Adjustment>('POST', '/fees/adjustments', { body, headers: key() }),
  approveAdjustment: (id: string, reason: string) => send<Adjustment>('POST', '/fees/adjustments/{id}/approve', { path: { id }, body: { reason }, headers: key() }),
  rejectAdjustment: (id: string, reason: string) => send<Adjustment>('POST', '/fees/adjustments/{id}/reject', { path: { id }, body: { reason }, headers: key() }),
  cancelReceipt: (id: string, reason: string) => send<Cancellation>('POST', '/receipts/{id}/cancel', { path: { id }, body: { reason }, headers: key() }),
  forwardCancellation: (id: string) => send<Cancellation>('POST', '/fees/cancellations/{id}/forward', { path: { id }, headers: key() }),
  approveCancellation: (id: string, reason: string) => send<Cancellation>('POST', '/fees/cancellations/{id}/approve', { path: { id }, body: { reason }, headers: key() }),
  rejectCancellation: (id: string, reason: string) => send<Cancellation>('POST', '/fees/cancellations/{id}/reject', { path: { id }, body: { reason }, headers: key() }),
  createHead: (name: string) => send<Head>('POST', '/fees/heads', { body: { name }, headers: key() }),
  updateHead: (id: string, body: { name?: string; status?: string }) => send<Head>('PATCH', '/fees/heads/{id}', { path: { id }, body }),
  createCategory: (name: string) => send<Head>('POST', '/fees/categories', { body: { name }, headers: key() }),
  createPlan: (body: unknown) => send<Plan>('POST', '/fees/plans', { body, headers: key() }),
  updatePlan: (id: string, body: unknown) => send<Plan>('PUT', '/fees/plans/{id}', { path: { id }, body }),
  raise: () => send<{ raised: number; students: number; skipped_no_plan: number }>('POST', '/fees/demands/raise', { headers: key() }),
  saveGateway: (body: { provider: string; key_id: string; key_secret?: string; webhook_secret?: string; status?: string }) => send<GatewayView>('PUT', '/fees/gateway', { body }),
  checkout: (studentId: string, demandIds: string[]) => send<Checkout>('POST', '/fees/checkout', { body: { student_id: studentId, demand_ids: demandIds }, headers: key() }),
  fakePay: (id: string, outcome: 'paid' | 'failed') => send<{ queued: boolean }>('POST', '/fees/checkout/{id}/fake', { path: { id }, body: { outcome }, headers: key() }),
};

/** Paise → "₹1,250.50" (Indian digit grouping), built from integers only. */
export function rupees(paise: number | null | undefined, language = 'en'): string {
  if (paise === null || paise === undefined) return '–';
  const sign = paise < 0 ? '−' : '';
  const abs = Math.abs(Math.trunc(paise));
  const whole = Math.trunc(abs / 100);
  const part = String(abs % 100).padStart(2, '0');
  const grouped = new Intl.NumberFormat(language === 'hi' ? 'hi-IN' : 'en-IN', { maximumFractionDigits: 0, useGrouping: true }).format(whole);

  return `${sign}₹${grouped}.${part}`;
}

/** Paise → "1250.50", for a form field (no symbol, no grouping). */
export function rupeeInput(paise: number): string {
  const abs = Math.abs(paise);

  return `${paise < 0 ? '-' : ''}${Math.trunc(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}

/** A rupee amount as typed: digits with at most two after the point (commas allowed). */
export function validRupees(value: string): boolean {
  return /^\d{1,9}(\.\d{1,2})?$/.test(value.replace(/[,\s₹]/g, ''));
}

/** A valid typed rupee amount ("43,370.5") as whole paise, exactly (no floating point); null if not valid. */
export function typedPaise(value: string): number | null {
  if (!validRupees(value)) return null;
  const [whole, part = ''] = value.replace(/[,\s₹]/g, '').split('.');

  return Number(whole) * 100 + Number(part.padEnd(2, '0'));
}

/** Adds whole paise (integers stay exact in JavaScript up to 2^53). */
export function sumPaise(values: number[]): number {
  return values.reduce((a, b) => a + Math.trunc(b), 0);
}

export const demandChip = (status: DemandStatus) => (({ paid: 'approved', part_paid: 'pending', overdue: 'rejected', due: 'draft' }) as const)[status];
