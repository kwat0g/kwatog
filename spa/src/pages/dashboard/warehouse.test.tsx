import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { dashboardsApi } from '@/api/dashboards';
import WarehouseDashboard from './warehouse';

vi.mock('@/api/dashboards', () => ({ dashboardsApi: { warehouse: vi.fn() } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/components/dashboard/KpiStrip', () => ({ KpiStrip: () => null }));

const zones = [
  { warehouse_code: 'WH-A', warehouse_name: 'Main', zone: 'A', name: 'Raw Materials', percent: 50 },
  { warehouse_code: 'WH-B', warehouse_name: 'Overflow', zone: 'A', name: 'Raw Materials', percent: 50 },
];

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  vi.mocked(dashboardsApi.warehouse).mockResolvedValue({
    kpis: [],
    panels: { incoming_queue: [], outgoing_queue: [], low_stock_alerts: [], zone_utilization: zones },
  });

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <WarehouseDashboard />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('WarehouseDashboard', () => {
  afterEach(() => vi.restoreAllMocks());

  it('keeps same-code zones from different warehouses distinct in the rendered page', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});

    renderPage();

    expect(await screen.findByRole('progressbar', { name: 'Main (WH-A), Raw Materials (A): 50% occupied' })).toBeInTheDocument();
    expect(screen.getByRole('progressbar', { name: 'Overflow (WH-B), Raw Materials (A): 50% occupied' })).toBeInTheDocument();
    expect(screen.getAllByText('WH-A/A — Raw Materials')).toHaveLength(2);
    expect(screen.getAllByText('WH-B/A — Raw Materials')).toHaveLength(2);
    expect(error.mock.calls.flat().join(' ')).not.toContain('Encountered two children with the same key');
  });
});
