import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { returnManagementApi } from '@/api/returnManagement';
import type { ReturnRequest, ReturnRequestItem, DispositionType, DispositionPayload } from '@/types/returnManagement';
import { toast } from '@/lib/toast';

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
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-default text-left text-xs uppercase tracking-wider text-muted">
                <th className="py-2 pr-3 font-medium">Product</th>
                <th className="py-2 pr-3 font-medium text-right font-mono">Qty</th>
                <th className="py-2 pr-3 font-medium">Disposition</th>
                <th className="py-2 pr-3 font-medium">Notes</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id} className="border-b border-default">
                  <td className="py-2 pr-3">{itemLabel(item)}</td>
                  <td className="py-2 pr-3 text-right font-mono tabular-nums">
                    {parseFloat(item.returned_quantity || item.quantity).toLocaleString()}
                  </td>
                  <td className="py-2 pr-3">
                    <select
                      className="input w-full"
                      value={dispositions[item.id]?.disposition ?? 'restock'}
                      onChange={(e) => updateItem(item.id, 'disposition', e.target.value)}
                    >
                      {DISPOSITION_OPTIONS.map((opt) => (
                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                      ))}
                    </select>
                  </td>
                  <td className="py-2 pr-3">
                    <input
                      type="text"
                      className="input w-full"
                      placeholder="Optional notes..."
                      value={dispositions[item.id]?.notes ?? ''}
                      onChange={(e) => updateItem(item.id, 'notes', e.target.value)}
                      maxLength={500}
                    />
                  </td>
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
