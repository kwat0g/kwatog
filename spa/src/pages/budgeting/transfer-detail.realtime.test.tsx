import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { budgetingApi } from '@/api/accounting/budgeting';
import type { BudgetTransfer } from '@/types/budgeting';
import BudgetTransferDetailPage from './transfer-detail';

vi.mock('@/api/accounting/budgeting', () => ({
  budgetingApi: {
    transfer: vi.fn(),
    approveTransfer: vi.fn(),
    rejectTransfer: vi.fn(),
  },
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ canApprove: () => false }) }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeRecord: () => {} }));

const transfer = {
  id: 'budget-transfer-1',
  transfer_number: 'BT-2026-0001',
  month: 'jan',
  amount: '25.00',
  reason: 'Same-role reject action projection.',
  status: 'pending',
  can_approve: false,
  available_actions: { approve: false, reject: true },
  created_at: '2026-11-03T00:00:00Z',
  updated_at: '2026-11-03T00:00:00Z',
} as BudgetTransfer;

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(budgetingApi.transfer).mockResolvedValue(transfer);
});

it('renders the server-authorized reject action independently from approval eligibility', async () => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/budgeting/transfers/budget-transfer-1']}>
        <Routes>
          <Route path="/budgeting/transfers/:id" element={<BudgetTransferDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  expect(await screen.findByRole('button', { name: 'Reject' })).toBeEnabled();
  expect(screen.queryByRole('button', { name: 'Approve transfer' })).not.toBeInTheDocument();
});
