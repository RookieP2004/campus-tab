import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { act } from 'react';
import { beforeEach, describe, expect, it } from 'vitest';
import i18n from '@/i18n';
import { usePreferences } from '@/stores/preferences';
import { demoUser, fakeApi, json } from '@/test/setup';
import { renderApp } from '@/test/renderApp';
import { rupeeInput, rupees, sumPaise, typedPaise, validRupees } from './api';
import type { StudentFees } from './api';

const parent = { ...demoUser, roles: ['parent'], permissions: ['fees.dues_view:child', 'fees.pay_online:child'], scope: { children: ['c1'], class_sections: [], subject_sections: [], wings: [] } };
const classTeacher = { ...demoUser, roles: ['class_teacher'], permissions: ['fees.dues_view:class'], scope: { children: [], class_sections: ['s1'], subject_sections: [], wings: [] } };

function dues(overrides: Partial<StudentFees> = {}): StudentFees {
  return {
    student: { id: 'c1', name: 'Aarav Patel', admission_no: 'A-001' },
    year: { id: 'y1', name: '2026-27' },
    balance_paise: 430050,
    demands: [
      {
        id: 'd1', label: 'Quarter 1', due_on: '2026-04-10', lines: [], discounts: [], total_paise: 430050, due_paise: 430050, fine_paise: 0, fine_posted: false,
        paid_paise: 0, adjusted_paise: 0, balance_paise: 430050, status: 'due',
      },
    ],
    upcoming: [{ label: 'Quarter 2', due_on: '2026-07-10', total_paise: 230050 }],
    ledger: [],
    receipts: [],
    payments_pending: 0,
    adjustments: [],
    can: { collect: false, adjust: false, pay_online: true, setup: false },
    ...overrides,
  };
}

describe('fees', () => {
  beforeEach(async () => {
    act(() => usePreferences.setState({ language: 'en', theme: 'system' }));
    await i18n.changeLanguage('en');
  });

  it('shows money from whole paise without floating point', () => {
    expect(rupees(430050)).toBe('₹4,300.50');
    expect(rupees(10000099)).toBe('₹1,00,000.99');
    expect(rupees(5)).toBe('₹0.05');
    expect(rupeeInput(125050)).toBe('1250.50');
    expect(sumPaise([10, 20, 30])).toBe(60);
    expect(validRupees('1,250.50')).toBe(true);
    expect(validRupees('10.555')).toBe(false);
    expect(typedPaise('43,370.5')).toBe(4337050);
    expect(typedPaise('0.07')).toBe(7);
    expect(typedPaise('10.555')).toBeNull();
  });

  it('lets a parent pay the dues: the server sets the amount, and the test gateway confirms it', async () => {
    fakeApi.me = parent;
    let checkoutBody: unknown = null;
    let paid = false;
    fakeApi.handlers.push(async (req) => {
      const path = new URL(req.url).pathname;
      if (path.endsWith('/students/c1/fees')) return json({ data: paid ? dues({ demands: [{ ...dues().demands[0]!, status: 'paid', balance_paise: 0, paid_paise: 430050 }], balance_paise: 0 }) : dues() });
      if (path.endsWith('/students/c1')) return json({ data: { id: 'c1', name: 'Aarav Patel' } });
      if (path.endsWith('/fees/checkout') && req.method === 'POST') {
        checkoutBody = await req.json();
        return json({ data: { id: 'o1', provider: 'fake', order_id: 'order_fakeabc', amount_paise: 430050, currency: 'INR', school: 'Sunrise Public School', status: 'created', key_id: 'k', fake: true } }, 201);
      }
      if (path.endsWith('/fees/checkout/o1/fake')) {
        paid = true;
        return json({ data: { queued: true } }, 202);
      }
      if (path.endsWith('/fees/checkout/o1')) return json({ data: { id: 'o1', status: paid ? 'paid' : 'created', receipt_no: paid ? '2026-27/000001' : null, amount_paise: 430050 } });
      return undefined;
    });
    const user = userEvent.setup();
    await renderApp('/fees');

    await user.click(await screen.findByRole('button', { name: 'Pay now ₹4,300.50' }));
    expect(checkoutBody).toEqual({ student_id: 'c1', demand_ids: ['d1'] });
    const dialog = await screen.findByRole('dialog', { name: 'Test checkout' });
    await user.click(within(dialog).getByRole('button', { name: 'Pay' }));
    expect(await screen.findByText(/Receipt 2026-27\/000001 is in your receipts/, {}, { timeout: 5000 })).toBeInTheDocument();
  });

  it('shows a class teacher paid or pending only, never an amount', async () => {
    fakeApi.me = classTeacher;
    fakeApi.handlers.push((req) => {
      if (new URL(req.url).pathname.endsWith('/fees/sections/s1/status')) {
        return json({ data: { section: { id: 's1', label: '6-A' }, students: [{ student_id: 'a', name: 'Aarav Patel', status: 'pending' }, { student_id: 'b', name: 'Diya Shah', status: 'paid' }] } });
      }
      return undefined;
    });
    await renderApp('/fees');

    expect(await screen.findByText('Class 6-A: fees')).toBeInTheDocument();
    expect(screen.getByText('1 of 2 pending')).toBeInTheDocument();
    expect(screen.queryByText(/₹/)).not.toBeInTheDocument();
  });
});
