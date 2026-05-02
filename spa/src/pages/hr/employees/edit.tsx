import { useRef } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { employeesApi, type UpdateEmployeeData } from '@/api/hr/employees';
import { EmployeeForm, type EmployeeFormValues } from '@/components/hr/EmployeeForm';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonForm } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import type { ApiValidationError } from '@/types';

const cleanup = (d: EmployeeFormValues): UpdateEmployeeData => {
  const out: Record<string, unknown> = { ...d };
  Object.keys(out).forEach((k) => {
    if (out[k] === '') out[k] = undefined;
  });
  return out as UpdateEmployeeData;
};

export default function EditEmployeePage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const setErrorRef = useRef<((field: keyof EmployeeFormValues, msg: string) => void) | null>(null);

  const { data: employee, isLoading, isError, refetch } = useQuery({
    queryKey: ['hr', 'employee', id],
    queryFn: () => employeesApi.show(id),
  });

  const mutation = useMutation({
    mutationFn: (d: EmployeeFormValues) => employeesApi.update(id, cleanup(d)),
    onSuccess: (employee) => {
      qc.invalidateQueries({ queryKey: ['hr', 'employees'] });
      qc.invalidateQueries({ queryKey: ['hr', 'employee', id] });
      toast.success('Employee updated.');
      navigate(`/hr/employees/${employee.id}`);
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
          setErrorRef.current?.(field as keyof EmployeeFormValues, msgs[0]);
        });
        toast.error('Please fix the errors below.');
      } else {
        toast.error('Failed to update employee.');
      }
    },
  });

  if (isLoading) {
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
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
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
        registerSetError={(fn) => { setErrorRef.current = fn; }}
        submitLabel="Save changes"
      />
    </div>
  );
}
