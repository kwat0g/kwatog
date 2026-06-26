import { useRef } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { employeesApi, type CreateEmployeeData } from '@/api/hr/employees';
import { recruitmentApi } from '@/api/recruitment';
import { EmployeeForm, type EmployeeFormValues } from '@/components/hr/EmployeeForm';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonTable } from '@/components/ui/Skeleton';
import type { ApiValidationError } from '@/types';
import type { Employee } from '@/types/hr';

const cleanup = (d: EmployeeFormValues): CreateEmployeeData => {
  const out: Record<string, unknown> = { ...d };
  Object.keys(out).forEach((k) => {
    if (out[k] === '') out[k] = undefined;
  });
  return out as unknown as CreateEmployeeData;
};

export default function CreateEmployeePage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const fromApplication = searchParams.get('from_application');
  const qc = useQueryClient();
  const setErrorRef = useRef<((field: keyof EmployeeFormValues, msg: string) => void) | null>(null);

  const { data: conversionData, isLoading: conversionLoading } = useQuery({
    queryKey: ['recruitment-conversion', fromApplication],
    queryFn: () => recruitmentApi.getConversionData(fromApplication!).then((r) => r.data.data),
    enabled: !!fromApplication,
  });

  const prefillEmployee = conversionData
    ? ({
        first_name: conversionData.first_name ?? '',
        last_name: conversionData.last_name ?? '',
        contact: {
          email: conversionData.email ?? null,
          mobile_number: conversionData.phone ?? null,
          emergency_contact_name: null,
          emergency_contact_relation: null,
          emergency_contact_phone: null,
        },
        department: conversionData.department_id ? { id: conversionData.department_id } : null,
        position: conversionData.position_id ? { id: conversionData.position_id } : null,
      } as unknown as Employee)
    : null;

  const mutation = useMutation({
    mutationFn: (d: EmployeeFormValues) => {
      const payload = cleanup(d);
      if (fromApplication) {
        (payload as Record<string, unknown>).from_application = fromApplication;
      }
      return employeesApi.create(payload);
    },
    onSuccess: (employee) => {
      qc.invalidateQueries({ queryKey: ['hr', 'employees'] });
      if (fromApplication) {
        qc.invalidateQueries({ queryKey: ['recruitment-application', fromApplication] });
      }
      toast.success(`Employee ${employee.employee_no} created.`);
      navigate(`/hr/employees/${employee.id}`);
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
          setErrorRef.current?.(field as keyof EmployeeFormValues, msgs[0]);
        });
        toast.error(e.response?.data?.message || 'Validation failed.');
      } else {
        toast.error('Failed to create employee.');
      }
    },
  });

  if (fromApplication && conversionLoading) {
    return <SkeletonTable rows={5} cols={3} />;
  }

  return (
    <div>
      <PageHeader
        title={fromApplication ? 'Convert applicant to employee' : 'Add employee'}
        backTo="/hr/employees"
        backLabel="Employees"
        breadcrumbs={[
          { label: 'HR', href: '/hr' },
          { label: 'Employees', href: '/hr/employees' },
          { label: fromApplication ? 'Convert Applicant' : 'New Employee' },
        ]}
      />
      {fromApplication && conversionData && (
        <div className="mx-auto mt-4 max-w-4xl rounded-lg border border-info/30 bg-info/5 px-4 py-3">
          <p className="text-sm">
            <span className="font-medium">Converting applicant:</span>{' '}
            {conversionData.first_name} {conversionData.last_name}.
            Fields have been pre-filled from the application. Complete the remaining details below.
          </p>
        </div>
      )}
      <EmployeeForm
        employee={prefillEmployee}
        onSubmit={(d) => mutation.mutate(d)}
        onCancel={() => navigate('/hr/employees')}
        isPending={mutation.isPending}
        registerSetError={(fn) => { setErrorRef.current = fn; }}
        submitLabel={fromApplication ? 'Convert & create employee' : 'Create employee'}
      />
    </div>
  );
}
