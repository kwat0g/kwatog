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
import { numberInputProps } from '@/lib/numberInput';

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
  is_vatable: z.boolean().default(true),
  remarks: z.string().max(1000).optional().or(z.literal('')),
  items: z.array(lineSchema).min(1, 'Add at least one line.'),
}).refine((d) => !d.expected_delivery_date || d.expected_delivery_date >= d.date, {
  message: 'Expected delivery cannot be before the PO date.',
  path: ['expected_delivery_date'],
});
type V = z.infer<typeof schema>;

const VP_THRESHOLD = 50000;

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
  const { data: pr } = useQuery({
    queryKey: ['purchasing', 'purchase-requests', prId],
    queryFn: () => purchaseRequestsApi.show(prId!),
    enabled: !!prId,
  });

  const { register, handleSubmit, setError, control, watch, reset, formState: { errors, isSubmitting } } = useForm<V>({
    resolver: zodResolver(schema),
    defaultValues: {
      vendor_id: '',
      date: new Date().toISOString().slice(0, 10),
      expected_delivery_date: '',
      is_vatable: true,
      remarks: '',
      items: [{ item_id: '', description: '', quantity: '1', unit: 'pcs', unit_price: '0' }],
    },
  });
  const { fields, append, remove } = useFieldArray({ control, name: 'items' });

  // Pre-fill from PR.
  useEffect(() => {
    if (pr && pr.items) {
      reset({
        vendor_id: '',
        date: new Date().toISOString().slice(0, 10),
        expected_delivery_date: '',
        is_vatable: true,
        remarks: `Auto-generated from PR ${pr.pr_number}`,
        items: pr.items.map((i) => ({
          item_id: i.item?.id ?? '',
          description: i.description,
          quantity: i.quantity,
          unit: i.unit ?? 'pcs',
          unit_price: i.estimated_unit_price ?? '0',
        })),
      });
    }
  }, [pr, reset]);

  const watchedItems = watch('items');
  const isVatable = watch('is_vatable');
  const subtotal = watchedItems.reduce((s, l) => s + Number(l.quantity || 0) * Number(l.unit_price || 0), 0);
  const vat = isVatable ? subtotal * 0.12 : 0;
  const total = subtotal + vat;
  const requiresVp = total >= VP_THRESHOLD;

  const create = useMutation({
    mutationFn: (values: V) => purchaseOrdersApi.create({
      vendor_id: values.vendor_id,
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
        actions={requiresVp ? <Chip variant="warning">VP approval required</Chip> : null}
      />
      <form
        onSubmit={handleSubmit((d) => { setPendingValues(d); setConfirmOpen(true); }, onFormInvalid<V>())}
        className="max-w-5xl mx-auto px-5 py-6 space-y-4"
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
            <Switch label="VAT-able (12%)" {...register('is_vatable')} />
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
              onClick={() => append({ item_id: '', description: '', quantity: '1', unit: 'pcs', unit_price: '0' })}
            >
              Add line
            </Button>
          }
        >
          {errors.items?.root && <div className="text-xs text-danger-fg mb-2">{errors.items.root.message}</div>}
          <table className="w-full text-xs">
            <thead>
              <tr className="text-2xs uppercase tracking-wider text-muted">
                <th className="text-left py-1 font-medium">Item</th>
                <th className="text-left font-medium">Description</th>
                <th className="text-right font-medium">Qty</th>
                <th className="font-medium">Unit</th>
                <th className="text-right font-medium">Unit price</th>
                <th className="text-right font-medium">Total</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {fields.map((f, i) => (
                <tr key={f.id} className="h-9 border-t border-subtle align-top">
                  <td className="py-1.5">
                    <select
                      className={`h-7 w-32 px-1 rounded-sm border text-2xs font-mono ${errors.items?.[i]?.item_id ? 'border-danger' : 'border-default'}`}
                      {...register(`items.${i}.item_id` as const)}
                    >
                      <option value="">—</option>
                      {items.data?.data.map((it) => (
                        <option key={it.id} value={it.id}>{it.code}</option>
                      ))}
                    </select>
                  </td>
                  <td>
                    <Input className="h-7" {...register(`items.${i}.description` as const)} error={errors.items?.[i]?.description?.message} />
                  </td>
                  <td className="text-right">
                    <input
                      className="h-7 w-20 px-2 rounded-sm border border-default text-right font-mono tabular-nums text-xs"
                      type="text"
                      {...numberInputProps()}
                      {...register(`items.${i}.quantity` as const)}
                    />
                  </td>
                  <td>
                    <input
                      className="h-7 w-16 px-2 rounded-sm border border-default text-xs"
                      {...register(`items.${i}.unit` as const)}
                    />
                  </td>
                  <td className="text-right">
                    <input
                      className="h-7 w-24 px-2 rounded-sm border border-default text-right font-mono tabular-nums text-xs"
                      type="text"
                      {...numberInputProps()}
                      {...register(`items.${i}.unit_price` as const)}
                    />
                  </td>
                  <td className="text-right font-mono tabular-nums pt-1">
                    {(Number(watchedItems[i]?.quantity || 0) * Number(watchedItems[i]?.unit_price || 0)).toFixed(2)}
                  </td>
                  <td className="text-right pt-1">
                    {fields.length > 1 && (
                      <button
                        type="button"
                        onClick={() => remove(i)}
                        className="p-1 text-text-muted hover:text-danger hover:bg-elevated rounded-sm"
                        aria-label="Remove line"
                      >
                        <Trash2 size={12} />
                      </button>
                    )}
                  </td>
                </tr>
              ))}
              <tr className="border-t border-default">
                <td colSpan={5} className="text-right py-1.5 text-muted">Subtotal</td>
                <td className="text-right font-mono tabular-nums">₱ {subtotal.toFixed(2)}</td>
                <td />
              </tr>
              {isVatable && (
                <tr>
                  <td colSpan={5} className="text-right py-1 text-muted">VAT (12%)</td>
                  <td className="text-right font-mono tabular-nums">₱ {vat.toFixed(2)}</td>
                  <td />
                </tr>
              )}
              <tr className="border-t border-default font-medium">
                <td colSpan={5} className="text-right py-2 uppercase text-2xs tracking-wider">Total</td>
                <td className="text-right font-mono tabular-nums">₱ {total.toFixed(2)}</td>
                <td />
              </tr>
            </tbody>
          </table>
        </Panel>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={() => nav('/purchasing/purchase-orders')} disabled={create.isPending}>Cancel</Button>
          <Button type="submit" variant="primary" loading={create.isPending} disabled={create.isPending || isSubmitting}>Create PO</Button>
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
              Total <span className="font-mono font-medium text-primary">₱ {total.toFixed(2)}</span>.
              {requiresVp && (
                <span className="block mt-1 text-warning-fg">
                  Total ≥ ₱ {VP_THRESHOLD.toLocaleString()} — VP approval will be required before send.
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
