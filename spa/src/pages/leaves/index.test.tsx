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
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => false }) }));

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
