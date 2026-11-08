import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { leaveRequestsApi } from '@/api/leave';
import { useRealtimeList } from '@/hooks/useRealtimeRecord';
import type { LeaveRequestAction } from '@/types/leave';
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
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeList: vi.fn() }));

it('loads and retains the actionable leave filter from its deep link', async () => {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/hr/leaves?signal=awaiting_my_approval']}>
        <LeavesPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );

  expect(await screen.findByText('No leave requests match')).toBeVisible();
  expect(useRealtimeList).toHaveBeenCalledWith('leave_type', 'visible');
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
    available_actions: [],
    dept_approver: null, dept_approved_at: null, hr_approver: null, hr_approved_at: null,
    rejection_reason: null, created_at: '2026-10-26T00:00:00Z', updated_at: '2026-10-26T00:00:00Z',
  };
  vi.mocked(leaveRequestsApi.list).mockResolvedValueOnce({
    data: [
      { ...row, can_approve: false },
      { ...row, id: 'lr-2', leave_request_no: 'LR-202610-0002', can_approve: true, available_actions: ['approve_dept', 'reject'] as LeaveRequestAction[] },
      { ...row, id: 'lr-3', leave_request_no: 'LR-202610-0003', can_approve: true },
    ],
    meta: { current_page: 1, last_page: 1, per_page: 100, total: 3, from: 1, to: 3 },
    links: { first: '', last: '', prev: null, next: null },
  });

  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/hr/leaves']}>
        <LeavesPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );

  expect((await screen.findAllByText('LR-202610-0001')).length).toBeGreaterThan(0);
  expect(screen.getAllByText('LR-202610-0002').length).toBeGreaterThan(0);
  expect(screen.getAllByText('LR-202610-0003').length).toBeGreaterThan(0);
  // DataTable renders responsive desktop and compact layouts together; one
  // actionable row therefore exposes each action twice in the DOM.
  expect(screen.getAllByRole('button', { name: 'Approve' })).toHaveLength(2);
  expect(screen.getAllByRole('button', { name: 'Reject' })).toHaveLength(2);
});

it('fails closed when the server omits the Leave action projection', async () => {
  const legacyRow = {
    id: 'lr-legacy', leave_request_no: 'LR-202611-0200',
    employee: { id: 'e-1', employee_no: 'E-1', full_name: 'A. Employee', department: 'Production' },
    leave_type: { id: 'lt-1', code: 'VL', name: 'Vacation Leave' },
    start_date: '2026-11-03', end_date: '2026-11-03', days: '1.0', half_day_period: null,
    reason: null, has_document: false, status: 'pending_dept' as const, can_approve: true,
    dept_approver: null, dept_approved_at: null, hr_approver: null, hr_approved_at: null,
    rejection_reason: null, created_at: '2026-11-02T00:00:00Z', updated_at: '2026-11-02T00:00:00Z',
  };
  vi.mocked(leaveRequestsApi.list).mockResolvedValueOnce({
    data: [legacyRow],
    meta: { current_page: 1, last_page: 1, per_page: 100, total: 1, from: 1, to: 1 },
    links: { first: '', last: '', prev: null, next: null },
  } as never);

  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/hr/leaves']}><LeavesPage /></MemoryRouter>
    </QueryClientProvider>,
  );

  expect(await screen.findAllByText('LR-202611-0200')).not.toHaveLength(0);
  expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
});

it('applies the approved request response to the actor list while its refetch is pending', async () => {
  const request = {
    id: 'lr-race', leave_request_no: 'LR-202611-0099',
    employee: { id: 'e-1', employee_no: 'E-1', full_name: 'A. Employee', department: 'Production' },
    leave_type: { id: 'lt-1', code: 'VL', name: 'Vacation Leave' },
    start_date: '2026-11-03', end_date: '2026-11-03', days: '1.0', half_day_period: null,
    reason: null, has_document: false, status: 'pending_dept' as const, can_approve: true,
    available_actions: ['approve_dept', 'reject'] as LeaveRequestAction[],
    dept_approver: null, dept_approved_at: null, hr_approver: null, hr_approved_at: null,
    rejection_reason: null, created_at: '2026-11-02T00:00:00Z', updated_at: '2026-11-02T00:00:00Z',
  };
  const approved = {
    ...request,
    status: 'pending_hr' as const,
    can_approve: false,
    available_actions: [] as LeaveRequestAction[],
    dept_approver: { id: 'u-1', name: 'Approver' },
    dept_approved_at: '2026-11-02T00:01:00Z',
  };
  vi.mocked(leaveRequestsApi.list)
    .mockResolvedValueOnce({
      data: [request],
      meta: { current_page: 1, last_page: 1, per_page: 100, total: 1, from: 1, to: 1 },
      links: { first: '', last: '', prev: null, next: null },
    })
    .mockReturnValue(new Promise(() => {}));
  vi.mocked(leaveRequestsApi.approveDept).mockResolvedValue(approved);

  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/hr/leaves']}><LeavesPage /></MemoryRouter>
    </QueryClientProvider>,
  );

  fireEvent.click((await screen.findAllByRole('button', { name: 'Approve' }))[0]);
  const approveButtons = await screen.findAllByRole('button', { name: 'Approve' });
  fireEvent.click(approveButtons[approveButtons.length - 1]);
  await waitFor(() => expect(leaveRequestsApi.approveDept).toHaveBeenCalledWith('lr-race'));
  expect(await screen.findAllByText('pending hr')).toHaveLength(3);

  expect(screen.queryAllByRole('button', { name: 'Approve' })).toHaveLength(0);
  expect(screen.queryAllByRole('button', { name: 'Reject' })).toHaveLength(0);
});

it('reconciles successful rows from a bulk approval before the actor list refetch', async () => {
  const request = {
    id: 'lr-bulk', leave_request_no: 'LR-202611-0100',
    employee: { id: 'e-1', employee_no: 'E-1', full_name: 'A. Employee', department: 'Production' },
    leave_type: { id: 'lt-1', code: 'VL', name: 'Vacation Leave' },
    start_date: '2026-11-03', end_date: '2026-11-03', days: '1.0', half_day_period: null,
    reason: null, has_document: false, status: 'pending_dept' as const, can_approve: true,
    available_actions: ['approve_dept', 'reject'] as LeaveRequestAction[],
    dept_approver: null, dept_approved_at: null, hr_approver: null, hr_approved_at: null,
    rejection_reason: null, created_at: '2026-11-02T00:00:00Z', updated_at: '2026-11-02T00:00:00Z',
  };
  const approved = {
    ...request,
    status: 'pending_hr' as const,
    can_approve: false,
    available_actions: [] as LeaveRequestAction[],
    dept_approver: { id: 'u-1', name: 'Approver' },
    dept_approved_at: '2026-11-02T00:01:00Z',
  };
  vi.mocked(leaveRequestsApi.list)
    .mockResolvedValueOnce({
      data: [request],
      meta: { current_page: 1, last_page: 1, per_page: 100, total: 1, from: 1, to: 1 },
      links: { first: '', last: '', prev: null, next: null },
    })
    .mockReturnValue(new Promise(() => {}));
  vi.mocked(leaveRequestsApi.bulkApproveDept).mockResolvedValue({ approved: [approved], failed: [] });

  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/hr/leaves']}><LeavesPage /></MemoryRouter>
    </QueryClientProvider>,
  );

  fireEvent.click((await screen.findAllByRole('checkbox', { name: 'Select row' }))[0]);
  fireEvent.click(await screen.findByRole('button', { name: 'Approve selected' }));
  await waitFor(() => expect(leaveRequestsApi.bulkApproveDept).toHaveBeenCalledWith(['lr-bulk']));

  expect(await screen.findAllByText('pending hr')).toHaveLength(3);
  expect(screen.queryAllByRole('button', { name: 'Approve' })).toHaveLength(0);
  expect(screen.queryAllByRole('button', { name: 'Reject' })).toHaveLength(0);
});

it('disables a stale leave rejection dialog after the request is no longer rejectable', async () => {
  vi.mocked(leaveRequestsApi.list).mockReset().mockResolvedValueOnce({
    data: [{
      id: 'lr-stale-reject',
      leave_request_no: 'LR-202611-0199',
      employee: { id: 'e-1', employee_no: 'E-1', full_name: 'A. Employee', department: 'Production' },
      leave_type: { id: 'lt-1', code: 'VL', name: 'Vacation Leave' },
      start_date: '2026-11-03',
      end_date: '2026-11-03',
      days: '1.0',
      half_day_period: null,
      reason: null,
      has_document: false,
      status: 'pending_dept' as const,
      can_approve: true,
      available_actions: ['approve_dept', 'reject'] as LeaveRequestAction[],
      dept_approver: null,
      dept_approved_at: null,
      hr_approver: null,
      hr_approved_at: null,
      rejection_reason: null,
      created_at: '2026-11-02T00:00:00Z',
      updated_at: '2026-11-02T00:00:00Z',
    }],
    meta: { current_page: 1, last_page: 1, per_page: 100, total: 1, from: 1, to: 1 },
    links: { first: '', last: '', prev: null, next: null },
  });
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const { unmount } = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/hr/leaves']}><LeavesPage /></MemoryRouter>
    </QueryClientProvider>,
  );

  fireEvent.click((await screen.findAllByRole('button', { name: 'Reject' }))[0]);
  const dialog = screen.getByRole('dialog', { name: 'Reject leave request' });
  fireEvent.change(within(dialog).getByLabelText(/Reason for rejection/), {
    target: { value: 'Already reviewed by the department head' },
  });

  const listQuery = queryClient.getQueryCache().getAll().find(
    (query) => query.queryKey[0] === 'leaves' && typeof query.queryKey[1] === 'object',
  );
  expect(listQuery).toBeDefined();
  act(() => {
    const current = queryClient.getQueryData<{
      data: Array<Record<string, unknown>>;
      meta: unknown;
      links: unknown;
    }>(listQuery!.queryKey);
    expect(current).toBeDefined();
    if (!current) return;
    queryClient.setQueryData(listQuery!.queryKey, {
      ...current,
      data: current.data.map((row) => ({ ...row, status: 'approved', available_actions: [] })),
    });
  });

  await waitFor(() => expect(dialog).toHaveTextContent(/no longer available for rejection/i));
  expect(within(dialog).getByLabelText(/Reason for rejection/)).toHaveValue('Already reviewed by the department head');
  expect(within(dialog).getByRole('button', { name: 'Confirm reject' })).toBeDisabled();
  expect(leaveRequestsApi.reject).not.toHaveBeenCalled();

  unmount();
  queryClient.clear();
});

it('disables leave approval while a refreshed request is being checked', async () => {
  const request = {
    id: 'lr-stale-approve',
    leave_request_no: 'LR-202611-0201',
    employee: { id: 'e-1', employee_no: 'E-1', full_name: 'A. Employee', department: 'Production' },
    leave_type: { id: 'lt-1', code: 'VL', name: 'Vacation Leave' },
    start_date: '2026-11-03',
    end_date: '2026-11-03',
    days: '1.0',
    half_day_period: null,
    reason: null,
    has_document: false,
    status: 'pending_dept' as const,
    can_approve: true,
    available_actions: ['approve_dept', 'reject'] as LeaveRequestAction[],
    dept_approver: null,
    dept_approved_at: null,
    hr_approver: null,
    hr_approved_at: null,
    rejection_reason: null,
    created_at: '2026-11-02T00:00:00Z',
    updated_at: '2026-11-02T00:00:00Z',
  };
  vi.mocked(leaveRequestsApi.list).mockReset().mockResolvedValueOnce({
    data: [request],
    meta: { current_page: 1, last_page: 1, per_page: 100, total: 1, from: 1, to: 1 },
    links: { first: '', last: '', prev: null, next: null },
  });
  vi.mocked(leaveRequestsApi.approveDept).mockReset();
  let resolveRefresh!: (response: Awaited<ReturnType<typeof leaveRequestsApi.list>>) => void;
  vi.mocked(leaveRequestsApi.list).mockReturnValueOnce(new Promise((resolve) => {
    resolveRefresh = resolve;
  }));

  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const { unmount } = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/hr/leaves']}><LeavesPage /></MemoryRouter>
    </QueryClientProvider>,
  );

  fireEvent.click((await screen.findAllByRole('button', { name: 'Approve' }))[0]);
  const dialog = screen.getByRole('dialog');
  const listQuery = queryClient.getQueryCache().getAll().find(
    (query) => query.queryKey[0] === 'leaves' && typeof query.queryKey[1] === 'object',
  );
  expect(listQuery).toBeDefined();

  act(() => {
    void queryClient.invalidateQueries({ queryKey: listQuery!.queryKey, exact: true });
  });
  await waitFor(() => expect(leaveRequestsApi.list).toHaveBeenCalledTimes(2));
  expect(within(dialog).getByRole('button', { name: 'Approve' })).toBeDisabled();

  await act(async () => {
    resolveRefresh({
      data: [{ ...request, status: 'pending_hr', available_actions: [] }],
      meta: { current_page: 1, last_page: 1, per_page: 100, total: 1, from: 1, to: 1 },
      links: { first: '', last: '', prev: null, next: null },
    } as Awaited<ReturnType<typeof leaveRequestsApi.list>>);
  });
  await waitFor(() => expect(within(dialog).getByRole('button', { name: 'Approve' })).toBeDisabled());
  expect(leaveRequestsApi.approveDept).not.toHaveBeenCalled();

  unmount();
  queryClient.clear();
});
