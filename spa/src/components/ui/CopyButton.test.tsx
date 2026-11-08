import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CopyButton } from './CopyButton';

const originalClipboard = navigator.clipboard;
const originalExecCommand = document.execCommand;

function setClipboard(value: unknown) {
  Object.defineProperty(navigator, 'clipboard', {
    configurable: true,
    value,
  });
}

afterEach(() => {
  setClipboard(originalClipboard);
  Object.defineProperty(document, 'execCommand', {
    configurable: true,
    value: originalExecCommand,
  });
});

describe('CopyButton', () => {
  it('copies its value and confirms the action in the button', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    setClipboard({ writeText });
    const execCommand = vi.fn(() => true);
    Object.defineProperty(document, 'execCommand', {
      configurable: true,
      value: execCommand,
    });

    render(<CopyButton value="secret-value" label="Temporary password" />);

    fireEvent.click(screen.getByRole('button', { name: 'Copy temporary password' }));

    await waitFor(() => expect(writeText).toHaveBeenCalledWith('secret-value'));
    expect(screen.getByRole('button', { name: 'Copied' })).toBeInTheDocument();
    expect(execCommand).not.toHaveBeenCalled();
  });

  it('falls back to execCommand when the async Clipboard API is denied', async () => {
    const writeText = vi.fn().mockRejectedValue(new DOMException('Write permission denied.', 'NotAllowedError'));
    setClipboard({ writeText });
    const execCommand = vi.fn(() => true);
    Object.defineProperty(document, 'execCommand', {
      configurable: true,
      value: execCommand,
    });

    render(<CopyButton value="secret-value" label="Temporary password" />);

    fireEvent.click(screen.getByRole('button', { name: 'Copy temporary password' }));

    await waitFor(() => expect(execCommand).toHaveBeenCalledWith('copy'));
    expect(screen.getByRole('button', { name: 'Copied' })).toBeInTheDocument();
  });
});
