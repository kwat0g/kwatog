import { useNavigate, useParams } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { itemsApi, itemCategoriesApi } from '@/api/inventory/items';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Switch } from '@/components/ui/Switch';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid } from '@/lib/formErrors';
import { numberInputProps } from '@/lib/numberInput';
import type { ApiValidationError } from '@/types';
import type { CreateItemData } from '@/types/inventory';

const schema = z.object({
  code:                   z.string().min(2).max(30).regex(/^[A-Z0-9-]+$/, 'Use uppercase letters, digits, hyphens.'),
  name:                   z.string().min(1).max(200),
  description:            z.string().max(1000).optional().or(z.literal('')),
  category_id:            z.coerce.number().int().min(1, 'Category is required'),
  item_type:              z.enum(['raw_material', 'finished_good', 'packaging', 'spare_part']),
  unit_of_measure:        z.string().min(1).max(20),
  standard_cost:          z.string().regex(/^\d+(\.\d{1,4})?$/, 'Up to 4 decimals'),
  reorder_method:         z.enum(['fixed_quantity', 'days_of_supply']),
  reorder_point:          z.string().regex(/^\d+(\.\d{1,3})?$/),
  safety_stock:           z.string().regex(/^\d+(\.\d{1,3})?$/),
  minimum_order_quantity: z.string().regex(/^\d+(\.\d{1,3})?$/).optional().or(z.literal('')),
  lead_time_days:         z.coerce.number().int().min(0).max(365),
  is_critical:            z.boolean().default(false),
  is_active:              z.boolean().default(true),
});
type FormValues = z.infer<typeof schema>;

export default function ItemFormPage({ mode }: { mode: 'create' | 'edit' }) {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { id = '' } = useParams<{ id: string }>();

  const { data: existing } = useQuery({
    queryKey: ['inventory', 'items', id],
    queryFn: () => itemsApi.show(id),
    enabled: mode === 'edit' && !!id,
  });

  const { data: categories } = useQuery({
    queryKey: ['inventory', 'categories'],
    queryFn: () => itemCategoriesApi.list(),
  });

  const defaults: FormValues = existing ? {
    code: existing.code, name: existing.name, description: existing.description ?? '',
    category_id: Number(existing.category?.id ?? 0),
    item_type: existing.item_type, unit_of_measure: existing.unit_of_measure,
    standard_cost: existing.standard_cost, reorder_method: existing.reorder_method,
    reorder_point: existing.reorder_point, safety_stock: existing.safety_stock,
    minimum_order_quantity: existing.minimum_order_quantity,
    lead_time_days: existing.lead_time_days,
    is_critical: existing.is_critical, is_active: existing.is_active,
  } : {
    code: '', name: '', description: '', category_id: 0,
    item_type: 'raw_material', unit_of_measure: 'kg',
    standard_cost: '0.0000', reorder_method: 'fixed_quantity',
    reorder_point: '0.000', safety_stock: '0.000', minimum_order_quantity: '1.000',
    lead_time_days: 0, is_critical: false, is_active: true,
  };

  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: defaults,
    values: existing ? defaults : undefined,
  });

  const mutation = useMutation({
    mutationFn: (d: FormValues) => {
      const payload: CreateItemData = {
        ...d,
        description: d.description || undefined,
        minimum_order_quantity: d.minimum_order_quantity || undefined,
      };
      return mode === 'create' ? itemsApi.create(payload) : itemsApi.update(id, payload);
    },
    onSuccess: (it) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'items'] });
      toast.success(mode === 'create' ? 'Item created.' : 'Item updated.');
      navigate(`/inventory/items/${it.id}`);
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data?.errors) {
        Object.entries(e.response.data.errors).forEach(([f, msgs]) =>
          setError(f as keyof FormValues, { type: 'server', message: (msgs as string[])[0] }));
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to save item.');
      }
    },
  });

  return (
    <div>
      <PageHeader
        title={mode === 'create' ? 'New item' : `Edit ${existing?.code ?? 'item'}`}
        backTo="/inventory/items" backLabel="Items"
      />
      <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
            className="max-w-3xl mx-auto px-5 py-6 space-y-4">
        <Panel title="Identity">
          <div className="grid grid-cols-2 gap-3">
            <Input label="Code" required {...register('code')} error={errors.code?.message}
                   placeholder="RM-001" className="font-mono uppercase" />
            <Input label="Name" required {...register('name')} error={errors.name?.message} />
            <Textarea label="Description" rows={2} className="col-span-2" {...register('description')} />
            <Select label="Category" required {...register('category_id')} error={errors.category_id?.message}>
              <option value="">Select category</option>
              {categories?.map((c) => (
                <option key={c.id} value={Number(c.id) || c.id}>
                  {c.parent_name ? `${c.parent_name} > ${c.name}` : c.name}
                </option>
              ))}
            </Select>
            <Select label="Item type" required {...register('item_type')} error={errors.item_type?.message}>
              <option value="raw_material">Raw material</option>
              <option value="finished_good">Finished good</option>
              <option value="packaging">Packaging</option>
              <option value="spare_part">Spare part</option>
            </Select>
            <Input label="Unit of measure" required {...register('unit_of_measure')}
                   placeholder="kg, pcs, L" error={errors.unit_of_measure?.message} />
            <Input label="Standard cost (₱)" required {...register('standard_cost')}
                   {...numberInputProps({ decimal: true })}
                   className="font-mono tabular-nums text-right" error={errors.standard_cost?.message} />
          </div>
        </Panel>

        <Panel title="Replenishment">
          <div className="grid grid-cols-2 gap-3">
            <Select label="Reorder method" required {...register('reorder_method')} error={errors.reorder_method?.message}>
              <option value="fixed_quantity">Fixed quantity</option>
              <option value="days_of_supply">Days of supply</option>
            </Select>
            <Input label="Lead time (days)" required type="number" min={0} max={365}
                   {...register('lead_time_days')} className="font-mono tabular-nums text-right"
                   error={errors.lead_time_days?.message} />
            <Input label="Reorder point" required {...register('reorder_point')}
                   {...numberInputProps({ decimal: true })} className="font-mono tabular-nums text-right"
                   error={errors.reorder_point?.message} />
            <Input label="Safety stock" required {...register('safety_stock')}
                   {...numberInputProps({ decimal: true })} className="font-mono tabular-nums text-right"
                   error={errors.safety_stock?.message} />
            <Input label="Minimum order quantity" {...register('minimum_order_quantity')}
                   {...numberInputProps({ decimal: true })} className="font-mono tabular-nums text-right"
                   helper="Auto-PR rounds up to nearest multiple."
                   error={errors.minimum_order_quantity?.message} />
          </div>
          <div className="mt-3 flex gap-6">
            <Switch label="Critical item" {...register('is_critical')} />
            <Switch label="Active" {...register('is_active')} />
          </div>
        </Panel>

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="secondary" onClick={() => navigate('/inventory/items')}>Cancel</Button>
          <Button type="submit" variant="primary"
                  loading={mutation.isPending}
                  disabled={isSubmitting || mutation.isPending}>
            {mode === 'create' ? 'Create item' : 'Save changes'}
          </Button>
        </div>
      </form>
    </div>
  );
}
