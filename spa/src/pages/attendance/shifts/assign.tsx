import { useState } from 'react';
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
import { localIsoDate } from '@/lib/formatDate';

const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

const schema = z.object({
  working_days: z.array(z.number().int().min(1).max(7)).min(1, 'Select at least one working day'),
  department_id: z.string().min(1, 'Required'),
  shift_id: z.string().min(1, 'Required'),
  effective_date: z.string().min(1, 'Required'),
  end_date: z.string().optional().or(z.literal('')),
});
type FormValues = z.infer<typeof schema>;

export default function BulkAssignShiftPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [assignmentPage, setAssignmentPage] = useState(1);
  const [selectedAssignment, setSelectedAssignment] = useState('');
  const [configuredDays, setConfiguredDays] = useState<number[]>([]);

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
    register,
    handleSubmit,
    setError,
    watch,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { effective_date: localIsoDate(), working_days: [] },
  });

  const departmentId = watch('department_id');
  const workingDays = watch('working_days');
  const {
    data: assignmentResponse,
    isError: assignmentError,
    isFetching: assignmentsFetching,
    refetch: retryAssignments,
  } = useQuery({
    queryKey: ['attendance', 'shift-assignments', departmentId, assignmentPage],
    queryFn: () => shiftsApi.assignments(departmentId, assignmentPage),
  });
  const configure = useMutation({
    mutationFn: () => shiftsApi.configureWorkingDays(selectedAssignment, configuredDays),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['attendance'] });
      setSelectedAssignment('');
      toast.success('Working days configured. Assignment dates preserved.');
    },
    onError: () => toast.error('Failed to configure working days.'),
  });

  const mutation = useMutation({
    mutationFn: (d: FormValues) =>
      shiftsApi.bulkAssign({
        department_id: d.department_id,
        shift_id: d.shift_id,
        effective_date: d.effective_date,
        end_date: d.end_date || null,
        working_days: d.working_days,
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
        toast.error(e.response?.data?.message || 'Validation failed.');
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
      <form
        onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
        className="min-w-0 w-full px-5 py-4"
      >
        <Panel title="Assignment details">
          <div className="grid grid-cols-2 gap-3">
            <Select
              label="Department"
              required
              {...register('department_id', {
                onChange: () => {
                  setAssignmentPage(1);
                  setSelectedAssignment('');
                  setConfiguredDays([]);
                },
              })}
              error={errors.department_id?.message}
            >
              <option value="">— Select —</option>
              {depts.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </Select>
            <Select
              label="Shift"
              required
              {...register('shift_id')}
              error={errors.shift_id?.message}
            >
              <option value="">— Select —</option>
              {shifts.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </Select>
            <Input
              label="Effective from"
              type="date"
              required
              {...register('effective_date')}
              error={errors.effective_date?.message}
            />
            <Input
              label="End date (optional)"
              type="date"
              {...register('end_date')}
              error={errors.end_date?.message}
            />
          </div>
          <fieldset className="mt-4">
            <legend className="text-sm font-medium">Working days *</legend>
            <div className="flex flex-wrap gap-3 mt-2">
              {weekdays.map((day, index) => (
                <label key={day} className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={workingDays.includes(index + 1)}
                    onChange={(event) =>
                      setValue(
                        'working_days',
                        event.target.checked
                          ? [...workingDays, index + 1].sort()
                          : workingDays.filter((value) => value !== index + 1),
                        { shouldValidate: true },
                      )
                    }
                  />
                  {day}
                </label>
              ))}
            </div>
            {errors.working_days && (
              <p role="alert" className="text-sm text-danger-fg mt-2">
                {errors.working_days.message}
              </p>
            )}
          </fieldset>
        </Panel>
        <div className="flex justify-end gap-2 pt-4">
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/hr/attendance/shifts')}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={isSubmitting || mutation.isPending}
            loading={mutation.isPending}
          >
            {mutation.isPending ? 'Assigning…' : 'Assign shift'}
          </Button>
        </div>
      </form>
      <div className="px-5 py-4">
        <Panel title="Configure existing assignment">
          <p className="text-sm mb-3">
            Set actual working days without changing assignment dates. Department selection above
            filters this list.
          </p>
          {assignmentError && (
            <div role="alert" className="text-sm text-danger-fg mb-3">
              Failed to load shift assignments.
              <Button type="button" variant="secondary" onClick={() => retryAssignments()}>
                Retry assignments
              </Button>
            </div>
          )}
          <Select
            label="Assignment"
            disabled={assignmentError || assignmentsFetching}
            value={selectedAssignment}
            onChange={(event) => {
              const assignment = assignmentResponse?.data.find(
                (item) => item.id === event.target.value,
              );
              setSelectedAssignment(event.target.value);
              setConfiguredDays(assignment?.working_days ?? []);
            }}
          >
            <option value="">— Select —</option>
            {assignmentResponse?.data.map((assignment) => (
              <option key={assignment.id} value={assignment.id}>
                {assignment.employee_name} · {assignment.shift.name} · {assignment.effective_date} –{' '}
                {assignment.end_date ?? 'ongoing'} ·{' '}
                {assignment.working_days === null
                  ? 'Working days not configured'
                  : assignment.working_days.map((day) => weekdays[day - 1]).join(', ')}
              </option>
            ))}
          </Select>
          <div className="flex gap-2 mt-2">
            <Button
              type="button"
              variant="secondary"
              disabled={assignmentPage === 1}
              onClick={() => setAssignmentPage((page) => page - 1)}
            >
              Previous
            </Button>
            <Button
              type="button"
              variant="secondary"
              disabled={!assignmentResponse || assignmentPage >= assignmentResponse.meta.last_page}
              onClick={() => setAssignmentPage((page) => page + 1)}
            >
              Next
            </Button>
          </div>
          {selectedAssignment && (
            <fieldset className="mt-4">
              <legend className="text-sm font-medium">Working days</legend>
              <div className="flex flex-wrap gap-3 mt-2">
                {weekdays.map((day, index) => (
                  <label key={day} className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={configuredDays.includes(index + 1)}
                      onChange={(event) =>
                        setConfiguredDays(
                          event.target.checked
                            ? [...configuredDays, index + 1].sort()
                            : configuredDays.filter((value) => value !== index + 1),
                        )
                      }
                    />
                    {day}
                  </label>
                ))}
              </div>
              <Button
                type="button"
                variant="primary"
                className="mt-3"
                disabled={
                  assignmentError ||
                  assignmentsFetching ||
                  !assignmentResponse?.data.some(
                    (assignment) => assignment.id === selectedAssignment,
                  ) ||
                  configuredDays.length === 0 ||
                  configure.isPending
                }
                onClick={() => configure.mutate()}
              >
                Save working days
              </Button>
            </fieldset>
          )}
        </Panel>
      </div>
    </div>
  );
}
