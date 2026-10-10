import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { overtimeApi } from '@/api/attendance/overtime';
import { employeesApi } from '@/api/hr/employees';
import { useAuthStore } from '@/stores/authStore';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { localIsoDate, manilaIsoDate } from '@/lib/formatDate';

import { useFormSafety } from '@/hooks/useFormSafety';
import { FormDraftBanner } from '@/components/ui/FormDraftBanner';
import { FormActions } from '@/components/ui/FormActions';
function todayIso(): string {
  return manilaIsoDate();
}

function addDaysIso(dateStr: string, days: number): string {
  const d = new Date(dateStr + 'T00:00:00');
  d.setDate(d.getDate() + days);
  return localIsoDate(d);
}

type FormValues = {
  employee_id: string;
  date: string;
  hours_requested: number;
  reason: string;
};

export default function OvertimeCreatePage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const user = useAuthStore((s) => s.user);

  const {
    data: options,
    isLoading: optionsLoading,
    isError: optionsError,
  } = useQuery({
    queryKey: ['attendance', 'overtime', 'options'],
    queryFn: overtimeApi.options,
    staleTime: 5 * 60_000,
  });

  const { data: employeesResp } = useQuery({
    queryKey: ['hr', 'employees', 'all'],
    queryFn: () => employeesApi.list({ per_page: 100, status: 'active' }),
  });
  const employees = employeesResp?.data ?? [];

  // Policy validation is derived from the server response. Until the settings
  // arrive, only field-shape validation runs; the form remains disabled so a
  // missing response can never silently turn into a local default policy.
  const schema = useMemo(() => {
    const base = z.object({
      employee_id: z.string().min(1, 'Employee is required'),
      date: z.string().min(1, 'Date is required').refine((date) => date >= manilaIsoDate(), 'Choose today or a later date.'),
      hours_requested: z.coerce.number({ invalid_type_error: 'Enter a number' }),
      reason: z.string().trim().min(5, 'Provide at least 5 characters').max(2000),
    });
    if (!options) return base;

    // Preserve the configured future window while preventing backdating.
    const dateMax = addDaysIso(todayIso(), options.request_future_days);
    return base.extend({
      date: base.shape.date
        .refine((v) => v <= dateMax, 'Date is outside the configured request window.'),
      hours_requested: base.shape.hours_requested
        .min(options.request_min_hours, `Minimum ${options.request_min_hours} hours`)
        .max(options.maximum_hours, `Maximum ${options.maximum_hours} hours per day`),
    });
  }, [options]);

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      employee_id: user?.employee?.id ?? '',
      date: manilaIsoDate(),
    },
  });
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = form;

  const mutation = useMutation({
    mutationFn: (d: FormValues) =>
      overtimeApi.create({
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
    onError: (e) => {
      applyServerValidationErrors(e, setError, 'Failed to submit OT request.');
    },
  });
  const safety = useFormSafety({ form, saved: mutation.isSuccess });

  const dateMin = manilaIsoDate();
  const dateMax = options ? addDaysIso(todayIso(), options.request_future_days) : undefined;

  return (
    <div>
      <PageHeader
        title="New overtime request"
        backTo="/hr/attendance/overtime"
        backLabel="Overtime"
      />
      <FormDraftBanner safety={safety} />
      <form
        onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
        className="min-w-0 w-full px-5 py-4"
      >
        {optionsLoading ? (
          <Panel title="Request details">
            <SkeletonBlock className="h-24" />
          </Panel>
        ) : (
          <Panel
            title="Request details"
            meta={options ? `Max ${options.maximum_hours} hours/day` : undefined}
          >
            <div className="grid grid-cols-2 gap-3">
              <Select searchable
                label="Employee"
                required
                {...register('employee_id')}
                error={errors.employee_id?.message}
              >
                <option value="">— Select —</option>
                {employees.map((e) => (
                  <option key={e.id} value={e.id}>
                    {e.full_name} ({e.employee_no})
                  </option>
                ))}
              </Select>
              <Input
                label="Date"
                type="date"
                required
                {...register('date')}
                error={errors.date?.message}
                min={dateMin}
                max={dateMax}
              />
              <Input
                label="Hours requested"
                type="number"
                step="any"
                required
                {...register('hours_requested')}
                error={errors.hours_requested?.message}
                min={options?.request_min_hours}
                max={options?.maximum_hours}
                className="font-mono"
              />
            </div>
            {options && (
              <p className="text-xs text-muted mt-2">
                Request from today through {options.request_future_days} days ahead. Maximum {options.maximum_hours} hours per
                day.
              </p>
            )}
            <div className="mt-3">
              <Textarea
                label="Reason"
                required
                {...register('reason')}
                error={errors.reason?.message}
                rows={3}
              />
            </div>
          </Panel>
        )}
        <FormActions>
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/hr/attendance/overtime')}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={
              isSubmitting || mutation.isPending || optionsLoading || optionsError || !options
            }
            loading={mutation.isPending}
          >
            {mutation.isPending ? 'Submitting…' : 'Submit request'}
          </Button>
        </FormActions>
      </form>
    </div>
  );
}
