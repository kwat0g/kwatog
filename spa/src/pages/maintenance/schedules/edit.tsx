import { useParams, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { schedulesApi } from '@/api/maintenance/schedules';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid } from '@/lib/formErrors';
import type { ApiValidationError } from '@/types';

const schema = z.object({
  description: z.string().min(1).max(200),
  interval_type: z.enum(['hours', 'days', 'shots']),
  interval_value: z.coerce.number().int().min(1),
  is_active: z.coerce.boolean(),
});
type FormValues = z.infer<typeof schema>;

export default function EditMaintenanceSchedulePage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['maintenance', 'schedules', id],
    queryFn: () => schedulesApi.show(id),
    enabled: !!id,
  });

  const { register, handleSubmit, setError, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    values: data ? {
      description: data.description,
      interval_type: data.interval_type,
      interval_value: data.interval_value,
      is_active: data.is_active,
    } : undefined,
  });

  const mutation = useMutation({
    mutationFn: (values: FormValues) => schedulesApi.update(id, values),
    onSuccess: (schedule) => {
      qc.invalidateQueries({ queryKey: ['maintenance', 'schedules'] });
      toast.success('Schedule updated.');
      navigate(`/maintenance/schedules/${schedule.id}`);
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      if (err.response?.status === 422 && err.response.data.errors) {
        Object.entries(err.response.data.errors).forEach(([k, v]) =>
          setError(k as keyof FormValues, { type: 'server', message: v[0] }));
        toast.error(err.response?.data?.message || 'Validation failed.');
      } else {
        toast.error('Failed to update schedule.');
      }
    },
  });

  if (isLoading) return <SkeletonDetail />;
  if (isError || !data) return (
    <EmptyState icon="alert-circle" title="Failed to load schedule"
      action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
  );

  return (
    <div>
      <PageHeader
        title="Edit schedule"
        backTo={`/maintenance/schedules/${id}`}
        backLabel="Schedule"
        breadcrumbs={[
          { label: 'Maintenance', href: '/maintenance' },
          { label: 'Schedules', href: '/maintenance/schedules' },
          { label: data.description, href: `/maintenance/schedules/${id}` },
          { label: 'Edit' },
        ]}
      />
      <form onSubmit={handleSubmit((v) => mutation.mutate(v), onFormInvalid<FormValues>())} className="max-w-2xl mx-auto px-5 py-6">
        <div className="mb-6 p-3 bg-subtle rounded-md text-sm">
          <span className="text-muted text-xs uppercase tracking-wider font-medium mr-2">Target</span>
          <span className="font-mono">{data.maintainable?.code ?? '—'}</span>
          <span className="ml-2 text-muted">{data.maintainable?.name}</span>
          <span className="ml-2">({data.maintainable_type})</span>
          <span className="ml-3 text-xs text-muted">(target cannot be changed)</span>
        </div>

        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Schedule</legend>
          <Input label="Description" {...register('description')} error={errors.description?.message} required />
          <div className="grid grid-cols-2 gap-3 mt-3">
            <Select label="Interval type" {...register('interval_type')} error={errors.interval_type?.message} required>
              <option value="hours">Hours (engine time)</option>
              <option value="days">Days (calendar)</option>
              <option value="shots">Shots (mold only)</option>
            </Select>
            <Input label="Interval value" type="number" {...register('interval_value')} error={errors.interval_value?.message} required />
          </div>
          <div className="mt-3">
            <Select label="Status" {...register('is_active')} error={errors.is_active?.message}>
              <option value="true">Active</option>
              <option value="false">Disabled</option>
            </Select>
          </div>
        </fieldset>

        <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate(`/maintenance/schedules/${id}`)}>Cancel</Button>
          <Button type="submit" variant="primary" loading={mutation.isPending}>
            {mutation.isPending ? 'Saving…' : 'Save changes'}
          </Button>
        </div>
      </form>
    </div>
  );
}
