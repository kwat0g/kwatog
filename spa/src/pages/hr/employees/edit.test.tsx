import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { employeesApi } from '@/api/hr/employees';
import type { Employee } from '@/types/hr';
import EditEmployeePage from './edit';

vi.mock('@/api/hr/employees', () => ({
  employeesApi: {
    show: vi.fn(),
    update: vi.fn(),
  },
}));
vi.mock('@/api/attendance/shifts', () => ({ shiftsApi: { currentAssignment: vi.fn() } }));
vi.mock('@/components/hr/EmployeeForm', () => ({
  EmployeeForm: ({ employee, onSubmit, submitLabel }: {
    employee: Employee;
    onSubmit: (values: never) => void;
    submitLabel: string;
  }) => (
    <div>
      <span>{employee.status}</span>
      <button onClick={() => onSubmit({ first_name: 'Updated' } as never)}>{submitLabel}</button>
    </div>
  ),
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => false }) }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useEmployeeShiftRealtime: vi.fn() }));

const employee = (status: Employee['status']): Employee => ({
  id: 'employee-1',
  employee_no: 'EMP-001',
  full_name: 'Test Employee',
  first_name: 'Test',
  middle_name: null,
  last_name: 'Employee',
  suffix: null,
  status,
  photo_path: null,
  photo_url: null,
  address: {} as Employee['address'],
  contact: {} as Employee['contact'],
} as Employee);

describe('EditEmployeePage mutation freshness', () => {
  beforeEach(() => vi.clearAllMocks());

  it('keeps the fresh employee response in the detail cache while navigation starts', async () => {
    const before = employee('active');
    const updated = employee('on_leave');
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    vi.mocked(employeesApi.show).mockResolvedValue(before);
    vi.mocked(employeesApi.update).mockResolvedValue(updated);

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/hr/employees/employee-1/edit']}>
          <Routes>
            <Route path="/hr/employees/:id/edit" element={<EditEmployeePage />} />
            <Route path="/hr/employees/:id" element={<span>Employee profile</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    await screen.findByText('active');
    vi.mocked(employeesApi.show).mockImplementation(() => new Promise(() => undefined));
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(queryClient.getQueryData(['hr', 'employee', 'employee-1']))
      .toMatchObject({ status: 'on_leave' }));
  });
});
