import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { stockAdjustmentsApi } from '@/api/inventory/stock';
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
  item_id:     z.string().min(1, 'Item is required.'),
  location_id: z.string().min(1, 'Location is required.'),
  direction:   z.enum(['in', 'out']),
  quantity:    z.string().regex(/^\d+(\.\d{1,3})?$/, 'Up to 3 decimals.').refine(v => Number(v) > 0, 'Must be greater than zero.'),
  unit_cost:   z.string().regex(/^(\d+(\.\d{1,4})?)?$/, 'Up to 4 decimals.').optional().or(z.literal('')),
  reason:      z.string().trim().min(10, 'Reason must be at least 10 characters (audit trail).').max(500),
});
type V = z.infer<typeof schema>;

export default function CreateStockAdjustmentPage() {
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

  const { register, handleSubmit, setError, watch, formState: { errors, isSubmitting } } = useForm<V>({
    resolver: zodResolver(schema),
    defaultValues: { direction: 'in', quantity: '0', unit_cost: '' },
  });
  const direction = watch('direction');

  const m = useMutation({
    mutationFn: (d: V) => stockAdjustmentsApi.create({
      item_id: d.item_id,
      location_id: d.location_id,
      direction: d.direction,
      quantity: d.quantity,
      reason: d.reason.trim(),
      unit_cost: d.unit_cost || undefined,
    }),
    onSuccess: () => {
      toast.success('Stock adjustment recorded.');
      nav('/inventory/movements');
    },
    onError: (e) => {
      setConfirmOpen(false);
      applyServerValidationErrors(e, setError, 'Failed to record adjustment.');
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
      <PageHeader title="Stock adjustment" backTo="/inventory/movements" backLabel="Movements" />
      <form
        onSubmit={handleSubmit((d) => { setPendingValues(d); setConfirmOpen(true); }, onFormInvalid<V>())}
        className="max-w-3xl mx-auto px-5 py-6 space-y-4"
      >
        <Panel title="Adjustment">
          <div className="grid grid-cols-2 gap-3">
            <Select label="Item" required {...register('item_id')} error={errors.item_id?.message}>
              <option value="">Select item…</option>
              {items.data?.data.map((it) => (
                <option key={it.id} value={it.id}>{it.code} — {it.name}</option>
              ))}
            </Select>
            <Select label="Location" required {...register('location_id')} error={errors.location_id?.message}>
              <option value="">Select location…</option>
              {locations.map((l) => (
                <option key={l.id} value={l.id}>{l.label} ({l.sub})</option>
              ))}
            </Select>
            <Select label="Direction" required {...register('direction')}>
              <option value="in">Increase (IN — cycle count over)</option>
              <option value="out">Decrease (OUT — cycle count short / scrap)</option>
            </Select>
            <Input
              label="Quantity"
              required
              {...register('quantity')}
              {...numberInputProps()}
              className="font-mono tabular-nums text-right"
              error={errors.quantity?.message}
            />
            {direction === 'in' && (
              <Input
                label="Unit cost (₱)"
                {...register('unit_cost')}
                {...numberInputProps()}
                className="font-mono tabular-nums text-right"
                error={errors.unit_cost?.message}
                helper="Defaults to current weighted-average cost."
              />
            )}
            <Textarea
              label="Reason (recorded for audit)"
              rows={3}
              className="col-span-2"
              required
              maxLength={500}
              placeholder="e.g. Q4 cycle count discrepancy on bin A1-03; recount confirms +5 pcs"
              {...register('reason')}
              error={errors.reason?.message}
            />
          </div>
        </Panel>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={() => nav('/inventory/movements')}>Cancel</Button>
          <Button type="submit" variant="primary" disabled={isSubmitting}>Record adjustment</Button>
        </div>
      </form>

      <ConfirmDialog
        isOpen={confirmOpen}
        onClose={() => setConfirmOpen(false)}
        onConfirm={() => { if (pendingValues) m.mutate(pendingValues); }}
        title="Record this adjustment?"
        description={
          pendingValues ? (
            <>
              Stock will be {pendingValues.direction === 'in' ? 'increased' : 'decreased'} by{' '}
              <span className="font-mono font-medium text-primary">{pendingValues.quantity}</span>.
              <br />
              The adjustment is permanent and posts a stock movement to the audit log.
            </>
          ) : null
        }
        confirmLabel="Record"
        variant={pendingValues?.direction === 'out' ? 'danger' : 'primary'}
        pending={m.isPending}
      />
    </div>
  );
}
