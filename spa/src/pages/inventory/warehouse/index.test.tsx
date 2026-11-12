import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { warehouseApi } from '@/api/inventory/warehouse';
import WarehousePage from './index';

vi.mock('@/api/inventory/warehouse', () => ({ warehouseApi: { tree: vi.fn() } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => false }) }));

describe('Warehouse structure terminology', () => {
  beforeEach(() => {
    vi.mocked(warehouseApi.tree).mockResolvedValue([{
      id: 'wh-main', name: 'Main warehouse', code: 'MAIN', address: null, is_active: true,
      zones: [{
        id: 'zone-qc', warehouse_id: 'wh-main', name: 'Quality hold', code: 'QC', zone_type: 'quarantine',
        locations: [{
          id: 'loc-a1', zone_id: 'zone-qc', code: 'A1', rack: 'R1', bin: 'B1',
          is_active: true, is_blocked: false, full_code: 'MAIN/QC/A1',
        }],
      }],
    }]);
  });

  it('explains the warehouse, zone, and location/bin hierarchy and inventory statuses', async () => {
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter><WarehousePage /></MemoryRouter>
      </QueryClientProvider>,
    );

    const guide = await screen.findByText(/Warehouse contains zones/);
    expect(guide).toHaveAttribute('role', 'note');
    expect(guide).toHaveTextContent('Warehouse contains zones; zones contain storage locations; rack and bin values identify a location.');
    expect(guide).toHaveTextContent('Available stock can be picked');
    expect(guide).toHaveTextContent('Reserved stock is committed to an order');
    expect(guide).toHaveTextContent('QC-held stock is unavailable until inspection is resolved');
    expect(guide).toHaveTextContent('Damaged stock is recorded through a stock adjustment');
  });
});
