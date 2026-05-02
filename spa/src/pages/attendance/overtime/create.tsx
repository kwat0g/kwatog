import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { overtimeApi } from '@/api/attendance/overtime';
import { employeesApi } from '@/api/hr/employees';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import type { ApiValidationError } from '@/types';
import { onFormInvalid } from '@/lib/formErrors';

const schema = z.object({
  employee_id: z.string().min(1, 'Employee is required'),
  date: z.string().min(1, 'Date is required'),
  hours_requested: z.coerce.number({ invalid_type_error: 'Enter a number' })
    .min(0.5, 'Minimum 0.5 hours').max(8, 'Maximum 8 hours'),
  reason: z.string().trim().min(5, 'Provide at least 5 characters').max(2000),
});
type FormValues = z.infer<typeof schema>;

export default function OvertimeCreatePage() {
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data: employeesResp } = useQuery({
    queryKey: ['hr', 'employees', 'all'],
    queryFn: () => employeesApi.list({ per_page: 100, status: 'active' }),
  });
  const employees = employeesResp?.data ?? [];

  const {
    register, handleSubmit, setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const mutation = useMutation({
    mutationFn: (d: FormValues) => overtimeApi.create({
      employee_id: d.employee_id,
      date: d.date,
      hours_requested: d.hours_requested,
      reason: d.reason,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['attendance', 'overtime'] });
      toast.success('Overtime request submitted.');
      navigate('/hr/attendance/overtime');
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([f, msgs]) =>
          setError(f as keyof FormValues, { type: 'server', message: msgs[0] }),
        );
        toast.error('Please fix the errors below.');
      } else toast.error('Failed to submit OT request.');
    },
  });

  return (
    <div>
      <PageHeader title="New overtime request" backTo="/hr/attendance/overtime" backLabel="Overtime" />
      <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())} className="max-w-2xl mx-auto px-5 py-6">
        <Panel title="Request details">
          <div className="grid grid-cols-2 gap-3">
            <Select label="Employee" required {...register('employee_id')} error={errors.employee_id?.message}>
              <option value="">— Select —</option>
              {employees.map((e) => <option key={e.id} value={e.id}>{e.full_name} ({e.employee_no})</option>)}
            </Select>
            <Input label="Date" type="date" required {...register('date')} error={errors.date?.message} />
            <Input label="Hours requested" type="number" step="0.5" required {...register('hours_requested')} error={errors.hours_requested?.message} className="font-mono" />
          </div>
          <div className="mt-3">
            <Textarea label="Reason" required {...register('reason')} error={errors.reason?.message} rows={3} />
          </div>
        </Panel>
        <div className="flex justify-end gap-2 pt-4">
          <Button type="button" variant="secondary" onClick={() => navigate('/hr/attendance/overtime')}>Cancel</Button>
          <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Submitting…' : 'Submit request'}
          </Button>
        </div>
      </form>
    </div>
  );
}
