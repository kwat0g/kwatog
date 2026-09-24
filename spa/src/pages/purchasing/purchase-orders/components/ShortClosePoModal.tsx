import { useId, useState, useEffect } from 'react';
import { z } from 'zod';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { formatQuantity } from '@/lib/formatNumber';
import type { PurchaseOrder, PurchaseOrderItem } from '@/types/purchasing';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

interface ShortClosePoModalProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: (reason: string) => void;
  po: PurchaseOrder | null;
  pending?: boolean;
}

const reasonSchema = z
  .string()
  .trim()
  .min(10, 'At least 10 characters')
  .max(1000, 'Maximum 1000 characters');

type ReasonError = string | undefined;

/**
 * Presentational modal to short-close a purchase order (close with undelivered balance).
 * Shows line items with ordered, received, and open quantities.
 * Collects a reason with client-side validation.
 */
export function ShortClosePoModal({
  isOpen,
  onClose,
  onConfirm,
  po,
  pending = false,
}: ShortClosePoModalProps) {
  const [reason, setReason] = useState('');
  const [touched, setTouched] = useState(false);
  const titleId = useId();

  useEffect(() => {
    if (!isOpen) {
      setReason('');
      setTouched(false);
    }
  }, [isOpen]);

  const validate = (): ReasonError => {
    try {
      reasonSchema.parse(reason);
      return undefined;
    } catch (err) {
      if (err instanceof z.ZodError) {
        return err.errors[0]?.message;
      }
      return 'Invalid reason';
    }
  };

  const error = touched ? validate() : undefined;

  const handleSubmit = () => {
    setTouched(true);
    if (validate()) return;
    onConfirm(reason.trim());
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={pending ? () => undefined : onClose}
      size="md"
      closeOnOverlayClick={!pending}
      ariaLabelledBy={titleId}
    >
      <div className="py-2 space-y-4">
        <div>
          <h2 id={titleId} className="text-lg font-medium tracking-tight text-primary">
            Short-close purchase order
          </h2>
          <p className="text-base text-muted leading-relaxed mt-1">
            Close this PO with the undelivered balance. Received goods stay; the open quantity below
            will no longer be expected from the supplier.
          </p>
        </div>

        {!po || !po.items ? (
          <SkeletonTable rows={3} columns={3} />
        ) : (
          <div className="overflow-x-auto">
            <table className={`${tableCls} min-w-[400px]`}>
              <thead>
                <tr className={theadTrCls}>
                  <Th>Item</Th>
                  <Th align="right">Ordered</Th>
                  <Th align="right">Received</Th>
                  <Th align="right">Open</Th>
                </tr>
              </thead>
              <tbody>
                {po.items.map((item: PurchaseOrderItem) => (
                  <tr key={item.id} className={trCls}>
                    <Td mono>{item.item.code}</Td>
                    <Td align="right" mono>
                      {formatQuantity(item.quantity)}
                    </Td>
                    <Td align="right" mono>
                      {formatQuantity(item.quantity_received)}
                    </Td>
                    <Td align="right" mono className="font-medium">
                      {formatQuantity(item.quantity_open ?? item.quantity_remaining)}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div>
          <Textarea
            label="Reason"
            required
            rows={3}
            value={reason}
            onChange={(e) => {
              setReason(e.target.value);
              if (!touched) setTouched(true);
            }}
            placeholder="e.g. Supplier unable to deliver remaining balance, project timeline changed"
            maxLength={1050}
            error={error}
            autoFocus
          />
          <div className="mt-1 text-2xs text-muted text-right tabular-nums">
            {reason.trim().length}/1000
          </div>
        </div>

        <div className="flex justify-end gap-2 pt-3 border-t border-default">
          <Button variant="secondary" onClick={onClose} disabled={pending}>
            Cancel
          </Button>
          <Button
            variant="danger"
            onClick={handleSubmit}
            loading={pending}
            disabled={pending || !!error}
          >
            Short-close
          </Button>
        </div>
      </div>
    </Modal>
  );
}
