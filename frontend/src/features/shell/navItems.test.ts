import { describe, expect, it } from 'vitest';
import { visibleNavItems } from './navItems';

const paths = (permissions: string[]) => visibleNavItems({ permissions }).map((i) => i.to);

describe('shell navigation', () => {
  it('shows an item for its ability in any scope', () => {
    expect(paths(['students.view:class'])).toContain('/students');
    expect(paths(['results.view:child'])).toContain('/results');
  });

  it('does not count a longer ability that only starts the same', () => {
    // A fee collector may ask for a receipt to be cancelled, but decides nothing.
    const collector = paths(['fees.collect:school', 'fees.receipt_cancel_request:school', 'fees.dues_view:school', 'fees.reports:own']);
    expect(collector).toContain('/fees');
    expect(collector).not.toContain('/approvals');
    expect(paths(['fees.receipt_cancel_propose:school'])).toContain('/approvals');
  });
});
