import { useId, useState, type ReactNode } from 'react';
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
 * <ConfirmDialog isOpen={open} onClose={()=>setOpen(false)}
 * title="Delete category?" description="This cannot be undone."
 * variant="danger" onConfirm={() => del.mutate(id)} pending={del.isPending} />
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
 const titleId = useId();
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
 ? 'text-danger-fg'
 : variant === 'warning'
 ? 'text-warning-fg'
 : 'text-accent';

 const buttonVariant = variant === 'danger' || variant === 'warning' ? 'danger' : 'primary';

 return (
 <Modal
 isOpen={isOpen}
 onClose={isPending ? () => undefined : onClose}
 size="sm"
 closeOnOverlayClick={!isPending}
 ariaLabelledBy={titleId}
 >
 <div className="pt-2">
 <div className="flex gap-4">
 <div className={`shrink-0 p-3 bg-canvas/50 rounded-full border border-default/50 ${iconClass}`} aria-hidden="true">
 <AlertTriangle size={24} />
 </div>
 <div className="space-y-2 mt-1">
 <h2 id={titleId} className="text-lg font-medium tracking-tight text-primary">{title}</h2>
 {description && (
 <div className="text-base text-muted leading-relaxed">{description}</div>
 )}
 </div>
 </div>
 <div className="flex justify-end gap-3 pt-6 mt-6 border-t border-default/50">
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
