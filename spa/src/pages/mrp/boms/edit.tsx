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
import { LuPlus, LuTrash2 } from '@/lib/icons';
import toast from 'react-hot-toast';
import { onFormInvalid } from '@/lib/formErrors';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { itemsApi } from '@/api/inventory/items';
import { uomsApi } from '@/api/inventory/uoms';
import { bomsApi } from '@/api/mrp/boms';
import type { CreateBomData } from '@/api/mrp/boms';
import type { Path } from 'react-hook-form';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const itemSchema = z.object({
 item_id: z.string().min(1, 'Item is required'),
 quantity_per_unit: z.string().regex(/^\d+(\.\d{1,4})?$/, 'Use a positive decimal with up to 4 places').refine((v) => Number(v) > 0, 'Must be greater than 0'),
 unit: z.string().min(1, 'UOM is required').max(20),
 waste_factor: z.string().regex(/^\d+(\.\d{1,2})?$/, 'Use 0–50 with up to 2 decimals').optional().or(z.literal('')),
 sort_order: z.number().optional(),
 // track whether this row came from the server (item locked) or is new
 _existing: z.boolean().optional(),
 _item_display: z.string().optional(), // "CODE — Name" for locked rows
});

const schema = z.object({
 cost_batch_size: z.string().regex(/^\d+(\.\d{1,3})?$/, 'Use a positive batch size').refine((v) => Number(v) >= 1, 'Must be at least 1'),
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
 queryFn: () => itemsApi.list({ per_page: 200, is_active: 'true' }),
 });
 const { data: uoms = [] } = useQuery({ queryKey: ['inventory', 'uoms'], queryFn: uomsApi.list, staleTime: 300_000 });

 const {
 register, control, handleSubmit, setError, setValue, watch,
 formState: { errors, isSubmitting },
 } = useForm<FormValues>({
 resolver: zodResolver(schema),
 values: data
 ? {
 cost_batch_size: data.cost_batch_size ?? '1',
 items: (data.items ?? []).map((m, i) => ({
 item_id: m.item?.id ?? '',
 quantity_per_unit: m.quantity_per_unit,
 unit: m.unit,
 waste_factor: m.waste_factor ?? '',
 sort_order: m.sort_order ?? i,
 _existing: true,
 _item_display: m.item ? `${m.item.code} — ${m.item.name}` : '',
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
 cost_batch_size: values.cost_batch_size,
 items: values.items.map((row, i) => ({
 item_id: row.item_id,
 quantity_per_unit: row.quantity_per_unit,
 unit: row.unit,
 waste_factor: row.waste_factor || '0',
 sort_order: i,
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
 setError(field as Path<FormValues>, { type: 'server', message: msgs[0] });
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
 if (picked?.unit_of_measure && !watchedItems[rowIndex]?.unit) {
 setValue(`items.${rowIndex}.unit`, picked.unit_of_measure);
 }
 };

 if (isLoading) return (
 <div>
 <PageHeader title="Edit BOM" backTo="/mrp/boms" backLabel="BOMs"
 />
 <SkeletonDetail />
 </div>
 );

 if (isError || !data) return (
 <div>
 <PageHeader title="Edit BOM" backTo="/mrp/boms" backLabel="BOMs"
 />
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
 />
 <form
 onSubmit={handleSubmit((v) => update.mutate(v), onFormInvalid<FormValues>())}
 className="max-w-4xl mx-auto px-5 py-4"
 >
 {/* Read-only product banner */}
 <div className="mb-6 p-3 bg-subtle rounded-md text-sm">
 <span className="text-muted text-xs uppercase tracking-wider font-medium mr-2">Product</span>
 <span className="font-mono">{data.product?.part_number}</span>
 <span className="ml-2 text-muted">{data.product?.name}</span>
 <span className="ml-3 text-xs text-muted">(product cannot be changed — create a new BOM to reassign)</span>
 </div>

 <div className="mb-6 max-w-xs">
 <Input
 label="Cost batch size"
 required
 {...register('cost_batch_size')}
 error={errors.cost_batch_size?.message}
 className="font-mono"
 />
 <p className="mt-1 text-xs text-muted">Setup time is allocated across this many units for per-unit costing.</p>
 </div>

 <fieldset className="mb-8">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Material lines</legend>
 <div className="border border-default rounded-md overflow-hidden">
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th className="w-2/5">Item</Th>
 <Th align="right">Qty / unit</Th>
 <Th>UOM</Th>
 <Th align="right">Waste %</Th>
 <Th />
 </tr>
 </thead>
 <tbody>
 {fields.map((field, i) => {
 const isExisting = watchedItems?.[i]?._existing === true;
 return (
 <tr key={field.id} className={trCls}>
 <Td>
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
 </Td>
 <Td align="right" mono>
 <Input
 {...register(`items.${i}.quantity_per_unit` as const)}
 error={errors.items?.[i]?.quantity_per_unit?.message}
 placeholder="0.0000"
 className="font-mono text-right tabular-nums"
 />
 </Td>
 <Td>
 <Select
 {...register(`items.${i}.unit` as const)}
 error={errors.items?.[i]?.unit?.message}
 className="font-mono"
 >
 <option value="">—</option>
 {uoms.map((u) => <option key={u.id} value={u.code}>{u.code}</option>)}
 </Select>
 </Td>
 <Td align="right" mono>
 <Input
 {...register(`items.${i}.waste_factor` as const)}
 error={errors.items?.[i]?.waste_factor?.message}
 placeholder="0.00"
 className="font-mono text-right tabular-nums"
 />
 </Td>
 <Td align="right" mono>
 <Button
 type="button"
 variant="ghost"
 size="sm"
 iconOnly
 icon={<LuTrash2 size={14} />}
 aria-label="Remove line"
 onClick={() => remove(i)}
 disabled={fields.length === 1}
 className="text-muted hover:text-danger-fg"
 />
 </Td>
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
 icon={<LuPlus size={14} />}
 onClick={() => append({ item_id: '', quantity_per_unit: '', unit: '', waste_factor: '', _existing: false, _item_display: '' })}
 >
 Add line
 </Button>
 </div>

 {errors.items?.message && (
 <p className="mt-2 text-xs text-danger-fg">{errors.items.message as string}</p>
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
