import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { shiftsApi } from '@/api/attendance/shifts';
import { departmentsApi } from '@/api/hr/departments';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import type { ApiValidationError } from '@/types';
import { onFormInvalid } from '@/lib/formErrors';

const schema = z.object({
  department_id: z.string().min(1, 'Required'),
  shift_id: z.string().min(1, 'Required'),
  effective_date: z.string().min(1, 'Required'),
  end_date: z.string().optional().or(z.literal('')),
});
type FormValues = z.infer<typeof schema>;

export default function BulkAssignShiftPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data: depts = [] } = useQuery({
    queryKey: ['hr', 'departments', 'tree'],
    queryFn: () => departmentsApi.tree(),
  });
  const { data: shiftsResp } = useQuery({
    queryKey: ['attendance', 'shifts', 'all'],
    queryFn: () => shiftsApi.list({ per_page: 100 }),
  });
  const shifts = shiftsResp?.data ?? [];

  const {
    register, handleSubmit, setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { effective_date: new Date().toISOString().slice(0, 10) },
  });

  const mutation = useMutation({
    mutationFn: (d: FormValues) => shiftsApi.bulkAssign({
      department_id: d.department_id,
      shift_id: d.shift_id,
      effective_date: d.effective_date,
      end_date: d.end_date || null,
    }),
    onSuccess: (result) => {
      qc.invalidateQueries({ queryKey: ['attendance'] });
      toast.success(`Assigned shift to ${result.count} employees.`);
      navigate('/hr/attendance/shifts');
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([f, msgs]) =>
          setError(f as keyof FormValues, { type: 'server', message: msgs[0] }),
        );
        toast.error('Please fix the errors below.');
      } else {
        toast.error('Failed to assign shifts.');
      }
    },
  });

  return (
    <div>
      <PageHeader
        title="Bulk assign shift"
        subtitle="Assigns the selected shift to all employees in a department, closing previous open assignments."
        backTo="/hr/attendance/shifts"
        backLabel="Shifts"
      />
      <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())} className="max-w-2xl mx-auto px-5 py-6">
        <Panel title="Assignment details">
          <div className="grid grid-cols-2 gap-3">
            <Select label="Department" required {...register('department_id')} error={errors.department_id?.message}>
              <option value="">— Select —</option>
              {depts.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </Select>
            <Select label="Shift" required {...register('shift_id')} error={errors.shift_id?.message}>
              <option value="">— Select —</option>
              {shifts.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </Select>
            <Input label="Effective from" type="date" required {...register('effective_date')} error={errors.effective_date?.message} />
            <Input label="End date (optional)" type="date" {...register('end_date')} error={errors.end_date?.message} />
          </div>
        </Panel>
        <div className="flex justify-end gap-2 pt-4">
          <Button type="button" variant="secondary" onClick={() => navigate('/hr/attendance/shifts')}>Cancel</Button>
          <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Assigning…' : 'Assign shift'}
          </Button>
        </div>
      </form>
    </div>
  );
}
