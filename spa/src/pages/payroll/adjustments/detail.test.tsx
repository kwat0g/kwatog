import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
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
