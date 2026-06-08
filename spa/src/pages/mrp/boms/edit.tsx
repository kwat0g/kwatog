/**
 * BOM edit page — loads existing BOM, pre-fills form, calls bomsApi.update.
 * Product is read-only (BOM product never changes). Existing item rows show
 * the item code/name as static text; only qty/unit/waste are editable.
 * New rows appended during editing allow full item selection.
 */
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import { Plus, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { onFormInvalid } from '@/lib/formErrors';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { itemsApi } from '@/api/inventory/items';
import { bomsApi } from '@/api/mrp/boms';
import type { CreateBomData } from '@/api/mrp/boms';

const itemSchema = z.object({
  item_id:           z.string().min(1, 'Item is required'),
  quantity_per_unit: z.string().regex(/^\d+(\.\d{1,4})?$/, 'Use a positive decimal with up to 4 places').refine((v) => Number(v) > 0, 'Must be greater than 0'),
  unit:              z.string().min(1, 'UOM is required').max(20),
  waste_factor:      z.string().regex(/^\d+(\.\d{1,2})?$/, 'Use 0–50 with up to 2 decimals').optional().or(z.literal('')),
  sort_order:        z.number().optional(),
  // track whether this row came from the server (item locked) or is new
  _existing:         z.boolean().optional(),
  _item_display:     z.string().optional(), // "CODE — Name" for locked rows
});

const schema = z.object({
  items: z.array(itemSchema).min(1, 'Add at least one material line'),
});

type FormValues = z.infer<typeof schema>;

export default function EditBomPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['mrp', 'boms', 'detail', id],
    queryFn: () => bomsApi.show(id!),
    enabled: !!id,
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
    values: data
      ? {
          items: (data.items ?? []).map((m, i) => ({
            item_id:           m.item?.id ?? '',
            quantity_per_unit: m.quantity_per_unit,
            unit:              m.unit,
            waste_factor:      m.waste_factor ?? '0',
            sort_order:        m.sort_order ?? i,
            _existing:         true,
            _item_display:     m.item ? `${m.item.code} — ${m.item.name}` : '',
          })),
        }
      : undefined,
  });

  const { fields, append, remove } = useFieldArray({ control, name: 'items' });
  const watchedItems = watch('items');

  const update = useMutation({
    mutationFn: (values: FormValues) => {
      const payload: CreateBomData = {
        product_id: data!.product!.id,
        items: values.items.map((row, i) => ({
          item_id:           row.item_id,
          quantity_per_unit: row.quantity_per_unit,
          unit:              row.unit,
          waste_factor:      row.waste_factor || '0',
          sort_order:        i,
        })),
      };
      return bomsApi.update(id!, payload);
    },
    onSuccess: (bom) => {
      qc.invalidateQueries({ queryKey: ['mrp', 'boms'] });
      toast.success(`BOM v${bom.version} updated.`);
      navigate(`/mrp/boms/${bom.id}`);
    },
    onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
          setError(field as never, { type: 'server', message: msgs[0] });
        });
        toast.error(e.response?.data?.message || 'Validation failed.');
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to update BOM.');
      }
    },
  });

  // Auto-fill UOM when a new item row's item is picked.
  const handleItemPicked = (rowIndex: number, itemId: string) => {
    const picked = items.data?.data.find((it: { id: string; unit_of_measure?: string }) => it.id === itemId);
    if (picked && watchedItems[rowIndex] && !watchedItems[rowIndex].unit) {
      const ev = { target: { value: picked.unit_of_measure, name: `items.${rowIndex}.unit` } };
      register(`items.${rowIndex}.unit`).onChange(ev as never);
    }
  };

  if (isLoading) return (
    <div>
      <PageHeader title="Edit BOM" backTo="/mrp/boms" backLabel="BOMs"
        breadcrumbs={[{ label: 'MRP', href: '/mrp' }, { label: 'BOMs', href: '/mrp/boms' }, { label: 'Loading…' }]} />
      <SkeletonDetail />
    </div>
  );

  if (isError || !data) return (
    <div>
      <PageHeader title="Edit BOM" backTo="/mrp/boms" backLabel="BOMs"
        breadcrumbs={[{ label: 'MRP', href: '/mrp' }, { label: 'BOMs', href: '/mrp/boms' }, { label: 'Error' }]} />
      <EmptyState icon="alert-circle" title="Failed to load BOM"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
    </div>
  );

  return (
    <div>
      <PageHeader
        title={
          <div className="flex items-center gap-3">
            <span className="font-mono">{data.product?.part_number ?? '—'}</span>
            <span>{data.product?.name}</span>
          </div>
        }
        backTo={`/mrp/boms/${id}`}
        backLabel="BOM"
        breadcrumbs={[
          { label: 'MRP', href: '/mrp' },
          { label: 'BOMs', href: '/mrp/boms' },
          { label: data.product?.part_number ?? 'BOM', href: `/mrp/boms/${id}` },
          { label: 'Edit' },
        ]}
      />
      <form
        onSubmit={handleSubmit((v) => update.mutate(v), onFormInvalid<FormValues>())}
        className="max-w-4xl mx-auto px-5 py-6"
      >
        {/* Read-only product banner */}
        <div className="mb-6 p-3 bg-subtle rounded-md text-sm">
          <span className="text-muted text-xs uppercase tracking-wider font-medium mr-2">Product</span>
          <span className="font-mono">{data.product?.part_number}</span>
          <span className="ml-2 text-muted">{data.product?.name}</span>
          <span className="ml-3 text-xs text-muted">(product cannot be changed — create a new BOM to reassign)</span>
        </div>

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
                {fields.map((field, i) => {
                  const isExisting = watchedItems?.[i]?._existing === true;
                  return (
                    <tr key={field.id} className="border-t border-subtle">
                      <td className="px-2.5 py-1.5">
                        {isExisting ? (
                          /* Locked item — display only, hidden input carries the value */
                          <>
                            <input type="hidden" {...register(`items.${i}.item_id` as const)} />
                            <span className="font-mono text-xs">{watchedItems?.[i]?._item_display ?? watchedItems?.[i]?.item_id}</span>
                          </>
                        ) : (
                          <Select
                            {...register(`items.${i}.item_id` as const, {
                              onChange: (e) => handleItemPicked(i, e.target.value),
                            })}
                            error={errors.items?.[i]?.item_id?.message}
                          >
                            <option value="">Select item…</option>
                            {items.data?.data.map((it: { id: string; code: string; name: string }) => (
                              <option key={it.id} value={it.id}>{it.code} — {it.name}</option>
                            ))}
                          </Select>
                        )}
                      </td>
                      <td className="px-2.5 py-1.5 text-right">
                        <Input
                          {...register(`items.${i}.quantity_per_unit` as const)}
                          error={errors.items?.[i]?.quantity_per_unit?.message}
                          placeholder="0.0000"
                          className="font-mono text-right tabular-nums"
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
                          className="font-mono text-right tabular-nums"
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
                  );
                })}
              </tbody>
            </table>
          </div>

          <div className="mt-3">
            <Button
              type="button"
              variant="secondary"
              size="sm"
              icon={<Plus size={14} />}
              onClick={() => append({ item_id: '', quantity_per_unit: '', unit: '', waste_factor: '0', _existing: false, _item_display: '' })}
            >
              Add line
            </Button>
          </div>

          {errors.items?.message && (
            <p className="mt-2 text-xs text-danger">{errors.items.message as string}</p>
          )}
        </fieldset>

        <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate(`/mrp/boms/${id}`)}>
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={isSubmitting || update.isPending}
            loading={update.isPending}
          >
            {update.isPending ? 'Saving…' : 'Save changes'}
          </Button>
        </div>
      </form>
    </div>
  );
}
