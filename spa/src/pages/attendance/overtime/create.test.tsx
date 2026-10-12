import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { employeesApi } from '@/api/hr/employees';
import { overtimeApi } from '@/api/attendance/overtime';
import type { AuthUser } from '@/api/auth';
import { useAuthStore } from '@/stores/authStore';
import OvertimeCreatePage from './create';

vi.mock('@/api/hr/employees', () => ({ employeesApi: { list: vi.fn() } }));
vi.mock('@/api/attendance/overtime', () => ({ overtimeApi: { options: vi.fn(), create: vi.fn() } }));

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  useAuthStore.setState({ user: null, permissions: new Set() });
});

it('does not request the employee directory for an OT creator without HR read access', async () => {
  const user: AuthUser = {
    id: 'user-1',
    name: 'Demo Employee',
    email: 'employee@example.test',
    is_active: true,
    must_change_password: false,
    theme_mode: 'light',
    sidebar_collapsed: false,
    role: { id: 'role-1', name: 'OT Creator', slug: 'ot_creator' },
    employee: {
      id: 'employee-1',
      employee_no: 'EMP-001',
      full_name: 'Demo Employee',
      department_id: null,
    },
    permissions: ['attendance.ot.create'],
    delegated_approvals: [],
    features: ['attendance', 'hr'],
  };
  useAuthStore.setState({
    user,
    permissions: new Set(user.permissions),
    features: new Set(user.features),
  });
  vi.mocked(overtimeApi.options).mockResolvedValue({
    statuses: [],
    minimum_hours: 0.5,
    maximum_hours: 8,
    request_min_hours: 0.5,
    request_future_days: 30,
    request_past_days: 0,
    premium_multiplier: 1.25,
  });

  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter><OvertimeCreatePage /></MemoryRouter>
    </QueryClientProvider>,
  );

  expect(await screen.findByText('Requesting for Demo Employee.')).toBeInTheDocument();
  expect(employeesApi.list).not.toHaveBeenCalled();
  expect(screen.queryByLabelText('Employee')).not.toBeInTheDocument();
});
