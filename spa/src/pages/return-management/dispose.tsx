import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { returnManagementApi } from '@/api/returnManagement';
import type { ReturnRequest, ReturnRequestItem, DispositionType, DispositionPayload } from '@/types/returnManagement';
import { formatInt } from '@/lib/formatNumber';
import toast from 'react-hot-toast';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const DISPOSITION_OPTIONS: Array<{ value: DispositionType; label: string }> = [
  { value: 'scrap', label: 'Scrap' },
  { value: 'rework', label: 'Rework' },
  { value: 'restock', label: 'Restock' },
  { value: 'return_to_supplier', label: 'Return to Supplier' },
];

interface Props {
  rma: ReturnRequest;
  isOpen: boolean;
  onClose: () => void;
}

export default function DisposeDialog({ rma, isOpen, onClose }: Props) {
  const queryClient = useQueryClient();
  const items = rma.items ?? [];

  const [dispositions, setDispositions] = useState<Record<string, { disposition: DispositionType; notes: string }>>(
    () => Object.fromEntries(
      items.map((item) => [item.id, { disposition: 'restock' as DispositionType, notes: '' }])
    )
  );

  const mutation = useMutation({
    mutationFn: () => {
      const payload: DispositionPayload[] = items.map((item) => ({
        item_id: item.id,
        disposition: dispositions[item.id]?.disposition ?? 'restock',
        notes: dispositions[item.id]?.notes || undefined,
      }));
      return returnManagementApi.dispose(rma.id, payload);
    },
    onSuccess: () => {
      toast.success('Disposition recorded successfully.');
      queryClient.invalidateQueries({ queryKey: ['return-request', rma.id] });
      onClose();
    },
    onError: () => {
      toast.error('Failed to record disposition.');
    },
  });

  const updateItem = (itemId: string, field: 'disposition' | 'notes', value: string) => {
    setDispositions((prev) => ({
      ...prev,
      [itemId]: { ...prev[itemId], [field]: value },
    }));
  };

  const itemLabel = (item: ReturnRequestItem) => {
    if (item.product) return `${item.product.part_number} - ${item.product.name}`;
    if (item.item) return `${item.item.code} - ${item.item.name}`;
    return `Item ${item.id}`;
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Dispose Return Items" size="lg">
      <div className="space-y-4">
        <p className="text-sm text-muted">
          Set the disposition for each returned item. Scrap and rework items will auto-create an NCR.
          {rma.type === 'customer_return' && ' A credit memo will be generated for customer returns.'}
        </p>

        <div className="overflow-x-auto">
          <table className={tableCls}>
            <thead>
              <tr className={theadTrCls}>
                <Th>Product</Th>
                <Th align="right" className="font-mono">Qty</Th>
                <Th>Disposition</Th>
                <Th>Notes</Th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id} className={trCls}>
                  <Td>{itemLabel(item)}</Td>
                  <Td align="right" mono>
                    {formatInt(item.returned_quantity || item.quantity)}
                  </Td>
                  <Td>
                    <select
                      className="input w-full"
                      value={dispositions[item.id]?.disposition ?? 'restock'}
                      onChange={(e) => updateItem(item.id, 'disposition', e.target.value)}
                    >
                      {DISPOSITION_OPTIONS.map((opt) => (
                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                      ))}
                    </select>
                  </Td>
                  <Td>
                    <input
                      type="text"
                      className="input w-full"
                      placeholder="Optional notes..."
                      value={dispositions[item.id]?.notes ?? ''}
                      onChange={(e) => updateItem(item.id, 'notes', e.target.value)}
                      maxLength={500}
                    />
                  </Td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="flex justify-end gap-2 pt-2">
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button
            variant="primary"
            loading={mutation.isPending}
            onClick={() => mutation.mutate()}
            disabled={items.length === 0}
          >
            {mutation.isPending ? 'Recording...' : 'Record Disposition'}
          </Button>
        </div>
      </div>
    </Modal>
  );
}
