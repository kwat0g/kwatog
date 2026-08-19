import { act, fireEvent, render, screen } from '@testing-library/react';
import { Toaster, resolveValue, toast } from 'react-hot-toast';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { showUndoToast } from './undoToast';

/**
 * These tests exist because the announcement is invisible: nothing on screen
 * changes whether or not the undo toast carries a live region, so the only way
 * to keep it is to assert on the DOM.
 *
 * `AppToaster` reproduces the shape of the real `<Toaster>` in `main.tsx` — a
 * `children` render prop that wraps every toast in `role="status"` +
 * `aria-live`. react-hot-toast skips that render prop for `type: 'custom'`
 * toasts, which is exactly what `toast.custom` produces, so the undo toast has
 * to supply its own region.
 */
function AppToaster() {
  return (
    <Toaster>
      {(t) => (
        <div
          data-testid="app-toaster-renderer"
          role="status"
          aria-live={t.type === 'error' ? 'assertive' : 'polite'}
        >
          {resolveValue(t.message, t)}
        </div>
      )}
    </Toaster>
  );
}

// The react-hot-toast store is module-global and outlives RTL's unmount.
afterEach(() => toast.remove());

describe('showUndoToast', () => {
  it('puts the confirmation inside a polite live region in the DOM', () => {
    render(<AppToaster />);

    act(() => {
      showUndoToast({ message: '12 archived.', onUndo: () => {} });
    });

    const region = screen.getByRole('status');
    expect(region).toHaveAttribute('aria-live', 'polite');
    expect(region).toHaveAttribute('aria-atomic', 'true');
    expect(region).toHaveTextContent('12 archived.');
  });

  it('carries that region itself, because a custom toast bypasses the Toaster renderer', () => {
    render(<AppToaster />);

    act(() => {
      showUndoToast({ message: '12 archived.', onUndo: () => {} });
    });

    // The bypass is real — main.tsx's wrapper never ran for this toast…
    expect(screen.queryByTestId('app-toaster-renderer')).toBeNull();
    // …so the region doing the announcing has to be the toast's own node,
    // the one holding the Undo affordance.
    expect(screen.getByRole('status')).toContainElement(
      screen.getByRole('button', { name: 'Undo' }),
    );
  });

  it('proves the bypass by contrast: an ordinary toast does reach that renderer', () => {
    render(<AppToaster />);

    act(() => {
      toast.success('Saved.');
    });

    // Without this, the assertion above could pass on a typo'd test id.
    expect(screen.getByTestId('app-toaster-renderer')).toHaveTextContent('Saved.');
  });

  it('still runs the undo callback and dismisses itself when Undo is pressed', () => {
    const onUndo = vi.fn();
    render(<AppToaster />);

    act(() => {
      showUndoToast({ message: '1 archived.', onUndo });
    });
    act(() => {
      fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
    });

    expect(onUndo).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('status').className).toContain('opacity-0');
  });
});
