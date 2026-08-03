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
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import type { ApiValidationError } from '@/types';
import { onFormInvalid } from '@/lib/formErrors';
import type { AmortizationItem, LoanType } from '@/types/loans';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { cn } from '@/lib/cn';

const schema = z.object({
  employee_id: z.string().min(1, 'Employee is required'),
  loan_type: z.string().min(1, 'Loan type is required'),
  principal: z.coerce.number({ invalid_type_error: 'Enter a number' })
    .positive('Must be positive'),
  pay_periods: z.coerce.number({ invalid_type_error: 'Enter a number' })
    .int('Whole number').min(1, 'At least 1 period'),
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
    register, handleSubmit, watch, setError, setValue,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    // Period count is policy-driven; leave it blank until the selected
    // employee/type limits are loaded instead of inventing a default.
    defaultValues: { loan_type: '' },
  });

  const employeeId = watch('employee_id');
  const loanType = watch('loan_type') as LoanType;
  const principal = watch('principal');
  const periods = watch('pay_periods');

  const { data: loanTypes = [] } = useQuery({
    queryKey: ['loans', 'types'],
    queryFn: loansApi.types,
  });

  useEffect(() => {
    if (!loanType && loanTypes.length > 0) {
      setValue('loan_type', loanTypes[0].value, { shouldValidate: true });
    }
  }, [loanType, loanTypes, setValue]);

  const { data: limits } = useQuery({
    queryKey: ['loans', 'limits', employeeId, loanType],
    queryFn: () => loansApi.limits(employeeId, loanType),
    enabled: !!employeeId && !!loanType,
  });

  const [schedule, setSchedule] = useState<AmortizationItem[]>([]);
  useEffect(() => {
    if (loanType && principal && principal > 0 && periods && periods > 0) {
      loansApi.previewAmortization(loanType, Number(principal), Number(periods))
        .then(setSchedule);
    } else {
      setSchedule([]);
    }
  }, [loanType, principal, periods]);

  const mutation = useMutation({
    mutationFn: (d: FormValues) => loansApi.create({
      employee_id: d.employee_id,
      loan_type: d.loan_type as LoanType,
      principal: d.principal,
      pay_periods: d.pay_periods,
      purpose: d.purpose || undefined,
    }),
    onSuccess: (loan) => {
      qc.invalidateQueries({ queryKey: ['loans'] });
      toast.success(`Loan request ${loan.loan_no} submitted.`);
      navigate(`/hr/loans/${loan.id}`);
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
      <PageHeader title="New loan request" backTo="/hr/loans" backLabel="Loans" breadcrumbs={[{ label: 'HR', href: '/hr' }, { label: 'Loans', href: '/hr/loans' }, { label: 'New Request' }]} />
      <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())} className="max-w-3xl mx-auto px-5 py-4 space-y-4">
        <Panel title="Type & employee">
          <div className="space-y-3">
            <fieldset>
              <legend className="text-2xs uppercase tracking-wider text-muted font-medium mb-2">Loan type</legend>
              <div className="grid gap-2 sm:grid-cols-2">
                {loanTypes.map((type) => (
                  <label key={type.value} className="flex items-start gap-2 rounded border border-default p-3 text-sm">
                    <input type="radio" value={type.value} {...register('loan_type')} className="mt-0.5" />
                    <span>
                      <span className="block font-medium">{type.label}</span>
                      <span className="block text-xs text-muted">
                        {type.approval_steps} approval steps · {(Number(type.interest_rate) * 100).toFixed(2)}% annual interest
                      </span>
                    </span>
                  </label>
                ))}
              </div>
              {errors.loan_type && <p className="mt-1 text-xs text-danger-fg">{errors.loan_type.message}</p>}
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
            <Input label="Principal" type="number" step="0.01" min="1" max={limits?.principal_max} prefix="₱" required {...register('principal')} error={errors.principal?.message} className="font-mono tabular-nums text-right" placeholder="0.00" />
            <Input label="Pay periods" type="number" min={1} max={limits?.max_pay_periods} required {...register('pay_periods')} error={errors.pay_periods?.message} className="font-mono tabular-nums text-right" />
            <Textarea label="Purpose" {...register('purpose')} error={errors.purpose?.message} rows={2} className="col-span-2" maxLength={1000} />
          </div>
          {schedule.length > 0 && (
            <div className="mt-4 border border-default rounded-md overflow-hidden">
              <div className="px-3 py-2 bg-subtle text-2xs uppercase tracking-wider text-muted font-medium">
                Amortization preview
              </div>
              <table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>#</Th>
                    <Th align="right">Amount</Th>
                    <Th align="right">Interest</Th>
                    <Th align="right">Remaining</Th>
                  </tr>
                </thead>
                <tbody>
                  {schedule.slice(0, 12).map((s) => (
                    <tr key={s.period} className={trCls}>
                      <Td mono className="text-muted">{String(s.period).padStart(2, '0')}</Td>
                      <Td align="right" mono>{formatPeso(s.amount)}</Td>
                      <Td align="right" mono>{formatPeso(s.interest)}</Td>
                      <Td align="right" mono className="text-muted">{formatPeso(s.remaining_after)}</Td>
                    </tr>
                  ))}
                  {schedule.length > 12 && (
                    <tr className={cn(trCls, 'text-muted')}>
                      <Td className="italic" colSpan={4}>+ {schedule.length - 12} more periods</Td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          )}
        </Panel>

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="secondary" onClick={() => navigate('/hr/loans')}>Cancel</Button>
          <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Submitting…' : 'Submit request'}
          </Button>
        </div>
      </form>
    </div>
  );
}
