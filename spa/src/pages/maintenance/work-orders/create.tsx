/** Sprint 8 — Task 69. Corrective maintenance WO create form. */
import { useNavigate } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import type { ApiValidationError } from '@/types';

const schema = z.object({
  maintainable_type: z.enum(['machine', 'mold']),
  maintainable_id: z.coerce.number().int().min(1, 'Target ID required'),
  type: z.enum(['preventive', 'corrective']).default('corrective'),
  priority: z.enum(['critical', 'high', 'medium', 'low']),
  description: z.string().min(1, 'Description is required').max(5000),
});
type FormValues = z.infer<typeof schema>;

export default function CreateMaintenanceWorkOrderPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { maintainable_type: 'machine', type: 'corrective', priority: 'medium' },
  });

  const mutation = useMutation({
    mutationFn: (data: FormValues) => workOrdersApi.create(data),
    onSuccess: (wo) => {
      qc.invalidateQueries({ queryKey: ['maintenance', 'work-orders'] });
      toast.success(`Work order ${wo.mwo_number} created.`);
      navigate(`/maintenance/work-orders/${wo.id}`);
    },
    onError: (error: AxiosError<ApiValidationError>) => {
      if (error.response?.status === 422 && error.response.data.errors) {
        Object.entries(error.response.data.errors).forEach(([field, messages]) => {
          setError(field as keyof FormValues, { type: 'server', message: messages[0] });
        });
        toast.error('Please fix the errors below.');
      }
    },
  });

  return (
    <div>
      <PageHeader title="New maintenance work order" backTo="/maintenance/work-orders" backLabel="Work orders" />

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
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-3">Work order</legend>
          <div className="grid grid-cols-2 gap-3 mb-3">
            <Select label="Type" {...register('type')} error={errors.type?.message} required>
              <option value="corrective">Corrective</option>
              <option value="preventive">Preventive</option>
            </Select>
            <Select label="Priority" {...register('priority')} error={errors.priority?.message} required>
              <option value="critical">Critical</option>
              <option value="high">High</option>
              <option value="medium">Medium</option>
              <option value="low">Low</option>
            </Select>
          </div>
          <Textarea label="Description" {...register('description')} rows={5} error={errors.description?.message} required />
        </fieldset>

        <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate('/maintenance/work-orders')}>Cancel</Button>
          <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Creating…' : 'Create work order'}
          </Button>
        </div>
      </form>
    </div>
  );
}
