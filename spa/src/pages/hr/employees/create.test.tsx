import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { employeesApi } from '@/api/hr/employees';
import { shiftsApi } from '@/api/attendance/shifts';
import CreateEmployeePage from './create';
import { recruitmentApi } from '@/api/recruitment';
import { AxiosHeaders } from 'axios';
import type { Employee } from '@/types/hr';

vi.mock('@/api/hr/employees', () => ({ employeesApi: { create: vi.fn() } }));
vi.mock('@/api/attendance/shifts', () => ({ shiftsApi: { assignEmployee: vi.fn() } }));
vi.mock('@/api/recruitment', () => ({ recruitmentApi: { getConversionData: vi.fn() } }));
vi.mock('@/components/layout/PageHeader', () => ({ PageHeader: () => null }));
vi.mock('@/components/ui/Skeleton', () => ({ SkeletonTable: () => null }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@/components/hr/EmployeeForm', () => ({
  EmployeeForm: ({
    isPending,
    onSubmit,
    submitLabel,
    employee,
    lockPostingFields,
  }: {
    isPending: boolean;
    onSubmit: (data: { date_hired: string; shift_id: string }) => void;
    submitLabel: string;
    employee?: Employee | null;
    lockPostingFields?: boolean;
  }) => (
    <button
      data-employment-type={employee?.employment_type}
      data-posting-locked={lockPostingFields}
      type="button"
      disabled={isPending}
      onClick={() => onSubmit({ date_hired: '2026-10-03', shift_id: 'shift-1' })}
    >
      {submitLabel}
    </button>
  ),
}));

describe('create employee flow', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(employeesApi.create).mockResolvedValue({
      id: 'employee-1',
      employee_no: 'OGM-2026-0001',
    } as never);
  });

  it('sends the selected shift with employee creation as one request', async () => {
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter>
          <CreateEmployeePage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Create employee' }));

    await waitFor(() =>
      expect(employeesApi.create).toHaveBeenCalledWith({
        date_hired: '2026-10-03',
        shift_id: 'shift-1',
      }),
    );
    expect(shiftsApi.assignEmployee).not.toHaveBeenCalled();
  });

  it('passes posting employment type into the applicant conversion form', async () => {
    vi.mocked(recruitmentApi.getConversionData).mockResolvedValue({
      data: { data: { first_name: 'Jamie', last_name: 'Reyes', department_id: 'd1', position_id: 'p1', employment_type: 'probationary' } },
      status: 200, statusText: 'OK', headers: {}, config: { headers: new AxiosHeaders() },
    });
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter initialEntries={['/hr/employees/create?from_application=application-1']}>
          <CreateEmployeePage />
        </MemoryRouter>
      </QueryClientProvider>,
    );
    const submit = await screen.findByRole('button', { name: 'Convert & create employee' });
    expect(submit).toHaveAttribute('data-employment-type', 'probationary');
    expect(submit).toHaveAttribute('data-posting-locked', 'true');
  });
});
