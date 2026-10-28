import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { leaveRequestsApi } from '@/api/leave';
import LeavesPage from './index';

vi.mock('@/api/leave', () => ({
  leaveRequestsApi: {
    list: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }),
    options: vi.fn().mockResolvedValue({ statuses: [], half_day_periods: [], holiday_dates: [] }),
    approveDept: vi.fn(),
    approveHR: vi.fn(),
    bulkApproveDept: vi.fn(),
    bulkApproveHR: vi.fn(),
    reject: vi.fn(),
  },
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: (permission: string) => permission === 'leave.approve_dept' }) }));

it('loads and retains the actionable leave filter from its deep link', async () => {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/hr/leaves?signal=awaiting_my_approval']}>
        <LeavesPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );

  expect(await screen.findByText('No leave requests match')).toBeVisible();
  expect(leaveRequestsApi.list).toHaveBeenCalledWith(expect.objectContaining({ signal: 'awaiting_my_approval' }));
  expect(screen.queryByText('Status')).not.toBeInTheDocument();
});

it('shows row actions only when the backend says current role can approve', async () => {
  const row = {
    id: 'lr-1', leave_request_no: 'LR-202610-0001',
    employee: { id: 'e-1', employee_no: 'E-1', full_name: 'A. Employee', department: 'Production' },
    leave_type: { id: 'lt-1', code: 'VL', name: 'Vacation Leave' },
    start_date: '2026-10-27', end_date: '2026-10-27', days: '1.0', half_day_period: null,
    reason: null, has_document: false, status: 'pending_dept' as const,
    dept_approver: null, dept_approved_at: null, hr_approver: null, hr_approved_at: null,
    rejection_reason: null, created_at: '2026-10-26T00:00:00Z', updated_at: '2026-10-26T00:00:00Z',
  };
  vi.mocked(leaveRequestsApi.list).mockResolvedValueOnce({
    data: [
      { ...row, can_approve: false },
      { ...row, id: 'lr-2', leave_request_no: 'LR-202610-0002', can_approve: true },
    ],
    meta: { current_page: 1, last_page: 1, per_page: 100, total: 2, from: 1, to: 2 },
    links: { first: '', last: '', prev: null, next: null },
  });

  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/hr/leaves']}>
        <LeavesPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );

  expect(await screen.findByText('LR-202610-0001')).toBeVisible();
  expect(screen.getByText('LR-202610-0002')).toBeVisible();
  expect(screen.getAllByRole('button', { name: 'Approve' })).toHaveLength(1);
  expect(screen.getAllByRole('button', { name: 'Reject' })).toHaveLength(1);
});
