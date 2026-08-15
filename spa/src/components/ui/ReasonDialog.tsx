import { useEffect, useId, useState, type ReactNode } from 'react';
import { LuTriangleAlert } from '@/lib/icons';
import { Modal } from './Modal';
import { Button } from './Button';
import { Textarea } from './Textarea';

interface ReasonDialogProps {
 isOpen: boolean;
 onClose: () => void;
 /** Called with the trimmed reason string. */
 onConfirm: (reason: string) => void | Promise<void>;
 title: string;
 description?: ReactNode;
 reasonLabel?: string;
 reasonPlaceholder?: string;
 /** Minimum characters required (after trim). Default 5. */
 minLength?: number;
 /** Maximum characters. Default 500. */
 maxLength?: number;
 confirmLabel?: string;
 cancelLabel?: string;
 variant?: 'danger' | 'primary' | 'warning';
 pending?: boolean;
}

/**
 * Modal that collects a free-form reason before confirming a destructive or
 * audit-trail-significant action (cancel PR/PO, reject GRN, reject approval, …).
 * Validates length client-side; clears state on close.
 */
export function ReasonDialog({
 isOpen,
 onClose,
 onConfirm,
 title,
 description,
 reasonLabel = 'Reason',
 reasonPlaceholder,
 minLength = 5,
 maxLength = 500,
 confirmLabel = 'Confirm',
 cancelLabel = 'Cancel',
 variant = 'danger',
 pending = false,
}: ReasonDialogProps) {
 const [reason, setReason] = useState('');
 const [busy, setBusy] = useState(false);
 const [touched, setTouched] = useState(false);
 const titleId = useId();

 useEffect(() => {
 if (!isOpen) {
 setReason('');
 setBusy(false);
 setTouched(false);
 }
 }, [isOpen]);

 const trimmed = reason.trim();
 const tooShort = trimmed.length < minLength;
 const tooLong = trimmed.length > maxLength;
 const error = touched
 ? tooShort
 ? `Please provide at least ${minLength} characters.`
 : tooLong
 ? `Please keep it under ${maxLength} characters.`
 : undefined
 : undefined;

 const isPending = pending || busy;

 const handleSubmit = async () => {
 setTouched(true);
 if (tooShort || tooLong) return;
 try {
 setBusy(true);
 await onConfirm(trimmed);
 } finally {
 setBusy(false);
 }
 };

 const iconClass = variant === 'danger' ? 'text-danger-fg' : variant === 'warning' ? 'text-warning-fg' : 'text-accent';
 const buttonVariant: 'primary' | 'danger' = variant === 'danger' ? 'danger' : 'primary';

 return (
 <Modal
 isOpen={isOpen}
 onClose={isPending ? () => undefined : onClose}
 size="sm"
 closeOnOverlayClick={!isPending}
 ariaLabelledBy={titleId}
 >
 <div className="py-2">
 <div className="flex gap-4">
 <div className={`shrink-0 p-3 bg-canvas/50 rounded-full border border-default/50 ${iconClass}`} aria-hidden="true">
 <LuTriangleAlert size={24} />
 </div>
 <div className="space-y-2 mt-1 flex-1">
 <h2 id={titleId} className="text-lg font-medium tracking-tight text-primary">{title}</h2>
 {description && <div className="text-base text-muted leading-relaxed">{description}</div>}
 </div>
 </div>
 <div className="mt-4">
 <Textarea
 label={reasonLabel}
 required
 rows={3}
 value={reason}
 onChange={(e) => { setReason(e.target.value); if (!touched) setTouched(true); }}
 placeholder={reasonPlaceholder}
 maxLength={maxLength + 50}
 error={error}
 autoFocus
 />
 <div className="mt-1 text-2xs text-muted text-right tabular-nums">
 {trimmed.length}/{maxLength}
 </div>
 </div>
 <div className="flex justify-end gap-2 pt-3 mt-2 border-t border-default">
 <Button variant="secondary" onClick={onClose} disabled={isPending}>{cancelLabel}</Button>
 <Button
 variant={buttonVariant}
 onClick={handleSubmit}
 loading={isPending}
 disabled={isPending || tooShort || tooLong}
 >
 {confirmLabel}
 </Button>
 </div>
 </div>
 </Modal>
 );
}
