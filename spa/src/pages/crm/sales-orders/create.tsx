/**
 * Sprint 6 — Task 48 — Create Sales Order page.
 *
 * Multi-section form:
 * - Customer + order date + payment terms + delivery terms
 * - Line items (product + quantity + per-line delivery date)
 * - Notes
 * Pricing is resolved server-side from the active price agreement at the
 * delivery_date for each line — that's why the form does not show a price
 * input. Server returns 422 with field-targeted errors when no agreement
 * exists; those land on the offending row.
 */
import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
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
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { customersApi } from '@/api/accounting/customers';
import { productsApi } from '@/api/crm/products';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import { businessPoliciesApi } from '@/api/businessPolicies';
import type { CreateSalesOrderData } from '@/types/crm';
import { formatPeso } from '@/lib/formatNumber';
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

export default function CreateSalesOrderPage() {
 const navigate = useNavigate();
 const qc = useQueryClient();

 const customers = useQuery({
 queryKey: ['accounting', 'customers', 'lookup'],
 queryFn: () => customersApi.list({ per_page: 100, is_active: 'true' }),
 });
 const products = useQuery({
 queryKey: ['crm', 'products', 'lookup'],
 queryFn: () => productsApi.list({ per_page: 100, is_active: 'true' }),
 });
 const policies = useQuery({ queryKey: ['business-policies'], queryFn: businessPoliciesApi.get });

 const today = new Date().toISOString().slice(0, 10);

 const {
 register, control, handleSubmit, setError, setValue, watch,
 formState: { errors, isSubmitting },
 } = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: {
 customer_id: '',
 date: today,
 payment_terms_days: '',
 delivery_terms: '',
 notes: '',
 items: [{ product_id: '', quantity: '', delivery_date: '' }],
 },
 });
 const { fields, append, remove } = useFieldArray({ control, name: 'items' });
 const selectedCustomerId = watch('customer_id');

 useEffect(() => {
 const customer = customers.data?.data.find((row) => row.id === selectedCustomerId);
 if (customer) {
 setValue('payment_terms_days', String(customer.payment_terms_days));
 } else if (policies.data) {
 setValue('payment_terms_days', String(policies.data.customer_payment_terms_days));
 }
 }, [customers.data, policies.data, selectedCustomerId, setValue]);

 const [submitMode, setSubmitMode] = useState<'draft' | 'confirm'>('draft');

 const create = useMutation({
 mutationFn: async (values: FormValues) => {
 const payload: CreateSalesOrderData = {
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
 const so = await salesOrdersApi.create(payload);
 if (submitMode === 'confirm') {
 // confirm() returns { data, chain_result } — unwrap to the SalesOrder
 // so the mutation result type is always SalesOrder.
 const confirmed = await salesOrdersApi.confirm(so.id);
 return confirmed.data;
 }
 return so;
 },
 onSuccess: (so) => {
 qc.invalidateQueries({ queryKey: ['crm', 'sales-orders'] });
 toast.success(submitMode === 'confirm' ? `Sales order ${so.so_number} confirmed.` : `Draft ${so.so_number} created.`);
 navigate(`/crm/sales-orders/${so.id}`);
 },
 onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
 if (e.response?.status === 422 && e.response.data.errors) {
 Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
 // Map Laravel-style nested keys like items.0.product_id back to RHF paths.
 setError(field as never, { type: 'server', message: msgs[0] });
 });
 toast.error(e.response?.data?.message || 'Validation failed.');
 } else {
 toast.error(e.response?.data?.message ?? 'Failed to save sales order.');
 }
 },
 });

 // Live preview of subtotal (best-effort: pulls unit_price from product list — server
 // re-resolves from the actual price agreement on save, so this is approximate).
 const watchedItems = watch('items');
 const previewSubtotal = useMemo(() => {
 let total = 0;
 for (const it of watchedItems) {
 const qty = Number(it.quantity || 0);
 if (qty > 0 && it.product_id) {
 const p = products.data?.data.find((pp) => pp.id === it.product_id);
 if (p) total += qty * Number(p.standard_cost || 0);
 }
 }
 return total;
 }, [watchedItems, products.data]);

 return (
 <div>
 <PageHeader title="New sales order" backTo="/crm/sales-orders" backLabel="Sales orders"
 />
 <form
 onSubmit={handleSubmit((v) => create.mutate(v), onFormInvalid<FormValues>())}
 className="max-w-4xl mx-auto px-5 py-4"
 >
 <fieldset className="mb-8">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Order header</legend>
 <div className="grid grid-cols-2 gap-3">
 <Select label="Customer" required {...register('customer_id')} error={errors.customer_id?.message}>
 <option value="">Select customer…</option>
 {customers.data?.data.map((c) => (
 <option key={c.id} value={c.id}>{c.name}</option>
 ))}
 </Select>
 <Input
 label="Order date" type="date" required
 {...register('date')} error={errors.date?.message}
 />
 <Input
 label="Payment terms (days)" type="number" min={0} max={365}
 {...register('payment_terms_days')} error={errors.payment_terms_days?.message}
 className="font-mono"
 />
 <Input
 label="Delivery terms"
 {...register('delivery_terms')} error={errors.delivery_terms?.message}
 placeholder="Enter delivery terms"
 />
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
 <Select
 {...register(`items.${i}.product_id` as const)}
 error={errors.items?.[i]?.product_id?.message}
 >
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

 <div className="mt-3 flex items-center justify-between">
 <Button
 type="button"
 variant="secondary"
 size="sm"
 icon={<LuPlus size={14} />}
 onClick={() => append({ product_id: '', quantity: '', delivery_date: '' })}
 >
 Add line
 </Button>

 <div className="text-xs text-muted">
 Estimate (uses standard cost): <span className="font-mono tabular-nums text-primary">
 {formatPeso(previewSubtotal)}
 </span>
 <div className="text-2xs">Final pricing pulled from active price agreement on save.</div>
 </div>
 </div>

 {errors.items?.message && (
 <p className="mt-2 text-xs text-danger-fg">{errors.items.message as string}</p>
 )}
 </fieldset>

 <fieldset className="mb-8">
 <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Notes</legend>
 <Textarea
 rows={3}
 {...register('notes')}
 error={errors.notes?.message}
 placeholder="Optional internal notes."
 />
 </fieldset>

 <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
 <Button type="button" variant="secondary" onClick={() => navigate('/crm/sales-orders')}>
 Cancel
 </Button>
 <Button
 type="submit"
 variant="secondary"
 disabled={isSubmitting || create.isPending}
 loading={create.isPending && submitMode === 'draft'}
 onClick={() => setSubmitMode('draft')}
 >
 {create.isPending && submitMode === 'draft' ? 'Saving…' : 'Save as draft'}
 </Button>
 <Button
 type="submit"
 variant="primary"
 disabled={isSubmitting || create.isPending}
 loading={create.isPending && submitMode === 'confirm'}
 onClick={() => setSubmitMode('confirm')}
 >
 {create.isPending && submitMode === 'confirm' ? 'Confirming…' : 'Save & confirm'}
 </Button>
 </div>
 </form>
 </div>
 );
}
