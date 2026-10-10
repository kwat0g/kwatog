import { useRef } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { employeesApi, type UpdateEmployeeData } from '@/api/hr/employees';
import { shiftsApi } from '@/api/attendance/shifts';
import { EmployeeForm, type EmployeeFormValues } from '@/components/hr/EmployeeForm';
import { DEFAULT_WORKING_DAYS, sameDays } from '@/lib/workingDays';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import type { ApiValidationError } from '@/types';
import { usePermission } from '@/hooks/usePermission';
import { summarizeServerErrors } from '@/lib/formErrors';
import { localIsoDate } from '@/lib/formatDate';

/** Field names the API uses whose plain label differs from a title-cased key. */
const SERVER_FIELD_LABELS: Record<string, string> = {
  bank_name: 'Bank name',
  bank_account_no: 'Account number',
  mobile_number: 'Mobile number',
  emergency_contact_phone: 'Emergency phone',
};

const cleanup = (d: EmployeeFormValues): UpdateEmployeeData => {
  const out: Record<string, unknown> = { ...d };
  Object.keys(out).forEach((k) => {
    if (out[k] === '') out[k] = undefined;
  });
  delete out.__edit;
  delete out.shift_id;
  delete out.working_days;
  delete out.basic_monthly_salary;
  delete out.semi_monthly_rate;
  delete out.pay_type;
  // Bank details change through an approval request; the update endpoint refuses them.
  delete out.bank_name;
  delete out.bank_account_no;
  return out as UpdateEmployeeData;
};

export default function EditEmployeePage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const setErrorRef = useRef<((field: keyof EmployeeFormValues, msg: string) => void) | null>(null);
  const { can } = usePermission();
  const canAssignShift = can('attendance.shifts.manage');

  const {
    data: employee,
    isLoading,
    isError,
    refetch,
  } = useQuery({
    queryKey: ['hr', 'employee', id],
    queryFn: () => employeesApi.show(id),
  });

  // The current shift + schedule, so the form opens fully prefilled. Only people who
  // may manage shifts need (or are allowed) to load it.
  const shiftQuery = useQuery({
    queryKey: ['attendance', 'employee-shift', id],
    queryFn: () => shiftsApi.currentAssignment(id),
    enabled: canAssignShift,
    retry: false,
  });
  const currentShiftId = shiftQuery.data?.data?.id ?? '';
  const currentAssignment = shiftQuery.data?.assignment ?? null;

  const mutation = useMutation({
    mutationFn: async (d: EmployeeFormValues) => {
      const payload = cleanup(d);
      const employee = await employeesApi.update(id, payload);

      // Shift: a different shift starts a new assignment today; the same shift with
      // different working days updates the current assignment in place; otherwise
      // nothing is sent.
      if (canAssignShift && d.shift_id) {
        const days = d.working_days ?? [];
        if (d.shift_id !== currentShiftId) {
          await shiftsApi.assignEmployee(id, {
            shift_id: d.shift_id,
            effective_date: localIsoDate(),
            working_days: days,
          });
        } else if (
          currentAssignment &&
          !sameDays(days, currentAssignment.working_days ?? DEFAULT_WORKING_DAYS)
        ) {
          await shiftsApi.configureWorkingDays(currentAssignment.id, days);
        }
      }

      return employee;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['hr', 'employees'] });
      qc.invalidateQueries({ queryKey: ['hr', 'employee', id] });
      qc.invalidateQueries({ queryKey: ['attendance', 'employee-shift', id] });
      toast.success('Employee updated.');
      navigate(`/hr/employees/${id}`);
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
          setErrorRef.current?.(field as keyof EmployeeFormValues, msgs[0]);
        });
        toast.error(summarizeServerErrors(e.response.data.errors, SERVER_FIELD_LABELS), {
          duration: 6000,
        });
      } else {
        toast.error('Failed to update employee.');
      }
    },
  });

  if (isLoading || (canAssignShift && shiftQuery.isLoading)) {
    return (
      <div>
        <PageHeader title="Edit employee" backTo="/hr/employees" backLabel="Employees" />
        <SkeletonForm />
      </div>
    );
  }

  if (isError || !employee) {
    return (
      <div>
        <PageHeader title="Edit employee" backTo="/hr/employees" backLabel="Employees" />
        <EmptyState
          icon="alert-circle"
          title="Failed to load employee"
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title={`Edit ${employee.full_name}`}
        subtitle={<span className="font-mono">{employee.employee_no}</span>}
        backTo={`/hr/employees/${employee.id}`}
        backLabel="Profile"
      />
      <EmployeeForm
        employee={employee}
        onSubmit={(d) => mutation.mutate(d)}
        onCancel={() => navigate(`/hr/employees/${employee.id}`)}
        isPending={mutation.isPending}
        canEditSensitive={can('hr.employees.view_sensitive')}
        canAssignShift={canAssignShift && !shiftQuery.isError}
        initialShift={
          shiftQuery.data?.data
            ? {
                shift_id: shiftQuery.data.data.id,
                working_days: currentAssignment?.working_days ?? null,
              }
            : null
        }
        registerSetError={(fn) => {
          setErrorRef.current = fn;
        }}
        submitLabel="Save changes"
      />
    </div>
  );
}
