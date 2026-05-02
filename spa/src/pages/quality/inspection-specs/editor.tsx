/**
 * Sprint 7 — Task 59 — Inspection spec editor.
 *
 * Routed at /quality/inspection-specs/:productId where productId is the
 * product hash_id. The page calls inspectionSpecsApi.forProduct(productId)
 * to fetch the active spec (if any) and pre-fills the form. Saving POSTs
 * to /quality/inspection-specs (the upsert endpoint) — the service bumps
 * the version counter on every successful save.
 *
 * Special route /quality/inspection-specs/new lets the user pick a product
 * first; once picked, the page swaps to editor mode with that product
 * locked in.
 */
import { useEffect, useMemo, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import { Plus, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { productsApi } from '@/api/crm/products';
import { inspectionSpecsApi } from '@/api/quality/inspectionSpecs';
import type { UpsertInspectionSpecData } from '@/types/quality';

const itemSchema = z.object({
  parameter_name:  z.string().min(1, 'Parameter name is required').max(150),
  parameter_type:  z.enum(['dimensional', 'visual', 'functional']),
  unit_of_measure: z.string().max(20).optional().or(z.literal('')),
  nominal_value:   z.string().regex(/^-?\d+(\.\d{1,4})?$/, 'Use a decimal with up to 4 places').optional().or(z.literal('')),
  tolerance_min:   z.string().regex(/^-?\d+(\.\d{1,4})?$/, 'Use a decimal with up to 4 places').optional().or(z.literal('')),
  tolerance_max:   z.string().regex(/^-?\d+(\.\d{1,4})?$/, 'Use a decimal with up to 4 places').optional().or(z.literal('')),
  is_critical:     z.boolean().optional(),
  notes:           z.string().max(500).optional().or(z.literal('')),
});

const schema = z.object({
  product_id: z.string().min(1, 'Product is required'),
  notes:      z.string().max(2000).optional().or(z.literal('')),
  items:      z.array(itemSchema).min(1, 'Add at least one parameter'),
});

type FormValues = z.infer<typeof schema>;

export default function InspectionSpecEditorPage() {
  const { productId: productIdParam } = useParams<{ productId: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const isNewMode = productIdParam === 'new';
  const [pickedProductId, setPickedProductId] = useState('');
  const productId = isNewMode ? pickedProductId : (productIdParam ?? '');

  const products = useQuery({
    queryKey: ['crm', 'products', 'lookup'],
    queryFn: () => productsApi.list({ per_page: 100, is_active: 'true' }),
  });
  const existing = useQuery({
    queryKey: ['quality', 'inspection-specs', 'for-product', productId],
    queryFn: () => inspectionSpecsApi.forProduct(productId),
    enabled: !!productId,
  });

  const {
    register, control, handleSubmit, reset, setError, watch,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      product_id: productId,
      notes: '',
      items: [{ parameter_name: '', parameter_type: 'dimensional', unit_of_measure: '', nominal_value: '', tolerance_min: '', tolerance_max: '', is_critical: false, notes: '' }],
    },
  });
  const { fields, append, remove } = useFieldArray({ control, name: 'items' });

  // Pre-fill once the existing spec query resolves (or once user picks a product in new mode).
  useEffect(() => {
    if (!productId) return;
    if (existing.data === undefined) return;
    if (existing.data) {
      reset({
        product_id: productId,
        notes: existing.data.notes ?? '',
        items: (existing.data.items ?? []).map((it) => ({
          parameter_name:  it.parameter_name,
          parameter_type:  it.parameter_type,
          unit_of_measure: it.unit_of_measure ?? '',
          nominal_value:   it.nominal_value ?? '',
          tolerance_min:   it.tolerance_min ?? '',
          tolerance_max:   it.tolerance_max ?? '',
          is_critical:     it.is_critical,
          notes:           it.notes ?? '',
        })),
      });
    } else {
      reset({
        product_id: productId,
        notes: '',
        items: [{ parameter_name: '', parameter_type: 'dimensional', unit_of_measure: '', nominal_value: '', tolerance_min: '', tolerance_max: '', is_critical: false, notes: '' }],
      });
    }
  }, [productId, existing.data, reset]);

  const upsert = useMutation({
    mutationFn: (values: FormValues) => {
      const payload: UpsertInspectionSpecData = {
        product_id: values.product_id,
        notes: values.notes || undefined,
        items: values.items.map((row, i) => ({
          parameter_name:  row.parameter_name,
          parameter_type:  row.parameter_type,
          unit_of_measure: row.unit_of_measure || undefined,
          nominal_value:   row.nominal_value || undefined,
          tolerance_min:   row.tolerance_min || undefined,
          tolerance_max:   row.tolerance_max || undefined,
          is_critical:     row.is_critical ?? false,
          sort_order:      i,
          notes:           row.notes || undefined,
        })),
      };
      return inspectionSpecsApi.upsert(payload);
    },
    onSuccess: (spec) => {
      qc.invalidateQueries({ queryKey: ['quality', 'inspection-specs'] });
      qc.invalidateQueries({ queryKey: ['quality', 'inspection-specs', 'for-product', productId] });
      toast.success(`Spec v${spec.version} saved.`);
      navigate(`/quality/inspection-specs/${productId}`);
    },
    onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
          setError(field as any, { type: 'server', message: msgs[0] });
        });
        toast.error('Please fix the errors below.');
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to save spec.');
      }
    },
  });

  const productLabel = useMemo(() => {
    const p = products.data?.data.find((pp: any) => pp.id === productId);
    return p ? `${p.part_number} — ${p.name}` : '';
  }, [products.data, productId]);

  // ── New-spec mode without picked product yet
  if (isNewMode && !pickedProductId) {
    return (
      <div>
        <PageHeader title="New inspection spec" backTo="/quality/inspection-specs" backLabel="Inspection specs" />
        <div className="max-w-2xl mx-auto px-5 py-6 space-y-4">
          <Select
            label="Product"
            required
            value={pickedProductId}
            onChange={(e) => setPickedProductId(e.target.value)}
          >
            <option value="">Select product…</option>
            {products.data?.data.map((p: any) => (
              <option key={p.id} value={p.id}>{p.part_number} — {p.name}</option>
            ))}
          </Select>
          <p className="text-xs text-muted">
            Pick a product to author or replace its inspection spec. Each product carries one active spec; saving creates a new version.
          </p>
        </div>
      </div>
    );
  }

  // ── Loading existing spec
  if (productId && existing.isLoading) {
    return (
      <div>
        <PageHeader title="Inspection spec" backTo="/quality/inspection-specs" backLabel="Inspection specs" />
        <SkeletonForm />
      </div>
    );
  }

  // ── Error loading existing spec
  if (existing.isError) {
    return (
      <div>
        <PageHeader title="Inspection spec" backTo="/quality/inspection-specs" backLabel="Inspection specs" />
        <EmptyState
          icon="alert-circle"
          title="Failed to load spec"
          action={<Button variant="secondary" onClick={() => existing.refetch()}>Retry</Button>}
        />
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title={
          <div className="flex items-center gap-3">
            <span>{productLabel || 'Inspection spec'}</span>
            {existing.data && <Chip variant={existing.data.is_active ? 'success' : 'neutral'}>v{existing.data.version}</Chip>}
            {!existing.data && <Chip variant="info">New</Chip>}
          </div>
        }
        backTo="/quality/inspection-specs"
        backLabel="Inspection specs"
      />
      <form
        onSubmit={handleSubmit((v) => upsert.mutate(v))}
        className="max-w-5xl mx-auto px-5 py-6"
      >
        <input type="hidden" {...register('product_id')} value={productId} />

        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Notes</legend>
          <Textarea
            rows={2}
            {...register('notes')}
            error={errors.notes?.message}
            placeholder="Optional context for this revision."
          />
        </fieldset>

        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Parameters</legend>
          <div className="border border-default rounded-md overflow-hidden">
            <table className="w-full text-xs">
              <thead className="bg-subtle">
                <tr>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2 w-1/4">Parameter</th>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Type</th>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">UOM</th>
                  <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Nominal</th>
                  <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Min</th>
                  <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Max</th>
                  <th className="text-center text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Critical?</th>
                  <th className="px-2 py-2" />
                </tr>
              </thead>
              <tbody>
                {fields.map((field, i) => (
                  <tr key={field.id} className="border-t border-subtle">
                    <td className="px-2.5 py-1.5">
                      <Input
                        {...register(`items.${i}.parameter_name` as const)}
                        error={errors.items?.[i]?.parameter_name?.message}
                        placeholder="e.g. Shaft OD"
                      />
                    </td>
                    <td className="px-2.5 py-1.5">
                      <Select
                        {...register(`items.${i}.parameter_type` as const)}
                        error={errors.items?.[i]?.parameter_type?.message}
                      >
                        <option value="dimensional">Dimensional</option>
                        <option value="visual">Visual</option>
                        <option value="functional">Functional</option>
                      </Select>
                    </td>
                    <td className="px-2.5 py-1.5">
                      <Input
                        {...register(`items.${i}.unit_of_measure` as const)}
                        error={errors.items?.[i]?.unit_of_measure?.message}
                        placeholder="mm"
                        className="font-mono"
                      />
                    </td>
                    <td className="px-2.5 py-1.5 text-right">
                      <Input
                        {...register(`items.${i}.nominal_value` as const)}
                        error={errors.items?.[i]?.nominal_value?.message}
                        placeholder="0.0000"
                        className="font-mono text-right"
                      />
                    </td>
                    <td className="px-2.5 py-1.5 text-right">
                      <Input
                        {...register(`items.${i}.tolerance_min` as const)}
                        error={errors.items?.[i]?.tolerance_min?.message}
                        placeholder="0.0000"
                        className="font-mono text-right"
                      />
                    </td>
                    <td className="px-2.5 py-1.5 text-right">
                      <Input
                        {...register(`items.${i}.tolerance_max` as const)}
                        error={errors.items?.[i]?.tolerance_max?.message}
                        placeholder="0.0000"
                        className="font-mono text-right"
                      />
                    </td>
                    <td className="px-2.5 py-1.5 text-center">
                      <input
                        type="checkbox"
                        {...register(`items.${i}.is_critical` as const)}
                        className="rounded border-default"
                      />
                    </td>
                    <td className="px-2 py-1.5 text-right">
                      <button
                        type="button"
                        onClick={() => remove(i)}
                        disabled={fields.length === 1}
                        className="p-1.5 text-text-muted hover:text-danger hover:bg-elevated rounded-sm disabled:opacity-40 disabled:cursor-not-allowed"
                        aria-label="Remove parameter"
                      >
                        <Trash2 size={14} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="mt-3">
            <Button
              type="button"
              variant="secondary"
              size="sm"
              icon={<Plus size={14} />}
              onClick={() => append({ parameter_name: '', parameter_type: 'dimensional', unit_of_measure: '', nominal_value: '', tolerance_min: '', tolerance_max: '', is_critical: false, notes: '' })}
            >
              Add parameter
            </Button>
          </div>

          {errors.items?.message && <p className="mt-2 text-xs text-danger">{errors.items.message as string}</p>}
        </fieldset>

        <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate('/quality/inspection-specs')}>
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={isSubmitting || upsert.isPending}
            loading={upsert.isPending}
          >
            {upsert.isPending ? 'Saving…' : (existing.data ? 'Save new version' : 'Create spec')}
          </Button>
        </div>
      </form>
    </div>
  );
}
