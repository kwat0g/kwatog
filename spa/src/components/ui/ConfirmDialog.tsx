import { useState, type ReactNode } from 'react';
import { AlertTriangle } from 'lucide-react';
import { Modal } from './Modal';
import { Button } from './Button';

export type ConfirmVariant = 'danger' | 'warning' | 'primary';

interface ConfirmDialogProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: () => void | Promise<void>;
  title: string;
  description?: ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  variant?: ConfirmVariant;
  /** When true, the confirm button shows a loading state and overlay click is disabled. */
  pending?: boolean;
}

/**
 * Reusable destructive / acknowledgement confirmation.
 * Wraps Modal so it inherits ESC handling, backdrop, body scroll-lock, and focus trapping.
 *
 * Usage:
 *   <ConfirmDialog isOpen={open} onClose={()=>setOpen(false)}
 *     title="Delete category?" description="This cannot be undone."
 *     variant="danger" onConfirm={() => del.mutate(id)} pending={del.isPending} />
 */
export function ConfirmDialog({
  isOpen,
  onClose,
  onConfirm,
  title,
  description,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  variant = 'primary',
  pending = false,
}: ConfirmDialogProps) {
  const [busy, setBusy] = useState(false);
  const isPending = pending || busy;

  const handleConfirm = async () => {
    try {
      setBusy(true);
      await onConfirm();
    } finally {
      setBusy(false);
    }
  };

  const iconClass =
    variant === 'danger'
      ? 'text-danger'
      : variant === 'warning'
        ? 'text-warning'
        : 'text-accent';

  const buttonVariant: 'primary' | 'danger' = variant === 'danger' ? 'danger' : 'primary';

  return (
    <Modal
      isOpen={isOpen}
      onClose={isPending ? () => undefined : onClose}
      size="sm"
      closeOnOverlayClick={!isPending}
    >
      <div className="py-4">
        <div className="flex gap-3">
          <div className={`shrink-0 ${iconClass}`} aria-hidden="true">
            <AlertTriangle size={20} />
          </div>
          <div className="space-y-1.5">
            <h2 className="text-md font-medium text-primary">{title}</h2>
            {description && (
              <div className="text-sm text-text-muted">{description}</div>
            )}
          </div>
        </div>
        <div className="flex justify-end gap-2 pt-4 mt-4 border-t border-default">
          <Button variant="secondary" onClick={onClose} disabled={isPending}>
            {cancelLabel}
          </Button>
          <Button
            variant={buttonVariant}
            onClick={handleConfirm}
            loading={isPending}
            disabled={isPending}
            autoFocus
          >
            {confirmLabel}
          </Button>
        </div>
      </div>
    </Modal>
  );
}
