import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { loansApi } from '@/api/loans';
import { employeesApi } from '@/api/hr/employees';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Radio } from '@/components/ui/Radio';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import type { ApiValidationError } from '@/types';
import { onFormInvalid } from '@/lib/formErrors';
import type { LoanType } from '@/types/loans';

const schema = z.object({
  employee_id: z.string().min(1, 'Employee is required'),
  loan_type: z.enum(['company_loan', 'cash_advance']),
  principal: z.coerce.number({ invalid_type_error: 'Enter a number' })
    .positive('Must be positive').max(9_999_999.99, 'Maximum ₱9,999,999.99'),
  pay_periods: z.coerce.number({ invalid_type_error: 'Enter a number' })
    .int('Whole number').min(1, 'At least 1 period').max(60, 'Maximum 60 periods'),
  purpose: z.string().max(1000, 'Max 1000 characters').optional().or(z.literal('')),
});
type FormValues = z.infer<typeof schema>;

export default function CreateLoanPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data: employeesResp } = useQuery({
    queryKey: ['hr', 'employees', 'all-active'],
    queryFn: () => employeesApi.list({ per_page: 100, status: 'active' }),
  });
  const employees = employeesResp?.data ?? [];

  const {
    register, handleSubmit, watch, setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { loan_type: 'cash_advance', pay_periods: 6 },
  });

  const employeeId = watch('employee_id');
  const loanType = watch('loan_type') as LoanType;
  const principal = watch('principal');
  const periods = watch('pay_periods');

  const { data: limits } = useQuery({
    queryKey: ['loans', 'limits', employeeId, loanType],
    queryFn: () => loansApi.limits(employeeId, loanType),
    enabled: !!employeeId && !!loanType,
  });

  const [schedule, setSchedule] = useState<{ period: number; amount: string; remaining_after: string }[]>([]);
  useEffect(() => {
    if (principal && principal > 0 && periods && periods > 0) {
      loansApi.previewAmortization(Number(principal), Number(periods))
        .then(setSchedule)
        .catch(() => setSchedule([]));
    } else {
      setSchedule([]);
    }
  }, [principal, periods]);

  const mutation = useMutation({
    mutationFn: (d: FormValues) => loansApi.create({
      employee_id: d.employee_id,
      loan_type: d.loan_type,
      principal: d.principal,
      pay_periods: d.pay_periods,
      purpose: d.purpose || undefined,
    }),
    onSuccess: (loan) => {
      qc.invalidateQueries({ queryKey: ['loans'] });
      toast.success(`Loan request ${loan.loan_no} submitted.`);
      navigate(`/loans/${loan.id}`);
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422) {
        const data = e.response.data;
        if (data.errors) {
          Object.entries(data.errors).forEach(([f, msgs]) =>
            setError(f as keyof FormValues, { type: 'server', message: msgs[0] }),
          );
        } else if (data.message) {
          toast.error(data.message);
        }
      } else toast.error('Failed to submit loan request.');
    },
  });

  return (
    <div>
      <PageHeader title="New loan request" backTo="/loans" backLabel="Loans" />
      <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())} className="max-w-3xl mx-auto px-5 py-6 space-y-4">
        <Panel title="Type & employee">
          <div className="space-y-3">
            <fieldset>
              <legend className="text-2xs uppercase tracking-wider text-muted font-medium mb-2">Loan type</legend>
              <div className="flex gap-4">
                <label className="flex items-center gap-2 text-sm">
                  <input type="radio" value="cash_advance" {...register('loan_type')} />
                  <span>Cash advance · 3-step approval</span>
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <input type="radio" value="company_loan" {...register('loan_type')} />
                  <span>Company loan · 4-step approval</span>
                </label>
              </div>
            </fieldset>
            <Select label="Employee" required {...register('employee_id')} error={errors.employee_id?.message}>
              <option value="">— Select —</option>
              {employees.map((e) => <option key={e.id} value={e.id}>{e.full_name} ({e.employee_no})</option>)}
            </Select>
            {limits && (
              <div className="text-xs text-muted">
                Max principal: <span className="font-mono tabular-nums text-primary">{formatPeso(limits.principal_max)}</span>
                {limits.has_active && <span className="text-danger-fg ml-2">· employee already has an active {loanType.replace('_', ' ')}</span>}
              </div>
            )}
          </div>
        </Panel>

        <Panel title="Amount & schedule">
          <div className="grid grid-cols-2 gap-3">
            <Input label="Principal" type="number" step="0.01" min="1" max="9999999.99" prefix="₱" required {...register('principal')} error={errors.principal?.message} className="font-mono tabular-nums text-right" placeholder="0.00" />
            <Input label="Pay periods" type="number" min={1} max={60} required {...register('pay_periods')} error={errors.pay_periods?.message} className="font-mono tabular-nums text-right" />
            <Textarea label="Purpose" {...register('purpose')} error={errors.purpose?.message} rows={2} className="col-span-2" maxLength={1000} />
          </div>
          {schedule.length > 0 && (
            <div className="mt-4 border border-default rounded-md overflow-hidden">
              <div className="px-3 py-2 bg-subtle text-2xs uppercase tracking-wider text-muted font-medium">
                Amortization preview
              </div>
              <table className="w-full text-sm">
                <thead className="text-2xs uppercase tracking-wider text-muted">
                  <tr className="border-b border-default">
                    <th className="h-7 px-3 text-left">#</th>
                    <th className="h-7 px-3 text-right">Amount</th>
                    <th className="h-7 px-3 text-right">Remaining</th>
                  </tr>
                </thead>
                <tbody>
                  {schedule.slice(0, 12).map((s) => (
                    <tr key={s.period} className="h-7 border-b border-subtle">
                      <td className="px-3 text-muted font-mono tabular-nums">{String(s.period).padStart(2, '0')}</td>
                      <td className="px-3 text-right font-mono tabular-nums">{formatPeso(s.amount)}</td>
                      <td className="px-3 text-right font-mono tabular-nums text-muted">{formatPeso(s.remaining_after)}</td>
                    </tr>
                  ))}
                  {schedule.length > 12 && (
                    <tr className="h-7 text-muted">
                      <td className="px-3 italic" colSpan={3}>+ {schedule.length - 12} more periods</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          )}
        </Panel>

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="secondary" onClick={() => navigate('/loans')}>Cancel</Button>
          <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Submitting…' : 'Submit request'}
          </Button>
        </div>
      </form>
    </div>
  );
}
