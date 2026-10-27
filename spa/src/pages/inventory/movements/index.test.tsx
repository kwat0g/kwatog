import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { stockMovementsApi } from '@/api/inventory/stock';
import type { StockMovement } from '@/types/inventory';
import { StockMovementsTab } from './index';

vi.mock('@/api/inventory/stock', () => ({
  stockMovementsApi: { list: vi.fn(), options: vi.fn() },
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));

it('shows document references and negative delivery quantity without changing receipt quantity', async () => {
  const receipt: StockMovement = {
    id: 'movement-1', created_at: '2026-10-09T12:00:00Z', movement_type: 'grn_receipt',
    item: { id: 'item-1', code: 'PKG-001', name: 'Poly Bag', unit_of_measure: 'pcs' },
    from_location: null, to_location: null, quantity: '5.000', unit_cost: '0.5000',
    total_cost: '2.50', lot_number: null, expiry_date: null,
    gl_handoff: { status: 'not_required', status_label: 'Not required', message: null, at: null },
    reference_type: 'goods_receipt_note', reference_id: 'grn-1',
    reference_label: 'GRN-202610-0005', reference_url: '/inventory/grn/grn-1',
    remarks: null, creator: null,
  };
  vi.mocked(stockMovementsApi.list).mockResolvedValue({
    data: [receipt, {
      ...receipt, id: 'movement-2', movement_type: 'delivery', quantity: '498.000',
      reference_type: 'delivery_item', reference_id: 'delivery-item-1',
      reference_label: 'DEL-202610-0001', reference_url: '/supply-chain/deliveries/delivery-1',
    }],
    meta: { total: 2, current_page: 1, last_page: 1, per_page: 50, from: 1, to: 2 },
    links: { first: '', last: '', prev: null, next: null },
  });
  vi.mocked(stockMovementsApi.options).mockResolvedValue({ movement_types: [] });

  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    <MemoryRouter><StockMovementsTab /></MemoryRouter>
  </QueryClientProvider>);

  expect(await screen.findByRole('link', { name: 'GRN-202610-0005' })).toHaveAttribute('href', '/inventory/grn/grn-1');
  expect(screen.getByRole('link', { name: 'DEL-202610-0001' })).toHaveAttribute('href', '/supply-chain/deliveries/delivery-1');
  expect(screen.getByText('5 pcs')).toBeInTheDocument();
  expect(screen.getByText('-498 pcs')).toBeInTheDocument();
});
