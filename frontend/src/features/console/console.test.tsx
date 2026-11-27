import { screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { fakeApi, json } from '@/test/setup';
import { renderApp } from '@/test/renderApp';

const path = (r: Request) => new URL(r.url).pathname;

const school = {
  id: 't_1',
  code: 'NEW-BPL',
  name: 'New Public School',
  status: 'active',
  contact: { email: 'office@new-bpl.test', phone: '+919800000123' },
  licence: { id: 'l1', key_last4: 'ABCD', plan: 'standard', modules: ['attendance', 'leave'], seats: { students: 500, staff: 50 }, valid_from: '2026-04-01', valid_to: '2027-03-31', status: 'active', state: 'active', activated_at: null, revoked_at: null },
  usage: { students: 470, student_seats: 500, staff: 12, staff_seats: 50, active_7d: 9, active_30d: 14, last_activity_at: '2026-11-27T09:00:00+00:00', storage_bytes: 2_621_440, computed_at: '2026-11-27T09:15:00+00:00' },
  client: { package_name: 'Standard 500', paid_until: '2027-03-31', billing_period: 'yearly' },
  open_alerts: 1,
  created_at: null,
  client_record: { contact_person: 'Made Up Trustee', phone: null, email: null, address: null, package_name: 'Standard 500', price: 60000, billing_period: 'yearly', paid_until: '2027-03-31', notes: null },
  payments: [{ id: 'p1', amount: 60000, paid_on: '2026-04-02', method: 'upi', reference: 'UPI-1', period_from: null, period_to: null, note: null, recorded_by: 'Ravi', at: '2026-04-02T10:00:00+00:00' }],
  licence_history: [{ action: 'renewed', at: '2026-11-20T10:00:00+00:00', by: 'Ravi', changed: ['valid_to'], reason: null, before: null, after: { valid_to: '2027-03-31' } }],
  support_grants: [{ id: 'g1', agent_name: 'Asha Support', agent_email: 'asha@campustab.test', scope: ['settings'], read_only: true, starts_at: '2026-11-27T08:00:00+00:00', expires_at: '2026-11-27T12:00:00+00:00', status: 'active' }],
  security: {
    last_hour: { failed_sign_ins: 26, lockouts: 1, password_resets: 0, mfa_resets: 0, chief_changes: 0 },
    '24h': { failed_sign_ins: 31, lockouts: 2, password_resets: 1, mfa_resets: 0, chief_changes: 0 },
    '7d': { failed_sign_ins: 40, lockouts: 2, password_resets: 3, mfa_resets: 1, chief_changes: 1 },
    '30d': { failed_sign_ins: 55, lockouts: 3, password_resets: 6, mfa_resets: 1, chief_changes: 1 },
  },
  alerts: [{ id: 'a1', school_code: 'NEW-BPL', metric: 'failed_sign_ins', count: 26, baseline: 0.2, message: 'NEW-BPL: 26 failed sign ins in the last hour (usually 0.2 an hour).', raised_at: '2026-11-27T09:15:00+00:00', acknowledged_at: null, acknowledged_by: null }],
};

describe('platform console school page', () => {
  it('shows seats, usage, security counts, client, payments, licence history and grants from platform data only', async () => {
    fakeApi.me = null;
    fakeApi.handlers.push((r) => {
      if (path(r).endsWith('/platform/me')) {
        return json({ data: { id: 'a', name: 'Ravi Platform', email: 'ravi@campustab.test', mfa: { enabled: true, required: true }, session: { idle_minutes: 15, ends_at: null, mfa_enrolment_only: false } } });
      }
      if (path(r).endsWith('/platform/schools/NEW-BPL')) return json({ data: school });
      if (path(r).endsWith('/platform/audit-logs')) return json({ data: [] });
      return undefined;
    });

    await renderApp('/console/schools/NEW-BPL');

    expect(await screen.findByRole('heading', { name: 'New Public School' })).toBeInTheDocument();
    expect(screen.getByText('470 / 500')).toBeInTheDocument();
    expect(screen.getByText('12 / 50')).toBeInTheDocument();
    expect(screen.getByText('2.5 MB')).toBeInTheDocument();

    const security = screen.getByRole('heading', { name: 'Security overview' }).closest('section') as HTMLElement;
    expect(within(security).getByText('Failed sign-ins').closest('tr')).toHaveTextContent('26314055');
    expect(within(security).getByText(/26 failed sign ins in the last hour/)).toBeInTheDocument();

    expect(screen.getByText('Made Up Trustee')).toBeInTheDocument();
    expect(screen.getAllByText('₹60,000')).toHaveLength(2);
    expect(screen.getByText('Renewed')).toBeInTheDocument();
    expect(screen.getByText('Asha Support')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Renew' })).toBeInTheDocument();

    // The console never asks the school's own data endpoints.
    const schoolData = fakeApi.requests.filter((r) => /\/api\/v1\/(students|users|staff|audit-logs|config)/.test(path(r)));
    expect(schoolData).toEqual([]);
  });
});
