import { focusManager } from '@tanstack/react-query';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { act } from 'react';
import { beforeEach, describe, expect, it } from 'vitest';
import i18n from '@/i18n';
import { usePreferences } from '@/stores/preferences';
import { demoUser, fakeApi, json } from '@/test/setup';
import { renderApp } from '@/test/renderApp';

const chiefCards = [
  {
    id: 'licence',
    kind: 'licence',
    title: 'dashboard.cards.licence',
    priority: 0,
    link: null,
    data: { plan: 'standard', state: 'active', valid_to: '2027-03-31', students: { limit: 500, used: 450, available: 50, warning: true }, staff: { limit: 50, used: 12, available: 38, warning: false } },
  },
  { id: 'new_module.leave', kind: 'banner', title: 'dashboard.cards.newModule', params: { module: 'leave' }, priority: 1, link: '/settings/school', data: { module: 'leave' } },
  {
    id: 'approvals',
    kind: 'todo',
    title: 'dashboard.cards.approvals',
    priority: 5,
    link: '/staff?status=draft',
    data: { count: 1, items: [{ kind: 'staff_draft', label: 'Kiran Bhatt', detail: 'Accountant', by: 'Ramesh Kumar', link: '/staff/st1', at: '2026-11-25T10:00:00+05:30' }] },
  },
  { id: 'future_widget', kind: 'chart', title: 'dashboard.cards.attendanceToday', priority: 8, link: '/attendance', data: { series: [1, 2, 3] } },
  { id: 'on_roll', kind: 'kpi', title: 'dashboard.cards.onRoll', priority: 10, link: '/students', data: { students: 1248, staff: 84 } },
  { id: 'users_by_role', kind: 'kpi', title: 'dashboard.cards.usersByRole', priority: 20, link: '/staff', data: { by_role: { parent: 900, subject_teacher: 40 } } },
  {
    id: 'notices',
    kind: 'notices',
    title: 'dashboard.cards.notices',
    priority: 30,
    link: '/notices',
    data: { unread: 1, can_send: true, items: [{ id: 'a1', label: 'Sports day on Friday', by: 'Dr. Meera Iyer', at: '2026-11-26T09:00:00+05:30', read: false, link: '/notices/a1' }] },
  },
  { id: 'recent_audit', kind: 'list', title: 'dashboard.cards.recentAudit', priority: 40, link: null, data: { items: [{ label: 'auth.login_failed', detail: 'user', by: null, at: '2026-11-26T08:00:00+05:30', tone: 'warning' }] } },
  { id: 'recycle_bin', kind: 'kpi', title: 'dashboard.cards.recycleBin', priority: 45, link: null, data: { count: 3 } },
];

function serveDashboard(byRole: Record<string, unknown[]>, roles = [{ name: 'chief', label: 'Chief Admin' }]) {
  fakeApi.handlers.push((request) => {
    const url = new URL(request.url);
    if (!url.pathname.endsWith('/me/dashboard')) return undefined;
    const role = url.searchParams.get('as') ?? roles[0]?.name ?? 'chief';
    return json({ data: { role, roles, cards: byRole[role] ?? [] } });
  });
}

const dashboardRequests = () => fakeApi.requests.filter((r) => new URL(r.url).pathname.endsWith('/me/dashboard'));

describe('dashboard', () => {
  beforeEach(async () => {
    act(() => usePreferences.setState({ language: 'en', theme: 'system' }));
    await i18n.changeLanguage('en');
  });

  it('renders every card the server sends, in its order, including kinds it does not know', async () => {
    serveDashboard({ chief: chiefCards });
    await renderApp();

    await screen.findByText('Licence and seats');
    const rendered = Array.from(document.querySelectorAll('[data-card-id]')).map((el) => el.getAttribute('data-card-id'));
    expect(rendered).toEqual(chiefCards.map((c) => c.id));

    // The top card is the large one.
    expect(screen.getByRole('heading', { level: 2, name: 'Licence and seats' })).toHaveClass('text-lg');
    // An unknown kind still shows, with its link.
    const future = document.querySelector('[data-card-id="future_widget"]') as HTMLElement;
    expect(within(future).getByRole('link', { name: /Open/ })).toHaveAttribute('href', '/attendance');
    // Cards link to their screens.
    const approvals = document.querySelector('[data-card-id="approvals"]') as HTMLElement;
    expect(within(approvals).getByText('Kiran Bhatt')).toBeInTheDocument();
    expect(within(approvals).getByRole('link', { name: /Review/ })).toHaveAttribute('href', '/staff/st1');
    expect(within(approvals).getByRole('link', { name: /Open/ })).toHaveAttribute('href', '/staff?status=draft');
    expect(screen.getByText('Parent / Guardian')).toBeInTheDocument();
  });

  it('shows the new-module banner linking to school settings', async () => {
    serveDashboard({ chief: chiefCards });
    await renderApp();

    const banner = await screen.findByText('New module available: Leave. Switch it on in Settings.');
    const card = banner.closest('[data-card-id]') as HTMLElement;
    expect(card).toHaveAttribute('data-card-id', 'new_module.leave');
    expect(within(card).getByRole('link', { name: 'Open settings' })).toHaveAttribute('href', '/settings/school');
  });

  it('refetches the cards when the window regains focus, as a background request', async () => {
    serveDashboard({ chief: chiefCards });
    await renderApp();
    await screen.findByText('Licence and seats');

    const first = dashboardRequests();
    expect(first).toHaveLength(1);
    expect(first[0]?.headers.get('X-Background-Request')).toBeNull();

    act(() => {
      focusManager.setFocused(false);
      focusManager.setFocused(true);
    });

    await waitFor(() => expect(dashboardRequests().length).toBeGreaterThanOrEqual(2));
    expect(dashboardRequests().at(-1)?.headers.get('X-Background-Request')).toBe('1');
    act(() => focusManager.setFocused(undefined));
  });

  it('switches role from the profile menu and refetches with ?as=parent', async () => {
    fakeApi.me = { ...demoUser, roles: ['class_teacher', 'parent'], scope: { ...demoUser.scope, children: ['c1', 'c2'] } };
    serveDashboard(
      {
        class_teacher: [{ id: 'my_class.sec1', kind: 'list', title: 'dashboard.cards.myClass', params: { section: '6-A' }, priority: 3, link: '/students?section_id=sec1', data: { strength: 41, items: [] } }],
        parent: [
          {
            id: 'children',
            kind: 'children',
            title: 'dashboard.cards.children',
            priority: 0,
            link: null,
            data: { items: [{ id: 'c1', label: 'Aarav Sharma', detail: '6-A', link: '/students/c1' }, { id: 'c2', label: 'Diya Sharma', detail: '3-B', link: '/students/c2' }] },
          },
        ],
      },
      [
        { name: 'class_teacher', label: 'Class Teacher' },
        { name: 'parent', label: 'Parent' },
      ],
    );
    const user = userEvent.setup();
    await renderApp();
    expect(await screen.findByText('My class 6-A')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /Open the account menu/ }));
    await user.click(screen.getByRole('radio', { name: 'Parent / Guardian' }));

    await waitFor(() => expect(dashboardRequests().some((r) => new URL(r.url).searchParams.get('as') === 'parent')).toBe(true));
    expect(await screen.findByText('My children')).toBeInTheDocument();
    // The child switcher sits at the top and remembers the pick.
    const switcher = screen.getByRole('radiogroup', { name: 'Choose a child' });
    await user.click(within(switcher).getByRole('radio', { name: /Diya Sharma/ }));
    expect(screen.getByText('Class 3-B')).toBeInTheDocument();
    expect(JSON.parse(localStorage.getItem('campus-tab:view') ?? '{}').state.roles.u_1).toBe('parent');
  });

  it('shows an empty state when the server sends no cards', async () => {
    serveDashboard({ chief: [] });
    await renderApp();

    expect(await screen.findByText('Nothing on your dashboard yet')).toBeInTheDocument();
  });
});
