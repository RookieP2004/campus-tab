import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { act } from 'react';
import { beforeEach, describe, expect, it } from 'vitest';
import i18n from '@/i18n';
import { usePreferences } from '@/stores/preferences';
import { device } from '@/test/setup';
import { renderApp } from '@/test/renderApp';

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

  it('renders the empty home screen', async () => {
    await renderApp();

    expect(screen.getByRole('heading', { level: 1, name: 'Welcome to Campus TAB' })).toBeInTheDocument();
  });

  it('defaults to Match device and follows a light device', async () => {
    await renderApp();

    expect(screen.getByRole('radio', { name: 'Match device' })).toHaveAttribute('aria-checked', 'true');
    expect(document.documentElement).toHaveAttribute('data-theme', 'light');
  });

  it('follows a dark device when set to Match device', async () => {
    device.prefersDark = true;
    await renderApp();

    expect(document.documentElement).toHaveAttribute('data-theme', 'dark');
  });

  it('switches between light and dark and remembers the choice', async () => {
    const user = userEvent.setup();
    await renderApp();

    await user.click(screen.getByRole('radio', { name: 'Dark' }));
    expect(document.documentElement).toHaveAttribute('data-theme', 'dark');
    expect(JSON.parse(localStorage.getItem('campus-tab:preferences') ?? '{}').state.theme).toBe('dark');

    await user.click(screen.getByRole('radio', { name: 'Light' }));
    expect(document.documentElement).toHaveAttribute('data-theme', 'light');
  });

  it('switches the language to Hindi and back', async () => {
    const user = userEvent.setup();
    await renderApp();

    await user.selectOptions(screen.getByLabelText('Language'), 'hi');

    expect(await screen.findByRole('heading', { level: 1, name: 'Campus TAB में आपका स्वागत है' })).toBeInTheDocument();
    expect(document.documentElement).toHaveAttribute('lang', 'hi');
    expect(document.documentElement).toHaveAttribute('dir', 'ltr');

    await user.selectOptions(screen.getByLabelText('भाषा'), 'en');
    expect(await screen.findByRole('heading', { level: 1, name: 'Welcome to Campus TAB' })).toBeInTheDocument();
  });

  it('shows the server as connected when the health check is ok', async () => {
    await renderApp();

    await waitFor(() => expect(screen.getByTestId('server-status')).toHaveAttribute('data-state', 'ok'));
  });
});
