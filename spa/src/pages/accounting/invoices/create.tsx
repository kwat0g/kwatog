import { useEffect, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useFieldArray, useForm } from 'react-hook-form';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { LuPlus, LuTrash2 } from '@/lib/icons';
import { customersApi } from '@/api/accounting/customers';
import { accountsApi } from '@/api/accounting/accounts';
import { invoicesApi } from '@/api/accounting/invoices';
import { businessPoliciesApi } from '@/api/businessPolicies';
import { uomsApi } from '@/api/inventory/uoms';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Switch } from '@/components/ui/Switch';
import { Panel } from '@/components/ui/Panel';
import { EmptyState } from '@/components/ui/EmptyState';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { numberInputProps } from '@/lib/numberInput';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';
import { localIsoDate } from '@/lib/formatDate';
const itemSchema = z.object({
 revenue_account_id: z.string().min(1, 'Required'),
 description: z.string().min(1, 'Required').max(200),
 quantity: z.coerce.number().positive('> 0'),
 unit: z.string().max(20).optional().or(z.literal('')),
 unit_price: z.coerce.number().min(0, '≥ 0'),
});

const schema = z.object({
 customer_id: z.string().min(1, 'Customer is required'),
 prebill_reason: z.string().trim().min(1, 'Prebill approval reason is required').max(1000),
 date: z.string().min(1, 'Date is required'),
 due_date: z.string().optional().or(z.literal('')),
 is_vatable: z.boolean(),
 remarks: z.string().max(1000).optional().or(z.literal('')),
 items: z.array(itemSchema).min(1, 'At least one item'),
});
type FormValues = z.infer<typeof schema>;

export default function InvoiceFormPage() {
 const navigate = useNavigate();
 const qc = useQueryClient();
 const [params] = useSearchParams();
 const { id } = useParams<{ id?: string }>();
 const isEditing = Boolean(id);
 const presetCustomer = params.get('customer_id') ?? '';

 const invoiceQuery = useQuery({
 queryKey: ['accounting', 'invoices', id],
 queryFn: () => invoicesApi.show(id!),
 enabled: isEditing,
 });
 const { data: customersResp } = useQuery({
 queryKey: ['accounting', 'customers', 'all'],
 queryFn: () => customersApi.list({ per_page: 200, is_active: true }),
 enabled: !isEditing,
 });
 const invoice = invoiceQuery.data;
 const { data: accountsResp } = useQuery({
 queryKey: ['accounting', 'accounts', 'revenue'],
 queryFn: () => accountsApi.list({ per_page: 200, type: 'revenue', is_active: true }),
 });
 const { data: policies } = useQuery({ queryKey: ['business-policies'], queryFn: businessPoliciesApi.get });
 const { data: uoms = [] } = useQuery({ queryKey: ['inventory', 'uoms'], queryFn: uomsApi.list, staleTime: 300_000 });
 const customers = customersResp?.data ?? [];
 const accounts = accountsResp?.data ?? [];
 const vatConfigured = policies?.vat_status === 'VAT Registered' && policies.vat_rate !== null;
 const vatRateLabel = vatConfigured ? `${(Number(policies.vat_rate) * 100).toLocaleString()}%` : '—';

 const form = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: {
 customer_id: presetCustomer, date: localIsoDate(),
 prebill_reason: '', due_date: '', is_vatable: undefined as unknown as boolean, remarks: '',
 items: [{ revenue_account_id: '', description: '', quantity: undefined as unknown as number, unit: '', unit_price: undefined as unknown as number }],
 },
 });
 const { register, control, handleSubmit, watch, setError, setValue, reset, formState: { errors, isSubmitting } } = form;
 const [loadedInvoiceId, setLoadedInvoiceId] = useState('');
 const [editRevision, setEditRevision] = useState('');
 const editReady = !isEditing || loadedInvoiceId === id;
 useEffect(() => {
 if (policies && !isEditing) setValue('is_vatable', vatConfigured);
 }, [isEditing, policies, setValue, vatConfigured]);
 useEffect(() => {
 if (!isEditing || !invoice || loadedInvoiceId === id) return;
 setEditRevision(invoice.revision ?? '');
 reset({
 customer_id: invoice.customer?.id ?? '',
 prebill_reason: invoice.prebill_reason ?? '',
 date: invoice.date,
 due_date: invoice.due_date ?? '',
 is_vatable: invoice.is_vatable,
 remarks: invoice.remarks ?? '',
 items: (invoice.items ?? []).map((item) => ({
 revenue_account_id: item.revenue_account?.id ?? '',
 description: item.description,
 quantity: Number(item.quantity),
 unit: item.unit ?? '',
 unit_price: Number(item.unit_price),
 })),
 });
 setLoadedInvoiceId(id ?? '');
 }, [id, invoice, isEditing, loadedInvoiceId, reset]);
 const { fields, append, remove } = useFieldArray({ control, name: 'items' });
 const items = watch('items');
 const isVatable = watch('is_vatable');

 const totals = (() => {
 let subtotal = 0;
 for (const it of items) subtotal += (Number(it.quantity) || 0) * (Number(it.unit_price) || 0);
 const vat = isVatable && vatConfigured ? subtotal * Number(policies.vat_rate) : 0;
 return { subtotal: subtotal.toFixed(2), vat: vat.toFixed(2), total: (subtotal + vat).toFixed(2) };
 })();

 const mutation = useMutation({
 mutationFn: (d: FormValues) => {
 const data = {
 customer_id: d.customer_id, date: d.date, due_date: d.due_date || undefined,
 lifecycle_type: 'prebill' as const, prebill_reason: d.prebill_reason,
 is_vatable: d.is_vatable, remarks: d.remarks || undefined,
 items: d.items.map((it) => ({
 revenue_account_id: it.revenue_account_id,
 description: it.description,
 quantity: String(it.quantity),
 unit: it.unit || undefined,
 unit_price: String(it.unit_price),
 })),
 };
 return isEditing
 ? invoicesApi.update(id!, { ...data, expected_revision: editRevision })
 : invoicesApi.create(data);
 },
 onSuccess: (inv) => {
 qc.invalidateQueries({ queryKey: ['accounting', 'invoices'] });
 toast.success(isEditing ? 'Draft invoice updated.' : 'Draft invoice saved.');
 navigate(`/accounting/invoices/${inv.id}`);
 },
 onError: (e) => {
   applyServerValidationErrors(e, setError, isEditing ? 'Failed to update the invoice.' : 'Failed to create the invoice.');
 },
 });
 const safety = useFormSafety({ form, saved: mutation.isSuccess });

 if (isEditing && (invoiceQuery.isLoading || (!invoice && !invoiceQuery.isError) || !editReady)) {
 return <div><PageHeader title="Edit invoice" backTo={`/accounting/invoices/${id}`} backLabel="Invoice" /><p role="status" className="p-5 text-sm text-muted">Loading invoice draft…</p></div>;
 }
 if (isEditing && invoiceQuery.isError) {
 return <EmptyState icon="alert-circle" title="Failed to load invoice draft" action={<Button variant="secondary" onClick={() => invoiceQuery.refetch()}>Retry</Button>} />;
 }
 if (isEditing && (!invoice || invoice.status !== 'draft' || invoice.lifecycle_type !== 'prebill')) {
 return <EmptyState icon="file-text" title="This invoice cannot be edited" description="Only manual prebill drafts can be edited here." action={<Button variant="secondary" onClick={() => navigate(`/accounting/invoices/${id}`)}>Back to invoice</Button>} />;
 }
 if (isEditing && !editRevision) {
 return <EmptyState icon="alert-circle" title="Invoice revision unavailable" description="Reload the invoice before editing." action={<Button variant="secondary" onClick={() => invoiceQuery.refetch()}>Retry</Button>} />;
 }

 return (
 <div>
 <PageHeader title={isEditing ? 'Edit invoice' : 'New invoice'} backTo={isEditing ? `/accounting/invoices/${id}` : '/accounting/invoices'} backLabel={isEditing ? 'Invoice' : 'Invoices'}
 />
      <FormDraftBanner safety={safety} />
 <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())} className="min-w-0 w-full px-5 py-4 space-y-4">
 <Panel title="Header">
 <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
 {isEditing ? (
 <>
 <Input label="Customer" value={invoice?.customer?.name ?? '—'} disabled />
 <input type="hidden" {...register('customer_id')} />
 </>
 ) : (
 <Select searchable label="Customer" required {...register('customer_id')} error={errors.customer_id?.message}>
 <option value="">— Select customer —</option>
 {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
 </Select>
 )}
 <Input label="Date" type="date" required {...register('date')} error={errors.date?.message} />
 <Input label="Due date" type="date" {...register('due_date')} error={errors.due_date?.message} />
 <Textarea label="Prebill approval reason" required rows={2} className="col-span-3" {...register('prebill_reason')} error={errors.prebill_reason?.message} helper="Manual invoices need approval from another authorized user before they can be finalized. Delivery-generated invoices follow the confirmed-delivery lifecycle." />
 <div className="flex items-end col-span-2"><Switch label={`VAT-able (${vatRateLabel})`} disabled={!vatConfigured} {...register('is_vatable')} /></div>
 <div />
 <Textarea label="Remarks" rows={2} className="col-span-3" {...register('remarks')} error={errors.remarks?.message} />
 </div>
 <p className="text-xs text-muted mt-2">This manual invoice stays pending until another authorized user approves it. A different authorized user must finalize it before a journal entry is posted.</p>
 </Panel>

 <Panel title="Line items">
 <div className="border border-default rounded-md overflow-hidden">
 <div className="hidden md:grid md:grid-cols-12 gap-2 h-row px-2.5 bg-subtle text-2xs uppercase tracking-wider text-muted font-medium border-b border-default items-center">
 <div className="col-span-3">Description</div>
 <div className="col-span-3">Revenue account</div>
 <div className="col-span-1 text-right">Qty</div>
 <div className="col-span-1">Unit</div>
 <div className="col-span-2 text-right">Unit price</div>
 <div className="col-span-1 text-right">Total</div>
 <div className="col-span-1" />
 </div>
 {fields.map((field, idx) => {
 const it = items[idx] ?? ({} as FormValues['items'][number]);
 const lineTotal = ((Number(it.quantity) || 0) * (Number(it.unit_price) || 0)).toFixed(2);
 return (
 <div key={field.id} className="grid grid-cols-1 md:grid-cols-12 gap-2 px-2.5 py-1.5 border-b border-subtle items-start">
 <div className="col-span-3"><Input aria-label={`Description for line ${idx + 1}`} {...register(`items.${idx}.description` as const)} /></div>
 <div className="col-span-3">
 <Select searchable aria-label={`Revenue account for line ${idx + 1}`} {...register(`items.${idx}.revenue_account_id` as const)}>
 <option value="">— Account —</option>
 {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
 </Select>
 </div>
 <div className="col-span-1"><Input aria-label={`Quantity for line ${idx + 1}`} min="0.01" className="font-mono tabular-nums text-right" {...numberInputProps()} step="0.01" {...register(`items.${idx}.quantity` as const)} /></div>
 <div className="col-span-1">
 <Select aria-label={`Unit for line ${idx + 1}`} {...register(`items.${idx}.unit` as const)}>
 <option value="">—</option>
 {uoms.map((uom) => <option key={uom.id} value={uom.code}>{uom.code}</option>)}
 </Select>
 </div>
 <div className="col-span-2"><Input aria-label={`Unit price for line ${idx + 1}`} min="0" className="font-mono tabular-nums text-right" {...numberInputProps()} step="0.01" {...register(`items.${idx}.unit_price` as const)} /></div>
 <div className="col-span-1 pt-1.5 text-right font-mono tabular-nums text-sm">{formatPeso(lineTotal)}</div>
 <div className="col-span-1 flex justify-end pt-1.5">
 {fields.length > 1 && (
 <Button type="button" variant="ghost" size="sm" iconOnly icon={<LuTrash2 size={14} />}
 aria-label="Remove line" onClick={() => remove(idx)} className="text-muted hover:text-danger-fg" />
 )}
 </div>
 </div>
 );
 })}
 </div>
 <div className="flex items-center justify-between mt-3">
 <Button type="button" variant="secondary" size="sm" icon={<LuPlus size={14} />} onClick={() => append({ revenue_account_id: '', description: '', quantity: undefined as unknown as number, unit: '', unit_price: undefined as unknown as number })}>
 Add line
 </Button>
 <div className="text-sm font-mono tabular-nums">
 <div className="text-muted">Subtotal: <span className="text-primary">{formatPeso(totals.subtotal)}</span></div>
 <div className="text-muted">VAT: <span className="text-primary">{formatPeso(totals.vat)}</span></div>
 <div className="text-base font-medium">Total: {formatPeso(totals.total)}</div>
 </div>
 </div>
 </Panel>

 <FormActions>
 <Button type="button" variant="secondary" onClick={() => navigate(isEditing ? `/accounting/invoices/${id}` : '/accounting/invoices')}>Cancel</Button>
 <Button type="submit" variant="primary" loading={mutation.isPending} disabled={isSubmitting || mutation.isPending}>{isEditing ? 'Save changes' : 'Save draft'}</Button>
 </FormActions>
 </form>
 </div>
 );
}
