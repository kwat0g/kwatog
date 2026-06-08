/** Sprint 8 — Task 10. Edit asset form. */
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { assetsApi } from '@/api/assets';
import { departmentsApi } from '@/api/hr/departments';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { onFormInvalid } from '@/lib/formErrors';
import type { ApiValidationError } from '@/types';

const schema = z.object({
  name: z.string().min(1, 'Name is required').max(200),
  description: z.string().max(5000).optional().or(z.literal('')),
  category: z.enum(['machine', 'mold', 'vehicle', 'equipment', 'furniture', 'other']),
  department_id: z.coerce.number().int().optional(),
  acquisition_date: z.string().min(1, 'Acquisition date required'),
  acquisition_cost: z.string().regex(/^\d+(\.\d{1,2})?$/, 'Enter amount like 100000.00'),
  useful_life_years: z.coerce.number().int().min(1).max(100),
  salvage_value: z.string().regex(/^\d+(\.\d{1,2})?$/).optional().or(z.literal('')),
  location: z.string().max(100).optional().or(z.literal('')),
});
type FormValues = z.infer<typeof schema>;

export default function EditAssetPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['asset', id],
    queryFn: () => assetsApi.show(id),
    enabled: !!id,
  });

  const { data: deptData, isLoading: deptLoading } = useQuery({
    queryKey: ['hr', 'departments', 'list'],
    queryFn: () => departmentsApi.list({ per_page: 200 }),
    staleTime: 300_000,
  });

  // Resolve the asset's department hash_id to the numeric id used by the select options.
  // deptData items use the same hash_id string as department.id on the asset.
  // z.coerce.number() will coerce the string value from the <select> to a number on submit.
  const resolvedDeptId = data?.department?.id
    ? (deptData?.data?.find((d) => d.id === data.department!.id)?.id ?? undefined)
    : undefined;

  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    values: data
      ? {
          name: data.name,
          description: data.description ?? '',
          category: data.category,
          department_id: resolvedDeptId as unknown as number | undefined,
          acquisition_date: data.acquisition_date.slice(0, 10),
          acquisition_cost: data.acquisition_cost,
          useful_life_years: data.useful_life_years,
          salvage_value: data.salvage_value ?? '0',
          location: data.location ?? '',
        }
      : undefined,
  });

  const mutation = useMutation({
    mutationFn: (formData: FormValues) =>
      assetsApi.update(id, {
        ...formData,
        description: formData.description || undefined,
        salvage_value: formData.salvage_value || '0',
        location: formData.location || undefined,
        department_id: formData.department_id || null,
      }),
    onSuccess: (asset) => {
      qc.invalidateQueries({ queryKey: ['assets'] });
      qc.invalidateQueries({ queryKey: ['asset', id] });
      toast.success(`Asset ${asset.asset_code} updated.`);
      navigate(`/assets/${asset.id}`);
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      if (err.response?.status === 422 && err.response.data.errors) {
        Object.entries(err.response.data.errors).forEach(([k, v]) =>
          setError(k as keyof FormValues, { type: 'server', message: v[0] }));
        toast.error(err.response?.data?.message || 'Validation failed.');
      } else {
        toast.error('Failed to update asset.');
      }
    },
  });

  if (isLoading) return <SkeletonDetail />;
  if (isError || !data) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Failed to load asset"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
      />
    );
  }

  return (
    <div>
      <PageHeader
        title={`Edit ${data.asset_code}`}
        subtitle={data.name}
        backTo={`/assets/${id}`}
        backLabel={data.asset_code}
      />
      <form
        onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
        className="max-w-3xl mx-auto px-5 py-6"
      >
        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Identification</legend>
          <Input label="Name" {...register('name')} error={errors.name?.message} required />
          <div className="grid grid-cols-2 gap-3 mt-3">
            <Select label="Category" {...register('category')} error={errors.category?.message} required>
              <option value="machine">Machine</option>
              <option value="mold">Mold</option>
              <option value="vehicle">Vehicle</option>
              <option value="equipment">Equipment</option>
              <option value="furniture">Furniture</option>
              <option value="other">Other</option>
            </Select>
            <Select
              label="Department"
              {...register('department_id')}
              error={errors.department_id?.message}
              disabled={deptLoading}
            >
              <option value="">{deptLoading ? 'Loading…' : '— None —'}</option>
              {deptData?.data?.map((d) => (
                <option key={d.id} value={d.id}>{d.name}</option>
              ))}
            </Select>
          </div>
          <div className="mt-3">
            <Input
              label="Location"
              {...register('location')}
              error={errors.location?.message}
              placeholder="e.g. Production floor"
            />
          </div>
          <div className="mt-3">
            <Textarea label="Description" {...register('description')} rows={3} error={errors.description?.message} />
          </div>
        </fieldset>

        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Acquisition</legend>
          <div className="grid grid-cols-3 gap-3">
            <Input
              label="Acquisition date"
              type="date"
              {...register('acquisition_date')}
              error={errors.acquisition_date?.message}
              required
            />
            <Input
              label="Acquisition cost (₱)"
              {...register('acquisition_cost')}
              error={errors.acquisition_cost?.message}
              className="font-mono"
              placeholder="0.00"
              required
            />
            <Input
              label="Useful life (years)"
              type="number"
              {...register('useful_life_years')}
              error={errors.useful_life_years?.message}
              required
            />
          </div>
          <div className="mt-3 max-w-xs">
            <Input
              label="Salvage value (₱)"
              {...register('salvage_value')}
              error={errors.salvage_value?.message}
              className="font-mono"
              placeholder="0.00"
            />
          </div>
        </fieldset>

        <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate(`/assets/${id}`)}>
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={isSubmitting || mutation.isPending}
            loading={mutation.isPending}
          >
            {mutation.isPending ? 'Saving…' : 'Save changes'}
          </Button>
        </div>
      </form>
    </div>
  );
}
