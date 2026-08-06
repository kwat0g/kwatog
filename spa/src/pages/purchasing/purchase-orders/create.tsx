import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Plus, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { purchaseOrdersApi } from '@/api/purchasing/purchase-orders';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import { itemsApi } from '@/api/inventory/items';
import { vendorsApi } from '@/api/accounting/vendors';
import { businessPoliciesApi } from '@/api/businessPolicies';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { Switch } from '@/components/ui/Switch';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { formatPeso } from '@/lib/formatNumber';
import { numberInputProps } from '@/lib/numberInput';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { cn } from '@/lib/cn';

const lineSchema = z.object({
 item_id: z.string().min(1, 'Item is required.'),
 description: z.string().trim().min(2, 'Description is required.').max(200),
 quantity: z.string().regex(/^\d+(\.\d{1,2})?$/, 'Up to 2 decimals.').refine(v => Number(v) > 0, 'Must be > 0.'),
 unit: z.string().max(20).optional().or(z.literal('')),
 unit_price: z.string().regex(/^\d+(\.\d{1,2})?$/, 'Up to 2 decimals.').refine(v => Number(v) >= 0, 'Must be ≥ 0.'),
});

const schema = z.object({
 vendor_id: z.string().min(1, 'Vendor is required.'),
 date: z.string().min(1, 'Date is required.'),
 expected_delivery_date: z.string().optional().or(z.literal('')),
 is_vatable: z.boolean(),
 remarks: z.string().max(1000).optional().or(z.literal('')),
 items: z.array(lineSchema).min(1, 'Add at least one line.'),
}).refine((d) => !d.expected_delivery_date || d.expected_delivery_date >= d.date, {
 message: 'Expected delivery cannot be before the PO date.',
 path: ['expected_delivery_date'],
});
type V = z.infer<typeof schema>;

export default function CreatePurchaseOrderPage() {
 const nav = useNavigate();
 const [search] = useSearchParams();
 const prId = search.get('pr_id');
 const [confirmOpen, setConfirmOpen] = useState(false);
 const [pendingValues, setPendingValues] = useState<V | null>(null);

 const items = useQuery({
 queryKey: ['inventory', 'items', { per_page: 200, is_active: 'true' }],
 queryFn: () => itemsApi.list({ per_page: 200, is_active: 'true' }),
 });
 const vendors = useQuery({
 queryKey: ['accounting', 'vendors', { per_page: 200, is_active: 'true' }],
 queryFn: () => vendorsApi.list({ per_page: 200, is_active: 'true' }),
 });
 const policies = useQuery({
 queryKey: ['business-policies'],
 queryFn: businessPoliciesApi.get,
 });
 const { data: pr } = useQuery({
 queryKey: ['purchasing', 'purchase-requests', prId],
 queryFn: () => purchaseRequestsApi.show(prId!),
 enabled: !!prId,
 });
 const vatStatus = policies.data?.vat_status;

 const { register, handleSubmit, setError, setValue, control, watch, reset, formState: { errors, isSubmitting } } = useForm<V>({
 resolver: zodResolver(schema),
 defaultValues: {
 vendor_id: '',
 date: new Date().toISOString().slice(0, 10),
 expected_delivery_date: '',
 is_vatable: undefined as unknown as boolean,
 remarks: '',
 items: [{ item_id: '', description: '', quantity: '', unit: '', unit_price: '' }],
 },
 });
 useEffect(() => {
 if (vatStatus) setValue('is_vatable', vatStatus === 'VAT Registered');
 }, [vatStatus, setValue]);
 const { fields, append, remove } = useFieldArray({ control, name: 'items' });

 // Pre-fill from PR.
 useEffect(() => {
 if (pr && pr.items) {
 reset({
 vendor_id: '',
 date: new Date().toISOString().slice(0, 10),
 expected_delivery_date: '',
 is_vatable: vatStatus === 'VAT Registered',
 remarks: `Auto-generated from PR ${pr.pr_number}`,
 items: pr.items.map((i) => ({
 item_id: i.item?.id ?? '',
 description: i.description,
 quantity: i.quantity,
 unit: i.unit ?? i.item?.unit_of_measure ?? '',
 unit_price: i.estimated_unit_price ?? '',
 })),
 });
 }
 }, [pr, reset, vatStatus]);

 const watchedItems = watch('items');
 const isVatable = watch('is_vatable');
 const subtotal = watchedItems.reduce((s, l) => s + Number(l.quantity || 0) * Number(l.unit_price || 0), 0);
 const vatRate = Number(policies.data?.vat_rate ?? 0);
 const vatRateLabel = `${(vatRate * 100).toLocaleString()}%`;
 const vat = isVatable ? subtotal * vatRate : 0;
 const total = subtotal + vat;
 const requiresVp = policies.data !== undefined && total >= policies.data.purchase_order_vp_threshold;

 const create = useMutation({
 mutationFn: (values: V) => purchaseOrdersApi.create({
 vendor_id: values.vendor_id,
 purchase_request_id: prId || undefined,
 date: values.date,
 expected_delivery_date: values.expected_delivery_date || undefined,
 is_vatable: values.is_vatable,
 remarks: values.remarks?.trim() || undefined,
 items: values.items.map((l) => ({
 item_id: l.item_id,
 description: l.description.trim(),
 quantity: l.quantity,
 unit: l.unit || undefined,
 unit_price: l.unit_price,
 })),
 }),
 onSuccess: (po) => { toast.success(`PO ${po.po_number} created.`); nav(`/purchasing/purchase-orders/${po.id}`); },
 onError: (e) => { setConfirmOpen(false); applyServerValidationErrors(e, setError, 'Failed to create PO.'); },
 });

 return (
 <div>
 <PageHeader
 title="New purchase order"
 backTo="/purchasing/purchase-orders"
 backLabel="Purchase orders"
 breadcrumbs={[{ label: 'Purchasing', href: '/purchasing' }, { label: 'Purchase orders', href: '/purchasing/purchase-orders' }, { label: 'New purchase order' }]}
 actions={requiresVp ? <Chip variant="warning">VP approval required</Chip> : null}
 />
 <form
 onSubmit={handleSubmit((d) => { setPendingValues(d); setConfirmOpen(true); }, onFormInvalid<V>())}
 className="max-w-5xl mx-auto px-5 py-4 space-y-4"
 >
 <Panel title="Header">
 <div className="grid grid-cols-3 gap-3">
 <Select label="Vendor" required {...register('vendor_id')} error={errors.vendor_id?.message}>
 <option value="">Select vendor…</option>
 {vendors.data?.data.map((v) => (
 <option key={v.id} value={v.id}>{v.name}</option>
 ))}
 </Select>
 <Input label="Date" type="date" required {...register('date')} error={errors.date?.message} />
 <Input
 label="Expected delivery"
 type="date"
 {...register('expected_delivery_date')}
 error={errors.expected_delivery_date?.message}
 />
 <Switch label={`VAT-able (${vatRateLabel})`} {...register('is_vatable')} />
 <Textarea
 label="Remarks"
 rows={2}
 className="col-span-2"
 maxLength={1000}
 {...register('remarks')}
 error={errors.remarks?.message}
 />
 </div>
 </Panel>
 <Panel
 title="Line items"
 actions={
 <Button
 type="button"
 size="sm"
 variant="secondary"
 icon={<Plus size={12} />}
 onClick={() => append({ item_id: '', description: '', quantity: '', unit: '', unit_price: '' })}
 >
 Add line
 </Button>
 }
 >
 {errors.items?.root && <div className="text-xs text-danger-fg mb-2">{errors.items.root.message}</div>}
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>Item</Th>
 <Th>Description</Th>
 <Th align="right">Qty</Th>
 <Th>Unit</Th>
 <Th align="right">Unit price</Th>
 <Th align="right">Total</Th>
 <Th />
 </tr>
 </thead>
 <tbody>
 {fields.map((f, i) => (
 <tr key={f.id} className={cn(trCls, 'align-top')}>
 <Td>
 <Select
 fieldSize="sm"
 containerClassName="w-32"
 className="font-mono"
 aria-label="Item"
 error={errors.items?.[i]?.item_id?.message}
 {...register(`items.${i}.item_id` as const)}
 >
 <option value="">—</option>
 {items.data?.data.map((it) => (
 <option key={it.id} value={it.id}>{it.code}</option>
 ))}
 </Select>
 </Td>
 <Td>
 <Input fieldSize="sm" aria-label="Description" {...register(`items.${i}.description` as const)} error={errors.items?.[i]?.description?.message} />
 </Td>
 <Td align="right" mono>
 <Input
 fieldSize="sm"
 containerClassName="w-20 inline-flex"
 className="text-right font-mono tabular-nums"
 aria-label="Quantity"
 type="text"
 {...numberInputProps()}
 {...register(`items.${i}.quantity` as const)}
 />
 </Td>
 <Td>
 <Input
 fieldSize="sm"
 containerClassName="w-16"
 aria-label="Unit"
 {...register(`items.${i}.unit` as const)}
 />
 </Td>
 <Td align="right" mono>
 <Input
 fieldSize="sm"
 containerClassName="w-24 inline-flex"
 className="text-right font-mono tabular-nums"
 aria-label="Unit price"
 type="text"
 {...numberInputProps()}
 {...register(`items.${i}.unit_price` as const)}
 />
 </Td>
 <Td align="right" mono>
 {(Number(watchedItems[i]?.quantity || 0) * Number(watchedItems[i]?.unit_price || 0)).toFixed(2)}
 </Td>
 <Td align="right" mono>
 {fields.length > 1 && (
 <Button
 type="button"
 variant="ghost"
 size="sm"
 iconOnly
 icon={<Trash2 size={12} />}
 onClick={() => remove(i)}
 aria-label="Remove line"
 className="text-muted hover:text-danger"
 />
 )}
 </Td>
 </tr>
 ))}
 <tr className={trCls}>
 <Td align="right" mono className="text-muted" colSpan={5}>Subtotal</Td>
 <Td align="right" mono>{formatPeso(subtotal)}</Td>
 <Td />
 </tr>
 {isVatable && (
 <tr className={trCls}>
 <Td align="right" mono className="text-muted" colSpan={5}>VAT ({vatRateLabel})</Td>
 <Td align="right" mono>{formatPeso(vat)}</Td>
 <Td />
 </tr>
 )}
 <tr className={cn(trCls, 'font-medium')}>
 <Td align="right" mono className="uppercase text-2xs tracking-wider" colSpan={5}>Total</Td>
 <Td align="right" mono>{formatPeso(total)}</Td>
 <Td />
 </tr>
 </tbody>
 </table>
 </Panel>
 <div className="flex justify-end gap-2">
 <Button type="button" variant="secondary" onClick={() => nav('/purchasing/purchase-orders')} disabled={create.isPending}>Cancel</Button>
 <Button type="submit" variant="primary" loading={create.isPending || policies.isLoading} disabled={create.isPending || isSubmitting || !policies.data}>Create PO</Button>
 </div>
 </form>

 <ConfirmDialog
 isOpen={confirmOpen}
 onClose={() => setConfirmOpen(false)}
 onConfirm={() => { if (pendingValues) create.mutate(pendingValues); }}
 title="Create this PO?"
 description={
 pendingValues ? (
 <>
 Total <span className="font-mono font-medium text-primary">{formatPeso(total)}</span>.
 {requiresVp && (
 <span className="block mt-1 text-warning-fg">
 Total ≥ {formatPeso(policies.data?.purchase_order_vp_threshold ?? 0)} — VP approval will be required before send.
 </span>
 )}
 </>
 ) : null
 }
 confirmLabel="Create PO"
 variant="primary"
 pending={create.isPending}
 />
 </div>
 );
}
