/** Succession Plans — create / edit form page. */
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { successionPlansApi } from '@/api/hr/succession';
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
  position_id: z.string().min(1, 'Position is required'),
  incumbent_id: z.string().optional().or(z.literal('')),
  successor_id: z.string().min(1, 'Successor is required'),
  readiness: z.enum(['ready_now', 'ready_1_year', 'ready_2_years', 'development_needed']),
  priority: z.enum(['critical', 'high', 'medium', 'low']),
  development_notes: z.string().max(5000).optional().or(z.literal('')),
  target_date: z.string().optional().or(z.literal('')),
});
type FormValues = z.infer<typeof schema>;

export default function SuccessionPlanFormPage() {
  const { id } = useParams<{ id: string }>();
  const isEdit = !!id;
  const navigate = useNavigate();
  const qc = useQueryClient();

  /* ---------- load existing plan for edit ---------- */
  const { data: existing, isLoading: loadingExisting, isError: loadError, refetch } = useQuery({
    queryKey: ['succession-plan', id],
    queryFn: () => successionPlansApi.show(id!),
    enabled: isEdit,
  });

  /* ---------- form ---------- */
  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      readiness: 'development_needed',
      priority: 'medium',
    },
    values: isEdit && existing
      ? {
          position_id: existing.position.id,
          incumbent_id: existing.incumbent?.id ?? '',
          successor_id: existing.successor.id,
          readiness: existing.readiness,
          priority: existing.priority,
          development_notes: existing.development_notes ?? '',
          target_date: existing.target_date ? existing.target_date.slice(0, 10) : '',
        }
      : undefined,
  });

  /* ---------- mutation ---------- */
  const mutation = useMutation({
    mutationFn: (formData: FormValues) => {
      const payload = {
        position_id: formData.position_id,
        successor_id: formData.successor_id,
        incumbent_id: formData.incumbent_id || undefined,
        readiness: formData.readiness,
        priority: formData.priority,
        development_notes: formData.development_notes || undefined,
        target_date: formData.target_date || undefined,
      };
      return isEdit
        ? successionPlansApi.update(id!, payload)
        : successionPlansApi.create(payload as Required<typeof payload> & { position_id: string; successor_id: string; readiness: FormValues['readiness']; priority: FormValues['priority'] });
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['succession-plans'] });
      if (isEdit) {
        qc.invalidateQueries({ queryKey: ['succession-plan', id] });
      }
      toast.success(isEdit ? 'Succession plan updated.' : 'Succession plan created.');
      navigate('/hr/succession-plans');
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      if (err.response?.status === 422 && err.response.data.errors) {
        Object.entries(err.response.data.errors).forEach(([k, v]) =>
          setError(k as keyof FormValues, { type: 'server', message: v[0] }));
        toast.error(err.response?.data?.message || 'Validation failed.');
      } else {
        toast.error(isEdit ? 'Failed to update succession plan.' : 'Failed to create succession plan.');
      }
    },
  });

  /* ---------- loading / error states for edit ---------- */
  if (isEdit && loadingExisting) return <SkeletonDetail />;
  if (isEdit && (loadError || !existing)) {
    return (
      <EmptyState
        icon="alert-circle"
        title="Failed to load succession plan"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
      />
    );
  }

  return (
    <div>
      <PageHeader
        title={isEdit ? 'Edit succession plan' : 'New succession plan'}
        backTo="/hr/succession-plans"
        backLabel="Succession Plans"
      />
      <form
        onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
        className="max-w-3xl mx-auto px-5 py-6"
      >
        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Position &amp; People</legend>
          <Input
            label="Position ID"
            {...register('position_id')}
            error={errors.position_id?.message}
            placeholder="Enter position hash ID"
            required
          />
          <div className="grid grid-cols-2 gap-3 mt-3">
            <Input
              label="Incumbent ID"
              {...register('incumbent_id')}
              error={errors.incumbent_id?.message}
              placeholder="Current holder (optional)"
            />
            <Input
              label="Successor ID"
              {...register('successor_id')}
              error={errors.successor_id?.message}
              placeholder="Proposed successor"
              required
            />
          </div>
        </fieldset>

        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Assessment</legend>
          <div className="grid grid-cols-2 gap-3">
            <Select label="Readiness" {...register('readiness')} error={errors.readiness?.message} required>
              <option value="ready_now">Ready now</option>
              <option value="ready_1_year">Ready in 1 year</option>
              <option value="ready_2_years">Ready in 2 years</option>
              <option value="development_needed">Development needed</option>
            </Select>
            <Select label="Priority" {...register('priority')} error={errors.priority?.message} required>
              <option value="critical">Critical</option>
              <option value="high">High</option>
              <option value="medium">Medium</option>
              <option value="low">Low</option>
            </Select>
          </div>
          <div className="mt-3">
            <Input
              label="Target date"
              type="date"
              {...register('target_date')}
              error={errors.target_date?.message}
            />
          </div>
        </fieldset>

        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Notes</legend>
          <Textarea
            label="Development notes"
            {...register('development_notes')}
            rows={4}
            error={errors.development_notes?.message}
            placeholder="Training, mentoring, or rotation plans for the successor…"
          />
        </fieldset>

        <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate('/hr/succession-plans')}>
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={isSubmitting || mutation.isPending}
            loading={mutation.isPending}
          >
            {mutation.isPending
              ? (isEdit ? 'Saving…' : 'Creating…')
              : (isEdit ? 'Save changes' : 'Create plan')}
          </Button>
        </div>
      </form>
    </div>
  );
}
