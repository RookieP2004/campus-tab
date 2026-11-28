import { fireEvent, screen } from '@testing-library/react';
import { act } from 'react';
import { beforeEach, describe, expect, it } from 'vitest';
import i18n from '@/i18n';
import { usePreferences } from '@/stores/preferences';
import { demoUser, fakeApi, json } from '@/test/setup';
import { renderApp } from '@/test/renderApp';

const parent = { ...demoUser, roles: ['parent'], permissions: ['results.view:child'], scope: { children: ['c1'], class_sections: [], subject_sections: [], wings: [] } };

describe('report cards', () => {
  beforeEach(async () => {
    act(() => usePreferences.setState({ language: 'en', theme: 'system' }));
    await i18n.changeLanguage('en');
  });

  it('shows the public verification page with identity facts only', async () => {
    fakeApi.me = null;
    fakeApi.handlers.push((req) => {
      if (new URL(req.url).pathname.includes('/public/verify/SPS-BPL/')) {
        return json({ data: { school: 'Sunrise Public School', student: 'Aarav Patel', class: '9-A', year: '2026-27', version: 1, issued_on: '2027-03-10', status: 'replaced' } });
      }
      return undefined;
    });
    await renderApp(`/verify/SPS-BPL/${'a'.repeat(43)}`);

    expect(await screen.findByText('Aarav Patel')).toBeInTheDocument();
    expect(screen.getByText(/This version was replaced/)).toBeInTheDocument();
    expect(screen.queryByText(/%/)).not.toBeInTheDocument();
  });

  it('lists a family\'s published cards, a replaced version kept and marked', async () => {
    fakeApi.me = parent;
    const card = (version: number, current: boolean) => ({
      id: `k${version}`, student: { id: 'c1', name: 'Aarav Patel' }, section: { id: 's', label: '9-A' }, year: { id: 'y', name: '2026-27' }, version,
      status: current ? 'published' : 'replaced', current, issued_at: '2027-03-10T10:00:00+05:30', snapshot: { overall: { percent: '75', grade: 'B1', result: 'pass' } },
      remarks: {}, co_scholastic: {}, attendance: null, reissue_reason: null, has_pdf: true, history: [], can: { remark_class: false, remark_subjects: [], review: false, reissue: false, download: true },
    });
    fakeApi.handlers.push((req) => {
      const path = new URL(req.url).pathname;
      if (path.endsWith('/report-cards/students/c1')) return json({ data: { student: { id: 'c1', name: 'Aarav Patel' }, cards: [card(2, true), card(1, false)] } });
      if (path.endsWith('/students/c1')) return json({ data: { id: 'c1', name: 'Aarav Patel' } });
      return undefined;
    });
    await renderApp('/results?tab=cards');

    expect(await screen.findByText(/Version 2/)).toBeInTheDocument();
    expect(screen.getByText(/Version 1/)).toBeInTheDocument();
    expect(screen.getByText('Replaced')).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Open PDF' })).toHaveLength(2);
  });

  it('lists unpaid dues in the year-end wizard and keeps the year open until they are decided', async () => {
    fakeApi.me = { ...demoUser, roles: ['principal'], level: 80, permissions: ['year.promote:school'] };
    const preview = {
      from: { id: 'y1', name: '2026-27' },
      to: { id: null, name: '2027-28', starts_on: '2027-04-01', ends_on: '2028-03-31' },
      counts: { promote: 2, detain: 0, leave: 0, graduate: 0 },
      roll_forward: { classes: 1, sections: 1, assignments: 0, new_classes: [], new_sections: [] },
      sections: [],
      dues: {
        students: [
          { id: 's1', name: 'Asha Owes', section: '9-A', balance_paise: 500000, status: 'open' },
          { id: 's2', name: 'Bina Small', section: '9-A', balance_paise: 120000, status: 'open' },
        ],
        total_paise: 620000,
        unresolved: 2,
        digest: 'd'.repeat(64),
      },
      digest: 'p'.repeat(64),
    };
    fakeApi.handlers.push((req) => {
      const path = new URL(req.url).pathname;
      if (path.endsWith('/promotions/preview')) return json({ data: preview });
      if (path.endsWith('/promotions/carry-forward')) return json({ error: { code: 'step_up_required', message: 'Enter a fresh code.', details: {} } }, 403);
      return undefined;
    });
    await renderApp('/year-end');

    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: '2027-28' } });
    fireEvent.change(screen.getByLabelText('First day'), { target: { value: '2027-04-01' } });
    fireEvent.change(screen.getByLabelText('Last day'), { target: { value: '2028-03-31' } });
    fireEvent.click(screen.getByRole('button', { name: 'Show the preview' }));

    expect(await screen.findByText('Asha Owes')).toBeInTheDocument();
    expect(screen.getByText(/2 students still owe ₹6,200.00 for 2026-27/)).toBeInTheDocument();
    expect(screen.getByText('2 unpaid balances are not decided yet (step 3).')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Apply the promotion' })).toBeDisabled();

    fireEvent.change(screen.getByLabelText('What happens to the balance of Bina Small'), { target: { value: 'leave' } });
    expect(screen.getByText(/1 balance stays in 2026-27/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Approve the decisions for 2 balances' }));

    expect(await screen.findByRole('button', { name: 'Confirm and approve' })).toBeInTheDocument();
    const sent = fakeApi.requests.find((r) => r.url.endsWith('/promotions/carry-forward'));
    expect(await sent?.clone().json()).toMatchObject({ decisions: { s1: 'carry', s2: 'leave' }, dues_digest: 'd'.repeat(64) });
  });
});
