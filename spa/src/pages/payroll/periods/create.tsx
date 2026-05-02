import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { periodsApi } from '@/api/payroll/periods';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors } from '@/lib/formErrors';
import type { ApiValidationError } from '@/types';

const schema = z.object({
  period_start:  z.string().min(1, 'Start date is required'),
  period_end:    z.string().min(1, 'End date is required'),
  payroll_date:  z.string().min(1, 'Payroll date is required'),
  is_first_half: z.string().min(1, 'Pick a half'),
});

type FormValues = z.infer<typeof schema>;

export default function CreatePayrollPeriodPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [submitting, setSubmitting] = useState(false);

  const { register, handleSubmit, setError, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { is_first_half: 'true' },
  });

  const mutation = useMutation({
    mutationFn: (data: FormValues) => periodsApi.create({
      period_start: data.period_start,
      period_end: data.period_end,
      payroll_date: data.payroll_date,
      is_first_half: data.is_first_half === 'true',
    }),
    onSuccess: (period) => {
      qc.invalidateQueries({ queryKey: ['payroll-periods'] });
      toast.success('Payroll period created.');
      navigate(`/payroll/periods/${period.id}`);
    },
    onError: (error: AxiosError<ApiValidationError>) => {
      if (!applyServerValidationErrors(error, setError, 'Failed to create payroll period.')) {
        toast.error(error.response?.data?.message ?? 'Failed to create payroll period.');
      }
    },
  });

  const onSubmit = (data: FormValues) => {
    setSubmitting(true);
    mutation.mutate(data, { onSettled: () => setSubmitting(false) });
  };

  return (
    <div>
      <PageHeader title="New Payroll Period" backTo="/payroll/periods" backLabel="Payroll" />
      <form onSubmit={handleSubmit(onSubmit)} className="max-w-2xl mx-auto px-5 py-6">
        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Schedule</legend>
          <div className="grid grid-cols-2 gap-3">
            <Input label="Period start" type="date" required {...register('period_start')} error={errors.period_start?.message} />
            <Input label="Period end"   type="date" required {...register('period_end')}   error={errors.period_end?.message} />
            <Input label="Payroll date" type="date" required {...register('payroll_date')} error={errors.payroll_date?.message} />
            <Select label="Cycle" required {...register('is_first_half')} error={errors.is_first_half?.message}>
              <option value="true">1st half (gov deductions apply)</option>
              <option value="false">2nd half</option>
            </Select>
          </div>
        </fieldset>

        <div className="flex justify-end gap-2 pt-4 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate('/payroll/periods')}
            disabled={submitting || mutation.isPending}>
            Cancel
          </Button>
          <Button type="submit" variant="primary"
            disabled={submitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Creating…' : 'Create period'}
          </Button>
        </div>
      </form>
    </div>
  );
}
