import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CopyButton } from './CopyButton';

const originalClipboard = navigator.clipboard;

afterEach(() => {
  Object.defineProperty(navigator, 'clipboard', {
    configurable: true,
    value: originalClipboard,
  });
});

describe('CopyButton', () => {
  it('copies its value and confirms the action in the button', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: { writeText },
    });

    render(<CopyButton value="secret-value" label="Temporary password" />);

    fireEvent.click(screen.getByRole('button', { name: 'Copy temporary password' }));

    await waitFor(() => expect(writeText).toHaveBeenCalledWith('secret-value'));
    expect(screen.getByRole('button', { name: 'Copied' })).toBeInTheDocument();
  });
});
