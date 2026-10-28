import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { employeesApi, type EmployeeOptions, type EmployeeStatusCounts } from '@/api/hr/employees';
import EmployeesListPage from './index';

vi.mock('@/api/hr/employees', () => ({
  employeesApi: {
    options: vi.fn(),
    list: vi.fn(),
    statusCounts: vi.fn(),
    show: vi.fn(),
  },
}));
vi.mock('@/api/hr/departments', () => ({ departmentsApi: { tree: vi.fn().mockResolvedValue([]) } }));
vi.mock('@/api/hr/employee-accounts', () => ({ employeeAccountsApi: { bulkProvision: vi.fn() } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));

const options: EmployeeOptions = {
  statuses: [
    { value: 'active', label: 'Active' },
    { value: 'on_leave', label: 'On Leave' },
    { value: 'suspended', label: 'Suspended' },
    { value: 'resigned', label: 'Resigned' },
    { value: 'terminated', label: 'Terminated' },
    { value: 'retired', label: 'Retired' },
  ],
  employment_types: [],
  pay_types: [],
  genders: [],
  civil_statuses: [],
  separation_reasons: [],
  skill_levels: [],
};

const counts: EmployeeStatusCounts = {
  counts: { active: 30, on_leave: 4, suspended: 0, resigned: 3, terminated: 1, retired: 4 },
  total: 42,
};

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/hr/employees']}>
        <EmployeesListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Employees list status tabs', () => {
  beforeEach(() => {
    vi.mocked(employeesApi.options).mockResolvedValue(options);
    vi.mocked(employeesApi.list).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 30, from: null, to: null },
      links: { first: '', last: '', prev: null, next: null },
    });
    vi.mocked(employeesApi.statusCounts).mockResolvedValue(counts);
  });

  it('shows status counts and requests the selected status, with All omitting the status filter', async () => {
    renderPage();

    const activeTab = await screen.findByRole('tab', { name: 'Active 30' });
    expect(activeTab).toHaveAttribute('aria-selected', 'true');
    expect(await screen.findByRole('tab', { name: 'Suspended 0' })).toBeInTheDocument();
    await waitFor(() => expect(employeesApi.list).toHaveBeenLastCalledWith(
      expect.objectContaining({ status: 'active' }),
    ));

    fireEvent.click(screen.getByRole('tab', { name: 'On Leave 4' }));
    await waitFor(() => expect(employeesApi.list).toHaveBeenLastCalledWith(
      expect.objectContaining({ status: 'on_leave' }),
    ));

    fireEvent.click(screen.getByRole('tab', { name: 'All 42' }));
    await waitFor(() => expect(employeesApi.list).toHaveBeenLastCalledWith(
      expect.objectContaining({ status: undefined }),
    ));
  });
});
