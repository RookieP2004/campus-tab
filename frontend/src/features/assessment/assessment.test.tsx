import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { act } from 'react';
import { beforeEach, describe, expect, it } from 'vitest';
import i18n from '@/i18n';
import { usePreferences } from '@/stores/preferences';
import { demoUser, fakeApi, json } from '@/test/setup';
import { renderApp } from '@/test/renderApp';
import type { Correction, PaperDetail } from './api';

const teacher = { ...demoUser, roles: ['subject_teacher'], permissions: ['marks.enter:subject', 'tests.create:subject', 'marks.correction_request:subject'] };
const principal = { ...demoUser, roles: ['principal'], permissions: ['marks.correction_approve:school', 'marks.correction_request:school', 'marks.lock:school', 'leave.decide:school'] };

const can = { enter: true, open: false, submit: true, verify: false, send_back: false, lock: false, publish: false, correct: false };

function paper(overrides: Partial<PaperDetail> = {}): PaperDetail {
  return {
    id: 'p1',
    assessment_id: 'a1',
    section_id: 's1',
    section: '8-A',
    subject_id: 'sub1',
    subject: 'Science',
    max_marks: 40,
    date: '2026-11-24',
    status: 'open',
    published: false,
    class_test: false,
    entered: 1,
    students: 2,
    can,
    version: 3,
    assessment: { id: 'a1', kind: 'exam', type: 'periodic', term: 'Term 2', name: 'Unit Test 2', component: null, from: '2026-11-24', to: '2026-11-28', entry_closes_at: null, created_by: 'Ramesh Kumar' },
    rows: [
      { student_id: 'st1', name: 'Aditya Kulkarni', roll_no: 1, admission_no: 'A1', value: 34, code: null, remark: null },
      { student_id: 'st2', name: 'Ananya Bose', roll_no: 2, admission_no: 'A2', value: null, code: null, remark: null },
    ],
    returned_note: null,
    locked: null,
    published_at: null,
    pending_corrections: [],
    history: [],
    ...overrides,
  };
}

describe('tests and marks', () => {
  beforeEach(async () => {
    act(() => usePreferences.setState({ language: 'en', theme: 'system' }));
    await i18n.changeLanguage('en');
  });

  it('refuses a mark above the maximum before sending, and blocks submit until it is fixed', async () => {
    fakeApi.me = teacher;
    let saves = 0;
    fakeApi.handlers.push((req) => {
      const path = new URL(req.url).pathname;
      if (path.endsWith('/papers/p1/marks')) {
        saves++;
        return json({ data: paper() });
      }
      if (path.endsWith('/papers/p1')) return json({ data: paper() });
      return undefined;
    });
    const user = userEvent.setup();
    await renderApp('/tests/papers/p1');

    const cells = await screen.findAllByLabelText('Marks for Ananya Bose');
    await user.type(cells[0] as HTMLElement, '43');
    expect((await screen.findAllByText('More than 40')).length).toBeGreaterThan(0);
    expect(screen.getByRole('button', { name: 'Fix 1 mark to submit' })).toBeDisabled();
    await new Promise((r) => setTimeout(r, 1500));
    expect(saves).toBe(0);
  });

  it('saves with If-Match and, on a conflict, shows the other person\'s marks and keeps mine to save again', async () => {
    fakeApi.me = teacher;
    let ifMatch: string | null = null;
    let conflict = true;
    fakeApi.handlers.push((req) => {
      const path = new URL(req.url).pathname;
      if (path.endsWith('/papers/p1/marks')) {
        ifMatch = req.headers.get('If-Match');
        if (conflict) {
          conflict = false;
          return json({ error: { code: 'paper_conflict', message: 'Someone else saved.', details: { version: 4 } } }, 412);
        }
        return json({ data: paper({ version: 5, rows: paper().rows.map((r) => (r.student_id === 'st2' ? { ...r, value: 30 } : r)) }) });
      }
      if (path.endsWith('/papers/p1')) return json({ data: paper({ version: conflict ? 3 : 4 }) });
      return undefined;
    });
    const user = userEvent.setup();
    await renderApp('/tests/papers/p1');

    const cells = await screen.findAllByLabelText('Marks for Ananya Bose');
    await user.type(cells[0] as HTMLElement, '30');
    await user.click(screen.getByRole('button', { name: 'Save now' }));

    expect(await screen.findByText(/Someone else saved marks on this paper/)).toBeInTheDocument();
    expect(ifMatch).toBe('"v3"');
    // My typed value is still there.
    expect((screen.getAllByLabelText('Marks for Ananya Bose')[0] as HTMLInputElement).value).toBe('30');

    await user.click(screen.getByRole('button', { name: 'Save my changes' }));
    await waitFor(() => expect(ifMatch).toBe('"v4"'));
    expect(await screen.findByText(/Saved automatically/)).toBeInTheDocument();
  });

  it('shows a locked paper read-only, with only a correction request', async () => {
    fakeApi.me = teacher;
    let raised: Record<string, unknown> | null = null;
    fakeApi.handlers.push(async (req) => {
      const path = new URL(req.url).pathname;
      if (path.endsWith('/corrections') && req.method === 'POST') {
        raised = (await req.json()) as Record<string, unknown>;
        return json({ data: { id: 'c1' } }, 201);
      }
      if (path.endsWith('/papers/p1')) {
        return json({ data: paper({ status: 'locked', locked: { by: 'Ramesh Kumar', at: '2026-11-29T10:00:00+05:30' }, can: { ...can, enter: false, submit: false, correct: true } }) });
      }
      return undefined;
    });
    const user = userEvent.setup();
    await renderApp('/tests/papers/p1');

    expect(await screen.findByText(/Locked by Ramesh Kumar/)).toBeInTheDocument();
    expect(screen.getAllByLabelText('Marks for Aditya Kulkarni')[0]).toBeDisabled();
    expect(screen.queryByRole('button', { name: /Submit/ })).not.toBeInTheDocument();

    await user.click(screen.getAllByRole('button', { name: /Ask to correct.*Aditya Kulkarni/ })[0] as HTMLElement);
    const dialog = await screen.findByRole('dialog');
    await user.type(within(dialog).getByLabelText(/New mark/), '36');
    await user.type(within(dialog).getByLabelText('Reason'), 'Short');
    expect(within(dialog).getByRole('button', { name: 'Send to the Principal' })).toBeDisabled();
    await user.type(within(dialog).getByLabelText('Reason'), ' total on page 3');
    await user.click(within(dialog).getByRole('button', { name: 'Send to the Principal' }));
    await waitFor(() => expect(raised).toMatchObject({ paper_id: 'p1', student_id: 'st1', value: 36, code: null }));
  });

  it('asks the Principal for a fresh code and a typed reason before approving a correction', async () => {
    fakeApi.me = principal;
    const correction: Correction = {
      id: 'c1', paper_id: 'p1', assessment_id: 'a1', student_id: 'st1', student: 'Aditya Kulkarni', label: 'Science 8-A', old: { value: 32, code: null }, new: { value: 36, code: null },
      reason: 'Page 3 was not totalled', requested_by: 'Rakesh Sharma', requested_by_role: 'subject_teacher', requested_at: '2026-11-29T10:00:00+05:30', status: 'pending',
      decided_by: null, decision_reason: null, decided_at: null, self_approved: false, own: false, can: { decide: true },
    };
    let steppedUp = false;
    let approvedWith: string | null = null;
    fakeApi.handlers.push(async (req) => {
      const path = new URL(req.url).pathname;
      if (path.endsWith('/auth/step-up')) {
        steppedUp = true;
        return json({ data: { stepped_up_until: '2026-11-29T10:10:00+05:30' } });
      }
      if (path.endsWith('/corrections/c1/approve')) {
        if (!steppedUp) return json({ error: { code: 'step_up_required', message: 'Enter a code.', details: { action: 'marks_correction_approve' } } }, 403);
        approvedWith = ((await req.json()) as { reason: string }).reason;
        return json({ data: { ...correction, status: 'approved', decided_by: 'Dr. Meera Iyer', can: { decide: false } } });
      }
      if (path.endsWith('/corrections/c1')) return json({ data: correction });
      if (path.endsWith('/approvals/inbox')) return json({ data: [], counts: {}, total: 0 });
      if (path.endsWith('/leave/calendar')) return json({ data: [] });
      return undefined;
    });
    const user = userEvent.setup();
    await renderApp('/approvals?item=marks:c1');

    expect(await screen.findByText('“Page 3 was not totalled”')).toBeInTheDocument();
    const approve = screen.getByRole('button', { name: 'Approve the change' });
    expect(approve).toBeDisabled();
    await user.type(screen.getByLabelText('Your reason'), 'Checked the answer script');
    await user.click(approve);

    const code = await screen.findByLabelText(/code/i);
    await user.type(code, '123456');
    await user.click(screen.getByRole('button', { name: 'Confirm and approve' }));
    await waitFor(() => expect(approvedWith).toBe('Checked the answer script'));
    expect(await screen.findByText(/Approved\. Aditya Kulkarni's mark is changed/)).toBeInTheDocument();
  });

  it('shows the board-circular note whenever a preset is chosen', async () => {
    fakeApi.me = { ...demoUser, permissions: ['assessment.scheme:school'] };
    fakeApi.handlers.push((req) => {
      const path = new URL(req.url).pathname;
      if (path.endsWith('/assessment-schemes/presets')) {
        return json({ data: [{ key: 'cbse_6_8', label: 'CBSE-style, Classes VI to VIII', note: "A starting point only. Check against your board's latest circular and change anything that differs." }] });
      }
      if (path.endsWith('/assessment-schemes')) return json({ data: [] });
      return undefined;
    });
    const user = userEvent.setup();
    await renderApp('/tests/schemes');

    await user.selectOptions(await screen.findByLabelText('Start from'), 'cbse_6_8');
    expect(screen.getByText(/Check against your board's latest circular/)).toBeInTheDocument();
  });
});
