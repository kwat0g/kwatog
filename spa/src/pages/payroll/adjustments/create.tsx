import { useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { adjustmentsApi } from '@/api/payroll/adjustments';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors } from '@/lib/formErrors';
import type { ApiValidationError } from '@/types';

const schema = z.object({
  original_payroll_id: z.string().min(1, 'Source payroll is required'),
  type:                z.enum(['underpayment', 'overpayment']),
  amount:              z.string().min(1, 'Amount is required'),
  reason:              z.string().min(5, 'Reason is required (min 5 chars)').max(1000),
});

type FormValues = z.infer<typeof schema>;

export default function CreatePayrollAdjustmentPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const location = useLocation() as { state?: { original_payroll_id?: string; employee?: { full_name?: string; employee_no?: string } } };
  const [submitting, setSubmitting] = useState(false);

  const { register, handleSubmit, setError, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      original_payroll_id: location.state?.original_payroll_id ?? '',
      type: 'underpayment',
    },
  });

  const mutation = useMutation({
    mutationFn: (data: FormValues) => adjustmentsApi.create(data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['payroll-adjustments'] });
      toast.success('Adjustment submitted for approval.');
      navigate('/payroll/adjustments');
    },
    onError: (error: AxiosError<ApiValidationError>) => {
      if (!applyServerValidationErrors(error, setError, 'Failed to submit adjustment.')) {
        toast.error(error.response?.data?.message ?? 'Failed to submit adjustment.');
      }
    },
  });

  const onSubmit = (data: FormValues) => {
    setSubmitting(true);
    mutation.mutate(data, { onSettled: () => setSubmitting(false) });
  };

  const empName = location.state?.employee?.full_name;
  const empNo = location.state?.employee?.employee_no;

  return (
    <div>
      <PageHeader title="Raise Payroll Adjustment" backTo="/payroll/adjustments" backLabel="Adjustments" />
      <form onSubmit={handleSubmit(onSubmit)} className="max-w-2xl mx-auto px-5 py-6">
        {empName && (
          <div className="mb-4 p-3 bg-surface border border-default rounded-md text-xs">
            <div className="text-muted uppercase tracking-wider mb-1">Source payroll</div>
            <div className="font-medium">{empName}</div>
            {empNo && <div className="font-mono text-muted">{empNo}</div>}
          </div>
        )}

        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Adjustment</legend>
          <div className="grid grid-cols-2 gap-3">
            <Input
              label="Source payroll ID"
              {...register('original_payroll_id')}
              error={errors.original_payroll_id?.message}
              disabled={!!location.state?.original_payroll_id}
              required
              className="font-mono"
            />
            <Select label="Type" required {...register('type')} error={errors.type?.message}>
              <option value="underpayment">Underpayment refund</option>
              <option value="overpayment">Overpayment recovery</option>
            </Select>
            <Input
              label="Amount"
              {...register('amount')}
              error={errors.amount?.message}
              prefix="₱"
              placeholder="0.00"
              required
              className="font-mono"
            />
          </div>
        </fieldset>

        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Justification</legend>
          <Textarea
            label="Reason"
            {...register('reason')}
            error={errors.reason?.message}
            rows={5}
            placeholder="Explain what was wrong with the original payroll and what the adjustment corrects."
            required
          />
          <p className="text-xs text-muted mt-2">
            The adjustment will be queued for approval. Once approved, it applies automatically to the next non-finalized period.
          </p>
        </fieldset>

        <div className="flex justify-end gap-2 pt-4 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate('/payroll/adjustments')}
            disabled={submitting || mutation.isPending}>
            Cancel
          </Button>
          <Button type="submit" variant="primary"
            disabled={submitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Submitting…' : 'Submit adjustment'}
          </Button>
        </div>
      </form>
    </div>
  );
}
