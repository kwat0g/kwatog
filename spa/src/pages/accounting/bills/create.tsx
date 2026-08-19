import { useEffect, useMemo } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useFieldArray, useForm } from 'react-hook-form';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { LuPlus, LuTrash2 } from '@/lib/icons';
import { vendorsApi } from '@/api/accounting/vendors';
import { accountsApi } from '@/api/accounting/accounts';
import { billsApi } from '@/api/accounting/bills';
import { purchaseOrdersApi } from '@/api/purchasing/purchase-orders';
import { businessPoliciesApi } from '@/api/businessPolicies';
import { uomsApi } from '@/api/inventory/uoms';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Switch } from '@/components/ui/Switch';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { numberInputProps } from '@/lib/numberInput';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';
const itemSchema = z.object({
 expense_account_id: z.string().min(1, 'Required'),
 // REC-02 — hidden PO item FK; empty for manually added free-text lines.
 item_id: z.string().optional().or(z.literal('')),
 description: z.string().min(1, 'Required').max(200),
 quantity: z.coerce.number().positive('> 0'),
 unit: z.string().max(20).optional().or(z.literal('')),
 unit_price: z.coerce.number().min(0, '≥ 0'),
});

const schema = z.object({
 bill_number: z.string().min(1).max(50),
 vendor_id: z.string().min(1, 'Vendor is required'),
 provenance_type: z.enum(['stock', 'service']),
 purchase_order_id: z.string().optional().or(z.literal('')),
 goods_receipt_note_id: z.string().optional().or(z.literal('')),
 exception_evidence: z.string().max(2000).optional().or(z.literal('')),
 exception_approved: z.boolean().default(false),
 allow_override: z.boolean().default(false),
 date: z.string().min(1, 'Date is required'),
 due_date: z.string().optional().or(z.literal('')),
 is_vatable: z.boolean(),
 remarks: z.string().max(1000).optional().or(z.literal('')),
 items: z.array(itemSchema).min(1, 'At least one item'),
}).superRefine((data, ctx) => {
 if (data.provenance_type === 'stock') {
  if (!data.purchase_order_id) ctx.addIssue({ code: 'custom', path: ['purchase_order_id'], message: 'Purchase order is required' });
  if (!data.goods_receipt_note_id) ctx.addIssue({ code: 'custom', path: ['goods_receipt_note_id'], message: 'Accepted GRN is required' });
 } else {
  if (!data.exception_evidence?.trim()) ctx.addIssue({ code: 'custom', path: ['exception_evidence'], message: 'Evidence is required' });
  if (!data.exception_approved) ctx.addIssue({ code: 'custom', path: ['exception_approved'], message: 'Explicit approval is required' });
 }
});
type FormValues = z.infer<typeof schema>;

export default function CreateBillPage() {
 const navigate = useNavigate();
 const qc = useQueryClient();
 const [params] = useSearchParams();
 const presetVendor = params.get('vendor_id') ?? '';

 const { data: vendorsResp } = useQuery({
 queryKey: ['accounting', 'vendors', 'all'],
 queryFn: () => vendorsApi.list({ per_page: 200, is_active: true }),
 });
 const { data: accountsResp } = useQuery({
 queryKey: ['accounting', 'accounts', 'expense'],
 queryFn: () => accountsApi.list({ per_page: 200, type: 'expense', is_active: true }),
 });
 // REC-02 — billable POs for the optional link selector. The API exposes the
 // authoritative billability rule alongside each purchase order.
 const { data: posResp } = useQuery({
 queryKey: ['purchasing', 'purchase-orders', 'billable'],
 queryFn: () => purchaseOrdersApi.list({ per_page: 200 }),
 });
 const { data: policies } = useQuery({ queryKey: ['business-policies'], queryFn: businessPoliciesApi.get });
 const { data: uoms = [] } = useQuery({ queryKey: ['inventory', 'uoms'], queryFn: uomsApi.list, staleTime: 300_000 });
 const vatConfigured = policies?.vat_status === 'VAT Registered' && policies.vat_rate !== null;
 const vatRateLabel = vatConfigured ? `${(Number(policies.vat_rate) * 100).toLocaleString()}%` : '—';
 const vendors = useMemo(() => vendorsResp?.data ?? [], [vendorsResp]);
 const accounts = accountsResp?.data ?? [];
 const pos = useMemo(
 () => (posResp?.data ?? []).filter((po) => po.is_billable === true),
 [posResp],
 );

  const form = useForm<FormValues>({
 resolver: zodResolver(schema),
 defaultValues: {
 bill_number: '', vendor_id: presetVendor, provenance_type: 'stock', purchase_order_id: '', goods_receipt_note_id: '',
 exception_evidence: '', exception_approved: false, allow_override: false,
 date: new Date().toISOString().slice(0, 10),
 due_date: '', is_vatable: undefined as unknown as boolean, remarks: '',
 items: [{ expense_account_id: '', item_id: '', description: '', quantity: undefined as unknown as number, unit: '', unit_price: undefined as unknown as number }],
 },
 });
 const { register, control, handleSubmit, watch, setError, setValue, getValues, formState: { errors, isSubmitting } } = form;
 useEffect(() => {
 if (policies) setValue('is_vatable', vatConfigured);
 }, [policies, setValue, vatConfigured]);
 const { fields, append, remove, replace } = useFieldArray({ control, name: 'items' });
 const items = watch('items');
 const isVatable = watch('is_vatable');
 const purchaseOrderId = watch('purchase_order_id');
 const provenanceType = watch('provenance_type');

 // REC-02 — when a PO is picked, fetch it and prefill the vendor + line items
 // (one bill line per PO item, carrying the item_id FK for match alignment).
 const { data: selectedPo } = useQuery({
 queryKey: ['purchasing', 'purchase-orders', purchaseOrderId],
 queryFn: () => purchaseOrdersApi.show(purchaseOrderId as string),
 enabled: !!purchaseOrderId,
 });
 useEffect(() => {
 if (!purchaseOrderId || !selectedPo || selectedPo.id !== purchaseOrderId) return;
 // Prefill vendor from the PO (leave user free to change it afterward).
 if (selectedPo.vendor?.id) setValue('vendor_id', selectedPo.vendor.id, { shouldValidate: true, shouldDirty: true });
 const poItems = selectedPo.items ?? [];
 if (poItems.length > 0) {
 replace(poItems.map((pi) => {
 const remaining = Number(pi.quantity_remaining);
 const qty = remaining > 0 ? remaining : Number(pi.quantity);
 return {
 expense_account_id: '',
 item_id: pi.item?.id ?? '',
 description: pi.description || pi.item?.name || '',
 quantity: qty,
 unit: pi.unit ?? '',
 unit_price: Number(pi.unit_price),
 };
 }));
 }
 // eslint-disable-next-line react-hooks/exhaustive-deps
 }, [selectedPo, purchaseOrderId]);
 const acceptedGrns = useMemo(
 () => (selectedPo?.goods_receipt_notes ?? []).filter((grn) => grn.status === 'accepted'),
 [selectedPo],
 );
 useEffect(() => {
  setValue('goods_receipt_note_id', acceptedGrns.length === 1 ? acceptedGrns[0].id : '');
 }, [acceptedGrns, setValue]);

 // Auto-fill due_date when vendor changes (use payment_terms_days). Use
 // setValue (RHF API) so the field is properly tracked; the previous
 // document.querySelector + setAttribute hack only mutated the DOM
 // attribute and did not update form state.
 const vendorId = watch('vendor_id');
 const date = watch('date');
 useEffect(() => {
 if (!vendorId || !date) return;
 const v = vendors.find((x) => x.id === vendorId);
 if (!v) return;
 // Don't overwrite a manually edited due_date.
 if (getValues('due_date')) return;
 const d = new Date(date);
 d.setDate(d.getDate() + v.payment_terms_days);
 setValue('due_date', d.toISOString().slice(0, 10), { shouldValidate: false, shouldDirty: true });
 }, [vendorId, date, vendors, getValues, setValue]);

 const totals = useMemo(() => {
 let subtotal = 0;
 for (const it of items) subtotal += (Number(it.quantity) || 0) * (Number(it.unit_price) || 0);
 const vat = isVatable && vatConfigured ? subtotal * Number(policies.vat_rate) : 0;
 return { subtotal: subtotal.toFixed(2), vat: vat.toFixed(2), total: (subtotal + vat).toFixed(2) };
 }, [items, isVatable, policies, vatConfigured]);

 const mutation = useMutation({
 mutationFn: (d: FormValues) => billsApi.create({
 bill_number: d.bill_number,
 vendor_id: d.vendor_id,
 provenance_type: d.provenance_type,
 purchase_order_id: d.provenance_type === 'stock' ? d.purchase_order_id || undefined : undefined,
 goods_receipt_note_id: d.provenance_type === 'stock' ? d.goods_receipt_note_id || undefined : undefined,
 exception_evidence: d.provenance_type === 'service' ? d.exception_evidence?.trim() || undefined : undefined,
 exception_approved: d.provenance_type === 'service' ? d.exception_approved : undefined,
 allow_override: d.purchase_order_id ? d.allow_override : undefined,
 date: d.date,
 due_date: d.due_date || undefined,
 is_vatable: d.is_vatable,
 remarks: d.remarks || undefined,
 items: d.items.map((it) => ({
 expense_account_id: it.expense_account_id,
 item_id: it.item_id || undefined,
 description: it.description,
 quantity: String(it.quantity),
 unit: it.unit || undefined,
 unit_price: String(it.unit_price),
 })),
 }),
 onSuccess: (b) => {
 qc.invalidateQueries({ queryKey: ['accounting', 'bills'] });
 toast.success(`Bill ${b.bill_number} recorded.`);
 navigate(`/accounting/bills/${b.id}`);
 },
 onError: (e) => {
   applyServerValidationErrors(e, setError, 'Failed to create the bill.');
 },
 });
 const safety = useFormSafety({ form, saved: mutation.isSuccess });

 return (
 <div>
 <PageHeader title="New bill" backTo="/accounting/bills" backLabel="Bills" />
      <FormDraftBanner safety={safety} />
 <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())} className="max-w-5xl mx-auto px-5 py-4 space-y-4">
 <Panel title="Header">
 <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
 <Select label="Bill provenance" required {...register('provenance_type')} error={errors.provenance_type?.message}>
 <option value="stock">Stock/item — PO + accepted GRN</option>
 <option value="service">Service/non-stock — approved exception</option>
 </Select>
 {provenanceType === 'stock' ? <>
 <Select label="Purchase order" required {...register('purchase_order_id')} error={errors.purchase_order_id?.message} helper="The accepted receipt and 3-way match are checked against this PO.">
 <option value="">— Select purchase order —</option>
 {pos.map((po) => <option key={po.id} value={po.id}>{po.po_number}{po.vendor ? ` · ${po.vendor.name}` : ''}</option>)}
 </Select>
 <Select label="Accepted GRN" required {...register('goods_receipt_note_id')} error={errors.goods_receipt_note_id?.message} disabled={!purchaseOrderId}>
 <option value="">— Select accepted GRN —</option>
 {acceptedGrns.map((grn) => <option key={grn.id} value={grn.id}>{grn.grn_number}</option>)}
 </Select>
 </> : <>
 <Textarea label="Service evidence" required rows={2} className="col-span-2" {...register('exception_evidence')} error={errors.exception_evidence?.message} helper="Contract, completion report, timesheet, or other evidence supporting this non-stock bill." />
 <div className="flex items-end">
 <Switch label="Approve exception" description="I approve this service/non-stock exception and accept ownership of the evidence." {...register('exception_approved')} />
 </div>
 </>}
 <Select label="Vendor" required {...register('vendor_id')} error={errors.vendor_id?.message}>
 <option value="">— Select vendor —</option>
 {vendors.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
 </Select>
 <Input label="Vendor bill no." required {...register('bill_number')} error={errors.bill_number?.message} />
 <Input label="Date" type="date" required {...register('date')} error={errors.date?.message} />
 <Input label="Due date" type="date" {...register('due_date')} error={errors.due_date?.message} />
 <div className="flex items-end">
 <Switch label={`VAT-able (${vatRateLabel})`} disabled={!vatConfigured} {...register('is_vatable')} />
 </div>
 {provenanceType === 'stock' && purchaseOrderId && (
 <div className="col-span-3">
 <Switch
 label="Allow override"
 description="Post the bill even if the 3-way match flags a blocking variance. The override is recorded in the audit trail."
 {...register('allow_override')}
 />
 </div>
 )}
 <Textarea label="Remarks" rows={2} className="col-span-3" {...register('remarks')} error={errors.remarks?.message} />
 </div>
 </Panel>

 <Panel title="Line items">
 <div className="border border-default rounded-md overflow-hidden">
 <div className="hidden md:grid md:grid-cols-12 gap-2 h-row px-2.5 bg-subtle text-2xs uppercase tracking-wider text-muted font-medium border-b border-default items-center">
 <div className="col-span-3">Description</div>
 <div className="col-span-3">Expense account</div>
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
 {/* REC-02 — hidden PO item FK carried through to the payload. */}
 <input type="hidden" {...register(`items.${idx}.item_id` as const)} />
 <div className="col-span-3"><Input {...register(`items.${idx}.description` as const)} /></div>
 <div className="col-span-3">
 <Select {...register(`items.${idx}.expense_account_id` as const)}>
 <option value="">— Account —</option>
 {accounts.map((a) => <option key={a.id} value={a.id}>{a.code} — {a.name}</option>)}
 </Select>
 </div>
 <div className="col-span-1"><Input step="0.01" min="0.01" className="font-mono tabular-nums text-right" {...numberInputProps()} {...register(`items.${idx}.quantity` as const)} /></div>
 <div className="col-span-1">
 <Select {...register(`items.${idx}.unit` as const)}>
 <option value="">—</option>
 {uoms.map((uom) => <option key={uom.id} value={uom.code}>{uom.code}</option>)}
 </Select>
 </div>
 <div className="col-span-2"><Input step="0.01" min="0" className="font-mono tabular-nums text-right" {...numberInputProps()} {...register(`items.${idx}.unit_price` as const)} /></div>
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
 <Button type="button" variant="secondary" size="sm" icon={<LuPlus size={14} />} onClick={() => append({ expense_account_id: '', item_id: '', description: '', quantity: undefined as unknown as number, unit: '', unit_price: undefined as unknown as number })}>
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
 <Button type="button" variant="secondary" onClick={() => navigate('/accounting/bills')}>Cancel</Button>
 <Button type="submit" variant="primary" loading={mutation.isPending} disabled={isSubmitting || mutation.isPending}>
 Create bill
 </Button>
 </FormActions>
 </form>
 </div>
 );
}
