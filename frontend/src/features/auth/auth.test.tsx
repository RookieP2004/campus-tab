import { QueryClient, useQueryClient } from '@tanstack/react-query';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { beforeEach, describe, expect, it } from 'vitest';
import { Providers } from '@/app/Providers';
import { i18nReady } from '@/i18n';
import { useActivity } from '@/stores/activity';
import { useSchoolCode } from '@/stores/school';
import { useSessionState } from '@/stores/session';
import { demoUser, fakeApi, json } from '@/test/setup';
import { renderApp } from '@/test/renderApp';
import { meKey } from './api';
import { RequireSession } from './RequireSession';

const path = (r: Request) => new URL(r.url).pathname;

beforeEach(() => {
  useSchoolCode.setState({ code: null });
  useActivity.setState({ lastRealRequestAt: Date.now() });
});

describe('signing in', () => {
  it('goes from the school code to password and MFA, and never stores a token in the browser', async () => {
    fakeApi.me = null;
    fakeApi.handlers.push((r) => {
      if (path(r).endsWith('/auth/login')) return json({ data: { mfa_required: true, challenge: 'c'.repeat(48), expires_in: 300 } });
      if (path(r).endsWith('/auth/mfa/verify')) {
        fakeApi.me = demoUser;
        return json({ data: { school: demoUser.school, user: { id: 'u_1', name: demoUser.name, roles: ['chief'] }, mode: 'browser', device_session_id: 'd1', mfa_enrolment_required: false } });
      }
      return undefined;
    });
    const user = userEvent.setup();
    await renderApp('/school');

    await user.type(await screen.findByLabelText('School code'), 'sunrise-bpl');
    await user.click(screen.getByRole('button', { name: 'Continue' }));

    expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
    expect(await screen.findByText('Sunrise Public School')).toBeInTheDocument();

    await user.type(screen.getByLabelText('Email, phone or login name'), 'chief@sunrise.test');
    await user.type(screen.getByLabelText('Password'), 'correct-horse-42-battery');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    await user.type(await screen.findByLabelText('6-digit code'), '123456');
    await user.click(screen.getByRole('button', { name: 'Verify' }));

    expect(await screen.findByTestId('signed-in-name')).toHaveTextContent('Anita Verma');

    // Session cookie only: nothing but display preferences and the public school code is stored.
    expect(sessionStorage.length).toBe(0);
    expect(Object.keys(localStorage).sort()).toEqual(expect.arrayContaining(['campus-tab:school']));
    for (const key of Object.keys(localStorage)) {
      expect(['campus-tab:preferences', 'campus-tab:school']).toContain(key);
      expect(localStorage.getItem(key)).not.toMatch(/token|password|challenge|cccccccc/i);
    }

    // Changes carry the CSRF header from the cookie, and credentials are sent.
    const login = fakeApi.requests.find((r) => path(r).endsWith('/auth/login'));
    expect(login?.credentials).toBe('include');
  });

  it('lets a lost phone be replaced by a recovery code', async () => {
    fakeApi.me = null;
    useSchoolCode.setState({ code: 'SUNRISE-BPL' });
    fakeApi.handlers.push((r) => (path(r).endsWith('/auth/login') ? json({ data: { mfa_required: true, challenge: 'x'.repeat(48), expires_in: 300 } }) : undefined));
    const user = userEvent.setup();
    await renderApp('/sign-in');

    await user.type(await screen.findByLabelText('Email, phone or login name'), 'chief@sunrise.test');
    await user.type(screen.getByLabelText('Password'), 'whatever-password');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
    await user.click(await screen.findByRole('button', { name: 'Lost your phone? Use a recovery code' }));

    expect(screen.getByLabelText('Recovery code')).toBeInTheDocument();
  });
});

describe('MFA enrolment', () => {
  it('shows the QR code and the key, then the recovery codes once, with download and print', async () => {
    useSchoolCode.setState({ code: 'SUNRISE-BPL' });
    fakeApi.handlers.push((r) => {
      if (path(r).endsWith('/me/mfa/enrol')) return json({ data: { secret: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', otpauth_url: 'otpauth://totp/Campus%20TAB:chief?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP' } });
      if (path(r).endsWith('/me/mfa/enrol/confirm')) return json({ data: { recovery_codes: ['AAAAA-BBBBB', 'CCCCC-DDDDD'] } });
      return undefined;
    });
    const user = userEvent.setup();
    await renderApp('/mfa/enrol');

    expect(await screen.findByTitle('QR code for your authenticator app')).toBeInTheDocument();
    expect(screen.getByTestId('mfa-secret')).toHaveTextContent('JBSW Y3DP');

    await user.type(screen.getByLabelText('6-digit code'), '123456');
    await user.click(screen.getByRole('button', { name: 'Turn on two-step sign-in' }));

    expect(await screen.findByTestId('recovery-codes')).toHaveTextContent('AAAAA-BBBBB');
    expect(screen.getByRole('button', { name: 'Download' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Print' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
  });
});

describe('idle sign-out', () => {
  it('marks refetches of data already on screen as background requests', async () => {
    const { queryClient } = await renderApp('/');
    const first = fakeApi.requests.find((r) => path(r).endsWith('/me'));
    expect(first?.headers.get('X-Background-Request')).toBeNull();

    fakeApi.requests = [];
    await act(() => queryClient.invalidateQueries({ queryKey: meKey }));

    const refetch = fakeApi.requests.find((r) => path(r).endsWith('/me'));
    expect(refetch?.headers.get('X-Background-Request')).toBe('1');
  });

  it('polls the server status as a background request', async () => {
    await renderApp('/');
    await waitFor(() => expect(fakeApi.requests.some((r) => path(r).endsWith('/health'))).toBe(true));
    expect(fakeApi.requests.find((r) => path(r).endsWith('/health'))?.headers.get('X-Background-Request')).toBe('1');
  });

  it('warns a minute before the idle sign-out', async () => {
    await renderApp('/');
    act(() => useActivity.setState({ lastRealRequestAt: Date.now() - (15 * 60 - 45) * 1000 }));

    expect(await screen.findByRole('heading', { name: 'Still there?' }, { timeout: 2000 })).toBeInTheDocument();
  });

  it('shows sign-in over the page when the session ends, and keeps what was typed', async () => {
    await i18nReady;
    useSchoolCode.setState({ code: 'SUNRISE-BPL' });
    useSessionState.setState({ ended: false, reason: null });

    function Draft() {
      const [text, setText] = useState('');
      const client = useQueryClient();
      return (
        <div>
          <label>
            Remark
            <input value={text} onChange={(e) => setText(e.target.value)} />
          </label>
          <button type="button" onClick={() => void client.invalidateQueries({ queryKey: meKey })}>
            Save
          </button>
        </div>
      );
    }

    const router = createMemoryRouter([{ path: '/', element: <RequireSession><Draft /></RequireSession> }], { initialEntries: ['/'] });
    render(
      <Providers queryClient={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <RouterProvider router={router} />
      </Providers>,
    );

    const user = userEvent.setup();
    await user.type(await screen.findByLabelText('Remark'), 'Half-written remark');

    // The server now says the session has ended.
    fakeApi.me = null;
    fakeApi.handlers.push((r) => (path(r).endsWith('/me') ? json({ error: { code: 'session_expired', message: 'x', details: {} } }, 401) : undefined));
    await user.click(screen.getByRole('button', { name: 'Save' }));

    expect(await screen.findByRole('heading', { name: 'Sign in again' })).toBeInTheDocument();
    expect(screen.getByLabelText('Remark', { selector: 'input' })).toHaveValue('Half-written remark');
  });
});
