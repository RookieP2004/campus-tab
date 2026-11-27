import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { act } from 'react';
import { beforeEach, describe, expect, it } from 'vitest';
import i18n from '@/i18n';
import { usePreferences } from '@/stores/preferences';
import { demoUser, fakeApi, json } from '@/test/setup';
import { renderApp } from '@/test/renderApp';

const classes = [
  {
    id: 'cl6',
    year_id: 'y1',
    grade: '6',
    name: 'Class 6',
    order: 6,
    wing: 'middle',
    sections: [
      { id: 'sec1', class_id: 'cl6', name: 'A', label: '6-A', wing: 'middle', room: null, class_teacher_id: 'st1', class_teacher_name: 'Anita Verma' },
      { id: 'sec2', class_id: 'cl6', name: 'B', label: '6-B', wing: 'middle', room: null, class_teacher_id: null, class_teacher_name: null },
    ],
  },
];

const classTeacher = {
  ...demoUser,
  roles: ['class_teacher'],
  permissions: ['notices.send:class', 'notices.view:own', 'academic.view:school', 'students.view:class'],
  scope: { ...demoUser.scope, class_sections: ['sec1'] },
};

describe('notices', () => {
  beforeEach(async () => {
    act(() => usePreferences.setState({ language: 'en', theme: 'system' }));
    await i18n.changeLanguage('en');
    fakeApi.handlers.push((request) => (new URL(request.url).pathname.endsWith('/classes') ? json({ data: classes }) : undefined));
  });

  it('offers a class teacher only their own sections, never the whole school or roles', async () => {
    fakeApi.me = classTeacher;
    await renderApp('/notices/new');

    expect(await screen.findByRole('heading', { level: 1, name: 'Send a notice' })).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: 'Sections' })).toBeChecked();
    expect(screen.queryByRole('radio', { name: 'Whole school' })).not.toBeInTheDocument();
    expect(screen.queryByRole('radio', { name: 'Roles' })).not.toBeInTheDocument();
    expect(screen.queryByRole('radio', { name: 'Classes' })).not.toBeInTheDocument();
    expect(await screen.findByRole('checkbox', { name: '6-A' })).toBeInTheDocument();
    expect(screen.queryByRole('checkbox', { name: '6-B' })).not.toBeInTheDocument();
  });

  it('offers the whole school and roles to someone who sends school-wide', async () => {
    fakeApi.me = { ...demoUser, permissions: ['notices.send:school', 'notices.view:school', 'academic.view:school'] };
    await renderApp('/notices/new');

    expect(await screen.findByRole('radio', { name: 'Whole school' })).toBeChecked();
    expect(screen.getByRole('radio', { name: 'Roles' })).toBeInTheDocument();
  });

  it('refuses an attachment of the wrong type or size before sending, and sends a multipart notice', async () => {
    fakeApi.me = classTeacher;
    let sent: FormData | null = null;
    fakeApi.handlers.push(async (request) => {
      const url = new URL(request.url);
      if (url.pathname.endsWith('/notices') && request.method === 'POST') {
        sent = await request.formData();
        return json({ data: { id: 'a1', title: 'Picnic', body: 'Bring lunch.', audience: { type: 'section', refs: ['sec1'] }, sent_by: 'Anita Verma', sent_by_id: 'u_1', published_at: '2026-11-26T09:00:00+05:30', attachments: [], recipient_count: 40 } }, 201);
      }
      return undefined;
    });
    const user = userEvent.setup({ applyAccept: false });
    const { router } = await renderApp('/notices/new');

    await user.type(await screen.findByLabelText('Title'), 'Picnic');
    await user.type(screen.getByLabelText('Message'), 'Bring lunch.');
    await user.click(await screen.findByRole('checkbox', { name: '6-A' }));

    const input = screen.getByLabelText('Attachments (optional)');
    await user.upload(input, new File(['x'], 'script.exe', { type: 'application/octet-stream' }));
    expect(screen.getByRole('alert')).toHaveTextContent('script.exe cannot be attached');
    await user.upload(input, new File([new Uint8Array(6 * 1024 * 1024)], 'big.pdf', { type: 'application/pdf' }));
    expect(screen.getByRole('alert')).toHaveTextContent('big.pdf is larger than 5 MB.');
    await user.upload(input, new File(['%PDF'], 'route.pdf', { type: 'application/pdf' }));
    expect(screen.getByText('route.pdf')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Send notice' }));

    await waitFor(() => expect(router.state.location.pathname).toBe('/notices/a1'));
    const form = sent as unknown as FormData;
    expect(form.get('audience[type]')).toBe('section');
    expect(form.getAll('audience[refs][]')).toEqual(['sec1']);
    expect(form.getAll('attachments[]')).toHaveLength(1);
  });

  it('shows the server refusal for an audience the sender may not reach', async () => {
    fakeApi.me = classTeacher;
    fakeApi.handlers.push((request) =>
      new URL(request.url).pathname.endsWith('/notices') && request.method === 'POST'
        ? json({ error: { code: 'notice_audience_forbidden', message: 'You can send notices only to your own classes or sections.', details: {} } }, 403)
        : undefined,
    );
    const user = userEvent.setup();
    await renderApp('/notices/new');

    await user.type(await screen.findByLabelText('Title'), 'Picnic');
    await user.type(screen.getByLabelText('Message'), 'Bring lunch.');
    await user.click(await screen.findByRole('checkbox', { name: '6-A' }));
    await user.click(screen.getByRole('button', { name: 'Send notice' }));

    expect(await screen.findByText('You can send notices only to your own classes or sections.')).toBeInTheDocument();
  });

  it('lists notices with an unread marker', async () => {
    fakeApi.me = classTeacher;
    fakeApi.handlers.push((request) =>
      new URL(request.url).pathname.endsWith('/notices') && request.method === 'GET'
        ? json({
            data: [
              { id: 'a1', title: 'Sports day', body: 'Friday.', audience: { type: 'school', refs: [] }, sent_by: 'Dr. Meera Iyer', sent_by_id: 'u_9', published_at: '2026-11-26T09:00:00+05:30', attachments: [{ id: 'f1', name: 'plan.pdf', mime: 'application/pdf', size: 1000 }], recipient_count: 900, read: false },
              { id: 'a2', title: 'Holiday', body: 'Monday.', audience: { type: 'school', refs: [] }, sent_by: 'Dr. Meera Iyer', sent_by_id: 'u_9', published_at: '2026-11-20T09:00:00+05:30', attachments: [], recipient_count: 900, read: true },
            ],
          })
        : undefined,
    );
    await renderApp('/notices');

    expect(await screen.findByRole('link', { name: /Sports day\s*\(unread\)/ })).toHaveAttribute('href', '/notices/a1');
    expect(screen.getByRole('link', { name: /^Holiday/ })).not.toHaveTextContent('unread');
    expect(screen.getByText('1 attachment')).toBeInTheDocument();
  });
});
