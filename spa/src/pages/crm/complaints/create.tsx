/** Sprint 7 — Task 68 — File a customer complaint. */
import { useForm } from 'react-hook-form';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { useState } from 'react';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { complaintsApi } from '@/api/crm/complaints';
import { customersApi } from '@/api/accounting/customers';
import { productsApi } from '@/api/crm/products';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import type { CreateComplaintData, ComplaintSeverity } from '@/types/crm';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';
import { useDebounce } from '@/hooks/useDebounce';
const schema = z.object({
 customer_id: z.string().min(1, 'Customer is required'),
 product_id: z.string().optional().or(z.literal('')),
 sales_order_id: z.string().optional().or(z.literal('')),
 received_date: z.string().min(1, 'Received date is required'),
 severity: z.string().min(1, 'Severity is required'),
 description: z.string().min(1, 'Description is required').max(5000),
 affected_quantity: z.coerce.number().int().min(0).default(0),
});

type FormValues = z.infer<typeof schema>;

export default function CreateComplaintPage() {
 const navigate = useNavigate();
 const [selectedCustomerId, setSelectedCustomerId] = useState('');
 const [customerSearch, setCustomerSearch] = useState('');
 const [productSearch, setProductSearch] = useState('');
 const [salesOrderSearch, setSalesOrderSearch] = useState('');
 const debouncedCustomerSearch = useDebounce(customerSearch, 300);
 const debouncedProductSearch = useDebounce(productSearch, 300);
 const debouncedSalesOrderSearch = useDebounce(salesOrderSearch, 300);

 const customers = useQuery({
 queryKey: ['accounting', 'customers', 'complaint-lookup', debouncedCustomerSearch],
 queryFn: () => customersApi.list({
 per_page: 100,
 is_active: true,
 search: debouncedCustomerSearch || undefined,
 }),
 });
 const products = useQuery({
 queryKey: ['crm', 'products', 'complaint-lookup', debouncedProductSearch],
 queryFn: () => productsApi.list({
 is_active: true,
 per_page: 100,
 search: debouncedProductSearch || undefined,
 }),
 });
 const salesOrders = useQuery({
  queryKey: ['crm', 'sales-orders', 'complaint-lookup', selectedCustomerId, debouncedSalesOrderSearch],
  queryFn: () => salesOrdersApi.list({
   customer_id: selectedCustomerId,
   search: debouncedSalesOrderSearch || undefined,
   per_page: 100,
  }),
 enabled: Boolean(selectedCustomerId),
 });
 const complaintOptions = useQuery({
 queryKey: ['crm', 'complaints', 'options'],
 queryFn: () => complaintsApi.options(),
 });

  const form = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: {
 customer_id: '',
 product_id: '',
 sales_order_id: '',
 received_date: new Date().toISOString().slice(0, 10),
 severity: '',
 description: '',
 affected_quantity: 0,
 },
 });
 const {
 register, handleSubmit, setError, setValue, formState: { errors },
 } = form;

 const submit = useMutation({
 mutationFn: (data: CreateComplaintData) => complaintsApi.create(data),
 onSuccess: (c) => {
 toast.success(`Complaint ${c.complaint_number} opened${c.ncr ? `; NCR ${c.ncr.ncr_number} auto-created` : ''}`);
 navigate(`/crm/complaints/${c.id}`);
 },
 onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
 if (e.response?.data?.errors) {
 applyServerValidationErrors(e.response.data.errors, setError);
 toast.error(e.response?.data?.message || 'Validation failed.');
 } else {
 toast.error(e.response?.data?.message ?? 'Failed to open complaint');
 }
 },
 });
 const safety = useFormSafety({ form, saved: submit.isSuccess });

 return (
 <div>
 <PageHeader title="File complaint" subtitle="An NCR will be auto-created on submit." backTo="/crm/complaints" backLabel="Complaints"
 />
      <FormDraftBanner safety={safety} />
 <form
 onSubmit={handleSubmit((v) =>
 submit.mutate({
 customer_id: v.customer_id,
 product_id: v.product_id || null,
 sales_order_id: v.sales_order_id || null,
 received_date: v.received_date,
 severity: v.severity as ComplaintSeverity,
 description: v.description,
 affected_quantity: Number(v.affected_quantity),
 })
 , onFormInvalid<FormValues>())}
 className="px-5 py-4 max-w-3xl"
 >
 <div className="space-y-4">
 <Panel title="Subject">
 <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
 <div className="space-y-2">
 <Input
 label="Find customer"
 value={customerSearch}
 onChange={(e) => setCustomerSearch(e.target.value)}
 placeholder="Name or contact…"
 />
 <Select
 label="Customer"
 required
 {...register('customer_id', {
 onChange: (e) => {
  setSelectedCustomerId(e.target.value);
  setValue('sales_order_id', '');
  setSalesOrderSearch('');
 },
 })}
 error={errors.customer_id?.message}
 >
 <option value="">Select…</option>
 {customers.data?.data?.map((c) => (
 <option key={c.id} value={c.id}>{c.name}</option>
 ))}
 </Select>
 </div>
 <div className="space-y-2">
 <Input
 label="Find product"
 value={productSearch}
 onChange={(e) => setProductSearch(e.target.value)}
 placeholder="Part number or name…"
 />
 <Select label="Product (optional)" {...register('product_id')} error={errors.product_id?.message}>
 <option value="">— None —</option>
 {products.data?.data?.map((p) => (
 <option key={p.id} value={p.id}>{p.part_number} — {p.name}</option>
 ))}
 </Select>
 </div>
 <div className="space-y-2">
 <Input
  label="Find sales order"
  value={salesOrderSearch}
  onChange={(e) => setSalesOrderSearch(e.target.value)}
  placeholder="SO number…"
  disabled={!selectedCustomerId}
 />
 <Select label="Sales order (optional)" {...register('sales_order_id')} error={errors.sales_order_id?.message}>
 <option value="">— None —</option>
 {(salesOrders.data?.data ?? [])
  .filter((order) => order.status !== 'cancelled')
  .map((order) => (
  <option key={order.id} value={order.id}>{order.so_number} — {order.date}</option>
  ))}
 </Select>
 </div>
 </div>
 </Panel>

 <Panel title="Classification">
 <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
 <Input label="Received date" type="date" required
 {...register('received_date')} error={errors.received_date?.message} />
 <Select label="Severity" required {...register('severity')} error={errors.severity?.message}>
 <option value="">— Select —</option>
 {(complaintOptions.data?.severities ?? []).map((severity) => <option key={severity.value} value={severity.value}>{severity.label}</option>)}
 </Select>
 <Input label="Affected quantity" type="number" min={0}
 {...register('affected_quantity')} error={errors.affected_quantity?.message} />
 </div>
 </Panel>

 <Panel title="Description">
 <Textarea label="Customer complaint" required rows={6}
 {...register('description')} error={errors.description?.message} />
 </Panel>

 <FormActions>
 <Button variant="secondary" type="button" onClick={() => navigate(-1)}>Cancel</Button>
 <Button variant="primary" type="submit" loading={submit.isPending}>
 {submit.isPending ? 'Opening...' : 'Open complaint'}
 </Button>
 </FormActions>
 </div>
 </form>
 </div>
 );
}
