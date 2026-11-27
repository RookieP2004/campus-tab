import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import i18n from '@/i18n';
import { usePreferences } from '@/stores/preferences';
import { demoUser, fakeApi, json } from '@/test/setup';
import { renderApp } from '@/test/renderApp';

const TODAY = '2026-11-27';

const settings = {
  student_mode: 'daily',
  edit_window_hours: 4,
  min_percent_alert: 75,
  late_entry_days: 7,
  school_starts_at: '08:00',
  periods_per_day: 8,
  staff: { late_after: '08:15', geofence: { enabled: false } },
  today: TODAY,
  timezone: 'Asia/Kolkata',
};

const openRules = {
  can_save: true,
  needs_reason: false,
  reason_kind: null,
  office_only: false,
  window_closes_at: `${TODAY}T12:00:00+05:30`,
  late_entry_until: '2026-12-04',
  refusal: null,
  refusal_message: null,
};

function registerDay(overrides: Record<string, unknown> = {}) {
  return {
    section: { id: 'sec1', label: '7-B' },
    date: TODAY,
    period: 0,
    mode: 'daily',
    holiday: null,
    marked: false,
    marked_by: null,
    marked_at: null,
    late_entry: null,
    source: null,
    day_id: null,
    rules: openRules,
    students: [
      { id: 's1', name: 'Aarav Sharma', roll_no: 1, admission_no: 'A1', status: 'P' },
      { id: 's2', name: 'Diya Gupta', roll_no: 2, admission_no: 'A2', status: 'P' },
      { id: 's3', name: 'Ishita Rao', roll_no: 3, admission_no: 'A3', status: 'LV' },
    ],
    ...overrides,
  };
}

const classTeacher = {
  ...demoUser,
  roles: ['class_teacher'],
  permissions: ['attendance.view:class', 'attendance.mark:class', 'attendance.request_edit:class', 'staff_attendance.mark:own', 'calendar.view:school'],
  scope: { ...demoUser.scope, class_sections: ['sec1'] },
};

let day: ReturnType<typeof registerDay>;
let puts: { url: string; body: Record<string, unknown> }[];

function serve() {
  fakeApi.handlers.push(async (request) => {
    const url = new URL(request.url);
    const path = url.pathname;
    if (path.endsWith('/attendance/settings')) return json({ data: settings });
    if (path.endsWith(`/attendance/sections/sec1/days/${day.date}`)) {
      if (request.method === 'PUT') {
        const body = (await request.json()) as Record<string, unknown>;
        puts.push({ url: request.url, body });
        return json({ data: { ...day, marked: true, marked_by: 'Anita Verma', marked_at: `${day.date}T08:47:00+05:30` } });
      }
      return json({ data: day });
    }
    return undefined;
  });
}

const rowFor = (name: string) => screen.getByRole('button', { name: new RegExp(`${name}.*(tap to change|cannot be changed|approved leave)`) });
const otherWrites = () => fakeApi.requests.filter((r) => r.method !== 'GET' && new URL(r.url).pathname.includes('/attendance'));

describe('attendance taker', () => {
  beforeEach(async () => {
    act(() => usePreferences.setState({ language: 'en', theme: 'system' }));
    await i18n.changeLanguage('en');
    fakeApi.me = classTeacher;
    day = registerDay();
    puts = [];
    serve();
  });

  afterEach(() => {
    Reflect.deleteProperty(window.navigator, 'onLine');
  });

  it('starts with everyone present, a tap marks absent, and one PUT saves the whole register', async () => {
    const user = userEvent.setup();
    await renderApp('/attendance/sec1');

    expect(await screen.findByRole('heading', { level: 1, name: '7-B attendance' })).toBeInTheDocument();
    expect(rowFor('Aarav Sharma')).toHaveAttribute('data-status', 'P');
    expect(rowFor('Diya Gupta')).toHaveAttribute('data-status', 'P');
    // Approved leave is shown and cannot be changed.
    expect(rowFor('Ishita Rao')).toHaveAttribute('data-status', 'LV');
    expect(rowFor('Ishita Rao')).toBeDisabled();
    expect(screen.getByTestId('counts')).toHaveTextContent('Present 2');

    await user.click(rowFor('Aarav Sharma'));
    expect(rowFor('Aarav Sharma')).toHaveAttribute('data-status', 'A');
    expect(screen.getByTestId('counts')).toHaveTextContent('Absent 1');
    // Tap again: back to present; and once more: absent.
    await user.click(rowFor('Aarav Sharma'));
    expect(rowFor('Aarav Sharma')).toHaveAttribute('data-status', 'P');
    await user.click(rowFor('Aarav Sharma'));

    // Late and half-day come from the small menu.
    await user.click(screen.getByRole('button', { name: 'More choices for Diya Gupta' }));
    await user.click(within(screen.getByRole('group', { name: 'Status for Diya Gupta' })).getByRole('button', { name: 'Late' }));
    expect(rowFor('Diya Gupta')).toHaveAttribute('data-status', 'L');

    expect(otherWrites()).toHaveLength(0);
    await user.click(screen.getByRole('button', { name: 'Save attendance · 3 students' }));

    expect(await screen.findByText(/Saved at .* 1 parent told about the absence/)).toBeInTheDocument();
    expect(puts).toHaveLength(1);
    expect(puts[0]?.body).toEqual({ statuses: { s1: 'A', s2: 'L' } });
    expect(otherWrites()).toHaveLength(1);
  });

  it('needs a reason for a late entry from the paper register before it sends anything', async () => {
    day = registerDay({ date: '2026-11-24', rules: { ...openRules, needs_reason: true, reason_kind: 'late_entry' } });
    const user = userEvent.setup();
    await renderApp('/attendance/sec1?date=2026-11-24');

    expect(await screen.findByText('Late entry from the paper register')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Save attendance/ }));
    expect(await screen.findByText(/Say why this day is entered late/)).toBeInTheDocument();
    expect(puts).toHaveLength(0);

    await user.type(screen.getByLabelText('Why is this day entered late?'), 'No internet on 24 November; entered from the paper register');
    await user.click(screen.getByRole('button', { name: /Save attendance/ }));

    await waitFor(() => expect(puts).toHaveLength(1));
    expect(puts[0]?.body).toMatchObject({ reason: 'No internet on 24 November; entered from the paper register', statuses: { s1: 'P', s2: 'P' } });
  });

  it('shows why a change is refused after the edit window, and offers no save', async () => {
    day = registerDay({
      marked: true,
      marked_by: 'Anita Verma',
      marked_at: `${TODAY}T08:40:00+05:30`,
      rules: {
        ...openRules,
        can_save: false,
        needs_reason: true,
        reason_kind: 'late_edit',
        office_only: true,
        refusal: 'attendance_window_closed',
        refusal_message: 'The edit window for this day has closed. Ask the Clerk or the Principal to make the change, with a reason.',
      },
    });
    await renderApp('/attendance/sec1');

    expect(await screen.findByText('The edit window for this day has closed.')).toBeInTheDocument();
    expect(screen.getByText('Ask the Clerk or the Principal to make the change, with a reason.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Save attendance/ })).not.toBeInTheDocument();
    expect(rowFor('Aarav Sharma')).toBeDisabled();
  });

  it('tells the teacher to keep the paper register when offline, and sends nothing', async () => {
    Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => false });
    const user = userEvent.setup();
    await renderApp('/attendance/sec1');

    expect(await screen.findByText(/No internet connection\. Nothing has been sent\. Keep the register on paper/)).toHaveTextContent('within 7 days');
    await user.click(rowFor('Aarav Sharma'));
    await user.click(screen.getByRole('button', { name: /Save attendance/ }));

    expect(puts).toHaveLength(0);
    expect(otherWrites()).toHaveLength(0);
  });

  it('shows a holiday as read-only', async () => {
    day = registerDay({
      holiday: { title: 'Guru Nanak Jayanti' },
      rules: { ...openRules, can_save: false, refusal: 'holiday', refusal_message: 'This day is a holiday in the school calendar.' },
      students: registerDay().students.map((s) => ({ ...s, status: 'H' })),
    });
    await renderApp('/attendance/sec1');

    expect(await screen.findByText(/Holiday: Guru Nanak Jayanti/)).toBeInTheDocument();
    expect(rowFor('Aarav Sharma')).toHaveAttribute('data-status', 'H');
    expect(screen.queryByRole('button', { name: /Save attendance/ })).not.toBeInTheDocument();
  });

  it('has no accessibility violations on the taker screen, in light and dark', async () => {
    document.title = 'Campus TAB';
    await renderApp('/attendance/sec1');
    await screen.findByRole('heading', { level: 1, name: '7-B attendance' });

    for (const theme of ['light', 'dark'] as const) {
      act(() => usePreferences.setState({ theme }));
      await waitFor(() => expect(document.documentElement).toHaveAttribute('data-theme', theme));
      const results = await axe.run(document, { resultTypes: ['violations'] });
      expect(results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
    }
  });
});

describe('staff attendance', () => {
  beforeEach(async () => {
    act(() => usePreferences.setState({ language: 'en', theme: 'system' }));
    await i18n.changeLanguage('en');
  });

  afterEach(() => {
    Reflect.deleteProperty(window.navigator, 'geolocation');
  });

  it('shows the server refusal when the attendance officer enters their own attendance, and lists every row it gets', async () => {
    fakeApi.me = { ...demoUser, roles: ['clerk'], permissions: ['staff_attendance.enter:school', 'staff_attendance.view:school', 'staff_attendance.mark:own'] };
    let manual: Record<string, unknown> | null = null;
    fakeApi.handlers.push(async (request) => {
      const path = new URL(request.url).pathname;
      if (path.endsWith('/attendance/settings')) return json({ data: settings });
      if (path.endsWith('/attendance/staff')) {
        return json({
          data: {
            date: TODAY,
            staff: [
              { staff_id: 'st1', name: 'Anita Verma', designation: 'Clerk', roles: ['clerk'], can_enter: true, record: null },
              { staff_id: 'st2', name: 'Ravi Kumar', designation: 'Teacher', roles: ['subject_teacher'], can_enter: false, record: { id: 'r2', staff_id: 'st2', date: TODAY, status: 'P', in: '08:05', out: null, source: 'app', inside_area: true, late: false, entered_by: null, reason: null } },
            ],
          },
        });
      }
      if (path.endsWith('/attendance/staff/st1/manual')) {
        manual = (await request.json()) as Record<string, unknown>;
        return json({ error: { code: 'own_attendance', message: 'You cannot enter your own attendance.', details: {} } }, 403);
      }
      return undefined;
    });
    const user = userEvent.setup();
    await renderApp('/attendance/staff');

    const list = await screen.findByRole('list', { name: 'Staff' });
    expect(within(list).getAllByRole('listitem')).toHaveLength(2);

    expect(screen.queryByRole('button', { name: 'Enter manually: Ravi Kumar' })).toBeNull();
    await user.click(screen.getByRole('button', { name: 'Enter manually: Anita Verma' }));
    const dialog = screen.getByRole('dialog', { name: 'Manual entry for Anita Verma' });
    await user.click(within(dialog).getByRole('button', { name: 'Save' }));
    expect(within(dialog).getByText('Write a reason of at least 5 characters.')).toBeInTheDocument();
    expect(manual).toBeNull();

    await user.type(within(dialog).getByLabelText(/^Reason/), 'Forgot to check in at the gate');
    await user.type(within(dialog).getByLabelText('Time in'), '08:10');
    await user.click(within(dialog).getByRole('button', { name: 'Save' }));

    expect(await within(dialog).findByText('Nobody can enter their own attendance. Someone at a higher level enters it for you.')).toBeInTheDocument();
    expect(manual).toEqual({ date: TODAY, status: 'P', in: '08:10', reason: 'Forgot to check in at the gate' });
  });

  for (const geofence of [false, true]) {
    it(`sends the location with a check-in only when the geofence is ${geofence ? 'on' : 'off'}`, async () => {
      fakeApi.me = classTeacher;
      const getCurrentPosition = vi.fn((ok: PositionCallback) => ok({ coords: { latitude: 23.25, longitude: 77.41 } } as GeolocationPosition));
      Object.defineProperty(window.navigator, 'geolocation', { configurable: true, value: { getCurrentPosition } });
      let sent: Record<string, unknown> | null = null;
      fakeApi.handlers.push(async (request) => {
        const path = new URL(request.url).pathname;
        if (path.endsWith('/attendance/settings')) return json({ data: settings });
        if (path.endsWith('/attendance/staff/me')) return json({ data: { is_staff: true, today: null, late_after: '08:15', geofence, days: [] } });
        if (path.endsWith('/attendance/staff/check-in')) {
          sent = (await request.json()) as Record<string, unknown>;
          return json({ data: { id: 'r1', staff_id: 'st1', date: TODAY, status: 'P', in: '08:02', out: null, source: 'app', inside_area: geofence ? true : null, late: false, entered_by: null, reason: null } }, 201);
        }
        return undefined;
      });
      const user = userEvent.setup();
      await renderApp('/attendance/me');

      await user.click(await screen.findByRole('button', { name: 'Check in' }));
      await waitFor(() => expect(sent).not.toBeNull());

      if (geofence) {
        expect(sent).toEqual({ method: 'app', lat: 23.25, lng: 77.41 });
        expect(getCurrentPosition).toHaveBeenCalledTimes(1);
        expect(await screen.findByText('You were inside the school area.')).toBeInTheDocument();
        expect(document.body.textContent).not.toContain('23.25');
      } else {
        expect(sent).toEqual({ method: 'app' });
        expect(getCurrentPosition).not.toHaveBeenCalled();
      }
      expect(await screen.findByText('In at 08:02')).toBeInTheDocument();
    });
  }
});

describe('holiday calendar', () => {
  beforeEach(async () => {
    act(() => usePreferences.setState({ language: 'en', theme: 'system' }));
    await i18n.changeLanguage('en');
  });

  const entries = [{ id: 'c1', date: '2026-12-25', end_date: '2026-12-25', type: 'holiday', title: 'Christmas', status: 'pending', created_by: 'Ramesh Kumar', decided_by: null, decision_note: null }];

  for (const approve of [false, true]) {
    it(`shows approve and reject only when the server says the person may approve (${approve})`, async () => {
      fakeApi.me = { ...demoUser, roles: [approve ? 'principal' : 'clerk'], permissions: ['calendar.view:school', approve ? 'calendar.approve:school' : 'calendar.manage:school'] };
      const decided: string[] = [];
      fakeApi.handlers.push((request) => {
        const path = new URL(request.url).pathname;
        if (path.endsWith('/calendar')) return json({ data: entries, can: { manage: !approve, approve } });
        if (path.endsWith('/calendar/c1/approve')) {
          decided.push('approve');
          return json({ data: { ...entries[0], status: 'approved' } });
        }
        return undefined;
      });
      const user = userEvent.setup();
      await renderApp('/calendar?month=2026-12');

      expect(await screen.findByText('Christmas')).toBeInTheDocument();
      if (approve) {
        expect(screen.getByRole('button', { name: 'Reject: Christmas' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Edit: Christmas' })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Approve: Christmas' }));
        await waitFor(() => expect(decided).toEqual(['approve']));
      } else {
        expect(screen.queryByRole('button', { name: /Approve/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Reject/ })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Edit: Christmas' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add an entry' })).toBeInTheDocument();
      }
    });
  }
});

describe('attendance reports and dashboard', () => {
  beforeEach(async () => {
    act(() => usePreferences.setState({ language: 'en', theme: 'system' }));
    await i18n.changeLanguage('en');
    fakeApi.me = classTeacher;
  });

  it('puts a big Take attendance button on the class teacher dashboard and makes Attendance the main phone tab', async () => {
    const { device } = await import('@/test/setup');
    device.width = 375;
    fakeApi.handlers.push((request) =>
      new URL(request.url).pathname.endsWith('/me/dashboard')
        ? json({
            data: {
              role: 'class_teacher',
              roles: [{ name: 'class_teacher', label: 'Class Teacher' }],
              cards: [
                { id: 'take_attendance.sec1', kind: 'action', title: 'dashboard.cards.takeAttendance', params: { section: '7-B' }, priority: 0, link: '/attendance/sec1', data: { section_id: 'sec1', date: TODAY, marked: false, absent: null, holiday: null } },
                { id: 'check_in', kind: 'action', title: 'dashboard.cards.checkIn', priority: 2, link: '/attendance/me', data: { checked_in: false, in: null, out: null, late: false, late_after: '08:15' } },
              ],
            },
          })
        : undefined,
    );
    const { container } = await renderApp('/');

    expect(await screen.findByText('Attendance: 7-B')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Take attendance' })).toHaveAttribute('href', '/attendance/sec1');
    expect(screen.getByRole('link', { name: 'Check in' })).toHaveAttribute('href', '/attendance/me');
    const tabs = container.querySelector('[data-layout="tabs"]') as HTMLElement;
    expect(within(tabs).getAllByRole('link').map((a) => a.textContent)).toEqual(['Home', 'Attendance', 'Inbox', 'More']);
  });

  it('shows the daily summary with links, and a printable monthly register with late-entry days marked', async () => {
    fakeApi.handlers.push((request) => {
      const url = new URL(request.url);
      const path = url.pathname;
      if (path.endsWith('/attendance/settings')) return json({ data: settings });
      if (path.endsWith('/attendance/summary')) {
        return json({
          data: {
            date: TODAY,
            sections: [
              { section_id: 'sec1', section: '7-B', marked: true, late_entry: false, counts: { P: 38, A: 2, L: 1, HD: 0, LV: 0, H: 0 }, percent: 95.1 },
              { section_id: 'sec2', section: '7-C', marked: false, late_entry: false, counts: { P: 0, A: 0, L: 0, HD: 0, LV: 0, H: 0 }, percent: null },
            ],
            totals: { students: 41, present: 39, absent: 2, marked_sections: 1, sections: 2, percent: 95.1 },
            staff: null,
          },
        });
      }
      if (path.endsWith('/attendance/below-threshold')) return json({ data: { threshold: 75, students: [{ id: 's9', name: 'Kavya Menon', section: '7-B', section_id: 'sec1', percent: 68, days: 40 }] } });
      if (path.endsWith('/attendance/register')) {
        return json({
          data: {
            section: { id: 'sec1', label: '7-B' },
            month: '2026-11',
            dates: ['2026-11-24', '2026-11-25'],
            late_entries: ['2026-11-24'],
            students: [{ id: 's1', name: 'Aarav Sharma', roll_no: 1, admission_no: 'A1', marks: { '2026-11-24': 'A', '2026-11-25': 'P' }, percent: 92 }],
          },
        });
      }
      return undefined;
    });
    const user = userEvent.setup();
    const { router } = await renderApp('/attendance/summary');

    const row = (await screen.findByRole('rowheader', { name: '7-B' })).closest('tr') as HTMLElement;
    expect(within(row).getByText('95.1%')).toBeInTheDocument();
    expect(screen.getByText('Not marked')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Kavya Menon' })).toHaveAttribute('href', '/students/s9?tab=attendance');

    await user.click(within(row).getByRole('link', { name: /Monthly register\s*7-B/ }));
    await waitFor(() => expect(router.state.location.pathname).toBe('/attendance/register'));
    expect(await screen.findByRole('heading', { level: 1, name: /7-B · November 2026/ })).toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: /24.*Late entry/ })).toBeInTheDocument();
    const print = vi.spyOn(window, 'print').mockImplementation(() => undefined);
    await user.click(screen.getByRole('button', { name: 'Print' }));
    expect(print).toHaveBeenCalledTimes(1);
  });
});
