import { useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { AxiosError } from 'axios';
import { useNavigate } from 'react-router-dom';
import toast from 'react-hot-toast';
import { customerPortalApi } from '@/api/b2b/customer';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { formatDateTime } from '@/lib/formatDate';
import type { PortalDeliveryDetail, PortalReceiptGroup } from '@/types/b2b';

function quantityValue(value: string): bigint | null {
  if (!/^\d{1,12}(?:\.\d{1,2})?$/.test(value)) return null;
  const [whole, fraction = ''] = value.split('.');
  return BigInt(whole) * 1000n + BigInt(fraction.padEnd(3, '0'));
}

function displayQuantity(value: string | number): string {
  return String(value).replace(/(\.\d*?[1-9])0+$|\.0+$/, '$1');
}

export function ConfirmReceiptDialog({ delivery, group, onClose }: { delivery: PortalDeliveryDetail; group?: PortalReceiptGroup; onClose: () => void }) {
  const [reviewedGroup, setReviewedGroup] = useState(group);
  const [needsReview, setNeedsReview] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const shipments = reviewedGroup?.shipments ?? [{ kind: 'original' as const, already_confirmed: false, delivery }];
  const quantityKey = (shipmentId: string, itemId: string) => `${shipmentId}:${itemId}`;
  const title = reviewedGroup ? 'Confirm received shipments' : 'Confirm Receipt';
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const submitting = useRef(false);
  const [receiverName, setReceiverName] = useState(delivery.receiver_name ?? '');
  const [receiverPosition, setReceiverPosition] = useState(delivery.receiver_position ?? '');
  const [remarks, setRemarks] = useState(delivery.delivery_remarks ?? '');
  const [nameTouched, setNameTouched] = useState(false);
  const [quantities, setQuantities] = useState<Record<string, string>>(() => Object.fromEntries(
    shipments.flatMap((shipment) => shipment.delivery.items.map((item) => [quantityKey(shipment.delivery.id, item.id), displayQuantity(item.quantity_delivered)])),
  ));

  const confirmation = useMutation({
    mutationFn: async () => {
      const receiver = {
        receiver_name: receiverName.trim(), receiver_position: receiverPosition.trim() || undefined,
        delivery_remarks: remarks.trim() || undefined,
      };
      const lines = (shipment: PortalDeliveryDetail) => shipment.items.map((item) => ({
        delivery_item_id: item.id, received_quantity: quantities[quantityKey(shipment.id, item.id)] ?? '',
      }));
      if (reviewedGroup) {
        const result = await customerPortalApi.confirmReceiptGroup(delivery.id, {
          ...receiver, version: reviewedGroup.version,
          shipments: shipments.map((shipment) => ({ delivery_id: shipment.delivery.id, lines: lines(shipment.delivery) })),
        });
        return { deliveries: result.data.shipments.map((shipment) => shipment.delivery), message: result.message };
      }
      const result = await customerPortalApi.confirmDelivery(delivery.id, { ...receiver, lines: lines(delivery) });
      return { deliveries: [result.data], message: result.message };
    },
    onSuccess: async (result) => {
      for (const confirmed of result.deliveries) {
        queryClient.setQueryData(['portal', 'customer', 'delivery', confirmed.id], confirmed);
      }
      await queryClient.invalidateQueries({ queryKey: ['portal', 'customer'] });
      toast.success(result.message ?? 'Receipt confirmed.');
      onClose();
    },
    onError: () => {
      void queryClient.invalidateQueries({ queryKey: ['portal', 'customer', 'delivery', delivery.id] });
      if (reviewedGroup) {
        setNeedsReview(true);
        void queryClient.invalidateQueries({ queryKey: ['portal', 'customer', 'receipt-group', delivery.id] });
      }
    },
    onSettled: () => { submitting.current = false; },
  });
  const serverError = (confirmation.error as AxiosError<{ message?: string; errors?: Record<string, string[]> }> | null)?.response?.data;
  const fieldError = (field: string) => serverError?.errors?.[field]?.[0];
  const nameError = nameTouched && !receiverName.trim() ? 'Enter the receiver’s name.' : fieldError('receiver_name');
  const rows = shipments.flatMap((shipment, shipmentIndex) => shipment.delivery.items.map((item, index) => {
    const key = quantityKey(shipment.delivery.id, item.id);
    const raw = quantities[key] ?? '';
    const field = reviewedGroup ? `shipments.${shipmentIndex}.lines.${index}` : `lines.${index}`;
    const received = quantityValue(raw);
    const expected = quantityValue(displayQuantity(item.quantity_delivered));
    const unit = item.unit_of_measure ?? '';
    const pieces = ['pcs', 'pc', 'pieces'].includes(unit.trim().toLowerCase());
    const short = received !== null && expected !== null && received < expected;
    let error: string | undefined;
    if (!raw) error = 'Enter the received quantity.';
    else if (received === null) error = 'Enter a non-negative number with up to 2 decimal places.';
    else if (pieces && received % 1000n !== 0n) error = 'Enter a whole number of pieces.';
    else if (expected === null) error = 'Delivery quantity needs review before invoicing.';
    else if (received > expected) error = `Maximum ${displayQuantity(item.quantity_delivered)} ${unit}.`.trim();
    else if (short) error = 'Quantity is short. Report a problem before confirming.';
    return { item, index, shipment, key, unit, raw, short, error: error ?? fieldError(`${field}.received_quantity`) ?? fieldError(`${field}.delivery_item_id`) };
  }));
  const shortage = rows.find((row) => row.short);
  const eligible = reviewedGroup ? reviewedGroup.can_confirm : delivery.can_confirm === true;
  const canSubmit = eligible && !needsReview && !refreshing && rows.length > 0 && rows.every((row) => !row.error)
    && Boolean(receiverName.trim()) && receiverName.trim().length <= 100 && receiverPosition.trim().length <= 100 && remarks.trim().length <= 500;
  const close = () => { if (!submitting.current) onClose(); };

  return (
    <Modal isOpen onClose={close} title={title} size="lg" closeOnOverlayClick={!confirmation.isPending}>
      <form className="space-y-5" noValidate onSubmit={(event) => {
        event.preventDefault();
        setNameTouched(true);
        if (!canSubmit || submitting.current) return;
        submitting.current = true;
        confirmation.mutate();
      }}>
        <div>
          <p className="text-sm font-medium">{delivery.delivery_number} · {delivery.sales_order?.so_number}</p>
          <p className="text-sm text-muted mt-1">Check received quantities against the driver’s receipt.</p>
        </div>
        {reviewedGroup && <div className="rounded-md bg-subtle p-3 text-sm space-y-1">
          <p className="font-medium">{reviewedGroup.case.case_number} · Replacement receipt</p>
          <p>This records physical arrival, including previously reported damaged goods. It does not change the original inspection results.</p>
        </div>}
        {shipments.map((shipment) => (
          <section key={shipment.delivery.id} aria-label={shipment.delivery.delivery_number}>
            {reviewedGroup && <div className="mb-2 space-y-1">
              <h3 className="text-sm font-medium">{shipment.kind === 'replacement' ? 'Replacement shipment · No charge' : 'Original shipment'}</h3>
              <p className="text-xs text-muted">{shipment.delivery.delivery_number} · {shipment.delivery.sales_order?.so_number}
                {shipment.delivery.delivered_at && ` · ${formatDateTime(shipment.delivery.delivered_at)}`}</p>
              {shipment.already_confirmed && <div className="text-xs text-success-fg">
                <p>Receipt already confirmed</p>
                <p>{shipment.delivery.receiver_name}{shipment.delivery.confirmed_at && ` · ${formatDateTime(shipment.delivery.confirmed_at)}`}</p>
              </div>}
            </div>}
            <ul className="divide-y divide-default border-y border-default">
              {rows.filter((row) => row.shipment.delivery.id === shipment.delivery.id).map(({ item, index, key, unit, raw, error }) => (
                <li key={key} className="py-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_200px] sm:items-start">
                  <div className="min-w-0">
                    <p className="text-sm font-medium">{item.part_number} · {item.name}</p>
                    <p className="text-xs text-muted mt-1">Shipment line {index + 1} · Expected {displayQuantity(item.quantity_delivered)} {unit}</p>
                  </div>
                  <Input label={`${reviewedGroup ? shipment.delivery.delivery_number + ' · ' : ''}Received qty · line ${index + 1}`} required type="text" inputMode="decimal" fieldSize="lg"
                    value={raw} maxLength={15} suffix={unit || undefined} error={error} disabled={confirmation.isPending}
                    readOnly={shipment.already_confirmed}
                    onChange={(event) => {
                      if (shipment.already_confirmed) return;
                      setQuantities((values) => ({ ...values, [key]: event.target.value }));
                      confirmation.reset();
                    }} />
                </li>
              ))}
            </ul>
          </section>
        ))}
        {shortage && <div role="alert" className="rounded-md border border-warning bg-warning-subtle p-3 space-y-2">
          <p className="text-sm text-warning-fg">Received fewer goods? Report the shortage so quantities and billing can be checked.</p>
          <Button type="button" variant="secondary" size="touch" disabled={confirmation.isPending} onClick={() => {
            onClose();
            navigate(`/portal/customer/problems/new?source_kind=delivery&source_id=${encodeURIComponent(shortage.shipment.delivery.id)}`);
          }}>Report a problem</Button>
        </div>}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <Input label="Received by" required autoComplete="name" fieldSize="lg" value={receiverName} maxLength={100}
            disabled={confirmation.isPending} error={nameError} onBlur={() => setNameTouched(true)}
            onChange={(event) => { setReceiverName(event.target.value); confirmation.reset(); }} />
          <Input label="Position (optional)" fieldSize="lg" value={receiverPosition} maxLength={100}
            disabled={confirmation.isPending} error={fieldError('receiver_position')}
            onChange={(event) => { setReceiverPosition(event.target.value); confirmation.reset(); }} />
        </div>
        <details>
          <summary className="text-sm text-muted cursor-pointer py-1">Add remarks (optional)</summary>
          <Textarea label="Remarks" value={remarks} maxLength={500} rows={2} containerClassName="mt-2"
            disabled={confirmation.isPending} error={fieldError('delivery_remarks')}
            onChange={(event) => { setRemarks(event.target.value); confirmation.reset(); }} />
        </details>
        {!eligible && <p role="alert" className="text-sm text-danger-fg">
          {reviewedGroup ? reviewedGroup.blocked_reason ?? 'These shipment receipts are already confirmed.'
            : delivery.status === 'confirmed' ? 'This receipt has already been confirmed.' : 'Receipt confirmation is on hold. Check the updated delivery record.'}
        </p>}
        {confirmation.isError && <p role="alert" className="text-sm text-danger-fg">
          {fieldError('version') ?? fieldError('shipments') ?? fieldError('lines') ?? serverError?.message ?? 'Could not confirm receipt. Check the delivery and try again.'}
        </p>}
        {needsReview && <div className="rounded-md border border-warning bg-warning-subtle p-3 space-y-2">
          <p className="text-sm text-warning-fg">Review refreshed shipment details before submitting again. Your entered quantities stay unchanged.</p>
          <Button type="button" variant="secondary" size="touch" loading={refreshing} onClick={async () => {
            setRefreshing(true);
            try {
              const fresh = await customerPortalApi.getReceiptGroup(delivery.id);
              if (!fresh) { toast.error('These shipments can no longer be confirmed together. Close this form and check the delivery.'); return; }
              setReviewedGroup(fresh);
              setQuantities((values) => {
                const next = { ...values };
                for (const shipment of fresh.shipments) for (const item of shipment.delivery.items) {
                  const key = quantityKey(shipment.delivery.id, item.id);
                  if (!(key in next)) next[key] = displayQuantity(item.quantity_delivered);
                }
                return next;
              });
              setNeedsReview(false);
              confirmation.reset();
            } catch { toast.error('Could not refresh shipment details. Try again.'); }
            finally { setRefreshing(false); }
          }}>Review refreshed shipments</Button>
        </div>}
        <p className="text-xs text-muted">{reviewedGroup
          ? 'This records receipt of both shipments. Replacement pieces are free. Your original order quantity stays unchanged.'
          : 'Confirming records customer receipt and starts invoicing.'}</p>
        <ModalFooter>
          <Button type="button" variant="secondary" size="touch" onClick={close} disabled={confirmation.isPending}>Cancel</Button>
          <Button type="submit" variant="primary" size="touch" loading={confirmation.isPending} disabled={!canSubmit || confirmation.isPending}>{reviewedGroup ? 'Confirm received shipments' : 'Confirm receipt'}</Button>
        </ModalFooter>
      </form>
    </Modal>
  );
}
