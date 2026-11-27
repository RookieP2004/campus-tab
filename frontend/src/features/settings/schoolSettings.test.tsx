import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { act } from 'react';
import { beforeEach, describe, expect, it } from 'vitest';
import i18n from '@/i18n';
import { usePreferences } from '@/stores/preferences';
import { demoUser, fakeApi, json } from '@/test/setup';
import { renderApp } from '@/test/renderApp';

const config = {
  version: 7,
  body: {
    school: { name: 'Sunrise Public School', short_name: 'SPS', logo_url: null, board: 'CBSE', affiliation_no: null },
    branding: { accent: '#0F766E' },
    modules: { attendance: true, leave: false },
    sign_in: { methods: ['password', 'parent_otp'] },
    policies: {},
  },
};

describe('school settings', () => {
  let saved: { body: unknown; ifMatch: string | null } | null;

  beforeEach(async () => {
    act(() => usePreferences.setState({ language: 'en', theme: 'system' }));
    await i18n.changeLanguage('en');
    saved = null;
    fakeApi.handlers.push(async (request) => {
      if (!new URL(request.url).pathname.endsWith('/config')) return undefined;
      if (request.method === 'PUT') {
        saved = { body: await request.json(), ifMatch: request.headers.get('If-Match') };
        return json({ error: { code: 'module_not_licensed', message: 'These modules are not part of the licence: leave.', details: { modules: ['leave'] } } }, 422);
      }
      return json({ data: config });
    });
  });

  it('is read-only for a Principal without config.edit', async () => {
    fakeApi.me = { ...demoUser, roles: ['principal'], permissions: ['config.view:school', 'config.edit_policies:school'] };
    await renderApp('/settings/school');

    expect(await screen.findByText('Only the Chief Admin can change these settings. You can see them here.')).toBeInTheDocument();
    expect(screen.getByLabelText('School name')).toBeDisabled();
    expect(screen.getByRole('switch', { name: 'Leave' })).toBeDisabled();
    expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
  });

  it('asks the Chief to type the module name before switching it off, saves with If-Match and shows the licence error', async () => {
    fakeApi.me = { ...demoUser, permissions: ['config.view:school', 'config.edit:school'] };
    const user = userEvent.setup();
    await renderApp('/settings/school');

    await user.click(await screen.findByRole('switch', { name: 'Attendance' }));
    const confirm = screen.getByRole('button', { name: 'Switch off' });
    expect(confirm).toBeDisabled();
    await user.type(screen.getByLabelText('Type Attendance to confirm'), 'Attendance');
    await user.click(confirm);
    expect(screen.getByRole('switch', { name: 'Attendance' })).not.toBeChecked();

    await user.click(screen.getByRole('switch', { name: 'Leave' }));
    await user.click(screen.getByRole('switch', { name: /Students sign in with their own account/ }));
    await user.click(screen.getByRole('button', { name: 'Save' }));

    expect(await screen.findByText("One of these modules is not part of the school's licence, so it cannot be switched on.")).toBeInTheDocument();
    await waitFor(() => expect(saved).not.toBeNull());
    expect(saved?.ifMatch).toBe('"v7"');
    expect(saved?.body).toMatchObject({ body: { modules: { attendance: false, leave: true }, sign_in: { methods: ['password', 'parent_otp', 'student_login'] } } });
  });
});
