import { StatusChip, statuses, TypedConfirmDialog } from '@campus-tab/ui';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

describe('@campus-tab/ui', () => {
  it('keeps the destructive button disabled until the word is typed exactly', async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    render(
      <TypedConfirmDialog
        open
        title="Delete Class 6-A?"
        word="Class 6-A"
        inputLabel="Type Class 6-A to confirm"
        confirmLabel="Delete"
        cancelLabel="Cancel"
        onConfirm={onConfirm}
        onCancel={() => undefined}
      />,
    );

    const button = screen.getByRole('button', { name: 'Delete' });
    const input = screen.getByLabelText('Type Class 6-A to confirm');
    expect(button).toBeDisabled();

    await user.type(input, 'Class 6');
    expect(button).toBeDisabled();
    await user.type(input, '-B');
    expect(button).toBeDisabled();

    await user.clear(input);
    await user.type(input, 'Class 6-A');
    expect(button).toBeEnabled();
    await user.click(button);
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it('writes the state out on every status chip, never colour alone', () => {
    render(
      <div>
        {statuses.map((s) => (
          <StatusChip key={s} status={s} label={`label-${s}`} />
        ))}
      </div>,
    );

    for (const s of statuses) {
      const chip = screen.getByText(`label-${s}`);
      expect(chip).toHaveAttribute('data-status', s);
      expect(chip.className).toContain(`bg-status-${s}-bg`);
      expect(chip.className).toContain(`text-status-${s}-fg`);
    }
  });
});
