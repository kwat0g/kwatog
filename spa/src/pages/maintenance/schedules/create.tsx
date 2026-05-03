/** Sprint 8 — Task 69. Create maintenance schedule. */
import { useNavigate } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { schedulesApi } from '@/api/maintenance/schedules';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { PageHeader } from '@/components/layout/PageHeader';
import type { ApiValidationError } from '@/types';

const schema = z.object({
  maintainable_type: z.enum(['machine', 'mold']),
  maintainable_id: z.coerce.number().int().min(1, 'Target ID required'),
  description: z.string().min(1).max(200),
  interval_type: z.enum(['hours', 'days', 'shots']),
  interval_value: z.coerce.number().int().min(1),
  is_active: z.coerce.boolean().default(true),
}).refine((d) => !(d.interval_type === 'shots' && d.maintainable_type !== 'mold'), {
  message: 'Shot-based schedules are only valid for molds.', path: ['interval_type'],
});
type FormValues = z.infer<typeof schema>;

export default function CreateMaintenanceSchedulePage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { maintainable_type: 'machine', interval_type: 'days', is_active: true },
  });

  const mutation = useMutation({
    mutationFn: (data: FormValues) => schedulesApi.create(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['maintenance', 'schedules'] });
      toast.success('Schedule created.');
      navigate('/maintenance/schedules');
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      if (err.response?.status === 422 && err.response.data.errors) {
        Object.entries(err.response.data.errors).forEach(([k, v]) =>
          setError(k as keyof FormValues, { type: 'server', message: v[0] }));
        toast.error('Please fix the errors below.');
      }
    },
  });

  return (
    <div>
      <PageHeader title="New maintenance schedule" backTo="/maintenance/schedules" backLabel="Schedules" />
      <form onSubmit={handleSubmit((d) => mutation.mutate(d))} className="max-w-2xl mx-auto px-5 py-6">
        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Target</legend>
          <div className="grid grid-cols-2 gap-3">
            <Select label="Type" {...register('maintainable_type')} error={errors.maintainable_type?.message} required>
              <option value="machine">Machine</option>
              <option value="mold">Mold</option>
            </Select>
            <Input label="Target ID" type="number" {...register('maintainable_id')} error={errors.maintainable_id?.message} required />
          </div>
        </fieldset>

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
        </fieldset>

        <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate('/maintenance/schedules')}>Cancel</Button>
          <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Creating…' : 'Create schedule'}
          </Button>
        </div>
      </form>
    </div>
  );
}
