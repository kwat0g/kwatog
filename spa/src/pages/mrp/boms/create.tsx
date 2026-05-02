/**
 * Sprint 6 audit §3.4 — Bill of Materials authoring page.
 *
 * PPC head picks a finished-good product, then adds rows of raw-material
 * lines (item + quantity per finished unit + unit + waste %). On save the
 * service supersedes any prior active BOM for the product and creates a
 * new version (BomService::create). Re-running the form against a product
 * that already has an active BOM is documented behaviour — it just bumps
 * the version.
 */
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import { Plus, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { PageHeader } from '@/components/layout/PageHeader';
import { productsApi } from '@/api/crm/products';
import { itemsApi } from '@/api/inventory/items';
import { bomsApi } from '@/api/mrp/boms';
import type { CreateBomData } from '@/api/mrp/boms';

const itemSchema = z.object({
  item_id:           z.string().min(1, 'Item is required'),
  quantity_per_unit: z.string().regex(/^\d+(\.\d{1,4})?$/, 'Use a positive decimal with up to 4 places').refine((v) => Number(v) > 0, 'Must be greater than 0'),
  unit:              z.string().min(1, 'UOM is required').max(20),
  waste_factor:      z.string().regex(/^\d+(\.\d{1,2})?$/, 'Use 0–50 with up to 2 decimals').optional().or(z.literal('')),
});

const schema = z.object({
  product_id: z.string().min(1, 'Product is required'),
  items:      z.array(itemSchema).min(1, 'Add at least one material line'),
});

type FormValues = z.infer<typeof schema>;

export default function CreateBomPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();

  const products = useQuery({
    queryKey: ['crm', 'products', 'lookup'],
    queryFn: () => productsApi.list({ per_page: 100, is_active: 'true' }),
  });
  const items = useQuery({
    queryKey: ['inventory', 'items', 'lookup'],
    queryFn: () => itemsApi.list({ per_page: 200 }),
  });

  const {
    register, control, handleSubmit, setError, watch,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      product_id: '',
      items: [{ item_id: '', quantity_per_unit: '', unit: '', waste_factor: '0' }],
    },
  });
  const { fields, append, remove } = useFieldArray({ control, name: 'items' });

  const watchedItems = watch('items');

  const create = useMutation({
    mutationFn: (values: FormValues) => {
      const payload: CreateBomData = {
        product_id: values.product_id,
        items: values.items.map((row, i) => ({
          item_id:           row.item_id,
          quantity_per_unit: row.quantity_per_unit,
          unit:              row.unit,
          waste_factor:      row.waste_factor || '0',
          sort_order:        i,
        })),
      };
      return bomsApi.create(payload);
    },
    onSuccess: (bom) => {
      qc.invalidateQueries({ queryKey: ['mrp', 'boms'] });
      toast.success(`BOM v${bom.version} saved.`);
      navigate(`/mrp/boms/${bom.id}`);
    },
    onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
          setError(field as any, { type: 'server', message: msgs[0] });
        });
        toast.error('Please fix the errors below.');
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to save BOM.');
      }
    },
  });

  // Auto-fill UOM when an item is picked.
  const handleItemPicked = (rowIndex: number, itemId: string) => {
    const picked = items.data?.data.find((it: any) => it.id === itemId);
    if (picked && watchedItems[rowIndex] && !watchedItems[rowIndex].unit) {
      const ev = { target: { value: picked.unit_of_measure, name: `items.${rowIndex}.unit` } };
      register(`items.${rowIndex}.unit`).onChange(ev as any);
    }
  };

  return (
    <div>
      <PageHeader title="New BOM" backTo="/mrp/boms" backLabel="BOMs" />
      <form
        onSubmit={handleSubmit((v) => create.mutate(v))}
        className="max-w-4xl mx-auto px-5 py-6"
      >
        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Product</legend>
          <div className="grid grid-cols-2 gap-3">
            <Select label="Finished good" required {...register('product_id')} error={errors.product_id?.message}>
              <option value="">Select product…</option>
              {products.data?.data.map((p: any) => (
                <option key={p.id} value={p.id}>{p.part_number} — {p.name}</option>
              ))}
            </Select>
            <div className="text-xs text-muted self-end pb-2">
              Saving creates a new BOM version. Any prior active BOM for this product is automatically archived.
            </div>
          </div>
        </fieldset>

        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Material lines</legend>
          <div className="border border-default rounded-md overflow-hidden">
            <table className="w-full text-xs">
              <thead className="bg-subtle">
                <tr>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2 w-2/5">Item</th>
                  <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Qty / unit</th>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">UOM</th>
                  <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Waste %</th>
                  <th className="px-2 py-2" />
                </tr>
              </thead>
              <tbody>
                {fields.map((field, i) => (
                  <tr key={field.id} className="border-t border-subtle">
                    <td className="px-2.5 py-1.5">
                      <Select
                        {...register(`items.${i}.item_id` as const, {
                          onChange: (e) => handleItemPicked(i, e.target.value),
                        })}
                        error={errors.items?.[i]?.item_id?.message}
                      >
                        <option value="">Select item…</option>
                        {items.data?.data.map((it: any) => (
                          <option key={it.id} value={it.id}>{it.code} — {it.name}</option>
                        ))}
                      </Select>
                    </td>
                    <td className="px-2.5 py-1.5 text-right">
                      <Input
                        {...register(`items.${i}.quantity_per_unit` as const)}
                        error={errors.items?.[i]?.quantity_per_unit?.message}
                        placeholder="0.0000"
                        className="font-mono text-right"
                      />
                    </td>
                    <td className="px-2.5 py-1.5">
                      <Input
                        {...register(`items.${i}.unit` as const)}
                        error={errors.items?.[i]?.unit?.message}
                        placeholder="kg"
                        className="font-mono"
                      />
                    </td>
                    <td className="px-2.5 py-1.5 text-right">
                      <Input
                        {...register(`items.${i}.waste_factor` as const)}
                        error={errors.items?.[i]?.waste_factor?.message}
                        placeholder="0.00"
                        className="font-mono text-right"
                      />
                    </td>
                    <td className="px-2 py-1.5 text-right">
                      <button
                        type="button"
                        onClick={() => remove(i)}
                        disabled={fields.length === 1}
                        className="p-1.5 text-text-muted hover:text-danger hover:bg-elevated rounded-sm disabled:opacity-40 disabled:cursor-not-allowed"
                        aria-label="Remove line"
                      >
                        <Trash2 size={14} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="mt-3">
            <Button
              type="button"
              variant="secondary"
              size="sm"
              icon={<Plus size={14} />}
              onClick={() => append({ item_id: '', quantity_per_unit: '', unit: '', waste_factor: '0' })}
            >
              Add line
            </Button>
          </div>

          {errors.items?.message && <p className="mt-2 text-xs text-danger">{errors.items.message as string}</p>}
        </fieldset>

        <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate('/mrp/boms')}>
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={isSubmitting || create.isPending}
            loading={create.isPending}
          >
            {create.isPending ? 'Saving…' : 'Save BOM'}
          </Button>
        </div>
      </form>
    </div>
  );
}
