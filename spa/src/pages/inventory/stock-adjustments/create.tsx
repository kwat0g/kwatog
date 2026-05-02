import { useNavigate } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { stockAdjustmentsApi } from '@/api/inventory/stock';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { numberInputProps } from '@/lib/numberInput';

const schema = z.object({
  item_id:     z.string().min(1, 'Item is required'),
  location_id: z.string().min(1, 'Location is required'),
  direction:   z.enum(['in', 'out']),
  quantity:    z.string().regex(/^\d+(\.\d{1,3})?$/, 'Up to 3 decimals'),
  unit_cost:   z.string().regex(/^\d+(\.\d{1,4})?$/).optional().or(z.literal('')),
  reason:      z.string().min(10, 'Reason min 10 chars').max(500),
});
type V = z.infer<typeof schema>;

export default function CreateStockAdjustmentPage() {
  const nav = useNavigate();
  const { register, handleSubmit, watch, formState: { errors, isSubmitting } } = useForm<V>({
    resolver: zodResolver(schema),
    defaultValues: { direction: 'in', quantity: '0', unit_cost: '' },
  });
  const direction = watch('direction');

  const m = useMutation({
    mutationFn: (d: V) => stockAdjustmentsApi.create({
      ...d,
      unit_cost: d.unit_cost || undefined,
    }),
    onSuccess: () => { toast.success('Adjustment recorded.'); nav('/inventory/movements'); },
    onError: (e: any) => toast.error(e?.response?.data?.message ?? 'Failed.'),
  });

  return (
    <div>
      <PageHeader title="Stock adjustment" backTo="/inventory/movements" backLabel="Movements" />
      <form onSubmit={handleSubmit((d) => m.mutate(d))} className="max-w-3xl mx-auto px-5 py-6 space-y-4">
        <Panel title="Adjustment">
          <div className="grid grid-cols-2 gap-3">
            <Input label="Item ID (hash)" required {...register('item_id')} error={errors.item_id?.message} />
            <Input label="Location ID (hash)" required {...register('location_id')} error={errors.location_id?.message} />
            <Select label="Direction" required {...register('direction')}>
              <option value="in">Increase (IN)</option>
              <option value="out">Decrease (OUT)</option>
            </Select>
            <Input label="Quantity" required {...register('quantity')}
                   {...numberInputProps()} className="font-mono tabular-nums text-right"
                   error={errors.quantity?.message} />
            {direction === 'in' && (
              <Input label="Unit cost (₱)" {...register('unit_cost')}
                     {...numberInputProps()} className="font-mono tabular-nums text-right"
                     error={errors.unit_cost?.message} />
            )}
            <Textarea label="Reason" rows={2} className="col-span-2" required
                      {...register('reason')} error={errors.reason?.message} />
          </div>
        </Panel>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={() => nav('/inventory/movements')}>Cancel</Button>
          <Button type="submit" variant="primary" loading={m.isPending} disabled={isSubmitting || m.isPending}>Record</Button>
        </div>
      </form>
    </div>
  );
}
