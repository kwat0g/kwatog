/** Sprint 7 — Delivery Create Form. Outbound delivery from confirmed sales order. */
import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm, useFieldArray, Controller } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Plus, X } from 'lucide-react';
import toast from 'react-hot-toast';
import { deliveriesApi, vehiclesApi } from '@/api/supply-chain';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid, applyServerValidationErrors } from '@/lib/formErrors';

// ─── Validation ──────────────────────────────────────────────────────────────

const itemSchema = z.object({
  sales_order_item_id: z.string().min(1, 'Select a line item'),
  quantity: z.coerce
    .number({ invalid_type_error: 'Must be a number' })
    .positive('Must be greater than zero'),
  inspection_id: z.string().optional(),
});

const schema = z.object({
  sales_order_id: z.string().min(1, 'Sales order is required'),
  vehicle_id: z.string().optional(),
  driver_id: z.string().optional(),
  scheduled_date: z.string().min(1, 'Scheduled date is required'),
  notes: z.string().max(2000).optional(),
  items: z.array(itemSchema).min(1, 'Add at least one delivery line'),
});

type FormValues = z.infer<typeof schema>;

// ─── Component ───────────────────────────────────────────────────────────────

export default function CreateDeliveryPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();

  // ── Fetch reference data ──
  const { data: soData, isLoading: soLoading, isError: soError } = useQuery({
    queryKey: ['crm', 'sales-orders', 'for-delivery'],
    queryFn: () =>
      salesOrdersApi.list({ status: 'confirmed', per_page: 200 }),
  });
  const soList = soData?.data ?? [];

  const { data: vehiclesData, isLoading: vehiclesLoading } = useQuery({
    queryKey: ['supply-chain', 'vehicles', 'all'],
    queryFn: () => vehiclesApi.list({ per_page: 200 }),
  });
  const vehicleList = vehiclesData?.data ?? [];

  // ── Form ──
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
      sales_order_id: '',
      vehicle_id: '',
      driver_id: '',
      scheduled_date: '',
      notes: '',
      items: [{ sales_order_item_id: '', quantity: 1, inspection_id: '' }],
    },
  });

  const { fields, append, remove } = useFieldArray({ control, name: 'items' });

  // Watch SO selection to populate line item options.
  const selectedSoId = watch('sales_order_id');

  // When SO changes, fetch the SO detail to get its line items.
  const { data: selectedSo } = useQuery({
    queryKey: ['crm', 'sales-orders', selectedSoId],
    queryFn: () => salesOrdersApi.show(selectedSoId),
    enabled: Boolean(selectedSoId),
  });

  const soItems = selectedSo?.items ?? [];

  // ── Mutation ──
  const mutation = useMutation({
    mutationFn: (data: FormValues) =>
      deliveriesApi.create({
        sales_order_id: data.sales_order_id,
        vehicle_id: data.vehicle_id || undefined,
        driver_id: data.driver_id || undefined,
        scheduled_date: data.scheduled_date,
        notes: data.notes || undefined,
        items: data.items.map((i) => ({
          sales_order_item_id: i.sales_order_item_id,
          quantity: i.quantity,
          inspection_id: i.inspection_id || undefined,
        })),
      }),
    onSuccess: (delivery) => {
      qc.invalidateQueries({ queryKey: ['supply-chain', 'deliveries'] });
      toast.success('Delivery created');
      navigate(`/supply-chain/deliveries/${delivery.id}`);
    },
    onError: (err) => {
      applyServerValidationErrors(err, setError, 'Failed to create delivery.');
    },
  });

  // ── Pre-populate driver_id from SO if SO has a delivery address ──
  // (not applicable here — driver comes from fleet, not SO)

  return (
    <div>
      <PageHeader
        title="New delivery"
        backTo="/supply-chain/deliveries"
        backLabel="Deliveries"
        breadcrumbs={[
          { label: 'Deliveries', href: '/supply-chain/deliveries' },
          { label: 'New delivery' },
        ]}
      />

      <form
        onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
        className="max-w-3xl mx-auto px-5 py-6"
      >
        {/* ── Sales Order ── */}
        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">
            Sales order
          </legend>
          <Select
            label="Sales order"
            {...register('sales_order_id')}
            error={errors.sales_order_id?.message}
            required
            disabled={soLoading || soError}
          >
            <option value="">
              {soLoading
                ? 'Loading sales orders…'
                : soError
                ? 'Failed to load sales orders'
                : '— Select confirmed sales order —'}
            </option>
            {soList.map((so) => (
              <option key={so.id} value={so.id}>
                {so.so_number}
                {so.customer ? ` — ${so.customer.name}` : ''}
              </option>
            ))}
          </Select>
          {selectedSo && (
            <p className="mt-1.5 text-xs text-muted">
              {selectedSo.item_count} line{selectedSo.item_count === 1 ? '' : 's'} ·{' '}
              Customer: {selectedSo.customer?.name ?? '—'} · Total: ₱
              <span className="font-mono tabular-nums">{selectedSo.total_amount}</span>
            </p>
          )}
        </fieldset>

        {/* ── Schedule ── */}
        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">
            Schedule
          </legend>
          <Input
            label="Scheduled delivery date"
            type="date"
            {...register('scheduled_date')}
            error={errors.scheduled_date?.message}
            required
          />
        </fieldset>

        {/* ── Fleet ── */}
        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">
            Fleet (optional)
          </legend>
          <div className="grid grid-cols-2 gap-3">
            <Select
              label="Vehicle"
              {...register('vehicle_id')}
              error={errors.vehicle_id?.message}
              disabled={vehiclesLoading}
            >
              <option value="">
                {vehiclesLoading ? 'Loading vehicles…' : '— No vehicle assigned —'}
              </option>
              {vehicleList.map((v) => (
                <option key={v.id} value={v.id}>
                  {v.name} ({v.plate_number})
                </option>
              ))}
            </Select>
            {/* driver_id: free-text is not ideal but driver list comes from users.
                The backend resolves via ResolvesHashIds. We leave this optional
                and show a plain text input with a note to keep the form simple. */}
            <Input
              label="Driver user ID (hash)"
              {...register('driver_id')}
              error={errors.driver_id?.message}
              placeholder="Optional — hash ID of driver user"
            />
          </div>
        </fieldset>

        {/* ── Delivery line items ── */}
        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">
            Delivery items
          </legend>

          {!selectedSoId && (
            <p className="text-xs text-muted px-3 py-2 bg-subtle rounded-md border border-default mb-3">
              Select a sales order above to populate line item choices.
            </p>
          )}

          {errors.items?.root?.message && (
            <p className="text-xs text-danger mb-2">{errors.items.root.message}</p>
          )}

          <div className="space-y-3">
            {fields.map((field, index) => (
              <div
                key={field.id}
                className="grid grid-cols-[1fr_120px_auto] gap-2 items-end p-3 bg-subtle rounded-md border border-default"
              >
                {/* SO Item select */}
                <div>
                  <label className="text-2xs uppercase tracking-wider text-muted block mb-1">
                    Sales order line <span className="text-danger">*</span>
                  </label>
                  <select
                    {...register(`items.${index}.sales_order_item_id`)}
                    disabled={!selectedSoId || soItems.length === 0}
                    className="w-full text-sm rounded-md border border-default bg-canvas px-2 py-1.5 disabled:opacity-50"
                  >
                    <option value="">
                      {!selectedSoId
                        ? 'Select SO first'
                        : soItems.length === 0
                        ? 'Loading items…'
                        : '— Select line —'}
                    </option>
                    {soItems.map((item) => (
                      <option key={item.id} value={item.id}>
                        {item.product?.part_number
                          ? `${item.product.part_number} — ${item.product.name}`
                          : `Line ${item.id}`}
                        {' '}(Qty: {item.quantity} {item.product?.unit_of_measure ?? ''})
                      </option>
                    ))}
                  </select>
                  {errors.items?.[index]?.sales_order_item_id && (
                    <p className="text-2xs text-danger mt-0.5">
                      {errors.items[index]!.sales_order_item_id!.message}
                    </p>
                  )}
                </div>

                {/* Quantity */}
                <div>
                  <label className="text-2xs uppercase tracking-wider text-muted block mb-1">
                    Qty <span className="text-danger">*</span>
                  </label>
                  <input
                    type="number"
                    step="any"
                    min="0.001"
                    {...register(`items.${index}.quantity`)}
                    className="w-full text-sm rounded-md border border-default bg-canvas px-2 py-1.5 font-mono tabular-nums"
                  />
                  {errors.items?.[index]?.quantity && (
                    <p className="text-2xs text-danger mt-0.5">
                      {errors.items[index]!.quantity!.message}
                    </p>
                  )}
                </div>

                {/* Remove button */}
                <button
                  type="button"
                  onClick={() => remove(index)}
                  disabled={fields.length === 1}
                  title="Remove line"
                  className="mb-px inline-flex items-center justify-center w-7 h-7 rounded-md border border-default text-muted hover:text-danger hover:border-danger disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                >
                  <X size={14} />
                </button>
              </div>
            ))}
          </div>

          <button
            type="button"
            onClick={() =>
              append({ sales_order_item_id: '', quantity: 1, inspection_id: '' })
            }
            disabled={!selectedSoId}
            className="mt-2 inline-flex items-center gap-1.5 text-xs text-accent hover:underline disabled:opacity-40 disabled:cursor-not-allowed"
          >
            <Plus size={14} />
            Add delivery line
          </button>
        </fieldset>

        {/* ── Notes ── */}
        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">
            Notes
          </legend>
          <Textarea
            label="Notes"
            {...register('notes')}
            rows={3}
            error={errors.notes?.message}
            placeholder="Optional delivery notes, special instructions…"
          />
        </fieldset>

        {/* ── Actions ── */}
        <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/supply-chain/deliveries')}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={isSubmitting || mutation.isPending}
            loading={mutation.isPending}
          >
            {mutation.isPending ? 'Creating…' : 'Create delivery'}
          </Button>
        </div>
      </form>
    </div>
  );
}
