/**
 * Task 12 — Routing editor page (create + edit).
 *
 * If `:id` param is present, loads an existing routing for editing.
 * Uses React Hook Form with useFieldArray for the operations list.
 * Follows the BOM create page pattern for dynamic sub-item rows.
 */
import { useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import { Plus, Trash2, GripVertical } from 'lucide-react';
import toast from 'react-hot-toast';
import { onFormInvalid } from '@/lib/formErrors';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { productsApi } from '@/api/crm/products';
import { machinesApi } from '@/api/mrp/machines';
import { moldsApi } from '@/api/mrp/molds';
import { routingsApi } from '@/api/production/routings';

// ─── Schema ──────────────────────────────────────────────────────

const operationSchema = z.object({
  sequence:           z.string().regex(/^\d+$/, 'Required').refine((v) => Number(v) > 0, 'Must be > 0'),
  operation_name:     z.string().min(1, 'Operation name is required').max(100),
  work_center:        z.string().max(100).optional().or(z.literal('')),
  machine_id:         z.string().optional().or(z.literal('')),
  mold_id:            z.string().optional().or(z.literal('')),
  setup_time_minutes: z.string().regex(/^\d+(\.\d{1,2})?$/, 'Use a non-negative decimal').optional().or(z.literal('')),
  cycle_time_minutes: z.string().regex(/^\d+(\.\d{1,2})?$/, 'Use a non-negative decimal').optional().or(z.literal('')),
  qc_required:        z.boolean(),
  description:        z.string().max(500).optional().or(z.literal('')),
});

const schema = z.object({
  product_id: z.string().min(1, 'Product is required'),
  notes:      z.string().max(1000).optional().or(z.literal('')),
  operations: z.array(operationSchema).min(1, 'Add at least one operation'),
});

type FormValues = z.infer<typeof schema>;

// ─── Component ───────────────────────────────────────────────────

export default function RoutingEditorPage() {
  const { id } = useParams<{ id: string }>();
  const isEdit = !!id;
  const navigate = useNavigate();
  const qc = useQueryClient();

  // ── Lookups ──────────────────────────────────────────────
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

  // ── Existing routing (edit mode) ─────────────────────────
  const existing = useQuery({
    queryKey: ['production', 'routings', 'detail', id],
    queryFn: () => routingsApi.show(id!),
    enabled: isEdit,
  });

  const {
    register, control, handleSubmit, setError, reset,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      product_id: '',
      notes: '',
      operations: [
        { sequence: '10', operation_name: '', work_center: '', machine_id: '', mold_id: '', setup_time_minutes: '0', cycle_time_minutes: '0', qc_required: false, description: '' },
      ],
    },
  });

  const { fields, append, remove } = useFieldArray({ control, name: 'operations' });

  // Populate form when editing an existing routing.
  useEffect(() => {
    if (!existing.data) return;
    const r = existing.data;
    reset({
      product_id: r.product?.id ?? '',
      notes: r.notes ?? '',
      operations: r.operations.length > 0
        ? r.operations.map((op) => ({
            sequence:           String(op.sequence),
            operation_name:     op.operation_name,
            work_center:        op.work_center ?? '',
            machine_id:         op.machine?.id ?? '',
            mold_id:            op.mold?.id ?? '',
            setup_time_minutes: op.setup_time_minutes ?? '0',
            cycle_time_minutes: op.cycle_time_minutes ?? '0',
            qc_required:        op.qc_required,
            description:        op.description ?? '',
          }))
        : [{ sequence: '10', operation_name: '', work_center: '', machine_id: '', mold_id: '', setup_time_minutes: '0', cycle_time_minutes: '0', qc_required: false, description: '' }],
    });
  }, [existing.data, reset]);

  // ── Mutations ────────────────────────────────────────────
  const saveMut = useMutation({
    mutationFn: (values: FormValues) => {
      const payload = {
        product_id: values.product_id,
        notes: values.notes || null,
        operations: values.operations.map((op) => ({
          sequence:           Number(op.sequence),
          operation_name:     op.operation_name,
          work_center:        op.work_center || null,
          machine_id:         op.machine_id || null,
          mold_id:            op.mold_id || null,
          setup_time_minutes: op.setup_time_minutes || '0',
          cycle_time_minutes: op.cycle_time_minutes || '0',
          qc_required:        op.qc_required,
          description:        op.description || null,
        })),
      };
      return isEdit
        ? routingsApi.update(id!, payload)
        : routingsApi.create(payload);
    },
    onSuccess: (routing) => {
      qc.invalidateQueries({ queryKey: ['production', 'routings'] });
      toast.success(isEdit ? 'Routing updated.' : `Routing v${routing.version} created.`);
      navigate(`/production/routings/${routing.id}`);
    },
    onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
          setError(field as never, { type: 'server', message: msgs[0] });
        });
        toast.error(e.response?.data?.message || 'Validation failed.');
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to save routing.');
      }
    },
  });

  // ── Loading / error for edit mode ────────────────────────
  if (isEdit && existing.isLoading) {
    return (
      <div>
        <PageHeader
          title="Loading routing..."
          backTo="/production/routings"
          backLabel="Routings"
          breadcrumbs={[
            { label: 'Production', href: '/production' },
            { label: 'Routings', href: '/production/routings' },
            { label: 'Loading...' },
          ]}
        />
        <SkeletonForm />
      </div>
    );
  }

  if (isEdit && existing.isError) {
    return (
      <div>
        <PageHeader
          title="Routing"
          backTo="/production/routings"
          backLabel="Routings"
          breadcrumbs={[
            { label: 'Production', href: '/production' },
            { label: 'Routings', href: '/production/routings' },
            { label: 'Error' },
          ]}
        />
        <EmptyState
          icon="alert-circle"
          title="Failed to load routing"
          action={<Button variant="secondary" onClick={() => existing.refetch()}>Retry</Button>}
        />
      </div>
    );
  }

  const pageTitle = isEdit
    ? `Edit routing${existing.data?.product ? ` — ${existing.data.product.part_number}` : ''}`
    : 'New routing';

  return (
    <div>
      <PageHeader
        title={pageTitle}
        backTo="/production/routings"
        backLabel="Routings"
        breadcrumbs={[
          { label: 'Production', href: '/production' },
          { label: 'Routings', href: '/production/routings' },
          { label: isEdit ? 'Edit' : 'New routing' },
        ]}
      />

      <form
        onSubmit={handleSubmit((v) => saveMut.mutate(v), onFormInvalid<FormValues>())}
        className="max-w-5xl mx-auto px-5 py-6"
      >
        {/* Product + notes */}
        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Product</legend>
          <div className="grid grid-cols-2 gap-3">
            <Select
              label="Finished good"
              required
              {...register('product_id')}
              error={errors.product_id?.message}
              disabled={isEdit}
            >
              <option value="">Select product...</option>
              {products.data?.data.map((p) => (
                <option key={p.id} value={p.id}>{p.part_number} — {p.name}</option>
              ))}
            </Select>
            <Textarea
              label="Notes"
              {...register('notes')}
              error={errors.notes?.message}
              maxLength={1000}
              placeholder="Optional routing notes..."
            />
          </div>
        </fieldset>

        {/* Operations table */}
        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Operations</legend>
          <div className="border border-default rounded-md overflow-hidden">
            <table className="w-full text-xs">
              <thead className="bg-subtle">
                <tr>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2 w-10">#</th>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Operation</th>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Work center</th>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Machine</th>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Mold</th>
                  <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Setup (min)</th>
                  <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Cycle (min)</th>
                  <th className="text-center text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">QC</th>
                  <th className="px-2 py-2 w-8" />
                </tr>
              </thead>
              <tbody>
                {fields.map((field, i) => (
                  <tr key={field.id} className="border-t border-subtle align-top">
                    <td className="px-2.5 py-1.5">
                      <div className="flex items-center gap-1 text-muted">
                        <GripVertical size={12} className="shrink-0" aria-hidden />
                        <Input
                          {...register(`operations.${i}.sequence` as const)}
                          error={errors.operations?.[i]?.sequence?.message}
                          className="font-mono text-right w-12"
                          placeholder="10"
                        />
                      </div>
                    </td>
                    <td className="px-2.5 py-1.5">
                      <Input
                        {...register(`operations.${i}.operation_name` as const)}
                        error={errors.operations?.[i]?.operation_name?.message}
                        placeholder="e.g. Injection molding"
                      />
                    </td>
                    <td className="px-2.5 py-1.5">
                      <Input
                        {...register(`operations.${i}.work_center` as const)}
                        error={errors.operations?.[i]?.work_center?.message}
                        placeholder="e.g. Line A"
                      />
                    </td>
                    <td className="px-2.5 py-1.5">
                      <Select
                        {...register(`operations.${i}.machine_id` as const)}
                        error={errors.operations?.[i]?.machine_id?.message}
                      >
                        <option value="">—</option>
                        {machines.data?.data.map((m) => (
                          <option key={m.id} value={m.id}>{m.machine_code} — {m.name}</option>
                        ))}
                      </Select>
                    </td>
                    <td className="px-2.5 py-1.5">
                      <Select
                        {...register(`operations.${i}.mold_id` as const)}
                        error={errors.operations?.[i]?.mold_id?.message}
                      >
                        <option value="">—</option>
                        {molds.data?.data.map((m) => (
                          <option key={m.id} value={m.id}>{m.mold_code} — {m.name}</option>
                        ))}
                      </Select>
                    </td>
                    <td className="px-2.5 py-1.5">
                      <Input
                        {...register(`operations.${i}.setup_time_minutes` as const)}
                        error={errors.operations?.[i]?.setup_time_minutes?.message}
                        placeholder="0"
                        className="font-mono text-right"
                      />
                    </td>
                    <td className="px-2.5 py-1.5">
                      <Input
                        {...register(`operations.${i}.cycle_time_minutes` as const)}
                        error={errors.operations?.[i]?.cycle_time_minutes?.message}
                        placeholder="0"
                        className="font-mono text-right"
                      />
                    </td>
                    <td className="px-2.5 py-1.5 text-center">
                      <div className="flex items-center justify-center h-8">
                        <Checkbox {...register(`operations.${i}.qc_required` as const)} />
                      </div>
                    </td>
                    <td className="px-2 py-1.5 text-right">
                      <button
                        type="button"
                        onClick={() => remove(i)}
                        disabled={fields.length === 1}
                        className="p-1.5 text-text-muted hover:text-danger hover:bg-elevated rounded-sm disabled:opacity-40 disabled:cursor-not-allowed"
                        aria-label="Remove operation"
                      >
                        <Trash2 size={14} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="mt-3 flex items-center gap-3">
            <Button
              type="button"
              variant="secondary"
              size="sm"
              icon={<Plus size={14} />}
              onClick={() => {
                const nextSeq = fields.length > 0
                  ? String((Math.max(...fields.map((_, i) => {
                      const el = document.querySelector<HTMLInputElement>(`[name="operations.${i}.sequence"]`);
                      return el ? Number(el.value) || 0 : 0;
                    })) + 10))
                  : '10';
                append({
                  sequence: nextSeq,
                  operation_name: '',
                  work_center: '',
                  machine_id: '',
                  mold_id: '',
                  setup_time_minutes: '0',
                  cycle_time_minutes: '0',
                  qc_required: false,
                  description: '',
                });
              }}
            >
              Add operation
            </Button>
          </div>

          {errors.operations?.message && (
            <p className="mt-2 text-xs text-danger">{errors.operations.message as string}</p>
          )}
        </fieldset>

        {/* Submit / cancel */}
        <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate('/production/routings')}>
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={isSubmitting || saveMut.isPending}
            loading={saveMut.isPending}
          >
            {saveMut.isPending ? 'Saving...' : isEdit ? 'Update routing' : 'Create routing'}
          </Button>
        </div>
      </form>
    </div>
  );
}
