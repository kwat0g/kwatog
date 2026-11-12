import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { itemsApi } from '@/api/inventory/items';
import ItemDetailPage from './detail';

vi.mock('@/api/inventory/items', () => ({
  itemsApi: { show: vi.fn(), options: vi.fn() },
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => false }) }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeRecord: vi.fn() }));
vi.mock('./uom-conversions', () => ({ ItemUomConversions: () => null }));

function renderPage() {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MemoryRouter initialEntries={['/inventory/items/item-1']}>
        <Routes>
          <Route path="/inventory/items/:id" element={<ItemDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('finished-good item details', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(itemsApi.show).mockResolvedValue({
      id: 'item-1',
      code: 'FG-001',
      name: 'Finished component',
      description: null,
      category: { id: 'cat-1', name: 'Finished goods', item_type: 'finished_good' },
      item_type: 'finished_good',
      item_type_label: 'Finished Good',
      unit_of_measure: 'PCS',
      standard_cost: '10.0000',
      reorder_method: 'fixed_quantity',
      reorder_point: '20.000',
      safety_stock: '5.000',
      minimum_order_quantity: '1.000',
      lead_time_days: 0,
      is_critical: false,
      is_active: true,
      quality_plan_ready: true,
      on_hand_quantity: '0.000',
      reserved_quantity: '0.000',
      available_quantity: '0.000',
      stock_status: 'critical',
    } as never);
    vi.mocked(itemsApi.options).mockResolvedValue({
      replenishment_basis: {
        demand_history_days: 30,
        coverage_buffer_ratio: 1,
        default_lead_time_days: 14,
      },
      item_types: [],
      reorder_methods: [{ value: 'fixed_quantity', label: 'Fixed quantity' }],
      adjustment_directions: [],
      stock_statuses: [],
    });
  });

  it('shows production planning values without supplier reorder settings', async () => {
    renderPage();

    expect(await screen.findByText('FG-001')).toBeVisible();
    expect(screen.getByText('Production planning threshold')).toBeVisible();
    expect(screen.getByText('Production lead time')).toBeVisible();
    expect(screen.getByText('Production lead time').parentElement).toHaveTextContent(
      /14 days\s*\(default\)/,
    );
    expect(screen.queryByText('Reorder method')).not.toBeInTheDocument();
    expect(screen.queryByText('MOQ')).not.toBeInTheDocument();
  });
});
