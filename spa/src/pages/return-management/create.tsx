import { useEffect, useMemo } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
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
import type { ReturnRequest, ReturnRequestFormData, ReturnSourceLine } from '@/types/returnManagement';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid, applyServerValidationErrors } from '@/lib/formErrors';
import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';

const optionalNumber = z.preprocess(
  (value) => (value === '' || value === null ? undefined : value),
  z.coerce.number().min(0, 'Must be zero or greater').optional(),
);

const itemSchema = z.object({
  product_id: z.string().optional(),
  item_id: z.string().optional(),
  source_line: z.string().optional(),
  bill_line: z.string().optional(),
  quantity: z.coerce.number().min(0.001, 'Min 0.001'),
  unit_price: optionalNumber,
  reason: z.string().optional(),
  condition: z.string().optional(),
  lot_number: z.string().optional(),
  serial_number: z.string().optional(),
});

const schema = z.object({
  type: z.string().min(1, 'Select a return type'),
  return_date: z.string().min(1, 'Required'),
  customer_id: z.string().optional(),
  vendor_id: z.string().optional(),
  invoice_id: z.string().optional(),
  sales_order_id: z.string().optional(),
  purchase_order_id: z.string().optional(),
  bill_id: z.string().optional(),
  finance_only: z.boolean().optional(),
  finance_only_reason: z.string().optional(),
  reason_code: z.string().min(1, 'Select a reason'),
  reason_description: z.string().optional(),
  customer_notes: z.string().optional(),
  internal_notes: z.string().optional(),
  resolution: z.string().optional(),
  items: z.array(itemSchema).min(1, 'Add at least one item'),
}).superRefine((data, ctx) => {
  const customerReturn = data.type === 'customer_return';
  const financeOnly = customerReturn && data.finance_only === true;

  if (customerReturn && !data.customer_id) {
    ctx.addIssue({ code: 'custom', path: ['customer_id'], message: 'Select the customer returning the goods' });
  }
  if (!customerReturn && !data.vendor_id) {
    ctx.addIssue({ code: 'custom', path: ['vendor_id'], message: 'Select the supplier the goods go back to' });
  }
  if (financeOnly && !data.finance_only_reason?.trim()) {
    ctx.addIssue({ code: 'custom', path: ['finance_only_reason'], message: 'Explain why no goods are returning' });
  }

  data.items.forEach((line, index) => {
    if (financeOnly) {
      if (!line.product_id) {
        ctx.addIssue({ code: 'custom', path: ['items', index, 'product_id'], message: 'Select the credited product' });
      }
      if (line.unit_price === undefined) {
        ctx.addIssue({ code: 'custom', path: ['items', index, 'unit_price'], message: 'Enter the credit unit price' });
      }
      return;
    }
    if (customerReturn && !line.item_id) {
      ctx.addIssue({ code: 'custom', path: ['items', index, 'item_id'], message: 'Select the inventory item received back' });
    }
    if (customerReturn && !line.source_line) {
      ctx.addIssue({ code: 'custom', path: ['items', index, 'source_line'], message: 'Select the invoice, order, or delivery line' });
    }
    if (!customerReturn && !line.item_id) {
      ctx.addIssue({ code: 'custom', path: ['items', index, 'item_id'], message: 'Select the purchased item' });
    }
    if (!customerReturn && !line.source_line) {
      ctx.addIssue({ code: 'custom', path: ['items', index, 'source_line'], message: 'Select the accepted GRN line' });
    }
  });
});

type FormValues = z.infer<typeof schema>;

type SourceChoice = ReturnSourceLine & {
  value: string;
  rootId?: string | null;
  sourceKind: 'invoice_item' | 'sales_order_item' | 'delivery_item' | 'grn_item';
};

const sourceForExistingLine = (line: NonNullable<ReturnRequest['items']>[number]): string => {
  if (line.source_invoice_item_id) return `invoice_item:${line.source_invoice_item_id}`;
  if (line.source_sales_order_item_id) return `sales_order_item:${line.source_sales_order_item_id}`;
  if (line.source_delivery_item_id) return `delivery_item:${line.source_delivery_item_id}`;
  if (line.source_grn_item_id) return `grn_item:${line.source_grn_item_id}`;
  return '';
};

export default function CreateReturnRequestPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { id } = useParams<{ id: string }>();
  const editing = Boolean(id);
  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      type: '', return_date: new Date().toISOString().slice(0, 10), customer_id: '', vendor_id: '',
      invoice_id: '', sales_order_id: '', purchase_order_id: '', bill_id: '', finance_only: false,
      finance_only_reason: '', reason_code: '', reason_description: '', customer_notes: '', internal_notes: '',
      resolution: '', items: [],
    },
  });
  const { register, control, handleSubmit, watch, setValue, reset, setError, formState: { errors, isSubmitting } } = form;
  const { data: existing } = useQuery({ queryKey: ['return-request', id], queryFn: () => returnManagementApi.get(id!), enabled: editing });

  useEffect(() => {
    if (!existing) return;
    reset({
      type: existing.type,
      return_date: existing.return_date ?? new Date().toISOString().slice(0, 10),
      customer_id: existing.customer?.id ?? '', vendor_id: existing.vendor?.id ?? '',
      invoice_id: existing.invoice?.id ?? '', sales_order_id: existing.sales_order?.id ?? '',
      purchase_order_id: existing.purchase_order?.id ?? '', bill_id: existing.bill?.id ?? '',
      finance_only: existing.finance_only === true, finance_only_reason: existing.finance_only_reason ?? '',
      reason_code: existing.reason_code ?? '', reason_description: existing.reason_description ?? '',
      customer_notes: existing.customer_notes ?? '', internal_notes: existing.internal_notes ?? '',
      resolution: existing.resolution ?? '',
      items: (existing.items ?? []).map((line) => ({
        product_id: line.product_id ?? '', item_id: line.item_id ?? '',
        source_line: sourceForExistingLine(line), bill_line: line.source_bill_item_id ? `bill_item:${line.source_bill_item_id}` : '',
        quantity: Number(line.quantity), unit_price: Number(line.unit_price), reason: line.reason ?? '',
        condition: line.condition ?? '', lot_number: line.lot_number ?? '', serial_number: line.serial_number ?? '',
      })),
    });
  }, [existing, reset]);

  const { fields, append, remove } = useFieldArray({ control, name: 'items' });
  const returnType = watch('type');
  const partyId = returnType === 'supplier_return' ? watch('vendor_id') : watch('customer_id');
  const financeOnly = watch('finance_only') === true && returnType === 'customer_return';
  const { data: productsData } = useQuery({ queryKey: ['products'], queryFn: () => productsApi.list({ per_page: 500 }) });
  const { data: customersData } = useQuery({ queryKey: ['customers'], queryFn: () => customersApi.list({ per_page: 500 }) });
  const { data: vendorsData } = useQuery({ queryKey: ['vendors'], queryFn: () => vendorsApi.list({ per_page: 500 }) });
  const { data: itemsData } = useQuery({ queryKey: ['items'], queryFn: () => itemsApi.list({ per_page: 500 }) });
  const { data: options } = useQuery({ queryKey: ['return-management', 'options'], queryFn: () => returnManagementApi.options() });
  const { data: sourceOptions, isFetching: sourcesFetching } = useQuery({
    queryKey: ['return-management', 'source-options', returnType, partyId],
    queryFn: () => returnManagementApi.sourceOptions({ type: returnType, ...(returnType === 'supplier_return' ? { vendor_id: partyId } : { customer_id: partyId }) }),
    enabled: !!returnType && !!partyId && !financeOnly,
    staleTime: 60_000,
  });
  const products = productsData?.data ?? [];
  const customers = customersData?.data ?? [];
  const vendors = vendorsData?.data ?? [];
  const items = itemsData?.data ?? [];
  const isSupplierReturn = returnType === 'supplier_return';

  // RMA-010 — label the amount actually still reservable, not the raw document
  // quantity. The old labels said "10 available" to two operators at once; the
  // second only found out at submit, with no way to see whose reservation took
  // the headroom. Falls back to the document quantity if the API omits it.
  const reservable = (line: { quantity: string; remaining_quantity?: string | null }) =>
    line.remaining_quantity ?? line.quantity;

  const customerSourceLines = useMemo<SourceChoice[]>(() => sourceOptions ? [
    ...sourceOptions.customer.invoices.flatMap((doc) => doc.lines.map((line) => ({ ...line, value: `invoice_item:${line.id}`, rootId: doc.id, sourceKind: 'invoice_item' as const, label: `Invoice ${doc.label} · ${line.label} · ${reservable(line)} available` }))),
    ...sourceOptions.customer.salesOrders.flatMap((doc) => doc.lines.map((line) => ({ ...line, value: `sales_order_item:${line.id}`, rootId: doc.id, sourceKind: 'sales_order_item' as const, label: `SO ${doc.label} · ${line.label} · ${reservable(line)} returnable of ${line.quantity} delivered` }))),
    ...sourceOptions.customer.deliveries.flatMap((doc) => doc.lines.map((line) => ({ ...line, value: `delivery_item:${line.id}`, rootId: doc.sales_order_id, sourceKind: 'delivery_item' as const, label: `Delivery ${doc.label} · ${line.label} · ${reservable(line)} returnable of ${line.quantity} delivered` }))),
  ] : [], [sourceOptions]);
  const supplierSourceLines = useMemo<SourceChoice[]>(() => sourceOptions ? sourceOptions.supplier.goodsReceipts.flatMap((doc) => doc.lines.map((line) => ({ ...line, value: `grn_item:${line.id}`, rootId: doc.purchase_order_id, sourceKind: 'grn_item' as const, label: `GRN ${doc.label} · ${line.label} · ${reservable(line)} returnable of ${line.quantity} accepted` }))) : [], [sourceOptions]);
  const billLines = useMemo<SourceChoice[]>(() => sourceOptions ? sourceOptions.supplier.bills.flatMap((doc) => doc.lines.map((line) => ({ ...line, value: `bill_item:${line.id}`, rootId: doc.id, sourceKind: 'grn_item' as const, label: `Bill ${doc.label} · ${line.label}` }))) : [], [sourceOptions]);

  const selectSourceLine = (index: number, value: string) => {
    const choices = isSupplierReturn ? supplierSourceLines : customerSourceLines;
    const choice = choices.find((line) => line.value === value);
    if (!choice) return;
    if (isSupplierReturn) {
      setValue('purchase_order_id', choice.rootId ?? ''); setValue(`items.${index}.item_id`, choice.item_id ?? ''); setValue(`items.${index}.lot_number`, choice.lot_number ?? '');
    } else if (choice.sourceKind === 'invoice_item') {
      setValue('invoice_id', choice.rootId ?? ''); setValue('sales_order_id', '');
      setValue(`items.${index}.product_id`, choice.product_id ?? '');
    } else {
      setValue('sales_order_id', choice.rootId ?? ''); setValue('invoice_id', '');
      setValue(`items.${index}.product_id`, choice.product_id ?? '');
    }
  };
  const selectBillLine = (index: number, value: string) => {
    const choice = billLines.find((line) => line.value === value);
    setValue(`items.${index}.bill_line`, value); setValue('bill_id', choice?.rootId ?? '');
  };

  const mutation = useMutation({
    mutationFn: (data: FormValues) => {
      const customer = data.type === 'customer_return';
      const choices = customer ? customerSourceLines : supplierSourceLines;
      const payload: ReturnRequestFormData = {
        type: data.type as ReturnRequestFormData['type'], return_date: data.return_date,
        customer_id: customer ? data.customer_id || undefined : undefined, vendor_id: customer ? undefined : data.vendor_id || undefined,
        invoice_id: customer && !data.finance_only ? data.invoice_id || undefined : undefined,
        sales_order_id: customer && !data.finance_only ? data.sales_order_id || undefined : undefined,
        purchase_order_id: !customer ? data.purchase_order_id || undefined : undefined, bill_id: !customer ? data.bill_id || undefined : undefined,
        finance_only: customer ? data.finance_only === true : false, finance_only_reason: customer && data.finance_only ? data.finance_only_reason || undefined : undefined,
        reason_code: data.reason_code || undefined, reason_description: data.reason_description || undefined,
        customer_notes: data.customer_notes || undefined, internal_notes: data.internal_notes || undefined, resolution: data.resolution || undefined,
        items: data.items.map((line) => {
          const source = choices.find((candidate) => candidate.value === line.source_line);
          const bill = billLines.find((candidate) => candidate.value === line.bill_line);
          return {
            product_id: customer ? line.product_id || undefined : undefined,
            item_id: customer && !data.finance_only ? line.item_id || undefined : !customer ? line.item_id || undefined : undefined,
            quantity: line.quantity, unit_price: line.unit_price, reason: line.reason || undefined, condition: line.condition || undefined,
            lot_number: line.lot_number || undefined, serial_number: line.serial_number || undefined,
            source_invoice_item_id: source?.sourceKind === 'invoice_item' ? source.id : undefined,
            source_sales_order_item_id: source?.sourceKind === 'sales_order_item' ? source.id : undefined,
            source_delivery_item_id: source?.sourceKind === 'delivery_item' ? source.id : undefined,
            source_grn_item_id: source?.sourceKind === 'grn_item' ? source.id : undefined,
            source_po_item_id: source?.sourceKind === 'grn_item' ? source.po_item_id || undefined : undefined,
            source_bill_item_id: bill?.value.replace('bill_item:', '') || undefined,
          };
        }),
      };
      return editing ? returnManagementApi.update(id!, payload) : returnManagementApi.create(payload);
    },
    onSuccess: (rma) => {
      queryClient.invalidateQueries({ queryKey: ['return-management'] }); queryClient.invalidateQueries({ queryKey: ['return-request', id] });
      toast.success(editing ? 'Draft return request updated.' : 'Return request created.'); navigate(`/return-management/${rma.id}`);
    },
    onError: (error) => applyServerValidationErrors<FormValues>(error, setError, editing ? 'Failed to update return request.' : 'Failed to create return request.'),
  });
  const safety = useFormSafety({ form, saved: mutation.isSuccess });

  return (
    <div>
      <PageHeader title={editing ? 'Edit Return Request' : 'New Return Request'} subtitle={editing ? 'Correct a draft before approval' : 'Create a customer or supplier return'} backTo={editing ? `/return-management/${id}` : '/return-management'} />
      <FormDraftBanner safety={safety} />
      <form onSubmit={handleSubmit((data) => mutation.mutate(data), onFormInvalid<FormValues>())} className="max-w-4xl mx-auto px-5 py-4 space-y-4">
        <Panel title="Type & Source">
          <div className="grid grid-cols-2 gap-3">
            <Select label="Type" required {...register('type')} error={errors.type?.message} disabled={editing}><option value="">— Select type —</option>{(options?.types ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</Select>
            <Input label="Return Date" type="date" required {...register('return_date')} error={errors.return_date?.message} />
          </div>
          {returnType === 'customer_return' && <div className="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
            <Select label="Customer" required {...register('customer_id')} error={errors.customer_id?.message}><option value="">— Select customer —</option>{customers.map((customer: { id: string; name: string }) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}</Select>
            <label className="flex items-center gap-2 text-sm self-end pb-2"><input type="checkbox" {...register('finance_only')} /> Finance-only credit — no goods returning</label>
          </div>}
          {returnType === 'supplier_return' && <div className="mt-3"><Select label="Supplier" required {...register('vendor_id')} error={errors.vendor_id?.message}><option value="">— Select supplier —</option>{vendors.map((vendor: { id: string; name: string }) => <option key={vendor.id} value={vendor.id}>{vendor.name}</option>)}</Select></div>}
          {returnType && partyId && !financeOnly && <p className="text-xs text-muted mt-3">{sourcesFetching ? 'Loading party-scoped source lines…' : 'Each stockable line must be linked to a delivered or accepted source line below.'}</p>}
          {financeOnly && <div className="mt-3"><Textarea label="Finance-only reason" required rows={2} placeholder="Why is no inventory returning?" {...register('finance_only_reason')} error={errors.finance_only_reason?.message} /></div>}
        </Panel>

        <Panel title="Reason">
          <div className="grid grid-cols-2 gap-3"><Select label="Reason Code" required {...register('reason_code')} error={errors.reason_code?.message}><option value="">— Select reason —</option>{(options?.reasons ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</Select><Select label="Resolution" {...register('resolution')} error={errors.resolution?.message}><option value="">— Select resolution —</option>{(options?.resolutions ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</Select></div>
          <div className="mt-3 grid gap-3 md:grid-cols-2"><Textarea label="Description" rows={3} placeholder="Describe the reason for return…" {...register('reason_description')} error={errors.reason_description?.message} /><Textarea label="Internal notes" rows={3} placeholder="Operational notes…" {...register('internal_notes')} error={errors.internal_notes?.message} /></div>
          <div className="mt-3"><Textarea label="Customer notes" rows={2} placeholder="Notes from the customer…" {...register('customer_notes')} error={errors.customer_notes?.message} /></div>
        </Panel>

        <Panel title="Items" actions={<Button type="button" variant="secondary" size="sm" icon={<LuPlus size={14} />} onClick={() => append({ product_id: '', item_id: '', source_line: '', bill_line: '', quantity: '' as unknown as number, unit_price: undefined, reason: '', condition: '', lot_number: '', serial_number: '' })}>Add Item</Button>}>
          {fields.length === 0 ? <div className="text-muted text-sm py-2">No items added yet. Click “Add Item” to add the returned line.</div> : <div className="space-y-3">{fields.map((field, index) => {
            const sourceChoices = isSupplierReturn ? supplierSourceLines : customerSourceLines;
            const itemError = errors.items?.[index];
            return <div key={field.id} className="border border-default rounded-md p-3 space-y-2">
              <div className="grid grid-cols-1 md:grid-cols-12 gap-2 items-start">
                <div className="md:col-span-3">{financeOnly ? <Select label="Credited product" {...register(`items.${index}.product_id` as const)} error={itemError?.product_id?.message}><option value="">— Select product —</option>{products.map((product: { id: string; part_number: string; name: string }) => <option key={product.id} value={product.id}>{product.part_number} — {product.name}</option>)}</Select> : <Select label="Inventory item" {...register(`items.${index}.item_id` as const)} error={itemError?.item_id?.message}><option value="">— Select item —</option>{items.map((item: { id: string; code: string; name: string }) => <option key={item.id} value={item.id}>{item.code} — {item.name}</option>)}</Select>}</div>
                {!financeOnly && <div className="md:col-span-4"><Select label={isSupplierReturn ? 'Accepted GRN line' : 'Source line'} {...register(`items.${index}.source_line` as const)} error={itemError?.source_line?.message} onChange={(event) => { setValue(`items.${index}.source_line`, event.target.value, { shouldValidate: true }); selectSourceLine(index, event.target.value); }}><option value="">{sourcesFetching ? 'Loading source lines…' : '— Select source line —'}</option>{sourceChoices.map((choice) => <option key={choice.value} value={choice.value}>{choice.label}</option>)}</Select></div>}
                {isSupplierReturn && <div className="md:col-span-3"><Select label="Bill line (optional)" {...register(`items.${index}.bill_line` as const)} onChange={(event) => selectBillLine(index, event.target.value)}><option value="">— No bill line —</option>{billLines.map((choice) => <option key={choice.value} value={choice.value}>{choice.label}</option>)}</Select></div>}
                <div className="md:col-span-2"><Input label="Qty" type="number" step="0.001" min="0.001" className="font-mono tabular-nums text-right" {...register(`items.${index}.quantity` as const)} error={itemError?.quantity?.message} /></div>
                <div className="md:col-span-2"><Input label={financeOnly ? 'Unit price' : 'Price (source)'} type="number" step="0.01" min="0" className="font-mono tabular-nums text-right" {...register(`items.${index}.unit_price` as const)} error={itemError?.unit_price?.message} placeholder={financeOnly ? undefined : 'From source'} /></div>
                <div className="flex justify-end pt-6"><Button type="button" variant="ghost" size="sm" iconOnly icon={<LuTrash2 size={14} />} aria-label="Remove line" onClick={() => remove(index)} className="text-muted hover:text-danger-fg" /></div>
              </div>
              {isSupplierReturn && <p className="text-xs text-muted">Selecting a GRN line fills the PO line, item, and controlled lot provenance automatically.</p>}
              <div className="grid grid-cols-1 md:grid-cols-3 gap-2"><Select label="Condition" {...register(`items.${index}.condition` as const)}><option value="">— Condition —</option>{(options?.conditions ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</Select><Input label="Line reason" placeholder="Optional" {...register(`items.${index}.reason` as const)} /><Input label="Serial number" placeholder="Optional" {...register(`items.${index}.serial_number` as const)} /></div>
            </div>;
          })}</div>}
          {errors.items?.root?.message && <p className="text-xs text-danger-fg mt-2">{errors.items.root.message}</p>}
        </Panel>

        <FormActions><Button type="button" variant="secondary" onClick={() => navigate(editing ? `/return-management/${id}` : '/return-management')}>Cancel</Button><Button type="submit" variant="primary" loading={mutation.isPending} disabled={isSubmitting || mutation.isPending}>{mutation.isPending ? (editing ? 'Saving…' : 'Creating…') : (editing ? 'Save Draft' : 'Create Return Request')}</Button></FormActions>
      </form>
    </div>
  );
}
