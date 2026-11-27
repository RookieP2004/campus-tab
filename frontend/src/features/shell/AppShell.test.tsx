import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import { act } from 'react';
import { beforeEach, describe, expect, it } from 'vitest';
import i18n from '@/i18n';
import { usePreferences } from '@/stores/preferences';
import { demoUser, device, fakeApi, json } from '@/test/setup';
import { renderApp } from '@/test/renderApp';

const openProfileMenu = async (user: ReturnType<typeof userEvent.setup>) => {
  await user.click(screen.getByRole('button', { name: /Open the account menu|खाता मेनू खोलें/ }));
  return screen.getByRole('dialog', { name: /Account menu|खाता मेनू/ });
};

describe('AppShell', () => {
  beforeEach(async () => {
    act(() => usePreferences.setState({ language: 'en', theme: 'system' }));
    await i18n.changeLanguage('en');
  });

  it('shows the Campus TAB logo and name at the top of the brand stack', async () => {
    await renderApp();

    const stack = screen.getByTestId('brand-stack');
    const logo = within(stack).getByRole('img', { name: 'Campus TAB logo' });

    expect(logo).toHaveAttribute('src', '/logo-mark.svg');
    expect(logo).toHaveAttribute('width', '40');
    expect(stack).toHaveTextContent('Campus TAB');
    expect(await within(stack).findByText('Sunrise Public School')).toBeInTheDocument();
  });

  it('opens on the dashboard with a greeting, not a placeholder', async () => {
    await renderApp();

    expect(await screen.findByRole('heading', { level: 1, name: /Good (morning|afternoon|evening), Anita Verma/ })).toBeInTheDocument();
    expect(screen.queryByText('Welcome to Campus TAB')).not.toBeInTheDocument();
  });

  it('defaults to Match device and follows a light device', async () => {
    const user = userEvent.setup();
    await renderApp();

    const menu = await openProfileMenu(user);
    expect(within(menu).getByRole('radio', { name: 'Match device' })).toHaveAttribute('aria-checked', 'true');
    expect(document.documentElement).toHaveAttribute('data-theme', 'light');
  });

  it('follows a dark device when set to Match device', async () => {
    device.prefersDark = true;
    await renderApp();

    expect(document.documentElement).toHaveAttribute('data-theme', 'dark');
  });

  it('switches between light and dark from the profile menu and remembers the choice', async () => {
    const user = userEvent.setup();
    await renderApp();

    const menu = await openProfileMenu(user);
    await user.click(within(menu).getByRole('radio', { name: 'Dark' }));
    expect(document.documentElement).toHaveAttribute('data-theme', 'dark');
    expect(JSON.parse(localStorage.getItem('campus-tab:preferences') ?? '{}').state.theme).toBe('dark');

    await user.click(within(menu).getByRole('radio', { name: 'Light' }));
    expect(document.documentElement).toHaveAttribute('data-theme', 'light');
  });

  it('switches the language to Hindi and back', async () => {
    const user = userEvent.setup();
    await renderApp();

    await openProfileMenu(user);
    await user.selectOptions(screen.getByLabelText('Language'), 'hi');

    expect(await screen.findByRole('heading', { level: 1, name: /(सुप्रभात|नमस्ते|शुभ संध्या), Anita Verma/ })).toBeInTheDocument();
    expect(document.documentElement).toHaveAttribute('lang', 'hi');
    expect(document.documentElement).toHaveAttribute('dir', 'ltr');

    await user.selectOptions(screen.getByLabelText('भाषा'), 'en');
    expect(await screen.findByRole('heading', { level: 1, name: /Good (morning|afternoon|evening)/ })).toBeInTheDocument();
  });

  it('shows the server as connected when the health check is ok', async () => {
    await renderApp();

    await waitFor(() => expect(screen.getByTestId('server-status')).toHaveAttribute('data-state', 'ok'));
  });

  it('uses the sidebar on a desktop', async () => {
    device.width = 1280;
    const { container } = await renderApp();

    expect(container.querySelector('[data-layout="sidebar"]')).not.toBeNull();
    expect(container.querySelector('[data-layout="rail"]')).toBeNull();
    expect(container.querySelector('[data-layout="tabs"]')).toBeNull();
    expect(screen.getAllByRole('navigation', { name: 'Main navigation' })).toHaveLength(1);
  });

  it('uses an icon rail with named icons on a tablet', async () => {
    device.width = 900;
    const { container } = await renderApp();

    const rail = container.querySelector('[data-layout="rail"]') as HTMLElement;
    expect(rail).not.toBeNull();
    expect(within(rail).getByRole('link', { name: 'Students' })).toHaveAttribute('href', '/students');
    expect(container.querySelector('[data-layout="sidebar"]')).toBeNull();
  });

  it('uses bottom tabs on a phone: Home, the main job, Inbox, Notices and More', async () => {
    device.width = 375;
    fakeApi.me = { ...demoUser, permissions: [...demoUser.permissions, 'notices.view:school'] };
    const { container } = await renderApp();

    const tabs = container.querySelector('[data-layout="tabs"]') as HTMLElement;
    expect(tabs).not.toBeNull();
    expect(within(tabs).getAllByRole('link').map((a) => a.textContent)).toEqual(['Home', 'Students', 'Inbox', 'Notices', 'More']);
    expect(container.querySelector('[data-layout="sidebar"]')).toBeNull();
    expect(screen.getByTestId('brand-stack')).toHaveTextContent('Campus TAB');
  });

  it('opens search with Ctrl+K and finds people the server returns', async () => {
    const user = userEvent.setup();
    fakeApi.handlers.push((request) => {
      const url = new URL(request.url);
      if (url.pathname.endsWith('/me/search')) {
        return json({ data: [{ type: 'student', label: 'Aarav Sharma', detail: '6-A · ADM-001', link: '/students/s1' }] });
      }
      return undefined;
    });
    const { router } = await renderApp();

    await user.keyboard('{Control>}k{/Control}');
    const box = await screen.findByRole('combobox', { name: 'Search screens, students and staff' });
    expect(within(screen.getByRole('listbox')).getByRole('option', { name: /Students/ })).toBeInTheDocument();

    await user.type(box, 'Aar');
    expect(await screen.findByRole('option', { name: /Aarav Sharma/ })).toBeInTheDocument();
    await waitFor(() => expect(box).toHaveAttribute('aria-activedescendant', expect.stringContaining('-0')));
    await user.keyboard('{Enter}');

    await waitFor(() => expect(router.state.location.pathname).toBe('/students/s1'));
  });

  it('shows the unread count on the bell and polls as a background request when the socket is off', async () => {
    fakeApi.handlers.push((request) =>
      new URL(request.url).pathname.endsWith('/me/notifications')
        ? json({ data: [{ id: 'n1', type: 'notice', title: 'New notice: Sports day', body: null, link: '/notices/a1', data: {}, read: false, created_at: '2026-11-26T09:00:00+05:30' }], unread: 1 })
        : undefined,
    );
    const { queryClient } = await renderApp();

    expect(await screen.findByRole('button', { name: 'Notifications, 1 unread' })).toBeInTheDocument();
    const query = queryClient.getQueryCache().find({ queryKey: ['notifications'] });
    expect(query?.options).toMatchObject({ refetchInterval: 60_000 });

    await act(() => queryClient.refetchQueries({ queryKey: ['notifications'] }));
    const polls = fakeApi.requests.filter((r) => new URL(r.url).pathname.endsWith('/me/notifications'));
    expect(polls.length).toBeGreaterThanOrEqual(2);
    for (const poll of polls) expect(poll.headers.get('X-Background-Request')).toBe('1');
  });

  it('has no accessibility violations on the shell and dashboard, in light and dark', async () => {
    fakeApi.handlers.push((request) =>
      new URL(request.url).pathname.endsWith('/me/dashboard')
        ? json({
            data: {
              role: 'chief',
              roles: [{ name: 'chief', label: 'Chief Admin' }],
              cards: [
                { id: 'licence', kind: 'licence', title: 'dashboard.cards.licence', priority: 0, link: null, data: { plan: 'standard', state: 'active', valid_to: '2027-03-31', students: { limit: 500, used: 120, available: 380, warning: false }, staff: { limit: 50, used: 12, available: 38, warning: false } } },
                { id: 'new_module.leave', kind: 'banner', title: 'dashboard.cards.newModule', params: { module: 'leave' }, priority: 1, link: '/settings/school', data: { module: 'leave' } },
                { id: 'on_roll', kind: 'kpi', title: 'dashboard.cards.onRoll', priority: 10, link: '/students', data: { students: 120, staff: 12 } },
              ],
            },
          })
        : undefined,
    );
    const user = userEvent.setup();
    // index.html gives the page its title; the test document has none.
    document.title = 'Campus TAB';
    await renderApp();
    await screen.findByText('Licence and seats');

    for (const theme of ['light', 'dark'] as const) {
      const menu = await openProfileMenu(user);
      await user.click(within(menu).getByRole('radio', { name: theme === 'light' ? 'Light' : 'Dark' }));
      await user.keyboard('{Escape}');
      expect(document.documentElement).toHaveAttribute('data-theme', theme);

      const results = await axe.run(document, { resultTypes: ['violations'] });
      expect(results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
    }
  });
});
