import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { leaveRequestsApi, leaveTypesApi, leaveBalancesApi } from '@/api/leave';
import { employeesApi } from '@/api/hr/employees';
import { useAuthStore } from '@/stores/authStore';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { ApiValidationError } from '@/types';

const schema = z.object({
  employee_id: z.string().min(1),
  leave_type_id: z.string().min(1),
  start_date: z.string().min(1),
  end_date: z.string().min(1),
  reason: z.string().max(2000).optional().or(z.literal('')),
});
type FormValues = z.infer<typeof schema>;

export default function CreateLeavePage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const user = useAuthStore((s) => s.user);
  const { can } = usePermission();
  const isAdmin = can('leave.view') && (user?.role.slug === 'system_admin' || user?.role.slug === 'hr_officer');

  const { data: typesResp } = useQuery({ queryKey: ['leaves', 'types'], queryFn: () => leaveTypesApi.list() });
  const types = typesResp?.data ?? [];

  const { data: employeesResp } = useQuery({
    queryKey: ['hr', 'employees', 'all-active'],
    queryFn: () => employeesApi.list({ per_page: 100, status: 'active' }),
    enabled: isAdmin,
  });
  const employees = employeesResp?.data ?? [];

  const {
    register, handleSubmit, watch, setValue, setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      employee_id: (user as any)?.employee?.id ?? '',
    },
  });

  const employeeId = watch('employee_id');
  const leaveTypeId = watch('leave_type_id');
  const startDate = watch('start_date');
  const endDate = watch('end_date');

  const { data: balances = [] } = useQuery({
    queryKey: ['leaves', 'balances', employeeId],
    queryFn: () => isAdmin && employeeId
      ? leaveBalancesApi.forEmployee(employeeId)
      : leaveBalancesApi.me(),
    enabled: !!employeeId || !isAdmin,
  });

  const selectedBalance = balances.find((b) => b.leave_type.id === leaveTypeId);

  // Auto-compute estimated days (excluding Sundays).
  let estimatedDays = 0;
  if (startDate && endDate) {
    const a = new Date(startDate); const b = new Date(endDate);
    if (b >= a) {
      for (let d = new Date(a); d <= b; d.setDate(d.getDate() + 1)) {
        if (d.getDay() !== 0) estimatedDays++;
      }
    }
  }

  const mutation = useMutation({
    mutationFn: (d: FormValues) => leaveRequestsApi.create({
      employee_id: d.employee_id,
      leave_type_id: d.leave_type_id,
      start_date: d.start_date,
      end_date: d.end_date,
      reason: d.reason || undefined,
    }),
    onSuccess: (req) => {
      qc.invalidateQueries({ queryKey: ['leaves'] });
      toast.success(`Leave request ${req.leave_request_no} submitted.`);
      navigate(`/leaves/${req.id}`);
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
      } else toast.error('Failed to submit leave request.');
    },
  });

  return (
    <div>
      <PageHeader title="Request leave" backTo="/leaves" backLabel="Leaves" />
      <form onSubmit={handleSubmit((d) => mutation.mutate(d))} className="max-w-2xl mx-auto px-5 py-6 space-y-4">
        <Panel title="Leave details">
          <div className="grid grid-cols-2 gap-3">
            {isAdmin && (
              <Select label="Employee" required {...register('employee_id')} error={errors.employee_id?.message}>
                <option value="">— Select —</option>
                {employees.map((e) => <option key={e.id} value={e.id}>{e.full_name} ({e.employee_no})</option>)}
              </Select>
            )}
            {!isAdmin && (
              <input type="hidden" {...register('employee_id')} />
            )}
            <Select label="Leave type" required {...register('leave_type_id')} error={errors.leave_type_id?.message}>
              <option value="">— Select —</option>
              {types.map((t) => <option key={t.id} value={t.id}>{t.code} — {t.name}</option>)}
            </Select>
            <Input label="Start date" type="date" required {...register('start_date')} error={errors.start_date?.message} />
            <Input label="End date" type="date" required {...register('end_date')} error={errors.end_date?.message} />
          </div>
          <div className="mt-3">
            <Textarea label="Reason" {...register('reason')} error={errors.reason?.message} rows={3} />
          </div>
          {selectedBalance && (
            <div className="mt-3 p-3 bg-surface border border-default rounded-md text-sm">
              <div className="text-2xs uppercase tracking-wider text-muted mb-1">{selectedBalance.leave_type.code} balance</div>
              <div className="flex items-baseline gap-3">
                <span className="font-mono tabular-nums text-2xl font-medium">{selectedBalance.remaining}</span>
                <span className="text-xs text-muted">of {selectedBalance.total_credits} days remaining</span>
                {estimatedDays > 0 && (
                  <span className={`ml-auto font-mono tabular-nums text-sm ${parseFloat(selectedBalance.remaining) < estimatedDays ? 'text-danger-fg' : 'text-success-fg'}`}>
                    Requesting {estimatedDays} day{estimatedDays === 1 ? '' : 's'}
                  </span>
                )}
              </div>
              <div className="h-1.5 bg-elevated rounded-sm mt-2 overflow-hidden">
                <div
                  className="h-full bg-accent"
                  style={{ width: `${Math.min(100, (parseFloat(selectedBalance.used) / Math.max(1, parseFloat(selectedBalance.total_credits))) * 100)}%` }}
                />
              </div>
            </div>
          )}
        </Panel>
        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="secondary" onClick={() => navigate('/leaves')}>Cancel</Button>
          <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Submitting…' : 'Submit request'}
          </Button>
        </div>
      </form>
    </div>
  );
}
