/**
 * Sprint 6 audit §3.1 — Create Work Order page.
 *
 * Most WOs are created automatically by MrpEngineService::runForSalesOrder.
 * This page lets PPC manually queue a one-off planned WO (e.g. for a
 * sample run, internal R&D, or rework). Status starts as 'planned'; the
 * full lifecycle (confirm/start/pause/etc.) lives on the detail page.
 */
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { PageHeader } from '@/components/layout/PageHeader';
import { productsApi } from '@/api/crm/products';
import { machinesApi } from '@/api/mrp/machines';
import { moldsApi } from '@/api/mrp/molds';
import { workOrdersApi } from '@/api/production/workOrders';
import type { CreateWorkOrderData } from '@/types/production';

const schema = z.object({
  product_id:      z.string().min(1, 'Product is required'),
  quantity_target: z.string().regex(/^\d+$/, 'Use a positive integer').refine((v) => Number(v) > 0, 'Must be greater than 0'),
  planned_start:   z.string().min(1, 'Planned start is required'),
  planned_end:     z.string().min(1, 'Planned end is required'),
  machine_id:      z.string().optional().or(z.literal('')),
  mold_id:         z.string().optional().or(z.literal('')),
  priority:        z.string().regex(/^\d+$/, 'Use a non-negative integer').optional().or(z.literal('')),
}).refine(
  (v) => !v.planned_start || !v.planned_end || v.planned_end >= v.planned_start,
  { message: 'Planned end must be on or after planned start', path: ['planned_end'] },
);

type FormValues = z.infer<typeof schema>;

export default function CreateWorkOrderPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();

  const products = useQuery({
    queryKey: ['crm', 'products', 'lookup'],
    queryFn: () => productsApi.list({ per_page: 100, is_active: 'true' }),
  });
  const machines = useQuery({
    queryKey: ['mrp', 'machines', 'lookup'],
    queryFn: () => machinesApi.list({ per_page: 100 }),
  });
  const molds = useQuery({
    queryKey: ['mrp', 'molds', 'lookup'],
    queryFn: () => moldsApi.list({ per_page: 100 }),
  });

  const today = new Date().toISOString().slice(0, 16);

  const {
    register, handleSubmit, setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      product_id: '',
      quantity_target: '',
      planned_start: today,
      planned_end: today,
      machine_id: '',
      mold_id: '',
      priority: '50',
    },
  });

  const create = useMutation({
    mutationFn: (values: FormValues) => {
      const payload: CreateWorkOrderData = {
        product_id: values.product_id,
        quantity_target: Number(values.quantity_target),
        planned_start: values.planned_start,
        planned_end: values.planned_end,
        machine_id: values.machine_id || undefined,
        mold_id: values.mold_id || undefined,
        priority: values.priority ? Number(values.priority) : undefined,
      };
      return workOrdersApi.create(payload);
    },
    onSuccess: (wo) => {
      qc.invalidateQueries({ queryKey: ['production', 'work-orders'] });
      toast.success(`Work order ${wo.wo_number} created.`);
      navigate(`/production/work-orders/${wo.id}`);
    },
    onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
          setError(field as any, { type: 'server', message: msgs[0] });
        });
        toast.error('Please fix the errors below.');
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to create work order.');
      }
    },
  });

  return (
    <div>
      <PageHeader title="New work order" backTo="/production/work-orders" backLabel="Work orders" />
      <form
        onSubmit={handleSubmit((v) => create.mutate(v))}
        className="max-w-2xl mx-auto px-5 py-6"
      >
        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Product & quantity</legend>
          <div className="grid grid-cols-2 gap-3">
            <Select label="Product" required {...register('product_id')} error={errors.product_id?.message}>
              <option value="">Select product…</option>
              {products.data?.data.map((p) => (
                <option key={p.id} value={p.id}>{p.part_number} — {p.name}</option>
              ))}
            </Select>
            <Input
              label="Quantity target" type="number" min={1} required
              {...register('quantity_target')} error={errors.quantity_target?.message}
              placeholder="e.g. 1000"
              className="font-mono text-right"
            />
          </div>
        </fieldset>

        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Schedule</legend>
          <div className="grid grid-cols-2 gap-3">
            <Input
              label="Planned start" type="datetime-local" required
              {...register('planned_start')} error={errors.planned_start?.message}
              className="font-mono"
            />
            <Input
              label="Planned end" type="datetime-local" required
              {...register('planned_end')} error={errors.planned_end?.message}
              className="font-mono"
            />
            <Input
              label="Priority (0–255)" type="number" min={0} max={255}
              {...register('priority')} error={errors.priority?.message}
              className="font-mono text-right"
            />
          </div>
        </fieldset>

        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">
            Resources <span className="text-2xs lowercase tracking-normal text-subtle">— optional, can be assigned at confirm time</span>
          </legend>
          <div className="grid grid-cols-2 gap-3">
            <Select label="Machine" {...register('machine_id')} error={errors.machine_id?.message}>
              <option value="">Pick later</option>
              {machines.data?.data.map((m) => (
                <option key={m.id} value={m.id}>{m.machine_code} — {m.name}{m.tonnage ? ` · ${m.tonnage}T` : ''}</option>
              ))}
            </Select>
            <Select label="Mold" {...register('mold_id')} error={errors.mold_id?.message}>
              <option value="">Pick later</option>
              {molds.data?.data.map((m) => (
                <option key={m.id} value={m.id}>{m.mold_code} — {m.name}</option>
              ))}
            </Select>
          </div>
        </fieldset>

        <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate('/production/work-orders')}>
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={isSubmitting || create.isPending}
            loading={create.isPending}
          >
            {create.isPending ? 'Creating…' : 'Create work order'}
          </Button>
        </div>
      </form>
    </div>
  );
}
