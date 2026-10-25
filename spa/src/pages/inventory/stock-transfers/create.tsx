import { useNavigate } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { stockTransfersApi } from '@/api/inventory/stock';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { numberInputProps } from '@/lib/numberInput';

const schema = z.object({
  item_id:          z.string().min(1),
  from_location_id: z.string().min(1),
  to_location_id:   z.string().min(1),
  quantity:         z.string().regex(/^\d+(\.\d{1,3})?$/),
  remarks:          z.string().max(500).optional().or(z.literal('')),
}).refine((d) => d.from_location_id !== d.to_location_id, {
  message: 'Source and destination must differ.', path: ['to_location_id'],
});
type V = z.infer<typeof schema>;

export default function CreateStockTransferPage() {
  const nav = useNavigate();
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<V>({ resolver: zodResolver(schema) });
  const m = useMutation({
    mutationFn: (d: V) => stockTransfersApi.create({ ...d, remarks: d.remarks || undefined }),
    onSuccess: () => { toast.success('Transfer recorded.'); nav('/inventory/movements'); },
    onError: (e: any) => toast.error(e?.response?.data?.message ?? 'Failed.'),
  });
  return (
    <div>
      <PageHeader title="Stock transfer" backTo="/inventory/movements" backLabel="Movements" />
      <form onSubmit={handleSubmit((d) => m.mutate(d))} className="max-w-3xl mx-auto px-5 py-6 space-y-4">
        <Panel title="Transfer">
          <div className="grid grid-cols-2 gap-3">
            <Input label="Item ID (hash)" required {...register('item_id')} error={errors.item_id?.message} />
            <Input label="Quantity" required {...register('quantity')}
                   {...numberInputProps()} className="font-mono tabular-nums text-right" error={errors.quantity?.message} />
            <Input label="From location ID" required {...register('from_location_id')} error={errors.from_location_id?.message} />
            <Input label="To location ID" required {...register('to_location_id')} error={errors.to_location_id?.message} />
            <Textarea label="Remarks" rows={2} className="col-span-2" {...register('remarks')} />
          </div>
        </Panel>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={() => nav('/inventory/movements')}>Cancel</Button>
          <Button type="submit" variant="primary" loading={m.isPending} disabled={isSubmitting || m.isPending}>Transfer</Button>
        </div>
      </form>
    </div>
  );
}
