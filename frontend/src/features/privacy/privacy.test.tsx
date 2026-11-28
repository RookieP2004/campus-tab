import { fireEvent, screen } from '@testing-library/react';
import { act } from 'react';
import { beforeEach, describe, expect, it } from 'vitest';
import i18n from '@/i18n';
import { usePreferences } from '@/stores/preferences';
import { demoUser, fakeApi, json } from '@/test/setup';
import { renderApp } from '@/test/renderApp';

const parent = { ...demoUser, roles: ['parent'], level: 20, permissions: ['privacy.self:child'], scope: { children: ['c1'], class_sections: [], subject_sections: [], wings: [] } };

const mine = {
  notice: { id: 'n1', version: 2, status: 'published', title: 'How Sunrise uses personal data', body: 'First paragraph.\n\nSecond paragraph.', summary_of_changes: null, published_at: '2027-01-05T10:00:00+05:30', published_by: 'Vikram Malviya' },
  grievance: { name: 'Anita Desai', role: 'Grievance officer', email: 'privacy@sunrise.test', phone: null },
  consents: {
    notice: { version: 2, title: 'How Sunrise uses personal data' },
    purposes: { pen: 'Keep the PEN', photo_public: 'Use photos publicly' },
    children: [{ id: 'c1', name: 'Aarav Patel', consents: { pen: null, photo_public: { given: false, at: '2027-01-06T09:00:00+05:30', version: '2' } } }],
  },
  requests: [{ id: 'r1', type: 'access', status: 'completed', subject_type: 'student', student_id: 'c1', subject_name: 'Aarav Patel', details: 'Please send me everything.', due_on: '2027-02-05', late: false, outcome: 'A copy was handed over.', completed_at: null, created_at: null, requested_by: null, handled_by: null, history: [] }],
  can: { notices: false, requests: false, retention: false, incidents: false },
};

describe('privacy', () => {
  beforeEach(async () => {
    act(() => usePreferences.setState({ language: 'en', theme: 'system' }));
    await i18n.changeLanguage('en');
  });

  it('shows the notice, the grievance contact, a parent\'s consents and requests, and sends a consent with the notice version', async () => {
    fakeApi.me = parent;
    fakeApi.handlers.push((req) => {
      const path = new URL(req.url).pathname;
      if (path.endsWith('/privacy') && req.method === 'GET') return json({ data: mine });
      if (path.endsWith('/privacy/consents')) return json({ data: mine.consents });
      return undefined;
    });
    await renderApp('/privacy');

    expect(await screen.findByText('How Sunrise uses personal data')).toBeInTheDocument();
    expect(screen.getByText('privacy@sunrise.test')).toBeInTheDocument();
    expect(screen.getByText('A copy was handed over.')).toBeInTheDocument();
    expect(screen.getByText(/no advertising, tracking or outside analytics/)).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText(/Keep the PEN.*for Aarav Patel/), { target: { value: 'yes' } });
    await screen.findByText('Your consents');
    const sent = fakeApi.requests.find((r) => r.url.endsWith('/privacy/consents'));
    expect(await sent?.clone().json()).toEqual({ student_id: 'c1', purpose: 'pen', given: true, notice_version: 2 });
  });

  it('lets anyone ask for their data, and refuses an empty request on the page', async () => {
    fakeApi.me = { ...demoUser, roles: ['staff'], level: 30, permissions: ['privacy.self:own'] };
    fakeApi.handlers.push((req) => {
      const path = new URL(req.url).pathname;
      if (path.endsWith('/privacy') && req.method === 'GET') return json({ data: { ...mine, consents: null, requests: [] } });
      if (path.endsWith('/privacy/requests')) return json({ data: { ...mine.requests[0], due_on: '2027-03-01', status: 'received' } }, 201);
      return undefined;
    });
    await renderApp('/privacy');

    const send = await screen.findByRole('button', { name: 'Send the request' });
    expect(send).toBeDisabled();
    fireEvent.change(screen.getByLabelText(/^Details/), { target: { value: 'Please correct my phone number.' } });
    fireEvent.change(screen.getByLabelText('What do you want?'), { target: { value: 'correction' } });
    fireEvent.click(send);
    expect(await screen.findByText('Sent. The school will answer by 2027-03-01.')).toBeInTheDocument();
  });
});
