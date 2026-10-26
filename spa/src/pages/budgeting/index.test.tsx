import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { budgetingApi } from '@/api/accounting/budgeting';
import BudgetOverviewPage from './index';

vi.mock('@/api/accounting/budgeting', () => ({
  budgetingApi: {
    options: vi.fn(),
    fiscalYears: vi.fn(),
    overview: vi.fn(),
    list: vi.fn(),
  },
}));

vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(budgetingApi.options).mockResolvedValue({
    budget_types: [], statuses: [], warning_ratio_pct: 70, critical_ratio_pct: 90, exhausted_ratio_pct: 100,
  });
  vi.mocked(budgetingApi.fiscalYears).mockResolvedValue([{
    id: 'fy-2026', year: 2026, start_date: '2026-01-01', end_date: '2026-12-31', status: 'active',
  }]);
  vi.mocked(budgetingApi.overview).mockResolvedValue({
    total_allocated: '0', total_spent: '0', total_committed: '0', total_available: '0', utilization_pct: 0, by_department: [],
  });
  vi.mocked(budgetingApi.list).mockResolvedValue({
    data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null },
  });
});

it('loads the all-year approval queue from the sidebar signal', async () => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/budgeting?signal=awaiting_my_approval']}>
        <BudgetOverviewPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );

  await waitFor(() => expect(budgetingApi.list).toHaveBeenLastCalledWith({
    signal: 'awaiting_my_approval', page: 1, per_page: 25,
  }));
  expect(await screen.findByText('Budgets awaiting your approval')).toBeInTheDocument();
});
