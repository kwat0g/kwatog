import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { payrollAnomaliesApi } from '@/api/payroll/anomalies';
import type { PayrollAnomalyFlag } from '@/types/payroll';
import { AnomalyReviewPanel } from './AnomalyReviewPanel';

vi.mock('@/api/payroll/anomalies', () => ({
  payrollAnomaliesApi: {
    list: vi.fn(),
    resolve: vi.fn(),
  },
}));
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({ can: () => true }),
}));
vi.mock('react-hot-toast', () => ({ default: { error: vi.fn(), success: vi.fn() } }));

const flag: PayrollAnomalyFlag = {
  id: 'flag-1',
  flag_type: 'zero_pay',
  details: { current: 0 },
  is_resolved: false,
  resolution_remarks: null,
  resolved_at: null,
  employee: { id: 'employee-1', employee_no: 'EMP-001', name: 'Ana Cruz' },
  payroll_id: 'payroll-1',
  created_at: '2026-11-03T00:00:00Z',
};

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(payrollAnomaliesApi.list).mockResolvedValue({
    data: [flag],
    meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
  });
});

it('disables a stale anomaly action after another reviewer resolves it and preserves typed remarks', async () => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const queryKey = ['payroll', 'period-1', 'anomalies', { resolved: false }];
  const { unmount } = render(
    <QueryClientProvider client={queryClient}>
      <AnomalyReviewPanel periodId="period-1" periodStatus="computed" makerIds={[]} />
    </QueryClientProvider>,
  );

  fireEvent.click(await screen.findByRole('button', { name: 'Mark as reviewed' }));
  const dialog = screen.getByRole('dialog', { name: 'Resolve anomaly' });
  const remarks = within(dialog).getByLabelText('Remarks');
  fireEvent.change(remarks, { target: { value: 'Verified with payroll lead' } });

  act(() => {
    queryClient.setQueryData(queryKey, {
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 },
    });
  });

  await waitFor(() => expect(dialog).toHaveTextContent(/no longer unresolved/i));
  expect(within(dialog).getByLabelText('Remarks')).toHaveValue('Verified with payroll lead');
  expect(within(dialog).getByRole('button', { name: 'Mark reviewed' })).toBeDisabled();
  expect(payrollAnomaliesApi.resolve).not.toHaveBeenCalled();

  unmount();
  queryClient.clear();
});

it('disables a resolution dialog when the payroll period becomes ineligible', async () => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const { rerender, unmount } = render(
    <QueryClientProvider client={queryClient}>
      <AnomalyReviewPanel periodId="period-1" periodStatus="computed" makerIds={[]} />
    </QueryClientProvider>,
  );

  fireEvent.click(await screen.findByRole('button', { name: 'Mark as reviewed' }));
  const dialog = screen.getByRole('dialog', { name: 'Resolve anomaly' });
  fireEvent.change(within(dialog).getByLabelText('Remarks'), { target: { value: 'Verified externally' } });
  expect(within(dialog).getByRole('button', { name: 'Mark reviewed' })).toBeEnabled();

  rerender(
    <QueryClientProvider client={queryClient}>
      <AnomalyReviewPanel periodId="period-1" periodStatus="draft" makerIds={[]} />
    </QueryClientProvider>,
  );

  expect(within(dialog).getByRole('button', { name: 'Mark reviewed' })).toBeDisabled();
  fireEvent.click(within(dialog).getByRole('button', { name: 'Mark reviewed' }));
  expect(payrollAnomaliesApi.resolve).not.toHaveBeenCalled();

  unmount();
  queryClient.clear();
});
