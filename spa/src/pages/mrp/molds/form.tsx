/**
 * Mold create/edit form. Mirrors StoreMoldRequest/UpdateMoldRequest.
 * Status is never submitted — the model defaults to available and lifecycle
 * changes flow through commission/decommission endpoints. product_id is a
 * hash ID resolved server-side via ResolvesHashIds.
 */
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { onFormInvalid } from '@/lib/formErrors';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { moldsApi } from '@/api/mrp/molds';
import { productsApi } from '@/api/crm/products';
import type { Mold, CreateMoldData, UpdateMoldData } from '@/types/mrp';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
const intField = (max: number, msg: string) =>
  z
    .string()
    .regex(/^\d+$/, msg)
    .refine((v) => Number(v) >= 1 && Number(v) <= max, `Use 1–${max}`);

const schema = z.object({
  mold_code: z
    .string()
    .regex(/^[A-Z0-9-]{2,20}$/, 'Use 2–20 uppercase letters, digits, or hyphens.'),
  name: z.string().min(1, 'Name is required').max(100),
  product_id: z.string().min(1, 'Product is required'),
  cavity_count: intField(512, 'Whole number of cavities'),
  cycle_time_seconds: intField(3600, 'Whole seconds'),
  output_rate_per_hour: intField(100000, 'Whole parts per hour'),
  setup_time_minutes: z
    .string()
    .regex(/^\d+$/, 'Whole minutes, or leave blank')
    .optional()
    .or(z.literal('')),
  max_shots_before_maintenance: intField(10000000, 'Whole number of shots'),
  lifetime_max_shots: intField(100000000, 'Whole number of shots'),
  location: z.string().max(50).optional().or(z.literal('')),
});

type FormValues = z.infer<typeof schema>;

interface Props {
  initial?: Mold;
  mode: 'create' | 'edit';
}

export function MoldForm({ initial, mode }: Props) {
  const navigate = useNavigate();
  const qc = useQueryClient();

  const products = useQuery({
    queryKey: ['crm', 'products', 'lookup'],
    queryFn: () => productsApi.list({ per_page: 200, is_active: 'true' }),
  });

    const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      mold_code: initial?.mold_code ?? '',
      name: initial?.name ?? '',
      product_id: initial?.product?.id ?? '',
      cavity_count: initial ? String(initial.cavity_count) : '',
      cycle_time_seconds: initial ? String(initial.cycle_time_seconds) : '',
      output_rate_per_hour: initial ? String(initial.output_rate_per_hour) : '',
      setup_time_minutes:
        initial?.setup_time_minutes != null ? String(initial.setup_time_minutes) : '',
      max_shots_before_maintenance: initial ? String(initial.max_shots_before_maintenance) : '',
      lifetime_max_shots: initial ? String(initial.lifetime_max_shots) : '',
      location: initial?.location ?? '',
    },
  });
  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = form;

  useEffect(() => {
    if (initial) {
      // `reset` rather than field-by-field `setValue`: one render instead of
      // nine, and it re-baselines the defaults so hydrating a fetched record
      // doesn't leave the form looking dirty before the user has touched it.
      reset({
        mold_code: initial.mold_code,
        name: initial.name,
        product_id: initial.product?.id ?? '',
        cavity_count: String(initial.cavity_count),
        cycle_time_seconds: String(initial.cycle_time_seconds),
        output_rate_per_hour: String(initial.output_rate_per_hour),
        setup_time_minutes:
          initial.setup_time_minutes != null ? String(initial.setup_time_minutes) : '',
        max_shots_before_maintenance: String(initial.max_shots_before_maintenance),
        lifetime_max_shots: String(initial.lifetime_max_shots),
        location: initial.location ?? '',
      });
    }
  }, [initial, reset]);

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const payload: CreateMoldData | UpdateMoldData = {
        mold_code: values.mold_code,
        name: values.name,
        product_id: values.product_id,
        cavity_count: Number(values.cavity_count),
        cycle_time_seconds: Number(values.cycle_time_seconds),
        output_rate_per_hour: Number(values.output_rate_per_hour),
        setup_time_minutes: values.setup_time_minutes ? Number(values.setup_time_minutes) : null,
        max_shots_before_maintenance: Number(values.max_shots_before_maintenance),
        lifetime_max_shots: Number(values.lifetime_max_shots),
        location: values.location?.trim() ? values.location : null,
      };
      return mode === 'create'
        ? moldsApi.create(payload as CreateMoldData)
        : moldsApi.update(initial!.id, payload);
    },
    onSuccess: (mold) => {
      qc.invalidateQueries({ queryKey: ['mrp', 'molds'] });
      toast.success(mode === 'create' ? 'Mold created.' : 'Mold updated.');
      navigate(`/mrp/molds/${mold.id}`);
    },
    onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
          setError(field as keyof FormValues, { type: 'server', message: msgs[0] });
        });
        toast.error(e.response?.data?.message || 'Validation failed.');
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to save mold.');
      }
    },
  });
  const safety = useFormSafety({ form, saved: mutation.isSuccess });

  return (
    <form
      onSubmit={handleSubmit((v) => mutation.mutate(v), onFormInvalid<FormValues>())}
      className="max-w-3xl mx-auto px-5 py-4"
    >
      <FormDraftBanner safety={safety} inset={false} />
      <fieldset className="mb-8">
        <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">
          Identification
        </legend>
        <div className="grid grid-cols-2 gap-3">
          <Input
            label="Mold Code"
            required
            {...register('mold_code')}
            error={errors.mold_code?.message}
            placeholder="e.g. MD-01"
            className="font-mono"
          />
          <Input
            label="Mold Name"
            required
            {...register('name')}
            error={errors.name?.message}
            placeholder="e.g. Wiper Bushing Mold 1"
          />
        </div>
        <div className="mt-3">
          <Select
            label="Product"
            required
            {...register('product_id')}
            error={errors.product_id?.message}
            disabled={products.isLoading}
          >
            <option value="">Select a product…</option>
            {products.data?.data.map((p) => (
              <option key={p.id} value={p.id}>
                {p.part_number} — {p.name}
              </option>
            ))}
          </Select>
        </div>
      </fieldset>

      <fieldset className="mb-8">
        <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">
          Performance
        </legend>
        <div className="grid grid-cols-2 gap-3">
          <Input
            label="Cavity Count"
            required
            {...register('cavity_count')}
            error={errors.cavity_count?.message}
            placeholder="e.g. 4"
            className="font-mono"
          />
          <Input
            label="Cycle Time (seconds)"
            required
            {...register('cycle_time_seconds')}
            error={errors.cycle_time_seconds?.message}
            placeholder="e.g. 30"
            className="font-mono"
          />
          <Input
            label="Output Rate (parts / hour)"
            required
            {...register('output_rate_per_hour')}
            error={errors.output_rate_per_hour?.message}
            placeholder="e.g. 480"
            className="font-mono"
          />
          <Input
            label="Setup Time (minutes)"
            {...register('setup_time_minutes')}
            error={errors.setup_time_minutes?.message}
            placeholder="e.g. 45"
            className="font-mono"
          />
        </div>
      </fieldset>

      <fieldset className="mb-8">
        <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">
          Maintenance
        </legend>
        <div className="grid grid-cols-2 gap-3">
          <Input
            label="Max Shots Before Maintenance"
            required
            {...register('max_shots_before_maintenance')}
            error={errors.max_shots_before_maintenance?.message}
            placeholder="e.g. 100000"
            className="font-mono"
          />
          <Input
            label="Lifetime Max Shots"
            required
            {...register('lifetime_max_shots')}
            error={errors.lifetime_max_shots?.message}
            placeholder="e.g. 1000000"
            className="font-mono"
          />
        </div>
        <div className="mt-3">
          <Input
            label="Location"
            {...register('location')}
            error={errors.location?.message}
            placeholder="e.g. Rack A-3"
          />
        </div>
      </fieldset>

      <div className="flex items-center justify-end gap-2">
        <Button type="button" variant="secondary" onClick={() => navigate(-1)}>
          Cancel
        </Button>
        <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending}>
          {isSubmitting || mutation.isPending
            ? 'Saving…'
            : mode === 'create'
              ? 'Create mold'
              : 'Save changes'}
        </Button>
      </div>
    </form>
  );
}
