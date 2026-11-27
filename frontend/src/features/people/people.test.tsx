import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { act } from 'react';
import { beforeEach, describe, expect, it } from 'vitest';
import i18n from '@/i18n';
import { usePreferences } from '@/stores/preferences';
import { renderApp } from '@/test/renderApp';
import { demoUser, fakeApi, json } from '@/test/setup';

/** Answers one method and API path (after /api/v1). */
function on(method: string, path: string, answer: (request: Request) => Response) {
  fakeApi.handlers.push((request) => {
    const url = new URL(request.url);
    return request.method === method && url.pathname === `/api/v1${path}` ? answer(request) : undefined;
  });
}

const classes = [
  {
    id: 'c6',
    year_id: 'y1',
    grade: '6',
    name: 'Class 6',
    order: 6,
    wing: null,
    sections: [
      { id: 's6a', class_id: 'c6', name: 'A', label: '6-A', wing: null, room: null, class_teacher_id: null, class_teacher_name: null },
      { id: 's6b', class_id: 'c6', name: 'B', label: '6-B', wing: null, room: null, class_teacher_id: null, class_teacher_name: null },
    ],
  },
];

const column = (key: string, en: string, hi?: string) => ({
  key,
  label: hi ? { en, hi } : { en },
  type: 'text',
  options: null,
  entity: 'student',
  scope: { level: 'school', ref: null },
  owner_level: 90,
  sensitive: false,
  can_fill: true,
});

describe('people screens', () => {
  beforeEach(async () => {
    act(() => usePreferences.setState({ language: 'en', theme: 'system' }));
    await i18n.changeLanguage('en');
    on('GET', '/classes', () => json({ data: classes }));
  });

  it('shows the custom columns from the list, and the column chooser hides one and remembers it', async () => {
    const user = userEvent.setup();
    on('GET', '/students', () =>
      json({
        data: [
          {
            id: 'st1',
            admission_no: 'A-101',
            name: 'Kavya Rao',
            dob: '2014-05-02',
            gender: 'female',
            status: 'active',
            section_id: 's6a',
            section_label: '6-A',
            roll_no: 4,
            custom: { house: 'Blue', bus_route: 'Route 7' },
          },
        ],
        columns: [column('house', 'House', 'सदन'), column('bus_route', 'Bus route')],
        meta: { total: 1, page: 1, per_page: 50 },
      }),
    );

    const first = await renderApp('/students');
    const table = await screen.findByTestId('student-table');
    expect(within(table).getByRole('columnheader', { name: 'House' })).toBeInTheDocument();
    expect(within(table).getByRole('columnheader', { name: 'Bus route' })).toBeInTheDocument();
    expect(within(table).getByText('Route 7')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Columns' }));
    await user.click(screen.getByRole('checkbox', { name: 'House' }));

    expect(within(table).queryByRole('columnheader', { name: 'House' })).not.toBeInTheDocument();
    expect(JSON.parse(localStorage.getItem(`campus-tab:student-columns:${demoUser.id}`) ?? '[]')).toEqual(['custom:house']);

    first.unmount();
    await renderApp('/students');
    const again = await screen.findByTestId('student-table');
    expect(within(again).getByRole('columnheader', { name: 'Bus route' })).toBeInTheDocument();
    expect(within(again).queryByRole('columnheader', { name: 'House' })).not.toBeInTheDocument();
  });

  it('shows the seat-limit message from the server when admitting is refused', async () => {
    const user = userEvent.setup();
    fakeApi.me = { ...demoUser, permissions: ['students.view:school', 'students.admit:school'] };
    on('GET', '/students/seats', () => json({ data: { limit: 100, used: 100, available: 0, warning: true } }));
    on('POST', '/students', () =>
      json(
        {
          error: {
            code: 'seat_limit_reached',
            message: 'The licence allows 100 students and 100 are on roll. Ask the Chief Admin to add seats.',
            details: { limit: 100, used: 100, available: 0, adding: 1 },
          },
        },
        422,
      ),
    );

    await renderApp('/students/new');
    await screen.findByRole('heading', { name: 'Admit a student' });
    await screen.findByRole('option', { name: '6-A' });

    await user.type(screen.getByLabelText('Admission number'), 'A-200');
    await user.type(screen.getByLabelText('Full name'), 'Arjun Mehta');
    fireEvent.change(screen.getByLabelText('Date of birth'), { target: { value: '2015-04-01' } });
    await user.selectOptions(screen.getByLabelText('Class and section'), 's6a');
    await user.type(screen.getByLabelText('Guardian name'), 'Ritu Mehta');
    await user.click(screen.getByRole('button', { name: 'Admit student' }));

    expect(await screen.findByText('The licence allows 100 students and 100 are on roll. Ask the Chief Admin to add seats.')).toBeInTheDocument();
    expect(screen.getByText('No student seats left')).toBeInTheDocument();
    const sent = fakeApi.requests.find((r) => r.method === 'POST' && new URL(r.url).pathname === '/api/v1/students');
    expect(sent).toBeDefined();
  });

  it('offers "Draft for the Principal" when the user may only draft staff', async () => {
    const user = userEvent.setup();
    fakeApi.me = { ...demoUser, roles: ['clerk'], level: 60, permissions: ['staff.view:school', 'staff.draft:school'] };
    on('GET', '/staff', () => json({ data: [], can: { create: false, draft: true, approve: false } }));

    await renderApp('/staff');
    await user.click(await screen.findByRole('button', { name: 'Draft for the Principal' }));

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText('This sends a draft to the Principal. The account is created only when the Principal approves it.')).toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: 'Send to the Principal' })).toBeInTheDocument();
  });

  it('puts the seat check first on an import and keeps Import disabled when the file cannot be imported', async () => {
    fakeApi.me = { ...demoUser, permissions: ['students.view:school', 'imports.students:school'] };
    const seatMessage = 'The licence has 1 seat left, but this file adds 3 students. Remove 2 rows or ask the Chief Admin to add seats.';
    on('GET', '/imports/b1', () =>
      json({
        data: {
          id: 'b1',
          entity: 'students',
          status: 'validated',
          file_name: 'students.xlsx',
          progress: { done: 4, total: 4 },
          summary: {
            rows: 4,
            ok: 3,
            errors: 1,
            duplicates: 0,
            seats: { limit: 100, used: 99, available: 1, adding: 3, over: 2 },
            can_commit: false,
            messages: [seatMessage, '1 rows have errors and will be skipped. Fix them and upload again to include them.'],
          },
          rows: [
            { row: 2, status: 'ok', label: 'Kavya Rao', messages: [] },
            { row: 3, status: 'error', label: 'Dev Shah', messages: ['dob must be a date, YYYY-MM-DD.'] },
          ],
          result: null,
          error: null,
        },
      }),
    );

    await renderApp('/imports/students?batch=b1');
    const seatCheck = await screen.findByTestId('seat-check');
    expect(seatCheck).toHaveTextContent(seatMessage);

    // The seat check comes before the counts and the row messages.
    const counts = screen.getByText('Ready', { selector: 'dt' });
    expect(seatCheck.compareDocumentPosition(counts) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.getByText('dob must be a date, YYYY-MM-DD.')).toBeInTheDocument();

    await waitFor(() => expect(screen.getByRole('button', { name: 'Import 3 records' })).toBeDisabled());
  });
});
