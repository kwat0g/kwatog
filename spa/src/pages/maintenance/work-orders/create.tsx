/** Sprint 8 — Task 69. Corrective maintenance WO create form. */
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import { useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';
const schema = z.object({
  maintainable_type: z.string().min(1, 'Target type is required'),
  maintainable_id: z.string().min(1, 'Target ID required'),
  type: z.string().min(1, 'Work order type is required'),
  priority: z.string().min(1, 'Priority is required'),
  description: z.string().min(1, 'Description is required').max(5000),
});
type FormValues = z.infer<typeof schema>;

export default function CreateMaintenanceWorkOrderPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { data: options, isFetching: optionsLoading, isError: optionsError, refetch: refetchOptions } = useQuery({
    queryKey: ['maintenance', 'work-order-options'],
    queryFn: () => workOrdersApi.options(),
  });

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { maintainable_type: '', maintainable_id: '', type: '', priority: '' },
  });
  const {
    register,
    handleSubmit,
    setError,
    watch,
    formState: { errors, isSubmitting },
  } = form;

  const maintainableType = watch('maintainable_type');
  const [targetSearch, setTargetSearch] = useState('');
  const targets = useQuery({
    queryKey: ['maintenance', 'targets', maintainableType, targetSearch],
    queryFn: () => workOrdersApi.targets(maintainableType, targetSearch),
    enabled: !!maintainableType,
  });
  const targetOptions = (targets.data?.data ?? []).map((target) => ({
    value: target.id,
    label: `${target.code} - ${target.name}`,
  }));

  const mutation = useMutation({
    mutationFn: (data: FormValues) =>
      workOrdersApi.create(data as Parameters<typeof workOrdersApi.create>[0]),
    onSuccess: (wo) => {
      qc.invalidateQueries({ queryKey: ['maintenance', 'work-orders'] });
      toast.success(`Work order ${wo.mwo_number} created.`);
      navigate(`/maintenance/work-orders/${wo.id}`);
    },
    onError: (error) => {
      applyServerValidationErrors(error, setError, 'Failed to create the maintenance work order.');
    },
  });
  const safety = useFormSafety({ form, saved: mutation.isSuccess });

  return (
    <div>
      <PageHeader
        title="New maintenance work order"
        backTo="/maintenance/work-orders"
        backLabel="Work orders"
      />
      <FormDraftBanner safety={safety} />
      {optionsError && <div role="alert" className="px-5 py-3 text-sm text-danger-fg">
        Maintenance work order options could not be loaded. <Button variant="secondary" size="sm" onClick={() => refetchOptions()}>Retry options</Button>
      </div>}

      <form
        onSubmit={handleSubmit((d) => { if (!optionsError && !optionsLoading && !targets.isError && !targets.isFetching) mutation.mutate(d); }, onFormInvalid<FormValues>())}
        className="min-w-0 w-full px-5 py-4"
      >
        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">
            Target
          </legend>
          <div className="grid grid-cols-2 gap-3">
            <Select
              label="Type"
              {...register('maintainable_type', { onChange: () => { form.setValue('maintainable_id', ''); setTargetSearch(''); } })}
              error={errors.maintainable_type?.message}
              required
            >
              <option value="">— Select —</option>
              {(options?.maintainable_types ?? []).map((type) => (
                <option key={type.value} value={type.value}>
                  {type.label}
                </option>
              ))}
            </Select>
            <Select
              searchable
              onSearch={setTargetSearch}
              label="Target"
              helper="Only equipment free for maintenance appears. Production must release equipment in use."
              {...register('maintainable_id')}
              error={errors.maintainable_id?.message}
              disabled={!maintainableType}
              required
            >
              <option value="">— Select Target —</option>
              {targetOptions.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
            {targets.isError && (
              <p role="alert" className="text-sm text-danger-fg">
                Targets could not load.{' '}
                <button type="button" className="underline" onClick={() => void targets.refetch()}>
                  Retry
                </button>
              </p>
            )}
          </div>
        </fieldset>

        <fieldset className="mb-6">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">
            Work order
          </legend>
          <div className="grid grid-cols-2 gap-3 mb-3">
            <Select label="Type" {...register('type')} error={errors.type?.message} required>
              <option value="">— Select —</option>
              {(options?.types ?? []).map((type) => (
                <option key={type.value} value={type.value}>
                  {type.label}
                </option>
              ))}
            </Select>
            <Select
              label="Priority"
              {...register('priority')}
              error={errors.priority?.message}
              required
            >
              <option value="">— Select —</option>
              {(options?.priorities ?? []).map((priority) => (
                <option key={priority.value} value={priority.value}>
                  {priority.label}
                </option>
              ))}
            </Select>
          </div>
          <Textarea
            label="Description"
            {...register('description')}
            rows={5}
            error={errors.description?.message}
            required
          />
        </fieldset>

        <FormActions>
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/maintenance/work-orders')}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={isSubmitting || mutation.isPending || optionsLoading || optionsError || targets.isError || targets.isFetching}
            loading={mutation.isPending}
          >
            {mutation.isPending ? 'Creating…' : 'Create work order'}
          </Button>
        </FormActions>
      </form>
    </div>
  );
}
