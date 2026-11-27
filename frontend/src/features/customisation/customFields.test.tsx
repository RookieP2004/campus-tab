import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { act } from 'react';
import { beforeEach, describe, expect, it } from 'vitest';
import i18n from '@/i18n';
import { usePreferences } from '@/stores/preferences';
import { renderApp } from '@/test/renderApp';
import { demoUser, fakeApi, json } from '@/test/setup';

function on(method: string, path: string, answer: () => Response) {
  fakeApi.handlers.push((request) => (request.method === method && new URL(request.url).pathname === `/api/v1${path}` ? answer() : undefined));
}

describe('custom-field manager', () => {
  beforeEach(async () => {
    act(() => usePreferences.setState({ language: 'en', theme: 'system' }));
    await i18n.changeLanguage('en');
  });

  it('offers a class teacher only their own section, never the whole school', async () => {
    const user = userEvent.setup();
    fakeApi.me = {
      ...demoUser,
      roles: ['class_teacher'],
      level: 50,
      permissions: ['students.view:class', 'custom_fields.manage:class'],
      scope: { children: [], class_sections: ['s6a'], subject_sections: [], wings: [] },
    };
    on('GET', '/custom-fields', () => json({ data: [] }));
    on('GET', '/subjects', () => json({ data: [] }));
    on('GET', '/classes', () =>
      json({
        data: [
          {
            id: 'c6',
            year_id: 'y1',
            grade: '6',
            name: 'Class 6',
            order: 6,
            wing: null,
            sections: [
              { id: 's6a', class_id: 'c6', name: 'A', label: '6-A', wing: null, room: null, class_teacher_id: 'st9', class_teacher_name: 'Anil' },
              { id: 's6b', class_id: 'c6', name: 'B', label: '6-B', wing: null, room: null, class_teacher_id: null, class_teacher_name: null },
            ],
          },
        ],
      }),
    );

    await renderApp('/settings/custom-fields');
    await user.click(await screen.findByRole('button', { name: 'New field' }));
    const dialog = await screen.findByRole('dialog');

    const scope = within(dialog).getByLabelText('Where it applies');
    await within(scope).findByRole('option', { name: 'Class 6-A' });
    expect(within(scope).getAllByRole('option').map((o) => o.textContent)).toEqual(['Class 6-A']);
    expect(within(scope).queryByRole('option', { name: 'Whole school' })).not.toBeInTheDocument();

    // A section column belongs to the enrolment.
    const entity = within(dialog).getByLabelText('Record');
    expect(entity).toBeDisabled();
    expect(entity).toHaveValue('enrolment');
  });
});
