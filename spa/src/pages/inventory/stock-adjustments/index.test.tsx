import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { stockAdjustmentsApi } from '@/api/inventory/stock';
import type { StockAdjustment } from '@/types/inventory';
import StockAdjustmentsPage from './index';

const api = vi.hoisted(() => ({
  list: vi.fn(),
  options: vi.fn(),
  approve: vi.fn(),
  reject: vi.fn(),
  withdraw: vi.fn(),
}));
vi.mock('@/api/inventory/stock', () => ({ stockAdjustmentsApi: api }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => false }) }));

const adjustment: StockAdjustment = {
  id: 'adjustment-hash', direction: 'out', quantity: '5.000', unit_cost: '10.0000', value: '50.00',
  reason_code: 'damage', reason_label: 'Damage', reason: 'Damaged goods', status: 'pending', status_label: 'Pending',
  item: { id: 'item-hash', code: 'ABC-1', name: 'Resin pellets', unit_of_measure: 'kg' },
  location: { id: 'location-hash', code: 'WH-1' }, stock_movement: null,
  requested_by: { id: 'maker-hash', name: 'Mina Santos' }, approved_by: null, approved_at: null,
  resolved_by: null, resolved_at: null, resolution_reason: null,
  can_approve: false, can_reject: false, can_withdraw: false, created_at: '2026-10-26T08:00:00+08:00',
};

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/inventory/stock-adjustments?id=adjustment-hash']}>
        <Routes><Route path="/inventory/stock-adjustments" element={<StockAdjustmentsPage />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(stockAdjustmentsApi.list).mockResolvedValue({
    data: [adjustment],
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 1, from: 1, to: 1 },
    links: { first: '', last: '', prev: null, next: null },
  });
  vi.mocked(stockAdjustmentsApi.options).mockResolvedValue({
    approval_threshold: '0', statuses: [], directions: [], reasons: [],
  });
});

it('loads the exact adjustment from a notification link and can return to the full list', async () => {
  renderPage();

  expect(await screen.findByText('ABC-1')).toBeInTheDocument();
  await waitFor(() => expect(stockAdjustmentsApi.list).toHaveBeenCalledWith(expect.objectContaining({ id: 'adjustment-hash' })));

  fireEvent.click(screen.getByRole('button', { name: 'Show all adjustments' }));

  await waitFor(() => expect(stockAdjustmentsApi.list).toHaveBeenLastCalledWith(expect.objectContaining({ id: '', page: 1 })));
  expect(screen.queryByRole('button', { name: 'Show all adjustments' })).not.toBeInTheDocument();
});
