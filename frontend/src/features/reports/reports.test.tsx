import { screen } from '@testing-library/react';
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
});
