import { useNavigate } from 'react-router-dom';
import { useFieldArray, useForm } from 'react-hook-form';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { Plus, X } from 'lucide-react';
import { returnManagementApi } from '@/api/returnManagement';
import { productsApi } from '@/api/crm/products';
import { customersApi } from '@/api/accounting/customers';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid, applyServerValidationErrors } from '@/lib/formErrors';

const itemSchema = z.object({
  product_id: z.string().min(1, 'Select a product'),
  quantity: z.coerce.number().min(0.001, 'Min 0.001'),
  unit_price: z.coerce.number().min(0).optional(),
  reason: z.string().optional(),
  condition: z.string().optional(),
});

const schema = z.object({
  type: z.enum(['customer_return', 'supplier_return']),
  return_date: z.string().min(1, 'Required'),
  customer_id: z.string().optional(),
  vendor_id: z.string().optional(),
  reason_code: z.string().min(1, 'Select a reason'),
  reason_description: z.string().optional(),
  customer_notes: z.string().optional(),
  resolution: z.string().optional(),
  items: z.array(itemSchema).min(1, 'Add at least one item'),
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
      type: 'customer_return',
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

  const products = productsData?.data ?? [];
  const customers = customersData?.data ?? [];

  const mutation = useMutation({
    mutationFn: (data: FormValues) =>
      returnManagementApi.create({
        ...data,
        reason_description: data.reason_description || undefined,
        customer_notes: data.customer_notes || undefined,
        resolution: data.resolution || undefined,
        customer_id: data.customer_id || undefined,
        vendor_id: data.vendor_id || undefined,
        items: data.items.map((it) => ({
          product_id: it.product_id,
          quantity: it.quantity,
          unit_price: it.unit_price ?? undefined,
          reason: it.reason || undefined,
          condition: it.condition || undefined,
        })),
      }),
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
        className="max-w-3xl mx-auto px-5 py-6 space-y-4"
      >
        {/* Type & Source */}
        <Panel title="Type & Source">
          <div className="grid grid-cols-2 gap-3">
            <Select label="Type" required {...register('type')} error={errors.type?.message}>
              <option value="customer_return">Customer Return</option>
              <option value="supplier_return">Supplier Return</option>
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
          ) : (
            <div className="grid grid-cols-2 gap-3 mt-3">
              <Input
                label="Vendor ID"
                placeholder="Vendor hash ID"
                {...register('vendor_id')}
                error={errors.vendor_id?.message}
              />
            </div>
          )}
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
              <option value="defective">Defective product</option>
              <option value="damaged">Damaged in transit</option>
              <option value="wrong_item">Wrong item shipped</option>
              <option value="excess">Excess quantity</option>
              <option value="customer_change">Customer changed mind</option>
              <option value="quality_issue">Quality issue</option>
              <option value="other">Other</option>
            </Select>
            <Select
              label="Resolution"
              {...register('resolution')}
              error={errors.resolution?.message}
            >
              <option value="">— Select resolution —</option>
              <option value="replace">Replace</option>
              <option value="refund">Refund</option>
              <option value="credit_note">Credit Note</option>
              <option value="scrap">Scrap</option>
              <option value="return_to_vendor">Return to Vendor</option>
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
              icon={<Plus size={14} />}
              onClick={() =>
                append({ product_id: '', quantity: 1, unit_price: 0, reason: '', condition: '' })
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
              <div className="grid grid-cols-12 gap-2 h-8 px-2.5 bg-subtle text-2xs uppercase tracking-wider text-muted font-medium border-b border-default items-center">
                <div className="col-span-4">Product</div>
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
                      <option value="new">New</option>
                      <option value="used">Used</option>
                      <option value="damaged">Damaged</option>
                      <option value="defective">Defective</option>
                      <option value="obsolete">Obsolete</option>
                    </Select>
                  </div>
                  <div className="col-span-1">
                    <Input
                      placeholder="Reason"
                      {...register(`items.${idx}.reason` as const)}
                    />
                  </div>
                  <div className="col-span-1 flex justify-end pt-1.5">
                    <button
                      type="button"
                      className="text-muted hover:text-danger-fg"
                      onClick={() => remove(idx)}
                    >
                      <X size={14} />
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
          {errors.items?.root?.message && (
            <p className="text-xs text-danger mt-2">{errors.items.root.message}</p>
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
