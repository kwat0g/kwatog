import { useNavigate } from 'react-router-dom';
import { useFieldArray, useForm } from 'react-hook-form';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { LuPlus, LuTrash2 } from '@/lib/icons';
import { returnManagementApi } from '@/api/returnManagement';
import { productsApi } from '@/api/crm/products';
import { customersApi } from '@/api/accounting/customers';
import { vendorsApi } from '@/api/accounting/vendors';
import { itemsApi } from '@/api/inventory/items';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid, applyServerValidationErrors } from '@/lib/formErrors';

const itemSchema = z.object({
 // A supplier return moves raw materials (items); a customer return moves
 // finished goods (products). The backend requires one or the other per line.
 product_id: z.string().optional(),
 item_id: z.string().optional(),
 quantity: z.coerce.number().min(0.001, 'Min 0.001'),
 // The backend rejects a line with no price — it cannot value the credit note.
 unit_price: z.coerce.number().min(0, 'Required'),
 reason: z.string().optional(),
 condition: z.string().optional(),
});

const schema = z
 .object({
  type: z.string().min(1, 'Select a return type'),
  return_date: z.string().min(1, 'Required'),
  customer_id: z.string().optional(),
  vendor_id: z.string().optional(),
  reason_code: z.string().min(1, 'Select a reason'),
  reason_description: z.string().optional(),
  customer_notes: z.string().optional(),
  resolution: z.string().optional(),
  items: z.array(itemSchema).min(1, 'Add at least one item'),
 })
 .superRefine((data, ctx) => {
  if (data.type === 'customer_return' && !data.customer_id) {
   ctx.addIssue({ code: 'custom', path: ['customer_id'], message: 'Select the customer returning the goods' });
  }
  if (data.type === 'supplier_return' && !data.vendor_id) {
   ctx.addIssue({ code: 'custom', path: ['vendor_id'], message: 'Select the supplier the goods go back to' });
  }
  data.items.forEach((line, idx) => {
   const needsProduct = data.type === 'customer_return';
   if (needsProduct && !line.product_id) {
    ctx.addIssue({ code: 'custom', path: ['items', idx, 'product_id'], message: 'Select a product' });
   }
   if (!needsProduct && !line.item_id) {
    ctx.addIssue({ code: 'custom', path: ['items', idx, 'item_id'], message: 'Select an item' });
   }
  });
 });
type FormValues = z.infer<typeof schema>;

export default function CreateReturnRequestPage() {
 const navigate = useNavigate();
 const qc = useQueryClient();

 const {
 register,
 control,
 handleSubmit,
 watch,
 setError,
 formState: { errors, isSubmitting },
 } = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: {
 type: '',
 return_date: new Date().toISOString().slice(0, 10),
 customer_id: '',
 vendor_id: '',
 reason_code: '',
 reason_description: '',
 customer_notes: '',
 resolution: '',
 items: [],
 },
 });

 const { fields, append, remove } = useFieldArray({ control, name: 'items' });
 const returnType = watch('type');

 const { data: productsData } = useQuery({
 queryKey: ['products'],
 queryFn: () => productsApi.list({ per_page: 500 }),
 });

 const { data: customersData } = useQuery({
 queryKey: ['customers'],
 queryFn: () => customersApi.list({ per_page: 500 }),
 });
 const { data: vendorsData } = useQuery({
 queryKey: ['vendors'],
 queryFn: () => vendorsApi.list({ per_page: 500 }),
 });
 const { data: itemsData } = useQuery({
 queryKey: ['items'],
 queryFn: () => itemsApi.list({ per_page: 500 }),
 });
 const { data: options } = useQuery({
 queryKey: ['return-management', 'options'],
 queryFn: () => returnManagementApi.options(),
 });

 const products = productsData?.data ?? [];
 const customers = customersData?.data ?? [];
 const vendors = vendorsData?.data ?? [];
 const items = itemsData?.data ?? [];
 const isSupplierReturn = returnType === 'supplier_return';

 const mutation = useMutation({
 mutationFn: (data: FormValues) =>
 returnManagementApi.create({
 ...data,
 reason_description: data.reason_description || undefined,
 customer_notes: data.customer_notes || undefined,
 resolution: data.resolution || undefined,
 // Send only the party that matches the type — the backend rejects
 // a customer return carrying a vendor and vice versa.
 customer_id: isSupplierReturn ? undefined : data.customer_id || undefined,
 vendor_id: isSupplierReturn ? data.vendor_id || undefined : undefined,
 items: data.items.map((it) => ({
 product_id: isSupplierReturn ? undefined : it.product_id,
 item_id: isSupplierReturn ? it.item_id : undefined,
 quantity: it.quantity,
 unit_price: it.unit_price,
 reason: it.reason || undefined,
 condition: it.condition || undefined,
 })),
 } as Parameters<typeof returnManagementApi.create>[0]),
 onSuccess: (rma) => {
 qc.invalidateQueries({ queryKey: ['return-management'] });
 toast.success('Return request created.');
 navigate(`/return-management/${rma.id}`);
 },
 onError: (err) => {
 applyServerValidationErrors<FormValues>(err, setError, 'Failed to create return request.');
 },
 });

 return (
 <div>
 <PageHeader
 title="New Return Request"
 subtitle="Create a customer or supplier return"
 backTo="/return-management"
 breadcrumbs={[
 { label: 'Returns', href: '/return-management' },
 { label: 'New Return Request' },
 ]}
 />

 <form
 onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
 className="max-w-3xl mx-auto px-5 py-4 space-y-4"
 >
 {/* Type & Source */}
 <Panel title="Type & Source">
 <div className="grid grid-cols-2 gap-3">
 <Select label="Type" required {...register('type')} error={errors.type?.message}>
 <option value="">— Select type —</option>
 {(options?.types ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
 </Select>
 <Input
 label="Return Date"
 type="date"
 required
 {...register('return_date')}
 error={errors.return_date?.message}
 />
 </div>

 {returnType === 'customer_return' ? (
 <div className="grid grid-cols-2 gap-3 mt-3">
 <Select
 label="Customer"
 required
 {...register('customer_id')}
 error={errors.customer_id?.message}
 >
 <option value="">— Select customer —</option>
 {customers.map((c: { id: string; name: string }) => (
 <option key={c.id} value={c.id}>
 {c.name}
 </option>
 ))}
 </Select>
 </div>
 ) : returnType === 'supplier_return' ? (
 <div className="grid grid-cols-2 gap-3 mt-3">
 {/* Was a free-text "Vendor hash ID" box: nothing in the UI exposed a
     vendor's hash ID, so a supplier return could not be filed at all. */}
 <Select
 label="Supplier"
 required
 {...register('vendor_id')}
 error={errors.vendor_id?.message}
 >
 <option value="">— Select supplier —</option>
 {vendors.map((v: { id: string; name: string }) => (
 <option key={v.id} value={v.id}>
 {v.name}
 </option>
 ))}
 </Select>
 </div>
 ) : null}
 </Panel>

 {/* Reason */}
 <Panel title="Reason">
 <div className="grid grid-cols-2 gap-3">
 <Select
 label="Reason Code"
 required
 {...register('reason_code')}
 error={errors.reason_code?.message}
 >
 <option value="">— Select reason —</option>
 {(options?.reasons ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
 </Select>
 <Select
 label="Resolution"
 {...register('resolution')}
 error={errors.resolution?.message}
 >
 <option value="">— Select resolution —</option>
 {(options?.resolutions ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
 </Select>
 </div>
 <div className="mt-3">
 <Textarea
 label="Description"
 rows={3}
 placeholder="Describe the reason for return..."
 {...register('reason_description')}
 error={errors.reason_description?.message}
 />
 </div>
 <div className="mt-3">
 <Textarea
 label="Customer Notes"
 rows={3}
 placeholder="Notes from the customer..."
 {...register('customer_notes')}
 error={errors.customer_notes?.message}
 />
 </div>
 </Panel>

 {/* Items */}
 <Panel
 title="Items"
 actions={
 <Button
 type="button"
 variant="secondary"
 size="sm"
 icon={<LuPlus size={14} />}
 onClick={() =>
 append({ product_id: '', item_id: '', quantity: '' as unknown as number, unit_price: '' as unknown as number, reason: '', condition: '' })
 }
 >
 Add Item
 </Button>
 }
 >
 {fields.length === 0 ? (
 <div className="text-muted text-sm py-2">
 No items added yet. Click &quot;Add Item&quot; to add products being returned.
 </div>
 ) : (
 <div className="border border-default rounded-md overflow-hidden">
 <div className="grid grid-cols-12 gap-2 h-row px-2.5 bg-subtle text-2xs uppercase tracking-wider text-muted font-medium border-b border-default items-center">
 <div className="col-span-4">{isSupplierReturn ? 'Item' : 'Product'}</div>
 <div className="col-span-2 text-right">Qty</div>
 <div className="col-span-2 text-right">Unit Price</div>
 <div className="col-span-2">Condition</div>
 <div className="col-span-1">Reason</div>
 <div className="col-span-1" />
 </div>
 {fields.map((field, idx) => (
 <div
 key={field.id}
 className="grid grid-cols-12 gap-2 px-2.5 py-1.5 border-b border-subtle items-start"
 >
 <div className="col-span-4">
 {isSupplierReturn ? (
 <Select
 {...register(`items.${idx}.item_id` as const)}
 error={errors.items?.[idx]?.item_id?.message}
 >
 <option value="">— Select item —</option>
 {items.map((it: { id: string; code: string; name: string }) => (
 <option key={it.id} value={it.id}>
 {it.code} — {it.name}
 </option>
 ))}
 </Select>
 ) : (
 <Select
 {...register(`items.${idx}.product_id` as const)}
 error={errors.items?.[idx]?.product_id?.message}
 >
 <option value="">— Select product —</option>
 {products.map((p: { id: string; part_number: string; name: string }) => (
 <option key={p.id} value={p.id}>
 {p.part_number} — {p.name}
 </option>
 ))}
 </Select>
 )}
 </div>
 <div className="col-span-2">
 <Input
 type="number"
 step="0.001"
 min="0.001"
 className="font-mono tabular-nums text-right"
 {...register(`items.${idx}.quantity` as const)}
 error={errors.items?.[idx]?.quantity?.message}
 />
 </div>
 <div className="col-span-2">
 <Input
 type="number"
 step="0.01"
 min="0"
 className="font-mono tabular-nums text-right"
 {...register(`items.${idx}.unit_price` as const)}
 error={errors.items?.[idx]?.unit_price?.message}
 />
 </div>
 <div className="col-span-2">
 <Select {...register(`items.${idx}.condition` as const)}>
 <option value="">—</option>
 {(options?.conditions ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
 </Select>
 </div>
 <div className="col-span-1">
 <Input
 placeholder="Reason"
 {...register(`items.${idx}.reason` as const)}
 />
 </div>
 <div className="col-span-1 flex justify-end pt-1.5">
 <Button
 type="button"
 variant="ghost"
 size="sm"
 iconOnly
 icon={<LuTrash2 size={14} />}
 aria-label="Remove line"
 onClick={() => remove(idx)}
 className="text-muted hover:text-danger-fg"
 />
 </div>
 </div>
 ))}
 </div>
 )}
 {errors.items?.root?.message && (
 <p className="text-xs text-danger-fg mt-2">{errors.items.root.message}</p>
 )}
 </Panel>

 {/* Submit footer */}
 <div className="flex justify-end gap-2 pt-2">
 <Button type="button" variant="secondary" onClick={() => navigate('/return-management')}>
 Cancel
 </Button>
 <Button
 type="submit"
 variant="primary"
 loading={mutation.isPending}
 disabled={isSubmitting || mutation.isPending}
 >
 {mutation.isPending ? 'Creating...' : 'Create Return Request'}
 </Button>
 </div>
 </form>
 </div>
 );
}
