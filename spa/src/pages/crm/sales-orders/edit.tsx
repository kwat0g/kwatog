/**
 * Sprint 6 audit §3.1 — Edit Sales Order page.
 *
 * Only draft SOs can be edited. This pre-fills the create-form layout and
 * issues a PUT instead of POST. A confirmed/cancelled SO that lands here
 * shows a friendly "not editable" empty state instead of letting the user
 * silently lose their changes.
 */
import { useEffect, useMemo } from 'react';
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
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { customersApi } from '@/api/accounting/customers';
import { productsApi } from '@/api/crm/products';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import type { UpdateSalesOrderData } from '@/types/crm';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

const itemSchema = z.object({
 product_id: z.string().min(1, 'Product is required'),
 quantity: z.string().regex(/^\d+(\.\d{1,2})?$/, 'Use a positive decimal with up to 2 places').refine((v) => Number(v) > 0, 'Must be greater than 0'),
 delivery_date: z.string().min(1, 'Delivery date is required'),
});

const schema = z.object({
 customer_id: z.string().min(1, 'Customer is required'),
 date: z.string().min(1, 'Order date is required'),
 payment_terms_days: z.string().regex(/^\d+$/, 'Use a non-negative integer').optional().or(z.literal('')),
 delivery_terms: z.string().max(50).optional().or(z.literal('')),
 notes: z.string().max(2000).optional().or(z.literal('')),
 items: z.array(itemSchema).min(1, 'Add at least one line item'),
});

type FormValues = z.infer<typeof schema>;

export default function EditSalesOrderPage() {
 const { id } = useParams<{ id: string }>();
 const navigate = useNavigate();
 const qc = useQueryClient();

 const detail = useQuery({
 queryKey: ['crm', 'sales-orders', 'detail', id],
 queryFn: () => salesOrdersApi.show(id!),
 enabled: !!id,
 });
 const customers = useQuery({
 queryKey: ['accounting', 'customers', 'lookup'],
 queryFn: () => customersApi.list({ per_page: 100, is_active: 'true' }),
 });
 const products = useQuery({
 queryKey: ['crm', 'products', 'lookup'],
 queryFn: () => productsApi.list({ per_page: 100, is_active: 'true' }),
 });

 const {
 register, control, handleSubmit, reset, setError,
 formState: { errors, isSubmitting },
 } = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: {
 customer_id: '',
 date: '',
 payment_terms_days: '',
 delivery_terms: '',
 notes: '',
 items: [{ product_id: '', quantity: '', delivery_date: '' }],
 },
 });
 const { fields, append, remove } = useFieldArray({ control, name: 'items' });

 // Pre-fill once the SO loads.
 useEffect(() => {
 if (!detail.data) return;
 const so = detail.data;
 reset({
 customer_id: so.customer?.id ?? '',
 date: so.date,
 payment_terms_days: String(so.payment_terms_days),
 delivery_terms: so.delivery_terms ?? '',
 notes: so.notes ?? '',
 items: (so.items ?? []).map((it) => ({
 product_id: it.product?.id ?? '',
 quantity: String(it.quantity),
 delivery_date: it.delivery_date,
 })),
 });
 }, [detail.data, reset]);

 const update = useMutation({
 mutationFn: async (values: FormValues) => {
 const payload: UpdateSalesOrderData = {
 customer_id: values.customer_id,
 date: values.date,
 payment_terms_days: values.payment_terms_days ? Number(values.payment_terms_days) : undefined,
 delivery_terms: values.delivery_terms || undefined,
 notes: values.notes || undefined,
 items: values.items.map((i) => ({
 product_id: i.product_id,
 quantity: i.quantity,
 delivery_date: i.delivery_date,
 })),
 };
 return salesOrdersApi.update(id!, payload);
 },
 onSuccess: (so) => {
 qc.invalidateQueries({ queryKey: ['crm', 'sales-orders'] });
 qc.invalidateQueries({ queryKey: ['crm', 'sales-orders', 'detail', id] });
 toast.success(`Sales order ${so.so_number} updated.`);
 navigate(`/crm/sales-orders/${so.id}`);
 },
 onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
 if (e.response?.status === 422 && e.response.data.errors) {
 Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
 setError(field as never, { type: 'server', message: msgs[0] });
 });
 toast.error(e.response?.data?.message || 'Validation failed.');
 } else {
 toast.error(e.response?.data?.message ?? 'Failed to update sales order.');
 }
 },
 });

 const isDraft = useMemo(() => detail.data?.status === 'draft', [detail.data]);

 if (detail.isLoading) {
 return (
 <div>
 <PageHeader title="Edit sales order" backTo="/crm/sales-orders" backLabel="Sales orders"
 />
 <SkeletonForm />
 </div>
 );
 }
 if (detail.isError || !detail.data) {
 return (
 <div>
 <PageHeader title="Edit sales order" backTo="/crm/sales-orders" backLabel="Sales orders"
 />
 <EmptyState
 icon="alert-circle"
 title="Failed to load sales order"
 action={<Button variant="secondary" onClick={() => detail.refetch()}>Retry</Button>}
 />
 </div>
 );
 }

 if (!isDraft) {
 return (
 <div>
 <PageHeader title={`Edit ${detail.data.so_number}`} backTo={`/crm/sales-orders/${id}`} backLabel="Back to sales order"
 />
 <EmptyState
 icon="lock"
 title="This sales order can no longer be edited"
 description={`Status is ${detail.data.status_label}. Only draft sales orders can be edited; cancel and recreate if changes are needed.`}
 action={<Button variant="secondary" onClick={() => navigate(`/crm/sales-orders/${id}`)}>View sales order</Button>}
 />
 </div>
 );
 }

 return (
 <div>
 <PageHeader title={`Edit ${detail.data.so_number}`} backTo={`/crm/sales-orders/${id}`} backLabel="Back to sales order"
 />
 <form onSubmit={handleSubmit((v) => update.mutate(v), onFormInvalid<FormValues>())} className="max-w-4xl mx-auto px-5 py-4">
 <fieldset className="mb-8">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Order header</legend>
 <div className="grid grid-cols-2 gap-3">
 <Select label="Customer" required {...register('customer_id')} error={errors.customer_id?.message}>
 <option value="">Select customer…</option>
 {customers.data?.data.map((c) => (
 <option key={c.id} value={c.id}>{c.name}</option>
 ))}
 </Select>
 <Input label="Order date" type="date" required {...register('date')} error={errors.date?.message} />
 <Input
 label="Payment terms (days)" type="number" min={0} max={365}
 {...register('payment_terms_days')} error={errors.payment_terms_days?.message}
 className="font-mono"
 />
 <Input label="Delivery terms" {...register('delivery_terms')} error={errors.delivery_terms?.message} placeholder="Enter delivery terms" />
 </div>
 </fieldset>

 <fieldset className="mb-8">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Line items</legend>
 <div className="border border-default rounded-md overflow-hidden">
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th className="w-1/2">Product</Th>
 <Th align="right">Quantity</Th>
 <Th align="right">Delivery date</Th>
 <Th />
 </tr>
 </thead>
 <tbody>
 {fields.map((field, i) => (
 <tr key={field.id} className={trCls}>
 <Td>
 <Select {...register(`items.${i}.product_id` as const)} error={errors.items?.[i]?.product_id?.message}>
 <option value="">Select product…</option>
 {products.data?.data.map((p) => (
 <option key={p.id} value={p.id}>{p.part_number} — {p.name}</option>
 ))}
 </Select>
 </Td>
 <Td align="right" mono>
 <Input
 {...register(`items.${i}.quantity` as const)}
 error={errors.items?.[i]?.quantity?.message}
 placeholder="0.00"
 className="font-mono text-right"
 />
 </Td>
 <Td align="right" mono>
 <Input
 type="date"
 {...register(`items.${i}.delivery_date` as const)}
 error={errors.items?.[i]?.delivery_date?.message}
 className="font-mono"
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
 ))}
 </tbody>
 </table>
 </div>

 <div className="mt-3">
 <Button
 type="button"
 variant="secondary"
 size="sm"
 icon={<LuPlus size={14} />}
 onClick={() => append({ product_id: '', quantity: '', delivery_date: '' })}
 >
 Add line
 </Button>
 </div>

 {errors.items?.message && <p className="mt-2 text-xs text-danger-fg">{errors.items.message as string}</p>}
 </fieldset>

 <fieldset className="mb-8">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Notes</legend>
 <Textarea rows={3} {...register('notes')} error={errors.notes?.message} placeholder="Optional internal notes." />
 </fieldset>

 <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
 <Button type="button" variant="secondary" onClick={() => navigate(`/crm/sales-orders/${id}`)}>
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
