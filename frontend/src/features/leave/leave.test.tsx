import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { act } from 'react';
import { beforeEach, describe, expect, it } from 'vitest';
import i18n from '@/i18n';
import { usePreferences } from '@/stores/preferences';
import { demoUser, fakeApi, json } from '@/test/setup';
import { renderApp } from '@/test/renderApp';
import type { LeaveDetail } from './api';

const types = [
  { id: 't1', code: 'CL', name: 'Casual leave', quota: 12, sandwich: false, half_day: true, document_after_days: null, status: 'active' },
  { id: 't2', code: 'SL', name: 'Sick leave', quota: 10, sandwich: false, half_day: true, document_after_days: 2, status: 'active' },
];

const teacher = { ...demoUser, roles: ['class_teacher'], permissions: ['leave.apply:own', 'leave.view:school', 'student_leave.approve:class'] };
const principal = { ...demoUser, roles: ['principal'], permissions: ['leave.apply:own', 'leave.view:school', 'leave.decide:school', 'leave.fast_track:school', 'leave.manage_types:school'] };

const can = { edit: false, submit: false, withdraw: false, attach: false, request_cancel: false, verify: false, return: false, approve: false, reject: false, fast_track: false, cancel: false, keep: false };

function request(overrides: Partial<LeaveDetail> = {}): LeaveDetail {
  return {
    id: 'r1',
    kind: 'staff',
    applicant: { name: 'Anjali Verma', designation: 'TGT Mathematics', section: null, student_id: null },
    applied_by: 'Anjali Verma',
    type_code: 'CL',
    type_name: 'Casual leave',
    from: '2026-12-03',
    to: '2026-12-04',
    half_day: null,
    days: 2,
    status: 'verified',
    stage: 'principal',
    chain: ['clerk', 'principal'],
    fast_tracked: false,
    principal_sees: false,
    cancel_requested: false,
    has_documents: false,
    last_step: null,
    created_at: '2026-11-26T09:12:00+05:30',
    updated_at: '2026-11-26T10:40:00+05:30',
    reason: 'Family wedding in Indore.',
    documents: [],
    timeline: [
      { stage: 'applicant', action: 'submitted', by: 'Anjali Verma', remark: null, at: '2026-11-26T09:12:00+05:30', from: null, to: null, restored_days: null, state: 'done' },
      { stage: 'clerk', action: 'verified', by: 'Ramesh Kumar', remark: 'Balance OK.', at: '2026-11-26T10:40:00+05:30', from: null, to: null, restored_days: null, state: 'done' },
      { stage: 'principal', action: null, by: null, remark: null, at: null, from: null, to: null, restored_days: null, state: 'current' },
    ],
    cover_needed: [{ date: '2026-12-03', section_id: 's1', section: '7-B', subject: 'Mathematics', kind: 'lesson' }],
    approved: null,
    cancel_request: null,
    balance: { type_code: 'CL', type_name: 'Casual leave', quota: 12, reserved: 2, taken: 0, available: 10 },
    can,
    ...overrides,
  };
}

describe('leave', () => {
  beforeEach(async () => {
    act(() => usePreferences.setState({ language: 'en', theme: 'system' }));
    await i18n.changeLanguage('en');
  });

  it('shows the days and the balance after, live, and sends the request with an Idempotency-Key', async () => {
    fakeApi.me = teacher;
    let sent: Request | null = null;
    fakeApi.handlers.push(async (req) => {
      const path = new URL(req.url).pathname;
      if (path.endsWith('/leave-types')) return json({ data: types, can: { manage: false } });
      if (path.endsWith('/leave-requests/preview')) {
        return json({ data: { days: 2, counted: ['2026-12-03', '2026-12-04'], off: [], sandwich: false, document_required: false, balance: types[0] && { type_code: 'CL', type_name: 'Casual leave', quota: 12, reserved: 0, taken: 2, available: 10 }, available_after: 8, chain: ['clerk', 'principal'] } });
      }
      if (path.endsWith('/leave-requests') && req.method === 'POST') {
        sent = req.clone();
        return json({ data: request({ status: 'submitted', stage: 'clerk' }) }, 201);
      }
      if (path.endsWith('/leave-requests/r1')) return json({ data: request({ status: 'submitted', stage: 'clerk' }) });
      return undefined;
    });
    const user = userEvent.setup();
    const { router } = await renderApp('/leave/apply');

    expect(await screen.findByText(/2 days · 10 of 12 → 8 after/)).toBeInTheDocument();
    expect(screen.getByText('Goes to: Clerk → Principal')).toBeInTheDocument();

    await user.type(screen.getByLabelText('Reason'), 'Family wedding in Indore.');
    await user.click(screen.getByRole('button', { name: 'Send the request' }));

    await waitFor(() => expect(router.state.location.pathname).toBe('/leave/requests/r1'));
    expect(sent).not.toBeNull();
    const body = (await (sent as unknown as Request).json()) as Record<string, unknown>;
    expect(body).toMatchObject({ type_code: 'CL', reason: 'Family wedding in Indore.', submit: true });
    expect((sent as unknown as Request).headers.get('Idempotency-Key')).toMatch(/^leave-/);
  });

  it('shows the Principal the steps, the reason and the cover list, with Approve and Reject only when allowed', async () => {
    fakeApi.me = principal;
    let approved = false;
    fakeApi.handlers.push((req) => {
      const path = new URL(req.url).pathname;
      if (path.endsWith('/leave-requests/r1/approve')) {
        approved = true;
        return json({ data: request({ status: 'approved', stage: null, can }) });
      }
      if (path.endsWith('/leave-requests/r1')) return json({ data: request({ can: { ...can, approve: true, reject: true, return: true } }) });
      return undefined;
    });
    const user = userEvent.setup();
    await renderApp('/leave/requests/r1');

    expect(await screen.findByText('Family wedding in Indore.')).toBeInTheDocument();
    const steps = screen.getByRole('list', { name: 'Steps' });
    expect(within(steps).getByText('Checked by Ramesh Kumar (Clerk)')).toBeInTheDocument();
    expect(within(steps).getByText('“Balance OK.”')).toBeInTheDocument();
    expect(within(steps).getByText('Waiting for the Principal')).toBeInTheDocument();
    expect(screen.getByRole('cell', { name: '7-B' })).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Reject' }));
    const dialog = await screen.findByRole('dialog');
    // A reason is required to reject.
    expect(within(dialog).getByRole('button', { name: 'Reject' })).toBeDisabled();
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }));

    await user.click(screen.getByRole('button', { name: 'Approve' }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(approved).toBe(true));
    expect(await screen.findByText(/Approved\. Anjali Verma has been told/)).toBeInTheDocument();
  });

  it('shows no decision buttons to the applicant', async () => {
    fakeApi.me = teacher;
    fakeApi.handlers.push((req) => (new URL(req.url).pathname.endsWith('/leave-requests/r1') ? json({ data: request({ can: { ...can, withdraw: true } }) }) : undefined));
    await renderApp('/leave/requests/r1');

    expect(await screen.findByRole('button', { name: 'Withdraw' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
  });

  it('shows only names on the leave calendar', async () => {
    fakeApi.me = teacher;
    fakeApi.handlers.push((req) =>
      new URL(req.url).pathname.endsWith('/leave/calendar') ? json({ data: [{ name: 'Kamla Devi', from: '2026-01-01', to: '2030-12-31', half_day: null, own: false, request_id: null }] }) : undefined,
    );
    await renderApp('/leave/calendar');

    expect((await screen.findAllByText('Kamla Devi')).length).toBeGreaterThan(0);
    expect(screen.queryByRole('link', { name: 'Kamla Devi' })).not.toBeInTheDocument();
  });

  it('lists what is waiting in the approvals inbox and opens one beside the list', async () => {
    fakeApi.me = principal;
    fakeApi.handlers.push((req) => {
      const path = new URL(req.url).pathname;
      if (path.endsWith('/approvals/inbox')) {
        return json({
          data: [{ kind: 'leave', id: 'r1', title: 'Anjali Verma · Casual leave', detail: null, subtitle: { from: '2026-12-03', to: '2026-12-04', days: 2, student: false, last_action: 'verified', last_by: 'Ramesh Kumar' }, status: 'verified', at: '2026-11-26T10:40:00+05:30', link: '/approvals?item=leave:r1', flags: [] }],
          counts: { leave: 1 },
          total: 1,
        });
      }
      if (path.endsWith('/leave/calendar')) return json({ data: [] });
      if (path.endsWith('/leave-requests/r1')) return json({ data: request({ can: { ...can, approve: true } }) });
      return undefined;
    });
    const user = userEvent.setup();
    await renderApp('/approvals');

    expect(await screen.findByRole('tab', { name: 'Leave 1' })).toBeInTheDocument();
    await user.click(await screen.findByRole('button', { name: /Anjali Verma · Casual leave/ }));
    expect(await screen.findByText('Family wedding in Indore.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Approve' })).toBeInTheDocument();
  });
});
