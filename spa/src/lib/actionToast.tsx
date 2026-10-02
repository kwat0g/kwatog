import toast from 'react-hot-toast';
import { Button } from '@/components/ui/Button';

export interface ActionToastOptions {
  message: string;
  actionLabel: string;
  onAction: () => void;
  duration?: number;
}

export function showActionToast({
  message,
  actionLabel,
  onAction,
  duration = 4000,
}: ActionToastOptions): string {
  return toast.custom(
    (t) => (
      <div
        role="status"
        aria-live="polite"
        aria-atomic="true"
        className={`flex items-center gap-3 px-4 py-2.5 bg-success-bg border border-success rounded-md shadow-menu transition-opacity duration-fast ${
          t.visible ? 'animate-slide-up opacity-100' : 'opacity-0 scale-95'
        }`}
      >
        <span className="text-xs text-success-fg font-medium">{message}</span>
        <Button
          size="sm"
          variant="secondary"
          className="h-6 px-2 text-xs font-mono text-success-fg hover:text-primary"
          onClick={() => {
            toast.dismiss(t.id);
            onAction();
          }}
        >
          {actionLabel}
        </Button>
      </div>
    ),
    { duration },
  );
}
