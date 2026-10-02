import { act, fireEvent, render, screen } from '@testing-library/react';
import { Toaster, resolveValue, toast } from 'react-hot-toast';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { showActionToast } from './actionToast';

function AppToaster() {
  return (
    <Toaster>
      {(t) => (
        <div data-testid="app-toaster-renderer" role="status" aria-live="polite">
          {resolveValue(t.message, t)}
        </div>
      )}
    </Toaster>
  );
}

afterEach(() => act(() => toast.remove()));

describe('showActionToast', () => {
  it('announces the success and runs its action', () => {
    const onAction = vi.fn();
    render(<AppToaster />);

    act(() => {
      showActionToast({ message: 'Transfer executed.', actionLabel: 'View movements', onAction });
    });

    const region = screen.getByRole('status');
    expect(region).toHaveAttribute('aria-atomic', 'true');
    expect(region).toHaveTextContent('Transfer executed.');

    fireEvent.click(screen.getByRole('button', { name: 'View movements' }));
    expect(onAction).toHaveBeenCalledTimes(1);
  });
});
