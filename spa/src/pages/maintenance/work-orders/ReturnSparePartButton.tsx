import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { reportMutationError } from '@/lib/formErrors';
import { createRequestKey } from '@/lib/requestKey';
import type { SparePartUsage } from '@/types/maintenance';

export function ReturnSparePartButton({ workOrderId, usage, desktop }: {
  workOrderId: string;
  usage: SparePartUsage;
  desktop: boolean;
}) {
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [quantity, setQuantity] = useState('');
  const [operationKey, setOperationKey] = useState(createRequestKey);
  const remainingMills = Math.round(Number(usage.quantity) * 1000) - Math.round(Number(usage.returned_quantity) * 1000);
  const remaining = (remainingMills / 1000).toFixed(3);
  const enteredMills = Math.round(Number(quantity) * 1000);
  const valid = /^\d+(?:\.\d{1,3})?$/.test(quantity) && enteredMills > 0 && enteredMills <= remainingMills;
  const mutation = useMutation({
    mutationFn: () => workOrdersApi.returnPart(workOrderId, usage.id, quantity, operationKey),
    onSuccess: () => {
      toast.success('Unused part returned.');
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-order', workOrderId] });
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-orders'] });
      setOpen(false);
      setQuantity('');
      setOperationKey(createRequestKey());
    },
    onError: (error) => reportMutationError(error, 'Could not return spare part.'),
  });
  function close() {
    if (mutation.isPending) return;
    setOpen(false);
  }
  if (remainingMills <= 0) return null;
  const fields = (
    <div className="space-y-4 py-2">
      <p className="text-sm text-muted">{usage.item?.name ?? 'Spare part'} · {remaining} {usage.item?.unit ?? ''} available to return</p>
      <Input label="Return quantity" type="number" min="0.001" max={remaining} step="0.001"
        value={quantity} onChange={(event) => setQuantity(event.target.value)} />
      <Button variant="primary" disabled={!valid} loading={mutation.isPending}
        onClick={() => mutation.mutate()}>Confirm return</Button>
    </div>
  );
  return <>
    <Button variant="secondary" size={desktop ? 'sm' : 'lg'}
      aria-label={`Return ${usage.item?.name ?? 'spare part'}${usage.lot_number ? ` from lot ${usage.lot_number}` : ''}`}
      onClick={() => setOpen(true)}>Return</Button>
    {/* One surface, one rule (W1.3): `Modal` presents as a centred dialog on a
        desktop and a bottom sheet on a phone, so this site no longer keeps its
        own `desktop ? Modal : BottomSheet` copy of the breakpoint. */}
    <Modal isOpen={open} onClose={close} title="Return unused part" size="sm">{fields}</Modal>
  </>;
}
