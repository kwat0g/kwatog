import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { adjustmentsApi } from '@/api/payroll/adjustments';
import type { PayrollAdjustment } from '@/types/payroll';
import type { PaginatedResponse } from '@/types';
import PayrollAdjustmentsPage from './index';

vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeList: vi.fn() }));
vi.mock('@/api/payroll/adjustments', () => ({
  adjustmentsApi: {
    list: vi.fn(),
    options: vi.fn(),
    approve: vi.fn(),
    reject: vi.fn(),
  },
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

const page = (rows: PayrollAdjustment[]): PaginatedResponse<PayrollAdjustment> => ({
  data: rows,
  meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length, from: rows.length ? 1 : null, to: rows.length || null },
  links: { first: '', last: '', prev: null, next: null },
});

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(adjustmentsApi.list).mockResolvedValue(page([adjustment]));
  vi.mocked(adjustmentsApi.options).mockResolvedValue({ statuses: [], types: [] });
});

it('disables a stale approval confirmation after the adjustment disappears from refreshed list data', async () => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const { unmount } = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/payroll/adjustments']}>
        <Routes>
          <Route path="/payroll/adjustments" element={<PayrollAdjustmentsPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  await screen.findAllByRole('button', { name: 'Approve' });
  fireEvent.click(screen.getAllByRole('button', { name: 'Approve' })[0]);
  const dialog = await screen.findByRole('dialog');
  const listQuery = queryClient.getQueryCache().getAll().find((query) => query.queryKey[0] === 'payroll-adjustments');
  expect(listQuery).toBeDefined();

  act(() => {
    queryClient.setQueryData(listQuery!.queryKey, page([]));
  });

  await waitFor(() => expect(dialog).toHaveTextContent(/no longer available/i));
  expect(within(dialog).getByRole('button', { name: 'Approve' })).toBeDisabled();
  expect(adjustmentsApi.approve).not.toHaveBeenCalled();

  unmount();
  queryClient.clear();
});
