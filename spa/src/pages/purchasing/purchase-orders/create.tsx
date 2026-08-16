import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { LuPlus, LuTrash2 } from '@/lib/icons';
import toast from 'react-hot-toast';
import { purchaseOrdersApi } from '@/api/purchasing/purchase-orders';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import { itemsApi } from '@/api/inventory/items';
import { vendorsApi } from '@/api/accounting/vendors';
import { businessPoliciesApi } from '@/api/businessPolicies';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Switch } from '@/components/ui/Switch';
import { Textarea } from '@/components/ui/Textarea';
import { DraftRestoreBanner } from '@/components/ui/DraftRestoreBanner';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { useFormDraftAutosave } from '@/hooks/useFormDraftAutosave';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import { useAuthStore } from '@/stores/authStore';
import { formatPeso } from '@/lib/formatNumber';
import { numberInputProps } from '@/lib/numberInput';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { cn } from '@/lib/cn';

const lineSchema = z.object({
  item_id: z.string().min(1, 'Item is required.'),
  description: z.string().trim().min(2, 'Description is required.').max(200),
  quantity: z
    .string()
    .regex(/^\d+(\.\d{1,2})?$/, 'Up to 2 decimals.')
    .refine((v) => Number(v) > 0, 'Must be > 0.'),
  unit: z.string().max(20).optional().or(z.literal('')),
  unit_price: z
    .string()
    .regex(/^\d+(\.\d{1,2})?$/, 'Up to 2 decimals.')
    .refine((v) => Number(v) >= 0, 'Must be ≥ 0.'),
});

const schema = z
  .object({
    vendor_id: z.string().min(1, 'Vendor is required.'),
    date: z.string().min(1, 'Date is required.'),
    expected_delivery_date: z.string().optional().or(z.literal('')),
    is_vatable: z.boolean(),
    remarks: z.string().max(1000).optional().or(z.literal('')),
    items: z.array(lineSchema).min(1, 'Add at least one line.'),
  })
  .refine((d) => !d.expected_delivery_date || d.expected_delivery_date >= d.date, {
    message: 'Expected delivery cannot be before the PO date.',
    path: ['expected_delivery_date'],
  });
type V = z.infer<typeof schema>;

export default function CreatePurchaseOrderPage() {
  const nav = useNavigate();
  const userId = useAuthStore((s) => s.user?.id);
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
  // POs must originate from an approved PR. When none is linked yet, offer a
  // picker of approved (not yet converted) PRs instead of a blank form.
  const approvedPrs = useQuery({
    queryKey: ['purchasing', 'purchase-requests', { status: 'approved', source: 'po' }],
    queryFn: () => purchaseRequestsApi.list({ status: 'approved', per_page: 100 }),
    enabled: !prId,
  });
  const vatStatus = policies.data?.vat_status;
  const vatRate = policies.data?.vat_rate == null ? null : Number(policies.data.vat_rate);
  const vatConfigured = vatStatus === 'VAT Registered' && vatRate !== null;

  const {
    register,
    handleSubmit,
    setError,
    setValue,
    control,
    watch,
    reset,
    getValues,
    formState: { errors, isSubmitting, isDirty },
  } = useForm<V>({
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
    if (policies.data) setValue('is_vatable', vatConfigured);
  }, [policies.data, setValue, vatConfigured]);
  const { fields, append, remove } = useFieldArray({ control, name: 'items' });

  // Pre-fill from PR.
  useEffect(() => {
    if (pr && pr.items) {
      reset({
        vendor_id: '',
        date: new Date().toISOString().slice(0, 10),
        expected_delivery_date: '',
        is_vatable: vatConfigured,
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
  }, [pr, reset, vatConfigured]);

  const watchedItems = watch('items');
  const isVatable = watch('is_vatable');
  const subtotal = watchedItems.reduce(
    (s, l) => s + Number(l.quantity || 0) * Number(l.unit_price || 0),
    0,
  );
  // Unit of measure is always copied from the selected item — never typed.
  const itemById = useMemo(
    () => new Map((items.data?.data ?? []).map((it) => [it.id, it])),
    [items.data],
  );
  const onLineItemChange = (index: number, itemId: string) => {
    setValue(`items.${index}.item_id`, itemId);
    const item = itemById.get(itemId);
    setValue(`items.${index}.unit`, item?.unit_of_measure ?? '');
  };
  const vatRateLabel = vatConfigured && vatRate !== null ? `${(vatRate * 100).toLocaleString()}%` : '—';
  const vat = isVatable && vatRate !== null ? subtotal * vatRate : 0;
  const total = subtotal + vat;
  const requiresVp =
    policies.data !== undefined && total >= policies.data.purchase_order_vp_threshold;

  const create = useMutation({
    mutationFn: (values: V) =>
      purchaseOrdersApi.create({
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
    onSuccess: (po) => {
      toast.success(`PO ${po.po_number} created.`);
      nav(`/purchasing/purchase-orders/${po.id}`);
    },
    onError: (e) => {
      setConfirmOpen(false);
      applyServerValidationErrors(e, setError, 'Failed to create PO.');
    },
  });

  const applyDraft = useCallback((data: Record<string, unknown>) => {
    reset(data as V);
  }, [reset]);
  const draft = useFormDraftAutosave({
    formKey: `purchasing.purchase-orders.create.${prId ?? 'new'}`,
    getValues,
    setValues: applyDraft,
    userId,
    enabled: Boolean(prId) && !create.isSuccess,
  });
  useUnsavedChangesGuard(isDirty && !create.isSuccess);

  return (
    <div>
      <PageHeader
        title="New purchase order"
        backTo="/purchasing/purchase-orders"
        backLabel="Purchase orders"
        actions={requiresVp ? <Chip variant="warning">VP approval required</Chip> : null}
      />
      {!prId && (
        <Panel title="Source purchase request">
          <p className="text-sm text-muted mb-3">
            A purchase order must be created from an <span className="font-medium text-primary">approved</span> purchase
            request (PR). Choose the PR to source this PO from — its lines, quantities, and estimated prices are
            carried over for the vendor.
          </p>
          {approvedPrs.isLoading && <SkeletonTable rows={3} columns={4} />}
          {approvedPrs.isError && (
            <EmptyState icon="alert-circle" title="Failed to load approved PRs" action={<Button onClick={() => approvedPrs.refetch()}>Retry</Button>} />
          )}
          {approvedPrs.data && approvedPrs.data.data.length === 0 && (
            <EmptyState
              icon="inbox"
              title="No approved purchase requests"
              description="Create and approve a purchase request first — a PO always traces back to an approved PR."
            />
          )}
          {approvedPrs.data && approvedPrs.data.data.length > 0 && (
            <ul className="divide-y divide-subtle border border-subtle rounded-md">
              {approvedPrs.data.data.map((pr) => (
                <li key={pr.id}>
                  <button
                    type="button"
                    onClick={() => nav(`/purchasing/purchase-orders/create?pr_id=${pr.id}`, { replace: true })}
                    className="w-full text-left px-4 py-3 flex items-center gap-3 transition-colors cursor-pointer hover:bg-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                  >
                    <span className="font-mono text-sm font-medium text-primary">{pr.pr_number}</span>
                    <span className="flex-1 text-sm text-secondary truncate">{pr.reason ?? '—'}</span>
                    {pr.items && <span className="text-2xs text-muted">{pr.items.length} lines</span>}
                    <Chip variant={pr.priority === 'critical' ? 'danger' : pr.priority === 'urgent' ? 'warning' : 'neutral'}>
                      {pr.priority_label ?? pr.priority}
                    </Chip>
                    <span className="font-mono text-sm tabular-nums">{formatPeso(pr.total_estimated_amount)}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      )}
      {prId && (
      <form
        onSubmit={handleSubmit((d) => {
          setPendingValues(d);
          setConfirmOpen(true);
        }, onFormInvalid<V>())}
        className="max-w-5xl mx-auto px-5 py-4 space-y-4"
      >
        {draft.hasDraft && (
          <DraftRestoreBanner
            ageMs={draft.draftAge}
            onRestore={draft.restore}
            onDiscard={draft.discard}
          />
        )}
        <Panel title="Header">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <Select
              label="Vendor"
              required
              {...register('vendor_id')}
              error={errors.vendor_id?.message}
            >
              <option value="">Select vendor…</option>
              {vendors.data?.data.map((v) => (
                <option key={v.id} value={v.id}>
                  {v.name}
                </option>
              ))}
            </Select>
            <Input
              label="Date"
              type="date"
              required
              {...register('date')}
              error={errors.date?.message}
            />
            <Input
              label="Expected delivery"
              type="date"
              {...register('expected_delivery_date')}
              error={errors.expected_delivery_date?.message}
            />
            <Switch label={`VAT-able (${vatRateLabel})`} disabled={!vatConfigured} {...register('is_vatable')} />
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
              icon={<LuPlus size={12} />}
              onClick={() =>
                append({ item_id: '', description: '', quantity: '', unit: '', unit_price: '' })
              }
            >
              Add line
            </Button>
          }
        >
          {errors.items?.root && (
            <div className="text-xs text-danger-fg mb-2">{errors.items.root.message}</div>
          )}
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
                      value={watchedItems[i]?.item_id ?? ''}
                      onChange={(e) => onLineItemChange(i, e.target.value)}
                    >
                      <option value="">—</option>
                      {items.data?.data.map((it) => (
                        <option key={it.id} value={it.id}>
                          {it.code} — {it.name}
                        </option>
                      ))}
                    </Select>
                  </Td>
                  <Td>
                    <Input
                      fieldSize="sm"
                      aria-label="Description"
                      {...register(`items.${i}.description` as const)}
                      error={errors.items?.[i]?.description?.message}
                    />
                  </Td>
                  <Td align="right" mono>
                    <Input
                      fieldSize="sm"
                      containerClassName="w-20 inline-flex"
                      className="text-right font-mono tabular-nums"
                      aria-label="Quantity"
                      {...numberInputProps()}
                      {...register(`items.${i}.quantity` as const)}
                    />
                  </Td>
                  <Td>
                    <Input
                      fieldSize="sm"
                      containerClassName="w-16"
                      aria-label="Unit"
                      value={watchedItems[i]?.unit ?? ''}
                      readOnly
                      title="Unit is copied from the selected item"
                    />
                  </Td>
                  <Td align="right" mono>
                    <Input
                      fieldSize="sm"
                      containerClassName="w-24 inline-flex"
                      className="text-right font-mono tabular-nums"
                      aria-label="Unit price"
                      {...numberInputProps()}
                      {...register(`items.${i}.unit_price` as const)}
                    />
                  </Td>
                  <Td align="right" mono>
                    {(
                      Number(watchedItems[i]?.quantity || 0) *
                      Number(watchedItems[i]?.unit_price || 0)
                    ).toFixed(2)}
                  </Td>
                  <Td align="right" mono>
                    {fields.length > 1 && (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        iconOnly
                        icon={<LuTrash2 size={12} />}
                        onClick={() => remove(i)}
                        aria-label="Remove line"
                        className="text-muted hover:text-danger-fg"
                      />
                    )}
                  </Td>
                </tr>
              ))}
              <tr className={trCls}>
                <Td align="right" mono className="text-muted" colSpan={5}>
                  Subtotal
                </Td>
                <Td align="right" mono>
                  {formatPeso(subtotal)}
                </Td>
                <Td />
              </tr>
              {isVatable && (
                <tr className={trCls}>
                  <Td align="right" mono className="text-muted" colSpan={5}>
                    VAT ({vatRateLabel})
                  </Td>
                  <Td align="right" mono>
                    {formatPeso(vat)}
                  </Td>
                  <Td />
                </tr>
              )}
              <tr className={cn(trCls, 'font-medium')}>
                <Td align="right" mono className="uppercase text-2xs tracking-wider" colSpan={5}>
                  Total
                </Td>
                <Td align="right" mono>
                  {formatPeso(total)}
                </Td>
                <Td />
              </tr>
            </tbody>
          </table>
        </Panel>
        <div className="flex justify-end gap-2">
          <Button
            type="button"
            variant="secondary"
            onClick={() => nav('/purchasing/purchase-orders')}
            disabled={create.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            loading={create.isPending || policies.isLoading}
            disabled={create.isPending || isSubmitting || !policies.data}
          >
            Create PO
          </Button>
        </div>
      </form>
      )}

      <ConfirmDialog
        isOpen={confirmOpen}
        onClose={() => setConfirmOpen(false)}
        onConfirm={() => {
          if (pendingValues) create.mutate(pendingValues);
        }}
        title="Create this PO?"
        description={
          pendingValues ? (
            <>
              Total <span className="font-mono font-medium text-primary">{formatPeso(total)}</span>.
              {requiresVp && (
                <span className="block mt-1 text-warning-fg">
                  Total ≥ {formatPeso(policies.data?.purchase_order_vp_threshold)} — VP
                  approval will be required before send.
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
