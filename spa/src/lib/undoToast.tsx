import toast from 'react-hot-toast';
import { Button } from '@/components/ui/Button';

export interface UndoToastOptions {
  message: string;
  onUndo: () => void;
  duration?: number;
}

/**
 * Show a toast notification with an inline [Undo] action button.
 * Uses react-hot-toast custom renderer with auto-dismiss countdown.
 *
 * The live region below is not decoration. `toast.custom` sets `type: 'custom'`,
 * and react-hot-toast's `Toaster` short-circuits that type: it renders
 * `resolveValue(t.message, t)` straight into the positioning wrapper instead of
 * calling the `children` renderer (react-hot-toast/src/components/toaster.tsx).
 * `main.tsx` puts `role="status"` + `aria-live` on that children renderer, and
 * `ToastBar` — the other branch — applies `toast.ariaProps` itself, so a custom
 * toast is the ONE toast in the app that reaches the DOM with no announcement
 * attached. A batch archive of 12 items said nothing at all to a screen reader,
 * and on the clean-batch path in pages/inventory/items/index.tsx this toast is
 * the only feedback there is.
 *
 * Mirrors main.tsx's split, minus the `assertive` arm: an undo confirmation is
 * never an error, so it is always `polite`. `aria-atomic` is explicit so the
 * message and its Undo affordance are announced as one unit rather than the
 * message alone.
 */
export function showUndoToast({ message, onUndo, duration = 5000 }: UndoToastOptions): string {
  return toast.custom(
    (t) => (
      <div
        role="status"
        aria-live="polite"
        aria-atomic="true"
        className={`flex items-center gap-3 px-4 py-2.5 bg-canvas border border-default rounded-md shadow-menu transition-opacity duration-fast ${
          t.visible ? 'animate-slide-up opacity-100' : 'opacity-0 scale-95'
        }`}
      >
        <span className="text-xs text-primary font-medium">{message}</span>
        <Button
          size="sm"
          variant="secondary"
          className="h-6 px-2 text-xs font-mono text-accent hover:text-accent-hover"
          onClick={() => {
            toast.dismiss(t.id);
            onUndo();
          }}
        >
          Undo
        </Button>
      </div>
    ),
    { duration },
  );
}
