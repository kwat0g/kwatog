import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { overtimeApi } from '@/api/attendance/overtime';
import { useRealtimeList } from '@/hooks/useRealtimeRecord';
import OvertimeListPage from './index';

vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeList: vi.fn() }));
vi.mock('@/api/attendance/overtime', () => ({
  overtimeApi: {
    list: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } }),
    options: vi.fn().mockResolvedValue({ statuses: [{ value: 'pending', label: 'Pending' }] }),
  },
}));
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({
    can: (permission: string) => permission === 'attendance.ot.approve',
    canApprove: () => true,
  }),
}));

it('loads and retains the actionable overtime filter from its deep link', async () => {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/hr/attendance/overtime?signal=awaiting_my_approval']}>
        <OvertimeListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );

  expect(await screen.findByText('No OT requests match')).toBeVisible();
  expect(overtimeApi.list).toHaveBeenCalledWith(expect.objectContaining({
    signal: 'awaiting_my_approval',
  }));
  expect(useRealtimeList).toHaveBeenCalledWith('overtime_request', 'visible');
  expect(screen.queryByText('Status')).not.toBeInTheDocument();
});

it('disables an open rejection when the current server row no longer allows rejecting', async () => {
  const pendingRequest = {
    id: 'overtime-1',
    permissions: { approve: true, reject: true },
    available_actions: { approve: true, reject: true },
    employee: { id: 'employee-1', full_name: 'Ada Lovelace', employee_no: 'EMP-001' },
    date: '2026-11-02',
    hours_requested: '2.00',
    reason: 'Production support',
    status: 'pending' as const,
    status_label: 'Pending',
    approver: { id: 'approver-1', name: 'Grace Hopper' },
    approved_at: null,
    rejection_reason: null,
    is_auto_detected: false,
    created_at: '2026-11-02T08:00:00Z',
    updated_at: '2026-11-02T08:00:00Z',
  };
  const response = {
    data: [pendingRequest],
    meta: { current_page: 1, from: null, last_page: 1, per_page: 100, to: null, total: 1 },
    links: { first: '', last: '', prev: null, next: null },
  };
  vi.mocked(overtimeApi.list).mockResolvedValueOnce(response);
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/hr/attendance/overtime']}>
        <OvertimeListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );

  fireEvent.click((await screen.findAllByRole('button', { name: 'Reject' }))[0]);
  const rejectionDialog = await screen.findByRole('heading', { name: 'Reject overtime request' });
  expect(rejectionDialog).toBeVisible();
  fireEvent.change(screen.getByLabelText(/Reason for rejection/), { target: { value: 'Already covered' } });

  const listQuery = queryClient.getQueryCache().findAll({ queryKey: ['attendance', 'overtime'] })
    .find(({ queryKey }) => queryKey.length === 3);
  expect(listQuery).toBeDefined();
  queryClient.setQueryData(listQuery!.queryKey, {
    ...response,
    data: [{ ...pendingRequest, status: 'approved' as const, available_actions: { approve: false, reject: false } }],
  });

  await waitFor(() => {
    expect(screen.getByRole('button', { name: 'Confirm reject' })).toBeDisabled();
  });
  expect(screen.getByLabelText(/Reason for rejection/)).toHaveValue('Already covered');
  expect(screen.getByText(/no longer available|checking current status/i)).toBeVisible();
});
