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
 */
export function showUndoToast({ message, onUndo, duration = 5000 }: UndoToastOptions): string {
  return toast.custom(
    (t) => (
      <div
        className={`flex items-center gap-3 px-4 py-2.5 bg-canvas border border-default rounded-md shadow-menu transition-all duration-fast ${
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
