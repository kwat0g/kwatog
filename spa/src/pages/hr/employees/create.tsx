import { useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { employeesApi, type CreateEmployeeData } from '@/api/hr/employees';
import { EmployeeForm, type EmployeeFormValues } from '@/components/hr/EmployeeForm';
import { PageHeader } from '@/components/layout/PageHeader';
import type { ApiValidationError } from '@/types';

const cleanup = (d: EmployeeFormValues): CreateEmployeeData => {
  const out: Record<string, unknown> = { ...d };
  Object.keys(out).forEach((k) => {
    if (out[k] === '') out[k] = undefined;
  });
  return out as unknown as CreateEmployeeData;
};

export default function CreateEmployeePage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const setErrorRef = useRef<((field: keyof EmployeeFormValues, msg: string) => void) | null>(null);

  const mutation = useMutation({
    mutationFn: (d: EmployeeFormValues) => employeesApi.create(cleanup(d)),
    onSuccess: (employee) => {
      qc.invalidateQueries({ queryKey: ['hr', 'employees'] });
      toast.success(`Employee ${employee.employee_no} created.`);
      navigate(`/hr/employees/${employee.id}`);
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
          setErrorRef.current?.(field as keyof EmployeeFormValues, msgs[0]);
        });
        toast.error('Please fix the errors below.');
      } else {
        toast.error('Failed to create employee.');
      }
    },
  });

  return (
    <div>
      <PageHeader
        title="Add employee"
        backTo="/hr/employees"
        backLabel="Employees"
      />
      <EmployeeForm
        onSubmit={(d) => mutation.mutate(d)}
        onCancel={() => navigate('/hr/employees')}
        isPending={mutation.isPending}
        registerSetError={(fn) => { setErrorRef.current = fn; }}
        submitLabel="Create employee"
      />
    </div>
  );
}
