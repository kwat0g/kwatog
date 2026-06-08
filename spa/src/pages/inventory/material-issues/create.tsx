import { useMemo } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useFieldArray, useForm } from 'react-hook-form';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { Plus, Trash2 } from 'lucide-react';
import { materialIssuesApi } from '@/api/inventory/material-issues';
import { workOrdersApi } from '@/api/production/workOrders';
import { itemsApi } from '@/api/inventory/items';
import { warehouseApi } from '@/api/inventory/warehouse';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { numberInputProps } from '@/lib/numberInput';
import { onFormInvalid } from '@/lib/formErrors';
import type { ApiValidationError } from '@/types';

const itemSchema = z.object({
  item_id: z.string().min(1, 'Item required'),
  location_id: z.string().min(1, 'Location required'),
  quantity_issued: z
    .string()
    .regex(/^\d+(\.\d{1,4})?$/, 'Valid quantity required')
    .refine((v) => Number(v) > 0, 'Must be > 0'),
  remarks: z.string().max(200).optional().or(z.literal('')),
});

const schema = z
  .object({
    work_order_id: z.string().optional().or(z.literal('')),
    reference_text: z.string().max(200).optional().or(z.literal('')),
    issued_date: z.string().min(1, 'Issued date required'),
    remarks: z.string().max(1000).optional().or(z.literal('')),
    items: z.array(itemSchema).min(1, 'Add at least one line'),
  })
  .refine((d) => !!d.work_order_id || !!d.reference_text, {
    message: 'Either a work order or a free-text reference is required.',
    path: ['reference_text'],
  });

type FormValues = z.infer<typeof schema>;

const blankLine = { item_id: '', location_id: '', quantity_issued: '', remarks: '' };

export default function CreateMaterialIssuePage() {
  const nav = useNavigate();
  const qc = useQueryClient();
  const [search] = useSearchParams();

  const { register, control, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      work_order_id: search.get('work_order_id') ?? '',
      reference_text: '',
      issued_date: new Date().toISOString().slice(0, 10),
      remarks: '',
      items: [{ ...blankLine }],
    },
  });
  const { fields, append, remove } = useFieldArray({ control, name: 'items' });

  const { data: workOrders } = useQuery({
    queryKey: ['production', 'work-orders', 'released-for-mis'],
    queryFn: () => workOrdersApi.list({ status: 'confirmed', per_page: 100 }),
  });
  const woList = workOrders?.data ?? [];

  const { data: itemsResp } = useQuery({
    queryKey: ['inventory', 'items', 'for-mis'],
    queryFn: () => itemsApi.list({ per_page: 500, is_active: true }),
  });
  const itemOpts = itemsResp?.data ?? [];

  const { data: warehouses } = useQuery({
    queryKey: ['inventory', 'warehouse', 'tree'],
    queryFn: () => warehouseApi.tree(),
  });
  const locations = useMemo(
    () =>
      (warehouses ?? []).flatMap((w) =>
        (w.zones ?? []).flatMap((z) =>
          (z.locations ?? []).map((l) => ({
            id: l.id,
            label: `${w.code}-${z.code}-${l.code}`,
          })),
        ),
      ),
    [warehouses],
  );

  const mutation = useMutation({
    mutationFn: (v: FormValues) =>
      materialIssuesApi.create({
        work_order_id: v.work_order_id ? v.work_order_id : null,
        issued_date: v.issued_date,
        reference_text: v.reference_text || undefined,
        remarks: v.remarks || undefined,
        items: v.items.map((i) => ({
          item_id: i.item_id,
          location_id: i.location_id,
          quantity_issued: i.quantity_issued,
          remarks: i.remarks || undefined,
        })),
      }),
    onSuccess: (slip) => {
      qc.invalidateQueries({ queryKey: ['inventory', 'material-issues'] });
      toast.success(`Material issue ${slip.slip_number} created.`);
      nav(`/inventory/material-issues/${slip.id}`);
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data?.errors) {
        Object.entries(e.response.data.errors).forEach(([f, msgs]) =>
          setError(f as keyof FormValues, { type: 'server', message: msgs[0] }),
        );
        toast.error('The server flagged some fields. Please review.');
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to create material issue.');
      }
    },
  });

  return (
    <div>
      <PageHeader title="New material issue" backTo="/inventory/material-issues" backLabel="Material issues" />

      <form
        onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
        className="max-w-5xl mx-auto px-5 py-6 space-y-4"
      >
        <Panel title="Reference">
          <div className="grid grid-cols-3 gap-3">
            <Select
              label="Work order"
              {...register('work_order_id')}
              error={errors.work_order_id?.message}
            >
              <option value="">— None (use reference) —</option>
              {woList.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.wo_number} {w.product ? `— ${w.product.part_number}` : ''}
                </option>
              ))}
            </Select>
            <Input
              label="Issued date"
              type="date"
              required
              {...register('issued_date')}
              error={errors.issued_date?.message}
            />
            <Input
              label="Reference"
              maxLength={200}
              placeholder="Free-text (if no WO)"
              {...register('reference_text')}
              error={errors.reference_text?.message}
            />
          </div>
          <div className="mt-3">
            <Textarea
              label="Remarks"
              rows={2}
              maxLength={1000}
              placeholder="Optional"
              {...register('remarks')}
              error={errors.remarks?.message}
            />
          </div>
        </Panel>

        <Panel title="Line items">
          <div className="border border-default rounded-md overflow-hidden">
            <div className="grid grid-cols-12 h-8 px-2.5 bg-subtle text-2xs uppercase tracking-wider text-muted font-medium border-b border-default items-center">
              <div className="col-span-4">Item</div>
              <div className="col-span-3">Location</div>
              <div className="col-span-2 text-right">Qty issued</div>
              <div className="col-span-2">Remarks</div>
              <div className="col-span-1" />
            </div>
            {fields.map((field, idx) => (
              <div key={field.id} className="grid grid-cols-12 gap-2 px-2.5 py-1.5 border-b border-subtle items-start">
                <div className="col-span-4">
                  <Select required {...register(`items.${idx}.item_id` as const)} error={errors.items?.[idx]?.item_id?.message}>
                    <option value="">— Select item —</option>
                    {itemOpts.map((it) => (
                      <option key={it.id} value={it.id}>
                        {it.code} — {it.name}
                      </option>
                    ))}
                  </Select>
                </div>
                <div className="col-span-3">
                  <Select required {...register(`items.${idx}.location_id` as const)} error={errors.items?.[idx]?.location_id?.message}>
                    <option value="">— Select location —</option>
                    {locations.map((l) => (
                      <option key={l.id} value={l.id}>
                        {l.label}
                      </option>
                    ))}
                  </Select>
                </div>
                <div className="col-span-2">
                  <Input
                    type="text"
                    placeholder="0"
                    className="font-mono tabular-nums text-right"
                    {...numberInputProps()}
                    {...register(`items.${idx}.quantity_issued` as const)}
                    error={errors.items?.[idx]?.quantity_issued?.message}
                  />
                </div>
                <div className="col-span-2">
                  <Input
                    placeholder="Optional"
                    maxLength={200}
                    {...register(`items.${idx}.remarks` as const)}
                    error={errors.items?.[idx]?.remarks?.message}
                  />
                </div>
                <div className="col-span-1 flex justify-end pt-1">
                  {fields.length > 1 && (
                    <button
                      type="button"
                      onClick={() => remove(idx)}
                      className="p-1 text-muted hover:text-danger-fg hover:bg-elevated rounded-sm"
                      aria-label="Remove line"
                    >
                      <Trash2 size={14} />
                    </button>
                  )}
                </div>
              </div>
            ))}
          </div>
          <div className="mt-3">
            <Button
              type="button"
              variant="secondary"
              size="sm"
              icon={<Plus size={14} />}
              onClick={() => append({ ...blankLine })}
            >
              Add line
            </Button>
          </div>
        </Panel>

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="secondary" onClick={() => nav('/inventory/material-issues')} disabled={mutation.isPending}>
            Cancel
          </Button>
          <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Saving…' : 'Create issue'}
          </Button>
        </div>
      </form>
    </div>
  );
}
