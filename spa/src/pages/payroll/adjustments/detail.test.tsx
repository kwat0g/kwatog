import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { adjustmentsApi } from '@/api/payroll/adjustments';
import type { PayrollAdjustment } from '@/types/payroll';
import PayrollAdjustmentDetailPage from './detail';

const grants = vi.hoisted(() => new Set<string>());
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({
    canApprove: (permission: string) => grants.has(permission),
  }),
}));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeRecord: vi.fn() }));
vi.mock('@/api/payroll/adjustments', () => ({
  adjustmentsApi: { show: vi.fn(), approve: vi.fn(), reject: vi.fn() },
}));

const adjustment: PayrollAdjustment = {
  id: 'adjustment-hash-1',
  period: { id: 'period-hash-1', label: 'Sep 16 – Sep 30, 2026' },
  employee: { id: 'employee-hash-1', employee_no: 'EMP-0042', full_name: 'Mika Tanaka' },
  type: 'underpayment',
  type_label: 'Underpayment',
  amount: '1250.00',
  reason: 'Missed approved overtime line.',
  status: 'pending',
  status_label: 'Pending',
  permissions: { view: true, approve: true, reject: true },
  available_actions: { approve: true, reject: true },
  approver: null,
  applied_at: null,
  created_at: '2026-10-24T03:00:00.000Z',
  updated_at: '2026-10-24T03:00:00.000Z',
};

beforeEach(() => {
  vi.clearAllMocks();
  grants.clear();
  vi.mocked(adjustmentsApi.show).mockResolvedValue(adjustment);
});

it('opens the hashed adjustment target and presents its approval details', async () => {
  grants.add('payroll.adjustments.approve');
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/payroll/adjustments/adjustment-hash-1']}>
        <Routes>
          <Route path="/payroll/adjustments/:id" element={<PayrollAdjustmentDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  expect(await screen.findByText('Missed approved overtime line.')).toBeInTheDocument();
  expect(screen.getAllByText('Mika Tanaka')).toHaveLength(2);
  expect(screen.getByRole('button', { name: 'Approve' })).toBeInTheDocument();
  expect(adjustmentsApi.show).toHaveBeenCalledWith('adjustment-hash-1');
});

it('renders approval buttons only when the current API resource permits them', async () => {
  grants.add('payroll.adjustments.approve');
  grants.add('payroll.adjustments.reject');
  vi.mocked(adjustmentsApi.show).mockResolvedValue({
    ...adjustment,
    permissions: { view: true, approve: true, reject: true },
    available_actions: { approve: false, reject: false },
  });
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/payroll/adjustments/adjustment-hash-1']}>
        <Routes>
          <Route path="/payroll/adjustments/:id" element={<PayrollAdjustmentDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  expect(await screen.findByText('Missed approved overtime line.')).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
});

it('keeps the approval response when an older detail read finishes afterward', async () => {
  grants.add('payroll.adjustments.approve');
  let finishOldRead: ((value: PayrollAdjustment) => void) | undefined;
  vi.mocked(adjustmentsApi.show).mockImplementation(() => new Promise((resolve) => {
    finishOldRead = resolve;
  }));
  const approved = {
    ...adjustment,
    status: 'approved',
    status_label: 'Approved',
    available_actions: { approve: false, reject: false },
  } as PayrollAdjustment;
  vi.mocked(adjustmentsApi.approve).mockResolvedValue(approved);
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const detailKey = ['payroll-adjustment', adjustment.id];

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/payroll/adjustments/${adjustment.id}`]}>
        <Routes>
          <Route path="/payroll/adjustments/:id" element={<PayrollAdjustmentDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  await waitFor(() => expect(adjustmentsApi.show).toHaveBeenCalledOnce());
  queryClient.setQueryData(detailKey, adjustment);
  expect(queryClient.getQueryState(detailKey)?.fetchStatus).toBe('fetching');
  await screen.findByRole('button', { name: 'Approve' });
  fireEvent.click(screen.getByRole('button', { name: 'Approve' }));
  await screen.findByText('Approve payroll adjustment?');
  const approveButtons = screen.getAllByRole('button', { name: 'Approve' });
  fireEvent.click(approveButtons[approveButtons.length - 1]);

  await waitFor(() => expect(adjustmentsApi.approve).toHaveBeenCalledOnce());
  await screen.findByText('Approved');
  expect(queryClient.getQueryState(detailKey)?.fetchStatus).toBe('idle');
  await act(async () => {
    finishOldRead?.(adjustment);
    await Promise.resolve();
  });
  await waitFor(() => expect(queryClient.getQueryState(detailKey)?.fetchStatus).toBe('idle'));

  expect(queryClient.getQueryData(detailKey)).toEqual(approved);
  expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
  queryClient.clear();
});
