import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { stockTransfersApi } from '@/api/inventory/stock';
import { itemsApi } from '@/api/inventory/items';
import { warehouseApi } from '@/api/inventory/warehouse';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { numberInputProps } from '@/lib/numberInput';

const schema = z.object({
  item_id:          z.string().min(1, 'Item is required.'),
  from_location_id: z.string().min(1, 'Source location is required.'),
  to_location_id:   z.string().min(1, 'Destination location is required.'),
  quantity:         z.string().regex(/^\d+(\.\d{1,3})?$/, 'Up to 3 decimals.').refine(v => Number(v) > 0, 'Must be greater than zero.'),
  remarks:          z.string().max(500).optional().or(z.literal('')),
}).refine((d) => d.from_location_id !== d.to_location_id, {
  message: 'Source and destination must differ.',
  path: ['to_location_id'],
});
type V = z.infer<typeof schema>;

export default function CreateStockTransferPage() {
  const nav = useNavigate();
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [pendingValues, setPendingValues] = useState<V | null>(null);

  const items = useQuery({
    queryKey: ['inventory', 'items', { per_page: 200, is_active: 'true' }],
    queryFn: () => itemsApi.list({ per_page: 200, is_active: 'true' }),
  });
  const warehouses = useQuery({
    queryKey: ['inventory', 'warehouse', 'tree'],
    queryFn: () => warehouseApi.tree(),
  });

  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<V>({
    resolver: zodResolver(schema),
  });

  const m = useMutation({
    mutationFn: (d: V) => stockTransfersApi.create({
      item_id: d.item_id,
      from_location_id: d.from_location_id,
      to_location_id: d.to_location_id,
      quantity: d.quantity,
      remarks: d.remarks?.trim() || undefined,
    }),
    onSuccess: () => { toast.success('Stock transfer recorded.'); nav('/inventory/movements'); },
    onError: (e) => {
      setConfirmOpen(false);
      applyServerValidationErrors(e, setError, 'Failed to record transfer.');
    },
  });

  const locations = (warehouses.data ?? []).flatMap((w) =>
    (w.zones ?? []).flatMap((z) => (z.locations ?? []).map((l) => ({
      id: l.id,
      label: `${w.code}-${z.code}-${l.code}`,
      sub: `${w.name} / ${z.name}`,
    }))),
  );

  return (
    <div>
      <PageHeader title="Stock transfer" backTo="/inventory/movements" backLabel="Movements" />
      <form
        onSubmit={handleSubmit((d) => { setPendingValues(d); setConfirmOpen(true); }, onFormInvalid<V>())}
        className="max-w-3xl mx-auto px-5 py-6 space-y-4"
      >
        <Panel title="Transfer">
          <div className="grid grid-cols-2 gap-3">
            <Select label="Item" required {...register('item_id')} error={errors.item_id?.message}>
              <option value="">Select item…</option>
              {items.data?.data.map((it) => (
                <option key={it.id} value={it.id}>{it.code} — {it.name}</option>
              ))}
            </Select>
            <Input
              label="Quantity"
              required
              {...register('quantity')}
              {...numberInputProps()}
              className="font-mono tabular-nums text-right"
              error={errors.quantity?.message}
            />
            <Select label="From location" required {...register('from_location_id')} error={errors.from_location_id?.message}>
              <option value="">Select source…</option>
              {locations.map((l) => (
                <option key={l.id} value={l.id}>{l.label} ({l.sub})</option>
              ))}
            </Select>
            <Select label="To location" required {...register('to_location_id')} error={errors.to_location_id?.message}>
              <option value="">Select destination…</option>
              {locations.map((l) => (
                <option key={l.id} value={l.id}>{l.label} ({l.sub})</option>
              ))}
            </Select>
            <Textarea
              label="Remarks"
              rows={2}
              className="col-span-2"
              maxLength={500}
              placeholder="Optional notes — kept on movement record"
              {...register('remarks')}
            />
          </div>
        </Panel>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={() => nav('/inventory/movements')}>Cancel</Button>
          <Button type="submit" variant="primary" disabled={isSubmitting}>Transfer</Button>
        </div>
      </form>

      <ConfirmDialog
        isOpen={confirmOpen}
        onClose={() => setConfirmOpen(false)}
        onConfirm={() => { if (pendingValues) m.mutate(pendingValues); }}
        title="Record this transfer?"
        description={
          pendingValues ? (
            <>
              Stock of <span className="font-mono font-medium text-primary">{pendingValues.quantity}</span>
              {' '}will move between locations. This posts an OUT and IN stock movement pair.
            </>
          ) : null
        }
        confirmLabel="Transfer"
        variant="primary"
        pending={m.isPending}
      />
    </div>
  );
}
